<?php
/**
 * Analytics dashboard with date filters and a built-in "Data Analyst" AI assistant.
 *
 * @package AK_AI_Chatbot
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_AI_Chatbot_Dashboard {

    public function register_hooks() {
        add_action( 'admin_menu', array( $this, 'add_dashboard_menu' ) ); 
        add_action( 'admin_init', array( $this, 'init_analytics_table' ) );
        add_action( 'wp_ajax_wp_ai_get_dashboard_data', array( $this, 'ajax_get_dashboard_data' ) );
        add_action( 'wp_ajax_wp_ai_sync_historical_data', array( $this, 'ajax_sync_historical_data' ) );
        add_action( 'wp_ajax_wp_ai_admin_dashboard_chat', array( $this, 'ajax_admin_dashboard_chat' ) );
        add_action( 'admin_init', array( $this, 'register_design_setting' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    public function add_dashboard_menu() {
        add_submenu_page(
            'wp-ai-chatbot',
            'Dashboard & Analytics',
            'Dashboard',
            'manage_options',
            'wp-ai-chatbot-dashboard',
            array( $this, 'display_dashboard' )
        );
    }

    public function init_analytics_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ai_chat_analytics';
        
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
            $charset_collate = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE $table_name (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                session_id varchar(100) NOT NULL,
                prompt_tokens int(11) DEFAULT 0,
                completion_tokens int(11) DEFAULT 0,
                embedding_tokens int(11) DEFAULT 0,
                response_time float DEFAULT 0,
                is_fallback tinyint(1) DEFAULT 0,
                token_source varchar(10) NOT NULL DEFAULT 'estimate',
                unverified tinyint(1) NOT NULL DEFAULT 0,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY session_idx (session_id),
                KEY created_idx (created_at)
            ) $charset_collate;";
            
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta( $sql );
        }
    }

    public function ajax_admin_dashboard_chat() {
        check_ajax_referer( 'wp_ai_dashboard_nonce', 'security' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Not allowed.', 403 ); }
        
        $admin_message = isset( $_POST['message'] ) ? sanitize_text_field( wp_unslash( $_POST['message'] ) ) : '';
        $context_data  = isset( $_POST['context'] ) ? sanitize_textarea_field( wp_unslash( $_POST['context'] ) ) : '';
        $history_data  = isset( $_POST['history'] ) ? sanitize_textarea_field( wp_unslash( $_POST['history'] ) ) : '';
        if ( '' === $admin_message ) {
            wp_send_json_error( 'Please type a question.' );
        }
        // Keep the request size reasonable.
        $context_data = substr( $context_data, 0, 20000 );
        $history_data = substr( $history_data, 0, 12000 );

        $system_prompt = "SYSTEM CONTEXT: You are a professional AI Data Analyst for this WordPress dashboard ONLY. You are completely isolated from the frontend website. You DO NOT answer questions about frontend products. You ONLY analyze the dashboard data provided below and answer the admin's queries, which includes system configurations, models used, tokens, and form conversions.\n\n";
        $system_prompt .= "STRICT BEHAVIOR RULES:\n";
        $system_prompt .= "- BE SHORT AND TO THE POINT. Use short bullet points.\n";
        $system_prompt .= "- Identify yourself simply as the 'Data Analyst'. Do not use any specific company brand names for yourself.\n";
        $system_prompt .= "- DO NOT repeat your intro. Only state your role if explicitly asked or on the first greeting.\n";
        $system_prompt .= "- ALWAYS use Markdown (boldings, lists).\n";
        $system_prompt .= "- Use the 'Hourly Activity' data in the JSON below to answer questions about 'peak hours' or 'busiest times'. Find the time with the highest numeric value.\n\n";
        $system_prompt .= "LIVE SYSTEM DATA (JSON FORMAT):\n" . $context_data . "\n\n";
        
        if (!empty($history_data)) {
            $system_prompt .= "RECENT CHAT HISTORY (MEMORY):\n" . $history_data . "\n\n";
        }

        if ($admin_message === 'INIT_GREETING') {
            $user_prompt = "TASK: The admin just opened the dashboard. Write a 2-sentence welcome message introducing yourself as the Data Analyst. Give a 1-sentence quick health check of the data provided.";
        } else {
            $user_prompt = "ADMIN QUESTION: " . $admin_message;
        }

        if ( '' === WP_AI_Chatbot_AI::api_key( WP_AI_Chatbot_AI::chat_provider() ) ) {
            wp_send_json_error( 'API key is missing. Add it under AI Chatbot → Settings.' );
        }

        $start_time = microtime( true );
        WP_AI_Chatbot_AI::reset_usage();
        $bot_reply  = WP_AI_Chatbot_AI::chat(
            $system_prompt,
            array( array( 'role' => 'user', 'content' => $user_prompt ) ),
            array( 'temperature' => 0.4 )
        );
        $response_time = round( microtime( true ) - $start_time, 2 );

        if ( is_wp_error( $bot_reply ) ) {
            WP_AI_Chatbot_AI::record_error( 'Dashboard analyst', $bot_reply );
            wp_send_json_error( 'AI error: ' . $bot_reply->get_error_message() );
        }

        $usage             = WP_AI_Chatbot_AI::usage();
        $prompt_tokens     = $usage['prompt'];
        $completion_tokens = $usage['completion'];

        global $wpdb;
        $analytics_table = $wpdb->prefix . 'ai_chat_analytics';
        $wpdb->insert($analytics_table, array(
            'session_id' => 'admin_dashboard_bot',
            'prompt_tokens' => $prompt_tokens,
            'completion_tokens' => $completion_tokens,
            'embedding_tokens' => 0,
            'response_time' => $response_time,
            'is_fallback' => 0,
            'token_source' => $usage['exact'] ? 'api' : 'estimate',
            'created_at' => current_time('mysql')
        ));

        wp_send_json_success(array('reply' => $bot_reply));
    }

    public function ajax_sync_historical_data() {
        check_ajax_referer( 'wp_ai_dashboard_nonce', 'security' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Not allowed.', 403 ); }
        global $wpdb;
        $logs_table = $wpdb->prefix . 'ai_chat_logs';
        $analytics_table = $wpdb->prefix . 'ai_chat_analytics';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $analytics_table ) ) === $analytics_table ) {
            $wpdb->query("UPDATE $analytics_table SET embedding_tokens = prompt_tokens WHERE embedding_tokens = 0");
        }

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $logs_table ) ) === $logs_table ) {
            $existing_analytics = $wpdb->get_var("SELECT COUNT(*) FROM $analytics_table WHERE session_id != 'admin_dashboard_bot'");
            if ($existing_analytics == 0) {
                $old_logs = $wpdb->get_results("SELECT * FROM $logs_table");
                if (!empty($old_logs)) {
                    foreach ($old_logs as $log) {
                        $user_msg_len = isset($log->user_message) ? strlen($log->user_message) : 0;
                        $bot_msg_len = isset($log->bot_reply) ? strlen($log->bot_reply) : 0;
                        $wpdb->insert($analytics_table, array(
                            'session_id' => isset($log->session_id) ? $log->session_id : 'historical_user',
                            'prompt_tokens' => ceil($user_msg_len / 4),
                            'completion_tokens' => ceil($bot_msg_len / 4),
                            'embedding_tokens' => ceil($user_msg_len / 4),
                            'created_at' => isset($log->created_at) ? $log->created_at : current_time('mysql')
                        ));
                    }
                    wp_send_json_success('Historical data synchronized successfully.');
                }
                wp_send_json_success('No historical chats to import.');
            } else { wp_send_json_success('Historical data updated! Embeddings recalculated.'); }
        } else { wp_send_json_error('No historical data found.'); }
    }

    /* ------------------------------------------------------------------
     * Design settings (chart colours & style)
     * ------------------------------------------------------------------ */

    const DESIGN_OPTION = 'wp_ai_chatbot_dashboard_design';

    public static function design_defaults() {
        return array(
            'c1'     => '#fd9400', // Conversations (brand orange)
            'c2'     => '#0a0a0a', // Leads (brand black)
            'c3'     => '#2a78d6',
            'c4'     => '#1baf7a',
            'c5'     => '#4a3aa7',
            'c6'     => '#e87ba4',
            'fill'   => '1',
            'smooth' => '1',
            'grid'   => '1',
            // Prices in USD per 1 million tokens. Empty = the plugin's default for known models.
            'rate_in'    => '',
            'rate_out'   => '',
            'rate_embed' => '',
        );
    }

    /** Built-in prices (USD per 1M tokens) for the default OpenAI models. Other models need the admin's own rates. */
    const KNOWN_RATES = array(
        'gpt-4o-mini'            => array( 'in' => 0.15, 'out' => 0.60 ),
        'text-embedding-3-small' => array( 'embed' => 0.02 ),
    );

    /**
     * Prices used for the cost estimate: the admin's own rates (Dashboard, Design),
     * otherwise the built-in price of the selected model when it is known.
     *
     * @return array in, out, embed (floats or null when unknown) and the model names.
     */
    public static function cost_rates() {
        $d          = self::design();
        $chat       = WP_AI_Chatbot_AI::chat_model();
        $embed      = WP_AI_Chatbot_AI::embedding_model();
        $known_chat = isset( self::KNOWN_RATES[ $chat ] ) ? self::KNOWN_RATES[ $chat ] : array();
        $known_emb  = isset( self::KNOWN_RATES[ $embed ] ) ? self::KNOWN_RATES[ $embed ] : array();
        $pick       = function ( $own, $known ) {
            return '' !== (string) $own ? (float) $own : ( null === $known ? null : (float) $known );
        };
        return array(
            'in'          => $pick( $d['rate_in'], isset( $known_chat['in'] ) ? $known_chat['in'] : null ),
            'out'         => $pick( $d['rate_out'], isset( $known_chat['out'] ) ? $known_chat['out'] : null ),
            'embed'       => $pick( $d['rate_embed'], isset( $known_emb['embed'] ) ? $known_emb['embed'] : null ),
            'chat_model'  => $chat,
            'embed_model' => $embed,
        );
    }

    public static function design() {
        $saved = get_option( self::DESIGN_OPTION, array() );
        $saved = is_array( $saved ) ? $saved : array();
        return array_merge( self::design_defaults(), array_intersect_key( $saved, self::design_defaults() ) );
    }

    public function register_design_setting() {
        register_setting( 'wp_ai_dashboard_design_group', self::DESIGN_OPTION, array( 'sanitize_callback' => array( $this, 'sanitize_design' ) ) );
    }

    public function sanitize_design( $input ) {
        $defaults = self::design_defaults();
        $input    = is_array( $input ) ? $input : array();
        $clean    = array();
        foreach ( array( 'c1', 'c2', 'c3', 'c4', 'c5', 'c6' ) as $key ) {
            $color         = isset( $input[ $key ] ) ? sanitize_hex_color( $input[ $key ] ) : '';
            $clean[ $key ] = $color ? $color : $defaults[ $key ];
        }
        foreach ( array( 'fill', 'smooth', 'grid' ) as $key ) {
            $clean[ $key ] = ( isset( $input[ $key ] ) && '1' === $input[ $key ] ) ? '1' : '0';
        }
        foreach ( array( 'rate_in', 'rate_out', 'rate_embed' ) as $key ) {
            $value         = isset( $input[ $key ] ) ? trim( str_replace( ',', '.', (string) $input[ $key ] ) ) : '';
            $clean[ $key ] = ( '' !== $value && is_numeric( $value ) && (float) $value >= 0 ) ? (string) round( (float) $value, 6 ) : '';
        }
        return $clean;
    }

    public function enqueue_assets( $hook ) {
        if ( false === strpos( (string) $hook, 'wp-ai-chatbot-dashboard' ) ) {
            return;
        }
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'akdk-dashboard', WP_AI_CHATBOT_URL . 'admin/js/akdk-dashboard.js', array( 'jquery', 'wp-color-picker' ), WP_AI_CHATBOT_VERSION, true );
        wp_localize_script(
            'akdk-dashboard',
            'akdkDash',
            array(
                'ajaxurl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'wp_ai_dashboard_nonce' ),
                'design'  => self::design(),
                'defaults'=> self::design_defaults(),
            )
        );
    }

    /* ------------------------------------------------------------------
     * Data
     * ------------------------------------------------------------------ */

    /** Start and end date (Y-m-d) for a filter, plus a SQL WHERE clause on created_at. */
    private function date_range( $filter, $start_date, $end_date ) {
        global $wpdb;
        $today = current_time( 'Y-m-d' );
        $day   = function ( $offset ) use ( $today ) {
            return gmdate( 'Y-m-d', strtotime( $today . ' ' . $offset ) );
        };
        switch ( $filter ) {
            case 'today':
                $from = $today; $to = $today; break;
            case 'yesterday':
                $from = $day( '-1 day' ); $to = $from; break;
            case '30days':
                $from = $day( '-29 days' ); $to = $today; break;
            case 'this_month':
                $from = gmdate( 'Y-m-01', strtotime( $today ) ); $to = $today; break;
            case 'last_month':
                $from = gmdate( 'Y-m-01', strtotime( $today . ' first day of last month' ) );
                $to   = gmdate( 'Y-m-t', strtotime( $from ) );
                break;
            case 'custom':
                $valid = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_date ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_date );
                $from  = $valid ? min( $start_date, $end_date ) : $day( '-6 days' );
                $to    = $valid ? max( $start_date, $end_date ) : $today;
                break;
            case 'all':
                $table    = $wpdb->prefix . 'ai_chat_analytics';
                $earliest = $wpdb->get_var( "SELECT DATE(MIN(created_at)) FROM {$table}" );
                $from     = $earliest ? max( $earliest, $day( '-365 days' ) ) : $today;
                $to       = $today;
                break;
            case '7days':
            default:
                $from = $day( '-6 days' ); $to = $today; break;
        }
        $where = $wpdb->prepare( 'WHERE created_at >= %s AND created_at < %s', $from . ' 00:00:00', gmdate( 'Y-m-d', strtotime( $to . ' +1 day' ) ) . ' 00:00:00' );
        return array( $from, $to, $where );
    }

    public function ajax_get_dashboard_data() {
        check_ajax_referer( 'wp_ai_dashboard_nonce', 'security' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Not allowed.', 403 );
        }
        global $wpdb;
        $analytics_table = $wpdb->prefix . 'ai_chat_analytics';
        $vectors_table   = $wpdb->prefix . 'ai_vector_embeddings';

        $filter     = isset( $_POST['date_filter'] ) ? sanitize_key( wp_unslash( $_POST['date_filter'] ) ) : '7days';
        $start_date = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
        $end_date   = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
        list( $from, $to, $where ) = $this->date_range( $filter, $start_date, $end_date );

        // Every day in the range, so charts show gaps as zero.
        $dates  = array();
        $cursor = strtotime( $from );
        $end    = strtotime( $to );
        while ( $cursor <= $end && count( $dates ) < 400 ) {
            $dates[] = gmdate( 'Y-m-d', $cursor );
            $cursor  = strtotime( '+1 day', $cursor );
        }
        $zero   = array_fill_keys( $dates, 0 );
        $labels = array_map( function ( $d ) { return gmdate( 'M j', strtotime( $d ) ); }, $dates );

        $user_where  = $where . " AND session_id != 'admin_dashboard_bot'";
        $admin_where = $where . " AND session_id = 'admin_dashboard_bot'";
        $has_table   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $analytics_table ) ) === $analytics_table;

        $daily_q = $zero; $daily_v = $zero; $daily_f = $zero; $daily_rt = $zero; $daily_in = $zero; $daily_out = $zero;
        $totals  = array( 'q' => 0, 'v' => 0, 'f' => 0, 'pi' => 0, 'po' => 0, 'pe' => 0, 'rt' => 0, 'admin' => 0, 'admin_in' => 0, 'admin_out' => 0, 'engaged' => 0, 'exact_rows' => 0, 'unverified' => 0 );
        $hourly_q = array_fill( 0, 24, 0 );

        if ( $has_table ) {
            $row = $wpdb->get_row( "SELECT COUNT(*) q, COUNT(DISTINCT session_id) v, SUM(is_fallback) f, SUM(prompt_tokens) pi, SUM(completion_tokens) po, SUM(embedding_tokens) pe, AVG(NULLIF(response_time,0)) rt, SUM(token_source = 'api') exact_rows, SUM(unverified) unverified FROM {$analytics_table} {$user_where}", ARRAY_A );
            if ( is_array( $row ) ) {
                foreach ( array( 'q', 'v', 'f', 'pi', 'po', 'pe' ) as $k ) {
                    $totals[ $k ] = (int) $row[ $k ];
                }
                $totals['rt'] = (float) $row['rt'];
                $totals['exact_rows'] = (int) $row['exact_rows'];
                $totals['unverified'] = (int) $row['unverified'];
            }
            $admin_row         = $wpdb->get_row( "SELECT SUM(prompt_tokens) i, SUM(completion_tokens) o FROM {$analytics_table} {$admin_where}", ARRAY_A );
            $totals['admin_in']  = $admin_row ? (int) $admin_row['i'] : 0;
            $totals['admin_out'] = $admin_row ? (int) $admin_row['o'] : 0;
            $totals['admin']     = $totals['admin_in'] + $totals['admin_out'];
            $totals['engaged'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT session_id FROM {$analytics_table} {$user_where} GROUP BY session_id HAVING COUNT(*) >= 2) engaged" );

            $rows = $wpdb->get_results( "SELECT DATE(created_at) d, COUNT(*) q, COUNT(DISTINCT session_id) v, SUM(is_fallback) f, AVG(NULLIF(response_time,0)) rt, SUM(prompt_tokens) pi, SUM(completion_tokens) po FROM {$analytics_table} {$user_where} GROUP BY DATE(created_at)", ARRAY_A );
            foreach ( (array) $rows as $r ) {
                if ( ! isset( $zero[ $r['d'] ] ) ) {
                    continue;
                }
                $daily_q[ $r['d'] ]   = (int) $r['q'];
                $daily_v[ $r['d'] ]   = (int) $r['v'];
                $daily_f[ $r['d'] ]   = (int) $r['f'];
                $daily_rt[ $r['d'] ]  = round( (float) $r['rt'], 2 );
                $daily_in[ $r['d'] ]  = (int) $r['pi'];
                $daily_out[ $r['d'] ] = (int) $r['po'];
            }
            foreach ( (array) $wpdb->get_results( "SELECT HOUR(created_at) h, COUNT(*) c FROM {$analytics_table} {$user_where} GROUP BY HOUR(created_at)", ARRAY_A ) as $r ) {
                $hourly_q[ (int) $r['h'] ] = (int) $r['c'];
            }
        }

        // Leads, per form and per day.
        $forms        = get_option( 'wp_ai_chatbot_multi_forms', array() );
        $forms        = is_array( $forms ) ? $forms : array();
        $daily_leads  = $zero;
        $hourly_leads = array_fill( 0, 24, 0 );
        $by_form      = array();
        $total_leads  = 0;
        $ext_counts = WP_AI_Chatbot_External_Forms::counts( $where );
        $ext_clicks   = 0;
        $ext_reported = 0;
        foreach ( WP_AI_Chatbot_External_Forms::daily_submissions( $where ) as $d => $c ) {
            if ( isset( $daily_leads[ $d ] ) ) {
                $daily_leads[ $d ] += $c;
            }
        }
        foreach ( $forms as $f ) {
            if ( empty( $f['id'] ) ) {
                continue;
            }
            if ( WP_AI_Chatbot_External_Forms::is_external( $f ) ) {
                $events      = isset( $ext_counts[ $f['id'] ] ) ? $ext_counts[ $f['id'] ] : array();
                // Only server-confirmed submissions count as leads; browser reports are shown separately.
                $confirmed     = isset( $events['confirmed'] ) ? (int) $events['confirmed'] : 0;
                $ext_clicks   += isset( $events['click'] ) ? (int) $events['click'] : 0;
                $ext_reported += ( isset( $events['submitted'] ) ? (int) $events['submitted'] : 0 ) + ( isset( $events['booked'] ) ? (int) $events['booked'] : 0 );
                $total_leads  += $confirmed;
                $by_form[]     = array( 'label' => isset( $f['title'] ) ? (string) $f['title'] : (string) $f['id'], 'value' => $confirmed );
                continue;
            }
            $tbl = $wpdb->prefix . 'ai_leads_' . sanitize_key( $f['id'] );
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) !== $tbl ) {
                continue;
            }
            $count = 0;
            foreach ( (array) $wpdb->get_results( "SELECT DATE(created_at) d, COUNT(*) c FROM {$tbl} {$where} GROUP BY DATE(created_at)", ARRAY_A ) as $r ) {
                $count += (int) $r['c'];
                if ( isset( $daily_leads[ $r['d'] ] ) ) {
                    $daily_leads[ $r['d'] ] += (int) $r['c'];
                }
            }
            foreach ( (array) $wpdb->get_results( "SELECT HOUR(created_at) h, COUNT(*) c FROM {$tbl} {$where} GROUP BY HOUR(created_at)", ARRAY_A ) as $r ) {
                $hourly_leads[ (int) $r['h'] ] += (int) $r['c'];
            }
            $total_leads += $count;
            $by_form[]    = array( 'label' => isset( $f['title'] ) ? (string) $f['title'] : (string) $f['id'], 'value' => $count );
        }

        // Knowledge base.
        $vec_inventory = array();
        $outdated      = 0;
        $outdated_list = array();
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $vectors_table ) ) === $vectors_table ) {
            foreach ( (array) $wpdb->get_results( "SELECT source_type, COUNT(DISTINCT source_id) c FROM {$vectors_table} GROUP BY source_type", ARRAY_A ) as $r ) {
                $label = WP_AI_Chatbot_PDF_Knowledge::SOURCE === $r['source_type'] ? 'PDF Parts' : ucwords( str_replace( '_', ' ', (string) $r['source_type'] ) );
                $vec_inventory[] = array( 'label' => $label, 'value' => (int) $r['c'] );
            }
            $stale_sql = "FROM {$vectors_table} v INNER JOIN {$wpdb->posts} p ON v.source_id = p.ID AND v.source_type IN ('wc_product','wp_page','wp_post','tutor_course') WHERE v.chunk_index = 0 AND ( p.post_modified > COALESCE(v.source_modified, v.updated_at) OR v.index_format < " . (int) WP_AI_Chatbot_Knowledge_Index::INDEX_FORMAT . ' )';
            $outdated  = (int) $wpdb->get_var( "SELECT COUNT(*) {$stale_sql}" );
            if ( $outdated ) {
                foreach ( (array) $wpdb->get_results( "SELECT p.ID, p.post_title {$stale_sql} LIMIT 5" ) as $item ) {
                    $outdated_list[] = array( 'title' => $item->post_title ? $item->post_title : 'ID ' . $item->ID, 'url' => (string) get_edit_post_link( $item->ID, 'raw' ) );
                }
            }
        }

        $q         = $totals['q'];
        $visitors  = $totals['v'];
        $answered  = max( 0, $q - $totals['f'] );
        // Cost: chats + admin assistant + knowledge base indexing + voice, at the selected models' prices.
        $index_use = WP_AI_Chatbot_Knowledge_Index::index_usage( $from, $to );
        $rates     = self::cost_rates();
        $rates_ok  = null !== $rates['in'] && null !== $rates['out'] && null !== $rates['embed'];
        $per_m     = function ( $tokens, $rate ) {
            return null === $rate ? 0 : ( $tokens / 1000000 ) * $rate;
        };
        $cost_chat  = $per_m( $totals['pi'], $rates['in'] ) + $per_m( $totals['po'], $rates['out'] ) + $per_m( $totals['pe'], $rates['embed'] );
        $cost_admin = $per_m( $totals['admin_in'], $rates['in'] ) + $per_m( $totals['admin_out'], $rates['out'] );
        $cost_index = $per_m( $index_use['tokens'], $rates['embed'] );
        // Voice input (all-languages mode): audio is billed as input tokens of the Gemini model.
        $voice_use  = WP_AI_Chatbot_Voice::usage( $from, $to );
        $cost_voice = $per_m( $voice_use['in'], $rates['in'] ) + $per_m( $voice_use['out'], $rates['out'] );
        $est_cost   = $cost_chat + $cost_admin + $cost_index + $cost_voice;
        $hour_lbls = array();
        for ( $i = 0; $i < 24; $i++ ) {
            $hour_lbls[] = gmdate( 'g A', mktime( $i, 0, 0, 1, 1, 2020 ) );
        }

        $kpi = array(
            'conversations'  => $q,
            'visitors'       => $visitors,
            'leads'          => $total_leads,
            'conversion'     => $visitors ? round( 100 * $total_leads / $visitors, 1 ) : 0,
            'answer_rate'    => $q ? round( 100 * $answered / $q, 1 ) : 0,
            'fallbacks'      => $totals['f'],
            'avg_response'   => round( $totals['rt'], 2 ),
            'tokens_in'      => $totals['pi'],
            'tokens_out'     => $totals['po'],
            'tokens_embed'   => $totals['pe'],
            'tokens_admin'   => $totals['admin'],
            'est_cost'       => round( $est_cost, 4 ),
            'cost_chat'      => round( $cost_chat, 4 ),
            'cost_admin'     => round( $cost_admin, 4 ),
            'cost_index'     => round( $cost_index, 4 ),
            'cost_voice'     => round( $cost_voice, 4 ),
            'voice_clips'    => $voice_use['clips'],
            'tokens_voice'   => $voice_use['in'] + $voice_use['out'],
            'rates_complete' => $rates_ok,
            'tokens_index'   => $index_use['tokens'],
            'tokens_index_estimated' => $index_use['estimated'],
            'outdated'       => $outdated,
            'external_clicks'=> $ext_clicks,
            'external_reported' => $ext_reported,
            'exact_share'    => $q ? round( 100 * $totals['exact_rows'] / $q ) : 0,
            'unverified'     => $totals['unverified'],
        );

        $context = array(
            'Date range'        => $from . ' to ' . $to,
            'System'            => array(
                'Chat provider'  => WP_AI_Chatbot_AI::provider_label( WP_AI_Chatbot_AI::chat_provider(), true ),
                'Chat model'     => WP_AI_Chatbot_AI::chat_model(),
                'RAG provider'   => ucfirst( WP_AI_Chatbot_AI::embedding_provider() ),
                'Embedding model'=> WP_AI_Chatbot_AI::embedding_model(),
            ),
            'Totals'            => $kpi,
            'Engaged visitors (2+ messages)' => $totals['engaged'],
            'Leads by form'     => wp_list_pluck( $by_form, 'value', 'label' ),
            'Daily conversations' => array_combine( $dates, array_values( $daily_q ) ),
            'Daily leads'       => array_combine( $dates, array_values( $daily_leads ) ),
            'Hourly activity (Chat Count)' => array_combine( $hour_lbls, $hourly_q ),
            'Hourly leads'      => array_combine( $hour_lbls, $hourly_leads ),
            'Knowledge base items' => wp_list_pluck( $vec_inventory, 'value', 'label' ),
        );

        wp_send_json_success(
            array(
                'range'        => array( 'from' => $from, 'to' => $to ),
                'kpi'          => $kpi,
                'labels'       => $labels,
                'dates'        => $dates,
                'daily'        => array(
                    'conversations' => array_values( $daily_q ),
                    'visitors'      => array_values( $daily_v ),
                    'leads'         => array_values( $daily_leads ),
                    'fallbacks'     => array_values( $daily_f ),
                    'response'      => array_values( $daily_rt ),
                    'tokens_in'     => array_values( $daily_in ),
                    'tokens_out'    => array_values( $daily_out ),
                ),
                'hours'        => $hour_lbls,
                'hourly'       => array( 'conversations' => $hourly_q, 'leads' => $hourly_leads ),
                'by_form'      => $by_form,
                'funnel'       => array(
                    array( 'label' => 'Visitors', 'value' => $visitors ),
                    array( 'label' => 'Engaged (2+ messages)', 'value' => $totals['engaged'] ),
                    array( 'label' => 'Leads', 'value' => $total_leads ),
                ),
                'engine'       => $context['System'],
                'vectors'      => $vec_inventory,
                'outdated_list'=> $outdated_list,
                'raw_context_string' => wp_json_encode( $context ),
            )
        );
    }

    /* ------------------------------------------------------------------
     * Page
     * ------------------------------------------------------------------ */

    public function display_dashboard() {
        $tab    = ( isset( $_GET['tab'] ) && 'design' === $_GET['tab'] ) ? 'design' : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $design = self::design();
        $base   = admin_url( 'admin.php?page=wp-ai-chatbot-dashboard' );
        $today  = current_time( 'Y-m-d' );
        ?>
        <div class="wrap akdk-wrap akdk-dash" data-tab="<?php echo esc_attr( $tab ); ?>">
            <div class="akdk-dash-head">
                <div>
                    <h1 class="akdk-page-title">Dashboard</h1>
                    <p class="akdk-page-intro">How your chatbot is performing: conversations, leads and answer quality.</p>
                </div>
                <nav class="akdk-seg" aria-label="Dashboard views">
                    <a class="<?php echo 'overview' === $tab ? 'is-active' : ''; ?>" href="<?php echo esc_url( $base ); ?>">Overview</a>
                    <a class="<?php echo 'design' === $tab ? 'is-active' : ''; ?>" href="<?php echo esc_url( $base . '&tab=design' ); ?>">Design</a>
                </nav>
            </div>
            <?php settings_errors(); ?>

            <?php if ( 'design' === $tab ) : ?>
                <?php $this->render_design_tab( $design ); ?>
            <?php else : ?>

            <div class="akdk-filters">
                <label for="dashboard-date-filter" class="screen-reader-text">Date range</label>
                <select id="dashboard-date-filter">
                    <option value="today">Today</option>
                    <option value="yesterday">Yesterday</option>
                    <option value="7days" selected>Last 7 days</option>
                    <option value="30days">Last 30 days</option>
                    <option value="this_month">This month</option>
                    <option value="last_month">Last month</option>
                    <option value="all">All time</option>
                    <option value="custom">Custom range…</option>
                </select>
                <span id="custom-date-range" class="akdk-custom-range" hidden>
                    <input type="date" id="dash-start-date" max="<?php echo esc_attr( $today ); ?>" aria-label="From">
                    <span>to</span>
                    <input type="date" id="dash-end-date" max="<?php echo esc_attr( $today ); ?>" aria-label="To">
                    <button type="button" id="apply-custom-date" class="button">Apply</button>
                </span>
                <span class="akdk-range-label" id="akdk-range-label"></span>
                <span class="spinner" id="dashboard-spinner"></span>
                <button type="button" id="sync-history-btn" class="button akdk-push-right" title="Import older chat logs into the analytics">Sync history</button>
            </div>

            <?php WP_AI_Chatbot_Unanswered::dashboard_card(); ?>

            <div class="akdk-kpis" id="akdk-kpis">
                <?php
                $tiles = array(
                    'conversations' => array( 'Conversations', 'c1' ),
                    'visitors'      => array( 'Visitors', 'c3' ),
                    'leads'         => array( 'Leads', 'c2' ),
                    'conversion'    => array( 'Conversion rate', 'c4' ),
                    'answer_rate'   => array( 'Answer rate', 'c5' ),
                    'avg_response'  => array( 'Avg. response time', 'c6' ),
                );
                foreach ( $tiles as $key => $tile ) :
                    ?>
                    <div class="akdk-kpi" data-kpi="<?php echo esc_attr( $key ); ?>" data-color="<?php echo esc_attr( $tile[1] ); ?>">
                        <span class="akdk-kpi-label"><i class="akdk-dot"></i><?php echo esc_html( $tile[0] ); ?></span>
                        <strong class="akdk-kpi-value">0</strong>
                        <span class="akdk-kpi-sub"></span>
                        <span class="akdk-kpi-spark" aria-hidden="true"></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="akdk-dash-grid">
                <section class="akdk-panel-card akdk-span-2">
                    <header><h2>Conversations &amp; leads</h2><span class="akdk-card-meta">per day</span></header>
                    <div class="akdk-chart" id="chart-trend" style="height:280px"></div>
                    <details class="akdk-table-toggle"><summary>View as table</summary><div id="table-trend"></div></details>
                </section>

                <section class="akdk-panel-card akdk-analyst admin-chatbot-container">
                    <header class="admin-chatbot-header">
                        <h2>Data Analyst</h2>
                        <button type="button" id="clear-admin-chat" class="akdk-icon-btn" title="Clear conversation" aria-label="Clear conversation">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path></svg>
                        </button>
                    </header>
                    <div id="admin-chat-box" class="admin-chat-body">
                        <div id="chat-typing" class="chat-msg ai-msg" hidden><div class="akdk-typing"><i></i><i></i><i></i></div></div>
                    </div>
                    <div class="admin-chat-footer">
                        <input type="text" id="admin-chat-input" placeholder="Ask about your numbers…" aria-label="Ask the Data Analyst">
                        <button type="button" id="admin-chat-send" class="chat-send-btn" aria-label="Send">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="19" x2="12" y2="5"></line><polyline points="5 12 12 5 19 12"></polyline></svg>
                        </button>
                    </div>
                </section>

                <section class="akdk-panel-card">
                    <header><h2>Busiest hours</h2><span class="akdk-card-meta">conversations &amp; leads</span></header>
                    <div class="akdk-chart" id="chart-hourly" style="height:220px"></div>
                </section>

                <section class="akdk-panel-card">
                    <header><h2>Leads by form</h2></header>
                    <div class="akdk-chart" id="chart-forms" style="height:220px"></div>
                </section>

                <section class="akdk-panel-card">
                    <header><h2>Visitor journey</h2><span class="akdk-card-meta">from first message to lead</span></header>
                    <div class="akdk-chart" id="chart-funnel"></div>
                </section>

                <section class="akdk-panel-card">
                    <header><h2>Token usage</h2><span class="akdk-card-meta">per day</span></header>
                    <div class="akdk-chart" id="chart-tokens" style="height:220px"></div>
                    <p class="akdk-card-foot">Estimated cost: <strong id="akdk-cost">$0</strong> <span id="akdk-cost-parts"></span> · Admin assistant: <strong id="akdk-admin-tokens">0</strong> tokens · Indexing: <strong id="akdk-index-tokens">0</strong> tokens</p>
                    <p class="akdk-card-foot" id="akdk-token-note"></p>
                </section>

                <section class="akdk-panel-card">
                    <header><h2>Response time</h2><span class="akdk-card-meta">seconds, daily average</span></header>
                    <div class="akdk-chart" id="chart-response" style="height:220px"></div>
                </section>

                <section class="akdk-panel-card">
                    <header><h2>System</h2></header>
                    <dl class="akdk-dl" id="akdk-engine"></dl>
                    <h3 class="akdk-subhead">Knowledge base</h3>
                    <div class="akdk-chart" id="chart-vectors"></div>
                    <div id="akdk-alerts"></div>
                </section>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_design_tab( $design ) {
        $labels = array(
            'c1' => array( 'Series 1', 'Conversations' ),
            'c2' => array( 'Series 2', 'Leads' ),
            'c3' => array( 'Series 3', 'Visitors, input tokens, 2nd form' ),
            'c4' => array( 'Series 4', 'Conversion, response time, 3rd form' ),
            'c5' => array( 'Series 5', 'Answer rate, output tokens, 4th form' ),
            'c6' => array( 'Series 6', '5th form' ),
        );
        ?>
        <div class="akdk-customizer akdk-design">
            <form method="post" action="options.php" class="akdk-customizer-form" id="akdk-design-form">
                <?php settings_fields( 'wp_ai_dashboard_design_group' ); ?>
                <section class="akdk-panel is-active">
                    <h2>Chart colours</h2>
                    <p class="akdk-hint">Each colour always means the same thing on every chart. Defaults use the AK Deep Knowledge palette.</p>
                    <div class="akdk-grid">
                        <?php foreach ( $labels as $key => $label ) : ?>
                            <label class="akdk-field akdk-color">
                                <span><?php echo esc_html( $label[0] ); ?> <small>(<?php echo esc_html( $label[1] ); ?>)</small></span>
                                <input type="text" class="akdk-design-color" name="<?php echo esc_attr( self::DESIGN_OPTION . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( $design[ $key ] ); ?>" data-key="<?php echo esc_attr( $key ); ?>">
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <h2>Chart style</h2>
                    <div class="akdk-stack">
                        <?php
                        foreach ( array(
                            'fill'   => array( 'Soft area fill under lines', 'A light tint below trend lines.' ),
                            'smooth' => array( 'Smooth curves', 'Rounded lines instead of straight segments.' ),
                            'grid'   => array( 'Gridlines', 'Faint horizontal guide lines.' ),
                        ) as $key => $text ) :
                            ?>
                            <label class="akdk-toggle">
                                <input type="hidden" name="<?php echo esc_attr( self::DESIGN_OPTION . '[' . $key . ']' ); ?>" value="0">
                                <input type="checkbox" class="akdk-design-flag" data-key="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( self::DESIGN_OPTION . '[' . $key . ']' ); ?>" value="1" <?php checked( '1', $design[ $key ] ); ?>>
                                <span class="akdk-switch" aria-hidden="true"></span>
                                <span class="akdk-toggle-text"><strong><?php echo esc_html( $text[0] ); ?></strong><small><?php echo esc_html( $text[1] ); ?></small></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <h2>Cost estimate</h2>
                    <?php $rates = self::cost_rates(); ?>
                    <p class="akdk-hint">Prices change and differ per model. Enter your model's prices in USD per 1 million tokens (see your provider's pricing page). Leave a field empty to use the built-in price, which is only known for <code>gpt-4o-mini</code> and <code>text-embedding-3-small</code>.</p>
                    <div class="akdk-grid">
                        <?php
                        foreach ( array(
                            'rate_in'    => array( 'Input, per 1M', $rates['chat_model'], $rates['in'] ),
                            'rate_out'   => array( 'Output, per 1M', $rates['chat_model'], $rates['out'] ),
                            'rate_embed' => array( 'Embedding, per 1M', $rates['embed_model'], $rates['embed'] ),
                        ) as $key => $info ) :
                            ?>
                            <label class="akdk-field">
                                <span><?php echo esc_html( $info[0] ); ?> <small>(<?php echo esc_html( $info[1] ); ?>)</small></span>
                                <span class="akdk-input-unit"><input type="text" inputmode="decimal" name="<?php echo esc_attr( self::DESIGN_OPTION . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( $design[ $key ] ); ?>" placeholder="<?php echo esc_attr( null === $info[2] ? 'enter price' : (string) $info[2] ); ?>"><em>$</em></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </section>
                <div class="akdk-savebar">
                    <?php submit_button( 'Save design', 'primary', 'submit', false ); ?>
                    <button type="button" class="button akdk-button-ghost" id="akdk-design-reset">Reset to brand colours</button>
                </div>
            </form>
            <aside class="akdk-preview-col">
                <div class="akdk-preview-sticky">
                    <div class="akdk-preview-head"><strong>Preview</strong><span>Sample data</span></div>
                    <div class="akdk-panel-card">
                        <header><h2>Conversations &amp; leads</h2></header>
                        <div class="akdk-chart" id="chart-preview-trend" style="height:200px"></div>
                    </div>
                    <div class="akdk-panel-card">
                        <header><h2>Leads by form</h2></header>
                        <div class="akdk-chart" id="chart-preview-forms" style="height:190px"></div>
                    </div>
                </div>
            </aside>
        </div>
        <?php
    }
}
