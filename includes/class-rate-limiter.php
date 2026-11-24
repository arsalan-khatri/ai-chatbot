<?php
/**
 * Rate limiter for the public endpoints (chat, leads, voice, tracking, webhooks).
 *
 * Counting is atomic so parallel requests cannot slip past the limit:
 * - with a persistent object cache (Redis, Memcached): wp_cache_add + wp_cache_incr;
 * - otherwise: one row per bucket in {prefix}ai_rate_limits, increased with a
 *   single UPDATE statement (the database locks the row);
 * - if that table does not exist yet: the old transient counter (best effort).
 *
 * This limits naive scripted abuse of paid AI calls from one client. It is not a
 * replacement for a firewall (WAF) or an edge rate limit at the host or CDN.
 *
 * @package AK_AI_Chatbot
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_AI_Chatbot_Rate_Limiter {

    const TABLE     = 'ai_rate_limits';
    const MAX_COUNT = 1000000;

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /** Creates the counter table (called from the schema upgrade). */
    public static function ensure_table() {
        global $wpdb;
        $table = self::table();
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS {$table} (
                bucket CHAR(32) NOT NULL,
                hits INT UNSIGNED NOT NULL DEFAULT 0,
                reset_at INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (bucket),
                KEY reset_at (reset_at)
            ) " . $wpdb->get_charset_collate()
        );
        update_option( 'wp_ai_chatbot_rate_table', $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ? '1' : '0', true );
    }

    private static function has_table() {
        return '1' === get_option( 'wp_ai_chatbot_rate_table', '0' );
    }

    /**
     * Returns true if the request is allowed, false if the limit was exceeded.
     *
     * @param string $key           Unique bucket key (e.g. 'chat_' . $ip).
     * @param int    $max_requests  Max requests allowed within the window.
     * @param int    $window        Window length in seconds.
     */
    public static function allow( $key, $max_requests, $window ) {
        $window = max( 1, (int) $window );
        $count  = self::hit( $key, $window );
        return $count <= (int) $max_requests;
    }

    /** Counts one request and returns the number of requests in the current window. */
    public static function hit( $key, $window ) {
        $bucket = md5( (string) $key );

        if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
            $group = 'wp_ai_rl';
            $ck    = $bucket . '_' . (int) floor( time() / $window ); // Fixed window per key.
            wp_cache_add( $ck, 0, $group, $window + 5 );
            $n = wp_cache_incr( $ck, 1, $group );
            if ( false !== $n ) {
                return (int) $n;
            }
        }

        if ( self::has_table() ) {
            $n = self::hit_db( $bucket, $window );
            if ( null !== $n ) {
                return $n;
            }
        }
        return self::hit_transient( $bucket, $window );
    }

    /** Atomic counter in the database. Returns null if the database is not usable. */
    private static function hit_db( $bucket, $window ) {
        global $wpdb;
        $table = self::table();
        $now   = time();
        // LAST_INSERT_ID(expr) hands this request the exact count it produced, even when other
        // requests update the same row a moment later (MySQL / MariaDB, per connection).
        $sql   = $wpdb->prepare(
            "UPDATE {$table} SET
                hits = LAST_INSERT_ID( CASE WHEN reset_at <= %d THEN 1 WHEN hits >= %d THEN hits ELSE hits + 1 END ),
                reset_at = CASE WHEN reset_at <= %d THEN %d ELSE reset_at END
             WHERE bucket = %s",
            $now, self::MAX_COUNT, $now, $now + $window, $bucket
        );
        $updated = $wpdb->query( $sql );
        if ( false === $updated ) {
            return null;
        }
        $n = null;
        if ( 0 === (int) $updated ) {
            $suppress = $wpdb->suppress_errors( true );
            $inserted = $wpdb->insert( $table, array( 'bucket' => $bucket, 'hits' => 1, 'reset_at' => $now + $window ), array( '%s', '%d', '%d' ) );
            $wpdb->suppress_errors( $suppress );
            if ( false === $inserted ) {
                // Another request created the row at the same moment: count on it.
                if ( false === $wpdb->query( $sql ) ) {
                    return null;
                }
            } else {
                $n = 1;
            }
        }
        if ( null === $n ) {
            $n = $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
            if ( null === $n || (int) $n < 1 ) {
                $n = $wpdb->get_var( $wpdb->prepare( "SELECT hits FROM {$table} WHERE bucket = %s", $bucket ) );
            }
        }
        if ( null === $n ) {
            return null;
        }
        // Expired rows are removed now and then, so the table stays small.
        if ( 1 === wp_rand( 1, 200 ) ) {
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE reset_at < %d", $now - 3600 ) );
        }
        return (int) $n;
    }

    /** Old behaviour (read, then write): only used until the table exists. */
    private static function hit_transient( $bucket, $window ) {
        $transient_key = 'wp_ai_rl_' . $bucket;
        $data          = get_transient( $transient_key );
        if ( false === $data || ! is_array( $data ) || empty( $data['reset'] ) || $data['reset'] < time() ) {
            $data = array( 'count' => 0, 'reset' => time() + $window );
        }
        $data['count'] = min( self::MAX_COUNT, $data['count'] + 1 );
        set_transient( $transient_key, $data, max( 1, $data['reset'] - time() ) );
        return (int) $data['count'];
    }

    /** Cloudflare's published edge ranges (https://www.cloudflare.com/ips/). */
    const CLOUDFLARE = array(
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
        '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    );

    /**
     * The visitor's IP address.
     *
     * Client-supplied headers are only trusted when the request really comes from a
     * trusted proxy: Cloudflare's edge (CF-Connecting-IP), or proxies listed in the
     * WP_AI_CHATBOT_TRUSTED_PROXIES constant (comma separated IPs or CIDR ranges,
     * X-Forwarded-For is then read from the right). Otherwise REMOTE_ADDR is used,
     * so a visitor cannot pick their own rate-limit bucket. Behind an untrusted
     * proxy every visitor shares the proxy's address; the filter
     * 'wp_ai_chatbot_client_ip' can still override the result.
     */
    public static function get_client_ip() {
        $remote = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        $ip     = filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '0.0.0.0';

        if ( '0.0.0.0' !== $ip ) {
            if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && self::in_ranges( $ip, self::CLOUDFLARE ) ) {
                $cf = trim( (string) wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
                if ( filter_var( $cf, FILTER_VALIDATE_IP ) ) {
                    $ip = $cf;
                }
            } else {
                $trusted = defined( 'WP_AI_CHATBOT_TRUSTED_PROXIES' ) ? array_filter( array_map( 'trim', explode( ',', (string) WP_AI_CHATBOT_TRUSTED_PROXIES ) ) ) : array();
                if ( $trusted && self::in_ranges( $ip, $trusted ) && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
                    $chain = array_reverse( array_map( 'trim', explode( ',', (string) wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) ) );
                    foreach ( $chain as $hop ) {
                        if ( ! filter_var( $hop, FILTER_VALIDATE_IP ) ) {
                            break;
                        }
                        $ip = $hop;
                        if ( ! self::in_ranges( $hop, $trusted ) ) {
                            break; // First address not added by our own proxies.
                        }
                    }
                }
            }
        }
        return (string) apply_filters( 'wp_ai_chatbot_client_ip', $ip );
    }

    /** Is $ip inside one of the IPs / CIDR ranges? IPv4 and IPv6. */
    public static function in_ranges( $ip, $ranges ) {
        $bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        if ( false === $bin ) {
            return false;
        }
        foreach ( (array) $ranges as $range ) {
            $range = trim( (string) $range );
            if ( false === strpos( $range, '/' ) ) {
                if ( @inet_pton( $range ) === $bin ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
                    return true;
                }
                continue;
            }
            list( $net, $bits ) = explode( '/', $range, 2 );
            $net_bin = @inet_pton( $net ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            $bits    = (int) $bits;
            if ( false === $net_bin || strlen( $net_bin ) !== strlen( $bin ) || $bits < 0 || $bits > strlen( $bin ) * 8 ) {
                continue;
            }
            $bytes = intdiv( $bits, 8 );
            $rest  = $bits % 8;
            if ( substr( $bin, 0, $bytes ) !== substr( $net_bin, 0, $bytes ) ) {
                continue;
            }
            if ( 0 === $rest ) {
                return true;
            }
            $mask = chr( ( 0xff << ( 8 - $rest ) ) & 0xff );
            if ( ( $bin[ $bytes ] & $mask ) === ( $net_bin[ $bytes ] & $mask ) ) {
                return true;
            }
        }
        return false;
    }
}
