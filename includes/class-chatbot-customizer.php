<?php
/**
 * Appearance screen: templates, colours, layout and a live preview of the widget.
 *
 * @package AK_AI_Chatbot
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_AI_Chatbot_Customizer {

    private $option_name = 'wp_ai_chatbot_custom_style';
    private $opts        = array();

    public function register_hooks() {
        add_action( 'admin_menu', array( $this, 'add_submenu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    public function add_submenu() {
        add_submenu_page( 'wp-ai-chatbot', 'Chatbot Appearance', 'Appearance', 'manage_options', 'wp-ai-chatbot-customizer', array( $this, 'display_page' ) );
    }

    public function enqueue_assets( $hook ) {
        if ( false === strpos( (string) $hook, 'wp-ai-chatbot-customizer' ) ) {
            return;
        }
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_style( 'wp-ai-chat-widget-style', WP_AI_CHATBOT_URL . 'public/css/chat-widget.css', array(), WP_AI_CHATBOT_VERSION );
        wp_enqueue_style( 'wp-ai-launcher-icons', WP_AI_CHATBOT_URL . 'public/css/launcher-icons.css', array( 'wp-ai-chat-widget-style' ), WP_AI_CHATBOT_VERSION );
        wp_enqueue_script( 'wp-ai-chatbot-templates', WP_AI_CHATBOT_URL . 'admin/js/chatbot-templates.js', array( 'jquery', 'wp-color-picker' ), WP_AI_CHATBOT_VERSION, true );
    }

    public function register_settings() {
        register_setting( 'wp_ai_customizer_group', $this->option_name, array( 'sanitize_callback' => array( $this, 'sanitize_style' ) ) );
    }

    /**
     * Values are printed inside a <style> block, so strip anything that could
     * break out of a CSS declaration.
     */
    public function sanitize_style( $input ) {
        $clean = array();
        if ( ! is_array( $input ) ) {
            return $clean;
        }
        foreach ( $input as $key => $value ) {
            $key = sanitize_key( $key );
            if ( is_array( $value ) ) {
                continue;
            }
            if ( in_array( $key, array( 'bot_name', 'header_subtitle', 'input_placeholder' ), true ) ) {
                $clean[ $key ] = sanitize_text_field( (string) $value );
            } elseif ( 'launcher_greet_delay' === $key ) {
                $clean[ $key ] = (string) max( 0, min( 120, intval( $value ) ) );
            } elseif ( 'launcher_greet_duration' === $key ) {
                $clean[ $key ] = (string) max( 1, min( 120, intval( $value ) ) );
            } elseif ( 'launcher_greeting' === $key ) {
                $clean[ $key ] = function_exists( 'mb_substr' ) ? mb_substr( sanitize_text_field( (string) $value ), 0, 30 ) : substr( sanitize_text_field( (string) $value ), 0, 30 );
            } elseif ( 'launcher_style' === $key ) {
                $clean[ $key ] = in_array( $value, WP_AI_Chatbot_Launcher_Icons::styles(), true ) ? $value : 'akdk_bot';
            } elseif ( 'launcher_mode' === $key ) {
                $clean[ $key ] = in_array( $value, array( 'light', 'dark', 'auto' ), true ) ? $value : 'light';
            } elseif ( 0 === strpos( $key, 'icon_' ) && 'icon_size' !== $key ) {
                $color         = sanitize_hex_color( trim( (string) $value ) );
                $clean[ $key ] = $color ? $color : '';
            } elseif ( in_array( $key, array( 'toggle_icon', 'send_btn_icon', 'bot_avatar' ), true ) ) {
                $clean[ $key ] = esc_url_raw( trim( (string) $value ) );
            } elseif ( 'voice_mode' === $key ) {
                $clean[ $key ] = 'strict' === $value ? 'strict' : 'auto';
            } elseif ( 'voice_lang' === $key ) {
                $clean[ $key ] = preg_replace( '/[^A-Za-z\-]/', '', (string) $value );
            } else {
                $clean[ $key ] = trim( str_replace( array( '{', '}', ';', '<', '>', '\\', '"', "'" ), '', sanitize_text_field( (string) $value ) ) );
            }
        }
        foreach ( array( 'show_branding', 'voice_input', 'toggle_icon_transparent', 'chat_bg_transparent', 'launcher_greet' ) as $flag ) {
            if ( isset( $clean[ $flag ] ) ) {
                $clean[ $flag ] = '1' === $clean[ $flag ] ? '1' : '0';
            }
        }
        return $clean;
    }

    /* ---------- Small field helpers ---------- */

    private function name( $key ) {
        return $this->option_name . '[' . $key . ']';
    }

    private function color( $key, $label ) {
        printf(
            '<label class="akdk-field akdk-color"><span>%1$s</span><input type="text" name="%2$s" value="%3$s" class="akdk-color-picker" data-key="%4$s" /></label>',
            esc_html( $label ),
            esc_attr( $this->name( $key ) ),
            esc_attr( $this->opts[ $key ] ),
            esc_attr( $key )
        );
    }

    private function number( $key, $label, $min = 0, $max = 2000 ) {
        printf(
            '<label class="akdk-field"><span>%1$s</span><span class="akdk-input-unit"><input type="number" name="%2$s" value="%3$s" min="%4$d" max="%5$d" data-key="%6$s" /><em>px</em></span></label>',
            esc_html( $label ),
            esc_attr( $this->name( $key ) ),
            esc_attr( $this->opts[ $key ] ),
            (int) $min,
            (int) $max,
            esc_attr( $key )
        );
    }

    private function text( $key, $label, $placeholder = '', $help = '' ) {
        printf(
            '<label class="akdk-field akdk-field-wide"><span>%1$s</span><input type="text" name="%2$s" value="%3$s" placeholder="%4$s" data-key="%5$s" />%6$s</label>',
            esc_html( $label ),
            esc_attr( $this->name( $key ) ),
            esc_attr( $this->opts[ $key ] ),
            esc_attr( $placeholder ),
            esc_attr( $key ),
            $help ? '<small>' . esc_html( $help ) . '</small>' : ''
        );
    }

    private function select( $key, $label, $choices ) {
        echo '<label class="akdk-field"><span>' . esc_html( $label ) . '</span><select name="' . esc_attr( $this->name( $key ) ) . '" data-key="' . esc_attr( $key ) . '">';
        foreach ( $choices as $value => $text ) {
            echo '<option value="' . esc_attr( $value ) . '"' . selected( $this->opts[ $key ], $value, false ) . '>' . esc_html( $text ) . '</option>';
        }
        echo '</select></label>';
    }

    private function toggle( $key, $label, $help = '' ) {
        printf(
            '<label class="akdk-toggle"><input type="hidden" name="%1$s" value="0" /><input type="checkbox" name="%1$s" value="1" data-key="%2$s" %3$s /><span class="akdk-switch" aria-hidden="true"></span><span class="akdk-toggle-text"><strong>%4$s</strong>%5$s</span></label>',
            esc_attr( $this->name( $key ) ),
            esc_attr( $key ),
            checked( '1', $this->opts[ $key ], false ),
            esc_html( $label ),
            $help ? '<small>' . esc_html( $help ) . '</small>' : ''
        );
    }

    /** "Chat Icon" tab: animated icon gallery, colours, greeting and size. */
    private function icons_panel() {
        $o       = $this->opts;
        $catalog = WP_AI_Chatbot_Launcher_Icons::catalog();
        $mode    = WP_AI_Chatbot_Launcher_Icons::mode( $o );
        ?>
        <section class="akdk-panel" data-panel="icons">
            <h2>Choose your chat icon</h2>
            <p class="akdk-hint">These are live: what you see moving here is what visitors see. Hover a card to see how the icon reacts. The icon is shown under the chat window in the preview.</p>
            <div class="akdk-icon-switch" role="group" aria-label="Show icons on">
                <span>Show on:</span>
                <button type="button" class="akdk-site-btn<?php echo 'dark' !== $mode ? ' is-active' : ''; ?>" data-site="light">Light website</button>
                <button type="button" class="akdk-site-btn<?php echo 'dark' === $mode ? ' is-active' : ''; ?>" data-site="dark">Dark website</button>
            </div>
            <div class="akdk-icon-grid akic-scope akic-mode-<?php echo esc_attr( $mode ); ?><?php echo 'dark' === $mode ? ' akdk-site-dark' : ''; ?>" style="<?php echo esc_attr( WP_AI_Chatbot_Launcher_Icons::css_vars( $o ) ); ?>">
                <?php
                $groups = array( 'bot' => 'Chatbot icons', 'fun' => 'Fun characters' );
                $group  = '';
                foreach ( $catalog as $id => $info ) :
                    if ( $info[2] !== $group ) :
                        $group = $info[2];
                        ?>
                        <h3 class="akic-group"><?php echo esc_html( $groups[ $group ] ); ?></h3>
                    <?php endif; ?>
                    <label class="akic-card<?php echo $id === $o['launcher_style'] ? ' is-selected' : ''; ?>">
                        <input type="radio" name="<?php echo esc_attr( $this->name( 'launcher_style' ) ); ?>" value="<?php echo esc_attr( $id ); ?>" data-key="launcher_style" <?php checked( $o['launcher_style'], $id ); ?>>
                        <span class="akic-card-stage"><?php echo WP_AI_Chatbot_Launcher_Icons::svg( $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed plugin SVG. ?></span>
                        <strong><?php echo esc_html( $info[0] ); ?></strong>
                        <small><?php echo esc_html( $info[1] ); ?></small>
                    </label>
                <?php endforeach; ?>
                <h3 class="akic-group">Simple or your own</h3>
                <label class="akic-card akic-card-classic<?php echo 'classic' === $o['launcher_style'] ? ' is-selected' : ''; ?>">
                    <input type="radio" name="<?php echo esc_attr( $this->name( 'launcher_style' ) ); ?>" value="classic" data-key="launcher_style" <?php checked( $o['launcher_style'], 'classic' ); ?>>
                    <span class="akic-card-stage"><span class="akic-classic-dot" style="background:<?php echo esc_attr( $o['header_bg'] ); ?>;color:<?php echo esc_attr( $o['header_text'] ); ?>"><?php echo WP_AI_Chatbot_Launcher_Icons::classic_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed plugin SVG. ?></span></span>
                    <strong>Classic bubble</strong>
                    <small>Simple round button, header colours</small>
                </label>
                <label class="akic-card akic-card-custom<?php echo 'custom' === $o['launcher_style'] ? ' is-selected' : ''; ?>">
                    <input type="radio" name="<?php echo esc_attr( $this->name( 'launcher_style' ) ); ?>" value="custom" data-key="launcher_style" <?php checked( $o['launcher_style'], 'custom' ); ?>>
                    <span class="akic-card-stage"><span class="akic-custom-thumb"><?php if ( ! empty( $o['toggle_icon'] ) ) : ?><img src="<?php echo esc_url( $o['toggle_icon'] ); ?>" alt=""><?php else : ?><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="3"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><path d="M21 15l-5-5L5 21"></path></svg><?php endif; ?></span></span>
                    <strong>Your own icon</strong>
                    <small>Image or GIF from a link</small>
                </label>
            </div>

            <div class="akdk-grid akdk-custom-icon-row"<?php echo 'custom' === $o['launcher_style'] ? '' : ' hidden'; ?>>
                <?php $this->text( 'toggle_icon', 'Icon image link (PNG, JPG, GIF, WebP or SVG)', 'https://example.com/my-icon.gif', 'Upload it under Media, Add New, then copy the file URL. Animated GIFs work too.' ); ?>
                <div class="akdk-stack"><?php $this->toggle( 'toggle_icon_transparent', 'No round background behind the image', 'For icons with a transparent background.' ); ?></div>
            </div>

            <h2>Light or dark website</h2>
            <div class="akdk-grid">
                <?php
                $this->select(
                    'launcher_mode',
                    'Icon outline',
                    array(
                        'light' => 'Light website (dark outline)',
                        'dark'  => 'Dark website (white outline)',
                        'auto'  => 'Automatic (follows the visitor\'s light or dark mode)',
                    )
                );
                ?>
            </div>

            <h2>Icon colours</h2>
            <div class="akdk-grid">
                <?php
                $this->color( 'icon_main', 'Body' );
                $this->color( 'icon_face', 'Face, screen and pupils' );
                $this->color( 'icon_eye', 'Eyes and greeting bubble' );
                $this->color( 'icon_accent', 'Details (antenna, sparkle, mic)' );
                $this->color( 'icon_ink', 'Outline on light websites' );
                $this->color( 'icon_ink_dark', 'Outline on dark websites' );
                ?>
            </div>
            <p><button type="button" class="button" id="akdk-icon-colors-reset">Use AK Deep Knowledge colours</button></p>

            <h2>Greeting</h2>
            <div class="akdk-stack">
                <?php $this->toggle( 'launcher_greet', 'Show a greeting bubble next to the icon', 'Shows on each page visit for the selected duration, regardless of saved chat history. Always visible on hover.' ); ?>
            </div>
            <div class="akdk-grid">
                <?php $this->text( 'launcher_greeting', 'Greeting text (max 30 characters)', 'Hi!' ); ?>
                <?php $this->number( 'launcher_greet_delay', 'Show after (seconds)', 0, 120 ); ?>
                <?php $this->number( 'launcher_greet_duration', 'Stay visible for (seconds)', 1, 120 ); ?>
            </div>

            <h2>Size</h2>
            <div class="akdk-grid">
                <?php $this->number( 'icon_size', 'Icon size', 40, 110 ); ?>
            </div>
        </section>
        <?php
    }

    public function display_page() {
        $this->opts = WP_AI_Chatbot_Widget_Style::get();
        $o          = $this->opts;
        ?>
        <div class="wrap akdk-wrap">
            <h1 class="akdk-page-title">Appearance</h1>
            <p class="akdk-page-intro">Pick a template, then fine-tune colours and layout. The preview updates as you change things. Click <strong>Save</strong> to publish.</p>
            <?php settings_errors(); ?>

            <div class="akdk-customizer">
                <form method="post" action="options.php" id="wp-ai-customizer-form" class="akdk-customizer-form">
                    <?php settings_fields( 'wp_ai_customizer_group' ); ?>

                    <nav class="akdk-tabs" role="tablist">
                        <button type="button" class="akdk-tab is-active" data-tab="templates">Templates</button>
                        <button type="button" class="akdk-tab" data-tab="icons">Chat Icon</button>
                        <button type="button" class="akdk-tab" data-tab="general">General</button>
                        <button type="button" class="akdk-tab" data-tab="colors">Colours</button>
                        <button type="button" class="akdk-tab" data-tab="input">Input &amp; Buttons</button>
                        <button type="button" class="akdk-tab" data-tab="layout">Size &amp; Position</button>
                    </nav>

                    <section class="akdk-panel is-active" data-panel="templates">
                        <h2>Brand templates</h2>
                        <div class="akdk-featured">
                            <button type="button" class="apply-template-btn akdk-tpl-card akdk-tpl-light" data-template="akdk_light">
                                <span class="akdk-tpl-swatch"><i style="background:#fd9400"></i><i style="background:#ffffff"></i><i style="background:#0a0a0a"></i></span>
                                <strong>AK Deep Knowledge Light</strong><small>Orange header, white chat</small>
                            </button>
                            <button type="button" class="apply-template-btn akdk-tpl-card akdk-tpl-dark" data-template="akdk_dark">
                                <span class="akdk-tpl-swatch"><i style="background:#0a0a0a"></i><i style="background:#1a1a1a"></i><i style="background:#fd9400"></i></span>
                                <strong>AK Deep Knowledge Dark</strong><small>Black chat, orange accents</small>
                            </button>
                        </div>
                        <details class="akdk-more">
                        <summary>Show 70+ more templates</summary>
                        <div class="akdk-tpl-grid">
                    <button type="button" class="button apply-template-btn" data-template="chatgpt_light">Minimal Light</button>
                    <button type="button" class="button apply-template-btn" data-template="chatgpt_dark" style="background:#212121; color:#fff; border:none;">Minimal Dark</button>
                    <button type="button" class="button apply-template-btn" data-template="claude_light" style="background:#f3f0e8; color:#d97757; border:1px solid #d97757;">Warm Paper</button>
                    <button type="button" class="button apply-template-btn" data-template="whatsapp" style="background:#25D366; color:#fff; border:none;">Fresh Green</button>
                    <button type="button" class="button apply-template-btn" data-template="messenger" style="background:#0084ff; color:#fff; border:none;">Sky Blue</button>
                    <button type="button" class="button apply-template-btn" data-template="instagram" style="background:linear-gradient(45deg, #f09433 0%, #e6683c 25%, #dc2743 50%, #cc2366 75%, #bc1888 100%); color:#fff; border:none;">Sunset Gradient</button>
                    <button type="button" class="button apply-template-btn" data-template="twitter" style="background:#1DA1F2; color:#fff; border:none;">Bright Blue</button>
                    <button type="button" class="button apply-template-btn" data-template="telegram" style="background:#0088cc; color:#fff; border:none;">Cloud Blue</button>
                    <button type="button" class="button apply-template-btn" data-template="discord" style="background:#5865F2; color:#fff; border:none;">Blurple</button>
                    <button type="button" class="button apply-template-btn" data-template="discord_dark" style="background:#36393f; color:#fff; border:none;">Blurple Dark</button>
                    <button type="button" class="button apply-template-btn" data-template="linkedin" style="background:#0077b5; color:#fff; border:none;">Business Blue</button>
                    <button type="button" class="button apply-template-btn" data-template="slack" style="background:#4A154B; color:#fff; border:none;">Aubergine</button>
                    <button type="button" class="button apply-template-btn" data-template="imessage" style="background:#34C759; color:#fff; border:none;">Bubble Blue</button>
                    <button type="button" class="button apply-template-btn" data-template="android_sms" style="background:#1a73e8; color:#fff; border:none;">Classic SMS</button>
                    <button type="button" class="button apply-template-btn" data-template="teams" style="background:#6264A7; color:#fff; border:none;">Corporate Violet</button>
                    <button type="button" class="button apply-template-btn" data-template="material_ui" style="border:2px solid #4285F4; color:#4285F4;">Material</button>
                    <button type="button" class="button apply-template-btn" data-template="fluent_design" style="background:#f3f2f1; color:#000;">Fluent</button>
                    <button type="button" class="button apply-template-btn" data-template="cyberpunk" style="background:#fcee0a; color:#00f0ff; border:2px solid #00f0ff;">Cyberpunk</button>
                    <button type="button" class="button apply-template-btn" data-template="monochrome" style="background:#000; color:#fff; border:none;">Monochrome</button>
                    <button type="button" class="button apply-template-btn" data-template="glassmorphism" style="background:linear-gradient(135deg, rgba(255,255,255,0.1), rgba(255,255,255,0)); border:1px solid #ccc; backdrop-filter:blur(10px);">Glassmorphism</button>
                    
                    <button type="button" class="button apply-template-btn" data-template="spotify" style="background:#1DB954; color:#fff; border:none;">Vibrant Green</button>
                    <button type="button" class="button apply-template-btn" data-template="netflix" style="background:#E50914; color:#fff; border:none;">Cinema Red</button>
                    <button type="button" class="button apply-template-btn" data-template="twitch" style="background:#9146FF; color:#fff; border:none;">Electric Purple</button>
                    <button type="button" class="button apply-template-btn" data-template="github" style="background:#24292e; color:#fff; border:none;">Charcoal</button>
                    <button type="button" class="button apply-template-btn" data-template="github_dark" style="background:#0d1117; color:#c9d1d9; border:1px solid #30363d;">Charcoal Dark</button>
                    <button type="button" class="button apply-template-btn" data-template="notion" style="border:1px solid #37352f;">Paper</button>
                    <button type="button" class="button apply-template-btn" data-template="pinterest" style="background:#e60023; color:#fff; border:none;">Cherry Red</button>
                    <button type="button" class="button apply-template-btn" data-template="snapchat" style="background:#FFFC00; color:#000; border:none;">Sunny Yellow</button>
                    <button type="button" class="button apply-template-btn" data-template="reddit" style="background:#FF4500; color:#fff; border:none;">Bright Orange</button>
                    <button type="button" class="button apply-template-btn" data-template="tiktok" style="background:#010101; color:#fff; border:2px solid #25F4EE;">Neon Night</button>
                    <button type="button" class="button apply-template-btn" data-template="dracula" style="background:#282a36; color:#ff79c6; border:none;">Dracula</button>
                    <button type="button" class="button apply-template-btn" data-template="monokai" style="background:#272822; color:#a6e22e; border:none;">Monokai</button>
                    <button type="button" class="button apply-template-btn" data-template="nord" style="background:#2e3440; color:#88c0d0; border:none;">Nord</button>
                    <button type="button" class="button apply-template-btn" data-template="synthwave" style="background:#262335; color:#ff7edb; border:none;">Synthwave</button>
                    <button type="button" class="button apply-template-btn" data-template="solarized_dark" style="background:#002b36; color:#2aa198; border:none;">Solarized Dk</button>
                    <button type="button" class="button apply-template-btn" data-template="solarized_light" style="background:#fdf6e3; color:#268bd2; border:none;">Solarized Lt</button>
                    <button type="button" class="button apply-template-btn" data-template="windows_11" style="background:#005fb8; color:#fff; border:none;">Desktop Blue</button>
                    <button type="button" class="button apply-template-btn" data-template="macos" style="background:#e8e8e8; color:#000; border:1px solid #ccc;">Silver</button>
                    <button type="button" class="button apply-template-btn" data-template="neon_glow" style="background:#000; color:#39ff14; border:1px solid #39ff14;">Neon Glow</button>
                    <button type="button" class="button apply-template-btn" data-template="pastel_dream" style="background:#ffb6c1; color:#fff; border:none;">Pastel Dream</button>
                    <button type="button" class="button apply-template-btn" data-template="ocean_breeze" style="background:#006994; color:#fff; border:none;">Ocean Breeze</button>
                    <button type="button" class="button apply-template-btn" data-template="forest_green" style="background:#2d5a27; color:#fff; border:none;">Forest Green</button>
                    <button type="button" class="button apply-template-btn" data-template="sunset_orange" style="background:#e65100; color:#fff; border:none;">Sunset Orange</button>
                    <button type="button" class="button apply-template-btn" data-template="luxury_gold" style="background:#1a1a1a; color:#d4af37; border:none;">Luxury Gold</button>
                    <button type="button" class="button apply-template-btn" data-template="matte_black" style="background:#1e1e1e; color:#fff; border:none;">Matte Black</button>
                    <button type="button" class="button apply-template-btn" data-template="hacker" style="background:#000; color:#0f0; border:1px solid #0f0;">Hacker</button>
                    <button type="button" class="button apply-template-btn" data-template="deep_space" style="background:#0b0c10; color:#58a6ff; border:none;">Deep Space</button>
                    <button type="button" class="button apply-template-btn" data-template="coral" style="background:#ff7f50; color:#fff; border:none;">Coral</button>
                    <button type="button" class="button apply-template-btn" data-template="mocha" style="background:#4e342e; color:#fff; border:none;">Mocha</button>
                    <button type="button" class="button apply-template-btn" data-template="mint" style="background:#00bfa5; color:#fff; border:none;">Mint</button>
                    <button type="button" class="button apply-template-btn" data-template="lavender" style="background:#7e57c2; color:#fff; border:none;">Lavender</button>
                    <button type="button" class="button apply-template-btn" data-template="peach" style="background:#ffab91; color:#fff; border:none;">Peach</button>
                    <button type="button" class="button apply-template-btn" data-template="teal" style="background:#00695c; color:#fff; border:none;">Teal</button>
                    <button type="button" class="button apply-template-btn" data-template="slate" style="background:#455a64; color:#fff; border:none;">Slate</button>
                    <button type="button" class="button apply-template-btn" data-template="crimson" style="background:#b71c1c; color:#fff; border:none;">Crimson</button>
                    <button type="button" class="button apply-template-btn" data-template="azure" style="background:#0277bd; color:#fff; border:none;">Azure</button>
                    <button type="button" class="button apply-template-btn" data-template="indigo" style="background:#283593; color:#fff; border:none;">Indigo</button>
                    <button type="button" class="button apply-template-btn" data-template="amber" style="background:#ff8f00; color:#fff; border:none;">Amber</button>
                    <button type="button" class="button apply-template-btn" data-template="emerald" style="background:#2e7d32; color:#fff; border:none;">Emerald</button>
                    <button type="button" class="button apply-template-btn" data-template="fuchsia" style="background:#c2185b; color:#fff; border:none;">Fuchsia</button>
                    <button type="button" class="button apply-template-btn" data-template="rose" style="background:#e91e63; color:#fff; border:none;">Rose</button>
                    <button type="button" class="button apply-template-btn" data-template="violet" style="background:#673ab7; color:#fff; border:none;">Violet</button>
                    <button type="button" class="button apply-template-btn" data-template="cyan" style="background:#0097a7; color:#fff; border:none;">Cyan</button>
                    <button type="button" class="button apply-template-btn" data-template="lime" style="background:#afb42b; color:#fff; border:none;">Lime</button>
                    <button type="button" class="button apply-template-btn" data-template="emerald_dark" style="background:#1b5e20; color:#fff; border:none;">Emerald Dark</button>
                    <button type="button" class="button apply-template-btn" data-template="midnight" style="background:#1a237e; color:#fff; border:none;">Midnight</button>
                    <button type="button" class="button apply-template-btn" data-template="valentine" style="background:#e91e63; color:#fff; border:none;">Valentine</button>
                    <button type="button" class="button apply-template-btn" data-template="halloween" style="background:#e65100; color:#000; border:none;">Halloween</button>
                    <button type="button" class="button apply-template-btn" data-template="royal_purple" style="background:#4a148c; color:#fff; border:none;">Royal Purple</button>
                    <button type="button" class="button apply-template-btn" data-template="golden_hour" style="background:#ffb300; color:#000; border:none;">Golden Hour</button>
                    <button type="button" class="button apply-template-btn" data-template="frost_glass" style="background:rgba(255,255,255,0.2); color:#333; border:1px solid #ccc; backdrop-filter:blur(5px);">Frost Glass</button>
                        </div>
                        </details>
                    </section>

                    <?php $this->icons_panel(); ?>

                    <section class="akdk-panel" data-panel="general">
                        <h2>Header</h2>
                        <div class="akdk-grid">
                            <?php
                            $this->text( 'bot_name', 'Chatbot name', 'AI Assistant' );
                            $this->text( 'header_subtitle', 'Subtitle under the name', 'Online · replies instantly', 'Leave empty to hide.' );
                            $this->text( 'input_placeholder', 'Chat input placeholder', 'Type your message...' );
                            $this->text( 'bot_avatar', 'Avatar image URL (optional)', 'https://…', 'Square image. Without one, the first letter of the name is shown.' );
                            ?>
                        </div>
                        <h2>Features</h2>
                        <div class="akdk-stack">
                            <?php
                            $this->toggle( 'voice_input', 'Voice input', 'Adds a microphone button to the chat. Needs an HTTPS site.' );
                            ?>
                            <div class="akdk-grid">
                                <?php
                                $this->select(
                                    'voice_mode',
                                    'Voice listens to',
                                    array(
                                        'auto'   => 'All languages (recommended)',
                                        'strict' => 'One language only (strict)',
                                    )
                                );
                                $this->select(
                                    'voice_lang',
                                    'Strict language',
                                    array(
                                        'en-US' => 'English (US)',
                                        'en-GB' => 'English (UK)',
                                        'ur-PK' => 'Urdu (Pakistan)',
                                        'hi-IN' => 'Hindi (India)',
                                        'ar-SA' => 'Arabic',
                                    )
                                );
                                ?>
                            </div>
                            <p class="akdk-hint">
                                <strong>All languages:</strong> visitors can speak any language. English is written in English, Urdu and Hindi are written in Roman Urdu (for example "mujhe price batao"). This uses your Gemini API key, about 32 tokens per second of speech (a 10 second message costs a fraction of a cent), and works in all modern browsers.
                                <?php if ( '' === WP_AI_Chatbot_AI::api_key( 'gemini' ) ) : ?>
                                    <br><strong>No Gemini API key is saved</strong>, so the browser's own speech recognition is used in each visitor's browser language until you add one under AI Settings.
                                <?php endif; ?>
                                <br><strong>One language only:</strong> the browser's free speech recognition listens only to the strict language above (Chrome, Edge and Safari). Use this when visitors should speak one language.
                            </p>
                            <?php $this->toggle( 'show_branding', 'Show "Powered by AK Deep Knowledge"', 'Small credit line under the message box.' ); ?>
                        </div>
                    </section>

                    <section class="akdk-panel" data-panel="colors">
                        <h2>Header &amp; window</h2>
                        <div class="akdk-grid">
                            <?php
                            $this->color( 'header_bg', 'Header background' );
                            $this->color( 'header_text', 'Header text' );
                            $this->color( 'chat_bg', 'Chat background' );
                            $this->color( 'general_border_color', 'Lines & borders' );
                            ?>
                        </div>
                        <h2>Messages</h2>
                        <div class="akdk-grid">
                            <?php
                            $this->color( 'bot_bubble_bg', 'Bot bubble' );
                            $this->color( 'bot_bubble_text', 'Bot text' );
                            $this->color( 'user_bubble_bg', 'Visitor bubble' );
                            $this->color( 'user_bubble_text', 'Visitor text' );
                            ?>
                        </div>
                    </section>

                    <section class="akdk-panel" data-panel="input">
                        <h2>Message box</h2>
                        <div class="akdk-grid">
                            <?php
                            $this->color( 'input_bg_color', 'Background' );
                            $this->color( 'input_border_color', 'Border' );
                            $this->color( 'input_border_focus_color', 'Border while typing' );
                            $this->select(
                                'input_border_sides',
                                'Border style',
                                array(
                                    'all'    => 'Rounded box',
                                    'bottom' => 'Underline',
                                    'top'    => 'Line above',
                                    'none'   => 'No border',
                                )
                            );
                            $this->number( 'input_border_width', 'Border width', 0, 6 );
                            ?>
                        </div>
                        <h2>Send button</h2>
                        <div class="akdk-grid">
                            <?php
                            $this->color( 'send_btn_bg', 'Background' );
                            $this->color( 'send_btn_hover_bg', 'Hover background' );
                            $this->color( 'send_btn_text', 'Icon' );
                            $this->color( 'send_btn_icon_hover', 'Hover icon' );
                            $this->text( 'send_btn_icon', 'Custom icon URL (optional)', 'https://…' );
                            ?>
                        </div>
                        <h2>Quick action buttons</h2>
                        <div class="akdk-grid">
                            <?php
                            $this->select(
                                'quick_actions_align',
                                'Alignment',
                                array(
                                    'left'   => 'Left',
                                    'center' => 'Center',
                                    'right'  => 'Right',
                                )
                            );
                            ?>
                            <p class="akdk-hint">Used when the buttons fit on one line. When there are more, the row scrolls sideways.</p>
                        </div>
                        <h2>Close (X) button</h2>
                        <div class="akdk-grid">
                            <?php
                            $this->color( 'close_bg', 'Background' );
                            $this->color( 'close_icon', 'Icon' );
                            $this->color( 'close_hover_bg', 'Hover background' );
                            $this->color( 'close_hover_icon', 'Hover icon' );
                            $this->number( 'close_btn_size', 'Button size', 20, 60 );
                            ?>
                        </div>
                        <h2>Clear-chat button</h2>
                        <div class="akdk-grid">
                            <?php
                            $this->color( 'close_btn_color', 'Icon' );
                            $this->color( 'del_btn_bg', 'Hover background' );
                            $this->color( 'del_btn_hover_bg', 'Dialog "Clear" hover' );
                            ?>
                        </div>
                    </section>

                    <section class="akdk-panel" data-panel="layout">
                        <h2>Position</h2>
                        <div class="akdk-grid">
                            <?php
                            $this->select(
                                'position',
                                'Screen corner',
                                array(
                                    'right' => 'Bottom right',
                                    'left'  => 'Bottom left',
                                )
                            );
                            $this->number( 'margin_side', 'Distance from side', 0, 200 );
                            $this->number( 'margin_bottom', 'Distance from bottom', 0, 200 );
                            ?>
                        </div>
                        <h2>Mobile Position</h2>
                        
                        <div class="akdk-grid">
                            <?php
                            $this->number( 'margin_side_mobile', 'Distance from side', 0, 200 );
                            $this->number( 'margin_bottom_mobile', 'Distance from bottom', 0, 200 );
                            ?>
                        </div>
                        
                        <h2>Size</h2>
                        <div class="akdk-grid">
                            <?php
                            $this->number( 'chat_width', 'Window width', 300, 600 );
                            $this->number( 'chat_height', 'Window height', 400, 900 );
                            $this->number( 'chat_radius', 'Corner roundness', 0, 32 );
                            ?>
                        </div>
                        <p class="akdk-hint">On phones the chat always opens full screen. The chat icon and its size are on the <strong>Chat Icon</strong> tab.</p>
                        <h2>Effects</h2>
                        <div class="akdk-stack">
                            <?php $this->toggle( 'chat_bg_transparent', 'Glass effect', 'Blurs the page behind the chat window. Use a semi-transparent chat background.' ); ?>
                        </div>
                    </section>

                    <div class="akdk-savebar">
                        <?php submit_button( 'Save changes', 'primary', 'submit', false ); ?>
                        <button type="button" id="wp-ai-reset-btn" class="button akdk-button-ghost">Reset to default</button>
                        <span class="akdk-savebar-note" id="akdk-unsaved" hidden>Unsaved changes</span>
                    </div>
                </form>

                <aside class="akdk-preview-col">
                    <div class="akdk-preview-sticky">
                        <div class="akdk-preview-head"><strong>Live preview</strong><span>Not saved until you click Save</span></div>
                        <div class="akdk-preview-stage<?php echo 'dark' === $o['launcher_mode'] ? ' akdk-stage-dark' : ''; ?>">
                            <?php echo WP_AI_Chatbot_Chat_Renderer::widget_markup( $o, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
                            <div class="akdk-preview-launcher<?php echo 'left' === $o['position'] ? ' wp-ai-pos-left' : ''; ?>" style="<?php echo esc_attr( WP_AI_Chatbot_Widget_Style::css_vars( $o ) ); ?>">
                                <div class="<?php echo esc_attr( WP_AI_Chatbot_Launcher_Icons::toggle_classes( $o ) ); ?>" style="<?php echo esc_attr( WP_AI_Chatbot_Launcher_Icons::css_vars( $o ) ); ?>" aria-hidden="true">
                                    <?php echo WP_AI_Chatbot_Launcher_Icons::toggle_inner( $o ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed plugin SVG; admin text escaped inside. ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </aside>
            </div>

            <script>var defaultSettings = <?php echo wp_json_encode( WP_AI_Chatbot_Widget_Style::defaults() ); ?>;</script>
        </div>
        <?php
    }
}
