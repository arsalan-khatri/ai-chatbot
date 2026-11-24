<?php
/**
 * External forms: links (checkout, booking pages), embedded URLs (Google Forms,
 * Typeform, Calendly...), WordPress form shortcodes (Contact Form 7, WPForms,
 * Fluent Forms, Gravity Forms, WooCommerce) and pasted embed code.
 *
 * Shortcodes and embed code are shown inside the chat through a small,
 * same-site page (?akdk_chat_form=ID) so the form plugin's own scripts work.
 * Views, clicks and submissions are tracked for the dashboard.
 *
 * @package AK_AI_Chatbot
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_AI_Chatbot_External_Forms {

    const SOURCES     = array( 'builtin', 'link', 'directlink', 'embed', 'shortcode', 'html' );
    /** Events the browser may report. 'confirmed' is only recorded by the server (form plugin hooks, webhook). */
    const EVENTS      = array( 'view', 'click', 'submitted', 'booked', 'cart' );
    const TABLE       = 'ai_form_events';
    const QUERY_PARAM = 'akdk_chat_form';
    const COOKIE      = 'akdk_chat_form';
    const DB_VERSION  = '3';

    /** Database error of the last failed record() call. */
    private static $last_db_error = '';

    /** Shortcode tag => form plugin, used to read the plugin's own form ID from the saved shortcode. */
    const PLUGIN_SHORTCODES = array(
        'contact-form-7'  => 'cf7',
        'wpforms'         => 'wpforms',
        'fluentform'      => 'fluentform',
        'gravityform'     => 'gravityforms',
        'gravityforms'    => 'gravityforms',
        'ninja_form'      => 'ninja',
        'ninja_forms'     => 'ninja',
        'forminator_form' => 'forminator',
    );

    public function register_hooks() {
        add_action( 'template_redirect', array( $this, 'render_frame' ), 0 );
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
        add_action( 'admin_init', array( __CLASS__, 'ensure_table' ) );

        // Server-side confirmation from WordPress form plugins (submissions made inside the chat).
        add_action( 'wpcf7_mail_sent', array( $this, 'confirm_from_plugin' ), 10, 1 );
        add_action( 'wpforms_process_complete', array( $this, 'confirm_from_plugin' ), 10, 4 );
        add_action( 'fluentform/submission_inserted', array( $this, 'confirm_from_plugin' ), 10, 3 );
        add_action( 'fluentform_submission_inserted', array( $this, 'confirm_from_plugin' ), 10, 3 );
        add_action( 'gform_after_submission', array( $this, 'confirm_from_plugin' ), 10, 2 );
        add_action( 'ninja_forms_after_submission', array( $this, 'confirm_from_plugin' ), 10, 1 );
        add_action( 'forminator_form_after_save_entry', array( $this, 'confirm_from_plugin' ), 10, 2 );
        add_action( 'elementor_pro/forms/new_record', array( $this, 'confirm_from_plugin' ), 10, 1 );
    }

    /* ------------------------------------------------------------------
     * Verified submissions
     * ------------------------------------------------------------------ */

    public static function webhook_secret() {
        $secret = (string) get_option( 'wp_ai_chatbot_webhook_secret', '' );
        if ( strlen( $secret ) < 20 ) {
            $secret = wp_generate_password( 32, false, false );
            update_option( 'wp_ai_chatbot_webhook_secret', $secret, false );
        }
        return $secret;
    }

    public static function webhook_url( $form_id ) {
        return add_query_arg( 'key', self::webhook_secret(), rest_url( 'wp-ai-chatbot/v1/webhook/' . $form_id ) );
    }

    /**
     * Which form plugin and which of its forms a saved shortcode shows,
     * e.g. [wpforms id="12"] → array( 'wpforms', '12' ). Null when unknown.
     */
    public static function plugin_form_of( $form ) {
        if ( 'shortcode' !== self::source_of( $form ) || empty( $form['ext_code'] ) ) {
            return null;
        }
        if ( ! preg_match( '/\[\s*([a-z0-9_\-]+)([^\]]*)\]/i', (string) $form['ext_code'], $m ) ) {
            return null;
        }
        $tag = strtolower( $m[1] );
        if ( ! isset( self::PLUGIN_SHORTCODES[ $tag ] ) ) {
            return null;
        }
        if ( ! preg_match( '/\bid\s*=\s*["\']?([a-z0-9\-]+)/i', $m[2], $id ) ) {
            return null;
        }
        return array( self::PLUGIN_SHORTCODES[ $tag ], strtolower( $id[1] ) );
    }

    /**
     * The plugin, form ID(s) and entry ID of a submission, read from the form
     * plugin's own hook arguments.
     *
     * @return array array( plugin, form_ids[], entry_id ) or plugin '' when unknown.
     */
    public static function submission_from_hook( $hook, $args ) {
        $a     = array_values( (array) $args );
        $get   = function ( $i ) use ( $a ) {
            return isset( $a[ $i ] ) ? $a[ $i ] : null;
        };
        $ids   = array();
        $entry = '';
        switch ( $hook ) {
            case 'wpcf7_mail_sent':
                $cf = $get( 0 );
                if ( is_object( $cf ) && method_exists( $cf, 'id' ) ) {
                    $ids[] = (string) $cf->id();
                    if ( method_exists( $cf, 'hash' ) ) {
                        $ids[] = strtolower( substr( (string) $cf->hash(), 0, 7 ) ); // [contact-form-7 id="1a2b3c4"].
                    }
                }
                return array( 'cf7', $ids, '' );
            case 'wpforms_process_complete':
                $form_data = $get( 2 );
                $ids[]     = is_array( $form_data ) && isset( $form_data['id'] ) ? (string) $form_data['id'] : '';
                return array( 'wpforms', $ids, (string) $get( 3 ) );
            case 'fluentform/submission_inserted':
            case 'fluentform_submission_inserted':
                $form  = $get( 2 );
                $ids[] = is_object( $form ) && isset( $form->id ) ? (string) $form->id : '';
                return array( 'fluentform', $ids, (string) $get( 0 ) );
            case 'gform_after_submission':
                $entry_data = $get( 0 );
                $form       = $get( 1 );
                $ids[]      = is_array( $form ) && isset( $form['id'] ) ? (string) $form['id'] : '';
                return array( 'gravityforms', $ids, is_array( $entry_data ) && isset( $entry_data['id'] ) ? (string) $entry_data['id'] : '' );
            case 'ninja_forms_after_submission':
                $data  = $get( 0 );
                $ids[] = is_array( $data ) && isset( $data['form_id'] ) ? (string) $data['form_id'] : '';
                $sub   = is_array( $data ) && isset( $data['actions']['save']['sub_id'] ) ? (string) $data['actions']['save']['sub_id'] : '';
                return array( 'ninja', $ids, $sub );
            case 'forminator_form_after_save_entry':
                $ids[] = (string) $get( 0 );
                return array( 'forminator', $ids, '' );
        }
        return array( '', array(), '' );
    }

    /** A form plugin saved an entry: if it was sent from a chat form page, record a confirmed lead. */
    public function confirm_from_plugin( ...$args ) {
        $hook = current_filter();

        // 1. Which chat form page was it sent from? Referer first, then the short-lived frame cookie.
        $referer = function_exists( 'wp_get_raw_referer' ) ? (string) wp_get_raw_referer() : '';
        if ( '' === $referer && isset( $_SERVER['HTTP_REFERER'] ) ) {
            $referer = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
        }
        parse_str( (string) wp_parse_url( $referer, PHP_URL_QUERY ), $params );
        $via_cookie = false;
        $form_id    = ! empty( $params[ self::QUERY_PARAM ] ) ? sanitize_key( $params[ self::QUERY_PARAM ] ) : '';
        if ( '' === $form_id && ! empty( $_COOKIE[ self::COOKIE ] ) ) {
            $form_id    = sanitize_key( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
            $via_cookie = true;
        }
        $form = '' !== $form_id ? self::find_form( $form_id ) : null;
        if ( ! $form || ! in_array( self::source_of( $form ), array( 'shortcode', 'html' ), true ) ) {
            return;
        }

        // 2. The submitted form must be the one this chat form shows.
        list( $plugin, $submitted_ids, $entry_id ) = self::submission_from_hook( $hook, $args );
        $expected = self::plugin_form_of( $form );
        if ( $expected ) {
            if ( $plugin !== $expected[0] || ! in_array( $expected[1], array_map( 'strtolower', array_filter( $submitted_ids ) ), true ) ) {
                return; // Another form on the site, not the one in the chat.
            }
        } elseif ( $via_cookie ) {
            return; // Without a known form ID, only the referer of the chat frame is trusted.
        }

        $dedupe = '' !== $entry_id ? 'wp:' . $plugin . ':' . $entry_id : '';
        self::record( $form_id, 'confirmed', array( 'via' => $hook ), $dedupe );
    }

    /** Webhook for Google Forms (Apps Script), n8n, Zapier, Make or any service. */
    public function webhook( WP_REST_Request $request ) {
        $ip = WP_AI_Chatbot_Rate_Limiter::get_client_ip();
        if ( ! WP_AI_Chatbot_Rate_Limiter::allow( 'hook_' . $ip, 60, 60 ) ) {
            return new WP_Error( 'rate_limited', 'Too many requests.', array( 'status' => 429 ) );
        }
        $key = (string) $request->get_param( 'key' );
        if ( '' === $key ) {
            $key = (string) $request->get_header( 'X-AKDK-Key' );
        }
        if ( ! hash_equals( self::webhook_secret(), $key ) ) {
            return new WP_Error( 'forbidden', 'Invalid key.', array( 'status' => 403 ) );
        }
        $form_id = sanitize_key( (string) $request->get_param( 'form_id' ) );
        if ( ! self::find_form( $form_id ) ) {
            return new WP_Error( 'not_found', 'Unknown form.', array( 'status' => 404 ) );
        }

        // Keep a small, clean copy of the submitted answers (name, email, ...).
        $raw  = $request->get_json_params();
        $raw  = is_array( $raw ) && $raw ? $raw : $request->get_body_params();
        $data = array();
        foreach ( (array) $raw as $k => $v ) {
            if ( 'key' === $k || count( $data ) >= 30 ) {
                continue;
            }
            $v = is_array( $v ) ? implode( ', ', array_map( 'strval', array_filter( $v, 'is_scalar' ) ) ) : ( is_scalar( $v ) ? (string) $v : '' );
            $data[ substr( sanitize_text_field( (string) $k ), 0, 80 ) ] = substr( sanitize_textarea_field( $v ), 0, 500 );
        }

        $status = self::record( $form_id, 'confirmed', $data, self::webhook_dedupe_key( $form_id, $request, $data ) );
        if ( 'error' === $status ) {
            // Not saved: answer with an error so the sender (Zapier, n8n, Apps Script...) retries later.
            WP_AI_Chatbot_AI::record_error( 'Verified lead webhook', new WP_Error( 'lead_not_saved', 'A verified submission for form "' . $form_id . '" could not be saved: ' . self::$last_db_error ) );
            return new WP_Error( 'not_saved', 'The submission could not be saved. Please retry.', array( 'status' => 503 ) );
        }
        return rest_ensure_response( array( 'success' => true, 'duplicate' => 'duplicate' === $status ) );
    }

    /**
     * De-duplication key for a webhook call: the sender's own submission ID when it
     * sends one, otherwise the answers themselves (same answers on the same day =
     * a retry). Null when there is nothing to tell submissions apart.
     */
    public static function webhook_dedupe_key( $form_id, $request, $data ) {
        $sid = (string) $request->get_header( 'X-AKDK-Submission-Id' );
        if ( '' === $sid ) {
            $sid = (string) $request->get_header( 'Idempotency-Key' );
        }
        foreach ( array( 'submission_id', 'event_id', 'response_id', 'entry_id' ) as $field ) {
            if ( '' === $sid && isset( $data[ $field ] ) && '' !== $data[ $field ] ) {
                $sid = (string) $data[ $field ];
            }
        }
        if ( '' !== $sid ) {
            return 'id:' . md5( $form_id . '|' . $sid );
        }
        if ( ! $data ) {
            return null;
        }
        ksort( $data );
        return 'h:' . md5( $form_id . '|' . wp_json_encode( $data ) . '|' . current_time( 'Y-m-d' ) );
    }

    /**
     * Saves an event. A non-empty $dedupe key is stored in a UNIQUE column, so the
     * same submission can only be recorded once, even when two retries arrive at once.
     *
     * @return string 'saved', 'duplicate' (same submission already recorded) or 'error' (not saved).
     */
    private static function record( $form_id, $event, $data = array(), $dedupe = null ) {
        self::ensure_table();
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE;
        $session = isset( $_COOKIE['wp_ai_chat_session'] ) ? strtolower( (string) wp_unslash( $_COOKIE['wp_ai_chat_session'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated below.
        $session = WP_AI_Chatbot_Chat_Renderer::valid_session_id( $session ) ? $session : '';
        $row     = array(
            'form_id'    => $form_id,
            'event'      => $event,
            'session_id' => substr( $session, 0, 100 ),
            'data'       => $data ? wp_json_encode( $data ) : '',
            'created_at' => current_time( 'mysql' ),
        );
        if ( $dedupe ) {
            $key    = substr( $dedupe, 0, 80 );
            $result = $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO {$table} (form_id, event, session_id, data, created_at, dedupe_key) VALUES (%s, %s, %s, %s, %s, %s)",
                $row['form_id'], $row['event'], $row['session_id'], $row['data'], $row['created_at'], $key
            ) );
            if ( (int) $result > 0 ) {
                return 'saved';
            }
            // Nothing inserted: a duplicate only if the key really exists (INSERT IGNORE also hides other errors).
            $exists = false !== $result && (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE dedupe_key = %s", $key ) ) > 0;
            if ( $exists ) {
                return 'duplicate';
            }
            self::$last_db_error = $wpdb->last_error ? $wpdb->last_error : 'the row was not inserted';
            return 'error';
        }
        if ( false === $wpdb->insert( $table, $row, array( '%s', '%s', '%s', '%s', '%s' ) ) ) {
            self::$last_db_error = $wpdb->last_error ? $wpdb->last_error : 'insert failed';
            return 'error';
        }
        return 'saved';
    }

    /** Latest confirmed submissions that came with data (for the Leads screen). */
    public static function recent_confirmed( $limit = 20 ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return array();
        }
        return (array) $wpdb->get_results( $wpdb->prepare( "SELECT form_id, data, created_at FROM {$table} WHERE event = 'confirmed' AND data <> '' ORDER BY id DESC LIMIT %d", $limit ) );
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    public static function source_of( $form ) {
        return ( isset( $form['source'] ) && in_array( $form['source'], self::SOURCES, true ) ) ? $form['source'] : 'builtin';
    }

    public static function is_external( $form ) {
        return 'builtin' !== self::source_of( $form );
    }

    public static function source_label( $source ) {
        $labels = array(
            'builtin'   => 'Built-in form',
            'link'      => 'External link / checkout',
            'directlink'=> 'Direct link',
            'embed'     => 'Embedded page (URL)',
            'shortcode' => 'WordPress form shortcode',
            'html'      => 'Embed code (HTML)',
        );
        return isset( $labels[ $source ] ) ? $labels[ $source ] : $labels['builtin'];
    }

    public static function frame_url( $form_id ) {
        return add_query_arg( self::QUERY_PARAM, rawurlencode( $form_id ), home_url( '/' ) );
    }

    /** Form data that is safe to send to the browser (no raw shortcode/HTML). */
    public static function public_form( $form ) {
        $source = self::source_of( $form );
        $out    = array(
            'id'     => (string) $form['id'],
            'title'  => isset( $form['title'] ) ? (string) $form['title'] : '',
            'source' => $source,
            'fields' => ( 'builtin' === $source && isset( $form['fields'] ) && is_array( $form['fields'] ) ) ? $form['fields'] : array(),
        );
        if ( 'builtin' !== $source ) {
            $out['note']   = isset( $form['ext_note'] ) ? (string) $form['ext_note'] : '';
            $out['button'] = ! empty( $form['ext_button'] ) ? (string) $form['ext_button'] : ( in_array( $source, array( 'link', 'directlink' ), true ) ? 'Open link' : 'Open in new tab' );
            $out['height'] = ! empty( $form['ext_height'] ) ? max( 200, min( 900, (int) $form['ext_height'] ) ) : 520;
            if ( in_array( $source, array( 'link', 'directlink', 'embed' ), true ) ) {
                $out['url'] = isset( $form['ext_url'] ) ? esc_url_raw( $form['ext_url'] ) : '';
            } else {
                $out['url'] = self::frame_url( $form['id'] );
            }
        }
        return $out;
    }

    private static function find_form( $form_id ) {
        $forms = get_option( 'wp_ai_chatbot_multi_forms', array() );
        foreach ( (array) $forms as $form ) {
            if ( isset( $form['id'] ) && $form['id'] === $form_id && ( ! isset( $form['is_enabled'] ) || '1' === $form['is_enabled'] ) ) {
                return $form;
            }
        }
        return null;
    }

    /* ------------------------------------------------------------------
     * Tracking
     * ------------------------------------------------------------------ */

    public static function ensure_table() {
        if ( get_option( 'wp_ai_form_events_db' ) === self::DB_VERSION ) {
            return;
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . self::TABLE;
        dbDelta(
            "CREATE TABLE {$table} (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                form_id varchar(60) NOT NULL DEFAULT '',
                event varchar(20) NOT NULL DEFAULT '',
                session_id varchar(100) NOT NULL DEFAULT '',
                data text NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
                dedupe_key varchar(80) NULL DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY dedupe_key (dedupe_key),
                KEY form_event (form_id, event),
                KEY created_at (created_at)
            ) " . $wpdb->get_charset_collate() . ';'
        );
        update_option( 'wp_ai_form_events_db', self::DB_VERSION, false );
    }

    public function register_routes() {
        register_rest_route(
            'wp-ai-chatbot/v1',
            '/track',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'track' ),
                'permission_callback' => '__return_true',
            )
        );
        register_rest_route(
            'wp-ai-chatbot/v1',
            '/webhook/(?P<form_id>[a-z0-9_]+)',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'webhook' ),
                'permission_callback' => '__return_true', // Checked with the secret key inside the callback.
            )
        );
    }

    /** Events reported by the visitor's browser (never counted as verified leads). */
    public function track( WP_REST_Request $request ) {
        $ip = WP_AI_Chatbot_Rate_Limiter::get_client_ip();
        if ( ! WP_AI_Chatbot_Rate_Limiter::allow( 'track_' . $ip, 30, 60 ) ) {
            return new WP_Error( 'rate_limited', 'Too many requests.', array( 'status' => 429 ) );
        }
        $form_id = sanitize_key( (string) $request->get_param( 'form_id' ) );
        $event   = sanitize_key( (string) $request->get_param( 'event' ) );
        if ( ! in_array( $event, self::EVENTS, true ) || ! self::find_form( $form_id ) ) {
            return new WP_Error( 'invalid', 'Invalid event.', array( 'status' => 400 ) );
        }
        self::record( $form_id, $event );
        return rest_ensure_response( array( 'success' => true ) );
    }

    /** Event counts per form for a WHERE clause on created_at: [ form_id => [ event => count ] ]. */
    public static function counts( $where ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return array();
        }
        $out = array();
        foreach ( (array) $wpdb->get_results( "SELECT form_id, event, COUNT(*) c FROM {$table} {$where} GROUP BY form_id, event", ARRAY_A ) as $row ) {
            $out[ $row['form_id'] ][ $row['event'] ] = (int) $row['c'];
        }
        return $out;
    }

    /** Daily verified (confirmed) submissions of external forms: [ Y-m-d => count ]. */
    public static function daily_submissions( $where ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return array();
        }
        $out = array();
        foreach ( (array) $wpdb->get_results( "SELECT DATE(created_at) d, COUNT(*) c FROM {$table} {$where} AND event = 'confirmed' GROUP BY DATE(created_at)", ARRAY_A ) as $row ) {
            $out[ $row['d'] ] = (int) $row['c'];
        }
        return $out;
    }

    /* ------------------------------------------------------------------
     * Frame page for shortcodes and embed code
     * ------------------------------------------------------------------ */

    public function render_frame() {
        if ( ! isset( $_GET[ self::QUERY_PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }
        $form_id = sanitize_key( wp_unslash( $_GET[ self::QUERY_PARAM ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $form    = self::find_form( $form_id );
        $source  = $form ? self::source_of( $form ) : '';
        if ( ! in_array( $source, array( 'shortcode', 'html' ), true ) ) {
            status_header( 404 );
            exit;
        }

        if ( ! defined( 'WP_AI_CHATBOT_IN_FORM_FRAME' ) ) {
            define( 'WP_AI_CHATBOT_IN_FORM_FRAME', true ); // Keeps the chat widget itself out of the frame.
        }
        show_admin_bar( false );
        header( 'X-Frame-Options: SAMEORIGIN' );
        // Fallback for form plugins whose requests carry no referer (checked against the form ID).
        if ( ! headers_sent() ) {
            setcookie( self::COOKIE, $form_id, array( 'expires' => time() + 1800, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
        }
        header( 'X-Robots-Tag: noindex, nofollow' );
        nocache_headers();

        $content = isset( $form['ext_code'] ) ? (string) $form['ext_code'] : '';
        $content = 'shortcode' === $source ? do_shortcode( $content ) : $content;
        ?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<?php wp_head(); ?>
<style>
html, body { margin: 0 !important; padding: 0 !important; background: transparent !important; }
body.akdk-form-frame { padding: 4px 2px 8px !important; font-size: 14px; }
.akdk-frame-content { max-width: 100%; overflow-x: hidden; }
.akdk-frame-content input:not([type=checkbox]):not([type=radio]):not([type=submit]), .akdk-frame-content select, .akdk-frame-content textarea { max-width: 100%; box-sizing: border-box; }
</style>
</head>
<body class="akdk-form-frame">
<div class="akdk-frame-content">
<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode output / embed code saved by an administrator. ?>
</div>
<?php wp_footer(); ?>
<script>
(function () {
    var id = <?php echo wp_json_encode( $form_id ); ?>, sent = false;
    function post(msg) { msg.akdkForm = id; try { window.parent.postMessage(msg, window.location.origin); } catch (e) {} }
    function height() { var b = document.body; post({ type: 'height', height: Math.ceil(b.getBoundingClientRect().height + parseFloat(getComputedStyle(b).marginTop || 0) + parseFloat(getComputedStyle(b).marginBottom || 0)) }); }
    function done() { if (sent) return; sent = true; post({ type: 'submitted' }); setTimeout(height, 300); }
    window.addEventListener('load', height);
    if (window.ResizeObserver) new ResizeObserver(height).observe(document.body); else setInterval(height, 800);
    // Success events of popular form plugins.
    ['wpcf7mailsent', 'wpformsAjaxSubmitSuccess', 'fluentform_submission_success', 'forminator:form:submit:success', 'nfFormSubmitResponse', 'elementor/forms/success']
        .forEach(function (ev) { document.addEventListener(ev, done); });
    if (window.jQuery) {
        jQuery(document).on('gform_confirmation_loaded wpformsAjaxSubmitSuccess fluentform_submission_success submit_success', done);
        // Add to cart is not a lead: reported separately.
        jQuery(document.body).on('added_to_cart', function () { post({ type: 'cart' }); });
    }
    // HubSpot / generic embeds that post messages.
    window.addEventListener('message', function (e) {
        var d = e.data || {};
        if (d.event === 'calendly.event_scheduled') { post({ type: 'booked' }); return; }
        if ((d.type === 'hsFormCallback' && d.eventName === 'onFormSubmitted') || d.type === 'form-submit') done();
    });
    // Plain HTML forms that reload the page after submitting.
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (f && !f.hasAttribute('data-akdk-ajax') && !/wpcf7|wpforms|fluentform|gform|forminator|nf-form|elementor/.test(f.className + ' ' + (f.id || ''))) {
            try { sessionStorage.setItem('akdk_frame_submitted_' + id, '1'); } catch (err) {}
        }
    }, true);
    try { if (sessionStorage.getItem('akdk_frame_submitted_' + id) === '1') { sessionStorage.removeItem('akdk_frame_submitted_' + id); done(); } } catch (err) {}
})();
</script>
</body>
</html>
        <?php
        exit;
    }
}
