<?php
/**
 * Knowledge index: builds the text for every knowledge source, splits long
 * content into overlapping chunks, embeds each chunk and keeps the index in
 * sync automatically when content changes.
 *
 * @package AK_AI_Chatbot
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_AI_Chatbot_Knowledge_Index {

    const POST_TYPES      = array( 'wc_product' => 'product', 'wp_post' => 'post', 'wp_page' => 'page', 'tutor_course' => 'courses' );
    const CHUNK_SIZE      = 1600;  // Characters per chunk.
    const CHUNK_OVERLAP   = 200;   // Characters repeated between neighbouring chunks.
    const MAX_CHUNKS      = 40;    // Per source (about 60,000 characters).
    const REINDEX_HOOK    = 'wp_ai_chatbot_reindex_source';
    const MAINTENANCE_HOOK = 'wp_ai_chatbot_rag_maintenance';
    const CACHE_GEN_OPTION = 'wp_ai_rag_cache_gen';
    const KB_SYNC_HOOK     = 'wp_ai_chatbot_kb_sync';
    const USAGE_OPTION     = 'wp_ai_chatbot_index_usage';

    /**
     * Version of the way content is split and embedded, and the completion marker.
     *
     * Rows are written with index_format 0 and switched to INDEX_FORMAT by one
     * single UPDATE only after every chunk of the source was saved. A source that
     * was interrupted half way therefore never looks complete.
     *
     * Every row written before 1.5.2 (by 1.4, 1.5.0 or 1.5.1) has a lower value
     * and is rebuilt once, because those versions had no completion marker and
     * a partly written source cannot be told apart from a complete one.
     */
    const INDEX_FORMAT = 3;

    public function register_hooks() {
        // Automatic sync when content changes.
        add_action( 'save_post', array( $this, 'on_save_post' ), 30, 2 );
        add_action( 'woocommerce_update_product', array( $this, 'on_product_update' ), 30 );
        add_action( 'woocommerce_product_set_stock', array( $this, 'on_product_object' ), 30 );
        add_action( 'woocommerce_variation_set_stock', array( $this, 'on_product_object' ), 30 );
        add_action( 'before_delete_post', array( $this, 'on_delete_post' ) );
        add_action( 'wp_trash_post', array( $this, 'on_delete_post' ) );
        add_action( 'wp_ai_kb_row_changed', array( $this, 'on_kb_row_changed' ), 10, 3 );
        add_action( 'wp_ai_kb_bulk_changed', array( $this, 'on_kb_bulk_changed' ), 10, 2 );
        add_action( self::KB_SYNC_HOOK, array( $this, 'run_kb_sync' ) );

        add_action( self::REINDEX_HOOK, array( $this, 'run_scheduled_reindex' ), 10, 2 );
        add_action( self::MAINTENANCE_HOOK, array( $this, 'run_maintenance' ) );
        add_action( 'init', array( $this, 'schedule_maintenance' ) );

        // Source selection changed under Train Chatbot: remove what is no longer selected.
        add_action( 'update_option_wp_ai_rag_settings', array( __CLASS__, 'reconcile_selection' ), 10, 0 );
        add_action( 'add_option_wp_ai_rag_settings', array( __CLASS__, 'reconcile_selection' ), 10, 0 );
    }

    /* ------------------------------------------------------------------
     * Table & cache
     * ------------------------------------------------------------------ */

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'ai_vector_embeddings';
    }

    public static function table_exists() {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }

    /** Adds chunk support to tables created by older versions. Safe to run more than once. */
    public static function migrate() {
        global $wpdb;
        if ( ! self::table_exists() ) {
            return;
        }
        $table   = self::table();
        $columns = $wpdb->get_col( "DESCRIBE {$table}", 0 );
        if ( ! in_array( 'embed_model', (array) $columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN embed_model VARCHAR(150) NOT NULL DEFAULT '' AFTER vector_data" );
        }
        if ( ! in_array( 'chunk_index', (array) $columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN chunk_index SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER source_id" );
        }
        if ( ! in_array( 'source_modified', (array) $columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN source_modified DATETIME NULL DEFAULT NULL" );
            $wpdb->query( "UPDATE {$table} SET source_modified = updated_at" );
        }
        if ( ! in_array( 'index_format', (array) $columns, true ) ) {
            // Existing rows get format 0, so they are rebuilt (into chunks) once.
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN index_format TINYINT UNSIGNED NOT NULL DEFAULT 0" );
        }
        $indexes = $wpdb->get_col( "SHOW INDEX FROM {$table}", 2 );
        if ( in_array( 'source_unique', (array) $indexes, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} DROP INDEX source_unique" );
        }
        if ( ! in_array( 'source_chunk', (array) $indexes, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY source_chunk (source_type, source_id, chunk_index)" );
        }
        if ( ! in_array( 'model_idx', (array) $indexes, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD KEY model_idx (embed_model)" );
        }
    }

    /**
     * True when the knowledge base has at least one searchable vector made with the
     * current embedding model (remembered until the indexed content changes).
     */
    public static function has_vectors() {
        global $wpdb;
        if ( ! self::table_exists() ) {
            return false;
        }
        $key    = 'wp_ai_rag_has_' . md5( WP_AI_Chatbot_AI::embedding_signature() . '|' . self::cache_generation() );
        $cached = get_transient( $key );
        if ( false !== $cached ) {
            return '1' === (string) $cached;
        }
        $has = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . " WHERE embed_model = %s AND vector_data <> '[]' LIMIT 1", WP_AI_Chatbot_AI::embedding_signature() ) );
        set_transient( $key, $has ? '1' : '0', HOUR_IN_SECONDS );
        return $has;
    }

    public static function cache_generation() {
        return (int) get_option( self::CACHE_GEN_OPTION, 1 );
    }

    /** Invalidates every cached search result (called whenever indexed content changes). */
    public static function bump_cache() {
        update_option( self::CACHE_GEN_OPTION, self::cache_generation() + 1, false );
    }

    /**
     * SQL condition (alias v = index row, p = post) that is true when a post needs
     * (re)indexing: never indexed, other model, older index format or edited since.
     * Use with $wpdb->prepare() and pass the embedding signature.
     */
    public static function needs_index_sql() {
        return '( v.id IS NULL OR v.embed_model <> %s OR v.index_format < ' . (int) self::INDEX_FORMAT . ' OR v.source_modified IS NULL OR p.post_modified > v.source_modified )';
    }

    /** Number of indexed sources that still use an older index format. */
    public static function outdated_format_count() {
        global $wpdb;
        if ( ! self::table_exists() ) {
            return 0;
        }
        $columns = (array) $wpdb->get_col( 'DESCRIBE ' . self::table(), 0 );
        if ( ! in_array( 'index_format', $columns, true ) ) {
            return 0;
        }
        return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE chunk_index = 0 AND index_format < %d', self::INDEX_FORMAT ) );
    }

    /* ------------------------------------------------------------------
     * Indexing usage (for the Dashboard)
     * ------------------------------------------------------------------ */

    /** Adds embedding tokens spent on indexing to a per-day log (last 120 days). */
    public static function record_index_usage( $tokens, $exact ) {
        $tokens = (int) $tokens;
        if ( $tokens <= 0 ) {
            return;
        }
        $log = get_option( self::USAGE_OPTION, array() );
        $log = is_array( $log ) ? $log : array();
        $day = current_time( 'Y-m-d' );
        if ( ! isset( $log[ $day ] ) ) {
            $log[ $day ] = array( 'tokens' => 0, 'estimated' => 0, 'model' => '' );
        }
        $log[ $day ]['tokens'] += $tokens;
        if ( ! $exact ) {
            $log[ $day ]['estimated'] += $tokens;
        }
        $log[ $day ]['model'] = WP_AI_Chatbot_AI::embedding_signature();
        ksort( $log );
        if ( count( $log ) > 120 ) {
            $log = array_slice( $log, -120, null, true );
        }
        update_option( self::USAGE_OPTION, $log, false );
    }

    /** @return array tokens and estimated tokens spent on indexing between two dates (Y-m-d, inclusive). */
    public static function index_usage( $from, $to ) {
        $log = get_option( self::USAGE_OPTION, array() );
        $out = array( 'tokens' => 0, 'estimated' => 0 );
        foreach ( (array) $log as $day => $row ) {
            if ( $day >= $from && $day <= $to && is_array( $row ) ) {
                $out['tokens']    += (int) $row['tokens'];
                $out['estimated'] += (int) $row['estimated'];
            }
        }
        return $out;
    }

    /* ------------------------------------------------------------------
     * Documents
     * ------------------------------------------------------------------ */

    /** Splits text into overlapping chunks on sentence boundaries. */
    public static function chunk( $text ) {
        $text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
        if ( '' === $text ) {
            return array();
        }
        $len = function_exists( 'mb_strlen' ) ? 'mb_strlen' : 'strlen';
        $sub = function_exists( 'mb_substr' ) ? 'mb_substr' : 'substr';
        if ( $len( $text ) <= self::CHUNK_SIZE ) {
            return array( $text );
        }

        // Sentences; very long ones (lists, tables) are cut into pieces.
        $pieces = array();
        foreach ( preg_split( '/(?<=[.!?\x{06D4}\x{061F}])\s+/u', $text ) as $sentence ) {
            $slen = $len( $sentence );
            if ( $slen <= self::CHUNK_SIZE ) {
                $pieces[] = $sentence;
                continue;
            }
            for ( $off = 0; $off < $slen; $off += self::CHUNK_SIZE - self::CHUNK_OVERLAP ) {
                $pieces[] = $sub( $sentence, $off, self::CHUNK_SIZE );
            }
        }

        $chunks  = array();
        $current = '';
        foreach ( $pieces as $piece ) {
            if ( '' !== $current && $len( $current ) + 1 + $len( $piece ) > self::CHUNK_SIZE ) {
                $chunks[] = $current;
                if ( count( $chunks ) >= self::MAX_CHUNKS ) {
                    return $chunks;
                }
                // Start the next chunk with the end of this one, so no answer is split in half.
                $tail    = $sub( $current, -self::CHUNK_OVERLAP );
                $space   = strpos( $tail, ' ' );
                $current = false !== $space ? substr( $tail, $space + 1 ) : '';
            }
            $current = '' === $current ? $piece : $current . ' ' . $piece;
        }
        if ( '' !== $current ) {
            $chunks[] = $current;
        }
        return $chunks;
    }

    /** Plain text of a post, page or course (page builders and blocks rendered). */
    private static function post_text( $post ) {
        $raw = $post->post_content;
        if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->db ) && \Elementor\Plugin::$instance->db->is_built_with_elementor( $post->ID ) ) {
            $raw = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $post->ID );
        } else {
            if ( function_exists( 'do_blocks' ) ) {
                $raw = do_blocks( $raw );
            }
            $raw = do_shortcode( $raw );
        }
        $text = wp_strip_all_tags( $raw );
        if ( ! empty( $post->post_excerpt ) ) {
            $text = wp_strip_all_tags( $post->post_excerpt ) . ' ' . $text;
        }
        return wp_strip_all_tags( $post->post_title ) . '. ' . $text;
    }

    /**
     * Builds the document for a source.
     *
     * @return array|null array( 'title', 'text', 'modified' ), or null when the source is gone or not public.
     */
    public static function document( $source_type, $source_id ) {
        // Page builders (Elementor), shortcodes and blocks may print CSS or HTML while
        // their content is rendered. Catch and discard it, so indexing never sends
        // output to the browser (which caused "headers already sent" warnings).
        $level = ob_get_level();
        ob_start();
        try {
            return self::build_document( $source_type, $source_id );
        } finally {
            while ( ob_get_level() > $level ) {
                ob_end_clean();
            }
        }
    }

    /**
     * Price facts of a product. Variable and grouped products get their range
     * ("Price: from 5000 to 9000 (depends on the option chosen)"), never one
     * definitive price. Products without a price say so.
     */
    public static function price_text( $product ) {
        $is_range = method_exists( $product, 'is_type' ) && ( $product->is_type( 'variable' ) || $product->is_type( 'grouped' ) );
        if ( $is_range ) {
            $min = $max = '';
            if ( $product->is_type( 'variable' ) && method_exists( $product, 'get_variation_price' ) ) {
                $min = (string) $product->get_variation_price( 'min', true );
                $max = (string) $product->get_variation_price( 'max', true );
            } elseif ( method_exists( $product, 'get_children' ) && function_exists( 'wc_get_product' ) ) {
                $prices = array();
                foreach ( (array) $product->get_children() as $child_id ) {
                    $child = wc_get_product( $child_id );
                    if ( $child && '' !== (string) $child->get_price() ) {
                        $prices[] = (float) $child->get_price();
                    }
                }
                if ( $prices ) {
                    $min = (string) min( $prices );
                    $max = (string) max( $prices );
                }
            }
            if ( '' === $min ) {
                return 'Price: not listed. ';
            }
            if ( (float) $min === (float) $max ) {
                return 'Price: ' . $min . '. ';
            }
            return 'Price: from ' . $min . ' to ' . $max . ' (depends on the option chosen). ';
        }
        $price = (string) $product->get_price();
        if ( '' === $price ) {
            return 'Price: not listed. ';
        }
        $text = 'Price: ' . $price . '. ';
        if ( $product->is_on_sale() && $product->get_regular_price() && (string) $product->get_regular_price() !== $price ) {
            $text .= 'Regular price: ' . $product->get_regular_price() . ' (on sale now). ';
        }
        return $text;
    }

    /** Name, SKU, price, availability and category of a product, as one line of facts. */
    public static function product_facts( $product ) {
        $cats  = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
        $text  = 'Product Name: ' . $product->get_name() . '. ';
        $text .= $product->get_sku() ? 'SKU: ' . $product->get_sku() . '. ' : '';
        $text .= self::price_text( $product );
        $text .= 'Availability: ' . ( $product->is_in_stock() ? 'In stock' : 'Out of stock' ) . '. ';
        $text .= ( ! is_wp_error( $cats ) && $cats ) ? 'Category: ' . implode( ', ', $cats ) . '. ' : '';
        return $text;
    }

    private static function build_document( $source_type, $source_id ) {
        global $wpdb;

        if ( isset( self::POST_TYPES[ $source_type ] ) ) {
            $post = get_post( (int) $source_id );
            if ( ! self::is_public_post( $post, $source_type ) || self::POST_TYPES[ $source_type ] !== $post->post_type ) {
                return null;
            }
            if ( 'wc_product' === $source_type ) {
                if ( ! function_exists( 'wc_get_product' ) ) {
                    return null;
                }
                $product = wc_get_product( $post->ID );
                if ( ! $product ) {
                    return null;
                }
                $desc  = $product->get_description() ? $product->get_description() : $product->get_short_description();
                $text  = self::product_facts( $product );
                if ( $product->get_short_description() && $product->get_description() ) {
                    $text .= 'Summary: ' . wp_strip_all_tags( $product->get_short_description() ) . ' ';
                }
                $text .= 'Details: ' . wp_strip_all_tags( do_shortcode( (string) $desc ) );
                return array( 'title' => $product->get_name(), 'text' => $text, 'modified' => $post->post_modified );
            }
            return array( 'title' => $post->post_title, 'text' => self::post_text( $post ), 'modified' => $post->post_modified );
        }

        if ( WP_AI_Chatbot_PDF_Knowledge::SOURCE === $source_type ) {
            return WP_AI_Chatbot_PDF_Knowledge::document( $source_id );
        }

        if ( WP_AI_Chatbot_Unanswered::SOURCE === $source_type ) {
            return WP_AI_Chatbot_Unanswered::document( (int) $source_id );
        }

        if ( 'custom_kb' === $source_type ) {
            $parts  = explode( '_', (string) $source_id );
            $row_id = (int) array_pop( $parts );
            $kb_id  = sanitize_key( implode( '_', $parts ) );
            $table  = $wpdb->prefix . 'ai_kb_' . $kb_id;
            if ( ! $kb_id || $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
                return null;
            }
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $row_id ), ARRAY_A );
            if ( ! $row ) {
                return null;
            }
            $cols  = array_diff( array_keys( $row ), array( 'id', 'created_at' ) );
            $lines = array();
            foreach ( $cols as $col ) {
                if ( '' !== trim( (string) $row[ $col ] ) ) {
                    $lines[] = ucfirst( str_replace( '_', ' ', $col ) ) . ': ' . wp_strip_all_tags( $row[ $col ] );
                }
            }
            $first = reset( $cols );
            $kbs   = get_option( 'wp_ai_chatbot_knowledge_bases', array() );
            $title = ( $first && ! empty( $row[ $first ] ) ) ? wp_strip_all_tags( $row[ $first ] ) : ( isset( $kbs[ $kb_id ] ) ? $kbs[ $kb_id ] : 'Record' ) . ' #' . $row_id;
            return array( 'title' => $title, 'text' => implode( '. ', $lines ), 'modified' => current_time( 'mysql' ) );
        }
        return null;
    }

    /* ------------------------------------------------------------------
     * Indexing
     * ------------------------------------------------------------------ */

    public static function delete_source( $source_type, $source_id ) {
        global $wpdb;
        if ( ! self::table_exists() ) {
            return;
        }
        $wpdb->delete( self::table(), array( 'source_type' => $source_type, 'source_id' => (string) $source_id ), array( '%s', '%s' ) );
        self::bump_cache();
    }

    /**
     * (Re)indexes one source: every chunk gets its own vector.
     *
     * All chunks are embedded first and written only when every one succeeded,
     * inside a transaction. If the API fails half way, the old index of the
     * source stays as it was (and is retried later). If a database write fails,
     * the source is marked as needing a refresh, so it is never reported as done.
     *
     * @return int|WP_Error Number of chunks stored (0 = nothing to index, old vectors removed).
     */
    public static function index_source( $source_type, $source_id ) {
        global $wpdb;
        if ( ! self::table_exists() ) {
            WP_AI_Chatbot_RAG_Manager::ensure_table();
        }
        $doc = self::document( $source_type, $source_id );
        if ( null === $doc ) {
            self::delete_source( $source_type, $source_id );
            return 0;
        }

        $chunks = self::chunk( $doc['text'] );
        $total  = count( $chunks );
        $sig    = WP_AI_Chatbot_AI::embedding_signature();

        if ( 0 === $total ) {
            // Keep a marker row so bulk indexing doesn't retry empty content forever.
            return self::write_chunks( $source_type, $source_id, $doc, array( '' ), array( array() ), $sig ) ? 0 : self::write_error( $source_type, $source_id );
        }

        // 1. Embed every chunk. Nothing is written until all of them succeed.
        $usage_before = WP_AI_Chatbot_AI::usage();
        $vectors      = array();
        $error        = null;
        foreach ( $chunks as $i => $chunk ) {
            // The title is added to every chunk so each piece keeps its context.
            $embed_text = $doc['title'] . ( $total > 1 ? ' (part ' . ( $i + 1 ) . ' of ' . $total . ')' : '' ) . ': ' . $chunk;
            if ( $i > 0 ) {
                usleep( 150000 ); // Stay under provider rate limits.
            }
            $vector = WP_AI_Chatbot_AI::embed( $embed_text, 'document' );
            if ( is_wp_error( $vector ) ) {
                $error = $vector;
                break;
            }
            $vectors[] = $vector;
        }
        $usage_after = WP_AI_Chatbot_AI::usage();
        self::record_index_usage( $usage_after['embedding'] - $usage_before['embedding'], $usage_after['exact'] );

        if ( $error ) {
            WP_AI_Chatbot_AI::record_error( 'Knowledge base indexing', $error );
            return $error; // The previous index of this source is untouched.
        }

        // 2. Write all chunks at once.
        if ( ! self::write_chunks( $source_type, $source_id, $doc, $chunks, $vectors, $sig ) ) {
            return self::write_error( $source_type, $source_id );
        }
        return $total;
    }

    /**
     * Replaces every row of a source in one transaction.
     *
     * @return bool False when a database write failed.
     */
    private static function write_chunks( $source_type, $source_id, $doc, $chunks, $vectors, $sig ) {
        global $wpdb;
        $table = self::table();
        $total = count( $chunks );
        $ok    = true;

        $wpdb->query( 'START TRANSACTION' );
        foreach ( $chunks as $i => $chunk ) {
            $result = $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$table} (source_type, source_id, chunk_index, title, content, vector_data, embed_model, source_modified, index_format) VALUES (%s, %s, %d, %s, %s, %s, %s, %s, %d) ON DUPLICATE KEY UPDATE title = VALUES(title), content = VALUES(content), vector_data = VALUES(vector_data), embed_model = VALUES(embed_model), source_modified = VALUES(source_modified), index_format = VALUES(index_format), updated_at = CURRENT_TIMESTAMP",
                $source_type, (string) $source_id, $i, $doc['title'], $chunk, WP_AI_Chatbot_AI::pack_vector( $vectors[ $i ] ), $sig, $doc['modified'], 0 // Pending until every chunk is saved.
            ) );
            if ( false === $result ) {
                $ok = false;
                break;
            }
        }
        if ( $ok ) {
            // Remove chunks left over from a longer previous version.
            $ok = false !== $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE source_type = %s AND source_id = %s AND chunk_index >= %d", $source_type, (string) $source_id, $total ) );
        }
        if ( $ok ) {
            // Completion marker: one statement, so it is all or nothing even without transactions.
            $done = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET index_format = %d WHERE source_type = %s AND source_id = %s", self::INDEX_FORMAT, $source_type, (string) $source_id ) );
            $ok   = false !== $done && $total === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE source_type = %s AND source_id = %s AND index_format = %d", $source_type, (string) $source_id, self::INDEX_FORMAT ) );
        }
        $wpdb->query( $ok ? 'COMMIT' : 'ROLLBACK' );
        if ( $ok ) {
            self::bump_cache();
        }
        return $ok;
    }

    /** A write failed: make sure the source is picked up again by the next sync. */
    private static function write_error( $source_type, $source_id ) {
        global $wpdb;
        $message = $wpdb->last_error ? $wpdb->last_error : 'unknown database error';
        // Without transactions (MyISAM) some rows may already be new: mark them all as stale.
        $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET source_modified = NULL WHERE source_type = %s AND source_id = %s', $source_type, (string) $source_id ) );
        self::bump_cache();
        $error = new WP_Error( 'index_write_failed', 'Could not save the knowledge index: ' . $message );
        WP_AI_Chatbot_AI::record_error( 'Knowledge base indexing', $error );
        return $error;
    }

    /**
     * Stores an empty marker for a source that is public but has nothing that can
     * be indexed, so bulk indexing does not pick it up again and again.
     */
    public static function mark_unindexable( $source_type, $source_id ) {
        $post = in_array( $source_type, array_keys( self::POST_TYPES ), true ) ? get_post( (int) $source_id ) : null;
        $doc  = array(
            'title'    => $post ? $post->post_title : ( WP_AI_Chatbot_PDF_Knowledge::SOURCE === $source_type ? WP_AI_Chatbot_PDF_Knowledge::title( (int) $source_id ) : (string) $source_id ),
            'modified' => $post ? $post->post_modified : current_time( 'mysql' ),
        );
        return self::write_chunks( $source_type, $source_id, $doc, array( '' ), array( array() ), WP_AI_Chatbot_AI::embedding_signature() );
    }

    /**
     * Removes indexed content that is no longer selected under Train Chatbot.
     * Runs whenever the source selection is saved.
     */
    public static function reconcile_selection() {
        global $wpdb;
        if ( ! self::table_exists() ) {
            return;
        }
        $table = self::table();
        $o     = get_option( 'wp_ai_rag_settings', array() );
        $o     = is_array( $o ) ? $o : array();
        $map   = array(
            'wc_product'   => array( 'wc_products_strategy', 'wc_products_ids' ),
            'wp_post'      => array( 'wp_posts_strategy', 'wp_posts_ids' ),
            'wp_page'      => array( 'wp_pages_strategy', 'wp_pages_ids' ),
            'tutor_course' => array( 'tutor_courses_strategy', 'tutor_courses_ids' ),
        );
        foreach ( $map as $type => $keys ) {
            $strategy = isset( $o[ $keys[0] ] ) ? $o[ $keys[0] ] : 'none';
            if ( 'all' === $strategy ) {
                continue;
            }
            $ids = 'specific' === $strategy ? array_filter( array_map( 'intval', (array) ( isset( $o[ $keys[1] ] ) ? $o[ $keys[1] ] : array() ) ) ) : array();
            if ( $ids ) {
                $in = "'" . implode( "','", $ids ) . "'";
                $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE source_type = %s AND source_id NOT IN ({$in})", $type ) );
            } else {
                $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE source_type = %s", $type ) );
            }
        }

        $strategy = isset( $o['custom_kbs_strategy'] ) ? $o['custom_kbs_strategy'] : 'none';
        if ( 'none' === $strategy ) {
            $wpdb->query( "DELETE FROM {$table} WHERE source_type = 'custom_kb'" );
        } else {
            // Rows of knowledge bases that are unselected or no longer exist.
            $kbs  = get_option( 'wp_ai_chatbot_knowledge_bases', array() );
            $kbs  = is_array( $kbs ) ? $kbs : array();
            $seen = $wpdb->get_col( "SELECT DISTINCT source_id FROM {$table} WHERE source_type = 'custom_kb'" );
            $drop = array();
            foreach ( (array) $seen as $sid ) {
                $kb_id = self::kb_of( $sid );
                if ( ! isset( $kbs[ $kb_id ] ) || ! self::kb_selected( $kb_id ) ) {
                    $drop[ $kb_id ] = true;
                }
            }
            foreach ( array_keys( $drop ) as $kb_id ) {
                $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE source_type = 'custom_kb' AND source_id REGEXP %s", self::kb_regexp( $kb_id ) ) );
            }
        }
        self::bump_cache();
    }

    /* ------------------------------------------------------------------
     * Automatic sync
     * ------------------------------------------------------------------ */

    /** Is this source type (and post) selected under Train Chatbot? */
    public static function is_selected( $source_type, $post_id = 0 ) {
        $o   = get_option( 'wp_ai_rag_settings', array() );
        $map = array(
            'wc_product'   => array( 'wc_products_strategy', 'wc_products_ids' ),
            'wp_post'      => array( 'wp_posts_strategy', 'wp_posts_ids' ),
            'wp_page'      => array( 'wp_pages_strategy', 'wp_pages_ids' ),
            'tutor_course' => array( 'tutor_courses_strategy', 'tutor_courses_ids' ),
        );
        if ( ! isset( $map[ $source_type ] ) ) {
            return false;
        }
        $strategy = isset( $o[ $map[ $source_type ][0] ] ) ? $o[ $map[ $source_type ][0] ] : 'none';
        if ( 'all' === $strategy ) {
            return true;
        }
        if ( 'specific' === $strategy ) {
            $ids = isset( $o[ $map[ $source_type ][1] ] ) ? array_map( 'intval', (array) $o[ $map[ $source_type ][1] ] ) : array();
            return in_array( (int) $post_id, $ids, true );
        }
        return false;
    }

    private static function source_type_for( $post_type ) {
        $flip = array_flip( self::POST_TYPES );
        return isset( $flip[ $post_type ] ) ? $flip[ $post_type ] : '';
    }

    public static function schedule( $source_type, $source_id ) {
        $args = array( $source_type, (string) $source_id );
        if ( ! wp_next_scheduled( self::REINDEX_HOOK, $args ) ) {
            wp_schedule_single_event( time() + 15, self::REINDEX_HOOK, $args );
        }
    }

    public function on_save_post( $post_id, $post ) {
        if ( ! $post || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }
        $type = self::source_type_for( $post->post_type );
        if ( '' === $type ) {
            return;
        }
        if ( ! self::is_public_post( $post, $type ) ) {
            // No longer public: remove it right away (and from cached answers).
            self::delete_source( $type, $post_id );
            return;
        }
        if ( self::is_selected( $type, $post_id ) ) {
            self::bump_cache();
            self::schedule( $type, $post_id );
        }
    }

    public function on_product_update( $product_id ) {
        $post = get_post( $product_id );
        if ( $post ) {
            $this->on_save_post( $product_id, $post );
        }
    }

    public function on_product_object( $product ) {
        if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
            $id = method_exists( $product, 'get_parent_id' ) && $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
            $this->on_product_update( $id );
        }
    }

    public function on_delete_post( $post_id ) {
        $post = get_post( $post_id );
        $type = $post ? self::source_type_for( $post->post_type ) : '';
        if ( '' !== $type ) {
            self::delete_source( $type, $post_id );
        }
    }

    /** Fired by the Knowledge Bases screen when a row is added, edited or deleted. */
    public function on_kb_row_changed( $kb_id, $row_id, $action ) {
        $source_id = sanitize_key( $kb_id ) . '_' . (int) $row_id;
        if ( 'delete' === $action ) {
            self::delete_source( 'custom_kb', $source_id );
            return;
        }
        $selected = self::kb_selected( $kb_id );
        if ( $selected ) {
            self::bump_cache();
            self::schedule( 'custom_kb', $source_id );
        }
    }

    private static function kb_regexp( $kb_id ) {
        return '^' . sanitize_key( $kb_id ) . '_[0-9]+$'; // sanitize_key leaves only a-z, 0-9, _ and -.
    }

    /** Splits a custom_kb source ID ("<kb_id>_<row id>") into its knowledge base ID. */
    public static function kb_of( $source_id ) {
        $parts = explode( '_', (string) $source_id );
        array_pop( $parts );
        return sanitize_key( implode( '_', $parts ) );
    }

    /**
     * Can visitors see this post? Published, no password and, for WooCommerce
     * products, not hidden from the shop (catalog visibility "Hidden").
     */
    public static function is_public_post( $post, $source_type = '' ) {
        if ( ! $post || 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
            return false;
        }
        if ( 'product' === $post->post_type || 'wc_product' === $source_type ) {
            $hidden = function_exists( 'has_term' ) && has_term( 'exclude-from-catalog', 'product_visibility', $post ) && has_term( 'exclude-from-search', 'product_visibility', $post );
            if ( $hidden ) {
                return false;
            }
        }
        return true;
    }

    /** Is this exact source selected under Train Chatbot? Works for posts and knowledge base rows. */
    public static function is_source_selected( $source_type, $source_id ) {
        if ( WP_AI_Chatbot_Unanswered::SOURCE === $source_type ) {
            return WP_AI_Chatbot_Unanswered::is_live( (int) $source_id ); // Answers added in Chat History.
        }
        if ( WP_AI_Chatbot_PDF_Knowledge::SOURCE === $source_type ) {
            return WP_AI_Chatbot_PDF_Knowledge::is_selected( $source_id );
        }
        if ( 'custom_kb' === $source_type ) {
            $kb_id = self::kb_of( $source_id );
            $kbs   = get_option( 'wp_ai_chatbot_knowledge_bases', array() );
            return '' !== $kb_id && is_array( $kbs ) && isset( $kbs[ $kb_id ] ) && self::kb_selected( $kb_id );
        }
        return self::is_selected( $source_type, (int) $source_id );
    }

    public static function kb_selected( $kb_id ) {
        $o        = get_option( 'wp_ai_rag_settings', array() );
        $strategy = isset( $o['custom_kbs_strategy'] ) ? $o['custom_kbs_strategy'] : 'none';
        return 'all' === $strategy || ( 'specific' === $strategy && in_array( (string) $kb_id, array_map( 'strval', (array) ( isset( $o['custom_kbs_ids'] ) ? $o['custom_kbs_ids'] : array() ) ), true ) );
    }

    public static function delete_kb_vectors( $kb_id ) {
        global $wpdb;
        if ( ! self::table_exists() ) {
            return;
        }
        // Exact match on "<kb_id>_<row id>", so deleting "faq" never touches "faq_2".
        $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . " WHERE source_type = 'custom_kb' AND source_id REGEXP %s", self::kb_regexp( $kb_id ) ) );
        self::bump_cache();
    }

    /** Many rows changed at once (CSV import, columns changed, knowledge base deleted). */
    public function on_kb_bulk_changed( $kb_id, $what ) {
        if ( 'deleted' === $what || 'reset' === $what ) {
            self::delete_kb_vectors( $kb_id );
        }
        if ( 'deleted' !== $what && self::kb_selected( $kb_id ) && ! wp_next_scheduled( self::KB_SYNC_HOOK, array( (string) $kb_id ) ) ) {
            wp_schedule_single_event( time() + 20, self::KB_SYNC_HOOK, array( (string) $kb_id ) );
        }
    }

    /** Indexes missing rows of one knowledge base, 25 at a time, until done. */
    public function run_kb_sync( $kb_id ) {
        global $wpdb;
        $kb_id    = sanitize_key( $kb_id );
        $kb_table = $wpdb->prefix . 'ai_kb_' . $kb_id;
        if ( ! self::table_exists() || $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $kb_table ) ) !== $kb_table ) {
            return;
        }
        if ( ! self::is_source_selected( 'custom_kb', $kb_id . '_0' ) ) {
            self::delete_kb_vectors( $kb_id );
            return;
        }
        $row_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT r.id FROM {$kb_table} r LEFT JOIN " . self::table() . " v ON v.source_id = CONCAT(%s, r.id) AND v.source_type = 'custom_kb' AND v.chunk_index = 0
             WHERE v.id IS NULL OR v.embed_model <> %s OR v.index_format < %d OR v.source_modified IS NULL ORDER BY r.id ASC LIMIT 26",
            $kb_id . '_', WP_AI_Chatbot_AI::embedding_signature(), self::INDEX_FORMAT
        ) );
        foreach ( array_slice( $row_ids, 0, 25 ) as $row_id ) {
            if ( is_wp_error( self::index_source( 'custom_kb', $kb_id . '_' . $row_id ) ) ) {
                wp_schedule_single_event( time() + 600, self::KB_SYNC_HOOK, array( $kb_id ) );
                return;
            }
        }
        if ( count( $row_ids ) > 25 ) {
            wp_schedule_single_event( time() + 60, self::KB_SYNC_HOOK, array( $kb_id ) );
        }
    }

    public function run_scheduled_reindex( $source_type, $source_id ) {
        // The selection may have changed since this job was queued.
        if ( ! self::is_source_selected( $source_type, $source_id ) ) {
            self::delete_source( $source_type, $source_id );
            return;
        }
        $result = self::index_source( $source_type, $source_id );
        if ( is_wp_error( $result ) ) {
            // Try again later (API limit or temporary error).
            wp_schedule_single_event( time() + 600, self::REINDEX_HOOK, array( $source_type, (string) $source_id ) );
        }
    }

    public function schedule_maintenance() {
        if ( ! wp_next_scheduled( self::MAINTENANCE_HOOK ) ) {
            wp_schedule_event( time() + 300, 'hourly', self::MAINTENANCE_HOOK );
        }
    }

    /**
     * Hourly safety net: refreshes items changed since they were indexed (e.g. edits
     * made by imports), rebuilds items made with an older index format, retries
     * items whose last write failed and removes items that are no longer selected.
     */
    public function run_maintenance() {
        global $wpdb;
        if ( ! self::table_exists() ) {
            return;
        }
        self::migrate();
        $table = self::table();
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT v.source_type, v.source_id FROM {$table} v LEFT JOIN {$wpdb->posts} p ON p.ID = v.source_id
             WHERE v.chunk_index = 0 AND (
               ( v.source_type IN ('wc_product','wp_post','wp_page','tutor_course')
                 AND ( p.ID IS NULL OR v.source_modified IS NULL OR p.post_modified > v.source_modified OR p.post_status <> 'publish' OR p.post_password <> '' OR v.index_format < %d ) )
               OR ( v.source_type = 'custom_kb' AND ( v.source_modified IS NULL OR v.index_format < %d ) )
             )
             ORDER BY v.id ASC LIMIT 25",
            self::INDEX_FORMAT, self::INDEX_FORMAT
        ) );
        foreach ( (array) $rows as $row ) {
            if ( ! self::is_source_selected( $row->source_type, $row->source_id ) ) {
                self::delete_source( $row->source_type, $row->source_id );
                continue;
            }
            if ( is_wp_error( self::index_source( $row->source_type, $row->source_id ) ) ) {
                break;
            }
        }
        WP_AI_Chatbot_PDF_Knowledge::maintenance();
        self::convert_vectors( 300 );
    }

    /**
     * Converts up to $limit JSON vectors (stored before 1.7.6) to the packed
     * format. No API call is made; the numbers stay the same (as 32-bit floats).
     */
    public static function convert_vectors( $limit ) {
        global $wpdb;
        if ( '1' === get_option( 'wp_ai_chatbot_vectors_packed' ) ) {
            return self::normalize_vectors( $limit ); // JSON done; 1.8.0 also stores unit-length vectors.
        }
        $table = self::table();
        $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, vector_data FROM {$table} WHERE vector_data LIKE %s AND vector_data <> '[]' LIMIT %d", '[%', (int) $limit ) );
        $done  = 0;
        foreach ( (array) $rows as $row ) {
            $v = json_decode( $row->vector_data, true );
            if ( ! is_array( $v ) || ! $v ) {
                continue; // "[]" placeholders stay as they are.
            }
            $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET vector_data = %s WHERE id = %d AND vector_data = %s", WP_AI_Chatbot_AI::pack_vector( $v ), (int) $row->id, $row->vector_data ) );
            $done++;
        }
        if ( ! $rows ) {
            update_option( 'wp_ai_chatbot_vectors_packed', '1', false );
        }
        return $done;
    }

    /**
     * Rewrites up to $limit "f32:" vectors (1.7.6) at unit length ("n32:"), which
     * makes search faster. No API call; similarity scores stay the same.
     */
    public static function normalize_vectors( $limit ) {
        global $wpdb;
        if ( '1' === get_option( 'wp_ai_chatbot_vectors_normalized' ) ) {
            return 0;
        }
        $table = self::table();
        $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, vector_data FROM {$table} WHERE vector_data LIKE %s LIMIT %d", 'f32:%', (int) $limit ) );
        $done  = 0;
        foreach ( (array) $rows as $row ) {
            $v = WP_AI_Chatbot_AI::unpack_vector( $row->vector_data );
            if ( ! $v ) {
                continue;
            }
            $packed = WP_AI_Chatbot_AI::pack_vector( $v );
            if ( 0 !== strncmp( $packed, 'n32:', 4 ) ) {
                continue; // Zero vector: nothing to normalize.
            }
            $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET vector_data = %s WHERE id = %d AND vector_data = %s", $packed, (int) $row->id, $row->vector_data ) );
            $done++;
        }
        if ( count( (array) $rows ) < (int) $limit ) {
            update_option( 'wp_ai_chatbot_vectors_normalized', '1', false );
        }
        return $done;
    }
}
