<?php
/**
 * Front-end chat widget: assets, HTML and the public REST endpoints.
 *
 * @package AK_AI_Chatbot
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_AI_Chatbot_Chat_Renderer {

    private $settings;
    private $multi_forms;

    public function __construct() {
        $this->settings    = get_option( 'wp_ai_chatbot_settings', array() );
        $this->multi_forms = get_option( 'wp_ai_chatbot_multi_forms', array() );
    }

    /** True once the widget's script and data are queued (block themes render the shortcode first). */
    private static $queued = false;

    public function enqueue_public_assets() {
        if ( defined( 'WP_AI_CHATBOT_IN_FORM_FRAME' ) || self::$queued ) {
            return;
        }
        self::$queued = true;
        wp_enqueue_style( 'wp-ai-chat-widget-style', WP_AI_CHATBOT_URL . 'public/css/chat-widget.css', array(), (string) filemtime( WP_AI_CHATBOT_PATH . 'public/css/chat-widget.css' ) );
        wp_enqueue_style( 'wp-ai-launcher-icons', WP_AI_CHATBOT_URL . 'public/css/launcher-icons.css', array( 'wp-ai-chat-widget-style' ), (string) filemtime( WP_AI_CHATBOT_PATH . 'public/css/launcher-icons.css' ) );
        wp_enqueue_script( 'wp-ai-chat-widget-script', WP_AI_CHATBOT_URL . 'public/js/chat-widget.js', array(), (string) filemtime( WP_AI_CHATBOT_PATH . 'public/js/chat-widget.js' ), true );

        $active_forms = array();
        $active_form_ids = array();
        
        if ( is_array( $this->multi_forms ) ) {
            foreach ( $this->multi_forms as $form ) {
                if ( is_array( $form ) && ! empty( $form['id'] ) && ( ! isset( $form['is_enabled'] ) || $form['is_enabled'] === '1' ) ) {
                    $active_forms[] = WP_AI_Chatbot_External_Forms::public_form( $form );
                    $active_form_ids[] = $form['id'];
                }
            }
        }

        $raw_quick_actions = get_option( 'wp_ai_chatbot_quick_actions', array() );
        $active_quick_actions = array();
        
        if ( is_array( $raw_quick_actions ) ) {
            foreach ( $raw_quick_actions as $qa ) {
                if ( ! is_array( $qa ) || empty( $qa['label'] ) ) continue;
                if ( !empty($qa['form_id']) ) {
                    if ( in_array( $qa['form_id'], $active_form_ids ) ) {
                        $active_quick_actions[] = $qa;
                    }
                } else {
                    $active_quick_actions[] = $qa;
                }
            }
        }

        // The pre-chat form only applies if that form still exists and is enabled.
        $pre_chat_form_id = (string) get_option( 'wp_ai_pre_chat_form_id', '' );
        if ( '' !== $pre_chat_form_id && ! in_array( $pre_chat_form_id, $active_form_ids, true ) ) {
            $pre_chat_form_id = '';
        }
        // A Direct Link cannot be a pre-chat form (it has nothing to fill in, so the chat would stay locked).
        foreach ( $active_forms as $af ) {
            if ( '' !== $pre_chat_form_id && isset( $af['id'], $af['source'] ) && (string) $af['id'] === $pre_chat_form_id && 'directlink' === $af['source'] ) {
                $pre_chat_form_id = '';
            }
        }

        // Re-index so it is always a JSON array (array_filter keeps the original keys).
        $guided_queries = get_option( 'wp_ai_guided_queries', array() );
        $guided_queries = is_array( $guided_queries ) ? array_values( array_filter( array_map( 'strval', $guided_queries ), 'strlen' ) ) : array();

        // Bot of this page (Sales Tools > Bots); [akdk_chatbot bot="..."] can change it later.
        if ( '' === WP_AI_Chatbot_Bots::active() ) {
            WP_AI_Chatbot_Bots::activate( WP_AI_Chatbot_Bots::for_current_page() );
        }
        $style = self::front_style();

        wp_localize_script( 'wp-ai-chat-widget-script', 'wpAiChatbotData', array(
            'rest_url'        => esc_url_raw( rest_url( 'wp-ai-chatbot/v1/chat' ) ),
            'form_submit_url' => esc_url_raw( rest_url( 'wp-ai-chatbot/v1/submit-lead' ) ),
            'track_url'       => esc_url_raw( rest_url( 'wp-ai-chatbot/v1/track' ) ),
            'form_view_url'   => esc_url_raw( rest_url( 'wp-ai-chatbot/v1/form-view' ) ),
            // Only logged-in users need (and send) a nonce. Visitors get none, so a page served
            // from a cache can never carry an expired one ("Cookie check failed").
            'nonce'           => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
            'logged_in'       => is_user_logged_in() ? '1' : '0',
            'bot_name'        => (string) $style['bot_name'],
            'forms'           => array_values( $active_forms ),
            'quick_actions'   => array_values( $active_quick_actions ),
            'pre_chat_form_id'=> $pre_chat_form_id,
            'guided_queries'  => $guided_queries,
            'is_guided_enabled' => get_option( 'wp_ai_guided_flow_enabled', '0' ),
            'max_message_len' => (int) WP_AI_CHATBOT_MAX_MSG_LEN,
            'input_placeholder' => (string) $style['input_placeholder'],
            'launcher_greet_delay' => (int) $style['launcher_greet_delay'],
            'launcher_greet_duration' => (int) $style['launcher_greet_duration'],
            'history_size'    => max( 0, min( 50, (int) ( get_option( 'wp_ai_chatbot_settings', array() )['history_limit'] ?? 6 ) ) ),
            // 2.0.0
            'lang'            => WP_AI_Chatbot_I18n::current(),
            'welcome'         => self::welcome_text(),
            'page_type'       => WP_AI_Chatbot_Proactive::page_type(),
            'strings'         => self::strings(),
        ) + WP_AI_Chatbot_Voice::widget_data( $style ) + WP_AI_Chatbot_Chat_History::widget_data()
          + WP_AI_Chatbot_Bots::widget_data() + WP_AI_Chatbot_Live_Chat::widget_data()
          + WP_AI_Chatbot_Product_Cards::widget_data() + WP_AI_Chatbot_Proactive::widget_data() );
    }

    /** Appearance for visitors: translated texts (WPML / Polylang) and the bot of this page. */
    public static function front_style() {
        $o = WP_AI_Chatbot_Widget_Style::get();
        if ( WP_AI_Chatbot_I18n::active() ) {
            $o['bot_name']          = WP_AI_Chatbot_I18n::t( 'Chatbot name', $o['bot_name'] );
            $o['header_subtitle']   = WP_AI_Chatbot_I18n::t( 'Subtitle', $o['header_subtitle'] );
            $o['input_placeholder'] = WP_AI_Chatbot_I18n::t( 'Input placeholder', $o['input_placeholder'] );
            $o['launcher_greeting'] = WP_AI_Chatbot_I18n::t( 'Launcher greeting', $o['launcher_greeting'] );
        }
        return WP_AI_Chatbot_Bots::style( $o );
    }

    /** First message of a new chat ('' = the widget's default). */
    private static function welcome_text() {
        $w = WP_AI_Chatbot_Bots::welcome();
        if ( '' === $w && WP_AI_Chatbot_I18n::active() ) {
            $t = WP_AI_Chatbot_I18n::t( 'Welcome message', 'Hi there! How can I help you today?' );
            $w = 'Hi there! How can I help you today?' !== $t ? $t : '';
        }
        return $w;
    }

    /** Button texts of the 2.0.0 features (translatable with WPML / Polylang). */
    private static function strings() {
        $list = array(
            'add_to_cart' => 'Add to cart',
            'added'       => 'Added ✓',
            'buy_now'     => 'Buy now',
            'options'     => 'Choose options',
            'view_cart'   => 'View cart',
            'out_stock'   => 'Out of stock',
            'agent'       => 'Team',
        );
        $out = array();
        foreach ( $list as $k => $v ) {
            $out[ $k ] = WP_AI_Chatbot_I18n::active() ? WP_AI_Chatbot_I18n::t( 'Button: ' . $v, $v ) : $v;
        }
        return $out;
    }

    /** True once [akdk_chatbot] has put the chat on this page (the floating chat is then left out). */
    private static $embedded = false;

    public function register_shortcodes() {
        foreach ( array( 'akdk_chatbot', 'ak_chatbot', 'wp_ai_chatbot' ) as $tag ) {
            add_shortcode( $tag, array( $this, 'render_shortcode' ) );
        }
    }

    /** "600" -> "600px"; "80vh", "100%", "640px" kept; anything else -> the default. */
    private static function css_size( $value, $default ) {
        $value = strtolower( trim( (string) $value ) );
        if ( preg_match( '/^\d{1,4}$/', $value ) ) {
            return $value . 'px';
        }
        return preg_match( '/^\d{1,4}(\.\d+)?(px|vh|dvh|%|rem|em)$/', $value ) ? $value : $default;
    }

    /**
     * [akdk_chatbot] - the chatbot inside a page (a dedicated chat page, or a section of any page).
     *
     * Attributes (all optional):
     *   height="640"         Height of the chat: number (px), "80vh" or "100%". Default 640.
     *   width="900"          Largest width: number (px), "100%". Default 100% of the content area.
     *   title="..."          Name in the header (default: Appearance > Chatbot name).
     *   subtitle="..."       Line under the name (default: Appearance > Subtitle). Empty hides it.
     *   header="no"          Hides the header bar.
     *   welcome="..."        First message of a new chat (default: "Hi there! How can I help you today?").
     *
     * It uses the same settings, knowledge, forms, quick actions, voice and chat history as the
     * floating chat. One chat per page: on that page the floating chat button is not shown.
     */
    public function render_shortcode( $atts = array() ) {
        if ( defined( 'WP_AI_CHATBOT_IN_FORM_FRAME' ) || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST && ! self::is_builder_preview() ) ) {
            return '';
        }
        if ( self::$embedded ) {
            return '<!-- AK Deep Knowledge AI Chatbot: only one chat can be shown per page. -->';
        }
        $atts = shortcode_atts(
            array(
                'height'   => '640',
                'width'    => '100%',
                'title'    => null,
                'subtitle' => null,
                'header'   => 'yes',
                'welcome'  => '',
                'bot'      => '',
            ),
            (array) $atts,
            'akdk_chatbot'
        );

        // A bot of its own (Sales Tools > Bots): bot="sales".
        $bot = sanitize_key( (string) $atts['bot'] );
        if ( '' !== $bot && WP_AI_Chatbot_Bots::get( $bot ) ) {
            WP_AI_Chatbot_Bots::activate( $bot );
        }
        // The scripts may not be queued yet (page builders, themes that call do_shortcode late).
        if ( ! wp_script_is( 'wp-ai-chat-widget-script', 'enqueued' ) ) {
            $this->enqueue_public_assets();
        } elseif ( '' !== $bot && WP_AI_Chatbot_Bots::get( $bot ) ) {
            $data = WP_AI_Chatbot_Bots::widget_data();
            $data['bot_name'] = (string) self::front_style()['bot_name'];
            if ( '' !== $data['bot_welcome'] ) {
                $data['welcome'] = $data['bot_welcome'];
            }
            wp_add_inline_script( 'wp-ai-chat-widget-script', 'window.wpAiChatbotData = window.wpAiChatbotData || {}; Object.assign(window.wpAiChatbotData, ' . wp_json_encode( $data ) . ');', 'before' );
        }
        $welcome = trim( sanitize_text_field( (string) $atts['welcome'] ) );
        if ( '' !== $welcome ) {
            wp_add_inline_script( 'wp-ai-chat-widget-script', 'window.wpAiChatbotData = window.wpAiChatbotData || {}; window.wpAiChatbotData.welcome = ' . wp_json_encode( $welcome ) . ';', 'before' );
        }
        self::$embedded = true;

        $o = self::front_style();
        if ( null !== $atts['title'] && '' !== trim( (string) $atts['title'] ) ) {
            $o['bot_name'] = sanitize_text_field( (string) $atts['title'] );
        }
        if ( null !== $atts['subtitle'] ) {
            $o['header_subtitle'] = sanitize_text_field( (string) $atts['subtitle'] );
        }
        $height = self::css_size( $atts['height'], '640px' );
        $width  = self::css_size( $atts['width'], '100%' );
        $header = ! in_array( strtolower( (string) $atts['header'] ), array( 'no', '0', 'false', 'hide', 'off' ), true );

        $html  = '<style id="wp-ai-chatbot-vars">#wp-ai-chatbot-window{' . WP_AI_Chatbot_Widget_Style::css_vars( $o ) . '}</style>';
        $html .= '<div class="wp-ai-embed-wrap' . ( $header ? '' : ' wp-ai-embed-no-header' ) . '" style="' . esc_attr( '--wp-ai-embed-height:' . $height . ';--wp-ai-embed-width:' . $width . ';' ) . '">';
        $html .= self::widget_markup( $o, false, true );
        $html .= '</div>';
        return $html;
    }

    /** Elementor / Beaver / Divi editors render shortcodes through REST or AJAX: show the chat there too. */
    private static function is_builder_preview() {
        return isset( $_GET['elementor-preview'] ) || isset( $_GET['fl_builder'] ) || isset( $_GET['et_fb'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    }

    public function render_chat_widget_html() {
        if ( defined( 'WP_AI_CHATBOT_IN_FORM_FRAME' ) ) {
            return;
        }
        // This page shows the chat inside its content ([akdk_chatbot]): no floating chat as well.
        if ( self::$embedded ) {
            return;
        }
        $o = self::front_style();

        
        $side   = intval( $o['margin_side'] );
        $bottom = intval( $o['margin_bottom'] );

        $mobile_side   = intval( $o['margin_side_mobile'] );
        $mobile_bottom = intval( $o['margin_bottom_mobile'] );

        $icon      = intval( $o['icon_size'] );
        $is_left   = ( 'left' === $o['position'] );

        // Keep the desktop chat window above the launcher.
        $win_bottom = $bottom + $icon + 14;

        // Desktop launcher position.
        $fab_pos = $is_left
            ? "left:{$side}px;right:auto;"
            : "right:{$side}px;left:auto;";

        // Mobile launcher position.
        $mobile_fab_pos = $is_left
            ? "left:{$mobile_side}px;right:auto;"
            : "right:{$mobile_side}px;left:auto;";

        // Desktop chat window position.
        $win_pos = $is_left
            ? "left:{$side}px;right:auto;transform-origin:bottom left;"
            : "right:{$side}px;left:auto;transform-origin:bottom right;";

        echo '<style id="wp-ai-chatbot-vars">'
            . '#wp-ai-fab-container,#wp-ai-chatbot-window{'
            . WP_AI_Chatbot_Widget_Style::css_vars( $o ) . '}'

            // Desktop positioning.
            . '@media (min-width:481px){'
            . '#wp-ai-fab-container{bottom:' . $bottom . 'px;' . $fab_pos . '}'
            . '#wp-ai-chatbot-window{bottom:' . $win_bottom . 'px;'
            . $win_pos
            . 'max-height:calc(100vh - ' . ( $win_bottom + 16 ) . 'px);}'
            . '}'

            // Mobile positioning (launcher only; mobile chat window keeps its existing layout).
            . '@media (max-width:480px){'
            . '#wp-ai-fab-container{bottom:' . $mobile_bottom . 'px;'
            . $mobile_fab_pos . '}'
            . '}'

            . '</style>';

        // Optional WhatsApp button: when enabled, the launcher opens a small menu (WhatsApp + AI chat).
        $wa_number     = preg_replace( '/[^0-9]/', '', (string) get_option( 'wp_ai_wa_number', '' ) );
        $has_wa        = ( '1' === get_option( 'wp_ai_wa_enable', '1' ) && '' !== $wa_number );
        $wa_hover_text = get_option( 'wp_ai_wa_hover_text', 'Chat on WhatsApp' );
        $wa_message    = get_option( 'wp_ai_wa_message', 'Hello, I need some help!' );
        $wa_bg_color   = sanitize_hex_color( get_option( 'wp_ai_wa_bg_color', '#25D366' ) );
        $wa_text_color = sanitize_hex_color( get_option( 'wp_ai_wa_text_color', '#ffffff' ) );
        $wa_icon       = (string) get_option( 'wp_ai_wa_icon', '' );
        if ( false !== strpos( $wa_icon, 'cdn-icons-png.flaticon.com' ) ) {
            $wa_icon = ''; // Old third-party default: use the built-in icon instead.
        }
        $wa_url = 'https://wa.me/' . $wa_number . '?text=' . rawurlencode( (string) $wa_message );

        $fab_classes = 'wp-ai-fab-container' . ( $has_wa ? ' has-wa-menu' : '' ) . ( '1' === $o['toggle_icon_transparent'] ? ' wp-ai-toggle-transparent' : '' ) . ( $is_left ? ' wp-ai-pos-left' : '' );
        ?>
        <div id="wp-ai-fab-container" class="<?php echo esc_attr( $fab_classes ); ?>">
            <?php if ( $has_wa ) : ?>
            <div class="wp-ai-fab-menu">
                <a href="<?php echo esc_url( $wa_url ); ?>" target="_blank" rel="noopener noreferrer" class="wp-ai-fab-item wp-ai-whatsapp-btn" style="background-color:<?php echo esc_attr( $wa_bg_color ? $wa_bg_color : '#25D366' ); ?>;color:<?php echo esc_attr( $wa_text_color ? $wa_text_color : '#ffffff' ); ?>;" aria-label="<?php echo esc_attr( $wa_hover_text ); ?>">
                    <span class="wp-ai-fab-label"><?php echo esc_html( $wa_hover_text ); ?></span>
                    <?php if ( '' !== $wa_icon ) : ?>
                        <img src="<?php echo esc_url( $wa_icon ); ?>" alt="">
                    <?php else : ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                    <?php endif; ?>
                </a>
                <button type="button" id="wp-ai-open-chat-btn" class="wp-ai-fab-item wp-ai-chat-btn" aria-label="<?php echo esc_attr( 'Chat with ' . $o['bot_name'] ); ?>">
                    <span class="wp-ai-fab-label"><?php echo esc_html( 'Chat with ' . $o['bot_name'] ); ?></span>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                </button>
            </div>
            <?php endif; ?>
            <div id="wp-ai-chatbot-toggle" class="<?php echo esc_attr( WP_AI_Chatbot_Launcher_Icons::toggle_classes( $o ) ); ?>" style="<?php echo esc_attr( WP_AI_Chatbot_Launcher_Icons::css_vars( $o ) ); ?>" role="button" tabindex="0" aria-label="<?php echo esc_attr( 'Chat with ' . $o['bot_name'] ); ?>">
                <?php echo WP_AI_Chatbot_Launcher_Icons::toggle_inner( $o ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed plugin SVG; admin text escaped inside. ?>
            </div>
        </div>
        <?php
        echo self::widget_markup( $o, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
    }

    /**
     * Chat window markup. Also used (with $preview = true) for the live preview
     * on the Appearance screen, so the preview always matches the real widget.
     */
    public static function widget_markup( $o, $preview = false, $embedded = false ) {
        $classes = 'wp-ai-chatbot-window' . ( $preview ? ' wp-ai-preview' : ( $embedded ? ' wp-ai-embedded' : ' wp-ai-hidden' ) ) . ( '1' === $o['chat_bg_transparent'] ? ' wp-ai-glass' : '' );
        $initial = function_exists( 'mb_substr' ) ? mb_substr( trim( $o['bot_name'] ), 0, 1 ) : substr( trim( $o['bot_name'] ), 0, 1 );
        $id      = function ( $name ) use ( $preview ) {
            return $preview ? '' : ' id="' . $name . '"';
        };

        ob_start();
        ?>
        <div<?php echo $id( 'wp-ai-chatbot-window' ); ?> class="<?php echo esc_attr( $classes ); ?>" data-input-border="<?php echo esc_attr( $o['input_border_sides'] ); ?>" role="<?php echo $embedded ? 'region' : 'dialog'; ?>" aria-label="<?php echo esc_attr( $o['bot_name'] ); ?>"<?php echo $preview ? ' style="' . esc_attr( WP_AI_Chatbot_Widget_Style::css_vars( $o ) ) . '"' : ''; ?>>
            <div class="wp-ai-chat-header">
                <div class="wp-ai-avatar" aria-hidden="true">
                    <?php if ( ! empty( $o['bot_avatar'] ) ) : ?>
                        <img src="<?php echo esc_url( $o['bot_avatar'] ); ?>" alt="">
                    <?php else : ?>
                        <span class="wp-ai-avatar-initial"><?php echo esc_html( strtoupper( $initial ) ); ?></span>
                    <?php endif; ?>
                    <span class="wp-ai-status-dot"></span>
                </div>
                <div class="wp-ai-chat-title">
                    <span class="wp-ai-bot-name"><?php echo esc_html( $o['bot_name'] ); ?></span>
                    <?php if ( '' !== trim( (string) $o['header_subtitle'] ) ) : ?>
                        <span class="wp-ai-subtitle"><?php echo esc_html( $o['header_subtitle'] ); ?></span>
                    <?php endif; ?>
                </div>
                <div class="wp-ai-header-actions">
                    <button type="button"<?php echo $id( 'wp-ai-delete-chat' ); ?> class="wp-ai-header-icon wp-ai-clear-btn" title="Clear chat" aria-label="Clear chat">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path><path d="M10 11v6M14 11v6"></path><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"></path></svg>
                    </button>
                    <?php if ( ! $embedded ) : ?>
                    <button type="button"<?php echo $id( 'wp-ai-close-chat' ); ?> class="wp-ai-close-btn" title="Close" aria-label="Close chat">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <div<?php echo $id( 'wp-ai-confirm-modal' ); ?> class="wp-ai-modal wp-ai-hidden">
                <div class="wp-ai-modal-content">
                    <p>Clear this conversation?</p>
                    <div class="wp-ai-modal-actions">
                        <button type="button"<?php echo $id( 'wp-ai-cancel-del' ); ?> class="wp-ai-btn-secondary">Cancel</button>
                        <button type="button"<?php echo $id( 'wp-ai-confirm-del' ); ?> class="wp-ai-btn-danger">Clear</button>
                    </div>
                </div>
            </div>

            <div<?php echo $id( 'wp-ai-chat-body' ); ?> class="wp-ai-chat-body" aria-live="polite">
                <?php if ( $preview ) : ?>
                    <div class="wp-ai-message-row wp-ai-message-row-bot"><div class="wp-ai-message wp-ai-bot-message">Hi there! How can I help you today?</div></div>
                    <div class="wp-ai-message-row wp-ai-message-row-user"><div class="wp-ai-message wp-ai-user-message">What services do you offer?</div></div>
                    <div class="wp-ai-message-row wp-ai-message-row-bot"><div class="wp-ai-message wp-ai-bot-message">We build <strong>AI chatbots</strong>, automations and websites. Would you like a free consultation?</div></div>
                <?php endif; ?>
            </div>

            <div class="wp-ai-dock">
                <div<?php echo $id( 'wp-ai-quick-actions-wrapper' ); ?> class="wp-ai-quick-actions-wrapper wp-ai-qa-align-<?php echo esc_attr( $o['quick_actions_align'] ); ?>"<?php echo $preview ? '' : ' style="display:none;"'; ?>>
                    <button type="button"<?php echo $id( 'wp-ai-scroll-left' ); ?> class="wp-ai-scroll-btn" aria-label="Scroll left">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
                    </button>
                    <div<?php echo $id( 'wp-ai-quick-actions' ); ?> class="wp-ai-quick-actions">
                        <?php if ( $preview ) : ?><button type="button" class="wp-ai-quick-action-btn">Contact Us</button><?php endif; ?>
                    </div>
                    <button type="button"<?php echo $id( 'wp-ai-scroll-right' ); ?> class="wp-ai-scroll-btn" aria-label="Scroll right">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
                    </button>
                </div>

                <div class="wp-ai-chat-footer">
                    <div class="wp-ai-composer">
                        <input type="text"<?php echo $id( 'wp-ai-chat-input' ); ?> placeholder="<?php echo esc_attr( $o['input_placeholder'] ); ?>" autocomplete="off" maxlength="<?php echo (int) WP_AI_CHATBOT_MAX_MSG_LEN; ?>" aria-label="Type your message" />
                        <button type="button"<?php echo $id( 'wp-ai-voice-btn' ); ?> class="wp-ai-voice-btn"<?php echo ( $preview && '1' === $o['voice_input'] ) ? '' : ' hidden'; ?> title="Speak" aria-label="Voice input">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="2" width="6" height="12" rx="3"></rect><path d="M19 10a7 7 0 0 1-14 0"></path><line x1="12" y1="17" x2="12" y2="22"></line></svg>
                        </button>
                        <button type="button"<?php echo $id( 'wp-ai-send-btn' ); ?> class="wp-ai-send-btn" aria-label="Send message">
                            <?php if ( ! empty( $o['send_btn_icon'] ) ) : ?>
                                <img src="<?php echo esc_url( $o['send_btn_icon'] ); ?>" alt="">
                            <?php else : ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="19" x2="12" y2="5"></line><polyline points="5 12 12 5 19 12"></polyline></svg>
                            <?php endif; ?>
                        </button>
                    </div>
                </div>

                <?php if ( '1' === $o['show_branding'] ) : ?>
                    <div class="wp-ai-branding">Powered by <a href="https://akdeepknowledge.com" target="_blank" rel="noopener">AK Deep Knowledge</a></div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function register_chat_rest_route() {
        register_rest_route( 'wp-ai-chatbot/v1', '/chat', array( 
            'methods' => 'POST', 
            'callback' => array( $this, 'handle_rest_chat_request' ), 
            'permission_callback' => array( $this, 'check_public_nonce' )
        ) );
        register_rest_route( 'wp-ai-chatbot/v1', '/submit-lead', array( 
            'methods' => 'POST', 
            'callback' => array( $this, 'handle_lead_submission' ), 
            'permission_callback' => array( $this, 'check_public_nonce' )
        ) );
        register_rest_route( 'wp-ai-chatbot/v1', '/form-view', array(
            'methods' => 'POST',
            'callback' => array( $this, 'record_form_view' ),
            'permission_callback' => array( $this, 'check_public_nonce' ),
        ) );
        WP_AI_Chatbot_Voice::register_route( array( $this, 'check_public_nonce' ) );
    }

    /**
     * Permission check for the public chat and lead endpoints.
     *
     * Guests are allowed without a nonce on purpose: page-cache plugins (and
     * CDNs) serve pages with nonces that expire after 12-24 hours, which used
     * to make the chatbot fail with "403 Invalid request" for visitors.
     * Abuse is limited by the per-IP rate limiter in each handler instead.
     * Logged-in users must send a valid nonce, because WordPress treats
     * REST requests from them as authenticated.
     */
    public function check_public_nonce( WP_REST_Request $request ) {
        if ( ! is_user_logged_in() ) {
            return true;
        }
        $nonce = $request->get_header( 'X-WP-Nonce' );
        if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new WP_Error( 'rest_invalid_nonce', 'Your session has expired. Please refresh the page and try again.', array( 'status' => 403 ) );
        }
        return true;
    }

    /** A session ID has exactly this shape: 32 lower-case hex characters. */
    public static function valid_session_id( $id ) {
        return is_string( $id ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $id );
    }

    /**
     * The visitor's chat session (HttpOnly cookie). A cookie that does not have
     * the expected shape (too long, other characters) is ignored and replaced
     * with a new random ID; valid existing sessions are kept.
     */
    private function get_or_set_session_cookie() {
        $cookie_name = 'wp_ai_chat_session';
        if ( isset( $_COOKIE[ $cookie_name ] ) ) {
            $current = strtolower( (string) wp_unslash( $_COOKIE[ $cookie_name ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated below.
            if ( self::valid_session_id( $current ) ) {
                return $current;
            }
        }
        $session_id = self::new_session_id();
        $cookie_args = array(
            'expires'  => time() + 2592000,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        );
        if ( headers_sent() ) {
            return $session_id;
        }
        if ( PHP_VERSION_ID >= 70300 ) {
            setcookie( $cookie_name, $session_id, $cookie_args );
        } else {
            // Older PHP: no native SameSite support in setcookie(); path trick keeps it at least HttpOnly/Secure-correct.
            setcookie( $cookie_name, $session_id, $cookie_args['expires'], '/; SameSite=Lax', '', is_ssl(), true );
        }
        $_COOKIE[ $cookie_name ] = $session_id;
        return $session_id;
    }

    /** 128 random bits from the operating system's secure generator. */
    public static function new_session_id() {
        try {
            return bin2hex( random_bytes( 16 ) );
        } catch ( Exception $e ) {
            return md5( wp_generate_password( 64, true, true ) . microtime() );
        }
    }

    /** Log a form actually rendered in the widget, including pre-chat and quick actions. */
    public function record_form_view( WP_REST_Request $request ) {
        $ip = WP_AI_Chatbot_Rate_Limiter::get_client_ip();
        if ( ! WP_AI_Chatbot_Rate_Limiter::allow( 'form_view_' . $ip, 35, 60 ) ) {
            return new WP_Error( 'rate_limited', 'Too many form events.', array( 'status' => 429 ) );
        }
        $id = sanitize_key( (string) $request->get_param( 'form_id' ) );
        if ( '' === $id || strlen( $id ) > 100 ) {
            return new WP_Error( 'invalid_form', 'Invalid form.', array( 'status' => 400 ) );
        }
        $forms = get_option( 'wp_ai_chatbot_multi_forms', array() );
        $found = null;
        if ( is_array( $forms ) ) {
            foreach ( $forms as $form ) {
                if ( is_array( $form ) && isset( $form['id'] ) && $id === sanitize_key( $form['id'] )
                    && ( ! isset( $form['is_enabled'] ) || '1' === (string) $form['is_enabled'] ) ) {
                    $found = $form;
                    break;
                }
            }
        }
        if ( ! $found ) {
            return new WP_Error( 'invalid_form', 'Form unavailable.', array( 'status' => 404 ) );
        }
        $session = $this->get_or_set_session_cookie();
        // Browser-reported impression: deduplicate by server session and form for
        // a brief window. This is analytics, not proof that a human saw the form.
        $dedupe_key = 'akdk_form_view_' . md5( $session . '|' . $id );
        if ( get_transient( $dedupe_key ) ) {
            return rest_ensure_response( array( 'success' => true, 'deduplicated' => true ) );
        }
        set_transient( $dedupe_key, 1, 5 * MINUTE_IN_SECONDS );
        $title = sanitize_text_field( isset( $found['title'] ) ? $found['title'] : $id );
        $title = function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 150 ) : substr( $title, 0, 150 );
        if ( class_exists( 'WP_AI_Chatbot_Logger' ) ) {
            WP_AI_Chatbot_Logger::log_chat( $session, '[FORM_SHOWN]:' . $id, $title );
        }
        return rest_ensure_response( array( 'success' => true ) );
    }

    public function handle_rest_chat_request( WP_REST_Request $request ) {
        $ip = WP_AI_Chatbot_Rate_Limiter::get_client_ip();
        if ( ! WP_AI_Chatbot_Rate_Limiter::allow( 'chat_' . $ip, WP_AI_CHATBOT_RATE_LIMIT_CHAT_MAX, WP_AI_CHATBOT_RATE_LIMIT_CHAT_WINDOW ) ) {
            return new WP_Error( 'rate_limited', 'You are sending messages too quickly. Please wait a moment and try again.', array( 'status' => 429 ) );
        }

        $raw_message  = $request->get_param( 'message' );
        $user_message = is_string( $raw_message ) ? sanitize_text_field( $raw_message ) : '';
        if ( function_exists( 'mb_substr' ) ) {
            $user_message = mb_substr( $user_message, 0, WP_AI_CHATBOT_MAX_MSG_LEN );
        } else {
            $user_message = substr( $user_message, 0, WP_AI_CHATBOT_MAX_MSG_LEN );
        }

        $memory_manager = new WP_AI_Chatbot_Memory_Manager();
        $history = $memory_manager->process_history( $request->get_param( 'history' ) );
        if ( empty( $user_message ) ) {
            return new WP_Error( 'empty_message', 'Message cannot be empty.', array( 'status' => 400 ) );
        }
        // The session cookie identifies this conversation's server-side state (list cursor, current item).
        $session_id = $this->get_or_set_session_cookie();

        // 2.0.0: which bot answers (Sales Tools > Bots), the page's language (WPML / Polylang)
        // and where the visitor is, for Live Chat and Chat History.
        WP_AI_Chatbot_Bots::activate( (string) $request->get_param( 'bot' ) );
        if ( WP_AI_Chatbot_I18n::active() ) {
            WP_AI_Chatbot_I18n::set_lang( (string) $request->get_param( 'lang' ) );
        }
        $page_url = esc_url_raw( (string) $request->get_param( 'page' ) );
        $page_url = ( '' !== $page_url && 0 === strpos( $page_url, home_url() ) ) ? substr( $page_url, 0, 250 ) : '';
        $touch    = array( 'channel' => 'web', 'bot_id' => WP_AI_Chatbot_Bots::active(), 'lang' => WP_AI_Chatbot_I18n::current() );
        if ( '' !== $page_url ) {
            $touch['page_url'] = $page_url;
        }
        WP_AI_Chatbot_Sessions::touch( $session_id, $touch );

        // Live chat: a person handles this chat (the bot stays quiet), or the visitor asks for one.
        $live = WP_AI_Chatbot_Live_Chat::before_ai( $session_id, $user_message );
        if ( is_array( $live ) ) {
            WP_AI_Chatbot_Logger::log_chat( $session_id, $user_message, $live['reply'] );
            return rest_ensure_response( array( 'success' => true, 'reply' => $live['reply'], 'mode' => $live['mode'] ) );
        }
        // WooCommerce order status: answered from the order itself, no AI call.
        $order_reply = WP_AI_Chatbot_Order_Status::maybe_answer( $session_id, $user_message, array( 'channel' => 'web' ) );
        if ( null !== $order_reply ) {
            WP_AI_Chatbot_Logger::log_chat( $session_id, $user_message, $order_reply );
            return rest_ensure_response( array( 'success' => true, 'reply' => $order_reply, 'mode' => 'bot' ) );
        }

        // Token control: daily site limit and messages per visitor. No AI call, no tokens.
        $gate = WP_AI_Chatbot_Token_Control::chat_gate( WP_AI_Chatbot_Rate_Limiter::get_client_ip() );
        if ( null !== $gate ) {
            return rest_ensure_response( array( 'success' => true, 'reply' => $gate, 'limited' => true ) );
        }
        $start_time = microtime(true);
        $api_handler = new WP_AI_Chatbot_API_Handler();
        // A submitted pre-chat name is kept separate from the admin-controlled
        // sliding message history. It is only context, never an instruction.
        $raw_visitor_name = $request->get_param( 'visitor_name' );
        $visitor_name = is_string( $raw_visitor_name ) ? sanitize_text_field( $raw_visitor_name ) : '';
        $visitor_name = function_exists( 'mb_substr' ) ? mb_substr( $visitor_name, 0, 120 ) : substr( $visitor_name, 0, 120 );
        // Use the name associated with an actual submitted form for this session.
        // The browser-provided field is only a fallback, not verified identity.
        if ( class_exists( 'WP_AI_Chatbot_Chat_Contacts' ) && '' !== $session_id ) {
            $contact_map = WP_AI_Chatbot_Chat_Contacts::for_sessions( array( $session_id ) );
            if ( isset( $contact_map[$session_id] ) && '' !== trim( (string) $contact_map[$session_id]->name ) ) {
                $visitor_name = sanitize_text_field( $contact_map[$session_id]->name );
            }
        }

        WP_AI_Chatbot_RAG_Engine::$seen_products = array();
        $bot_reply = $api_handler->process_chat_request( $user_message, $history, $session_id, $visitor_name );
        $end_time = microtime(true);
        $response_time = round($end_time - $start_time, 2);
        // Products in the reply become cards with Add to cart / Buy now (their image cards are then left out).
        $products = array();
        if ( ! $api_handler->last_request_failed ) {
            $products = WP_AI_Chatbot_Product_Cards::cards( WP_AI_Chatbot_Product_Cards::products_in( $bot_reply ) );
            if ( $products ) {
                $bot_reply = WP_AI_Chatbot_Product_Cards::strip_cards( $bot_reply, $products );
            }
        }
        if (class_exists('WP_AI_Chatbot_Logger')) {
            WP_AI_Chatbot_Logger::log_chat($session_id, $user_message, $bot_reply);
        }
        // Questions the bot could not answer: listed under Chat History > Unanswered questions.
        $unanswered = WP_AI_Chatbot_Unanswered::maybe_record( $session_id, $user_message, $bot_reply, $api_handler );
        global $wpdb;
        $analytics_table = $wpdb->prefix . 'ai_chat_analytics';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $analytics_table ) ) === $analytics_table ) {
            $usage             = WP_AI_Chatbot_AI::usage();
            $prompt_tokens     = $usage['prompt'];
            $completion_tokens = $usage['completion'];
            $embedding_tokens  = $usage['embedding'];
            $reply_lower = strtolower($bot_reply);
            $is_fallback = ( $api_handler->last_request_failed || strpos( $reply_lower, 'i don\'t know' ) !== false || strpos( $reply_lower, 'not sure' ) !== false || strpos( $reply_lower, 'contact our team' ) !== false ) ? 1 : 0;
            $wpdb->insert( $analytics_table, array(
                'session_id'        => $session_id,
                'prompt_tokens'     => $prompt_tokens,
                'completion_tokens' => $completion_tokens,
                'embedding_tokens'  => $embedding_tokens, 
                'response_time'     => $response_time,
                'is_fallback'       => $is_fallback,
                'token_source'      => $usage['exact'] ? 'api' : 'estimate',
                'unverified'        => empty( $api_handler->last_flags ) ? 0 : 1,
                'created_at'        => current_time('mysql')
            ));
        }
        $out = array( 'success' => true, 'reply' => $bot_reply, 'mode' => 'bot' );
        if ( $products ) {
            $out['products'] = $products;
        }
        // Could not answer: offer the "Talk to a person" button.
        if ( 'not_sure' === $unanswered && ! $api_handler->last_off_topic ) {
            $out['offer_human'] = true;
        }
        return rest_ensure_response( $out );
    }

    public function handle_lead_submission( WP_REST_Request $request ) {
        $ip = WP_AI_Chatbot_Rate_Limiter::get_client_ip();
        if ( ! WP_AI_Chatbot_Rate_Limiter::allow( 'lead_' . $ip, WP_AI_CHATBOT_RATE_LIMIT_LEAD_MAX, WP_AI_CHATBOT_RATE_LIMIT_LEAD_WINDOW ) ) {
            return new WP_Error( 'rate_limited', 'Too many submissions. Please try again in a minute.', array( 'status' => 429 ) );
        }

        $payload    = $request->get_json_params();
        $form_data  = ( isset( $payload['form_data'] ) && is_array( $payload['form_data'] ) ) ? $payload['form_data'] : array();
        $form_id    = isset( $form_data['wp_ai_form_id'] ) ? sanitize_key( wp_unslash( $form_data['wp_ai_form_id'] ) ) : '';

        if ( empty( $form_id ) ) {
            return new WP_Error( 'missing_form', 'This form could not be identified.', array( 'status' => 400 ) );
        }

        // Only accept submissions for forms that are currently defined AND enabled.
        $all_forms = get_option( 'wp_ai_chatbot_multi_forms', array() );
        $form_def  = null;
        if ( is_array( $all_forms ) ) {
            foreach ( $all_forms as $f ) {
                if ( isset( $f['id'] ) && $f['id'] === $form_id && ( ! isset( $f['is_enabled'] ) || $f['is_enabled'] === '1' ) ) {
                    $form_def = $f;
                    break;
                }
            }
        }
        if ( empty( $form_def ) || empty( $form_def['fields'] ) || ! is_array( $form_def['fields'] ) ) {
            return new WP_Error( 'invalid_form', 'This form is no longer available.', array( 'status' => 404 ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ai_leads_' . $form_id;

        // The table must have a column for every field (and TEXT for long answers). Forms made or
        // changed by older versions are repaired here once, instead of refusing every visitor.
        $needs_repair = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table;
        if ( ! $needs_repair ) {
            $col_types = array();
            foreach ( (array) $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A ) as $c ) {
                if ( isset( $c['Field'], $c['Type'] ) ) {
                    $col_types[ $c['Field'] ] = strtolower( (string) $c['Type'] );
                }
            }
            foreach ( $form_def['fields'] as $field ) {
                if ( empty( $field['label'] ) ) continue;
                $col = sanitize_key( str_replace( ' ', '_', strtolower( $field['label'] ) ) );
                $long = in_array( isset( $field['type'] ) ? $field['type'] : 'text', array( 'multiselect', 'textarea' ), true );
                if ( '' !== $col && ( ! isset( $col_types[ $col ] ) || ( $long && 0 === strpos( $col_types[ $col ], 'varchar' ) ) ) ) {
                    $needs_repair = true;
                    break;
                }
            }
        }
        if ( $needs_repair && class_exists( 'WP_AI_Chatbot_Form_Builder' ) ) {
            WP_AI_Chatbot_Form_Builder::repair_table( $form_def );
        }
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return new WP_Error( 'no_table', 'This form is not ready to accept submissions yet.', array( 'status' => 500 ) );
        }

        $existing_cols = $wpdb->get_col( "DESCRIBE {$table}", 0 );

        $insert         = array();
        $formats        = array();
        $missing_labels = array();

        foreach ( $form_def['fields'] as $field ) {
            if ( empty( $field['label'] ) ) continue;

            // Must mirror the same slug logic used when the column was created (class-form-builder.php)
            // and the same logic the frontend widget uses to name its inputs (chat-widget.js).
            $col = sanitize_key( str_replace( ' ', '_', strtolower( $field['label'] ) ) );
            // Never silently ignore a field if schema and saved form definition disagree.
            if ( empty( $col ) || ! in_array( $col, $existing_cols, true ) ) {
                return new WP_Error( 'form_schema_mismatch', 'This form needs administrator repair before submissions can be accepted.', array( 'status' => 503 ) );
            }

            $raw_val = isset( $form_data[ $col ] ) ? $form_data[ $col ] : '';
            $type = isset( $field['type'] ) ? sanitize_key( $field['type'] ) : 'text';
            $allowed_types = array( 'text', 'email', 'tel', 'textarea', 'select', 'multiselect' );
            if ( ! in_array( $type, $allowed_types, true ) ) {
                return new WP_Error( 'invalid_field_type', 'This form has an unsupported field type.', array( 'status' => 503 ) );
            }
            $choices = array();
            if ( in_array( $type, array( 'select', 'multiselect' ), true ) ) {
                $choices = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $field['options'] ?? '' ) ) ), 'strlen' ) );
                if ( ! $choices ) {
                    return new WP_Error( 'invalid_form_options', 'This form has invalid selection options.', array( 'status' => 503 ) );
                }
            }
            if ( 'multiselect' === $type ) {
                if ( ! is_array( $raw_val ) && '' !== $raw_val ) {
                    // The frontend may send comma-separated selected choices.
                    if ( ! is_scalar( $raw_val ) ) return new WP_Error( 'invalid_selection', 'Invalid selection.', array( 'status' => 400 ) );
                    $raw_val = array_map( 'trim', explode( ',', (string) $raw_val ) );
                }
                $values = array();
                foreach ( (array) $raw_val as $value ) {
                    if ( ! is_scalar( $value ) ) return new WP_Error( 'invalid_selection', 'Invalid selection.', array( 'status' => 400 ) );
                    $value = sanitize_text_field( (string) $value );
                    if ( '' !== $value && ! in_array( $value, $choices, true ) ) return new WP_Error( 'invalid_selection', 'Choose an available option.', array( 'status' => 400 ) );
                    if ( '' !== $value ) $values[] = $value;
                }
                $clean_val = implode( ', ', array_unique( $values ) );
            } else {
                if ( ! is_scalar( $raw_val ) ) return new WP_Error( 'invalid_field_value', 'Invalid field value.', array( 'status' => 400 ) );
                $clean_val = 'textarea' === $type ? sanitize_textarea_field( (string) $raw_val ) : sanitize_text_field( (string) $raw_val );
            }
            if ( 'email' === $type && '' !== $clean_val && ! is_email( $clean_val ) ) {
                return new WP_Error( 'invalid_email', 'Please enter a valid email address.', array( 'status' => 400 ) );
            }
            if ( 'tel' === $type && '' !== $clean_val && ! preg_match( '/^\+?[0-9][0-9 ()\-]{5,24}$/D', $clean_val ) ) {
                return new WP_Error( 'invalid_phone', 'Please enter a valid phone number.', array( 'status' => 400 ) );
            }
            if ( 'select' === $type && '' !== $clean_val && ! in_array( $clean_val, $choices, true ) ) {
                return new WP_Error( 'invalid_selection', 'Choose an available option.', array( 'status' => 400 ) );
            }
            // Long fields are stored as SQL TEXT; reject rather than silently truncate.
            $maximum = in_array( $type, array( 'textarea', 'multiselect' ), true ) ? 65000 : 500;
            if ( strlen( $clean_val ) > $maximum ) {
                return new WP_Error( 'field_too_long', 'One of the fields is too long. Please shorten it.', array( 'status' => 400 ) );
            }
            // Schema drift: enforce actual varchar limit even if a field type was changed later.
            $column_schema = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $col ), ARRAY_A );
            if ( ! $column_schema || ( preg_match( '/^varchar\((\d+)\)/i', $column_schema['Type'], $m ) && function_exists( 'mb_strlen' ) && mb_strlen( $clean_val ) > (int) $m[1] ) ) {
                return new WP_Error( 'form_schema_mismatch', 'This form needs administrator repair before submissions can be accepted.', array( 'status' => 503 ) );
            }
            if ( ( isset( $field['required'] ) && 'yes' === $field['required'] ) && '' === trim( $clean_val ) ) {
                $missing_labels[] = $field['label'];
            }

            $insert[ $col ] = $clean_val;
            $formats[]      = '%s';
        }

        if ( ! empty( $missing_labels ) ) {
            return new WP_Error( 'missing_fields', 'Please fill in: ' . implode( ', ', $missing_labels ), array( 'status' => 400 ) );
        }
        if ( empty( $insert ) ) {
            return new WP_Error( 'no_data', 'No valid form data was received.', array( 'status' => 400 ) );
        }

        $inserted = $wpdb->insert( $table, $insert, $formats );
        if ( false === $inserted ) {
            return new WP_Error( 'db_error', 'Could not save your submission. Please try again.', array( 'status' => 500 ) );
        }

        // Lead alert (and optional thank-you email); sent after the visitor has their answer.
        $session = '';
        if ( isset( $_COOKIE['wp_ai_chat_session'] ) ) {
            $maybe = strtolower( (string) wp_unslash( $_COOKIE['wp_ai_chat_session'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated below.
            $session = self::valid_session_id( $maybe ) ? $maybe : '';
        }
        // Chat History: show this chat under the visitor's name (from the form) instead of its ID.
        WP_AI_Chatbot_Chat_Contacts::remember( $session, $form_def, $insert, (int) $wpdb->insert_id );

        $page = wp_get_referer();
        WP_AI_Chatbot_Lead_Alerts::new_lead( $form_def, $insert, $session, $page && 0 === strpos( $page, home_url() ) ? $page : '' );

        return rest_ensure_response( array(
            'success' => true,
            'message' => 'Thank you! Your submission has been received.',
        ) );
    }
}
