<?php
/**
 * Voice input.
 *
 * Two modes, chosen under Appearance > General:
 * - "All languages" (default): the browser records a short WAV clip and this
 *   endpoint asks Gemini to write it down. English stays English; Urdu and
 *   Hindi come back in Roman Urdu. Needs a Gemini API key; without one the
 *   widget falls back to the browser's own speech recognition in the
 *   visitor's browser language.
 * - "One language only" (strict): the browser's free speech recognition in
 *   the language the admin picked. Nothing is sent to this endpoint.
 *
 * @package AK_AI_Chatbot
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_AI_Chatbot_Voice {

    const USAGE_OPTION = 'wp_ai_chatbot_voice_usage';
    const MAX_BYTES    = 2200000; // About 65 seconds of 16 kHz mono WAV; the widget stops at 30.
    const MIN_BYTES    = 8000;    // A quarter of a second: shorter clips are clicks, not speech.

    /** 'auto' (all languages) or 'strict' (one language). */
    public static function mode( $style = null ) {
        $style = is_array( $style ) ? $style : WP_AI_Chatbot_Widget_Style::get();
        return ( isset( $style['voice_mode'] ) && 'strict' === $style['voice_mode'] ) ? 'strict' : 'auto';
    }

    /** True when the all-languages mode can use Gemini on this site. */
    public static function server_available( $style = null ) {
        $style = is_array( $style ) ? $style : WP_AI_Chatbot_Widget_Style::get();
        return isset( $style['voice_input'] ) && '1' === $style['voice_input']
            && 'auto' === self::mode( $style )
            && WP_AI_Chatbot_AI::search_voice_on()
            && '' !== WP_AI_Chatbot_AI::api_key( 'gemini' );
    }

    /** Settings passed to the widget script. */
    public static function widget_data( $style ) {
        $server = self::server_available( $style );
        return array(
            // Search & voice turned off in AI Settings: no mic at all.
            'voice_enabled'  => WP_AI_Chatbot_AI::search_voice_on() ? $style['voice_input'] : '0',
            'voice_mode'     => self::mode( $style ),
            'voice_lang'     => $style['voice_lang'],
            'transcribe_url' => $server ? esc_url_raw( rest_url( 'wp-ai-chatbot/v1/transcribe' ) ) : '',
            'voice_max_sec'  => (int) apply_filters( 'wp_ai_chatbot_voice_max_seconds', WP_AI_Chatbot_Token_Control::voice_max_seconds() ),
        );
    }

    public static function register_route( $permission_callback ) {
        register_rest_route(
            'wp-ai-chatbot/v1',
            '/transcribe',
            array(
                'methods'             => 'POST',
                'callback'            => array( __CLASS__, 'handle' ),
                'permission_callback' => $permission_callback,
            )
        );
    }

    public static function handle( WP_REST_Request $request ) {
        if ( ! self::server_available() ) {
            return new WP_Error( 'voice_off', 'Voice input is not available.', array( 'status' => 403 ) );
        }
        $ip = WP_AI_Chatbot_Rate_Limiter::get_client_ip();
        if ( ! WP_AI_Chatbot_Rate_Limiter::allow( 'voice_' . $ip, (int) apply_filters( 'wp_ai_chatbot_voice_rate_limit', 8 ), 60 )
            || ! WP_AI_Chatbot_Rate_Limiter::allow( 'voice_day_' . $ip, (int) apply_filters( 'wp_ai_chatbot_voice_daily_limit', 150 ), DAY_IN_SECONDS ) ) {
            return new WP_Error( 'rate_limited', 'Too many voice messages. Please type your message or try again in a minute.', array( 'status' => 429 ) );
        }

        if ( WP_AI_Chatbot_Token_Control::limit_reached() ) {
            return new WP_Error( 'token_limit', 'Voice is paused for today. Please type your message.', array( 'status' => 429 ) );
        }

        $params = $request->get_json_params();
        $b64    = ( is_array( $params ) && isset( $params['audio'] ) && is_string( $params['audio'] ) ) ? $params['audio'] : '';
        if ( '' === $b64 || strlen( $b64 ) > (int) ceil( self::MAX_BYTES * 4 / 3 ) + 8 ) {
            return new WP_Error( 'bad_audio', 'The recording is empty or too long.', array( 'status' => 400 ) );
        }
        $wav = base64_decode( $b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
        if ( false === $wav || ! self::is_wav( $wav ) ) {
            return new WP_Error( 'bad_audio', 'The recording could not be read.', array( 'status' => 400 ) );
        }
        if ( strlen( $wav ) < self::MIN_BYTES ) {
            return rest_ensure_response( array( 'success' => true, 'text' => '' ) );
        }

        WP_AI_Chatbot_AI::reset_usage();
        $text = WP_AI_Chatbot_AI::transcribe( $wav );
        $use  = WP_AI_Chatbot_AI::usage();
        self::record_usage( $use['prompt'], $use['completion'], $use['exact'] );

        if ( is_wp_error( $text ) ) {
            WP_AI_Chatbot_AI::record_error( 'Voice input', $text );
            return new WP_Error( 'voice_failed', 'Sorry, voice is not working right now. Please type your message.', array( 'status' => 502 ) );
        }
        return rest_ensure_response( array( 'success' => true, 'text' => $text ) );
    }

    /** A PCM WAV file: RIFF header, WAVE format, 16-bit, mono or stereo. */
    public static function is_wav( $bin ) {
        if ( strlen( $bin ) < 44 || 'RIFF' !== substr( $bin, 0, 4 ) || 'WAVE' !== substr( $bin, 8, 4 ) || 'fmt ' !== substr( $bin, 12, 4 ) ) {
            return false;
        }
        $fmt = unpack( 'vformat/vchannels/Vrate/Vbyterate/vblock/vbits', substr( $bin, 20, 16 ) );
        return is_array( $fmt ) && 1 === (int) $fmt['format'] && in_array( (int) $fmt['channels'], array( 1, 2 ), true )
            && (int) $fmt['rate'] >= 8000 && (int) $fmt['rate'] <= 48000 && 16 === (int) $fmt['bits'];
    }

    /** Daily totals for the dashboard cost estimate. */
    public static function record_usage( $in, $out, $exact ) {
        $in  = max( 0, (int) $in );
        $out = max( 0, (int) $out );
        $log = get_option( self::USAGE_OPTION, array() );
        $log = is_array( $log ) ? $log : array();
        $day = current_time( 'Y-m-d' );
        if ( ! isset( $log[ $day ] ) ) {
            $log[ $day ] = array( 'in' => 0, 'out' => 0, 'clips' => 0, 'estimated' => 0 );
        }
        $log[ $day ]['in']    += $in;
        $log[ $day ]['out']   += $out;
        $log[ $day ]['clips'] += 1;
        if ( ! $exact ) {
            $log[ $day ]['estimated'] += $in + $out;
        }
        ksort( $log );
        if ( count( $log ) > 120 ) {
            $log = array_slice( $log, -120, null, true );
        }
        update_option( self::USAGE_OPTION, $log, false );
    }

    /** @return array in, out, clips between two dates (Y-m-d, inclusive). */
    public static function usage( $from, $to ) {
        $from = substr( (string) $from, 0, 10 );
        $to   = substr( (string) $to, 0, 10 );
        $out  = array( 'in' => 0, 'out' => 0, 'clips' => 0, 'estimated' => 0 );
        foreach ( (array) get_option( self::USAGE_OPTION, array() ) as $day => $row ) {
            if ( $day >= $from && $day <= $to && is_array( $row ) ) {
                foreach ( $out as $k => $v ) {
                    $out[ $k ] += (int) ( $row[ $k ] ?? 0 );
                }
            }
        }
        return $out;
    }
}
