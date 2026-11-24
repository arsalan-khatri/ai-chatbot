<?php
/**
 * Retrieval engine: finds the knowledge-base chunks that best match a visitor's
 * question (semantic vector search over the whole index + keyword fallback for
 * contact details).
 *
 * Only matching IDs are cached; the text is always loaded fresh and checked
 * for visibility, so content that becomes private disappears immediately.
 *
 * @package AK_AI_Chatbot
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_AI_Chatbot_RAG_Engine {

    const POST_SOURCES = array( 'wc_product', 'wp_post', 'wp_page', 'tutor_course' );
    const SCAN_BATCH   = 1000;   // Vectors read from the database per query.
    const KEEP_TOP     = 120;    // Best chunks kept before grouping.

    /** @var array Scored chunk IDs of the last search: id => similarity. */
    public $last_scores = array();

    /** @var array Details of the last search: mode, found, shown, first, has_more. */
    public $last_meta = array();

    const LIST_PAGE = 10; // Items per page for "show all" requests (token saver).

    /** Products the search found in this request: id => title (for the product cards). */
    public static $seen_products = array();

    /** True when the query embedding could not be made (search fell back to keywords only). */
    public $embed_failed = false;

    /** Scores of one query (cached: only IDs and scores, cleared whenever indexed content changes). */
    private function cached_scores( $query, $table, $signature ) {
        $ttl       = defined( 'WP_AI_CHATBOT_RAG_CACHE_TTL' ) ? (int) WP_AI_CHATBOT_RAG_CACHE_TTL : 300;
        $cache_key = 'wp_ai_rag_' . md5( $signature . '|' . WP_AI_Chatbot_Knowledge_Index::cache_generation() . '|' . $query );
        $scores    = get_transient( $cache_key );
        if ( ! is_array( $scores ) ) {
            $this->embed_failed = false;
            $scores             = $this->score( $query, $table, $signature );
            // A temporary API failure must not be remembered as "nothing found".
            if ( ! $this->embed_failed ) {
                set_transient( $cache_key, $scores, $ttl );
            }
        }
        return $scores;
    }

    /**
     * @param string $query Search text.
     * @param array  $opts  Token saver options: mode ('simple'|'multi'|'list'), page (list page, 0 = first),
     *                      parts (extra sub-questions of a multi-part question), legacy (true = 1.6 behaviour).
     */
    public function search_relevant_knowledge( $query, $opts = array() ) {
        global $wpdb;
        $query = trim( (string) $query );
        $this->last_meta = array( 'mode' => '', 'found' => 0, 'shown' => 0, 'first' => 0, 'has_more' => false );
        if ( '' === $query || ! WP_AI_Chatbot_Knowledge_Index::table_exists() ) {
            return '';
        }
        $table     = WP_AI_Chatbot_Knowledge_Index::table();
        $signature = WP_AI_Chatbot_AI::embedding_signature();
        $legacy    = ! empty( $opts['legacy'] );

        $scores   = $this->cached_scores( $query, $table, $signature );
        $required = array(); // source key => true: best sources of each sub-question.
        if ( ! $legacy && ! empty( $opts['parts'] ) ) {
            foreach ( array_slice( (array) $opts['parts'], 0, 3 ) as $part ) {
                $part_scores = $this->cached_scores( $part, $table, $signature );
                foreach ( $part_scores as $id => $sim ) {
                    $scores[ $id ] = max( isset( $scores[ $id ] ) ? $scores[ $id ] : 0, $sim );
                }
                $required[] = array_slice( $part_scores, 0, 6, true );
            }
            arsort( $scores );
        }
        $this->last_scores = $scores;
        if ( empty( $scores ) ) {
            return '';
        }

        // Fresh text + visibility check on every request.
        $ids  = implode( ',', array_map( 'intval', array_keys( $scores ) ) );
        $rows = $wpdb->get_results( "SELECT id, source_type, source_id, chunk_index, title, content FROM {$table} WHERE id IN ({$ids})" );
        $rows = $this->visible_only( (array) $rows, $table );
        if ( empty( $rows ) ) {
            return '';
        }

        if ( $legacy ) {
            $records = $this->group_by_source( $rows, $scores, 2 );
            return $this->format( $records, $query );
        }
        // Token saver without conversation state (tests, add-ons): same building blocks as the chat.
        $mode = isset( $opts['mode'] ) ? $opts['mode'] : 'simple';
        if ( 'list' === $mode ) {
            $ids    = $this->list_ids( $query );
            $cursor = 0;
            $first  = 1;
            $page   = null;
            for ( $p = 0; $p <= (int) ( $opts['page'] ?? 0 ); $p++ ) {
                $page   = $this->list_page( $ids, $cursor, $first, self::input_budget( 'list' ) - 500 );
                $cursor = $page['cursor'];
                $first += count( $page['shown'] );
            }
            return $page['text'];
        }
        $res = $this->retrieve( $query, isset( $opts['parts'] ) ? $opts['parts'] : array() );
        return $this->compose_answer( $res['records'], $query, array( 'mode' => $mode, 'must' => $res['must'] ) );
    }

    /** @return array id => similarity for the best matching chunks. */
    private function score( $query, $table, $signature ) {
        global $wpdb;
        $scores      = array();
        $query_lower = strtolower( $query );
        $contact     = (bool) preg_match( '/contact|phone|email|address|location|whatsapp|number|rabta|call/', $query_lower );

        // 1. Semantic search over every vector made with the current model, in batches.
        $vector = $this->get_query_embedding( $query );
        if ( ! $vector ) {
            $this->embed_failed = true;
        }
        if ( $vector ) {
            $threshold = str_word_count( $query ) <= 3 ? 0.35 : 0.45;
            if ( $contact ) {
                $threshold = 0.20;
            }
            $qn      = WP_AI_Chatbot_AI::normalize( $vector );
            $q1      = $qn ? array_combine( range( 1, count( $qn ) ), $qn ) : array(); // 1-indexed, like unpack().
            $cap     = defined( 'WP_AI_CHATBOT_RAG_SCAN_CAP' ) ? (int) WP_AI_CHATBOT_RAG_SCAN_CAP : 0; // 0 = no limit.
            $last_id = 0;
            $scanned = 0;
            do {
                $batch = $wpdb->get_results( $wpdb->prepare(
                    "SELECT id, vector_data FROM {$table} WHERE embed_model = %s AND id > %d AND vector_data <> '[]' ORDER BY id ASC LIMIT %d",
                    $signature, $last_id, self::SCAN_BATCH
                ) );
                foreach ( (array) $batch as $row ) {
                    $last_id = (int) $row->id;
                    $sim     = $q1 ? WP_AI_Chatbot_AI::similarity_packed( $q1, (string) $row->vector_data ) : null;
                    if ( null === $sim ) {
                        continue;
                    }
                    if ( $sim > $threshold ) {
                        $scores[ $last_id ] = $sim;
                    }
                }
                $scanned += count( (array) $batch );
                // Keep memory flat on very large knowledge bases.
                if ( count( $scores ) > self::KEEP_TOP * 3 ) {
                    arsort( $scores );
                    $scores = array_slice( $scores, 0, self::KEEP_TOP, true );
                }
            } while ( ! empty( $batch ) && count( $batch ) === self::SCAN_BATCH && ( 0 === $cap || $scanned < $cap ) );
        }

        // 2. Keyword fallback for contact details.
        if ( $contact && WP_AI_Chatbot_AI::token_saver() ) {
            // Token saver: rank pages that really hold contact details (title, email, phone number, address)
            // instead of any text that happens to say "phone".
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, title, content FROM {$table} WHERE embed_model = %s AND ( title LIKE %s OR title LIKE %s OR content LIKE %s OR content LIKE %s OR content LIKE %s ) LIMIT 80",
                $signature, '%contact%', '%rabta%', '%@%', '%whatsapp%', '%address%'
            ) );
            $found = array();
            foreach ( (array) $rows as $row ) {
                $t   = strtolower( (string) $row->title );
                $c   = (string) $row->content;
                $sim = 0;
                if ( false !== strpos( $t, 'contact' ) || false !== strpos( $t, 'rabta' ) ) {
                    $sim = 0.99;
                } elseif ( preg_match( '/[\w.+-]+@[\w-]+\.[\w.]+/', $c ) && preg_match( '/(?:\+?\d[\d\s-]{8,}\d)/', $c ) ) {
                    $sim = 0.9;
                } elseif ( preg_match( '/\b(address|located at|office)\b/i', $c ) && preg_match( '/\d/', $c ) ) {
                    $sim = 0.75;
                }
                if ( $sim ) {
                    $found[ (int) $row->id ] = $sim;
                }
            }
            arsort( $found );
            foreach ( array_slice( $found, 0, 4, true ) as $id => $sim ) {
                $scores[ $id ] = max( isset( $scores[ $id ] ) ? $scores[ $id ] : 0, $sim );
            }
        } elseif ( $contact ) {
            $kw = $wpdb->get_col( "SELECT id FROM {$table} WHERE title LIKE '%contact%' OR title LIKE '%rabta%' OR content LIKE '%phone%' OR content LIKE '%email%' OR content LIKE '%address%' OR content LIKE '%whatsapp%' LIMIT 8" );
            foreach ( (array) $kw as $id ) {
                $scores[ (int) $id ] = max( isset( $scores[ (int) $id ] ) ? $scores[ (int) $id ] : 0, 0.99 );
            }
        }

        // 3. Exact names and SKUs (token saver): found even when wording differs or the embedding failed.
        // A title that is only partly named never outranks the best meaning-based match;
        // exact names, SKUs and titles whose words are all named can.
        if ( WP_AI_Chatbot_AI::token_saver() ) {
            $sem_top = $scores ? max( $scores ) : 0;
            foreach ( $this->lexical( $query, $table, $signature, $sem_top ) as $id => $sim ) {
                $scores[ $id ] = max( isset( $scores[ $id ] ) ? $scores[ $id ] : 0, $sim );
            }
        }

        arsort( $scores );
        return array_slice( $scores, 0, self::KEEP_TOP, true );
    }

    /** Generic words that never identify an item on their own. */
    private static function generic_words() {
        return array( 'price', 'cost', 'rate', 'qeemat', 'keemat', 'available', 'stock', 'warranty', 'delivery', 'more', 'detail', 'details', 'service', 'product', 'package', 'plan', 'best', 'new', 'buy', 'order', 'about', 'info', 'information', 'list', 'all', 'sare', 'saare' );
    }

    /**
     * Keyword matches on titles and SKUs, scored by how completely the title is
     * named in the question. Prepared SQL, current embedding model only (the
     * same rows semantic search uses), first chunk of each source.
     *
     * @return array id => score
     */
    private function lexical( $query, $table, $signature, $sem_top = 0 ) {
        global $wpdb;
        $q_terms = WP_AI_Chatbot_Evidence::terms( $query );
        $words   = array();
        foreach ( array_keys( $q_terms ) as $t ) {
                $t = (string) $t;
            if ( '#' !== $t[0] && '~' !== $t[0] && strlen( $t ) >= 3 && ! in_array( $t, self::generic_words(), true ) ) {
                $words[] = $t;
            }
        }
        $out = array();

        // SKUs: tokens with letters and digits, like AK-105 or LAP14.
        if ( preg_match_all( '/\b[A-Za-z]{1,8}-?\d[A-Za-z0-9\-]{0,12}\b/', $query, $m ) ) {
            foreach ( array_slice( array_unique( $m[0] ), 0, 3 ) as $sku ) {
                $ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE chunk_index = 0 AND embed_model = %s AND content LIKE %s LIMIT 3", $signature, '%SKU: ' . $wpdb->esc_like( $sku ) . '.%' ) );
                foreach ( (array) $ids as $id ) {
                    $out[ (int) $id ] = 0.995;
                }
            }
        }
        if ( empty( $words ) ) {
            return $out;
        }
        usort( $words, function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );
        $words = array_slice( $words, 0, 6 );
        $like  = implode( ' OR ', array_fill( 0, count( $words ), 'title LIKE %s' ) );
        $args  = array_merge( array( $signature ), array_map( function ( $w ) use ( $wpdb ) { return '%' . $wpdb->esc_like( $w ) . '%'; }, $words ) );
        $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, title FROM {$table} WHERE chunk_index = 0 AND embed_model = %s AND ( {$like} ) LIMIT 60", $args ) );
        $excluded = WP_AI_Chatbot_Evidence::exclusions( $query );
        foreach ( (array) $rows as $row ) {
            if ( $excluded && array_intersect( $excluded, array_map( 'strval', array_keys( WP_AI_Chatbot_Evidence::terms( $row->title, true ) ) ) ) ) {
                continue; // "but not the refurbished one": no name boost for what the visitor excluded.
            }
            $t_terms = array();
            foreach ( array_keys( WP_AI_Chatbot_Evidence::terms( $row->title ) ) as $t ) {
                $t = (string) $t;
                if ( '#' !== $t[0] && '~' !== $t[0] ) {
                    $t_terms[] = $t;
                }
            }
            if ( empty( $t_terms ) ) {
                continue;
            }
            $hit = 0;
            foreach ( $t_terms as $t ) {
                $hit += isset( $q_terms[ $t ] ) ? 1 : 0;
            }
            $ratio = $hit / count( $t_terms );
            // Title pairs like "laptop a" must match too, so "Laptop B" is not an exact hit for "Laptop A".
            $pairs_ok = true;
            foreach ( array_keys( WP_AI_Chatbot_Evidence::terms( $row->title, true ) ) as $t ) {
                $t = (string) $t;
                if ( '~' === $t[0] && ! isset( $q_terms[ $t ] ) ) {
                    $pairs_ok = false;
                }
            }
            if ( 1.0 === (float) $ratio && $pairs_ok ) {
                $sim = 0.97; // The question names this item exactly.
            } elseif ( $ratio >= 0.5 && $hit >= 1 ) {
                $sim = 0.5 + 0.3 * $ratio * ( $pairs_ok ? 1 : 0.8 );
                if ( $ratio < 1.0 && $sem_top > 0 ) {
                    $sim = min( $sim, max( 0.5, $sem_top - 0.05 ) );
                }
            } else {
                continue;
            }
            $out[ (int) $row->id ] = max( isset( $out[ (int) $row->id ] ) ? $out[ (int) $row->id ] : 0, $sim );
        }
        return $out;
    }

    /**
     * Drops chunks whose post is no longer public or whose source is no longer
     * selected under Train Chatbot (and removes them from the index).
     */
    private function visible_only( $rows, $table ) {
        global $wpdb;
        $stale = array();
        $out   = array();
        foreach ( $rows as $row ) {
            if ( ! WP_AI_Chatbot_Knowledge_Index::is_source_selected( $row->source_type, $row->source_id ) ) {
                $stale[ $row->source_type . '|' . $row->source_id ] = array( $row->source_type, $row->source_id );
                continue;
            }
            if ( in_array( $row->source_type, self::POST_SOURCES, true ) ) {
                $post = get_post( (int) $row->source_id );
                if ( ! WP_AI_Chatbot_Knowledge_Index::is_public_post( $post, $row->source_type ) ) {
                    $stale[ $row->source_type . '|' . $row->source_id ] = array( $row->source_type, $row->source_id );
                    continue;
                }
            }
            // A bot limited to some knowledge (Sales Tools > Bots): the rest is skipped, not deleted.
            if ( ! WP_AI_Chatbot_Bots::allows( $row->source_type ) ) {
                continue;
            }
            $out[] = $row;
        }
        foreach ( $stale as $s ) {
            WP_AI_Chatbot_Knowledge_Index::delete_source( $s[0], $s[1] );
        }
        // Multilingual sites: pages in the visitor's language first.
        return WP_AI_Chatbot_I18n::filter_rows( $out, self::POST_SOURCES );
    }

    /** One record per source with its best chunks (2 in the 1.6 format, 3 candidates for the token saver). */
    private function group_by_source( $rows, $scores, $max_chunks = 2 ) {
        $groups = array();
        foreach ( $rows as $row ) {
            $key = $row->source_type . '|' . $row->source_id;
            if ( ! isset( $groups[ $key ] ) ) {
                $groups[ $key ] = (object) array(
                    'source_type' => $row->source_type,
                    'source_id'   => $row->source_id,
                    'title'       => $row->title,
                    'score'       => 0,
                    'chunks'      => array(),
                    'sales'       => 0,
                );
            }
            $score = isset( $scores[ (int) $row->id ] ) ? $scores[ (int) $row->id ] : 0;
            $groups[ $key ]->chunks[] = array( 'i' => (int) $row->chunk_index, 'score' => $score, 'text' => $row->content, 'id' => (int) $row->id );
            $groups[ $key ]->score    = max( $groups[ $key ]->score, $score );
        }
        foreach ( $groups as $g ) {
            usort( $g->chunks, function ( $a, $b ) { return $b['score'] <=> $a['score']; } );
            $g->chunks = array_slice( $g->chunks, 0, $max_chunks );
            usort( $g->chunks, function ( $a, $b ) { return $a['i'] <=> $b['i']; } ); // Reading order.
            if ( 'wc_product' === $g->source_type ) {
                $g->sales = (int) get_post_meta( (int) $g->source_id, 'total_sales', true );
                self::$seen_products[ (int) $g->source_id ] = (string) $g->title;
            }
        }
        return array_values( $groups );
    }

    private function format( $records, $query ) {
        $query_lower = strtolower( $query );
        // Whole words only, so "call", "small" or "install" don't count as "all".
        $wants_all = (bool) preg_match( '/\b(all|sare|saare|tamam|list|every|everything|complete)\b/u', $query_lower );

        usort( $records, function ( $a, $b ) {
            if ( $a->score >= 0.99 xor $b->score >= 0.99 ) {
                return $a->score >= 0.99 ? -1 : 1;
            }
            if ( 'wc_product' === $a->source_type && 'wc_product' === $b->source_type && $a->sales !== $b->sales && abs( $a->score - $b->score ) < 0.05 ) {
                return $b->sales - $a->sales;
            }
            return $b->score <=> $a->score;
        } );
        $found   = count( $records );
        $limit   = $wants_all ? 40 : 8;
        $records = array_slice( $records, 0, $limit );
        $shown   = count( $records );

        $out = "### RETRIEVED KNOWLEDGE ###\n";
        if ( function_exists( 'get_woocommerce_currency' ) ) {
            $out .= 'Store currency: ' . get_woocommerce_currency() . " (product prices below are in this currency)\n";
        }
        // Search returns the best matches above a similarity threshold, never a guaranteed full catalogue.
        $out .= $wants_all
            ? sprintf(
                "\n[NOTE: These are the %d best-matching items%s. This is NOT guaranteed to be everything the business offers. Present them clearly, and never call this the complete or full list. %s]\n\n",
                $shown,
                $found > $shown ? ' (more matches exist)' : '',
                'If the visitor wants everything, say these are the closest matches and offer to narrow the search (category, budget) or to share a link to the full catalogue page if one is listed.'
            )
            : "\n[NOTE: These are only the most relevant matches (best sellers first for products). Present them naturally. If the visitor seems to be browsing several items, end by offering to show more matches or more details, in the visitor's language.]\n\n";

        foreach ( $records as $r ) {
            $confidence = min( 100, round( $r->score * 100, 1 ) );
            $out .= '--- Match Confidence: ' . $confidence . '%' . ( 'wc_product' === $r->source_type ? ' | LIVE Total Sales: ' . $r->sales : '' ) . " ---\n";
            $out .= 'Title: ' . $r->title . "\n";
            $out .= 'Details: ' . implode( ' [...] ', wp_list_pluck( $r->chunks, 'text' ) ) . "\n";

            if ( in_array( $r->source_type, self::POST_SOURCES, true ) ) {
                $id  = (int) $r->source_id;
                $url = get_permalink( $id );
                $img = get_the_post_thumbnail_url( $id, 'medium' );
                if ( $url ) {
                    $out .= 'Official URL: ' . $url . "\n";
                }
                if ( $img ) {
                    $out .= 'Image URL: ' . $img . "\n";
                }
            } elseif ( WP_AI_Chatbot_PDF_Knowledge::SOURCE === $r->source_type ) {
                $url = WP_AI_Chatbot_PDF_Knowledge::url( $r->source_id );
                if ( $url ) {
                    $out .= 'Official URL: ' . $url . "\n";
                }
            }
            $out .= "\n";
        }
        return $out;
    }

    /* ------------------------------------------------------------------
     * Token saver (1.7.1): evidence within a token budget
     * ------------------------------------------------------------------ */

    /**
     * Total input budget per request type, in estimated tokens (system prompt,
     * forms, evidence, history and question together). Evidence gets what is
     * left after the fixed parts, but never less than a floor.
     */
    public static function input_budget( $mode ) {
        $defaults = array( 'simple' => 1700, 'multi' => 3000, 'list' => 2200 );
        $budget   = isset( $defaults[ $mode ] ) ? $defaults[ $mode ] : $defaults['simple'];
        if ( defined( 'WP_AI_CHATBOT_CONTEXT_BUDGET' ) ) {
            $budget = (int) round( $budget * WP_AI_CHATBOT_CONTEXT_BUDGET / 5200 ); // Same scale knob as 1.7.0.
        }
        // AI Settings > Token control > Knowledge per question.
        $budget = (int) round( $budget * WP_AI_Chatbot_Token_Control::knowledge_factor() );
        return (int) apply_filters( 'wp_ai_chatbot_input_budget', $budget, $mode, WP_AI_Chatbot_AI::chat_model() );
    }

    public static function evidence_floor( $mode ) {
        $floors = array( 'simple' => 450, 'multi' => 900, 'list' => 600 );
        $floor  = isset( $floors[ $mode ] ) ? $floors[ $mode ] : 450;
        // The minimum knowledge for an answer shrinks with Economy, but never below a usable size.
        $floor  = max( 300, (int) round( $floor * min( 1.0, WP_AI_Chatbot_Token_Control::knowledge_factor() ) ) );
        return (int) apply_filters( 'wp_ai_chatbot_evidence_floor', $floor, $mode );
    }

    /** Kept for compatibility (1.7.0 filter name). */
    public static function budget( $mode ) {
        return (int) apply_filters( 'wp_ai_chatbot_context_budget', self::input_budget( $mode ) * 3, $mode, WP_AI_Chatbot_AI::chat_model() );
    }

    /** Kept for compatibility: plain word list of a question. */
    public static function query_terms( $query ) {
        $out = array();
        foreach ( array_keys( WP_AI_Chatbot_Evidence::terms( $query ) ) as $t ) {
                $t = (string) $t;
            if ( '~' !== $t[0] ) {
                $out[] = ltrim( $t, '#' );
            }
        }
        return $out;
    }

    /** Kept for compatibility: shortens to about $cap characters by whole sentences. */
    public static function trim_passage( $text, $terms, $cap ) {
        $t = array();
        foreach ( (array) $terms as $w ) {
            $t += WP_AI_Chatbot_Evidence::terms( $w );
        }
        return WP_AI_Chatbot_Evidence::select( $text, $t, max( 20, (int) floor( $cap / 3.5 ) ) );
    }

    /** Facts at the top of a product record (name, SKU, price, availability, category). */
    private static function product_header( $text ) {
        $cut = strpos( $text, 'Details:' );
        $sum = strpos( $text, 'Summary:' );
        if ( false !== $sum && ( false === $cut || $sum < $cut ) ) {
            $cut = $sum;
        }
        return trim( false === $cut ? $text : substr( $text, 0, $cut ) );
    }

    private static function sort_records( &$records ) {
        usort( $records, function ( $a, $b ) {
            if ( ! empty( $a->forced ) xor ! empty( $b->forced ) ) {
                return ! empty( $a->forced ) ? -1 : 1;
            }
            if ( $a->score >= 0.99 xor $b->score >= 0.99 ) {
                return $a->score >= 0.99 ? -1 : 1;
            }
            if ( 'wc_product' === $a->source_type && 'wc_product' === $b->source_type && $a->sales !== $b->sales && abs( $a->score - $b->score ) < 0.05 ) {
                return $b->sales - $a->sales;
            }
            return $b->score <=> $a->score;
        } );
    }

    /** URL and image of a post-based record. */
    private static function links( $r ) {
        if ( WP_AI_Chatbot_PDF_Knowledge::SOURCE === $r->source_type ) {
            return array( WP_AI_Chatbot_PDF_Knowledge::url( $r->source_id ), '' );
        }
        if ( ! in_array( $r->source_type, self::POST_SOURCES, true ) ) {
            return array( '', '' );
        }
        $id = (int) $r->source_id;
        return array( (string) get_permalink( $id ), (string) get_the_post_thumbnail_url( $id, 'medium' ) );
    }

    public static function key_of( $r ) {
        return $r->source_type . '|' . $r->source_id;
    }

    /**
     * Searches the knowledge base: semantic + exact name/SKU matches, current
     * visibility and selection checked, one record per source (best 3 chunks).
     *
     * @param string $query Question.
     * @param array  $parts Sub-questions of a multi-part question (each keeps its best sources).
     * @return array array( 'records' => object[], 'must' => key => true )
     */
    public function retrieve( $query, $parts = array() ) {
        global $wpdb;
        $result = array( 'records' => array(), 'must' => array() );
        $query  = trim( (string) $query );
        if ( '' === $query || ! WP_AI_Chatbot_Knowledge_Index::table_exists() ) {
            return $result;
        }
        $table     = WP_AI_Chatbot_Knowledge_Index::table();
        $signature = WP_AI_Chatbot_AI::embedding_signature();
        $scores    = $this->cached_scores( $query, $table, $signature );
        $failed    = $this->embed_failed;
        $per_part  = array();
        foreach ( array_slice( (array) $parts, 0, 3 ) as $part ) {
            $ps = $this->cached_scores( $part, $table, $signature );
            $failed = $failed || $this->embed_failed;
            foreach ( $ps as $id => $sim ) {
                $scores[ $id ] = max( isset( $scores[ $id ] ) ? $scores[ $id ] : 0, $sim );
            }
            $per_part[] = $ps;
        }
        $this->embed_failed = $failed;
        arsort( $scores );
        $this->last_scores = $scores;
        if ( empty( $scores ) ) {
            return $result;
        }
        $ids  = implode( ',', array_map( 'intval', array_keys( $scores ) ) );
        $rows = $wpdb->get_results( "SELECT id, source_type, source_id, chunk_index, title, content FROM {$table} WHERE id IN ({$ids})" );
        $rows = $this->visible_only( (array) $rows, $table );
        $records = $this->group_by_source( $rows, $scores, 3 );
        // Items the visitor explicitly excluded go to the end (kept, so the AI can still say why).
        $excluded = WP_AI_Chatbot_Evidence::exclusions( $query );
        if ( $excluded ) {
            foreach ( $records as $r ) {
                if ( array_intersect( $excluded, array_map( 'strval', array_keys( WP_AI_Chatbot_Evidence::terms( $r->title, true ) ) ) ) ) {
                    $r->score    *= 0.5;
                    $r->excluded  = true;
                }
            }
        }
        self::sort_records( $records );

        // Each sub-question keeps its best two sources.
        foreach ( $per_part as $ps ) {
            arsort( $ps );
            $n = 0;
            foreach ( array_keys( $ps ) as $chunk_id ) {
                foreach ( $records as $r ) {
                    foreach ( $r->chunks as $c ) {
                        if ( $c['id'] === (int) $chunk_id && empty( $result['must'][ self::key_of( $r ) ] ) ) {
                            $result['must'][ self::key_of( $r ) ] = true;
                            $n++;
                            break 2;
                        }
                    }
                }
                if ( $n >= 2 ) {
                    break;
                }
            }
        }
        $result['records'] = $records;
        return $result;
    }

    /**
     * Loads one source by key with fresh text, after checking that it is still
     * selected and public. Used for remembered items and list pages.
     *
     * @return object|null Record (forced = true), or null when not available.
     */
    public function load_source( $key, $max_chunks = 3 ) {
        global $wpdb;
        if ( ! is_string( $key ) || false === strpos( $key, '|' ) || ! WP_AI_Chatbot_Knowledge_Index::table_exists() ) {
            return null;
        }
        list( $type, $id ) = explode( '|', $key, 2 );
        if ( ! WP_AI_Chatbot_Bots::allows( $type ) || ! WP_AI_Chatbot_Knowledge_Index::is_source_selected( $type, $id ) ) {
            return null;
        }
        if ( in_array( $type, self::POST_SOURCES, true ) ) {
            $post = get_post( (int) $id );
            if ( ! WP_AI_Chatbot_Knowledge_Index::is_public_post( $post, $type ) ) {
                return null;
            }
        }
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT id, source_type, source_id, chunk_index, title, content FROM ' . WP_AI_Chatbot_Knowledge_Index::table() . ' WHERE source_type = %s AND source_id = %s AND embed_model = %s ORDER BY chunk_index ASC LIMIT %d',
            $type, (string) $id, WP_AI_Chatbot_AI::embedding_signature(), max( 1, (int) $max_chunks )
        ) );
        if ( empty( $rows ) ) {
            return null;
        }
        $scores = array();
        foreach ( $rows as $row ) {
            $scores[ (int) $row->id ] = 1.0;
        }
        $recs = $this->group_by_source( $rows, $scores, $max_chunks );
        $r    = $recs ? $recs[0] : null;
        if ( $r ) {
            $r->forced = true;
        }
        return $r;
    }

    /** Ordered source keys of a list request (stable order for paging). */
    public function list_ids( $query ) {
        $res = $this->retrieve( $query );
        return array_map( array( __CLASS__, 'key_of' ), $res['records'] );
    }

    /** Header lines of a record: product facts, URL (and image when allowed). */
    private function head( $r, $with_image ) {
        $head   = '';
        $stored = '';
        if ( 'wc_product' === $r->source_type ) {
            $first = '';
            foreach ( $r->chunks as $c ) {
                if ( 0 === $c['i'] ) {
                    $first = $c['text'];
                }
            }
            if ( '' === $first ) {
                $first = (string) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT content FROM ' . WP_AI_Chatbot_Knowledge_Index::table() . ' WHERE source_type = %s AND source_id = %s AND chunk_index = 0', $r->source_type, (string) $r->source_id ) );
            }
            $head   = self::product_header( $first );
            $stored = $head;
            // Price and stock are read live from WooCommerce when it is active, so an answer never
            // uses an outdated price (or one price for a product that has a range).
            if ( function_exists( 'wc_get_product' ) ) {
                $product = wc_get_product( (int) $r->source_id );
                if ( $product ) {
                    $head = trim( WP_AI_Chatbot_Knowledge_Index::product_facts( $product ) );
                }
            }
        }
        list( $url, $img ) = self::links( $r );
        return array(
            'facts'  => $head,
            'stored' => $stored, // Header as indexed (removed from the passage even when the live facts differ).
            'links'  => ( $url ? "Official URL: {$url}\n" : '' ) . ( $with_image && $img && $url ? "Image URL: {$img}\n" : '' ),
        );
    }

    /** Units of a record's passages (best chunks, duplicates from chunk overlaps removed), in reading order. */
    private static function record_units( $r, $facts, $stored = '' ) {
        $chunks = $r->chunks;
        usort( $chunks, function ( $a, $b ) { return $b['score'] <=> $a['score']; } );
        $chunks = array_slice( $chunks, 0, 3 );
        usort( $chunks, function ( $a, $b ) { return $a['i'] <=> $b['i']; } );
        $seen  = array();
        $units = array();
        foreach ( $chunks as $c ) {
            $text = $c['text'];
            foreach ( array_unique( array( $stored, $facts ) ) as $header ) {
                if ( '' !== $header ) {
                    $text = str_replace( $header, '', $text ); // Never show indexed (possibly old) prices next to the live ones.
                }
            }
            foreach ( WP_AI_Chatbot_Evidence::units( $text ) as $u ) {
                $norm = WP_AI_Chatbot_Evidence::lower( preg_replace( '/\W+/u', '', $u ) );
                if ( '' === $norm || isset( $seen[ $norm ] ) ) {
                    continue; // Same sentence from the overlap of two chunks.
                }
                // A chunk can start in the middle of a sentence the previous chunk already holds.
                $tail = false;
                foreach ( array_keys( $seen ) as $prev ) {
                    if ( strlen( $prev ) > strlen( $norm ) && substr( $prev, -strlen( $norm ) ) === $norm ) {
                        $tail = true;
                        break;
                    }
                }
                if ( $tail ) {
                    continue;
                }
                $seen[ $norm ] = true;
                $units[]       = $u;
            }
        }
        return implode( ' ', $units );
    }

    /**
     * Builds the knowledge block for simple and multi-part questions.
     *
     * Pass 1 gives every chosen source its essentials (facts, link, best
     * sentences with their conditions); required sources (each sub-question's
     * best, remembered items) are never dropped, optional ones are dropped from
     * the end if the budget is short. Pass 2 spends what is left on more
     * evidence, source by source. Everything (labels, links, separators) is
     * counted with the multilingual token estimate.
     *
     * @param array $records Records from retrieve() (+ forced ones).
     * @param array $opts    mode, evidence_tokens, must (key => true), terms (extra question terms).
     */
    public function compose_answer( $records, $query, $opts ) {
        $mode   = isset( $opts['mode'] ) && 'multi' === $opts['mode'] ? 'multi' : 'simple';
        $budget = isset( $opts['evidence_tokens'] ) ? (int) $opts['evidence_tokens'] : self::input_budget( $mode );
        $must   = isset( $opts['must'] ) ? (array) $opts['must'] : array();
        $terms  = WP_AI_Chatbot_Evidence::terms( $query );
        foreach ( (array) ( $opts['terms'] ?? array() ) as $extra ) {
            $terms += WP_AI_Chatbot_Evidence::terms( $extra );
        }
        self::sort_records( $records );
        $found = count( $records );

        // Choose sources: required first, then clearly relevant ones.
        $max = 'simple' === $mode ? 4 : 8;
        $drop = 'simple' === $mode ? 0.10 : 0.20;
        $top  = 0;
        foreach ( $records as $r ) {
            if ( empty( $r->forced ) && $r->score < 0.99 ) {
                $top = $r->score;
                break;
            }
        }
        $chosen = array();
        foreach ( $records as $r ) {
            $req = ! empty( $r->forced ) || isset( $must[ self::key_of( $r ) ] );
            if ( $req ) {
                $r->required = true;
                $chosen[]    = $r;
            }
        }
        foreach ( $records as $r ) {
            if ( ! empty( $r->required ) || count( $chosen ) >= $max ) {
                continue;
            }
            if ( empty( $chosen ) || $r->score >= 0.99 || $r->score >= $top - $drop ) {
                $chosen[] = $r;
            }
        }

        $head_line = "### RETRIEVED KNOWLEDGE ###\n" . ( function_exists( 'get_woocommerce_currency' ) ? 'Store currency: ' . get_woocommerce_currency() . " (product prices below are in this currency)\n" : '' )
            . "[NOTE: The most relevant matches (best sellers first for products). Use only what answers the question.]\n"
            . ( WP_AI_Chatbot_Evidence::has_negation( $query ) ? "[NOTE: The question contains a negation, exclusion or \"without\". Respect it exactly; do not recommend what the visitor excluded.]\n" : '' )
            . "\n";
        $left = $budget - WP_AI_Chatbot_Evidence::tokens( $head_line );

        // Pass 1: essentials of every chosen source.
        $blocks = array();
        $cards  = 0;
        foreach ( $chosen as $r ) {
            $h        = $this->head( $r, $cards < 3 );
            $cards   += false !== strpos( $h['links'], 'Image URL' ) ? 1 : 0;
            $passage  = self::record_units( $r, $h['facts'], $h['stored'] );
            $minimum  = WP_AI_Chatbot_Evidence::select( $passage, $terms, 'simple' === $mode ? 90 : 70 );
            $blocks[] = array( 'r' => $r, 'h' => $h, 'passage' => $passage, 'text' => $minimum );
        }
        $cost = function ( $b, $n ) {
            $label = '' === $b['text'] || preg_match( '/^(Details|Summary):/', $b['text'] ) ? '' : 'Details: ';
            return WP_AI_Chatbot_Evidence::tokens( '--- Record ' . $n . " ---\nTitle: " . $b['r']->title . "\n" . ( '' !== $b['h']['facts'] ? $b['h']['facts'] . "\n" : '' ) . ( '' !== $b['text'] ? $label . $b['text'] . "\n" : '' ) . $b['h']['links'] . "\n" );
        };
        $total = 0;
        foreach ( $blocks as $i => $b ) {
            $total += $cost( $b, $i + 1 );
        }
        // Over budget: drop optional sources from the end (required ones stay).
        for ( $i = count( $blocks ) - 1; $i >= 0 && $total > $left; $i-- ) {
            if ( empty( $blocks[ $i ]['r']->required ) && count( $blocks ) > 1 ) {
                array_splice( $blocks, $i, 1 );
                $total = 0;
                foreach ( $blocks as $k => $bb ) {
                    $total += $cost( $bb, $k + 1 ); // Record numbers shift, so everything is counted again.
                }
            }
        }
        // Still over (several required sources): share the room fairly. Each required source keeps
        // its title, facts (name, price, stock) and link, plus its best sentences within an equal
        // share; no required source is dropped. If even that does not fit, the overshoot is
        // reported in last_meta['over_by'] (documented overflow policy) instead of losing items.
        if ( $total > $left && count( $blocks ) > 1 ) {
            $bare = 0;
            foreach ( $blocks as $k => $bb ) {
                $e         = $bb;
                $e['text'] = '';
                $bare     += $cost( $e, $k + 1 );
            }
            $share = max( 25, (int) floor( ( $left - $bare ) / count( $blocks ) ) );
            $total = 0;
            foreach ( $blocks as $k => $bb ) {
                if ( WP_AI_Chatbot_Evidence::tokens( $bb['text'] ) > $share ) {
                    $blocks[ $k ]['text'] = WP_AI_Chatbot_Evidence::select( $bb['passage'], $terms, $share );
                }
                $total += $cost( $blocks[ $k ], $k + 1 );
            }
        }

        // Pass 2: more evidence where it helps, within what is left.
        $spare = $left - $total;
        foreach ( $blocks as $i => $b ) {
            if ( $spare <= 20 ) {
                break;
            }
            $now    = $cost( $b, $i + 1 );
            $share  = (int) floor( $spare / max( 1, count( $blocks ) - $i ) ) + ( 0 === $i ? (int) floor( $spare / 3 ) : 0 );
            $target = WP_AI_Chatbot_Evidence::tokens( $b['text'] ) + min( $spare, $share );
            $more   = WP_AI_Chatbot_Evidence::select( $b['passage'], $terms, $target );
            $b2     = $b;
            $b2['text'] = $more;
            $after  = $cost( $b2, $i + 1 );
            if ( $after > $now && $after - $now <= $spare ) {
                $blocks[ $i ] = $b2;
                $spare       -= $after - $now;
            }
        }

        $out = $head_line;
        foreach ( $blocks as $i => $b ) {
            $label = '' === $b['text'] || preg_match( '/^(Details|Summary):/', $b['text'] ) ? '' : 'Details: ';
            $out  .= '--- Record ' . ( $i + 1 ) . " ---\nTitle: " . $b['r']->title . "\n" . ( '' !== $b['h']['facts'] ? $b['h']['facts'] . "\n" : '' ) . ( '' !== $b['text'] ? $label . $b['text'] . "\n" : '' ) . $b['h']['links'] . "\n";
        }
        $this->last_meta = array(
            'mode'     => $mode,
            'found'    => $found,
            'shown'    => count( $blocks ),
            'first'    => 0,
            'has_more' => $found > count( $blocks ),
            'keys'     => array_map( function ( $b ) { return self::key_of( $b['r'] ); }, $blocks ),
            'titles'   => array_map( function ( $b ) { return (string) $b['r']->title; }, $blocks ),
            'tokens'   => WP_AI_Chatbot_Evidence::tokens( $out ),
            'budget'   => $budget,
            'over_by'  => max( 0, WP_AI_Chatbot_Evidence::tokens( $out ) - $budget ),
            'embed_failed' => $this->embed_failed,
        );
        return count( $blocks ) ? $out : '';
    }

    /**
     * One page of a list: compact items from position $cursor of the stored
     * order. Each item is re-checked (selection, visibility) before it is shown.
     * The cursor moves past exactly the items looked at, so nothing is skipped
     * or repeated even when the budget allows fewer than 10 items.
     *
     * @return array array( 'text', 'shown' => keys, 'cursor' => new cursor, 'has_more' )
     */
    public function list_page( $ids, $cursor, $first_number, $budget, $note = '' ) {
        $ids   = array_values( (array) $ids );
        $total = count( $ids );
        $items = array();
        $used  = 0;
        $i     = max( 0, (int) $cursor );
        $num   = (int) $first_number;
        $parts = '';
        while ( $i < $total && count( $items ) < self::LIST_PAGE ) {
            $r = $this->load_source( $ids[ $i ], 1 );
            if ( ! $r ) {
                $i++; // No longer available: skipped for good, never shown.
                continue;
            }
            $h     = $this->head( $r, false );
            $block = '--- Record ' . $num . " ---\nTitle: " . $r->title . "\n";
            if ( '' !== $h['facts'] ) {
                $block .= $h['facts'] . "\n";
            } else {
                $block .= 'Summary: ' . WP_AI_Chatbot_Evidence::select( $r->chunks[0]['text'], array(), 55 ) . "\n";
            }
            $block .= str_replace( 'Official URL:', 'URL:', $h['links'] ) . "\n";
            $t = WP_AI_Chatbot_Evidence::tokens( $block );
            if ( $used + $t > $budget && ! empty( $items ) ) {
                break; // Not looked at: the next page starts here.
            }
            if ( $t > $budget ) {
                // A single item larger than the whole page budget (very long title or facts):
                // keep its identity (title, price, link) and shorten only the rest.
                $room  = max( 30, $budget - WP_AI_Chatbot_Evidence::tokens( '--- Record ' . $num . " ---\nTitle: \n" . $h['links'] ) - 10 );
                $title = WP_AI_Chatbot_Evidence::cut_tokens( (string) $r->title, (int) floor( $room / 3 ) );
                $facts = '' !== $h['facts'] ? $h['facts'] : 'Summary: ' . WP_AI_Chatbot_Evidence::select( $r->chunks[0]['text'], array(), 55 );
                $facts = WP_AI_Chatbot_Evidence::select( $facts, WP_AI_Chatbot_Evidence::terms( 'price stock sku' ), max( 20, $room - WP_AI_Chatbot_Evidence::tokens( $title ) ) );
                $block = '--- Record ' . $num . " ---\nTitle: " . $title . "\n" . $facts . "\n" . str_replace( 'Official URL:', 'URL:', $h['links'] ) . "\n";
                $t     = WP_AI_Chatbot_Evidence::tokens( $block );
            }
            $items[] = self::key_of( $r );
            $parts  .= $block;
            $used   += $t;
            $num++;
            $i++;
        }
        $more = $i < $total;
        $out  = "### RETRIEVED KNOWLEDGE ###\n" . ( function_exists( 'get_woocommerce_currency' ) ? 'Store currency: ' . get_woocommerce_currency() . " (product prices below are in this currency)\n" : '' );
        if ( empty( $items ) ) {
            $out .= "[NOTE: There are no more matching items beyond the ones already shown. Say so and offer to narrow the search.]\n";
        } else {
            // Written after the page is built, so the numbers are the real ones.
            $out .= sprintf(
                "[NOTE: Matching items %d to %d, ordered by relevance (%d matches in total). This is NOT the complete catalogue. List each on its own line with its number (%d, %d, ...) and link.%s%s]\n\n",
                $first_number,
                $first_number + count( $items ) - 1,
                $total,
                $first_number,
                $first_number + 1,
                $more ? ' End by offering to show more (the visitor can say "show more").' : ' These are the last matches; do not offer more.',
                '' !== $note ? ' ' . $note : ''
            ) . $parts;
        }
        $this->last_meta = array( 'mode' => 'list', 'found' => $total, 'shown' => count( $items ), 'first' => $first_number, 'has_more' => $more, 'keys' => $items, 'tokens' => WP_AI_Chatbot_Evidence::tokens( $out ), 'embed_failed' => $this->embed_failed );
        return array( 'text' => $out, 'shown' => $items, 'cursor' => $i, 'has_more' => $more );
    }

    /**
     * Embeds the visitor's question (cached, so repeated questions don't cost an API call).
     *
     * @return array|false
     */
    private function get_query_embedding( $text ) {
        $cache_key = 'wp_ai_qemb_' . md5( WP_AI_Chatbot_AI::embedding_signature() . '|' . $text );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }
        $vector = WP_AI_Chatbot_AI::embed( $text, 'query' );
        if ( is_wp_error( $vector ) ) {
            WP_AI_Chatbot_AI::record_error( 'Knowledge search', $vector );
            return false;
        }
        set_transient( $cache_key, $vector, defined( 'WP_AI_CHATBOT_EMBED_CACHE_TTL' ) ? WP_AI_CHATBOT_EMBED_CACHE_TTL : 3600 );
        return $vector;
    }
}
