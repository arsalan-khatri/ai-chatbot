<?php
/**
 * Main settings page: AI provider, API keys, models, system prompt and memory.
 *
 * @package AK_AI_Chatbot
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_AI_Chatbot_Admin_Settings {

    private $option_name = 'wp_ai_chatbot_settings';

    public function add_plugin_admin_menu() {
        add_menu_page( 'AI Chatbot Settings', 'AI Chatbot', 'manage_options', 'wp-ai-chatbot', array( $this, 'display_plugin_settings_page' ), WP_AI_Chatbot_Admin_UI::menu_icon(), 30 );
        add_submenu_page( 'wp-ai-chatbot', 'AI Settings', 'AI Settings', 'manage_options', 'wp-ai-chatbot', array( $this, 'display_plugin_settings_page' ) );
    }

    public function register_plugin_settings() {
        register_setting( 'wp_ai_chatbot_options_group', $this->option_name, array( $this, 'sanitize_settings' ) );
        add_action( 'wp_ajax_wp_ai_chatbot_test_connection', array( $this, 'ajax_test_connection' ) );
        add_action( 'wp_ajax_wp_ai_chatbot_refresh_models', array( $this, 'ajax_refresh_models' ) );
        add_action( 'admin_post_wp_ai_chatbot_create_chat_page', array( $this, 'create_chat_page' ) );
        // A new or changed key: fetch that provider's model list and pick the cheapest model.
        add_action( 'update_option_' . $this->option_name, array( 'WP_AI_Chatbot_Models', 'after_settings_saved' ), 10, 2 );
        add_action( 'add_option_' . $this->option_name, function ( $option, $value ) { WP_AI_Chatbot_Models::after_settings_saved( array(), $value ); }, 10, 2 );

        add_settings_section( 'wp_ai_chatbot_mode_section', 'Chatbot mode', function () { echo '<p>How should the bot know about your business? You can switch at any time; nothing is deleted.</p>'; }, 'wp-ai-chatbot-api' );
        add_settings_field( 'bot_mode', 'Mode', function () { WP_AI_Chatbot_Bot_Mode::render_field( $this->options() ); }, 'wp-ai-chatbot-api', 'wp_ai_chatbot_mode_section' );

        add_settings_section( 'wp_ai_chatbot_api_section', 'Connect your AI', array( $this, 'api_section_callback' ), 'wp-ai-chatbot-api' );
        add_settings_field( 'api_provider', 'AI provider', array( $this, 'render_provider_field' ), 'wp-ai-chatbot-api', 'wp_ai_chatbot_api_section' );
        add_settings_field( 'gemini_api_key', 'Gemini API key', array( $this, 'render_gemini_key_field' ), 'wp-ai-chatbot-api', 'wp_ai_chatbot_api_section', array( 'class' => 'akdk-provider-row akdk-provider-gemini akdk-gemini-key-row' ) );
        add_settings_field( 'gemini_model', 'Gemini model', array( $this, 'render_gemini_model_field' ), 'wp-ai-chatbot-api', 'wp_ai_chatbot_api_section', array( 'class' => 'akdk-provider-row akdk-provider-gemini' ) );
        add_settings_field( 'openai_api_key', 'OpenAI API key', array( $this, 'render_openai_key_field' ), 'wp-ai-chatbot-api', 'wp_ai_chatbot_api_section', array( 'class' => 'akdk-provider-row akdk-provider-openai akdk-openai-key-row' ) );
        add_settings_field( 'openai_model', 'OpenAI model', array( $this, 'render_openai_model_field' ), 'wp-ai-chatbot-api', 'wp_ai_chatbot_api_section', array( 'class' => 'akdk-provider-row akdk-provider-openai' ) );
        foreach ( self::extra_providers() as $p ) {
            $short = WP_AI_Chatbot_AI::provider_label( $p );
            add_settings_field( $p . '_api_key', $short . ' API key', array( $this, 'render_extra_key_field' ), 'wp-ai-chatbot-api', 'wp_ai_chatbot_api_section', array( 'class' => 'akdk-provider-row akdk-provider-' . $p, 'provider' => $p ) );
            add_settings_field( $p . '_model', $short . ' model', array( $this, 'render_extra_model_field' ), 'wp-ai-chatbot-api', 'wp_ai_chatbot_api_section', array( 'class' => 'akdk-provider-row akdk-provider-' . $p, 'provider' => $p ) );
        }
        add_settings_field( 'search_voice', 'Search &amp; voice', array( $this, 'render_search_voice_field' ), 'wp-ai-chatbot-api', 'wp_ai_chatbot_api_section', array( 'class' => 'akdk-sv-row' ) );

        add_settings_section( 'wp_ai_chatbot_behaviour_section', 'Chatbot behaviour', array( $this, 'behaviour_section_callback' ), 'wp-ai-chatbot-api' );
        add_settings_field( 'system_prompt', 'Instructions (system prompt)', array( $this, 'render_system_prompt_field' ), 'wp-ai-chatbot-api', 'wp_ai_chatbot_behaviour_section' );

        add_settings_section( 'wp_ai_chatbot_tokens_section', 'Token control', array( $this, 'tokens_section_callback' ), 'wp-ai-chatbot-api' );
        add_settings_field( 'token_saver', 'Token saver', array( $this, 'render_token_saver_field' ), 'wp-ai-chatbot-api', 'wp_ai_chatbot_tokens_section' );
        add_settings_field( 'token_knowledge', 'Knowledge per question', array( $this, 'render_token_knowledge_field' ), 'wp-ai-chatbot-api', 'wp_ai_chatbot_tokens_section' );
        add_settings_field( 'token_answer', 'Answer length', array( $this, 'render_token_answer_field' ), 'wp-ai-chatbot-api', 'wp_ai_chatbot_tokens_section' );
        add_settings_field( 'history_limit', 'Conversation memory', array( $this, 'render_history_limit_field' ), 'wp-ai-chatbot-api', 'wp_ai_chatbot_tokens_section' );
        add_settings_field( 'daily_token_limit', 'Daily token limit', array( $this, 'render_daily_limit_field' ), 'wp-ai-chatbot-api', 'wp_ai_chatbot_tokens_section' );
        add_settings_field( 'visitor_daily_messages', 'Messages per visitor', array( $this, 'render_visitor_messages_field' ), 'wp-ai-chatbot-api', 'wp_ai_chatbot_tokens_section' );
        add_settings_field( 'off_topic_block', 'Off-topic questions', array( $this, 'render_off_topic_field' ), 'wp-ai-chatbot-api', 'wp_ai_chatbot_tokens_section' );
        add_settings_field( 'voice_max_seconds', 'Voice message length', array( $this, 'render_voice_seconds_field' ), 'wp-ai-chatbot-api', 'wp_ai_chatbot_tokens_section' );

        add_settings_section( 'wp_ai_chatbot_data_section', 'Data', '__return_false', 'wp-ai-chatbot-api' );
        add_settings_field( 'delete_data_on_uninstall', 'When the plugin is deleted', array( $this, 'render_uninstall_field' ), 'wp-ai-chatbot-api', 'wp_ai_chatbot_data_section' );
    }

    public function sanitize_settings( $input ) {
        $sanitized = get_option( $this->option_name, array() );
        $sanitized = is_array( $sanitized ) ? $sanitized : array();
        $input     = is_array( $input ) ? $input : array();
        foreach ( array( 'api_provider', 'aux_provider', 'aux_enabled', 'bot_mode', 'token_knowledge', 'token_answer', 'off_topic_strictness' ) as $k ) {
            if ( isset( $input[ $k ] ) && ! is_string( $input[ $k ] ) ) {
                unset( $input[ $k ] );
            }
        }

        // "Other: type a model name" in the model chooser.
        foreach ( array_keys( WP_AI_Chatbot_AI::PROVIDERS ) as $p ) {
            if ( isset( $input[ $p . '_model' ] ) && '__custom__' === $input[ $p . '_model' ] ) {
                $input[ $p . '_model' ] = isset( $input[ $p . '_model_custom' ] ) ? $input[ $p . '_model_custom' ] : '';
            }
        }

        $sanitized = WP_AI_Chatbot_Bot_Mode::sanitize( $input, $sanitized );

        if ( isset( $input['api_provider'] ) ) {
            $sanitized['api_provider'] = isset( WP_AI_Chatbot_AI::PROVIDERS[ $input['api_provider'] ] ) ? $input['api_provider'] : 'gemini';
        }
        foreach ( self::extra_providers() as $p ) {
            if ( isset( $input[ $p . '_api_key' ] ) ) {
                $sanitized[ $p . '_api_key' ] = trim( sanitize_text_field( $input[ $p . '_api_key' ] ) );
            }
            if ( isset( $input[ $p . '_model' ] ) ) {
                $sanitized[ $p . '_model' ] = WP_AI_Chatbot_AI::clean_model_id( sanitize_text_field( $input[ $p . '_model' ] ) );
            }
        }
        // Search & voice (Claude, DeepSeek, Grok, Mistral): on/off and which key does it.
        if ( isset( $input['aux_enabled'] ) ) {
            $sanitized['aux_enabled'] = '1' === (string) $input['aux_enabled'] ? '1' : '0';
        }
        if ( isset( $input['aux_provider'] ) && in_array( $input['aux_provider'], array( 'gemini', 'openai' ), true ) ) {
            $sanitized['aux_provider'] = $input['aux_provider'];
        }
        foreach ( array( 'openai_api_key', 'gemini_api_key' ) as $key ) {
            if ( isset( $input[ $key ] ) ) {
                $sanitized[ $key ] = trim( sanitize_text_field( $input[ $key ] ) );
            }
        }
        foreach ( array( 'openai_model', 'gemini_model' ) as $key ) {
            if ( isset( $input[ $key ] ) ) {
                $sanitized[ $key ] = WP_AI_Chatbot_AI::clean_model_id( sanitize_text_field( $input[ $key ] ) );
            }
        }
        if ( ! empty( $sanitized['gemini_model'] ) && WP_AI_Chatbot_AI::is_retired_gemini_chat_model( $sanitized['gemini_model'] ) ) {
            add_settings_error(
                $this->option_name,
                'retired_model',
                sprintf( 'The Gemini model "%1$s" has been retired by Google, so "%2$s" will be used instead.', esc_html( $sanitized['gemini_model'] ), esc_html( WP_AI_Chatbot_AI::DEFAULT_GEMINI_CHAT ) ),
                'warning'
            );
            $sanitized['gemini_model'] = WP_AI_Chatbot_AI::DEFAULT_GEMINI_CHAT;
        }
        if ( isset( $input['system_prompt'] ) ) {
            $sanitized['system_prompt'] = sanitize_textarea_field( $input['system_prompt'] );
        }
        // The admin logo is fixed (AK Deep Knowledge); a logo saved by an older version is dropped.
        unset( $sanitized['admin_logo'] );
        if ( isset( $input['history_limit'] ) ) {
            $sanitized['history_limit'] = max( 0, min( 50, intval( $input['history_limit'] ) ) );
        }
        if ( isset( $input['token_knowledge'] ) ) {
            $sanitized['token_knowledge'] = isset( WP_AI_Chatbot_Token_Control::LEVELS[ $input['token_knowledge'] ] ) ? $input['token_knowledge'] : 'balanced';
        }
        if ( isset( $input['token_answer'] ) ) {
            $sanitized['token_answer'] = isset( WP_AI_Chatbot_Token_Control::ANSWERS[ $input['token_answer'] ] ) ? $input['token_answer'] : 'normal';
        }
        if ( isset( $input['daily_token_limit'] ) ) {
            $sanitized['daily_token_limit'] = max( 0, min( 100000000, intval( $input['daily_token_limit'] ) ) );
        }
        if ( isset( $input['token_limit_message'] ) ) {
            $sanitized['token_limit_message'] = sanitize_textarea_field( $input['token_limit_message'] );
        }
        if ( isset( $input['visitor_daily_messages'] ) ) {
            $sanitized['visitor_daily_messages'] = max( 0, min( 1000, intval( $input['visitor_daily_messages'] ) ) );
        }
        if ( isset( $input['off_topic_message'] ) ) {
            $msg = trim( sanitize_textarea_field( $input['off_topic_message'] ) );
            // Saving the default text unchanged keeps following future improvements of the default.
            $sanitized['off_topic_message'] = $msg === WP_AI_Chatbot_Token_Control::DEFAULT_OFF_TOPIC_MESSAGE ? '' : $msg;
        }
        if ( isset( $input['off_topic_strictness'] ) ) {
            $sanitized['off_topic_strictness'] = isset( WP_AI_Chatbot_Token_Control::STRICTNESS[ $input['off_topic_strictness'] ] ) ? $input['off_topic_strictness'] : 'normal';
        }
        if ( isset( $input['off_topic_allow'] ) ) {
            $sanitized['off_topic_allow'] = implode( ', ', array_filter( array_map( 'trim', preg_split( '/[,\n]+/u', sanitize_textarea_field( $input['off_topic_allow'] ) ) ) ) );
        }
        if ( isset( $input['voice_max_seconds'] ) ) {
            $sanitized['voice_max_seconds'] = max( 5, min( 60, intval( $input['voice_max_seconds'] ) ) );
        }
        // This option group is only saved from this page, so a missing checkbox means "unchecked".
        if ( isset( $input['system_prompt'] ) ) {
            $sanitized['delete_data_on_uninstall'] = ! empty( $input['delete_data_on_uninstall'] ) ? '1' : '0';
            $sanitized['token_saver']              = ! empty( $input['token_saver'] ) ? '1' : '0';
            $sanitized['off_topic_block']          = ! empty( $input['off_topic_block'] ) ? '1' : '0';
        }
        return $sanitized;
    }

    public function enqueue_admin_assets( $hook ) {
        // Styles come from admin/css/akdk-admin.css (loaded on every plugin screen).
    }

    private function options() {
        $options = get_option( $this->option_name, array() );
        return is_array( $options ) ? $options : array();
    }

    public function api_section_callback() {
        echo '<p>Choose a provider and paste its API key. Leave the model empty to use the recommended one.</p>';
    }

    public function behaviour_section_callback() {
        echo '<p class="akdk-smart-info">Tell the bot who it works for and how it should talk. Facts (prices, services, links) come from <a href="' . esc_url( admin_url( 'admin.php?page=wp-ai-chatbot-rag' ) ) . '">Train Chatbot</a>.</p>';
        echo '<p class="akdk-basic-only" hidden>Tell the bot how it should talk (tone, language, what to suggest). Facts go in the business details under <em>Chatbot mode</em>.</p>';
    }

    /** Providers that only write answers (no embeddings, no voice). */
    public static function extra_providers() {
        return array_values( array_diff( array_keys( WP_AI_Chatbot_AI::PROVIDERS ), array( 'gemini', 'openai' ) ) );
    }

    public function render_provider_field() {
        $provider = WP_AI_Chatbot_AI::chat_provider();
        echo '<select name="wp_ai_chatbot_settings[api_provider]" id="akdk-provider">';
        foreach ( WP_AI_Chatbot_AI::PROVIDERS as $key => $info ) {
            echo '<option value="' . esc_attr( $key ) . '" ' . selected( $provider, $key, false ) . '>' . esc_html( $info['label'] . ( 'gemini' === $key ? ' (recommended, free key)' : '' ) ) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">The AI that writes the chatbot\'s answers.</p>';
    }

    public function render_extra_key_field( $args ) {
        $p       = $args['provider'];
        $options = $this->options();
        $key     = $p . '_api_key';
        echo '<input type="password" name="wp_ai_chatbot_settings[' . esc_attr( $key ) . ']" value="' . esc_attr( isset( $options[ $key ] ) ? $options[ $key ] : '' ) . '" class="regular-text" autocomplete="off" />';
        echo '<p class="description"><a href="' . esc_url( WP_AI_Chatbot_AI::PROVIDERS[ $p ]['keys'] ) . '" target="_blank" rel="noopener noreferrer">Get a ' . esc_html( WP_AI_Chatbot_AI::provider_label( $p ) ) . ' API key &#8599;</a>, copy it and paste it here. Most providers need a little prepaid credit on the account.</p>';
    }

    public function render_extra_model_field( $args ) {
        $this->render_model_select( $args['provider'] );
    }

    /**
     * Model chooser: "Automatic" (cheapest good model, the default), every chat model the key
     * can use, or a name typed by hand.
     */
    private function render_model_select( $p ) {
        $options = $this->options();
        $name    = 'wp_ai_chatbot_settings[' . $p . '_model]';
        $saved   = isset( $options[ $p . '_model' ] ) ? (string) $options[ $p . '_model' ] : '';
        $cat     = WP_AI_Chatbot_Models::get( $p );
        $auto    = WP_AI_Chatbot_Models::auto( $p );
        $has_key = '' !== WP_AI_Chatbot_AI::api_key( $p );
        $models  = $has_key ? (array) $cat['models'] : array();
        $custom  = '' !== $saved && ! in_array( $saved, $models, true );
        $fallback = WP_AI_Chatbot_AI::default_chat_model( $p );

        // The model choice only appears once a working key is saved. Until then nothing is sent,
        // so a model saved earlier stays as it is.
        if ( ! WP_AI_Chatbot_Models::key_ok( $p ) ) {
            $label = WP_AI_Chatbot_AI::provider_label( $p );
            echo '<div class="akdk-model-picker akdk-model-locked" data-provider="' . esc_attr( $p ) . '"><p class="description">';
            if ( ! $has_key ) {
                echo '&#128274; Add your ' . esc_html( $label ) . ' API key above and click <strong>Save settings</strong>. The key is checked, and then the models it can use appear here (the cheapest one is picked for you).';
            } elseif ( '' !== $cat['error'] && $cat['key'] === substr( md5( WP_AI_Chatbot_AI::api_key( $p ) ), 0, 12 ) ) {
                echo '<span style="color:#b91c1c;"><strong>This key did not work:</strong> ' . esc_html( $cat['error'] ) . '</span><br>Check the key, paste it again and click <strong>Save settings</strong>. ';
                echo '<button type="button" class="button-link akdk-refresh-models" data-provider="' . esc_attr( $p ) . '">Check again</button>';
            } else {
                echo 'The key has not been checked yet. ';
                echo '<button type="button" class="button button-small akdk-refresh-models" data-provider="' . esc_attr( $p ) . '">Check key and load models</button>';
            }
            echo '</p></div>';
            return;
        }

        echo '<div class="akdk-model-picker" data-provider="' . esc_attr( $p ) . '">';
        echo '<select name="' . esc_attr( $name ) . '" class="akdk-model-select" style="min-width:22em;max-width:100%;">';
        echo '<option value="" ' . selected( $saved, '', false ) . '>' . esc_html( 'Automatic (cheapest): ' . ( '' !== $auto ? $auto : $fallback ) ) . '</option>';
        foreach ( $models as $m ) {
            echo '<option value="' . esc_attr( $m ) . '" ' . selected( $saved, $m, false ) . '>' . esc_html( $m . ( $m === $auto ? '  (cheapest)' : '' ) ) . '</option>';
        }
        echo '<option value="__custom__" ' . selected( $custom, true, false ) . '>Other: type a model name…</option>';
        echo '</select> ';
        echo '<input type="text" name="wp_ai_chatbot_settings[' . esc_attr( $p ) . '_model_custom]" class="akdk-model-custom regular-text" value="' . esc_attr( $custom ? $saved : '' ) . '" placeholder="exact model name" ' . ( $custom ? '' : 'hidden' ) . '>';
        echo '<p class="description">';
        if ( ! $has_key ) {
            echo 'Save your API key first: the list of models this key can use appears here, and the cheapest good one is chosen automatically.';
        } elseif ( '' !== $cat['error'] ) {
            echo '<span style="color:#b91c1c;">Could not load the model list: ' . esc_html( $cat['error'] ) . '</span> ';
        } elseif ( $models ) {
            echo esc_html( count( $models ) . ' models available for this key' . ( $cat['at'] ? ', checked ' . human_time_diff( (int) $cat['at'] ) . ' ago' : '' ) . '. ' );
        }
        if ( $has_key ) {
            echo '<button type="button" class="button-link akdk-refresh-models" data-provider="' . esc_attr( $p ) . '">Refresh list</button>';
        }
        echo '<br>In use now: <code>' . esc_html( WP_AI_Chatbot_AI::chat_model( $p ) ) . '</code>. Automatic keeps following the cheapest model when the list is refreshed (once a week, or when the key changes).</p>';
        echo '</div>';
    }

    /** AJAX: fetch the model list for one provider again. */
    public function ajax_refresh_models() {
        check_ajax_referer( 'wp_ai_chatbot_test_connection', 'security' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Not allowed.', 403 );
        }
        $p = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
        if ( ! isset( WP_AI_Chatbot_AI::PROVIDERS[ $p ] ) ) {
            wp_send_json_error( 'Unknown provider.' );
        }
        $c = WP_AI_Chatbot_Models::refresh( $p );
        if ( '' !== $c['error'] ) {
            wp_send_json_error( $c['error'] );
        }
        wp_send_json_success( array( 'count' => count( $c['models'] ), 'auto' => $c['auto'] ) );
    }

    public function render_openai_key_field() {
        $options = $this->options();
        echo '<input type="password" name="wp_ai_chatbot_settings[openai_api_key]" value="' . esc_attr( isset( $options['openai_api_key'] ) ? $options['openai_api_key'] : '' ) . '" class="regular-text" autocomplete="off" />';
        echo '<p class="description akdk-when-extra" style="display:none;"><strong>Only needed if you want OpenAI to do the search</strong> (instead of Gemini). Leave empty to use the Gemini key above.</p>';
        echo '<p class="description"><a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener noreferrer">Open OpenAI API keys &#8599;</a>, click <strong>Create new secret key</strong>, copy it and paste it here. OpenAI needs a little prepaid credit on the account.</p>';
    }

    public function render_openai_model_field() {
        $this->render_model_select( 'openai' );
    }

    public function render_gemini_key_field() {
        $options = $this->options();
        echo '<input type="password" name="wp_ai_chatbot_settings[gemini_api_key]" value="' . esc_attr( isset( $options['gemini_api_key'] ) ? $options['gemini_api_key'] : '' ) . '" class="regular-text" autocomplete="off" />';
        $search = WP_AI_Chatbot_AI::embedding_provider();
        echo '<div class="akdk-key-why akdk-when-extra" style="display:none;">';
        echo '<p><strong>Why is this key needed?</strong> <span class="akdk-prov-name">This provider</span> writes the answers, but it cannot do two other jobs of the chatbot:</p>';
        echo '<ol><li><strong>Search your website and PDFs</strong> to find the right facts before answering. This needs an "embedding" model, which <span class="akdk-prov-name">this provider</span> does not have.</li>';
        echo '<li><strong>Voice input:</strong> turning a visitor\'s voice message into text.</li></ol>';
        echo '<p>Gemini does both, and its key is <strong>free</strong>. You are only charged by <span class="akdk-prov-name">this provider</span> for the answers.</p>';
        echo '<p><strong>Prefer OpenAI?</strong> An OpenAI key can do the search instead: paste it in the OpenAI box below and choose <em>OpenAI</em> under <a href="' . esc_url( admin_url( 'admin.php?page=wp-ai-chatbot-rag' ) ) . '">Train Chatbot</a> → Embedding Configuration → Active Provider. Voice still needs Gemini; without a Gemini key the visitor\'s browser is used for voice (works best in English).</p>';
        echo '<p class="akdk-key-now">Search uses now: <strong>' . esc_html( 'openai' === $search ? 'OpenAI' : 'Gemini' ) . '</strong>' . ( 'openai' === $search ? ' (so here Gemini is only needed for voice)' : '' ) . '.</p>';
        echo '</div>';
        echo '<p class="description"><strong>Free key in 1 minute:</strong> <a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener noreferrer">open Google AI Studio &#8599;</a>, sign in with any Google account, click <strong>Create API key</strong>, copy it and paste it here. Then click <em>Save settings</em> and <em>Test connection</em>.</p>';
    }

    /**
     * One box for the search & voice key when Claude, DeepSeek, Grok or Mistral writes the answers.
     * A saved Gemini (or OpenAI) key is shown as it is; with no key the owner picks one.
     * Turned off, the box fades and search and voice stop.
     */
    public function render_search_voice_field() {
        $o       = $this->options();
        $on      = ! isset( $o['aux_enabled'] ) || '0' !== (string) $o['aux_enabled'];
        $gem     = isset( $o['gemini_api_key'] ) ? (string) $o['gemini_api_key'] : '';
        $oai     = isset( $o['openai_api_key'] ) ? (string) $o['openai_api_key'] : '';
        $pref    = isset( $o['aux_provider'] ) && in_array( $o['aux_provider'], array( 'gemini', 'openai' ), true ) ? $o['aux_provider'] : '';
        $choice  = $pref;
        if ( '' === $choice || ( 'gemini' === $choice && '' === $gem && '' !== $oai ) || ( 'openai' === $choice && '' === $oai && '' !== $gem ) ) {
            $choice = '' !== $gem ? 'gemini' : ( '' !== $oai ? 'openai' : 'gemini' );
        }
        $has_key = ( 'gemini' === $choice && '' !== $gem ) || ( 'openai' === $choice && '' !== $oai );
        ?>
        <div class="akdk-sv" data-has-key="<?php echo $has_key ? '1' : '0'; ?>">
            <input type="hidden" name="wp_ai_chatbot_settings[aux_enabled]" value="0" class="akdk-sv-keep" />
            <label class="akdk-sv-switch"><input type="checkbox" name="wp_ai_chatbot_settings[aux_enabled]" value="1" class="akdk-sv-toggle akdk-sv-keep" <?php checked( $on ); ?> /> <strong>Use website search &amp; voice</strong></label>
            <p class="description"><span class="akdk-prov-name">This provider</span> writes the answers. Searching your pages, PDFs and products before answering, and turning voice messages into text, need a separate <strong>Gemini (free)</strong> or <strong>OpenAI</strong> key. Turn this off if you do not want to add one: the bot then answers only from your instructions, and the microphone is hidden.</p>
            <div class="akdk-sv-body">
                <div class="akdk-sv-choice"<?php echo $has_key ? ' hidden' : ''; ?>>
                    <label><input type="radio" name="wp_ai_chatbot_settings[aux_provider]" value="gemini" <?php checked( 'gemini', $choice ); ?> /> Gemini <em>(free key, search + voice)</em></label>
                    &nbsp;&nbsp;<label><input type="radio" name="wp_ai_chatbot_settings[aux_provider]" value="openai" <?php checked( 'openai', $choice ); ?> /> OpenAI <em>(paid, search; voice uses the visitor's browser)</em></label>
                </div>
                <?php if ( $has_key ) : ?>
                    <input type="hidden" name="wp_ai_chatbot_settings[aux_provider]" value="<?php echo esc_attr( $choice ); ?>" class="akdk-sv-fixed" />
                <?php endif; ?>
                <div class="akdk-sv-key" data-for="gemini"<?php echo 'gemini' === $choice ? '' : ' hidden'; ?>>
                    <label><strong>Gemini API key</strong><br /><input type="password" name="wp_ai_chatbot_settings[gemini_api_key]" value="<?php echo esc_attr( $gem ); ?>" class="regular-text" autocomplete="off" /></label>
                    <p class="description"><a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener noreferrer">Get a free key in Google AI Studio &#8599;</a>, click <strong>Create API key</strong>, paste it here.</p>
                </div>
                <div class="akdk-sv-key" data-for="openai"<?php echo 'openai' === $choice ? '' : ' hidden'; ?>>
                    <label><strong>OpenAI API key</strong><br /><input type="password" name="wp_ai_chatbot_settings[openai_api_key]" value="<?php echo esc_attr( $oai ); ?>" class="regular-text" autocomplete="off" /></label>
                    <p class="description"><a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener noreferrer">Open OpenAI API keys &#8599;</a>, click <strong>Create new secret key</strong>, paste it here. Voice then uses the visitor's browser (works best in English).</p>
                </div>
                <?php if ( $has_key ) : ?>
                    <p class="description"><a href="#" class="akdk-sv-change">Use <?php echo 'gemini' === $choice ? 'an OpenAI' : 'a Gemini'; ?> key instead</a></p>
                <?php endif; ?>
            </div>
            <p class="description akdk-basic-only" hidden><strong>Simple mode:</strong> no website search is used, so this key is only for voice (Gemini). Without it, the visitor's browser does voice.</p>
            <p class="akdk-sv-off-note"><strong>Off:</strong> website search and voice are turned off. Your saved key (if any) is kept and is used again when you turn this on.</p>
        </div>
        <?php
    }

    public function render_gemini_model_field() {
        $this->render_model_select( 'gemini' );
    }

    public function render_system_prompt_field() {
        $options = $this->options();
        echo '<textarea name="wp_ai_chatbot_settings[system_prompt]" rows="6" class="large-text code">' . esc_textarea( isset( $options['system_prompt'] ) ? $options['system_prompt'] : '' ) . '</textarea>';
        echo '<p class="description">Describe your business, tone and what the bot should help with. Factual answers come from your knowledge base (Train Chatbot). The bot is instructed to use only that content, and links, contact details or prices it cannot find there are removed or marked "please confirm".</p>';
    }

    public function render_history_limit_field() {
        $options = $this->options();
        $limit   = isset( $options['history_limit'] ) ? intval( $options['history_limit'] ) : 6;
        echo '<input type="number" name="wp_ai_chatbot_settings[history_limit]" value="' . esc_attr( $limit ) . '" min="0" max="50" style="width:100px;" /> previous messages';
        echo '<p class="description">Number of previous messages sent to the AI (sliding window, not permanent memory). 0 disables history. Submitted pre-chat contact details are stored separately for the current chat session.</p>';
    }

    public function render_token_saver_field() {
        $on = WP_AI_Chatbot_AI::token_saver();
        echo '<label><input type="checkbox" name="wp_ai_chatbot_settings[token_saver]" value="1" ' . checked( $on, true, false ) . ' /> Send the AI only the knowledge it needs and ask for concise answers (recommended)</label>';
        echo '<p class="description">Picks the most relevant passages within a size budget, lists items 10 at a time ("show more" for the next ones), trims old replies in the conversation and asks for short answers to simple questions. Untick to go back to the previous behaviour (up to 8 full records, 40 for lists).</p>';
    }

    public function tokens_section_callback() {
        $used  = WP_AI_Chatbot_Token_Control::used_today();
        $limit = WP_AI_Chatbot_Token_Control::daily_limit();
        echo '<p>Decide how many tokens the chatbot may use. Fewer tokens = lower API bill; too few can make answers miss details. Start with <strong>Balanced</strong> and <strong>Normal</strong>, then check the dashboard.</p>';
        echo '<p><strong>Used today:</strong> ' . esc_html( number_format_i18n( $used ) ) . ' tokens'
            . ( $limit > 0 ? ' of ' . esc_html( number_format_i18n( $limit ) ) . ' (' . esc_html( (string) min( 100, (int) round( $used * 100 / $limit ) ) ) . '%)' : ' (no daily limit set)' )
            . '. Visitor chat and voice, counted since midnight.</p>';
    }

    private function select( $name, $value, $choices ) {
        echo '<select name="wp_ai_chatbot_settings[' . esc_attr( $name ) . ']">';
        foreach ( $choices as $key => $label ) {
            echo '<option value="' . esc_attr( $key ) . '" ' . selected( $value, $key, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
    }

    public function render_token_knowledge_field() {
        $base = 1700;
        $this->select( 'token_knowledge', WP_AI_Chatbot_Token_Control::knowledge_level(), array(
            'economy'  => 'Economy (about ' . round( $base * 0.6 ) . ' tokens per question)',
            'balanced' => 'Balanced (about ' . $base . ' tokens per question, recommended)',
            'detailed' => 'Detailed (about ' . round( $base * 1.5 ) . ' tokens per question)',
        ) );
        echo '<p class="description">How much of your knowledge base (pages, PDFs, products) is sent with each question. Economy suits a small site with short answers; Detailed suits big catalogues and long documents. Needs the token saver.</p>';
    }

    public function render_token_answer_field() {
        $this->select( 'token_answer', WP_AI_Chatbot_Token_Control::answer_level(), array(
            'short'    => 'Short (up to about 420 tokens)',
            'normal'   => 'Normal (up to about 700 tokens, recommended)',
            'detailed' => 'Detailed (up to about 1,050 tokens)',
        ) );
        echo '<p class="description">Longest answer to a simple question. Lists and comparisons get twice as much. Output tokens cost more than input tokens, so Short saves the most per answer. Needs the token saver.</p>';
    }

    public function render_daily_limit_field() {
        $options = $this->options();
        $limit   = WP_AI_Chatbot_Token_Control::daily_limit();
        $msg     = isset( $options['token_limit_message'] ) ? $options['token_limit_message'] : '';
        echo '<input type="number" name="wp_ai_chatbot_settings[daily_token_limit]" value="' . esc_attr( $limit ) . '" min="0" step="1000" style="width:140px;" /> tokens per day (0 = no limit)';
        echo '<p class="description">A safety cap for the whole site. When visitors use this many tokens in a day, the bot stops calling the AI until midnight and shows the message below instead (no tokens). Example: 200000 is roughly 100 to 120 answers.</p>';
        echo '<p style="margin-top:8px;"><label>Message shown when the limit is reached:<br><textarea name="wp_ai_chatbot_settings[token_limit_message]" rows="2" class="large-text" placeholder="' . esc_attr( WP_AI_Chatbot_Token_Control::DEFAULT_LIMIT_MESSAGE ) . '">' . esc_textarea( $msg ) . '</textarea></label></p>';
    }

    public function render_visitor_messages_field() {
        $value = WP_AI_Chatbot_Token_Control::visitor_daily_messages();
        echo '<input type="number" name="wp_ai_chatbot_settings[visitor_daily_messages]" value="' . esc_attr( $value ) . '" min="0" max="1000" style="width:100px;" /> messages per visitor per day (0 = no limit)';
        echo '<p class="description">Stops one person (or a bot) from using up your tokens. 30 to 50 is plenty for a real customer.</p>';
    }

    public function render_off_topic_field() {
        $options = $this->options();
        $on      = WP_AI_Chatbot_Token_Control::off_topic_on();
        $allow   = isset( $options['off_topic_allow'] ) ? $options['off_topic_allow'] : WP_AI_Chatbot_Token_Control::DEFAULT_ALLOW_WORDS;
        echo '<label><input type="checkbox" name="wp_ai_chatbot_settings[off_topic_block]" value="1" ' . checked( $on, true, false ) . ' /> Answer questions that have nothing to do with this website with a fixed message (no AI call, no tokens)</label>';
        echo '<p class="description akdk-basic-only" hidden><strong>Simple mode:</strong> there is no search to check questions against, so the AI itself replies with your message to unrelated questions. Strictness and allowed words are not used.</p>';
        echo '<p class="description">When a question matches nothing in your knowledge base (jokes, cricket, homework, "who made you"), the visitor gets the message below instead of an AI answer. Greetings, follow-up questions, your form names and the words in the list always go to the AI, so real customers are not turned away. Needs the token saver and a trained knowledge base.</p>';
        echo '<p style="margin-top:8px;"><label><strong>How strict</strong> ';
        $this->select( 'off_topic_strictness', WP_AI_Chatbot_Token_Control::off_topic_strictness(), array(
            'relaxed' => 'Relaxed (only clearly unrelated questions get the message)',
            'normal'  => 'Normal (recommended)',
            'strict'  => 'Strict (more questions get the message; check that real questions still reach the AI)',
        ) );
        echo '</label></p>';
        $cal = get_option( 'wp_ai_chatbot_offtopic_base' );
        if ( is_array( $cal ) && isset( $cal['base'] ) ) {
            echo '<p class="description">Measured on your knowledge base: off-topic examples (jokes, cricket, weather) match about ' . esc_html( number_format( (float) $cal['base'], 2 ) ) . '. Questions must match better than that to reach the AI. Measured again automatically when your content changes.</p>';
        }
        echo '<p style="margin-top:8px;"><label><strong>Message for off-topic questions</strong> (edit as you like):<br><textarea name="wp_ai_chatbot_settings[off_topic_message]" rows="4" class="large-text">' . esc_textarea( WP_AI_Chatbot_Token_Control::off_topic_message() ) . '</textarea></label></p>';
        echo '<p><label><strong>Always send to the AI</strong> when the question contains one of these words (comma separated):<br><textarea name="wp_ai_chatbot_settings[off_topic_allow]" rows="3" class="large-text code">' . esc_textarea( $allow ) . '</textarea></label></p>';
        echo '<p class="description">Add words your customers use, for example your product names or city. Empty the box to rely on the knowledge base only.</p>';
    }

    public function render_voice_seconds_field() {
        $value = WP_AI_Chatbot_Token_Control::voice_max_seconds();
        echo '<input type="number" name="wp_ai_chatbot_settings[voice_max_seconds]" value="' . esc_attr( $value ) . '" min="5" max="60" style="width:100px;" /> seconds at most';
        echo '<p class="description">Voice costs about 32 tokens per second of speech. Recording stops by itself when the visitor stops talking; this is only the upper limit. 20 to 30 seconds is enough for a question.</p>';
    }

    public function render_uninstall_field() {
        $options = $this->options();
        $checked = isset( $options['delete_data_on_uninstall'] ) && '1' === $options['delete_data_on_uninstall'];
        echo '<label><input type="checkbox" name="wp_ai_chatbot_settings[delete_data_on_uninstall]" value="1" ' . checked( $checked, true, false ) . ' /> Delete all chatbot data (settings, chat logs, leads and knowledge base) when the plugin is deleted</label>';
        echo '<p class="description">Leave unchecked if you plan to reinstall or update the plugin manually, your data will be kept.</p>';
    }

    /**
     * AJAX: sends a tiny test message to the selected provider.
     */
    public function ajax_test_connection() {
        check_ajax_referer( 'wp_ai_chatbot_test_connection', 'security' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Not allowed.', 403 );
        }

        $provider = WP_AI_Chatbot_AI::chat_provider();
        if ( '' === WP_AI_Chatbot_AI::api_key( $provider ) ) {
            wp_send_json_error( 'No API key saved for ' . WP_AI_Chatbot_AI::provider_label( $provider ) . '. Save your key first.' );
        }

        $reply = WP_AI_Chatbot_AI::chat(
            'You are a connection test. Reply with the single word OK.',
            array( array( 'role' => 'user', 'content' => 'Test' ) ),
            array( 'timeout' => 20 )
        );
        if ( is_wp_error( $reply ) ) {
            wp_send_json_error( $reply->get_error_message() );
        }

        // Simple mode, or search turned off: there is no search to test.
        if ( WP_AI_Chatbot_Bot_Mode::is_basic() ) {
            wp_send_json_success( 'Chat OK (' . WP_AI_Chatbot_AI::chat_model() . '). Simple mode: website search is not used.' );
        }
        if ( ! WP_AI_Chatbot_AI::search_voice_on() ) {
            wp_send_json_success( 'Chat OK (' . WP_AI_Chatbot_AI::chat_model() . '). Website search & voice are turned off.' );
        }
        // Also test the embedding model used by the knowledge base.
        $vector    = WP_AI_Chatbot_AI::embed( 'connection test', 'query' );
        $embed_msg = is_wp_error( $vector )
            ? 'Embeddings FAILED (' . WP_AI_Chatbot_AI::embedding_model() . '): ' . $vector->get_error_message()
            : 'Embeddings OK (' . WP_AI_Chatbot_AI::embedding_model() . ', ' . count( $vector ) . ' dimensions).';

        wp_send_json_success( 'Chat OK (' . WP_AI_Chatbot_AI::chat_model() . '). ' . $embed_msg );
    }

    /** The chat page made with "Create chat page" (0 when there is none, or it was deleted). */
    public static function chat_page_id() {
        $id   = (int) get_option( 'wp_ai_chatbot_chat_page', 0 );
        $post = $id ? get_post( $id ) : null;
        return ( $post && 'page' === $post->post_type && 'trash' !== $post->post_status ) ? $id : 0;
    }

    /** admin-post: makes a published page that shows the chat ([akdk_chatbot]) and opens it in the editor. */
    public function create_chat_page() {
        if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'publish_pages' ) ) {
            wp_die( 'Not allowed.' );
        }
        check_admin_referer( 'wp_ai_chatbot_create_chat_page' );
        $id = self::chat_page_id();
        if ( ! $id ) {
            $id = wp_insert_post(
                array(
                    'post_type'    => 'page',
                    'post_status'  => 'publish',
                    'post_title'   => 'Chat with us',
                    'post_name'    => 'chat',
                    'post_content' => "<!-- wp:shortcode -->\n[akdk_chatbot height=\"75vh\" width=\"900\"]\n<!-- /wp:shortcode -->",
                ),
                true
            );
            if ( is_wp_error( $id ) ) {
                wp_die( esc_html( 'The page could not be created: ' . $id->get_error_message() ) );
            }
            update_option( 'wp_ai_chatbot_chat_page', (int) $id, false );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=wp-ai-chatbot&chat_page=' . (int) $id ) );
        exit;
    }

    /** Side card: the shortcode, a copy button and "Create chat page". */
    private function render_chat_page_card() {
        $page_id = self::chat_page_id();
        ?>
        <div class="akdk-card akdk-chatpage-card">
            <h2>Chat page</h2>
            <p>Show the chatbot inside a page, for example a full <em>Chat with us</em> page. Paste this shortcode into any page or post (Gutenberg <em>Shortcode</em> block, Elementor <em>Shortcode</em> widget, or the classic editor):</p>
            <p class="akdk-sc-row"><code class="akdk-sc-code">[akdk_chatbot]</code> <button type="button" class="button button-small akdk-sc-copy" data-copy="[akdk_chatbot]">Copy</button></p>
            <?php if ( $page_id ) : ?>
                <p>Your chat page: <a href="<?php echo esc_url( get_permalink( $page_id ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_the_title( $page_id ) ); ?> &#8599;</a> &middot; <a href="<?php echo esc_url( get_edit_post_link( $page_id ) ); ?>">Edit</a></p>
            <?php elseif ( current_user_can( 'publish_pages' ) ) : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="wp_ai_chatbot_create_chat_page">
                    <?php wp_nonce_field( 'wp_ai_chatbot_create_chat_page' ); ?>
                    <p><button type="submit" class="button button-primary">Create chat page</button></p>
                </form>
            <?php endif; ?>
            <details class="akdk-sc-options">
                <summary>Options</summary>
                <ul>
                    <li><code>height="700"</code> height in pixels, or <code>height="80vh"</code> for most of the screen (default 640)</li>
                    <li><code>width="900"</code> largest width in pixels (default: the full content width)</li>
                    <li><code>title="Support"</code> and <code>subtitle="We reply in seconds"</code> change the header</li>
                    <li><code>header="no"</code> hides the header bar</li>
                    <li><code>welcome="Assalam o Alaikum! How can I help?"</code> first message of a new chat</li>
                </ul>
                <p>Example: <code>[akdk_chatbot height="80vh" width="900" welcome="Hi! Ask me anything."]</code></p>
                <p>It uses the same knowledge, forms, quick actions, voice and look as the floating chat, and keeps the same conversation. On that page the floating chat button is hidden. One chat per page.</p>
            </details>
        </div>
        <?php
    }

    public function display_plugin_settings_page() {
        ?>
        <div class="wrap akdk-wrap">
            <h1 class="akdk-page-title">AI Settings</h1>
            <p class="akdk-page-intro">Step 1: connect the AI that writes your chatbot's answers.</p>
            <?php settings_errors(); ?>
            <?php if ( isset( $_GET['chat_page'] ) && self::chat_page_id() === (int) $_GET['chat_page'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="notice notice-success is-dismissible"><p><strong>Chat page is ready.</strong> <a href="<?php echo esc_url( get_permalink( self::chat_page_id() ) ); ?>" target="_blank" rel="noopener">View it &#8599;</a> &middot; <a href="<?php echo esc_url( get_edit_post_link( self::chat_page_id() ) ); ?>">Edit the page</a> (add it to your menu under Appearance &rarr; Menus).</p></div>
            <?php endif; ?>

            <div class="akdk-two-col">
                <form method="post" action="options.php" class="akdk-settings-form">
                    <?php
                    settings_fields( 'wp_ai_chatbot_options_group' );
                    do_settings_sections( 'wp-ai-chatbot-api' );
                    ?>
                    <div class="akdk-savebar"><?php submit_button( 'Save settings', 'primary', 'submit', false ); ?></div>
                </form>

                <aside class="akdk-side">
                    <div class="akdk-card">
                        <h2>Test connection</h2>
                        <p>Checks the saved key, chat model and embedding model. Save first.</p>
                        <p>
                            <button type="button" class="button button-primary" id="wp-ai-test-connection">Test connection</button>
                            <span class="spinner" id="wp-ai-test-spinner" style="float:none;"></span>
                        </p>
                        <div id="wp-ai-test-result"></div>
                    </div>
                    <?php $this->render_chat_page_card(); ?>
                    <div class="akdk-card akdk-card-muted">
                        <h2>Which provider?</h2>
                        <p><strong>Gemini</strong>: has a free tier, good for most sites. <a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener noreferrer">Get a free key &#8599;</a></p>
                        <p><strong>OpenAI</strong>: paid, very consistent answers. <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener noreferrer">Get a key &#8599;</a></p>
                        <p><strong>Claude</strong>: paid, very good at following instructions and writing naturally.</p>
                        <p><strong>DeepSeek</strong>: paid, very low price.</p>
                        <p><strong>Grok, Mistral</strong>: paid alternatives.</p>
                        <p>With Claude, DeepSeek, Grok or Mistral, website search and voice need a Gemini (free) or OpenAI key, or you can turn them off under <em>Search &amp; voice</em>.</p>
                        <p>After saving, go to <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-ai-chatbot-rag' ) ); ?>">Train Chatbot</a> so the bot learns your content.</p>
                    </div>
                </aside>
            </div>

            <script>
            jQuery(function($) {
                function syncProvider() {
                    var p = $('#akdk-provider').val();
                    var extra = p !== 'gemini' && p !== 'openai';
                    $('.akdk-provider-row').hide();
                    $('.akdk-provider-' + p).show();
                    var name = $('#akdk-provider option:selected').text().replace(/^.*\((.*)\).*$/, '$1');
                    $('.akdk-prov-name').text(name);
                    $('.akdk-when-extra').hide();
                    $('.akdk-when-main').toggle(!extra);
                    // Claude, DeepSeek, Grok, Mistral: one "Search & voice" box instead of the Gemini/OpenAI key rows.
                    // Only one set of key boxes is sent, so the same key is never saved twice.
                    $('.akdk-sv-row').toggle(extra);
                    $('.akdk-gemini-key-row input, .akdk-openai-key-row input').prop('disabled', extra);
                    $('.akdk-sv .akdk-sv-keep').prop('disabled', !extra);
                    syncSearchVoice();
                }
                function syncSearchVoice() {
                    var $sv = $('.akdk-sv');
                    var extra = $('.akdk-sv-row').is(':visible');
                    var on = $sv.find('.akdk-sv-toggle').is(':checked');
                    var picking = !$sv.find('.akdk-sv-choice').prop('hidden');
                    var choice = picking ? ($sv.find('.akdk-sv-choice input:checked').val() || 'gemini') : $sv.find('.akdk-sv-fixed').val();
                    $sv.toggleClass('is-off', !on);
                    $sv.find('.akdk-sv-key').each(function () { $(this).prop('hidden', $(this).data('for') !== choice); });
                    // Off (or another key shown): boxes are disabled, so nothing is sent and saved keys stay as they are.
                    $sv.find('.akdk-sv-body input').prop('disabled', true);
                    if (extra && on) {
                        $sv.find('.akdk-sv-key[data-for="' + choice + '"] input').prop('disabled', false);
                        $sv.find(picking ? '.akdk-sv-choice input' : '.akdk-sv-fixed').prop('disabled', false);
                    }
                }
                $(document).on('change', '.akdk-sv-toggle, .akdk-sv-choice input', syncSearchVoice);
                $(document).on('click', '.akdk-sv-change', function (e) {
                    e.preventDefault();
                    var cur = $('.akdk-sv-fixed').val();
                    $('.akdk-sv-fixed').remove();
                    $('.akdk-sv-choice').prop('hidden', false).find('input[value="' + (cur === 'gemini' ? 'openai' : 'gemini') + '"]').prop('checked', true);
                    $(this).closest('p').remove();
                    syncSearchVoice();
                });
                $('#akdk-provider').on('change', syncProvider);
                syncProvider();

                $(document).on('click', '.akdk-sc-copy', function () {
                    var $b = $(this), text = String($b.data('copy'));
                    var done = function () { $b.text('Copied'); setTimeout(function () { $b.text('Copy'); }, 1500); };
                    if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(done, function () {}); return; }
                    var $t = $('<textarea>').val(text).css({ position: 'fixed', opacity: 0 }).appendTo('body');
                    $t[0].select(); try { document.execCommand('copy'); done(); } catch (e) {} $t.remove();
                });

                // Chatbot mode: Smart (website search) or Simple (business details only).
                function syncMode() {
                    var basic = $('input[name="wp_ai_chatbot_settings[bot_mode]"]:checked').val() === 'basic';
                    $('.akdk-basic-info, .akdk-basic-only').prop('hidden', !basic);
                    $('.akdk-smart-info').prop('hidden', basic);
                    $('.akdk-mode-card').removeClass('is-active').has('input:checked').addClass('is-active');
                    syncSize();
                }
                function syncSize() {
                    var chars = 0;
                    $('.akdk-basic-input').each(function () { chars += $(this).val().length; });
                    chars += ($('textarea[name="wp_ai_chatbot_settings[system_prompt]"]').val() || '').length;
                    chars += parseInt($('.akdk-basic-size').data('extra'), 10) || 0; // Answers added in Chat History.
                    var tokens = Math.round(chars / 4) + 300; // + the bot's own rules.
                    $('.akdk-basic-tokens').text(tokens.toLocaleString());
                    var advice = tokens > 2500 ? 'This is long and every message pays for it. Smart mode would send only the part that matches each question.'
                        : (tokens > 1500 ? 'Fine, but keep it short: every message pays for it.' : 'Good size.');
                    $('.akdk-basic-advice').text(advice).toggleClass('is-long', tokens > 2500);
                }
                $(document).on('change', 'input[name="wp_ai_chatbot_settings[bot_mode]"]', syncMode);
                $(document).on('input', '.akdk-basic-input, textarea[name="wp_ai_chatbot_settings[system_prompt]"]', syncSize);
                syncMode();

                $(document).on('change', '.akdk-model-select', function () {
                    var custom = $(this).val() === '__custom__';
                    $(this).siblings('.akdk-model-custom').prop('hidden', !custom).trigger(custom ? 'focus' : 'blur');
                });
                $(document).on('click', '.akdk-refresh-models', function (e) {
                    e.preventDefault();
                    var $b = $(this);
                    var label = $b.text();
                    $b.text('Checking...').prop('disabled', true);
                    $.post(ajaxurl, {
                        action: 'wp_ai_chatbot_refresh_models',
                        provider: $b.data('provider'),
                        security: '<?php echo esc_js( wp_create_nonce( 'wp_ai_chatbot_test_connection' ) ); ?>'
                    }).done(function (res) {
                        if (res && res.success) { window.location.reload(); return; }
                        // The key did not work: reload so the reason shows next to the key.
                        if ($b.closest('.akdk-model-locked').length) { window.location.reload(); return; }
                        $b.text(label).prop('disabled', false);
                        window.alert((res && res.data) ? res.data : 'Could not load the model list.');
                    }).fail(function () { $b.text(label).prop('disabled', false); });
                });

                $('#wp-ai-test-connection').on('click', function() {
                    var $btn = $(this), $out = $('#wp-ai-test-result');
                    $btn.prop('disabled', true);
                    $('#wp-ai-test-spinner').addClass('is-active');
                    $out.empty();
                    $.post(ajaxurl, {
                        action: 'wp_ai_chatbot_test_connection',
                        security: '<?php echo esc_js( wp_create_nonce( 'wp_ai_chatbot_test_connection' ) ); ?>'
                    }).done(function(res) {
                        var ok = res && res.success;
                        var msg = (res && typeof res.data === 'string') ? res.data : 'Unexpected response.';
                        if (!ok) {
                            // Plain-language next step for the most common setup problems.
                            if (/api[_ ]?key[^.]*(not valid|invalid)|incorrect api key|401|unauthenticated/i.test(msg)) {
                                msg += ' → The key is not accepted. Copy it again (the whole key, no spaces), paste it, Save settings and test again.';
                            } else if (/quota|429|rate limit|resource[_ ]exhausted|insufficient_quota|billing/i.test(msg)) {
                                msg += ' → The account has reached its limit. Wait a minute and try again, or check the plan/credit of this API key.';
                            } else if (/not found|404|does not exist|model/i.test(msg)) {
                                msg += ' → Clear the model field to use the recommended model, Save settings and test again.';
                            }
                        }
                        $out.append($('<div class="notice inline"><p></p></div>').addClass(ok ? 'notice-success' : 'notice-error').find('p').text(msg).end());
                    }).fail(function() {
                        $out.append($('<div class="notice notice-error inline"><p>Request failed. Please try again.</p></div>'));
                    }).always(function() {
                        $btn.prop('disabled', false);
                        $('#wp-ai-test-spinner').removeClass('is-active');
                    });
                });

                // Right after saving a key, check it automatically, so a wrong key is noticed at once.
                <?php if ( isset( $_GET['settings-updated'] ) && '' !== WP_AI_Chatbot_AI::api_key( WP_AI_Chatbot_AI::chat_provider() ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                $('#wp-ai-test-connection').trigger('click');
                <?php endif; ?>
            });
            </script>
        </div>
        <?php
    }
}
