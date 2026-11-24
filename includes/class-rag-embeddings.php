<?php
/**
 * Bulk knowledge base indexing ("Process & Index" on the Train Chatbot screen).
 * Indexes new items, items changed since they were indexed and items indexed
 * with a different embedding model, in small batches that resume automatically.
 * Day-to-day changes are synced automatically by WP_AI_Chatbot_Knowledge_Index.
 *
 * @package AK_AI_Chatbot
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_AI_Chatbot_RAG_Embeddings {

    private $batch_limit = 10; // Sources per run (each source may have several chunks).
    private $done        = 0;
    private $chunks      = 0;
    private $has_more    = false;
    private $error       = '';
    private $ob_level    = 0;

    public function register_hooks() {
        add_action( 'wp_ai_trigger_vector_sync', array( $this, 'process_all_sources' ) );
    }

    public function process_all_sources() {
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
        // Nothing may be printed before the redirect at the end (plugins sometimes echo while content is rendered).
        $this->ob_level = ob_get_level();
        ob_start();

        WP_AI_Chatbot_RAG_Manager::ensure_table();
        WP_AI_Chatbot_Knowledge_Index::migrate();

        $o = get_option( 'wp_ai_rag_settings', array() );
        $o = is_array( $o ) ? $o : array();

        $sources = array(
            array( 'wc_product', 'product', 'wc_products_strategy', 'wc_products_ids', class_exists( 'WooCommerce' ) ),
            array( 'wp_post', 'post', 'wp_posts_strategy', 'wp_posts_ids', true ),
            array( 'wp_page', 'page', 'wp_pages_strategy', 'wp_pages_ids', true ),
            array( 'tutor_course', 'courses', 'tutor_courses_strategy', 'tutor_courses_ids', post_type_exists( 'courses' ) ),
        );
        foreach ( $sources as $src ) {
            if ( '' !== $this->error ) {
                break;
            }
            if ( $this->done >= $this->batch_limit ) {
                // Batch is full and later source types were not checked yet: always run another batch.
                $this->has_more = true;
                break;
            }
            if ( ! $src[4] ) {
                continue;
            }
            $strategy = isset( $o[ $src[2] ] ) ? $o[ $src[2] ] : 'none';
            if ( 'none' === $strategy ) {
                continue;
            }
            $ids = 'specific' === $strategy ? array_map( 'intval', (array) ( isset( $o[ $src[3] ] ) ? $o[ $src[3] ] : array() ) ) : array();
            if ( 'specific' === $strategy && empty( $ids ) ) {
                continue;
            }
            $this->index_posts( $src[0], $src[1], $ids );
        }

        $kb_strategy = isset( $o['custom_kbs_strategy'] ) ? $o['custom_kbs_strategy'] : 'none';
        if ( '' === $this->error && $this->done >= $this->batch_limit && 'none' !== $kb_strategy ) {
            $this->has_more = true;
        } elseif ( '' === $this->error && $this->done < $this->batch_limit ) {
            $strategy = $kb_strategy;
            if ( 'none' !== $strategy ) {
                $this->index_kbs( 'specific' === $strategy ? (array) ( isset( $o['custom_kbs_ids'] ) ? $o['custom_kbs_ids'] : array() ) : null );
            }
        }

        // PDF documents (1.8.0): one section per batch slot, each section is up to 40 chunks.
        if ( '' === $this->error && WP_AI_Chatbot_PDF_Knowledge::ids() ) {
            $this->index_pdfs();
        }

        $this->finish();
    }

    /** New, changed or other-model posts of one type. */
    private function index_posts( $source_type, $post_type, $ids ) {
        global $wpdb;
        $table = WP_AI_Chatbot_Knowledge_Index::table();
        $limit = $this->batch_limit - $this->done;
        $where = $ids ? ' AND p.ID IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')' : '';

        $post_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$table} v ON v.source_id = p.ID AND v.source_type = %s AND v.chunk_index = 0
             WHERE p.post_type = %s AND p.post_status = 'publish' AND p.post_password = '' {$where}
             AND " . WP_AI_Chatbot_Knowledge_Index::needs_index_sql() . "
             ORDER BY p.ID ASC LIMIT %d",
            $source_type, $post_type, WP_AI_Chatbot_AI::embedding_signature(), $limit + 1
        ) );
        if ( count( $post_ids ) > $limit ) {
            $this->has_more = true;
            $post_ids       = array_slice( $post_ids, 0, $limit );
        }
        foreach ( $post_ids as $post_id ) {
            if ( ! $this->index_one( $source_type, $post_id ) ) {
                return;
            }
        }
    }

    private function index_kbs( $allowed ) {
        global $wpdb;
        $kbs   = get_option( 'wp_ai_chatbot_knowledge_bases', array() );
        $table = WP_AI_Chatbot_Knowledge_Index::table();
        foreach ( (array) $kbs as $kb_id => $kb_name ) {
            if ( ( null !== $allowed && ! in_array( (string) $kb_id, array_map( "strval", $allowed ), true ) ) || '' !== $this->error ) {
                continue;
            }
            $limit = $this->batch_limit - $this->done;
            if ( $limit <= 0 ) {
                $this->has_more = true;
                return;
            }
            $kb_table = $wpdb->prefix . 'ai_kb_' . sanitize_key( $kb_id );
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $kb_table ) ) !== $kb_table ) {
                continue;
            }
            $row_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT r.id FROM {$kb_table} r
                 LEFT JOIN {$table} v ON v.source_id = CONCAT(%s, r.id) AND v.source_type = 'custom_kb' AND v.chunk_index = 0
                 WHERE v.id IS NULL OR v.embed_model <> %s OR v.index_format < %d OR v.source_modified IS NULL ORDER BY r.id ASC LIMIT %d",
                $kb_id . '_', WP_AI_Chatbot_AI::embedding_signature(), WP_AI_Chatbot_Knowledge_Index::INDEX_FORMAT, $limit + 1
            ) );
            if ( count( $row_ids ) > $limit ) {
                $this->has_more = true;
                $row_ids        = array_slice( $row_ids, 0, $limit );
            }
            foreach ( $row_ids as $row_id ) {
                if ( ! $this->index_one( 'custom_kb', $kb_id . '_' . $row_id ) ) {
                    return;
                }
            }
        }
    }

    /** Pending PDF sections: at most 2 per batch, because each one can hold many chunks. */
    private function index_pdfs() {
        $limit = min( 2, $this->batch_limit - $this->done );
        if ( $limit <= 0 ) {
            if ( WP_AI_Chatbot_PDF_Knowledge::pending( 1 ) ) {
                $this->has_more = true;
            }
            return;
        }
        $pending = WP_AI_Chatbot_PDF_Knowledge::pending( $limit + 1 );
        if ( count( $pending ) > $limit ) {
            $this->has_more = true;
            $pending        = array_slice( $pending, 0, $limit );
        }
        foreach ( $pending as $source_id ) {
            if ( ! $this->index_one( WP_AI_Chatbot_PDF_Knowledge::SOURCE, $source_id ) ) {
                return;
            }
        }
    }

    private function index_one( $source_type, $source_id ) {
        $result = WP_AI_Chatbot_Knowledge_Index::index_source( $source_type, $source_id );
        if ( is_wp_error( $result ) ) {
            $this->error = $result->get_error_message();
            set_transient( 'wp_ai_rag_api_error', $this->error, 60 );
            return false;
        }
        if ( 0 === $result && null === WP_AI_Chatbot_Knowledge_Index::document( $source_type, $source_id ) && WP_AI_Chatbot_Knowledge_Index::is_source_selected( $source_type, $source_id ) ) {
            // Public but nothing indexable (e.g. product data unavailable): store a marker so it isn't picked again.
            WP_AI_Chatbot_Knowledge_Index::mark_unindexable( $source_type, $source_id );
        }
        $this->done++;
        $this->chunks += (int) $result;
        return true;
    }

    /** Batch number of the current automatic run (safety stop for endless loops). */
    private function round() {
        return isset( $_GET['round'] ) ? absint( wp_unslash( $_GET['round'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    }

    /** Redirects back to the Train Chatbot screen with a status message. */
    private function finish() {
        if ( '' !== $this->error ) {
            $message = 'Sync stopped. API error: ' . $this->error;
            $url     = admin_url( 'admin.php?page=wp-ai-chatbot-rag&message=' . rawurlencode( $message ) . '&status=error' );
        } elseif ( $this->has_more && $this->round() >= 2000 ) {
            $message = 'Sync paused after many batches. Click Process & Index again to continue.';
            $url     = admin_url( 'admin.php?page=wp-ai-chatbot-rag&message=' . rawurlencode( $message ) . '&status=error' );
        } elseif ( $this->has_more ) {
            $message = sprintf( 'Batch done: %d items indexed (%d chunks). Resting for 3 seconds, then continuing automatically...', $this->done, $this->chunks );
            $url     = admin_url( 'admin.php?page=wp-ai-chatbot-rag&message=' . rawurlencode( $message ) . '&status=success&auto_resume=1&round=' . ( $this->round() + 1 ) );
        } else {
            $message = $this->done
                ? sprintf( 'Sync complete. %d items indexed or refreshed (%d chunks).', $this->done, $this->chunks )
                : 'Sync complete. Everything is already up to date.';
            $url = admin_url( 'admin.php?page=wp-ai-chatbot-rag&message=' . rawurlencode( $message ) . '&status=success' );
        }
        // Throw away anything other plugins printed during indexing.
        while ( ob_get_level() > $this->ob_level ) {
            ob_end_clean();
        }
        if ( headers_sent() ) {
            // Output was already sent before this request reached the plugin: redirect from the page instead.
            echo '<script>window.location.replace(' . wp_json_encode( $url ) . ');</script><noscript><meta http-equiv="refresh" content="0;url=' . esc_attr( $url ) . '"></noscript>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            exit;
        }
        wp_safe_redirect( $url );
        exit;
    }
}
