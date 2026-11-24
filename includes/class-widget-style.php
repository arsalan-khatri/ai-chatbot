<?php
/**
 * Appearance values for the chat widget: defaults, saved options and the CSS
 * variables built from them. Shared by the front-end widget and the live
 * preview on the Appearance screen, so both always look the same.
 *
 * @package AK_AI_Chatbot
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_AI_Chatbot_Widget_Style {

    const OPTION = 'wp_ai_chatbot_custom_style';

    /** Default look = "AK Deep Knowledge Light". */
    public static function defaults() {
        return array(
            'bot_name'                 => 'AI Assistant',
            'header_subtitle'          => 'Online · replies instantly',
            'input_placeholder'        => 'Type your message...',
            'bot_avatar'               => '',
            'position'                 => 'right',
            'margin_side'              => '24',
            'margin_bottom'            => '24',
            'margin_side_mobile'       => '16',
            'margin_bottom_mobile'     => '16',
            'chat_width'               => '380',
            'chat_height'              => '600',
            'chat_radius'              => '18',
            'icon_size'                => '64',
            'general_border_color'     => '#ececec',
            'header_bg'                => '#fd9400',
            'header_text'              => '#0a0a0a',
            'chat_bg'                  => '#ffffff',
            'bot_bubble_bg'            => '#f5f5f5',
            'bot_bubble_text'          => '#0a0a0a',
            'user_bubble_bg'           => '#0a0a0a',
            'user_bubble_text'         => '#ffffff',
            'send_btn_bg'              => '#fd9400',
            'send_btn_hover_bg'        => '#e58600',
            'send_btn_text'            => '#0a0a0a',
            'send_btn_icon_hover'      => '#0a0a0a',
            'del_btn_bg'               => '#d63638',
            'del_btn_hover_bg'         => '#b32d2e',
            'close_btn_color'          => '#0a0a0a',
            'close_btn_hover_bg'       => 'rgba(0,0,0,0.08)',
            'close_bg'                 => '#ef4444',
            'close_icon'               => '#ffffff',
            'close_hover_bg'           => '#dc2626',
            'close_hover_icon'         => '#ffffff',
            'close_btn_size'           => '32',
            'close_btn_padding'        => '0',
            'close_btn_margin'         => '0',
            'input_bg_color'           => '#ffffff',
            'input_border_sides'       => 'all',
            'input_border_width'       => '1',
            'input_border_color'       => '#e5e5e5',
            'input_border_focus_color' => '#fd9400',
            'toggle_icon_transparent'  => '0',
            'chat_bg_transparent'      => '0',
            'send_btn_icon'            => '',
            'toggle_icon'              => '',
            'quick_actions_align'      => 'center',
            'show_branding'            => '1',
            'voice_input'              => '0',
            'voice_mode'               => 'auto', // 'auto' = all languages, 'strict' = only voice_lang.
            'voice_lang'               => 'en-US',
            // Chat icon (launcher). See WP_AI_Chatbot_Launcher_Icons.
            'launcher_style'           => 'akdk_bot',
            'launcher_mode'            => 'light',
            'launcher_greet'           => '1',
            'launcher_greeting'        => 'Hi!',
            'launcher_greet_delay'     => '2',
            'launcher_greet_duration'  => '8',
        ) + WP_AI_Chatbot_Launcher_Icons::color_defaults();
    }

    /** Saved options merged over the defaults (empty values fall back to the default). */
    public static function get() {
        $saved    = get_option( self::OPTION, array() );
        $saved    = is_array( $saved ) ? $saved : array();
        $defaults = self::defaults();
        $opts     = $defaults;
        foreach ( $saved as $key => $value ) {
            if ( is_array( $value ) ) {
                continue;
            }
            if ( '' === $value && isset( $defaults[ $key ] ) && '' !== $defaults[ $key ] && ! in_array( $key, array( 'header_subtitle' ), true ) ) {
                continue;
            }
            $opts[ $key ] = $value;
        }

        if ( ! in_array( $opts['input_border_sides'], array( 'all', 'none', 'top', 'bottom', 'left', 'right' ), true ) ) {
            $opts['input_border_sides'] = 'all';
        }
        if ( ! in_array( $opts['quick_actions_align'], array( 'left', 'center', 'right' ), true ) ) {
            $opts['quick_actions_align'] = 'center';
        }
        $opts['position'] = ( 'left' === $opts['position'] ) ? 'left' : 'right';

        // Chat icon. Sites that set a custom icon image before 1.6 keep it.
        if ( ! array_key_exists( 'launcher_style', $saved ) && ! empty( $saved['toggle_icon'] ) ) {
            $opts['launcher_style'] = 'custom';
        }
        if ( ! in_array( $opts['launcher_style'], WP_AI_Chatbot_Launcher_Icons::styles(), true ) ) {
            $opts['launcher_style'] = 'akdk_bot';
        }
        if ( 'custom' === $opts['launcher_style'] && empty( $opts['toggle_icon'] ) ) {
            $opts['launcher_style'] = 'akdk_bot';
        }
        $opts['launcher_mode'] = WP_AI_Chatbot_Launcher_Icons::mode( $opts );
        foreach ( WP_AI_Chatbot_Launcher_Icons::color_defaults() as $key => $color ) {
            $opts[ $key ] = sanitize_hex_color( $opts[ $key ] ) ? $opts[ $key ] : $color;
        }
        foreach ( array( 'margin_side', 'margin_bottom', 'chat_width', 'chat_height', 'chat_radius', 'icon_size', 'close_btn_size', 'close_btn_padding', 'close_btn_margin', 'input_border_width' ) as $num ) {
            $opts[ $num ] = (string) max( 0, intval( $opts[ $num ] ) );
        }
        return $opts;
    }

    /** #0a0a0a or #f5f5f5, whichever reads better on the given background colour. */
    public static function readable_on( $hex ) {
        $hex = ltrim( (string) $hex, '#' );
        if ( 3 === strlen( $hex ) ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if ( ! preg_match( '/^[0-9a-fA-F]{6}$/', $hex ) ) {
            return '#0a0a0a';
        }
        $lum = ( 0.299 * hexdec( substr( $hex, 0, 2 ) ) + 0.587 * hexdec( substr( $hex, 2, 2 ) ) + 0.114 * hexdec( substr( $hex, 4, 2 ) ) ) / 255;
        return $lum > 0.55 ? '#0a0a0a' : '#f5f5f5';
    }

    /** CSS custom properties for the widget, e.g. "--wp-ai-header-bg:#fd9400;". */
    public static function css_vars( $o ) {
        $vars = array(
            '--wp-ai-border-color'       => $o['general_border_color'],
            '--wp-ai-header-bg'          => $o['header_bg'],
            '--wp-ai-header-text'        => $o['header_text'],
            '--wp-ai-chat-bg'            => $o['chat_bg'],
            '--wp-ai-bot-bg'             => $o['bot_bubble_bg'],
            '--wp-ai-bot-text'           => $o['bot_bubble_text'],
            '--wp-ai-user-bg'            => $o['user_bubble_bg'],
            '--wp-ai-user-text'          => $o['user_bubble_text'],
            '--wp-ai-send-bg'            => $o['send_btn_bg'],
            '--wp-ai-send-hover-bg'      => $o['send_btn_hover_bg'],
            '--wp-ai-send-text'          => $o['send_btn_text'],
            '--wp-ai-send-icon-hover'    => $o['send_btn_icon_hover'],
            '--wp-ai-del-bg'             => $o['del_btn_bg'],
            '--wp-ai-del-hover-bg'       => $o['del_btn_hover_bg'],
            '--wp-ai-close-color'        => $o['close_btn_color'],
            '--wp-ai-close-hover-bg'     => $o['close_btn_hover_bg'],
            '--wp-ai-x-bg'               => $o['close_bg'],
            '--wp-ai-x-icon'             => $o['close_icon'],
            '--wp-ai-x-hover-bg'         => $o['close_hover_bg'],
            '--wp-ai-x-hover-icon'       => $o['close_hover_icon'],
            '--wp-ai-close-size'         => intval( $o['close_btn_size'] ) . 'px',
            '--wp-ai-close-padding'      => intval( $o['close_btn_padding'] ) . 'px',
            '--wp-ai-close-margin'       => intval( $o['close_btn_margin'] ) . 'px',
            '--wp-ai-input-bg'           => $o['input_bg_color'],
            '--wp-ai-input-border-color' => $o['input_border_color'],
            '--wp-ai-input-focus-color'  => $o['input_border_focus_color'],
            '--wp-ai-input-border-width' => intval( $o['input_border_width'] ) . 'px',
            // Text inside form boxes: dark on a light box, light on a dark box (always readable).
            '--wp-ai-input-text'         => self::readable_on( $o['input_bg_color'] ),
            '--wp-ai-primary-color'      => $o['send_btn_bg'],
            '--wp-ai-chat-width'         => intval( $o['chat_width'] ) . 'px',
            '--wp-ai-chat-height'        => intval( $o['chat_height'] ) . 'px',
            '--wp-ai-chat-radius'        => intval( $o['chat_radius'] ) . 'px',
            '--wp-ai-icon-size'          => intval( $o['icon_size'] ) . 'px',
        );
        $css = '';
        foreach ( $vars as $name => $value ) {
            $value = str_replace( array( '{', '}', ';', '<', '>', '"', "'" ), '', (string) $value );
            $css  .= $name . ':' . $value . ';';
        }
        return $css;
    }
}
