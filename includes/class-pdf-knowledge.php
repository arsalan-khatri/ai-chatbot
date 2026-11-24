<?php
/**
 * PDF documents as a knowledge source (1.8.0).
 *
 * PDFs live in the Media Library. The admin picks which ones the bot should
 * learn; their text is extracted in PHP (bundled PDF parser, no external
 * service), split into sections of about 48,000 characters and every section
 * is indexed like any other source ("pdf_doc", source ID "<attachment ID>:<section>").
 * Sections keep each request short, so even long PDFs index on hosts with
 * strict time limits, and an interrupted PDF resumes where it stopped.
 *
 * The selection is kept in its own option (wp_ai_chatbot_pdf_docs), so saving
 * the other knowledge base settings can never remove it.
 *
 * @package AK_AI_Chatbot
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_AI_Chatbot_PDF_Knowledge {

    const SOURCE        = 'pdf_doc';
    const OPTION        = 'wp_ai_chatbot_pdf_docs';
    const TEXT_META     = '_wp_ai_chatbot_pdf_text';
    const STATUS_META   = '_wp_ai_chatbot_pdf_status';
    const SECTION_CHARS = 48000; // Fits the 40 chunks per source of the knowledge index.
    const NONCE         = 'wp_ai_chatbot_pdf';

    public function register_hooks() {
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
        add_action( 'wp_ajax_wp_ai_pdf_add', array( $this, 'ajax_add' ) );
        add_action( 'wp_ajax_wp_ai_pdf_prepare', array( $this, 'ajax_prepare' ) );
        add_action( 'wp_ajax_wp_ai_pdf_index_section', array( $this, 'ajax_index_section' ) );
        add_action( 'wp_ajax_wp_ai_pdf_remove', array( $this, 'ajax_remove' ) );
        add_action( 'wp_ajax_wp_ai_pdf_settings', array( $this, 'ajax_settings' ) );
        add_action( 'delete_attachment', array( $this, 'on_delete_attachment' ) );
        add_action( 'attachment_updated', array( $this, 'on_attachment_updated' ), 10, 1 );
        add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'reconcile' ), 10, 0 );
    }

    /* ------------------------------------------------------------------
     * Settings
     * ------------------------------------------------------------------ */

    public static function settings() {
        $o = get_option( self::OPTION, array() );
        $o = is_array( $o ) ? $o : array();
        return array(
            'ids'        => array_values( array_unique( array_filter( array_map( 'intval', isset( $o['ids'] ) ? (array) $o['ids'] : array() ) ) ) ),
            'share_link' => isset( $o['share_link'] ) ? ( '1' === (string) $o['share_link'] ? '1' : '0' ) : '1',
        );
    }

    private static function save_settings( $settings ) {
        $current = self::settings();
        $new     = array(
            'ids'        => array_values( array_unique( array_filter( array_map( 'intval', (array) $settings['ids'] ) ) ) ),
            'share_link' => '1' === (string) $settings['share_link'] ? '1' : '0',
        );
        if ( $new === $current && false !== get_option( self::OPTION ) ) {
            return;
        }
        update_option( self::OPTION, $new, false );
    }

    public static function ids() {
        return self::settings()['ids'];
    }

    public static function share_link() {
        return '1' === self::settings()['share_link'];
    }

    public static function is_pdf_attachment( $id ) {
        $post = get_post( (int) $id );
        return $post && 'attachment' === $post->post_type && 'application/pdf' === $post->post_mime_type;
    }

    /** Splits "<attachment ID>:<section>" into its two numbers. */
    public static function parse_source_id( $source_id ) {
        $parts = explode( ':', (string) $source_id );
        return array( (int) $parts[0], isset( $parts[1] ) ? (int) $parts[1] : 0 );
    }

    public static function source_id( $attachment_id, $section ) {
        return (int) $attachment_id . ':' . (int) $section;
    }

    /** Is this PDF section selected (PDF chosen, still a PDF, section exists)? */
    public static function is_selected( $source_id ) {
        list( $id, $section ) = self::parse_source_id( $source_id );
        if ( $id <= 0 || ! in_array( $id, self::ids(), true ) || ! self::is_pdf_attachment( $id ) ) {
            return false;
        }
        $status = self::status( $id );
        $count  = isset( $status['sections'] ) ? (int) $status['sections'] : 1;
        return $section >= 0 && $section < max( 1, $count );
    }

    /* ------------------------------------------------------------------
     * Text extraction
     * ------------------------------------------------------------------ */

    /** Reason the PDF reader cannot run on this server, or '' when it can. */
    public static function requirements_error() {
        $missing = array();
        foreach ( array( 'mbstring', 'zlib', 'iconv' ) as $ext ) {
            if ( ! extension_loaded( $ext ) ) {
                $missing[] = $ext;
            }
        }
        if ( $missing ) {
            return 'Reading PDFs needs these PHP extensions, which are missing on this server: ' . implode( ', ', $missing ) . '. Ask your host to enable them.';
        }
        return '';
    }

    public static function max_bytes() {
        $mb = defined( 'WP_AI_CHATBOT_PDF_MAX_MB' ) ? (float) WP_AI_CHATBOT_PDF_MAX_MB : 20;
        return (int) apply_filters( 'wp_ai_chatbot_pdf_max_bytes', (int) ( $mb * 1024 * 1024 ) );
    }

    /** Most sections one PDF may have (10 x 48,000 characters, about 200 pages of dense text). */
    public static function max_sections() {
        $n = defined( 'WP_AI_CHATBOT_PDF_MAX_SECTIONS' ) ? (int) WP_AI_CHATBOT_PDF_MAX_SECTIONS : 10;
        return max( 1, (int) apply_filters( 'wp_ai_chatbot_pdf_max_sections', $n ) );
    }

    /** Fingerprint of the file on disk: changes when the file is replaced. */
    private static function file_signature( $file ) {
        return (int) @filesize( $file ) . '|' . (int) @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    }

    public static function status( $id ) {
        $s = get_post_meta( (int) $id, self::STATUS_META, true );
        return is_array( $s ) ? $s : array();
    }

    private static function load_parser() {
        if ( class_exists( '\AKDKVendor\Smalot\PdfParser\Parser', false ) ) {
            return;
        }
        $base = WP_AI_CHATBOT_PATH . 'vendor/pdfparser/src/';
        spl_autoload_register(
            function ( $class ) use ( $base ) {
                $prefix = 'AKDKVendor\\';
                if ( 0 !== strncmp( $class, $prefix, strlen( $prefix ) ) ) {
                    return;
                }
                $file = $base . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
                if ( is_readable( $file ) ) {
                    require_once $file;
                }
            }
        );
    }

    /**
     * Reads the PDF's text (cached until the file changes).
     *
     * @param int  $id    Attachment ID.
     * @param bool $force Read again even when the file did not change.
     * @return array status: state (ready|no_text|error), pages, chars, sections, truncated, message, sig, modified.
     */
    public static function extract( $id, $force = false ) {
        $id   = (int) $id;
        $file = get_attached_file( $id );
        if ( ! $file || ! file_exists( $file ) ) {
            return self::set_status( $id, array( 'state' => 'error', 'message' => 'The PDF file is missing from the uploads folder.' ) );
        }
        $sig    = self::file_signature( $file );
        $status = self::status( $id );
        if ( ! $force && isset( $status['sig'] ) && $status['sig'] === $sig && isset( $status['state'] ) ) {
            if ( 'reading' !== $status['state'] ) {
                return $status;
            }
            // The last attempt never finished (the server stopped it): don't try again automatically.
            return self::set_status( $id, array( 'state' => 'error', 'sig' => $sig, 'message' => 'This PDF could not be read last time (the server stopped, usually because the file is too large or complex). Try a smaller PDF, or split it into parts.' ) );
        }

        $modified = wp_date( 'Y-m-d H:i:s', (int) @filemtime( $file ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        $base     = array( 'sig' => $sig, 'modified' => $modified );

        $req = self::requirements_error();
        if ( '' !== $req ) {
            return self::set_status( $id, $base + array( 'state' => 'error', 'message' => $req ) );
        }
        $size = (int) filesize( $file );
        if ( $size > self::max_bytes() ) {
            return self::set_status( $id, $base + array( 'state' => 'error', 'message' => sprintf( 'This PDF is %s; the limit is %s. Split it into smaller PDFs (or raise WP_AI_CHATBOT_PDF_MAX_MB in wp-config.php).', size_format( $size ), size_format( self::max_bytes() ) ) ) );
        }

        // Mark the attempt first, so a fatal error (memory, time) is remembered instead of retried forever.
        self::set_status( $id, $base + array( 'state' => 'reading' ) );
        if ( function_exists( 'wp_raise_memory_limit' ) ) {
            wp_raise_memory_limit( 'admin' );
        }
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }

        $pages = array();
        try {
            self::load_parser();
            $config = new \AKDKVendor\Smalot\PdfParser\Config();
            $config->setRetainImageContent( false );
            $config->setDecodeMemoryLimit( 64 * 1024 * 1024 );
            $parser   = new \AKDKVendor\Smalot\PdfParser\Parser( array(), $config );
            $document = $parser->parseFile( $file );
            foreach ( $document->getPages() as $page ) {
                $pages[] = self::clean_text( (string) $page->getText() );
            }
            unset( $document, $parser );
        } catch ( \Throwable $e ) {
            $message = $e->getMessage();
            if ( false !== stripos( $message, 'secured' ) || false !== stripos( $message, 'encrypt' ) ) {
                $message = 'This PDF is password protected or encrypted. Save an unprotected copy and upload that.';
            } else {
                $message = 'This PDF could not be read: ' . $message;
            }
            delete_post_meta( $id, self::TEXT_META );
            return self::set_status( $id, $base + array( 'state' => 'error', 'message' => $message ) );
        }

        // Text with page markers, so answers can point to a page.
        $text = '';
        foreach ( $pages as $i => $page_text ) {
            if ( '' !== $page_text ) {
                $text .= '[Page ' . ( $i + 1 ) . '] ' . $page_text . "\n";
            }
        }
        $text  = trim( $text );
        $chars = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );

        // Scanned PDFs (pictures of pages) have little or no text.
        if ( $chars < max( 40, 15 * count( $pages ) ) ) {
            delete_post_meta( $id, self::TEXT_META );
            return self::set_status( $id, $base + array(
                'state'    => 'no_text',
                'pages'    => count( $pages ),
                'chars'    => $chars,
                'sections' => 1,
                'message'  => 'No readable text found. This looks like a scanned PDF (pictures of pages). Use a PDF with real text, for example exported from Word, or run it through OCR first.',
            ) );
        }

        $sections  = self::split_sections( $text );
        $truncated = count( $sections ) > self::max_sections();
        if ( $truncated ) {
            $sections = array_slice( $sections, 0, self::max_sections() );
        }
        update_post_meta( $id, self::TEXT_META, wp_slash( wp_json_encode( $sections ) ) );

        return self::set_status( $id, $base + array(
            'state'     => 'ready',
            'pages'     => count( $pages ),
            'chars'     => $chars,
            'sections'  => count( $sections ),
            'truncated' => $truncated,
            'message'   => '',
        ) );
    }

    private static function set_status( $id, $status ) {
        update_post_meta( (int) $id, self::STATUS_META, $status );
        return $status;
    }

    /** UTF-8, no control characters, tidy spacing. */
    public static function clean_text( $text ) {
        $text = (string) $text;
        if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $text, 'UTF-8' ) ) {
            $text = mb_convert_encoding( $text, 'UTF-8', 'UTF-8' );
        }
        $text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text );
        $text = preg_replace( '/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', (string) $text ); // Direction marks.
        $text = preg_replace( '/[ \t\x{00A0}]+/u', ' ', (string) $text );
        $text = preg_replace( '/\s*\n\s*/u', "\n", (string) $text );
        // Urdu / Arabic: shaped letter forms back to normal letters, and lines stored in visual order fixed.
        if ( preg_match( '/[\x{0600}-\x{06FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $text ) ) {
            if ( class_exists( 'Normalizer' ) && preg_match( '/[\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $text ) ) {
                $normalized = Normalizer::normalize( $text, Normalizer::FORM_KC );
                $text       = false !== $normalized ? $normalized : $text;
            }
            $lines = explode( "\n", (string) $text );
            foreach ( $lines as $i => $line ) {
                $lines[ $i ] = self::fix_rtl_line( $line );
            }
            $text = implode( "\n", $lines );
        }
        return trim( (string) $text );
    }

    /**
     * Some PDF writers store right-to-left lines in visual order, so the text
     * comes out backwards ("۔ےہ دنب" instead of "بند ہے۔"). A line that has only
     * Arabic-script words (no Latin letters or digits, whose order would break)
     * and starts with a sentence-ending mark is reversed back to reading order.
     */
    private static function fix_rtl_line( $line ) {
        $trim = trim( $line );
        if ( '' === $trim || preg_match( '/[A-Za-z0-9\x{0660}-\x{0669}\x{06F0}-\x{06F9}]/u', $trim ) ) {
            return $line;
        }
        if ( ! preg_match( '/^[\x{06D4}\x{061F}!.\x{060C}]/u', $trim ) || preg_match( '/[\x{06D4}\x{061F}!.]$/u', $trim ) ) {
            return $line;
        }
        $chars = preg_split( '//u', $trim, -1, PREG_SPLIT_NO_EMPTY );
        return implode( '', array_reverse( (array) $chars ) );
    }

    /** Groups page texts into sections of at most SECTION_CHARS, at page boundaries where possible. */
    public static function split_sections( $text ) {
        $len = function_exists( 'mb_strlen' ) ? 'mb_strlen' : 'strlen';
        $sub = function_exists( 'mb_substr' ) ? 'mb_substr' : 'substr';
        $sections = array();
        $current  = '';
        foreach ( preg_split( '/\n(?=\[Page \d+\] )/u', $text ) as $page ) {
            // A single very long page is cut into pieces.
            $pieces = array();
            for ( $off = 0, $plen = $len( $page ); $off < $plen; $off += self::SECTION_CHARS ) {
                $pieces[] = $sub( $page, $off, self::SECTION_CHARS );
            }
            foreach ( $pieces as $piece ) {
                if ( '' !== $current && $len( $current ) + 1 + $len( $piece ) > self::SECTION_CHARS ) {
                    $sections[] = $current;
                    $current    = '';
                }
                $current = '' === $current ? $piece : $current . "\n" . $piece;
            }
        }
        if ( '' !== trim( $current ) ) {
            $sections[] = $current;
        }
        return $sections;
    }

    /** Text of one section (from the cache written by extract()). */
    private static function section_text( $id, $section ) {
        $sections = json_decode( (string) get_post_meta( (int) $id, self::TEXT_META, true ), true );
        return ( is_array( $sections ) && isset( $sections[ $section ] ) ) ? (string) $sections[ $section ] : '';
    }

    /* ------------------------------------------------------------------
     * Knowledge index integration
     * ------------------------------------------------------------------ */

    /**
     * Document for one PDF section (called by WP_AI_Chatbot_Knowledge_Index::document()).
     *
     * @return array|null title, text, modified; null when not selected or gone.
     */
    public static function document( $source_id ) {
        list( $id, $section ) = self::parse_source_id( $source_id );
        if ( ! in_array( $id, self::ids(), true ) || ! self::is_pdf_attachment( $id ) ) {
            return null;
        }
        $status = self::extract( $id );
        $state  = isset( $status['state'] ) ? $status['state'] : 'error';
        $count  = isset( $status['sections'] ) ? max( 1, (int) $status['sections'] ) : 1;
        if ( $section >= $count ) {
            return null;
        }
        $title = self::title( $id );
        if ( $count > 1 ) {
            $title .= ' (part ' . ( $section + 1 ) . ' of ' . $count . ')';
        }
        $text = 'ready' === $state ? self::section_text( $id, $section ) : '';
        return array(
            'title'    => $title,
            'text'     => '' !== $text ? 'Document: ' . self::title( $id ) . ". \n" . $text : '',
            'modified' => isset( $status['modified'] ) ? $status['modified'] : current_time( 'mysql' ),
        );
    }

    public static function title( $id ) {
        $title = trim( wp_strip_all_tags( get_the_title( (int) $id ) ) );
        if ( '' === $title ) {
            $file  = get_attached_file( (int) $id );
            $title = $file ? wp_basename( $file ) : 'PDF #' . (int) $id;
        }
        return $title;
    }

    /** Download link shown with answers from this PDF ('' when sharing is off). */
    public static function url( $source_id ) {
        if ( ! self::share_link() ) {
            return '';
        }
        list( $id ) = self::parse_source_id( $source_id );
        $url = wp_get_attachment_url( $id );
        return $url ? (string) $url : '';
    }

    /** Indexed rows (first chunk of each section) of one PDF: section => row. */
    private static function indexed_sections( $id ) {
        global $wpdb;
        if ( ! WP_AI_Chatbot_Knowledge_Index::table_exists() ) {
            return array();
        }
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT source_id, embed_model, index_format, source_modified, vector_data FROM ' . WP_AI_Chatbot_Knowledge_Index::table() . ' WHERE source_type = %s AND source_id LIKE %s AND chunk_index = 0',
            self::SOURCE,
            $wpdb->esc_like( (int) $id . ':' ) . '%'
        ) );
        $out = array();
        foreach ( (array) $rows as $row ) {
            list( , $section ) = self::parse_source_id( $row->source_id );
            $out[ $section ] = $row;
        }
        return $out;
    }

    /** Number of stored chunks of one PDF with the current model and format. */
    private static function chunk_count( $id ) {
        global $wpdb;
        if ( ! WP_AI_Chatbot_Knowledge_Index::table_exists() ) {
            return 0;
        }
        return (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM ' . WP_AI_Chatbot_Knowledge_Index::table() . " WHERE source_type = %s AND source_id LIKE %s AND embed_model = %s AND index_format = %d AND vector_data <> '[]'",
            self::SOURCE,
            $wpdb->esc_like( (int) $id . ':' ) . '%',
            WP_AI_Chatbot_AI::embedding_signature(),
            WP_AI_Chatbot_Knowledge_Index::INDEX_FORMAT
        ) );
    }

    /**
     * Sections of one PDF that still need indexing (reads the file only when it changed).
     *
     * @return int[] Section numbers.
     */
    public static function pending_sections( $id, $status = null ) {
        $status = null === $status ? self::extract( $id ) : $status;
        $state  = isset( $status['state'] ) ? $status['state'] : 'error';
        if ( 'error' === $state ) {
            return array(); // Nothing to index until the admin fixes the file.
        }
        $count    = isset( $status['sections'] ) ? max( 1, (int) $status['sections'] ) : 1;
        $modified = isset( $status['modified'] ) ? (string) $status['modified'] : '';
        $indexed  = self::indexed_sections( $id );
        $sig      = WP_AI_Chatbot_AI::embedding_signature();
        $pending  = array();
        for ( $s = 0; $s < $count; $s++ ) {
            $row = isset( $indexed[ $s ] ) ? $indexed[ $s ] : null;
            if ( ! $row || $row->embed_model !== $sig || (int) $row->index_format < WP_AI_Chatbot_Knowledge_Index::INDEX_FORMAT || null === $row->source_modified || (string) $row->source_modified !== $modified ) {
                $pending[] = $s;
            }
        }
        return $pending;
    }

    /** Removes vectors of sections a PDF no longer has (after the file was replaced by a shorter one). */
    public static function drop_extra_sections( $id, $count ) {
        foreach ( array_keys( self::indexed_sections( $id ) ) as $section ) {
            if ( $section >= $count ) {
                WP_AI_Chatbot_Knowledge_Index::delete_source( self::SOURCE, self::source_id( $id, $section ) );
            }
        }
    }

    public static function delete_vectors( $id ) {
        global $wpdb;
        if ( ! WP_AI_Chatbot_Knowledge_Index::table_exists() ) {
            return;
        }
        $wpdb->query( $wpdb->prepare(
            'DELETE FROM ' . WP_AI_Chatbot_Knowledge_Index::table() . ' WHERE source_type = %s AND source_id LIKE %s',
            self::SOURCE,
            $wpdb->esc_like( (int) $id . ':' ) . '%'
        ) );
        WP_AI_Chatbot_Knowledge_Index::bump_cache();
    }

    /** Removes vectors of PDFs that are no longer selected. Runs whenever the PDF list is saved. */
    public static function reconcile() {
        global $wpdb;
        if ( ! WP_AI_Chatbot_Knowledge_Index::table_exists() ) {
            return;
        }
        $ids  = self::ids();
        $seen = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT source_id FROM ' . WP_AI_Chatbot_Knowledge_Index::table() . ' WHERE source_type = %s', self::SOURCE ) );
        $drop = array();
        foreach ( (array) $seen as $sid ) {
            list( $id ) = self::parse_source_id( $sid );
            if ( ! in_array( $id, $ids, true ) ) {
                $drop[ $id ] = true;
            }
        }
        foreach ( array_keys( $drop ) as $id ) {
            self::delete_vectors( $id );
        }
    }

    /**
     * Next PDF sections that need indexing, for the bulk sync and the hourly job.
     *
     * @return string[] Source IDs ("<id>:<section>").
     */
    public static function pending( $limit ) {
        $out = array();
        foreach ( self::ids() as $id ) {
            if ( ! self::is_pdf_attachment( $id ) ) {
                continue;
            }
            $status = self::extract( $id );
            self::drop_extra_sections( $id, isset( $status['sections'] ) ? max( 1, (int) $status['sections'] ) : 1 );
            foreach ( self::pending_sections( $id, $status ) as $section ) {
                $out[] = self::source_id( $id, $section );
                if ( count( $out ) >= $limit ) {
                    return $out;
                }
            }
        }
        return $out;
    }

    /** Hourly safety net: indexes a few pending sections. */
    public static function maintenance() {
        foreach ( self::pending( 2 ) as $source_id ) {
            if ( is_wp_error( WP_AI_Chatbot_Knowledge_Index::index_source( self::SOURCE, $source_id ) ) ) {
                return;
            }
        }
    }

    public function on_delete_attachment( $post_id ) {
        $post_id = (int) $post_id;
        $s       = self::settings();
        if ( in_array( $post_id, $s['ids'], true ) ) {
            $s['ids'] = array_diff( $s['ids'], array( $post_id ) );
            self::save_settings( $s );
        }
        self::delete_vectors( $post_id );
    }

    /** Title changed in the Media Library: refresh the indexed titles soon. */
    public function on_attachment_updated( $post_id ) {
        $post_id = (int) $post_id;
        if ( in_array( $post_id, self::ids(), true ) ) {
            foreach ( array_keys( self::indexed_sections( $post_id ) ) as $section ) {
                WP_AI_Chatbot_Knowledge_Index::schedule( self::SOURCE, self::source_id( $post_id, $section ) );
            }
        }
    }

    /* ------------------------------------------------------------------
     * Admin screen (section on the Vector RAG DB page)
     * ------------------------------------------------------------------ */

    public function enqueue( $hook ) {
        if ( false === strpos( (string) $hook, 'wp-ai-chatbot-rag' ) ) {
            return;
        }
        wp_enqueue_media();
        wp_enqueue_script( 'wp-ai-pdf-knowledge', WP_AI_CHATBOT_URL . 'admin/js/pdf-knowledge.js', array( 'jquery' ), WP_AI_CHATBOT_VERSION, true );
        wp_localize_script(
            'wp-ai-pdf-knowledge',
            'wpAiPdfData',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( self::NONCE ),
            )
        );
    }

    /** One row of the PDF table, as data for the admin script. */
    public static function row( $id ) {
        $id     = (int) $id;
        $status = self::status( $id );
        $file   = get_attached_file( $id );
        $state  = isset( $status['state'] ) ? $status['state'] : 'new';
        $count  = isset( $status['sections'] ) ? max( 1, (int) $status['sections'] ) : 1;
        $exists = self::is_pdf_attachment( $id ) && $file && file_exists( $file );
        $row    = array(
            'id'        => $id,
            'title'     => $exists ? self::title( $id ) : 'PDF #' . $id . ' (deleted)',
            'file'      => $file ? wp_basename( $file ) : '',
            'size'      => ( $file && file_exists( $file ) ) ? size_format( filesize( $file ) ) : '',
            'url'       => $exists ? (string) wp_get_attachment_url( $id ) : '',
            'pages'     => isset( $status['pages'] ) ? (int) $status['pages'] : 0,
            'sections'  => $count,
            'chunks'    => self::chunk_count( $id ),
            'state'     => $exists ? $state : 'error',
            'message'   => $exists ? ( isset( $status['message'] ) ? (string) $status['message'] : '' ) : 'The file was deleted from the Media Library.',
            'truncated' => ! empty( $status['truncated'] ),
            'pending'   => 0,
        );
        if ( $exists && in_array( $state, array( 'ready', 'no_text' ), true ) && isset( $status['sig'] ) && $status['sig'] === self::file_signature( $file ) ) {
            $row['pending'] = count( self::pending_sections( $id, $status ) );
        } elseif ( $exists && ! in_array( $state, array( 'error' ), true ) ) {
            $row['pending'] = $count; // Not read yet, or the file changed.
            $row['state']   = 'new';
        }
        return $row;
    }

    public static function render_section() {
        $settings = self::settings();
        $req      = self::requirements_error();
        $rows     = array();
        foreach ( $settings['ids'] as $id ) {
            $rows[] = self::row( $id );
        }
        ?>
        <div class="akdk-pdf-box" id="wp-ai-pdf-box">
            <div class="akdk-pdf-head">
                <div>
                    <h3>3. PDF Documents</h3>
                    <p class="description">Brochures, price lists, menus, manuals or policies: upload PDFs and the bot answers from them too. PDFs with real text work best; scanned pictures of pages cannot be read.</p>
                </div>
                <button type="button" class="button button-primary" id="wp-ai-pdf-add" <?php disabled( '' !== $req ); ?>>+ Add PDFs</button>
            </div>
            <?php if ( '' !== $req ) : ?>
                <div class="notice notice-error inline"><p><?php echo esc_html( $req ); ?></p></div>
            <?php endif; ?>
            <label class="akdk-pdf-share">
                <input type="checkbox" id="wp-ai-pdf-share" <?php checked( '1', $settings['share_link'] ); ?> />
                Let the bot share a download link to the PDF it answered from
            </label>
            <table class="widefat striped akdk-pdf-table" id="wp-ai-pdf-table" data-rows="<?php echo esc_attr( wp_json_encode( $rows ) ); ?>">
                <thead>
                    <tr><th>Document</th><th>Pages</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody></tbody>
            </table>
            <p class="akdk-pdf-empty" id="wp-ai-pdf-empty" <?php echo $rows ? 'hidden' : ''; ?>>No PDFs yet. Click <strong>Add PDFs</strong> to upload or choose from your Media Library.</p>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
     * AJAX
     * ------------------------------------------------------------------ */

    private function guard() {
        check_ajax_referer( self::NONCE, 'security' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
        }
    }

    public function ajax_add() {
        $this->guard();
        $ids      = isset( $_POST['ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['ids'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $settings = self::settings();
        $added    = array();
        $skipped  = 0;
        foreach ( $ids as $id ) {
            if ( $id > 0 && self::is_pdf_attachment( $id ) ) {
                if ( ! in_array( $id, $settings['ids'], true ) ) {
                    $settings['ids'][] = $id;
                }
                $added[] = $id;
            } elseif ( $id > 0 ) {
                $skipped++;
            }
        }
        self::save_settings( $settings );
        WP_AI_Chatbot_RAG_Manager::ensure_table();
        $rows = array();
        foreach ( $added as $id ) {
            $rows[] = self::row( $id );
        }
        wp_send_json_success( array( 'rows' => $rows, 'skipped' => $skipped ) );
    }

    /** Reads the PDF (again when forced) and says which sections need indexing. */
    public function ajax_prepare() {
        $this->guard();
        $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
        if ( ! in_array( $id, self::ids(), true ) || ! self::is_pdf_attachment( $id ) ) {
            wp_send_json_error( array( 'message' => 'This PDF is not in the list.' ) );
        }
        $force  = ! empty( $_POST['force'] );
        $status = self::extract( $id, $force );
        $count  = isset( $status['sections'] ) ? max( 1, (int) $status['sections'] ) : 1;
        self::drop_extra_sections( $id, $count );
        $pending = 'error' === ( isset( $status['state'] ) ? $status['state'] : 'error' ) ? array() : self::pending_sections( $id, $status );
        if ( $force && 'error' !== $status['state'] ) {
            $pending = range( 0, $count - 1 );
        }
        wp_send_json_success( array( 'row' => self::row( $id ), 'pending' => array_values( $pending ) ) );
    }

    public function ajax_index_section() {
        $this->guard();
        $id      = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
        $section = isset( $_POST['section'] ) ? (int) $_POST['section'] : 0;
        if ( ! in_array( $id, self::ids(), true ) ) {
            wp_send_json_error( array( 'message' => 'This PDF is not in the list.' ) );
        }
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
        WP_AI_Chatbot_RAG_Manager::ensure_table();
        WP_AI_Chatbot_Knowledge_Index::migrate();
        $result = WP_AI_Chatbot_Knowledge_Index::index_source( self::SOURCE, self::source_id( $id, $section ) );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message(), 'row' => self::row( $id ) ) );
        }
        wp_send_json_success( array( 'chunks' => (int) $result, 'row' => self::row( $id ) ) );
    }

    /** Removes a PDF from the bot's knowledge (the file stays in the Media Library). */
    public function ajax_remove() {
        $this->guard();
        $id       = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
        $settings = self::settings();
        $settings['ids'] = array_values( array_diff( $settings['ids'], array( $id ) ) );
        self::save_settings( $settings );
        self::delete_vectors( $id );
        wp_send_json_success();
    }

    public function ajax_settings() {
        $this->guard();
        $settings               = self::settings();
        $settings['share_link'] = ! empty( $_POST['share_link'] ) && 'false' !== $_POST['share_link'] ? '1' : '0';
        self::save_settings( $settings );
        WP_AI_Chatbot_Knowledge_Index::bump_cache();
        wp_send_json_success( array( 'share_link' => $settings['share_link'] ) );
    }
}
