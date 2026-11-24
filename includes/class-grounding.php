<?php
/**
 * Answer check: compares facts in an AI reply (links, emails, phone numbers,
 * prices) with the knowledge that was given to the AI. Links must be in the
 * knowledge or exist on this website; emails and phone numbers must match a
 * value from the knowledge exactly; prices must be a price from the knowledge
 * (labelled "Price: ..." or written with a currency) in a compatible currency,
 * and belong to the product named in the same sentence. Anything unsupported
 * is removed or replaced.
 *
 * This is a safety net for these four kinds of detail only. It does not check
 * whether ordinary sentences are correct.
 *
 * @package AK_AI_Chatbot
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_AI_Chatbot_Grounding {

    const PHONE_RE = '/(?<![\w.,])\+?\d[\d\s\-()]{5,}\d(?![\w.,]\d)/';
    /** One number: 1500, 1,500, 1,50,000 (lakh style) or 12.50. "1,2,3" is three numbers. */
    const NUM_RE   = '(?:\d{1,3}(?:,\d{2,3})+(?:\.\d+)?|\d+(?:\.\d+)?)';
    const PRICE_RE = '/(?:rs\.?|pkr|usd|aed|sar|inr|gbp|eur|\$|€|£|₹)\s?(?:\d{1,3}(?:,\d{2,3})+(?:\.\d+)?|\d+(?:\.\d+)?)|(?:\d{1,3}(?:,\d{2,3})+(?:\.\d+)?|\d+(?:\.\d+)?)\s?(?:rs\.?|pkr|rupees|usd|dollars|aed|sar|inr|gbp|eur)\b/iu';
    const EMAIL_RE = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i';

    /**
     * @param string $reply   AI reply.
     * @param string $sources Everything the AI was allowed to use (system prompt + retrieved knowledge).
     * @return array array( 'reply' => string, 'flags' => string[] )
     */
    public static function check( $reply, $sources ) {
        $flags   = array();
        $sources = (string) $sources;
        $numbers = self::numbers( $sources );
        $phones  = self::phones( $sources );
        $urls    = self::urls( $sources );
        $emails  = array();
        if ( preg_match_all( self::EMAIL_RE, $sources, $m ) ) {
            $emails = array_flip( array_map( 'strtolower', $m[0] ) );
        }

        // 1. Links: only links that appear in the knowledge or that really exist on this website.
        $url_ok = function ( $url ) use ( $urls ) {
            return self::url_known( $url, $urls );
        };
        // Markdown links and images: [text](url) / ![alt](url).
        $reply = preg_replace_callback(
            '/(!?)\[([^\]]*)\]\((https?:\/\/[^\s)]+)\)/i',
            function ( $m ) use ( $url_ok, &$flags ) {
                if ( $url_ok( $m[3] ) ) {
                    return $m[0];
                }
                $flags['link'] = 'link';
                return '!' === $m[1] ? '' : $m[2];
            },
            $reply
        );
        // Bare links.
        $reply = preg_replace_callback(
            '/(?<![(\[])\bhttps?:\/\/[^\s<>()\]]+/i',
            function ( $m ) use ( $url_ok, &$flags ) {
                if ( $url_ok( $m[0] ) ) {
                    return $m[0];
                }
                $flags['link'] = 'link';
                return '';
            },
            $reply
        );

        // 2. Email addresses: the exact address must appear in the knowledge.
        $reply = preg_replace_callback(
            self::EMAIL_RE,
            function ( $m ) use ( $emails, &$flags ) {
                if ( isset( $emails[ strtolower( $m[0] ) ] ) ) {
                    return $m[0];
                }
                $flags['email'] = 'email';
                return '(please use our contact form for the email address)';
            },
            $reply
        );

        // 3. Phone numbers: must match a number from the knowledge (formatting and country code ignored).
        $reply = preg_replace_callback(
            self::PHONE_RE,
            function ( $m ) use ( $phones, $numbers, &$flags ) {
                $digits = preg_replace( '/\D+/', '', $m[0] );
                if ( strlen( $digits ) < 7 || strlen( $digits ) > 15 ) {
                    return $m[0];
                }
                if ( self::phone_known( $digits, $phones ) ) {
                    return $m[0];
                }
                // A long plain number from the content (an SKU, an amount), but never a piece of a phone number.
                if ( isset( $numbers[ $digits ] ) ) {
                    $fragment = false;
                    foreach ( $phones as $p ) {
                        $fragment = $fragment || ( $p !== $digits && false !== strpos( $p, $digits ) );
                    }
                    if ( ! $fragment ) {
                        return $m[0];
                    }
                }
                $flags['phone'] = 'phone';
                return '(please use our contact form for the phone number)';
            },
            $reply
        );

        // 4. Prices: every amount must be a price from the knowledge, in a compatible
        //    currency, and (when the sentence names a product) a price of that product.
        //    Unsupported amounts are replaced, so a wrong price is never shown.
        $reply = self::check_prices( $reply, self::price_facts( $sources ), $flags );

        $reply = preg_replace( '/[ \t]{2,}/', ' ', $reply );
        return array( 'reply' => trim( $reply ), 'flags' => array_values( $flags ) );
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    /** "1,500.00" → "1500", "12.50" → "12.5". */
    public static function normalize_number( $n ) {
        $n = str_replace( ',', '', (string) $n );
        if ( false !== strpos( $n, '.' ) ) {
            $n = rtrim( rtrim( $n, '0' ), '.' );
        }
        $n = ltrim( $n, '0' );
        return '' === $n || '.' === $n[0] ? '0' . $n : $n;
    }

    /** Every separate number in the text (each one on its own, never glued together). */
    private static function numbers( $text ) {
        $out = array();
        if ( preg_match_all( '/' . self::NUM_RE . '/', $text, $m ) ) {
            foreach ( $m[0] as $n ) {
                $out[ self::normalize_number( $n ) ] = true;
            }
        }
        return $out;
    }

    /** Phone-like numbers in the text, as digit strings. */
    private static function phones( $text ) {
        $out = array();
        if ( preg_match_all( self::PHONE_RE, $text, $m ) ) {
            foreach ( $m[0] as $p ) {
                $d = preg_replace( '/\D+/', '', $p );
                if ( strlen( $d ) >= 7 ) {
                    $out[] = $d;
                }
            }
        }
        return $out;
    }

    /**
     * Same phone number? Exact digits, or the same national number with a different
     * prefix: 0300 1234567 = +92 300 1234567 = 0092 300 1234567.
     */
    private static function phone_known( $digits, $phones ) {
        $a = ltrim( $digits, '0' );
        foreach ( $phones as $p ) {
            if ( $p === $digits ) {
                return true;
            }
            $b       = ltrim( $p, '0' );
            $shorter = strlen( $a ) <= strlen( $b ) ? $a : $b;
            $longer  = strlen( $a ) <= strlen( $b ) ? $b : $a;
            $extra   = strlen( $longer ) - strlen( $shorter );
            // At least 9 matching digits; the extra part can only be a country code (1-3 digits).
            if ( strlen( $shorter ) >= 9 && $extra <= 3 && substr( $longer, -strlen( $shorter ) ) === $shorter ) {
                return true;
            }
        }
        return false;
    }

    /** Lower case, no scheme, no "www.", no trailing slash or punctuation. */
    private static function normalize_url( $url ) {
        $url = strtolower( html_entity_decode( (string) $url ) );
        $url = rtrim( $url, '/.,;:)!?' );
        $url = preg_replace( '#^https?://(www\.)?#', '', $url );
        return rtrim( $url, '/' );
    }

    /** All links in the knowledge, normalized. */
    private static function urls( $text ) {
        $out = array();
        if ( preg_match_all( '#https?://[^\s<>()\]"\']+#i', $text, $m ) ) {
            foreach ( $m[0] as $u ) {
                $out[ self::normalize_url( $u ) ] = true;
            }
        }
        return $out;
    }

    /** Is this link in the knowledge, or a page, post, product, term or file that exists on this site? */
    private static function url_known( $url, $urls ) {
        $norm = self::normalize_url( $url );
        if ( '' === $norm ) {
            return false;
        }
        if ( isset( $urls[ $norm ] ) ) {
            return true;
        }
        $site_host = strtolower( preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
        $host      = strtolower( preg_replace( '/^www\./', '', (string) wp_parse_url( $url, PHP_URL_HOST ) ) );
        if ( '' === $host || $host !== $site_host ) {
            return false; // Other websites: only when the exact link is in the knowledge.
        }

        // Own website: the address must resolve to something real.
        $path      = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
        $home_path = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
        if ( $path === $home_path ) {
            return true; // Home page.
        }
        $clean = strtok( rtrim( html_entity_decode( $url ), '.,;:)!?' ), '#' );
        if ( function_exists( 'url_to_postid' ) && url_to_postid( $clean ) > 0 ) {
            $post = get_post( url_to_postid( $clean ) );
            return WP_AI_Chatbot_Knowledge_Index::is_public_post( $post );
        }
        // Uploaded files (images, PDFs).
        if ( function_exists( 'wp_get_upload_dir' ) ) {
            $up   = wp_get_upload_dir();
            $base = self::normalize_url( $up['baseurl'] );
            if ( 0 === strpos( $norm, $base . '/' ) ) {
                // The file path keeps its original letter case (file names like "Price-List.pdf"
                // exist only with that case on Linux servers); $norm is lower case.
                $file_url  = rtrim( strtok( html_entity_decode( (string) $url ), '?#' ), '/.,;:)!?' );
                $base_path = (string) wp_parse_url( $up['baseurl'], PHP_URL_PATH );
                $url_path  = rawurldecode( (string) wp_parse_url( $file_url, PHP_URL_PATH ) );
                if ( '' === $base_path || 0 !== stripos( $url_path, rtrim( $base_path, '/' ) . '/' ) ) {
                    return false;
                }
                $file = $up['basedir'] . substr( $url_path, strlen( rtrim( $base_path, '/' ) ) );
                return false === strpos( $file, '..' ) && file_exists( $file );
            }
        }
        // Category, tag and product category archives.
        $slug = basename( $path );
        if ( '' !== $slug && function_exists( 'get_taxonomies' ) ) {
            foreach ( get_taxonomies( array( 'public' => true ) ) as $tax ) {
                $term = get_term_by( 'slug', sanitize_title( $slug ), $tax );
                if ( $term && ! is_wp_error( $term ) ) {
                    $link = get_term_link( $term );
                    if ( ! is_wp_error( $link ) && self::normalize_url( $link ) === $norm ) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /* ------------------------------------------------------------------
     * Prices
     * ------------------------------------------------------------------ */

    const LABEL_RE = '(?:regular price|sale price|price|prices|cost|costs|fee|fees|charges?|rate|qeemat|keemat|budget|amount)';

    /** Currency word or symbol → code. "Rs" / "rupees" is the generic "RS" (PKR, INR, LKR, NPR). */
    public static function currency_code( $token ) {
        $t   = strtolower( trim( (string) $token, " .\t" ) );
        $map = array(
            'rs' => 'RS', 'rupee' => 'RS', 'rupees' => 'RS', 'pkr' => 'PKR', 'inr' => 'INR', '₹' => 'INR', 'lkr' => 'LKR', 'npr' => 'NPR',
            '$' => 'USD', 'usd' => 'USD', 'dollar' => 'USD', 'dollars' => 'USD', '€' => 'EUR', 'eur' => 'EUR', 'euro' => 'EUR', 'euros' => 'EUR',
            '£' => 'GBP', 'gbp' => 'GBP', 'aed' => 'AED', 'dirham' => 'AED', 'dirhams' => 'AED', 'sar' => 'SAR', 'riyal' => 'SAR', 'riyals' => 'SAR',
        );
        if ( isset( $map[ $t ] ) ) {
            return $map[ $t ];
        }
        return preg_match( '/^[a-z]{3}$/', $t ) ? strtoupper( $t ) : '';
    }

    /** Same currency? An unknown side ('') can't be checked and is accepted. */
    public static function currency_compatible( $a, $b ) {
        if ( '' === $a || '' === $b || $a === $b ) {
            return true;
        }
        $rupees = array( 'RS', 'PKR', 'INR', 'LKR', 'NPR' );
        return ( 'RS' === $a && in_array( $b, $rupees, true ) ) || ( 'RS' === $b && in_array( $a, $rupees, true ) );
    }

    private static function currency_re() {
        return '(?:rs\.?|pkr|inr|lkr|npr|rupees?|usd|dollars?|aed|dirhams?|sar|riyals?|gbp|eur|euros?|\$|€|£|₹)';
    }

    /**
     * All amounts in a text that are prices: written with a currency ("Rs 1,500",
     * "1500 PKR") or after a price label ("Price: 1500").
     *
     * @return array list of array( 'amount', 'currency', 'offset', 'length' ).
     */
    public static function amounts( $text, $default_currency = '' ) {
        $cur = self::currency_re();
        $num = self::NUM_RE;
        $out = array();
        $pattern = '/(?:\b' . self::LABEL_RE . '\b\s*(?:is|:|=|-|of)?\s*)?(?:(?<![\p{L}])(?<c1>' . $cur . ')\s?(?<n1>' . $num . ')|(?<n2>' . $num . ')\s?(?<c2>' . $cur . ')(?![a-z])|(?<=[:=]\s|[:=]|is\s)(?<n3>' . $num . '))/iu';
        if ( ! preg_match_all( $pattern, $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
            return $out;
        }
        foreach ( $m as $hit ) {
            $whole = $hit[0][0];
            if ( isset( $hit['n3'] ) && '' !== $hit['n3'][0] && -1 !== $hit['n3'][1] ) {
                // Bare number: only when a price label is in front of it.
                if ( ! preg_match( '/^\s*' . self::LABEL_RE . '\b/iu', $whole ) ) {
                    continue;
                }
                $out[] = array( 'amount' => self::normalize_number( $hit['n3'][0] ), 'currency' => $default_currency, 'offset' => $hit['n3'][1], 'length' => strlen( $hit['n3'][0] ) );
                continue;
            }
            $n_key = ( isset( $hit['n1'] ) && '' !== $hit['n1'][0] && -1 !== $hit['n1'][1] ) ? '1' : '2';
            $c     = $hit[ 'c' . $n_key ];
            $n     = $hit[ 'n' . $n_key ];
            $start = min( $c[1], $n[1] );
            $end   = max( $c[1] + strlen( $c[0] ), $n[1] + strlen( $n[0] ) );
            $out[] = array( 'amount' => self::normalize_number( $n[0] ), 'currency' => self::currency_code( $c[0] ), 'offset' => $start, 'length' => $end - $start );
        }
        return $out;
    }

    /**
     * Prices the AI was given, per record of retrieved knowledge (title => prices)
     * plus '' for prices in the general instructions.
     */
    public static function price_facts( $sources ) {
        $default = preg_match( '/Store currency:\s*([A-Z]{3})/', $sources, $m ) ? $m[1] : '';
        $parts   = preg_split( '/^--- (?:Match Confidence|Record)[^\n]*---\s*$/m', $sources );
        $facts   = array( 'global' => array(), 'records' => array() );
        foreach ( $parts as $i => $part ) {
            $amounts = array_map( function ( $a ) { return array( $a['amount'], $a['currency'] ); }, self::amounts( $part, $i > 0 ? $default : '' ) );
            if ( 0 === $i ) {
                $facts['global'] = $amounts;
                continue;
            }
            $title                        = preg_match( '/^Title:\s*(.+)$/m', $part, $t ) ? trim( $t[1] ) : 'Record ' . $i;
            $facts['records'][ $title ]   = $amounts;
        }
        return $facts;
    }

    private static function norm_words( $text ) {
        return trim( preg_replace( '/[^\p{L}\p{N}]+/u', ' ', strtolower( (string) $text ) ) );
    }

    /** Does the sentence name this record? Full title, or all of its significant words. */
    private static function mentions( $sentence, $title ) {
        $s = ' ' . self::norm_words( $sentence ) . ' ';
        $t = self::norm_words( $title );
        if ( '' === $t ) {
            return false;
        }
        if ( false !== strpos( $s, ' ' . $t . ' ' ) ) {
            return true;
        }
        $words = array_filter( explode( ' ', $t ), function ( $w ) {
            return strlen( $w ) >= 4 && ! in_array( $w, array( 'with', 'from', 'pack', 'plan', 'package', 'service', 'services', 'product' ), true );
        } );
        if ( count( $words ) < 2 ) {
            return false;
        }
        foreach ( $words as $w ) {
            if ( false === strpos( $s, ' ' . $w . ' ' ) ) {
                return false;
            }
        }
        return true;
    }

    private static function check_prices( $reply, $facts, &$flags ) {
        $amounts = self::amounts( $reply );
        if ( ! $amounts ) {
            return $reply;
        }
        // Sentence boundaries, so an amount is checked against the product named next to it.
        $sentences = array();
        foreach ( preg_split( '/(?<=[!?])\s+|(?<=[a-z\)]\.)\s+|\n+/iu', $reply, -1, PREG_SPLIT_OFFSET_CAPTURE ) as $piece ) {
            $sentences[] = array( $piece[1], $piece[1] + strlen( $piece[0] ), $piece[0] );
        }
        $all = $facts['global'];
        foreach ( $facts['records'] as $list ) {
            $all = array_merge( $all, $list );
        }

        $replace = array();
        foreach ( $amounts as $a ) {
            $sentence = '';
            foreach ( $sentences as $se ) {
                if ( $a['offset'] >= $se[0] && $a['offset'] < $se[1] ) {
                    $sentence = $se[2];
                    break;
                }
            }
            $named = array();
            foreach ( $facts['records'] as $title => $list ) {
                if ( self::mentions( $sentence, $title ) ) {
                    $named = array_merge( $named, $list );
                }
            }
            // A named product must have this price; otherwise any price the AI was given.
            $allowed = $named ? array_merge( $named, $facts['global'] ) : $all;
            $ok      = false;
            foreach ( $allowed as $f ) {
                if ( $f[0] === $a['amount'] && self::currency_compatible( $f[1], $a['currency'] ) ) {
                    $ok = true;
                    break;
                }
            }
            if ( ! $ok ) {
                $replace[] = $a;
            }
        }
        if ( $replace ) {
            $flags['price'] = 'price';
            usort( $replace, function ( $x, $y ) { return $y['offset'] - $x['offset']; } );
            foreach ( $replace as $a ) {
                $reply = substr_replace( $reply, '(price: please confirm with our team)', $a['offset'], $a['length'] );
            }
        }
        return $reply;
    }
}
