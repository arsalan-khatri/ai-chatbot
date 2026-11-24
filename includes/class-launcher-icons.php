<?php
/**
 * Animated chat launcher icons ("Icons" tab under Appearance).
 *
 * Every icon is inline SVG animated with CSS (public/css/launcher-icons.css).
 * Colours come from CSS variables, so the admin can recolour any icon and the
 * same markup works for light and dark websites:
 *   --akic-main    body colour
 *   --akic-ink     outlines (one value for light sites, one for dark sites)
 *   --akic-face    screen, pupils and mouth
 *   --akic-eye     eyes and highlights
 *   --akic-accent  small details (antenna tip, sparkle, mic)
 *
 * The markup is a fixed string written by the plugin (no user input), so it is
 * printed as is. Everything the admin types (greeting, colours) is escaped.
 *
 * @package AK_AI_Chatbot
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_AI_Chatbot_Launcher_Icons {

    /** Icon colour defaults (AK Deep Knowledge palette). */
    public static function color_defaults() {
        return array(
            'icon_main'     => '#fd9400',
            'icon_face'     => '#0a0a0a',
            'icon_eye'      => '#ffffff',
            'icon_accent'   => '#fd9400',
            'icon_ink'      => '#0a0a0a', // Outline on light websites.
            'icon_ink_dark' => '#ffffff', // Outline on dark websites.
        );
    }

    /**
     * Animated templates in gallery order: id => array( name, short description, group ).
     * Group "bot" = chatbot icons (shown first), "fun" = characters.
     */
    public static function catalog() {
        return array(
            // Chatbot icons.
            'akdk_bot' => array( 'AKDK Bot', 'Hops, looks around and waves hello', 'bot' ),
            'typing'   => array( 'Typing Bubble', 'Types "..." then smiles', 'bot' ),
            'spark'    => array( 'AI Spark', 'Chat bubble with a turning AI sparkle', 'bot' ),
            'convo'    => array( 'Conversation', 'Two bubbles chatting back and forth', 'bot' ),
            'visor'    => array( 'Visor Bot', 'Sleek helmet, eyes glide behind the visor', 'bot' ),
            'orbit'    => array( 'Orbit Bot', 'Round head with a rocking ring', 'bot' ),
            'voice'    => array( 'Voice Bot', 'Sound-wave mouth for voice assistants', 'bot' ),
            'cube'     => array( 'Cube Bot', '3D cube with a blinking antenna', 'bot' ),
            'capsule'  => array( 'Capsule Bot', 'Floating pill assistant that waves', 'bot' ),
            'live'     => array( 'Live Bot', 'Always online, pulsing signal rings', 'bot' ),
            'helpbot'  => array( 'Help Bot', 'A "?" pops up and turns into a tick', 'bot' ),
            'buddy'    => array( 'Support Buddy', 'Headset helper, types and talks', 'bot' ),
            'robo'     => array( 'Retro Robo', 'Scanning eye and blinking antenna', 'bot' ),
            // Fun characters.
            'kitty'    => array( 'Kitty', 'Twitchy ears, swishy tail, paw wave', 'fun' ),
            'puppy'    => array( 'Puppy', 'Floppy ears, tongue out, happy', 'fun' ),
            'redpanda' => array( 'Red Panda', 'Twitchy ears and a cheeky grin', 'fun' ),
            'bunny'    => array( 'Bunny', 'Hops, flops an ear, wiggles its nose', 'fun' ),
            'fox'      => array( 'Foxy', 'Sly wink and a wagging tail', 'fun' ),
            'hoot'     => array( 'Hoot', 'Wise owl that tilts, winks and flaps', 'fun' ),
            'penguin'  => array( 'Penguin', 'Waddles and flaps its flippers', 'fun' ),
            'chick'    => array( 'Chick', 'Pops out of its egg to tweet', 'fun' ),
            'froggy'   => array( 'Froggy', 'Croaks and snaps at a fly', 'fun' ),
            'bee'      => array( 'Busy Bee', 'Buzzes around with fluttery wings', 'fun' ),
            'octopus'  => array( 'Octo', 'Bobs with wiggly tentacles', 'fun' ),
            'jelly'    => array( 'Jelly', 'Wobbly blob that jumps and squints', 'fun' ),
            'boo'      => array( 'Boo', 'Floating ghost that waves and says "o"', 'fun' ),
            'alien'    => array( 'Alien', 'One big curious eye, bouncy antennae', 'fun' ),
            'monster'  => array( 'Monster', 'Fuzzy one-eyed troublemaker, tongue out', 'fun' ),
            'dino'     => array( 'Dino', 'Tiny arms wave, then a big ROAR', 'fun' ),
            'ufo'      => array( 'UFO', 'Hovers with blinking lights and a beam', 'fun' ),
            'rocket'   => array( 'Rocket', 'Rumbles, flame flickers, ready to launch', 'fun' ),
            'cloud'    => array( 'Fluffy', 'Floating cloud with little drops', 'fun' ),
            'sun'      => array( 'Cool Sun', 'Spinning rays and shiny sunglasses', 'fun' ),
            'heart'    => array( 'Love', 'Beating heart with floating hearts', 'fun' ),
            'donut'    => array( 'Donut', 'Rolls side to side, sprinkles twinkle', 'fun' ),
            'chai'     => array( 'Chai', 'Steaming cup that winks at you', 'fun' ),
        );
    }

    /** All launcher styles, including the plain bubble and a custom image. */
    public static function styles() {
        return array_merge( array_keys( self::catalog() ), array( 'classic', 'custom' ) );
    }

    public static function is_animated( $style ) {
        return isset( self::catalog()[ $style ] );
    }

    /** CSS variables for the icon colours of the saved settings. */
    public static function css_vars( $o ) {
        $d    = self::color_defaults();
        $vars = array(
            '--akic-main'      => 'icon_main',
            '--akic-face'      => 'icon_face',
            '--akic-eye'       => 'icon_eye',
            '--akic-accent'    => 'icon_accent',
            '--akic-ink-light' => 'icon_ink',
            '--akic-ink-dark'  => 'icon_ink_dark',
        );
        $css = '';
        foreach ( $vars as $var => $key ) {
            $value = isset( $o[ $key ] ) ? sanitize_hex_color( $o[ $key ] ) : '';
            $css  .= $var . ':' . ( $value ? $value : $d[ $key ] ) . ';';
        }
        return $css;
    }

    /** "light", "dark" or "auto" (follows the visitor's device setting). */
    public static function mode( $o ) {
        $mode = isset( $o['launcher_mode'] ) ? $o['launcher_mode'] : 'light';
        return in_array( $mode, array( 'light', 'dark', 'auto' ), true ) ? $mode : 'light';
    }

    /** The SVG of one animated template. */
    public static function svg( $id ) {
        $body = array_merge( self::bodies(), self::more_bodies(), self::pro_bodies() );
        if ( ! isset( $body[ $id ] ) ) {
            $id = 'akdk_bot';
        }
        return '<svg class="akic akic-' . $id . '" viewBox="0 0 64 64" aria-hidden="true" focusable="false">' . $body[ $id ] . '</svg>';
    }

    /**
     * Inside of the launcher button: the icon, the greeting bubble and the small
     * close badge shown while the chat is open.
     */
    public static function toggle_inner( $o ) {
        $style = isset( $o['launcher_style'] ) ? $o['launcher_style'] : 'akdk_bot';
        $html  = '';
        if ( self::is_animated( $style ) ) {
            $html .= self::svg( $style );
        } elseif ( 'custom' === $style && ! empty( $o['toggle_icon'] ) ) {
            $html .= '<img class="wp-ai-toggle-img" src="' . esc_url( $o['toggle_icon'] ) . '" alt="">';
        } else {
            $html .= self::classic_svg();
        }
        $greeting = isset( $o['launcher_greeting'] ) ? trim( (string) $o['launcher_greeting'] ) : '';
        if ( '' !== $greeting && ( ! isset( $o['launcher_greet'] ) || '1' === $o['launcher_greet'] ) ) {
            $html .= '<span class="wp-ai-greet" aria-hidden="true">' . esc_html( $greeting ) . '</span>';
        }
        $html .= '<span class="wp-ai-launcher-x" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg></span>';
        return $html;
    }

    /** Classes for the launcher button. */
    public static function toggle_classes( $o ) {
        $style   = isset( $o['launcher_style'] ) ? $o['launcher_style'] : 'akdk_bot';
        $classes = 'wp-ai-chatbot-toggle akic-scope akic-mode-' . self::mode( $o );
        if ( self::is_animated( $style ) ) {
            $classes .= ' wp-ai-toggle-animated';
        } elseif ( 'custom' === $style && ! empty( $o['toggle_icon'] ) ) {
            $classes .= ' wp-ai-toggle-custom';
        } else {
            $classes .= ' wp-ai-toggle-classic';
        }
        return $classes;
    }

    public static function classic_svg() {
        return '<svg class="wp-ai-icon-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>'
            . '<svg class="wp-ai-icon-close" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>';
    }

    /* ------------------------------------------------------------------
     * Artwork (64 x 64; hands, tails and bubbles may reach outside)
     * Shared classes: akic-main (body fill), akic-line (outline), akic-face,
     * akic-eye, akic-acc, akic-shine, akic-blush, akic-root (hover reaction).
     * ------------------------------------------------------------------ */

    private static function bodies() {
        $spark = '<path class="akic-spark akic-acc akic-line akic-thin" d="M60 1c.5 2.6 1.4 3.5 4 4-2.6.5-3.5 1.4-4 4-.5-2.6-1.4-3.5-4-4 2.6-.5 3.5-1.4 4-4z"/>';
        return array(

            // 1. AKDK Bot: chat bubble with a face. Hops, looks left and right, blinks, waves.
            'akdk_bot' => '<ellipse class="akic-shadow akic-bot-shadow" cx="32" cy="61.5" rx="15" ry="1.8"/>'
                . '<g class="akic-root"><g class="akic-bot-hop">'
                . '<g class="akic-bot-hand"><path class="akic-main akic-line" d="M54 33c3-1.5 5.6-4.6 6.2-8.2.3-1.9 2.9-1.9 3.2 0 .2 1.2.1 2.5-.3 3.7l2.1-4.4c.8-1.7 3.3-.9 3 .9l-.6 3.3 1.6-2.5c1-1.6 3.4-.4 2.8 1.4-1.6 5.3-5.9 9.5-11.5 10.6L56 39z"/></g>'
                . '<path class="akic-line akic-nofill" d="M32 13V7"/>'
                . '<circle class="akic-acc akic-line akic-bot-tip" cx="32" cy="5.2" r="3.2"/>'
                . '<path class="akic-main akic-line" d="M20 12h24c6.6 0 12 5.4 12 12v14c0 6.6-5.4 12-12 12H27.5l-9.3 7.4c-1.3 1-3.2.1-3.2-1.6V49.1C10.3 47.4 8 43.1 8 38V24c0-6.6 5.4-12 12-12z"/>'
                . '<path class="akic-shine" d="M14.5 22.5c.8-3.6 3.6-6 7.3-6h8"/>'
                . '<rect class="akic-face" x="16" y="22" width="32" height="20" rx="10"/>'
                . '<g class="akic-bot-look">'
                . '<g class="akic-bot-open"><rect class="akic-eye akic-blink" x="23" y="26.5" width="5.2" height="8" rx="2.6"/><rect class="akic-eye akic-blink" x="35.8" y="26.5" width="5.2" height="8" rx="2.6"/></g>'
                . '<g class="akic-bot-happy akic-eye-s"><path d="M22.8 32c.9-2.6 4.7-2.6 5.6 0"/><path d="M35.6 32c.9-2.6 4.7-2.6 5.6 0"/></g>'
                . '<path class="akic-acc-s akic-bot-mouth" d="M28.5 37.2c2.2 1.5 4.8 1.5 7 0"/>'
                . '</g></g></g>' . $spark,

            // 2. Jelly: wobbly blob. Squish-jumps, eyes roll, squints "><" and sends up bubbles.
            'jelly' => '<ellipse class="akic-shadow akic-jelly-shadow" cx="32" cy="61" rx="17" ry="2"/>'
                . '<g class="akic-root"><g class="akic-jelly-body">'
                . '<path class="akic-main akic-line" d="M32 7c2.5 0 3.8 2.4 3.4 4.6C46.5 13.8 55 23.6 55 37c0 12.7-9.6 20-23 20S9 49.7 9 37c0-13.4 8.5-23.2 19.6-25.4C28.2 9.4 29.5 7 32 7z"/>'
                . '<path class="akic-shine" d="M16 30c1.2-5 4.5-9 9.5-11"/>'
                . '<ellipse class="akic-blush" cx="16.5" cy="43" rx="3.6" ry="2.2"/><ellipse class="akic-blush" cx="47.5" cy="43" rx="3.6" ry="2.2"/>'
                . '<g class="akic-jelly-open">'
                . '<circle class="akic-eye akic-line akic-thin" cx="24" cy="34" r="6"/><circle class="akic-eye akic-line akic-thin" cx="40" cy="34" r="6"/>'
                . '<g class="akic-jelly-pupils"><circle class="akic-face" cx="25" cy="35" r="3"/><circle class="akic-face" cx="41" cy="35" r="3"/><circle class="akic-eye" cx="26" cy="33.8" r="1"/><circle class="akic-eye" cx="42" cy="33.8" r="1"/></g>'
                . '</g>'
                . '<g class="akic-jelly-squint akic-line akic-nofill"><path d="M20.5 30.5l6 3.5-6 3.5"/><path d="M43.5 30.5l-6 3.5 6 3.5"/></g>'
                . '<path class="akic-face akic-jelly-mouth" d="M27.5 44c1.6 3.6 7.4 3.6 9 0z"/>'
                . '</g></g>'
                . '<g class="akic-jelly-bubbles"><circle class="akic-eye akic-line akic-thin" cx="54" cy="12" r="2.6"/><circle class="akic-eye akic-line akic-thin" cx="59" cy="5" r="1.7"/></g>',

            // 3. Kitty: ears twitch, tail swishes, head tilts, paw waves.
            'kitty' => '<ellipse class="akic-shadow" cx="32" cy="61" rx="16" ry="1.8"/>'
                . '<g class="akic-root">'
                . '<g class="akic-kitty-tail"><path class="akic-line akic-nofill akic-thick" d="M46 53c9 1 14-5 12-12-.8-3-3.6-4-5.6-2.3"/><path class="akic-main-s akic-nofill" d="M46 53c9 1 14-5 12-12-.8-3-3.6-4-5.6-2.3"/></g>'
                . '<g class="akic-kitty-head">'
                . '<g class="akic-kitty-ear-l"><path class="akic-main akic-line" d="M15 28L16 7l13 9z"/><path class="akic-face akic-o25" d="M18.5 21.5l.6-9.2 6 4.4z"/></g>'
                . '<g class="akic-kitty-ear-r"><path class="akic-main akic-line" d="M49 28L48 7 35 16z"/><path class="akic-face akic-o25" d="M45.5 21.5l-.6-9.2-6 4.4z"/></g>'
                . '<path class="akic-main akic-line" d="M32 13c13 0 21 8 21 20s-8 23-21 23S11 45 11 33s8-20 21-20z"/>'
                . '<path class="akic-shine" d="M17 27c1.5-4.4 5-7.6 9.6-8.8"/>'
                . '<ellipse class="akic-blush" cx="19" cy="42" rx="3.4" ry="2"/><ellipse class="akic-blush" cx="45" cy="42" rx="3.4" ry="2"/>'
                . '<g class="akic-kitty-eyes"><ellipse class="akic-face akic-blink" cx="24.5" cy="33" rx="3.6" ry="4.6"/><ellipse class="akic-face akic-blink" cx="39.5" cy="33" rx="3.6" ry="4.6"/><circle class="akic-eye" cx="25.8" cy="31.2" r="1.3"/><circle class="akic-eye" cx="40.8" cy="31.2" r="1.3"/></g>'
                . '<path class="akic-face" d="M30.3 38.6h3.4l-1.7 2.1z"/>'
                . '<path class="akic-line akic-nofill akic-thin" d="M28.4 42.3c1.2 1.5 2.7 1.5 3.6 0 .9 1.5 2.4 1.5 3.6 0"/>'
                . '<path class="akic-line akic-nofill akic-whisker" d="M19.5 38.5l-8.5-1.2M19.5 41.5l-8.2 2.4M44.5 38.5l8.5-1.2M44.5 41.5l8.2 2.4"/>'
                . '</g>'
                . '<g class="akic-kitty-paw"><path class="akic-main akic-line" d="M51 50c-1.5-4 .5-8.5 4.6-9.2 4-.7 7.2 2.6 6.8 6.4-.3 3.2-3 5-6 5.2"/><circle class="akic-face akic-o30" cx="55" cy="44.6" r="1"/><circle class="akic-face akic-o30" cx="58.4" cy="44.8" r="1"/><circle class="akic-face akic-o30" cx="60.4" cy="47.4" r="1"/></g>'
                . '</g>',

            // 4. Boo: floating ghost. Sways, waves both arms, says "o", blinks.
            'boo' => '<ellipse class="akic-shadow akic-boo-shadow" cx="32" cy="62" rx="13" ry="1.8"/>'
                . '<g class="akic-root"><g class="akic-boo-float">'
                . '<g class="akic-boo-arm-l"><path class="akic-main akic-line" d="M15 36c-4.4-.6-8 1.4-9.2 4.8 3.2 1.6 6.8.8 9.2-1.6"/></g>'
                . '<g class="akic-boo-arm-r"><path class="akic-main akic-line" d="M49 36c4.4-.6 8 1.4 9.2 4.8-3.2 1.6-6.8.8-9.2-1.6"/></g>'
                . '<path class="akic-main akic-line" d="M14 31c0-11.6 8-20 18-20s18 8.4 18 20v21q-3 3-6 0t-6 0t-6 0t-6 0t-6 0t-6 0z"/>'
                . '<path class="akic-shine" d="M19 27c1-5 4.4-9 9-10.4"/>'
                . '<ellipse class="akic-blush" cx="20.5" cy="37" rx="3" ry="1.9"/><ellipse class="akic-blush" cx="43.5" cy="37" rx="3" ry="1.9"/>'
                . '<g class="akic-boo-eyes"><ellipse class="akic-face akic-blink" cx="26" cy="29" rx="3" ry="4.4"/><ellipse class="akic-face akic-blink" cx="38" cy="29" rx="3" ry="4.4"/><circle class="akic-eye" cx="27" cy="27.2" r="1.1"/><circle class="akic-eye" cx="39" cy="27.2" r="1.1"/></g>'
                . '<ellipse class="akic-face akic-boo-mouth" cx="32" cy="38.5" rx="2.6" ry="3"/>'
                . '</g></g>' . $spark,

            // 5. Hoot: owl. Head tilts, pupils roll, winks, flaps its wings.
            'hoot' => '<ellipse class="akic-shadow" cx="32" cy="61.5" rx="16" ry="1.8"/>'
                . '<g class="akic-root"><g class="akic-hoot-tilt">'
                . '<g class="akic-hoot-wing-l"><path class="akic-main akic-line" d="M11.5 33c-4.4 4.4-5 12-.6 17 2.6-4.6 2.8-11.2.6-17z"/></g>'
                . '<g class="akic-hoot-wing-r"><path class="akic-main akic-line" d="M52.5 33c4.4 4.4 5 12 .6 17-2.6-4.6-2.8-11.2-.6-17z"/></g>'
                . '<path class="akic-main akic-line" d="M14.5 21.5 11 7.5l12.4 6.8z"/><path class="akic-main akic-line" d="M49.5 21.5 53 7.5l-12.4 6.8z"/>'
                . '<path class="akic-main akic-line" d="M32 12c13 0 22 10 22 23.5 0 13-9 21.5-22 21.5s-22-8.5-22-21.5C10 22 19 12 32 12z"/>'
                . '<ellipse class="akic-eye akic-o35" cx="32" cy="45" rx="12" ry="9.5"/>'
                . '<path class="akic-line akic-nofill akic-thin akic-o60" d="M26.5 43l2 2 2-2M33.5 43l2 2 2-2M30 48l2 2 2-2"/>'
                . '<g class="akic-hoot-eye-l"><circle class="akic-eye akic-line akic-thin" cx="23.5" cy="29.5" r="8"/><g class="akic-hoot-pupil"><circle class="akic-face" cx="24.5" cy="30" r="4"/><circle class="akic-eye" cx="26" cy="28.4" r="1.3"/></g></g>'
                . '<g class="akic-hoot-eye-r"><circle class="akic-eye akic-line akic-thin" cx="40.5" cy="29.5" r="8"/><g class="akic-hoot-pupil"><circle class="akic-face" cx="41.5" cy="30" r="4"/><circle class="akic-eye" cx="43" cy="28.4" r="1.3"/></g></g>'
                . '<path class="akic-line akic-nofill akic-hoot-wink" d="M35.5 30c1.6 1.8 8.4 1.8 10 0"/>'
                . '<path class="akic-face" d="M29.4 37.2h5.2l-2.6 4.2z"/>'
                . '</g></g>',

            // 6. Support Buddy: headset helper. Typing dots, nods, raises brows, talks.
            'buddy' => '<ellipse class="akic-shadow" cx="32" cy="61.5" rx="15" ry="1.8"/>'
                . '<g class="akic-root"><g class="akic-buddy-nod">'
                . '<circle class="akic-main akic-line" cx="32" cy="35" r="20"/>'
                . '<path class="akic-shine" d="M17.5 30c1.6-5.6 5.6-9.6 11-11"/>'
                . '<path class="akic-line akic-nofill akic-thick" d="M12.5 34c0-12 8.6-21 19.5-21s19.5 9 19.5 21"/>'
                . '<rect class="akic-face akic-line akic-thin" x="8.5" y="28" width="7" height="13" rx="3.5"/><rect class="akic-face akic-line akic-thin" x="48.5" y="28" width="7" height="13" rx="3.5"/>'
                . '<path class="akic-line akic-nofill" d="M52 41c0 7.4-4.8 11.6-11.4 11.6"/><ellipse class="akic-acc akic-line akic-thin" cx="39" cy="52.6" rx="3" ry="2.4"/>'
                . '<ellipse class="akic-blush" cx="21" cy="40" rx="3" ry="1.9"/><ellipse class="akic-blush" cx="43" cy="40" rx="3" ry="1.9"/>'
                . '<g class="akic-buddy-brows akic-line akic-nofill akic-thin"><path d="M21.5 25.8q3-2.2 6 0"/><path d="M36.5 25.8q3-2.2 6 0"/></g>'
                . '<ellipse class="akic-face akic-blink" cx="24.5" cy="31.5" rx="2.6" ry="3.2"/><ellipse class="akic-face akic-blink" cx="39.5" cy="31.5" rx="2.6" ry="3.2"/>'
                . '<ellipse class="akic-face akic-buddy-mouth" cx="32" cy="41.5" rx="3.6" ry="2.4"/>'
                . '</g></g>'
                . '<g class="akic-buddy-typing"><path class="akic-eye akic-line akic-thin" d="M48 -3h13a5 5 0 0 1 5 5v2a5 5 0 0 1-5 5h-7l-4 3.5 .6-3.5H48a5 5 0 0 1-5-5V2a5 5 0 0 1 5-5z"/>'
                . '<circle class="akic-face akic-dot1" cx="49.5" cy="3" r="1.5"/><circle class="akic-face akic-dot2" cx="54.5" cy="3" r="1.5"/><circle class="akic-face akic-dot3" cx="59.5" cy="3" r="1.5"/></g>',
        );
    }

    /* ------------------------------------------------------------------
     * 20 more characters (1.6.1). Built from small helpers so every one
     * shares the same eye, blush and smile style. Generic motion classes
     * (akic-a-*) live in launcher-icons.css; per-part pivots are inline.
     * ------------------------------------------------------------------ */

    private static function eyes( $x1, $x2, $y, $rx = 3, $ry = 4.2, $look = true ) {
        $e = '';
        foreach ( array( $x1, $x2 ) as $x ) {
            $e .= '<ellipse class="akic-face akic-blink" cx="' . $x . '" cy="' . $y . '" rx="' . $rx . '" ry="' . $ry . '"/>'
                . '<circle class="akic-eye" cx="' . ( $x + $rx * 0.35 ) . '" cy="' . ( $y - $ry * 0.4 ) . '" r="' . round( $rx * 0.38, 2 ) . '"/>';
        }
        return $look ? '<g class="akic-a-look">' . $e . '</g>' : $e;
    }

    private static function blush( $x1, $x2, $y ) {
        return '<ellipse class="akic-blush" cx="' . $x1 . '" cy="' . $y . '" rx="3.2" ry="2"/><ellipse class="akic-blush" cx="' . $x2 . '" cy="' . $y . '" rx="3.2" ry="2"/>';
    }

    private static function smile( $cx, $y, $w = 3.4 ) {
        return '<path class="akic-line akic-nofill akic-thin" d="M' . ( $cx - $w ) . ' ' . $y . 'c' . round( $w * 0.6, 2 ) . ' ' . round( $w * 0.7, 2 ) . ' ' . round( $w * 1.4, 2 ) . ' ' . round( $w * 0.7, 2 ) . ' ' . ( $w * 2 ) . ' 0"/>';
    }

    private static function shadow( $rx = 15 ) {
        return '<ellipse class="akic-shadow" cx="32" cy="61.5" rx="' . $rx . '" ry="1.8"/>';
    }

    /** Fuzzy outline for the monster: a circle with soft spikes. */
    private static function fuzzy( $cx, $cy, $r1, $r2, $n ) {
        $d = '';
        for ( $i = 0; $i < $n * 2; $i++ ) {
            $a  = M_PI * $i / $n - M_PI / 2;
            $r  = $i % 2 ? $r2 : $r1;
            $d .= ( $i ? 'L' : 'M' ) . round( $cx + $r * cos( $a ), 2 ) . ' ' . round( $cy + $r * sin( $a ), 2 );
        }
        return $d . 'z';
    }

    private static function more_bodies() {
        $smile = function ( $cx, $y, $w = 3.4 ) {
            return self::smile( $cx, $y, $w );
        };
        $sh    = self::shadow();
        $o     = function ( $x, $y, $delay = null ) {
            return ' style="transform-origin:' . $x . '% ' . $y . '%' . ( null === $delay ? '' : ';animation-delay:' . $delay . 's' ) . '"';
        };
        $delay = function ( $s ) {
            return ' style="animation-delay:' . $s . 's"';
        };

        return array(

            // Red Panda: ears twitch, head tilts, cheeky grin.
            'redpanda' => $sh . '<g class="akic-root"><g class="akic-a-sway">'
                . '<g class="akic-a-twitch"' . $o( 70, 100 ) . '><path class="akic-main akic-line" d="M13 24c-2-8 1-14 8-15 4 0 7 3 8 7z"/><path class="akic-eye akic-o60" d="M16 20c-.5-4 1-7 4.4-7.4 2 0 3.4 1.6 4 3.4z"/></g>'
                . '<g class="akic-a-twitch"' . $o( 30, 100, -1.7 ) . '><path class="akic-main akic-line" d="M51 24c2-8-1-14-8-15-4 0-7 3-8 7z"/><path class="akic-eye akic-o60" d="M48 20c.5-4-1-7-4.4-7.4-2 0-3.4 1.6-4 3.4z"/></g>'
                . '<ellipse class="akic-main akic-line" cx="32" cy="35" rx="21" ry="19"/>'
                . '<path class="akic-shine" d="M17 28c1.4-4.6 5-8 9.6-9"/>'
                . '<path class="akic-eye" d="M18 33c3-1 6 0 8 3 1 3 0 6 6 7 6-1 5-4 6-7 2-3 5-4 8-3-1 9-6 15-14 15s-13-6-14-15z"/>'
                . '<path class="akic-eye akic-o60" d="M27 22c2-2 8-2 10 0-1 3-2 5-5 5s-4-2-5-5z"/>'
                . self::eyes( 24, 40, 32, 2.8, 3.6 )
                . '<ellipse class="akic-face" cx="32" cy="41.5" rx="2.6" ry="1.8"/>'
                . '<path class="akic-line akic-nofill akic-thin" d="M29 45c1.4 1.4 4.6 1.4 6 0"/>'
                . '</g></g>',

            // Bunny: hops, one ear flops, nose twitches, buck teeth.
            'bunny' => '<ellipse class="akic-shadow akic-a-hopshadow" cx="32" cy="61.5" rx="15" ry="1.8"/><g class="akic-root"><g class="akic-a-hop">'
                . '<g><ellipse class="akic-main akic-line" cx="24" cy="15" rx="5.2" ry="12.5"/><ellipse class="akic-eye akic-o60" cx="24" cy="16" rx="2.4" ry="8.5"/></g>'
                . '<g class="akic-a-flop"' . $o( 50, 95 ) . '><ellipse class="akic-main akic-line" cx="40" cy="15" rx="5.2" ry="12.5"/><ellipse class="akic-eye akic-o60" cx="40" cy="16" rx="2.4" ry="8.5"/></g>'
                . '<ellipse class="akic-main akic-line" cx="32" cy="40" rx="19" ry="17"/>'
                . '<path class="akic-shine" d="M17.5 34c1.4-4.4 4.6-7.4 9-8.4"/>'
                . self::blush( 19.5, 44.5, 44 ) . self::eyes( 25, 39, 37, 2.8, 3.8 )
                . '<path class="akic-face akic-a-sniff" d="M30 42.6h4l-2 2.2z"/>'
                . '<path class="akic-line akic-nofill akic-thin" d="M28.6 46.6c1.2 1 2.4 1 3.4 0 1 1 2.2 1 3.4 0"/>'
                . '<rect class="akic-eye akic-line akic-thin" x="30" y="47.4" width="4" height="3.6" rx="1"/><path class="akic-line akic-thin" d="M32 47.6v3.2"/>'
                . '</g></g>',

            // Fox: sly wink, fluffy tail wags, head tilt.
            'fox' => $sh . '<g class="akic-root">'
                . '<g class="akic-a-wag"' . $o( 10, 90 ) . '><path class="akic-main akic-line" d="M44 52c8 2 16-2 17-12 0-5-3-8-6-9 1 6-2 11-7 13-3 1-5 4-4 8z"/><path class="akic-eye" d="M58.6 33.4c1.8 1.6 2.6 3.8 2.4 6.6-.4 3.4-2 6-4.6 7.8 1-3.6 1.4-8.6 2.2-14.4z"/></g>'
                . '<g class="akic-a-tilt">'
                . '<path class="akic-main akic-line" d="M11 20 14 6l12 9c4-1.4 8-1.4 12 0l12-9 3 14c2 6 2 13-1 19-4 8-11 13-20 13s-16-5-20-13c-3-6-3-13-1-19z"/>'
                . '<path class="akic-face akic-o30" d="M15.5 17 16.6 10l6 4.6zM48.5 17l-1.1-7-6 4.6z"/>'
                . '<path class="akic-eye" d="M13 33c6 0 12 3 16 9 1 2 2 3 3 3s2-1 3-3c4-6 10-9 16-9-1 9-8 18-19 19-11-1-18-10-19-19z"/>'
                . '<path class="akic-shine" d="M18 24c1.6-3.4 4-5.6 7.4-6.6"/>'
                . '<g><ellipse class="akic-face akic-blink" cx="24" cy="30" rx="2.6" ry="3.4"/><circle class="akic-eye" cx="25" cy="28.8" r="1"/></g>'
                . '<g class="akic-a-wink"><ellipse class="akic-face" cx="40" cy="30" rx="2.6" ry="3.4"/><circle class="akic-eye" cx="41" cy="28.8" r="1"/></g>'
                . '<ellipse class="akic-face" cx="32" cy="44.5" rx="2.6" ry="2"/>'
                . '<path class="akic-line akic-nofill akic-thin" d="M29 48.4c1.6 1.2 4.4 1.2 6 0"/>'
                . '</g></g>',

            // Froggy: croaks (throat puffs), flicks its tongue at a fly.
            'froggy' => $sh . '<g class="akic-root"><g class="akic-a-squish">'
                . '<circle class="akic-main akic-line" cx="20" cy="24" r="8.4"/><circle class="akic-main akic-line" cx="44" cy="24" r="8.4"/>'
                . '<ellipse class="akic-main akic-line" cx="32" cy="42" rx="23" ry="15"/>'
                . '<path class="akic-main" d="M14 30c4-3 32-3 36 0v6H14z"/>'
                . '<circle class="akic-eye" cx="20" cy="24" r="5.2"/><circle class="akic-eye" cx="44" cy="24" r="5.2"/>'
                . '<g class="akic-a-look"><circle class="akic-face akic-blink" cx="21" cy="24.6" r="2.7"/><circle class="akic-face akic-blink" cx="45" cy="24.6" r="2.7"/></g>'
                . '<ellipse class="akic-eye akic-o35 akic-a-croak" cx="32" cy="50" rx="9" ry="5"/>'
                . self::blush( 16, 48, 42 )
                . '<path class="akic-line akic-nofill" d="M20 40c6 5 18 5 24 0"/>'
                . '<g class="akic-a-flick"' . $o( 0, 50 ) . '><path class="akic-tongue-s akic-nofill" d="M37 42.6c8-2 14-6 21-12"/><circle class="akic-tongue" cx="58" cy="30.6" r="2.4"/></g>'
                . '</g></g>'
                . '<g class="akic-a-buzz"><circle class="akic-face" cx="58" cy="10" r="1.8"/><ellipse class="akic-eye akic-line akic-thin akic-a-buzzwing" cx="56.6" cy="7.6" rx="1.8" ry="1.2"/><ellipse class="akic-eye akic-line akic-thin akic-a-buzzwing" cx="59.6" cy="7.6" rx="1.8" ry="1.2"/></g>',

            // Penguin: waddles side to side and flaps its flippers.
            'penguin' => $sh . '<g class="akic-root"><g class="akic-a-waddle">'
                . '<g class="akic-a-flap"' . $o( 90, 10 ) . '><path class="akic-main akic-line" d="M14 32c-5 4-7 11-5 16 4-2 7-7 7-12z"/></g>'
                . '<g class="akic-a-flap"' . $o( 10, 10, -0.15 ) . '><path class="akic-main akic-line" d="M50 32c5 4 7 11 5 16-4-2-7-7-7-12z"/></g>'
                . '<path class="akic-face akic-line" d="M22 56c-2 2-6 2-6 4h10zM42 56c2 2 6 2 6 4H38z"/>'
                . '<path class="akic-main akic-line" d="M32 9c11 0 18 9 18 22v12c0 9-8 15-18 15s-18-6-18-15V31c0-13 7-22 18-22z"/>'
                . '<path class="akic-eye" d="M32 22c7 0 12 6 12 15 0 8-5 15-12 15s-12-7-12-15c0-9 5-15 12-15z"/>'
                . '<path class="akic-shine" d="M19 27c1-5 4-9 8.4-10.6"/>'
                . self::eyes( 26, 38, 29, 2.4, 3.2 )
                . '<path class="akic-face akic-a-chirp"' . $o( 50, 0 ) . ' d="M28.5 34.5h7l-3.5 4.4z"/>'
                . self::blush( 22.5, 41.5, 36 )
                . '</g></g>',

            // Chick: pops out of its egg, wobbles the shell, tweets.
            'chick' => $sh . '<g class="akic-root">'
                . '<g class="akic-a-peek">'
                . '<g class="akic-a-flap"' . $o( 90, 30 ) . '><path class="akic-main akic-line" d="M16 34c-5 0-8 3-8 6 4 1 7-1 9-3z"/></g>'
                . '<g class="akic-a-flap"' . $o( 10, 30, -0.2 ) . '><path class="akic-main akic-line" d="M48 34c5 0 8 3 8 6-4 1-7-1-9-3z"/></g>'
                . '<circle class="akic-main akic-line" cx="32" cy="30" r="17"/>'
                . '<path class="akic-main akic-line" d="M30 13c-1-4 1-6 3-6 0 2 2 3 3 5"/>'
                . '<path class="akic-shine" d="M19 26c1.2-4 4-7 8-8"/>'
                . self::eyes( 25.5, 38.5, 28, 2.6, 3.4 ) . self::blush( 21, 43, 33 )
                . '<path class="akic-face akic-a-chirp"' . $o( 50, 100 ) . ' d="M28.5 33h7l-3.5-2.6z"/><path class="akic-face" d="M28.5 33.4h7l-3.5 3z"/>'
                . '</g>'
                . '<g class="akic-a-wobble"' . $o( 50, 100 ) . '><path class="akic-eye akic-line" d="M12 42l5-4 5 4 5-4 5 4 5-4 5 4 5-4 5 4c1 2 1 4 1 6 0 8-9 12-20 12s-20-4-20-12c0-2 0-4 1-6z"/>'
                . '<path class="akic-face akic-o25" d="M18 50l3 2M44 49l-2 3"/></g>'
                . '</g>',

            // Puppy: floppy ears bounce, tongue out panting, tail-less wiggle.
            'puppy' => $sh . '<g class="akic-root"><g class="akic-a-sway">'
                . '<g class="akic-a-earflop"' . $o( 50, 5 ) . '><path class="akic-face akic-line" d="M17 18c-6 1-9 9-8 17 1 5 5 6 8 3 2-5 3-12 4-17z"/></g>'
                . '<g class="akic-a-earflop akic-flip"' . $o( 50, 5 ) . '><path class="akic-face akic-line" d="M47 18c6 1 9 9 8 17-1 5-5 6-8 3-2-5-3-12-4-17z"/></g>'
                . '<ellipse class="akic-main akic-line" cx="32" cy="35" rx="18" ry="19"/>'
                . '<ellipse class="akic-face akic-o25" cx="40" cy="27" rx="5.4" ry="4.6"/>'
                . '<path class="akic-shine" d="M18 30c1.2-4.6 4-8 8.6-9.4"/>'
                . self::eyes( 25, 39, 31, 2.8, 3.6 )
                . '<ellipse class="akic-eye akic-o60" cx="32" cy="43" rx="8" ry="6"/>'
                . '<ellipse class="akic-face" cx="32" cy="39.4" rx="3.2" ry="2.3"/>'
                . '<path class="akic-line akic-nofill akic-thin" d="M28 43.6c2 1.6 6 1.6 8 0"/>'
                . '<g class="akic-a-pant"' . $o( 50, 0 ) . '><path class="akic-tongue akic-line akic-thin" d="M29.6 44.6h4.8v3.6c0 1.6-1.2 2.6-2.4 2.6s-2.4-1-2.4-2.6z"/></g>'
                . '</g></g>',

            // Bee: buzzes around in a loop, wings flutter.
            'bee' => '<g class="akic-root"><g class="akic-a-fly">'
                . '<g class="akic-a-buzzwing"' . $o( 80, 100 ) . '><ellipse class="akic-eye akic-line akic-thin akic-o80" cx="21" cy="16" rx="7" ry="9" transform="rotate(-25 21 16)"/></g>'
                . '<g class="akic-a-buzzwing"' . $o( 20, 100 ) . '><ellipse class="akic-eye akic-line akic-thin akic-o80" cx="43" cy="16" rx="7" ry="9" transform="rotate(25 43 16)"/></g>'
                . '<path class="akic-line akic-nofill akic-thin" d="M27 18c-2-4-4-6-7-7M37 18c2-4 4-6 7-7"/><circle class="akic-face" cx="20" cy="11" r="2"/><circle class="akic-face" cx="44" cy="11" r="2"/>'
                . '<circle class="akic-main akic-line" cx="32" cy="35" r="18"/>'
                . '<path class="akic-face-s akic-nofill" d="M14.4 40q17.6 7 35.2 0M17.6 47.4q14.4 5 28.8 0"/>'
                . '<circle class="akic-nofill akic-line" cx="32" cy="35" r="18"/>'
                . '<path class="akic-shine" d="M19 30c1.2-4.4 4.4-7.6 8.6-8.6"/>'
                . self::eyes( 25, 39, 30, 2.6, 3.4 ) . self::blush( 20, 44, 35 ) . $smile( 32, 34.6, 3 )
                . '</g></g>',

            // Octopus: bobs while every tentacle wiggles.
            'octopus' => $sh . '<g class="akic-root"><g class="akic-a-bob">'
                . self::tentacles()
                . '<path class="akic-main akic-line" d="M12 36c0-14 9-24 20-24s20 10 20 24c0 3-2 5-5 5H17c-3 0-5-2-5-5z"/>'
                . '<path class="akic-shine" d="M18 28c1.4-5 5-9 10-10.4"/>'
                . '<circle class="akic-eye akic-o35" cx="40" cy="20" r="2.2"/><circle class="akic-eye akic-o35" cx="44" cy="25" r="1.4"/>'
                . self::eyes( 25, 39, 30, 2.8, 3.8 ) . self::blush( 20, 44, 35 ) . $smile( 32, 35, 3 )
                . '</g></g>',

            // Alien: one big eye looks around, antennae bounce.
            'alien' => $sh . '<g class="akic-root"><g class="akic-a-bob">'
                . '<g class="akic-a-wiggle"' . $o( 100, 100 ) . '><path class="akic-line akic-nofill" d="M24 14c-2-4-4-7-8-8"/><circle class="akic-acc akic-line akic-thin akic-a-blinklight" cx="15" cy="5.6" r="3"/></g>'
                . '<g class="akic-a-wiggle"' . $o( 0, 100, -0.8 ) . '><path class="akic-line akic-nofill" d="M40 14c2-4 4-7 8-8"/><circle class="akic-acc akic-line akic-thin akic-a-blinklight"' . $delay( -0.6 ) . ' cx="49" cy="5.6" r="3"/></g>'
                . '<path class="akic-main akic-line" d="M32 11c12 0 20 8 20 19 0 13-9 25-20 25S12 43 12 30c0-11 8-19 20-19z"/>'
                . '<path class="akic-shine" d="M18 25c1.4-4.6 5-8 9.6-9.4"/>'
                . '<g class="akic-a-blink-slow"><circle class="akic-eye akic-line akic-thin" cx="32" cy="29" r="9"/><g class="akic-a-lookaround"><circle class="akic-face" cx="32" cy="29.6" r="4.6"/><circle class="akic-eye" cx="33.8" cy="27.6" r="1.6"/></g></g>'
                . self::blush( 20, 44, 40 ) . '<path class="akic-face" d="M27.5 42c1.4 3.6 7.6 3.6 9 0z"/>'
                . '</g></g>',

            // UFO: hovers and tilts, lights blink in turn, beam pulses.
            'ufo' => '<path class="akic-eye akic-a-beam" d="M24 44h16l7 16H17z"/>'
                . '<g class="akic-root"><g class="akic-a-hover">'
                . '<path class="akic-eye akic-o80 akic-line" d="M19 37c0-9 6-15 13-15s13 6 13 15z"/>'
                . self::eyes( 28, 36, 32, 1.8, 2.4, false )
                . '<path class="akic-line akic-nofill akic-thin" d="M30.6 35.2c.8.6 2 .6 2.8 0"/>'
                . '<ellipse class="akic-main akic-line" cx="32" cy="40" rx="25" ry="7.5"/>'
                . '<path class="akic-shine" d="M14 38c4-2 10-3 16-3.4"/>'
                . '<circle class="akic-eye akic-a-blinklight" cx="18" cy="41" r="1.8"/><circle class="akic-eye akic-a-blinklight"' . $delay( -0.4 ) . ' cx="32" cy="43" r="1.8"/><circle class="akic-eye akic-a-blinklight"' . $delay( -0.8 ) . ' cx="46" cy="41" r="1.8"/>'
                . '</g></g>',

            // Rocket: rumbles, flame flickers, little face in the window.
            'rocket' => '<g class="akic-root"><g class="akic-a-launch">'
                . '<g class="akic-a-flicker"' . $o( 50, 0 ) . '><path class="akic-acc akic-line akic-thin" d="M24 50c0 6 4 10 8 14 4-4 8-8 8-14z"/><path class="akic-eye" d="M28 50c0 4 2 7 4 9 2-2 4-5 4-9z"/></g>'
                . '<path class="akic-main akic-line" d="M22 38l-8 8v6l9-4zM42 38l8 8v6l-9-4z"/>'
                . '<path class="akic-main akic-line" d="M32 4c8 6 11 15 11 26v19H21V30c0-11 3-20 11-26z"/>'
                . '<path class="akic-face akic-o25" d="M24 46h16v3H24z"/>'
                . '<path class="akic-shine" d="M26 18c1-3 2.4-5.4 4.4-7.4"/>'
                . '<circle class="akic-eye akic-line" cx="32" cy="27" r="7"/>'
                . self::eyes( 29.4, 34.6, 26.4, 1.5, 2, false ) . $smile( 32, 29.6, 1.8 )
                . '</g></g>',

            // Fluffy: a cloud that floats and lets little drops fall.
            'cloud' => '<circle class="akic-eye akic-line akic-thin akic-a-drop" cx="24" cy="54" r="1.8"/><circle class="akic-eye akic-line akic-thin akic-a-drop"' . $delay( -0.7 ) . ' cx="32" cy="56" r="1.8"/><circle class="akic-eye akic-line akic-thin akic-a-drop"' . $delay( -1.3 ) . ' cx="40" cy="54" r="1.8"/>'
                . '<g class="akic-root"><g class="akic-a-bob">'
                . '<path class="akic-main akic-line" d="M17 48h31c6 0 10-4 10-10s-4-10-10-10h-1c0-8-6-14-14-14-6 0-11 4-13 9-1 0-2-.4-3-.4-6 0-11 5-11 11.4 0 7 5 14 11 14z"/>'
                . '<path class="akic-shine" d="M13 33c1-3 3-5 6-6M24 21c2-2 5-3 8-3"/>'
                . self::eyes( 26, 40, 35, 2.6, 3.4 ) . self::blush( 21, 45, 40 ) . $smile( 33, 40, 3 )
                . '</g></g>',

            // Cool Sun: rays spin, sunglasses shine, big grin.
            'sun' => '<g class="akic-root">'
                . '<g class="akic-a-spin">' . self::rays() . '</g>'
                . '<g class="akic-a-bob">'
                . '<circle class="akic-main akic-line" cx="32" cy="32" r="15"/>'
                . '<path class="akic-shine" d="M21 28c1-3.6 3.4-6.2 6.8-7.4"/>'
                . '<path class="akic-face akic-line akic-thin" d="M19.5 27h11v3.4c0 2.6-2 4.6-5 4.6s-6-2-6-4.6zM33.5 27h11v3.4c0 2.6-3 4.6-6 4.6s-5-2-5-4.6z"/>'
                . '<path class="akic-eye akic-a-glint" d="M21.5 28.6l3.4 0-2 3zM35.5 28.6l3.4 0-2 3z"/>'
                . '<path class="akic-face" d="M25.5 38.5c2.4 4.6 10.6 4.6 13 0z"/>'
                . '</g></g>',

            // Love: a heart that beats, with little hearts floating up.
            'heart' => $sh . '<path class="akic-main akic-line akic-thin akic-a-rise" d="M50 14c-2-2-5-1-5 1.4 0 2 3 4 5 5.4 2-1.4 5-3.4 5-5.4 0-2.4-3-3.4-5-1.4z"/>'
                . '<path class="akic-main akic-line akic-thin akic-a-rise"' . $delay( -1.5 ) . ' d="M13 18c-1.6-1.6-4-.8-4 1.2 0 1.6 2.4 3.2 4 4.4 1.6-1.2 4-2.8 4-4.4 0-2-2.4-2.8-4-1.2z"/>'
                . '<g class="akic-root"><g class="akic-a-beat">'
                . '<path class="akic-main akic-line" d="M32 54C14 42 9 33 9 24a11 11 0 0 1 23-6 11 11 0 0 1 23 6c0 9-5 18-23 30z"/>'
                . '<path class="akic-shine" d="M14 24c.4-3.6 3-6.4 6.6-7"/>'
                . self::eyes( 25, 39, 30, 2.6, 3.4 ) . self::blush( 20, 44, 35 ) . $smile( 32, 35, 3 )
                . '</g></g>',

            // Donut: swings side to side, sprinkles twinkle, face on the dough.
            'donut' => $sh . '<g class="akic-root"><g class="akic-a-roll">'
                . '<path class="akic-main akic-line" fill-rule="evenodd" d="M32 13a21 21 0 1 1 0 42a21 21 0 1 1 0-42zM32 23a5 5 0 1 0 0 10a5 5 0 1 0 0-10z"/>'
                . '<path class="akic-eye akic-line akic-thin" fill-rule="evenodd" d="M15 30c0-9 8-15 17-15s17 6 17 15c0 3-2 4-3.4 4-1.6 0-1.6 3-3.6 3s-2.4-2.6-4-2.6c-2 0-2.4 4-5.4 4s-3-4-5-4c-1.6 0-2 2.6-4 2.6s-2-3-3.6-3C17 34 15 33 15 30zM32 23a5 5 0 1 0 0 10a5 5 0 1 0 0-10z"/>'
                . '<g class="akic-a-glint"><path class="akic-face-s akic-sprinkle" d="M21 25l2-1.6M41 20l2.4 1M45 27l.6 2.2M26 19.4l2.2-.4"/><path class="akic-acc-s akic-sprinkle" d="M19.6 29.6l.6 2.2M37 18l2.4.6M41.6 30.4l2-1.4M23 21.4l1 2"/></g>'
                . self::eyes( 25, 39, 43.5, 2.2, 3 )
                . '<path class="akic-face" d="M29 47.6c1.4 2.6 4.6 2.6 6 0z"/>' . self::blush( 20.5, 43.5, 47 )
                . '</g></g>',

            // Chai: a steaming cup that winks. Made for desi websites.
            'chai' => '<path class="akic-line akic-nofill akic-o60 akic-a-steam" d="M24 16c-3-3 3-5 0-9"/><path class="akic-line akic-nofill akic-o60 akic-a-steam"' . $delay( -0.8 ) . ' d="M32 15c-3-3 3-5 0-9"/><path class="akic-line akic-nofill akic-o60 akic-a-steam"' . $delay( -1.6 ) . ' d="M40 16c-3-3 3-5 0-9"/>'
                . '<g class="akic-root"><g class="akic-a-sway">'
                . '<ellipse class="akic-main akic-line" cx="32" cy="55" rx="24" ry="4.4"/>'
                . '<path class="akic-line akic-nofill akic-thick" d="M48 30c6-1 9 2 9 6s-4 7-10 7"/><path class="akic-main-s akic-nofill" d="M48 30c6-1 9 2 9 6s-4 7-10 7"/>'
                . '<path class="akic-main akic-line" d="M12 22h40v14c0 10-9 18-20 18S12 46 12 36z"/>'
                . '<ellipse class="akic-face akic-line" cx="32" cy="22" rx="20" ry="4"/>'
                . '<path class="akic-shine" d="M17 29c0 4 1 8 3 11"/>'
                . '<g><ellipse class="akic-face akic-blink" cx="25" cy="34" rx="2.4" ry="3.2"/><circle class="akic-eye" cx="25.8" cy="32.8" r=".9"/></g>'
                . '<path class="akic-line akic-nofill akic-a-wink-line" d="M36.6 34.4c1.2-1.6 3.6-1.6 4.8 0"/>'
                . '<g class="akic-a-wink-open"><ellipse class="akic-face" cx="39" cy="34" rx="2.4" ry="3.2"/><circle class="akic-eye" cx="39.8" cy="32.8" r=".9"/></g>'
                . self::blush( 21, 43, 39 ) . $smile( 32, 40, 3 )
                . '</g></g>',

            // Retro Robo: scanning eye bar, blinking antenna, bounces.
            'robo' => $sh . '<g class="akic-root"><g class="akic-a-hop">'
                . '<path class="akic-line akic-nofill" d="M32 13V7"/><circle class="akic-acc akic-line akic-a-blinklight" cx="32" cy="5" r="3"/>'
                . '<rect class="akic-face akic-line akic-thin" x="8" y="26" width="5" height="12" rx="2"/><rect class="akic-face akic-line akic-thin" x="51" y="26" width="5" height="12" rx="2"/>'
                . '<rect class="akic-main akic-line" x="12" y="13" width="40" height="38" rx="8"/>'
                . '<path class="akic-shine" d="M17 22c.4-2.6 2-4.4 4.6-5"/>'
                . '<rect class="akic-face" x="18" y="23" width="28" height="11" rx="5.5"/>'
                . '<rect class="akic-eye akic-a-scan" x="20" y="26" width="9" height="5" rx="2.5"/>'
                . '<rect class="akic-face" x="22" y="39" width="20" height="7" rx="2"/><path class="akic-eye-s akic-thin akic-a-talkbars" d="M26 41v3M30 41v3M34 41v3M38 41v3"/>'
                . '</g></g>',

            // Dino: little T-rex arms wave, spikes wiggle, ROAR.
            'dino' => $sh . '<g class="akic-root"><g class="akic-a-roarshake">'
                . '<path class="akic-main akic-line akic-a-wiggle"' . $o( 50, 100 ) . ' d="M23 17l3-9 5 7zM30 14l3-10 5 9zM38 16l4-8 3 9z"/>'
                . '<g class="akic-a-wag"' . $o( 100, 20 ) . '><path class="akic-main akic-line" d="M15 42c-4 0-6 2-6 4 3 1 5 0 7-1"/></g>'
                . '<g class="akic-a-wag"' . $o( 0, 20, -0.45 ) . '><path class="akic-main akic-line" d="M49 42c4 0 6 2 6 4-3 1-5 0-7-1"/></g>'
                . '<path class="akic-main akic-line" d="M14 36c0-13 8-22 18-22s18 9 18 22c0 10-8 19-18 19s-18-9-18-19z"/>'
                . '<path class="akic-shine" d="M19 29c1-4 4-7 8-8"/>'
                . '<circle class="akic-eye akic-o35" cx="22" cy="44" r="2"/><circle class="akic-eye akic-o35" cx="44" cy="40" r="1.6"/>'
                . self::eyes( 25, 39, 29, 2.6, 3.4 )
                . '<circle class="akic-face" cx="29" cy="36" r=".9"/><circle class="akic-face" cx="35" cy="36" r=".9"/>'
                . '<g class="akic-a-roar"' . $o( 50, 0 ) . '><path class="akic-face" d="M25 40h14c0 5-3 8-7 8s-7-3-7-8z"/><path class="akic-eye" d="M27 40l1.4 2.4L30 40zM34 40l1.6 2.4L37 40z"/></g>'
                . '</g></g>',

            // Monster: fuzzy one-eyed troublemaker, sticks its tongue out.
            'monster' => $sh . '<g class="akic-root"><g class="akic-a-jiggle">'
                . '<path class="akic-face akic-line akic-thin" d="M18 18l-4-10 9 6zM46 18l4-10-9 6z"/>'
                . '<path class="akic-main akic-line" d="' . self::fuzzy( 32, 35, 21, 19, 18 ) . '"/>'
                . '<path class="akic-shine" d="M18 30c1.2-4.6 4.4-8 9-9.4"/>'
                . '<g class="akic-a-blink-slow"><circle class="akic-eye akic-line akic-thin" cx="32" cy="30" r="8"/><g class="akic-a-lookaround"><circle class="akic-face" cx="32" cy="30.4" r="4"/><circle class="akic-eye" cx="33.6" cy="28.6" r="1.4"/></g></g>'
                . self::blush( 19, 45, 38 )
                . '<path class="akic-face" d="M23 40c3 7 15 7 18 0z"/><path class="akic-eye" d="M25 40.2l1.6 2 1.6-2zM36 40.2l1.6 2 1.6-2z"/>'
                . '<g class="akic-a-tongue"' . $o( 50, 0 ) . '><g class="akic-a-tonguewag"' . $o( 50, 0 ) . '><path class="akic-tongue akic-line akic-thin" d="M29 44h6v4c0 2-1.4 3.4-3 3.4s-3-1.4-3-3.4z"/></g></g>'
                . '</g></g>',
        );
    }

    /** Octopus legs: five wiggling tentacles. */
    private static function tentacles() {
        $out = '';
        foreach ( array( array( 16, -0.2 ), array( 24, -0.7 ), array( 32, -0.4 ), array( 40, -0.9 ), array( 48, -0.1 ) ) as $i => $leg ) {
            $x    = $leg[0];
            $dir  = $x < 32 ? -1 : ( $x > 32 ? 1 : 0 );
            $d    = 'M' . $x . ' 38q' . ( $dir * 3 - 3 ) . ' 9 ' . ( $dir * 5 + 1 ) . ' 16';
            $out .= '<g class="akic-a-wiggle" style="transform-origin:50% 0%;animation-delay:' . $leg[1] . 's"><path class="akic-line akic-nofill" style="stroke-width:7.6" d="' . $d . '"/><path class="akic-main-s akic-nofill" style="stroke-width:4.4" d="' . $d . '"/></g>';
        }
        return $out;
    }

    /** Sun rays: eight rounded triangles. */
    private static function rays() {
        $out = '';
        for ( $i = 0; $i < 8; $i++ ) {
            $out .= '<path class="akic-main akic-line" transform="rotate(' . ( $i * 45 ) . ' 32 32)" d="M28.6 15.4 32 6.6l3.4 8.8z"/>';
        }
        return $out;
    }

    /* ------------------------------------------------------------------
     * 10 professional chatbot icons (1.6.2): calm motion, clean faces.
     * ------------------------------------------------------------------ */

    private static function pro_bodies() {
        $sh = self::shadow();
        $o  = function ( $x, $y, $delay = null ) {
            return ' style="transform-origin:' . $x . '% ' . $y . '%' . ( null === $delay ? '' : ';animation-delay:' . $delay . 's' ) . '"';
        };
        $d  = function ( $s ) {
            return ' style="animation-delay:' . $s . 's"';
        };
        $bubble = 'M20 12h24c6.6 0 12 5.4 12 12v14c0 6.6-5.4 12-12 12H27.5l-9.3 7.4c-1.3 1-3.2.1-3.2-1.6V49.1C10.3 47.4 8 43.1 8 38V24c0-6.6 5.4-12 12-12z';

        return array(

            // Typing Bubble: three typing dots, then a smiling face.
            'typing' => $sh . '<g class="akic-root"><g class="akic-a-bob">'
                . '<path class="akic-main akic-line" d="' . $bubble . '"/>'
                . '<path class="akic-shine" d="M14.5 22.5c.8-3.6 3.6-6 7.3-6h8"/>'
                . '<g class="akic-a-phase-a"><circle class="akic-face akic-a-dot" cx="22" cy="31" r="3.2"/><circle class="akic-face akic-a-dot"' . $d( 0.15 ) . ' cx="32" cy="31" r="3.2"/><circle class="akic-face akic-a-dot"' . $d( 0.3 ) . ' cx="42" cy="31" r="3.2"/></g>'
                . '<g class="akic-a-phase-b"><rect class="akic-face" x="23" y="25" width="4.6" height="7.4" rx="2.3"/><rect class="akic-face" x="36.4" y="25" width="4.6" height="7.4" rx="2.3"/><path class="akic-line akic-nofill" style="stroke:var(--akic-face)" d="M26 37c3.4 3 8.6 3 12 0"/></g>'
                . '</g></g>',

            // AI Spark: round chat bubble with a turning AI sparkle.
            'spark' => $sh . '<g class="akic-root"><g class="akic-a-bob">'
                . '<path class="akic-main akic-line" d="M32 10c12.7 0 23 9.4 23 21s-10.3 21-23 21c-3 0-5.8-.5-8.4-1.4L12.4 55l2.8-10.2C11.4 40.8 9 36.2 9 31 9 19.4 19.3 10 32 10z"/>'
                . '<path class="akic-shine" d="M15.6 24c1.8-5 6-8.6 11.4-9.6"/>'
                . '<path class="akic-face akic-a-sparkle" d="M32 19c1.2 6.4 3.6 8.8 10 10-6.4 1.2-8.8 3.6-10 10-1.2-6.4-3.6-8.8-10-10 6.4-1.2 8.8-3.6 10-10z"/>'
                . '<path class="akic-eye akic-line akic-thin akic-a-sparkle"' . $d( -0.8 ) . ' d="M43 15.6c.5 2.4 1.4 3.3 3.8 3.8-2.4.5-3.3 1.4-3.8 3.8-.5-2.4-1.4-3.3-3.8-3.8 2.4-.5 3.3-1.4 3.8-3.8z"/>'
                . '</g></g>',

            // Conversation: two chat bubbles talking to each other.
            'convo' => $sh . '<g class="akic-root">'
                . '<g class="akic-a-pop"' . $o( 20, 100 ) . '><path class="akic-face akic-line" d="M31 6h21c4.4 0 8 3.6 8 8v9c0 4.4-3.6 8-8 8h-1.4l.4 6-7-6H31c-4.4 0-8-3.6-8-8v-9c0-4.4 3.6-8 8-8z"/>'
                . '<circle class="akic-eye akic-a-dot" cx="34" cy="18.6" r="2"/><circle class="akic-eye akic-a-dot"' . $d( 0.15 ) . ' cx="41.5" cy="18.6" r="2"/><circle class="akic-eye akic-a-dot"' . $d( 0.3 ) . ' cx="49" cy="18.6" r="2"/></g>'
                . '<g class="akic-a-bob"><path class="akic-main akic-line" d="M11 26h23c4.4 0 8 3.6 8 8v11c0 4.4-3.6 8-8 8H21l-8.4 6.4.8-6.4H11c-4.4 0-8-3.6-8-8V34c0-4.4 3.6-8 8-8z"/>'
                . '<path class="akic-shine" d="M8 34c.4-2.4 2-4 4.4-4.4"/>'
                . '<rect class="akic-face akic-blink" x="14" y="33.6" width="3.6" height="5.6" rx="1.8"/><rect class="akic-face akic-blink" x="27.4" y="33.6" width="3.6" height="5.6" rx="1.8"/>'
                . '<path class="akic-nofill" style="stroke:var(--akic-face);stroke-width:2.2;stroke-linecap:round" d="M18.4 43.6c2.4 2 5.8 2 8.2 0"/></g>'
                . '</g>',

            // Visor Bot: sleek helmet, glowing eyes glide behind the visor.
            'visor' => $sh . '<g class="akic-root"><g class="akic-a-bob">'
                . '<path class="akic-acc akic-line akic-thin akic-a-blinklight" d="M29 9.6 32 4l3 5.6z"/>'
                . '<rect class="akic-face akic-line akic-thin" x="7" y="27" width="6" height="14" rx="3"/><rect class="akic-face akic-line akic-thin" x="51" y="27" width="6" height="14" rx="3"/>'
                . '<path class="akic-main akic-line" d="M32 10c12.7 0 22 9 22 21.6V40c0 8-6 14-14 14H24c-8 0-14-6-14-14v-8.4C10 19 19.3 10 32 10z"/>'
                . '<path class="akic-shine" d="M16 24c1.6-4.6 5.4-8 10.4-9.4"/>'
                . '<rect class="akic-face" x="15" y="26" width="34" height="13" rx="6.5"/>'
                . '<g class="akic-a-glide"><rect class="akic-eye akic-blink" x="22" y="29.4" width="7" height="6" rx="3"/><rect class="akic-eye akic-blink" x="35" y="29.4" width="7" height="6" rx="3"/></g>'
                . '<path class="akic-line akic-nofill akic-thin akic-o60" d="M27 46h10"/>'
                . '</g></g>',

            // Orbit Bot: round head with a rocking ring and a pulsing moon.
            'orbit' => $sh . '<g class="akic-root"><g class="akic-a-rock">'
                . '<path class="akic-nofill" style="stroke:var(--akic-ink);stroke-width:5" d="M5 35a27 7.5 0 0 1 54 0"/><path class="akic-nofill" style="stroke:var(--akic-accent);stroke-width:2.4" d="M5 35a27 7.5 0 0 1 54 0"/>'
                . '<circle class="akic-main akic-line" cx="32" cy="33" r="18"/>'
                . '<path class="akic-shine" d="M19 28c1.4-4.6 5-8 9.6-9.2"/>'
                . '<g class="akic-a-look"><ellipse class="akic-face akic-blink" cx="25.6" cy="31" rx="2.6" ry="3.4"/><ellipse class="akic-face akic-blink" cx="38.4" cy="31" rx="2.6" ry="3.4"/></g>'
                . self::smile( 32, 37.4, 3 )
                . '<path class="akic-nofill" style="stroke:var(--akic-ink);stroke-width:5;stroke-linecap:round" d="M59 35a27 7.5 0 0 1-54 0"/><path class="akic-nofill" style="stroke:var(--akic-accent);stroke-width:2.4;stroke-linecap:round" d="M59 35a27 7.5 0 0 1-54 0"/>'
                . '<circle class="akic-eye akic-line akic-thin akic-a-pulse-soft" cx="10" cy="40" r="3"/>'
                . '</g></g>',

            // Voice Bot: talking sound-wave mouth for voice assistants.
            'voice' => $sh . '<g class="akic-root"><g class="akic-a-bob">'
                . '<rect class="akic-face akic-line akic-thin" x="7.5" y="26" width="6" height="13" rx="3"/><rect class="akic-face akic-line akic-thin" x="50.5" y="26" width="6" height="13" rx="3"/>'
                . '<rect class="akic-main akic-line" x="12" y="12" width="40" height="40" rx="13"/>'
                . '<path class="akic-shine" d="M17.4 24c.8-3.4 3.2-6 6.6-6.8"/>'
                . '<g class="akic-a-look"><ellipse class="akic-face akic-blink" cx="25" cy="27" rx="2.6" ry="3.4"/><ellipse class="akic-face akic-blink" cx="39" cy="27" rx="2.6" ry="3.4"/></g>'
                . '<rect class="akic-face" x="19" y="34" width="26" height="11" rx="5.5"/>'
                . '<g style="stroke:var(--akic-accent);stroke-width:2.2;stroke-linecap:round">'
                . '<path class="akic-a-eq" d="M24 37.4v4.2"/><path class="akic-a-eq"' . $d( -0.2 ) . ' d="M28 36.6v5.8"/><path class="akic-a-eq"' . $d( -0.45 ) . ' d="M32 36v7"/><path class="akic-a-eq"' . $d( -0.1 ) . ' d="M36 36.6v5.8"/><path class="akic-a-eq"' . $d( -0.35 ) . ' d="M40 37.4v4.2"/>'
                . '</g></g></g>',

            // Cube Bot: friendly 3D cube with an antenna, hops and blinks.
            'cube' => $sh . '<g class="akic-root"><g class="akic-a-hop">'
                . '<path class="akic-line akic-nofill" d="M33 18.6V10"/><circle class="akic-acc akic-line akic-thin akic-a-blinklight" cx="33" cy="8" r="2.8"/>'
                . '<path class="akic-main akic-line" d="M12 24l8-8h32l-8 8z"/><path class="akic-eye akic-o35" d="M15.4 22.6l5.6-5.4h27l-5.6 5.4z"/>'
                . '<path class="akic-main akic-line" d="M44 24l8-8v30l-8 8z"/><path class="akic-face akic-o25" d="M45.6 25l5-5v25.6l-5 5z"/>'
                . '<rect class="akic-main akic-line" x="12" y="24" width="32" height="30" rx="3"/>'
                . '<rect class="akic-face" x="16" y="29" width="24" height="15" rx="5"/>'
                . '<g class="akic-a-look"><rect class="akic-eye akic-blink" x="20.6" y="32.4" width="4" height="6" rx="2"/><rect class="akic-eye akic-blink" x="31.4" y="32.4" width="4" height="6" rx="2"/></g>'
                . '<path class="akic-line akic-nofill akic-thin" d="M24 49h8"/>'
                . '</g></g>',

            // Capsule Bot: floating pill-shaped assistant that waves.
            'capsule' => $sh . '<g class="akic-root"><g class="akic-a-bob">'
                . '<g class="akic-a-wag"' . $o( 100, 50 ) . '><path class="akic-main akic-line" d="M19 33c-6-1-10-5-10-10 0-2 2-3 3.4-1.6 1.6 2 3.6 4 6.6 4.6"/></g>'
                . '<path class="akic-main akic-line" d="M45 33c4 1 7 4 7 8 0 1.6-1.6 2.4-2.8 1.2-1.2-1.4-2.6-2.6-4.2-3.2"/>'
                . '<rect class="akic-main akic-line" x="19" y="8" width="26" height="48" rx="13"/>'
                . '<path class="akic-shine" d="M23.6 20c.4-3.4 2.4-6 5.4-7"/>'
                . '<rect class="akic-face" x="22" y="18" width="20" height="14" rx="7"/>'
                . '<g class="akic-a-look"><ellipse class="akic-eye akic-blink" cx="28" cy="25" rx="2" ry="2.8"/><ellipse class="akic-eye akic-blink" cx="36" cy="25" rx="2" ry="2.8"/></g>'
                . '<circle class="akic-acc akic-line akic-thin akic-a-blinklight" cx="32" cy="41" r="3"/><path class="akic-line akic-nofill akic-thin akic-o60" d="M27 48h10"/>'
                . '</g></g>',

            // Live Bot: always-online bot with pulsing signal rings.
            'live' => $sh . '<circle class="akic-nofill akic-a-ring" style="stroke:var(--akic-accent);stroke-width:2" cx="32" cy="32" r="18"/><circle class="akic-nofill akic-a-ring" style="stroke:var(--akic-accent);stroke-width:2;animation-delay:-1s" cx="32" cy="32" r="18"/>'
                . '<g class="akic-root"><g class="akic-a-bob">'
                . '<circle class="akic-main akic-line" cx="32" cy="32" r="19"/>'
                . '<path class="akic-shine" d="M18.6 27c1.4-4.6 5-8 9.6-9.2"/>'
                . '<rect class="akic-face" x="18" y="24" width="28" height="14" rx="7"/>'
                . '<g class="akic-a-look"><ellipse class="akic-eye akic-blink" cx="26" cy="31" rx="2.4" ry="3.2"/><ellipse class="akic-eye akic-blink" cx="38" cy="31" rx="2.4" ry="3.2"/></g>'
                . self::smile( 32, 42.6, 3 )
                . '<circle class="akic-online akic-line akic-thin" cx="47" cy="45" r="4.4"/>'
                . '</g></g>',

            // Help Bot: a "?" pops up, then turns into a tick: question answered.
            'helpbot' => $sh . '<g class="akic-root"><g class="akic-a-bob">'
                . '<path class="akic-line akic-nofill" d="M24 20v-6"/><circle class="akic-acc akic-line akic-thin" cx="24" cy="12" r="2.6"/>'
                . '<rect class="akic-main akic-line" x="8" y="20" width="40" height="34" rx="11"/>'
                . '<path class="akic-shine" d="M13 31c.6-3.6 3-6.2 6.4-7.2"/>'
                . '<rect class="akic-face" x="14" y="28" width="28" height="13" rx="6.5"/>'
                . '<g class="akic-a-look"><ellipse class="akic-eye akic-blink" cx="22" cy="34.4" rx="2.4" ry="3.2"/><ellipse class="akic-eye akic-blink" cx="34" cy="34.4" rx="2.4" ry="3.2"/></g>'
                . self::smile( 28, 46, 3 )
                . '</g></g>'
                . '<g class="akic-a-pop"' . $o( 10, 100 ) . '><path class="akic-eye akic-line" d="M48 2h8a6 6 0 0 1 6 6v6a6 6 0 0 1-6 6h-3l-7 5 1.4-5A6 6 0 0 1 42 14V8a6 6 0 0 1 6-6z"/>'
                . '<path class="akic-a-phase-a akic-nofill" style="stroke:var(--akic-face);stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round" d="M49.4 8.6c0-2 1.4-3 3-3s3 1 3 2.8c0 2.4-3 2.4-3 4.6"/><circle class="akic-face akic-a-phase-a" cx="52.4" cy="15.4" r="1.2"/>'
                . '<path class="akic-a-phase-b akic-nofill" style="stroke:var(--akic-face);stroke-width:2.6;stroke-linecap:round;stroke-linejoin:round" d="M48.6 11.4l2.8 2.8 5-5.6"/></g>',
        );
    }
}
