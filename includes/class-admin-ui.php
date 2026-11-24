<?php
/**
 * Shared admin UI: AK Deep Knowledge branding, setup steps, menu order and
 * the admin stylesheet used by every plugin screen.
 *
 * @package AK_AI_Chatbot
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_AI_Chatbot_Admin_UI {

    const PARENT = 'wp-ai-chatbot';

    private static $step_cache = null;

    public function register_hooks() {
        add_action( 'admin_menu', array( $this, 'order_menu' ), 999 );
        add_filter( 'admin_body_class', array( $this, 'body_class' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
        add_action( 'in_admin_header', array( $this, 'render_header' ) );
        add_action( 'admin_head', array( $this, 'menu_css' ) );
        // WordPress footer ("Thank you for creating with WordPress" / "Version x.y") floats over
        // the plugin's long forms, so it is removed on the plugin's own screens.
        add_filter( 'admin_footer_text', array( $this, 'footer_text' ), 999 );
        add_filter( 'update_footer', array( $this, 'footer_text' ), 999 );
    }

    public function footer_text( $text ) {
        return self::is_plugin_screen() ? '' : $text;
    }

    /** True on any screen that belongs to this plugin. */
    public static function is_plugin_screen() {
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return 0 === strpos( $page, self::PARENT );
    }

    private static function current_page() {
        return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    }

    /**
     * Setup steps in the order a new user should follow them.
     * "essential" steps are needed for a working chatbot.
     */
    public static function steps() {
        if ( null !== self::$step_cache ) {
            return self::$step_cache;
        }
        global $wpdb;

        $vectors = 0;
        $table   = $wpdb->prefix . 'ai_vector_embeddings';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
            $vectors = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE embed_model = %s AND vector_data <> '[]'", WP_AI_Chatbot_AI::embedding_signature() ) );
        }
        $kbs        = get_option( 'wp_ai_chatbot_knowledge_bases', array() );
        $forms      = get_option( 'wp_ai_chatbot_multi_forms', array() );
        $quick      = get_option( 'wp_ai_chatbot_quick_actions', array() );
        $wa_enabled = '1' === get_option( 'wp_ai_wa_enable', '1' ) && '' !== (string) get_option( 'wp_ai_wa_number', '' );

        self::$step_cache = array(
            array( 'slug' => 'wp-ai-chatbot',               'label' => 'AI Settings',     'essential' => true,  'done' => '' !== WP_AI_Chatbot_AI::api_key( WP_AI_Chatbot_AI::chat_provider() ), 'hint' => 'Add your Gemini or OpenAI API key and describe your business.' ),
            array( 'slug' => 'wp-ai-chatbot-db',            'label' => 'Knowledge Bases', 'essential' => false, 'done' => ! empty( $kbs ),        'hint' => 'Optional: add your own data tables (services, prices, FAQs).' ),
            array( 'slug' => 'wp-ai-chatbot-rag',           'label' => 'Train Chatbot',   'essential' => true,  'done' => $vectors > 0,            'hint' => 'Choose what the bot learns from and click Process & Index.' ),
            array( 'slug' => 'wp-ai-chatbot-customizer',    'label' => 'Appearance',      'essential' => true,  'done' => false !== get_option( WP_AI_Chatbot_Widget_Style::OPTION ), 'hint' => 'Pick a template and match your brand colours.' ),
            array( 'slug' => 'wp-ai-chatbot-form',          'label' => 'Lead Forms',      'essential' => false, 'done' => ! empty( $forms ),       'hint' => 'Optional: forms the bot can show to collect leads.' ),
            array( 'slug' => 'wp-ai-chatbot-quick-actions', 'label' => 'Quick Actions',   'essential' => false, 'done' => ! empty( $quick ),       'hint' => 'Optional: one-tap buttons above the message box.' ),
            array( 'slug' => 'wp-ai-chatbot-pre-chat',      'label' => 'Pre-Chat Form',   'essential' => false, 'done' => '' !== (string) get_option( 'wp_ai_pre_chat_form_id', '' ), 'hint' => 'Optional: ask for name/email before chatting.' ),
            array( 'slug' => 'wp-ai-chatbot-guided',        'label' => 'Guided Flow',     'essential' => false, 'done' => '1' === get_option( 'wp_ai_guided_flow_enabled', '0' ), 'hint' => 'Optional: suggested first questions.' ),
            array( 'slug' => 'wp-ai-chatbot-whatsapp',      'label' => 'WhatsApp',        'essential' => false, 'done' => $wa_enabled,             'hint' => 'Optional: WhatsApp button next to the chat.' ),
        );
        return self::$step_cache;
    }

    /** Re-orders the submenu: Dashboard, numbered setup steps, then results. */
    public function order_menu() {
        global $submenu;
        if ( empty( $submenu[ self::PARENT ] ) || ! is_array( $submenu[ self::PARENT ] ) ) {
            return;
        }
        $by_slug = array();
        foreach ( $submenu[ self::PARENT ] as $item ) {
            $by_slug[ $item[2] ] = $item;
        }

        $order = array( 'wp-ai-chatbot-dashboard' => 'Dashboard' );
        $n     = 1;
        foreach ( array(
            'wp-ai-chatbot'               => 'AI Settings',
            'wp-ai-chatbot-db'            => 'Knowledge Bases',
            'wp-ai-chatbot-rag'           => 'Train Chatbot',
            'wp-ai-chatbot-customizer'    => 'Appearance',
            'wp-ai-chatbot-form'          => 'Lead Forms',
            'wp-ai-chatbot-quick-actions' => 'Quick Actions',
            'wp-ai-chatbot-pre-chat'      => 'Pre-Chat Form',
            'wp-ai-chatbot-guided'        => 'Guided Flow',
            'wp-ai-chatbot-whatsapp'      => 'WhatsApp',
        ) as $slug => $label ) {
            $order[ $slug ] = '<span class="akdk-menu-num">' . $n . '</span>' . $label;
            $n++;
        }
        $order['wp-ai-chatbot-live']  = null; // Keeps its own label (count of waiting chats).
        $order['wp-ai-chatbot-logs']  = 'Chat History';
        $order['wp-ai-chatbot-leads'] = 'Leads';
        $order['wp-ai-chatbot-sales'] = 'Sales Tools';

        $new = array();
        foreach ( $order as $slug => $label ) {
            if ( isset( $by_slug[ $slug ] ) ) {
                $item    = $by_slug[ $slug ];
                if ( null !== $label ) {
                    $item[0] = $label;
                }
                $new[]   = $item;
                unset( $by_slug[ $slug ] );
            }
        }
        foreach ( $by_slug as $item ) { // Anything added by add-ons stays at the end.
            $new[] = $item;
        }
        $submenu[ self::PARENT ] = $new; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
    }

    public function body_class( $classes ) {
        if ( self::is_plugin_screen() ) {
            $classes .= ' akdk-admin akdk-page-' . self::current_page();
        }
        return $classes;
    }

    public function enqueue() {
        if ( ! self::is_plugin_screen() ) {
            return;
        }
        wp_enqueue_style( 'akdk-admin', WP_AI_CHATBOT_URL . 'admin/css/akdk-admin.css', array(), WP_AI_CHATBOT_VERSION );
    }

    /** Brand colours for the plugin's menu item on every admin screen (tiny inline CSS). */
    public function menu_css() {
        echo '<style id="akdk-menu-css">'
            . '#adminmenu #toplevel_page_wp-ai-chatbot.wp-has-current-submenu>a,#adminmenu #toplevel_page_wp-ai-chatbot.current>a{background:#fd9400;color:#0a0a0a}'
            . '#adminmenu #toplevel_page_wp-ai-chatbot.wp-has-current-submenu .wp-menu-image:before{color:#0a0a0a}'
            . '#adminmenu #toplevel_page_wp-ai-chatbot.wp-has-current-submenu .wp-menu-image img{filter:brightness(0)}'
            . '#adminmenu #toplevel_page_wp-ai-chatbot .wp-submenu li.current a{color:#fd9400;font-weight:600}'
            . '#adminmenu #toplevel_page_wp-ai-chatbot .wp-submenu a:hover{color:#fd9400}'
            . '#adminmenu .akdk-menu-num{display:inline-block;min-width:16px;height:16px;margin-right:6px;border-radius:50%;background:rgba(255,255,255,.12);font-size:10px;line-height:16px;text-align:center;font-weight:600}'
            . '#adminmenu .current .akdk-menu-num{background:#fd9400;color:#0a0a0a}'
            . '#adminmenu #toplevel_page_wp-ai-chatbot .wp-menu-image img{width:18px;height:18px;padding-top:8px;opacity:.85}'
            . '</style>';
    }

    /** Menu icon: chat bubble with a spark (data URI so it needs no request). */
    public static function menu_icon() {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#a7aaad"><path d="M4 3h16a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H9l-5 4v-4H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2zm8 3.2-1.1 2.7-2.7 1.1 2.7 1.1L12 13.8l1.1-2.7 2.7-1.1-2.7-1.1z"/></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
    }

    /** Branded top bar + step navigation on every plugin screen. */
    public function render_header() {
        if ( ! self::is_plugin_screen() ) {
            return;
        }
        $current = self::current_page();
        $steps   = self::steps();

        $essential_total = 0;
        $essential_done  = 0;
        $next            = null;
        foreach ( $steps as $step ) {
            if ( $step['essential'] ) {
                $essential_total++;
                if ( $step['done'] ) {
                    $essential_done++;
                } elseif ( null === $next ) {
                    $next = $step;
                }
            }
        }
        $pct = $essential_total ? round( 100 * $essential_done / $essential_total ) : 100;
        $nav      = array(
            'wp-ai-chatbot-dashboard' => 'Dashboard',
            'wp-ai-chatbot-logs'      => 'Chat History',
            'wp-ai-chatbot-leads'     => 'Leads',
        );
        ?>
        <div class="akdk-topbar">
            <div class="akdk-brand">
                <?php
                // The AK Deep Knowledge logo is a file inside the plugin (admin/images/akdk-logo.png) and cannot be changed from the admin.
                $logo_file = file_exists( WP_AI_CHATBOT_PATH . 'admin/images/akdk-logo.png' ) ? 'akdk-logo.png' : 'akdk-logo.svg';
                ?>
                <img class="akdk-logo-img" src="<?php echo esc_url( WP_AI_CHATBOT_URL . 'admin/images/' . $logo_file . '?v=' . WP_AI_CHATBOT_VERSION ); ?>" width="34" height="34" alt="AK Deep Knowledge">
                <span class="akdk-brand-text"><strong>AI Chatbot</strong><small>by AK Deep Knowledge · v<?php echo esc_html( WP_AI_CHATBOT_VERSION ); ?></small></span>
            </div>
            <nav class="akdk-topnav" aria-label="Chatbot sections">
                <?php foreach ( $nav as $slug => $label ) : ?>
                    <a class="<?php echo $slug === $current ? 'is-current' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>"><?php echo esc_html( $label ); ?></a>
                <?php endforeach; ?>
            </nav>
            <div class="akdk-top-actions">
                <span class="akdk-progress" title="Required setup steps completed">
                    <span class="akdk-progress-bar"><i style="width:<?php echo (int) $pct; ?>%"></i></span>
                    <span><?php echo (int) $essential_done; ?>/<?php echo (int) $essential_total; ?> setup</span>
                </span>
                <a class="akdk-top-link" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener">View site</a>
                <a class="akdk-top-link" href="https://akdeepknowledge.com" target="_blank" rel="noopener">Support</a>
            </div>
        </div>
        <nav class="akdk-stepper" aria-label="Setup steps">
            <?php foreach ( $steps as $i => $step ) : ?>
                <?php
                $classes = 'akdk-st';
                if ( $step['done'] ) {
                    $classes .= ' is-done';
                }
                if ( $step['slug'] === $current ) {
                    $classes .= ' is-current';
                }
                ?>
                <a class="<?php echo esc_attr( $classes ); ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $step['slug'] ) ); ?>" title="<?php echo esc_attr( $step['hint'] ); ?>"<?php echo $step['slug'] === $current ? ' aria-current="step"' : ''; ?>>
                    <span class="akdk-st-dot"><?php echo (int) ( $i + 1 ); ?><?php if ( $step['done'] ) : ?><i class="akdk-st-check" aria-label="done"><svg viewBox="0 0 12 12" width="8" height="8" aria-hidden="true"><path d="M2.5 6.2 5 8.5l4.5-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></i><?php endif; ?></span>
                    <span class="akdk-st-text">
                        <span class="akdk-st-label"><?php echo esc_html( $step['label'] ); ?></span>
                        <span class="akdk-st-tag"><?php echo $step['done'] ? 'Done' : ( $step['essential'] ? 'Required' : 'Optional' ); ?></span>
                    </span>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php if ( $next && $next['slug'] !== $current ) : ?>
            <div class="akdk-nextstep">
                <span class="akdk-nextstep-tag">Next step</span>
                <span><strong><?php echo esc_html( $next['label'] ); ?>:</strong> <?php echo esc_html( $next['hint'] ); ?></span>
                <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $next['slug'] ) ); ?>">Continue</a>
            </div>
        <?php endif; ?>
        <?php
    }
}
