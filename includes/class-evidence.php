<?php
/**
 * Evidence selection for the token saver: token estimates, multilingual term
 * matching and answer-first passage trimming.
 *
 * No AI call is made here; everything is deterministic.
 *
 * @package AK_AI_Chatbot
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_AI_Chatbot_Evidence {

    /**
     * Conservative token estimate for mixed English / Roman Urdu / Urdu text.
     * Latin characters: about 3.5 per token (slightly high on purpose).
     * Other scripts (Urdu, Arabic, Hindi...): about 1.5 characters per token.
     * Providers report the exact numbers afterwards; this is only for budgets.
     */
    public static function tokens( $text ) {
        $text = (string) $text;
        if ( '' === $text ) {
            return 0;
        }
        $total = function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
        $ascii = preg_match_all( '/[\x00-\x7F]/', $text );
        return (int) ceil( $ascii / 3.5 + max( 0, $total - $ascii ) / 1.5 );
    }

    public static function lower( $text ) {
        return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $text, 'UTF-8' ) : strtolower( (string) $text );
    }

    public static function len( $text ) {
        return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $text, 'UTF-8' ) : strlen( (string) $text );
    }

    /** Words that mean the same thing across English, Roman Urdu and Urdu: word => concept. */
    public static function concepts() {
        static $map = null;
        if ( null !== $map ) {
            return $map;
        }
        $groups = array(
            'price'    => array( 'price', 'prices', 'pricing', 'cost', 'costs', 'rate', 'rates', 'fee', 'fees', 'charge', 'charges', 'qeemat', 'keemat', 'qimat', 'kimat', 'qeemt', 'keemt', 'qimmat', 'daam', 'paisay', 'paisa', 'paise', 'price-list', 'paise', 'rs', 'pkr', 'rupees', 'rupee', 'usd', 'dollar', 'dollars', 'قیمت', 'ریٹ', 'روپے', 'فیس' ),
            'warranty' => array( 'warranty', 'guarantee', 'guaranty', 'zamanat', 'waranty', 'warrenty', 'warranti', 'waranti', 'garanti', 'guarantie', 'guarenty', 'وارنٹی', 'ضمانت', 'گارنٹی' ),
            'refund'   => array( 'refund', 'refunds', 'refunded', 'return', 'returns', 'returned', 'wapsi', 'wapas', 'money-back', 'cancel', 'cancellation', 'ریفنڈ', 'واپسی' ),
            'delivery' => array( 'delivery', 'deliver', 'delivered', 'shipping', 'ship', 'shipped', 'courier', 'dispatch', 'delivry', 'dilivery', 'deliveri', 'bhejenge', 'bhejo', 'bhejein', 'ڈیلیوری', 'ترسیل' ),
            'address'  => array( 'address', 'location', 'located', 'office', 'pata', 'map', 'directions', 'پتہ', 'ایڈریس', 'دفتر' ),
            'contact'  => array( 'contact', 'phone', 'call', 'number', 'whatsapp', 'email', 'mail', 'rabta', 'رابطہ', 'فون', 'نمبر' ),
            'hours'    => array( 'hours', 'timing', 'timings', 'open', 'opening', 'close', 'closing', 'waqt', 'auqat', 'schedule', 'اوقات', 'وقت' ),
            'stock'    => array( 'stock', 'available', 'availability', 'dastiyab', 'mojood', 'maujood', 'mojud', 'instock', 'milega', 'milegi', 'milenge', 'دستیاب', 'موجود', 'اسٹاک' ),
            'discount' => array( 'discount', 'offer', 'sale', 'deal', 'promo', 'coupon', 'رعایت', 'ڈسکاؤنٹ' ),
            'time'     => array( 'days', 'day', 'weeks', 'week', 'months', 'month', 'din', 'hafta', 'mahina', 'دن', 'ہفتہ', 'مہینہ' ),
        );
        $map = array();
        foreach ( $groups as $concept => $words ) {
            foreach ( $words as $w ) {
                $map[ $w ] = '#' . $concept;
            }
        }
        return $map;
    }

    /** Question words and fillers that never identify what is asked about. */
    public static function stopwords() {
        return array( 'the', 'and', 'for', 'you', 'your', 'are', 'what', 'which', 'with', 'how', 'can', 'does', 'this', 'that', 'have', 'has', 'about', 'please', 'tell', 'show', 'give', 'want', 'need', 'get', 'any', 'there', 'from', 'will', 'would', 'could', 'should', 'kya', 'hai', 'hain', 'mein', 'mujhe', 'batao', 'bataen', 'bata', 'aap', 'apka', 'apki', 'apke', 'kaise', 'kitna', 'kitne', 'kitni', 'wala', 'wali', 'wale', 'aur', 'bhi', 'koi', 'kar', 'karo', 'kare', 'hoga', 'hogi', 'raha', 'rahi', 'iski', 'iska', 'iske', 'uski', 'uska', 'uske', 'unki', 'unka', 'kia', 'ki', 'ka', 'ke', 'ko', 'se', 'ne', 'یہ', 'کیا', 'ہے', 'ہیں', 'کی', 'کا', 'کے', 'میں', 'سے', 'اس', 'آپ' );
    }

    public static function stem( $w ) {
        if ( strlen( $w ) > 4 && preg_match( '/^[a-z]+$/', $w ) ) {
            if ( 'ies' === substr( $w, -3 ) ) {
                return substr( $w, 0, -3 ) . 'y';
            }
            if ( 's' === substr( $w, -1 ) && 'ss' !== substr( $w, -2 ) ) {
                return substr( $w, 0, -1 );
            }
        }
        return $w;
    }

    /**
     * Normalised terms of a text: concepts (#price) for known words, stems for
     * the rest. Numbers are kept as they are.
     *
     * @return array term => true
     */
    public static function terms( $text, $keep_stopwords = false ) {
        $map   = self::concepts();
        $stop  = $keep_stopwords ? array() : array_flip( self::stopwords() );
        $out   = array();
        $words = preg_split( '/[^\p{L}\p{N}\-]+/u', self::lower( $text ), -1, PREG_SPLIT_NO_EMPTY );
        foreach ( $words as $w ) {
            $w = trim( $w, '-' );
            if ( '' === $w || isset( $stop[ $w ] ) ) {
                continue;
            }
            if ( isset( $map[ $w ] ) ) {
                $out[ $map[ $w ] ] = true;
                continue;
            }
            if ( ctype_digit( $w ) || self::len( $w ) >= 3 ) {
                $out[ self::stem( $w ) ] = true;
            }
        }
        // Phrases that ask for a price or a duration without a price word ("ye kitne ka milega?").
        $low = self::lower( $text );
        if ( preg_match( '/\b(?:kitne|kitnay|kitna|kitni)\s+(?:ka|ki|ke|kay|mein|main|me|tak|paise|paisay|rupay|rupees)\b|\bhow\s+much\b|کتنے\s*(?:کا|کی|میں)|کتنا/u', $low ) ) {
            $out['#price'] = true;
        }
        if ( preg_match( '/\b(?:kitne|kitnay)\s+(?:din|dino|hafte|mahine)\b|\bhow\s+(?:long|many\s+days)\b|\bkab\s+tak\b|کتنے\s+دن/u', $low ) ) {
            $out['#time'] = true;
        }
        // Word pairs keep model names together ("laptop a", "iphone 15", "plan b").
        $all = preg_split( '/[^\p{L}\p{N}\-]+/u', self::lower( $text ), -1, PREG_SPLIT_NO_EMPTY );
        for ( $i = 0; $i + 1 < count( $all ); $i++ ) {
            $a = $all[ $i ];
            $b = $all[ $i + 1 ];
            if ( ( self::len( $a ) >= 3 && ! in_array( $a, self::stopwords(), true ) ) && ( 1 === self::len( $b ) || ctype_digit( $b ) || preg_match( '/\d/', $b ) ) ) {
                $out[ '~' . $a . ' ' . $b ] = true;
            }
        }
        return $out;
    }

    /**
     * Things the visitor explicitly does not want ("but not the refurbished one",
     * "except the blue model", "X ke ilawa", "X ke siwa"). Returns plain terms.
     * A question about a condition ("warranty ke baghair mil sakta hai?") is NOT an
     * exclusion of items; see has_negation().
     */
    public static function exclusions( $text ) {
        $low = self::lower( $text );
        $out = array();
        $pat = array(
            '/\b(?:but\s+not(?:\s+(?:the|a|an|any))?|except(?:\s+for)?(?:\s+the)?|excluding(?:\s+the)?|other\s+than(?:\s+the)?|apart\s+from(?:\s+the)?)\s+([\p{L}\p{N}\-]{3,})/u',
            '/((?:[\p{L}\p{N}\-]{2,}\s+)?[\p{L}\p{N}\-]+)\s+(?:ke|k|kay)\s+(?:ilawa|alawa|siwa|siwaye|siwae)\b/u',
            '/([\p{L}\p{N}\-]{3,})\s+(?:nahi|nahin|nai)\s+(?:chahiye|chaiye|chahye|chahiyay)\b/u',
            '/(\p{Arabic}{2,})\s+کے\s+(?:علاوہ|سوا)/u',
        );
        $stop = array_flip( self::stopwords() );
        $skip = array( 'sure', 'only', 'too', 'very', 'working', 'available', 'expensive', 'more', 'less', 'include', 'included', 'including', 'cover', 'covered', 'need', 'want', 'like', 'have', 'been', 'yet' );
        foreach ( $pat as $re ) {
            if ( preg_match_all( $re, $low, $m ) ) {
                foreach ( $m[1] as $phrase ) {
                    $t     = array_map( 'strval', array_keys( self::terms( $phrase ) ) );
                    $pairs = array_values( array_filter( $t, function ( $x ) { return '~' === $x[0]; } ) );
                    // "Laptop A ke ilawa": the pair "laptop a" identifies the item, not "laptop" alone.
                    $use = $pairs ? $pairs : array_values( array_filter( $t, function ( $x ) { return '#' !== $x[0]; } ) );
                    foreach ( $use as $w ) {
                        if ( ! isset( $stop[ $w ] ) && ! in_array( $w, $skip, true ) ) {
                            $out[ $w ] = true;
                        }
                    }
                }
            }
        }
        return array_keys( $out );
    }

    /** True when the question contains a negation or "without" (the AI is told to respect it). */
    public static function has_negation( $text ) {
        return (bool) preg_match( '/\b(?:not|without|except|excluding|never|nahi|nahin|baghair|bagair|bina|siwaye|ilawa)\b|بغیر|نہیں|علاوہ|سوا/u', self::lower( $text ) );
    }

    /** Abbreviations that end with a full stop but do not end a sentence ("Rs. 5,000", "No. 3"). */
    const ABBREVIATIONS = array( 'rs', 'no', 'nos', 'mr', 'mrs', 'ms', 'dr', 'st', 'sr', 'jr', 'vs', 'etc', 'approx', 'e.g', 'i.e', 'inc', 'co', 'pvt', 'dept', 'ref', 'tel', 'ph', 'p.o', 'a.m', 'p.m' );

    /**
     * Sentence-like units (list lines and "Label: value" pairs stay whole).
     * Splits after . ! ? and the Urdu full stop (۔) and question mark (؟), also before a
     * lower-case word (Roman Urdu is often typed without capitals), but never after an
     * abbreviation such as "Rs." or inside a number such as "5.5".
     */
    public static function units( $text ) {
        $text  = trim( preg_replace( '/[ \t]+/u', ' ', (string) $text ) );
        $parts = preg_split( '/(?<=[.!?\x{06D4}\x{061F}])\s+(?=[\p{L}\p{N}"(\[*\-])|\n+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
        $out   = array();
        foreach ( $parts as $p ) {
            $p = trim( $p );
            if ( '' === $p ) {
                continue;
            }
            if ( $out ) {
                $prev = $out[ count( $out ) - 1 ];
                $last = '';
                if ( preg_match( '/(?:^|\s)([\p{L}.]{1,8})\.$/u', $prev, $m ) ) {
                    $last = self::lower( $m[1] );
                }
                // Merge back: after an abbreviation, after a single letter ("A. Khan"), or when the
                // previous piece ends with ":" (label without value), or is a very short fragment.
                if ( ( '' !== $last && ( in_array( $last, self::ABBREVIATIONS, true ) || 1 === self::len( $last ) ) )
                    || ( preg_match( '/^\p{Ll}/u', $p ) && self::len( $prev ) < 12 ) ) {
                    $out[ count( $out ) - 1 ] = $prev . ' ' . $p;
                    continue;
                }
            }
            $out[] = $p;
        }
        return $out;
    }

    /** True when a sentence limits, excludes or conditions the one before it. */
    public static function is_condition( $unit ) {
        $u = self::lower( $unit );
        return (bool) preg_match( '/^(only|except|excluding|unless|but|however|not |no |note|applies|valid|subject to|provided|condition|terms|additional|extra|plus|separately|this (?:offer|policy|price|warranty) (?:is|does|applies)|sirf|siwaye|magar|lekin|bashart|shart|nahi|alag|izafi|نوٹ|صرف|سوائے|لیکن|مگر|شرط|نہیں|اضافی|الگ)/u', $u )
            || ( self::len( $u ) < 220 && preg_match( '/\b(only|except|excluding|exclusive|inclusive|unless|not|non-|without|separately|additional|extra charge|extra cost|sirf|siwaye|nahi|baghair|bina|alag se|izafi)\b|(صرف|سوائے|نہیں|بغیر|الگ سے|اضافی)/u', $u ) );
    }

    /** True when a sentence starts with a pronoun that needs the sentence before it. */
    public static function needs_previous( $unit ) {
        return (bool) preg_match( '/^(it|its|this|these|they|that|those|such|yeh|ye|wo|woh|is|iska|iski|یہ|وہ|اس)\b/iu', ltrim( $unit ) );
    }

    private static function has_fact( $unit ) {
        return (bool) preg_match( '/\d|\b(?:rs|pkr|usd|aed)\b|\$|%|₹|€|£|روپے/iu', $unit );
    }

    /**
     * Picks the sentences of a passage that answer the question, within $cap
     * estimated tokens. Answer-bearing sentences are taken first (not the
     * introduction), each with the condition that follows it or the sentence a
     * pronoun refers to; the result is put back in reading order with [...]
     * where text was left out. Nothing is ever cut inside a sentence unless a
     * single sentence is longer than the whole allowance.
     *
     * @param string $text  Passage.
     * @param array  $terms Question terms from terms().
     * @param int    $cap   Allowance in estimated tokens.
     * @return string
     */
    public static function select( $text, $terms, $cap ) {
        self::$overflow = false;
        if ( self::tokens( $text ) <= $cap ) {
            return $text;
        }
        $units = self::units( $text );
        $n     = count( $units );
        if ( 0 === $n ) {
            return '';
        }
        $wants_fact = isset( $terms['#price'] ) || isset( $terms['#time'] ) || isset( $terms['#hours'] ) || isset( $terms['#stock'] ) || isset( $terms['#discount'] );
        $scores     = array();
        $identity   = array(); // How strongly a sentence names the asked item (not just the asked attribute).
        foreach ( $units as $i => $u ) {
            $ut    = self::terms( $u );
            $score = 0;
            $id    = 0;
            foreach ( $terms as $t => $unused ) {
                $t = (string) $t;
                if ( isset( $ut[ $t ] ) ) {
                    $w      = '~' === $t[0] ? 3 : ( '#' === $t[0] ? 2 : 1 );
                    $score += $w;
                    $id    += '#' === $t[0] ? 0 : $w;
                }
            }
            if ( $score > 0 && $wants_fact && self::has_fact( $u ) ) {
                $score += 1;
            }
            $scores[ $i ]   = $score;
            $identity[ $i ] = $id;
        }
        // "It costs Rs 95,000." belongs to the item named in the sentence before it.
        foreach ( $units as $i => $u ) {
            if ( $i > 0 && $scores[ $i ] > 0 && self::needs_previous( $u ) ) {
                $scores[ $i ] += $identity[ $i - 1 ];
            }
        }
        $order = array_keys( $scores );
        usort( $order, function ( $a, $b ) use ( $scores ) {
            return $scores[ $b ] <=> $scores[ $a ] ?: $a <=> $b;
        } );
        if ( 0 === $scores[ $order[0] ] ) {
            // No sentence names what was asked (different wording): the passage was still chosen
            // by meaning, so keep sentences with facts first, then the opening, within the allowance.
            $order = array_keys( $units );
            usort( $order, function ( $a, $b ) use ( $units ) {
                return (int) self::has_fact( $units[ $b ] ) <=> (int) self::has_fact( $units[ $a ] ) ?: $a <=> $b;
            } );
        }

        $keep = array();
        $rank = array(); // Order in which units were taken (to drop the last ones first if needed).
        $used = 0;
        foreach ( $order as $i ) {
            if ( isset( $keep[ $i ] ) ) {
                continue;
            }
            // The sentence with its attachments: previous one for "It/This...", following conditions.
            $group = array( $i );
            if ( $i > 0 && self::needs_previous( $units[ $i ] ) ) {
                array_unshift( $group, $i - 1 );
            }
            for ( $j = $i + 1; $j < $n && $j <= $i + 2 && self::is_condition( $units[ $j ] ); $j++ ) {
                $group[] = $j;
            }
            $cost = self::group_cost( $units, $group, $keep );
            if ( $used + $cost > $cap ) {
                if ( ! empty( $keep ) ) {
                    continue; // Try smaller sentences further down the ranking.
                }
                // Even the best sentence with its attachments is longer than the allowance.
                // Policy: keep the sentence with its conditions (a condition is never separated
                // from its fact); drop only the leading context sentence. The answer-bearing
                // sentence may go up to OVERSIZE x the allowance, plus up to CONDITION_ROOM
                // tokens for its conditions; a longer one is cut at a clause or word boundary
                // with the multilingual token estimate.
                $core      = array_values( array_diff( $group, array( $i - 1 ) ) );
                $core_cost = self::group_cost( $units, $core, array() );
                $main_cost = self::tokens( $units[ $i ] ) + 1;
                if ( $main_cost <= $cap * self::OVERSIZE && $core_cost - $main_cost <= self::CONDITION_ROOM ) {
                    $group = $core;
                    $cost  = $core_cost;
                    self::$overflow = $core_cost > $cap;
                } else {
                    self::$overflow = true;
                    return self::cut_tokens( implode( ' ', array_map( function ( $g ) use ( $units ) { return $units[ $g ]; }, $core ) ), max( 6, $cap - 2 ) ) . ' [...]';
                }
            }
            foreach ( $group as $g ) {
                if ( ! isset( $keep[ $g ] ) ) {
                    $keep[ $g ] = true;
                    $rank[]     = $g;
                }
            }
            $used += $cost;
        }
        $out = self::assemble( $units, $keep );
        // Separators (" [...] ") are counted too: drop the lowest-ranked units until it fits.
        while ( self::tokens( $out ) > max( $cap, $used ) && count( $rank ) > 1 ) {
            $drop = array_pop( $rank );
            unset( $keep[ $drop ] );
            $out = self::assemble( $units, $keep );
        }
        return $out;
    }

    /** Allowed overshoot for one complete answer-bearing sentence (instead of cutting it). */
    const OVERSIZE = 1.25;

    /** Extra tokens allowed for the conditions attached to an oversized answer sentence. */
    const CONDITION_ROOM = 40;

    /** True when the last select() had to go over its allowance or cut a sentence. */
    public static $overflow = false;

    private static function group_cost( $units, $group, $keep ) {
        $cost = 0;
        foreach ( $group as $g ) {
            $cost += isset( $keep[ $g ] ) ? 0 : self::tokens( $units[ $g ] ) + 1;
        }
        return $cost;
    }

    private static function assemble( $units, $keep ) {
        ksort( $keep );
        $out  = '';
        $prev = -2;
        foreach ( array_keys( $keep ) as $i ) {
            $out .= ( '' === $out ? '' : ( $i === $prev + 1 ? ' ' : ' [...] ' ) ) . $units[ $i ];
            $prev = $i;
        }
        return $out;
    }

    /**
     * Cuts text to at most $tokens estimated tokens (the same estimate as
     * tokens(), so Urdu script is not cut 2x too long), preferring the end of a
     * clause (, ; : or Urdu comma) and otherwise a word boundary.
     */
    public static function cut_tokens( $text, $tokens ) {
        $text = (string) $text;
        if ( self::tokens( $text ) <= $tokens ) {
            return $text;
        }
        $lo = 0;
        $hi = self::len( $text );
        while ( $lo < $hi ) {
            $mid = (int) ceil( ( $lo + $hi ) / 2 );
            if ( self::tokens( self::sub( $text, $mid ) ) <= $tokens ) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }
        $cut = self::sub( $text, max( 1, $lo ) );
        if ( preg_match( '/^(.*[,;:\x{060C}])\s/us', $cut, $m ) && self::len( $m[1] ) > self::len( $cut ) * 0.6 ) {
            return $m[1];
        }
        $word = preg_replace( '/\s+\S*$/u', '', $cut );
        return '' !== $word ? $word : $cut;
    }

    private static function sub( $text, $chars ) {
        return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $chars, 'UTF-8' ) : substr( $text, 0, $chars );
    }

    /** Kept for compatibility: cuts at a word boundary to about $tokens tokens (now script-aware). */
    public static function cut_words( $text, $tokens ) {
        return self::cut_tokens( $text, max( 6, (int) $tokens ) );
    }
}
