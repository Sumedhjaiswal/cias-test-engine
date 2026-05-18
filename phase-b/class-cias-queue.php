<?php
/**
 * CIAS Phase B – Redis Queue (Upstash HTTP API)
 *
 * Uses Upstash Redis REST API — no PHP Redis extension required.
 * Works on any shared/VPS hosting with curl.
 *
 * Configure via wp-config.php (preferred):
 *   define( 'CIAS_UPSTASH_URL', 'https://xxx.upstash.io' );
 *   define( 'CIAS_UPSTASH_TOKEN', 'AXxx...' );
 *
 * Queue naming convention:
 *   cias:wake:{type}        LPUSH 1 → worker poll signal
 *   cias:ratelimit:{uid}    Sliding window rate limiter
 *   cias:session:{id}       Temporary chat session state
 *   cias:cache:{key}        Short-lived computed values
 *
 * NOTE: The job queue is MySQL-backed (CIAS_DB_Phase_B::push_job).
 * Redis is used for:
 *   1. Worker wake signals (so workers don't spin-poll MySQL)
 *   2. Rate limiting (atomic INCR)
 *   3. Short-lived cache
 *   4. Session state
 *
 * @package CIAS\PhaseB
 * @since   3.19.0
 */
defined( 'ABSPATH' ) || exit;

class CIAS_Queue {

    const QUEUE_PREFIX   = 'cias:wake:';
    const CACHE_PREFIX   = 'cias:cache:';
    const RATE_PREFIX    = 'cias:rl:';
    const SESSION_PREFIX = 'cias:sess:';

    // ── Config ────────────────────────────────────────────────────────────────

    private static function url(): string {
        if ( defined('CIAS_UPSTASH_URL') && CIAS_UPSTASH_URL ) return rtrim( CIAS_UPSTASH_URL, '/' );
        return rtrim( (string) get_option('cias_upstash_url', ''), '/' );
    }

    private static function token(): string {
        if ( defined('CIAS_UPSTASH_TOKEN') && CIAS_UPSTASH_TOKEN ) return CIAS_UPSTASH_TOKEN;
        return (string) get_option('cias_upstash_token', '');
    }

    public static function is_configured(): bool {
        return (bool) self::url() && (bool) self::token();
    }

    // ── Core HTTP call ────────────────────────────────────────────────────────

    /**
     * Execute a Redis command via Upstash HTTP API.
     * Upstash format: POST /{command}/{arg1}/{arg2}/...
     *
     * @param array $command  e.g. ['SET', 'mykey', 'myvalue']
     * @return mixed  Decoded result or null on error
     */
    private static function cmd( array $command ) {
        $url   = self::url();
        $token = self::token();
        if ( ! $url || ! $token ) return null;

        // Encode each argument for URL safety
        $encoded = array_map( fn($p) => rawurlencode((string)$p), $command );
        $path    = '/' . implode( '/', $encoded );

        $ch = curl_init( $url . $path );
        curl_setopt_array( $ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_HTTPHEADER     => [ "Authorization: Bearer {$token}", 'Content-Type: application/json' ],
        ] );
        $raw  = curl_exec( $ch );
        $code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        curl_close( $ch );

        if ( $code !== 200 || ! $raw ) return null;
        $data = json_decode( $raw, true );
        return $data['result'] ?? null;
    }

    // ── Worker wake signals ────────────────────────────────────────────────────

    /**
     * Signal that a new job of $type is available.
     * Workers use BRPOP with a timeout; this LPUSH wakes them immediately.
     */
    public static function signal_wake( string $type ): void {
        self::cmd( ['LPUSH', self::QUEUE_PREFIX . $type, gmdate('c')] );
        // Cap the list at 10 signals (prevent memory growth)
        self::cmd( ['LTRIM', self::QUEUE_PREFIX . $type, 0, 9] );
    }

    // ── Rate limiting (sliding window) ─────────────────────────────────────────

    /**
     * Check and increment rate limit for a user.
     * Window: 1 minute.  Limit configurable via WP option.
     *
     * @param int $user_id
     * @param string $action   e.g. 'guru_chat', 'answer_submit'
     * @return array { allowed: bool, count: int, limit: int, ttl: int }
     */
    public static function rate_check( int $user_id, string $action = 'default' ): array {
        $limit  = (int) get_option( "cias_ratelimit_{$action}", 20 );
        $key    = self::RATE_PREFIX . "{$action}:{$user_id}:" . (int)( time() / 60 );

        // Get current count first
        $current = (int) self::cmd( ['GET', $key] );

        if ( $current >= $limit ) {
            return [ 'allowed' => false, 'count' => $current, 'limit' => $limit, 'ttl' => 60 - (time() % 60) ];
        }

        // Increment and set expiry (atomic: MULTI/EXEC not needed — INCR is atomic)
        $new = (int) self::cmd( ['INCR', $key] );
        if ( $new === 1 ) {
            self::cmd( ['EXPIRE', $key, 60] );  // First hit in this minute
        }

        return [ 'allowed' => true, 'count' => $new, 'limit' => $limit, 'ttl' => 60 - (time() % 60) ];
    }

    // ── Cache ─────────────────────────────────────────────────────────────────

    public static function cache_set( string $key, mixed $value, int $ttl = 300 ): void {
        $serialized = is_string($value) ? $value : wp_json_encode($value);
        self::cmd( ['SET', self::CACHE_PREFIX . $key, $serialized, 'EX', (string)$ttl] );
    }

    public static function cache_get( string $key ): mixed {
        $raw = self::cmd( ['GET', self::CACHE_PREFIX . $key] );
        if ( $raw === null ) return null;
        $decoded = json_decode( (string)$raw, true );
        return ( json_last_error() === JSON_ERROR_NONE ) ? $decoded : $raw;
    }

    public static function cache_del( string $key ): void {
        self::cmd( ['DEL', self::CACHE_PREFIX . $key] );
    }

    // ── Session state (temporary per-request state for multi-step flows) ───────

    public static function session_set( string $session_id, array $data, int $ttl = 3600 ): void {
        self::cmd( ['SET', self::SESSION_PREFIX . $session_id, wp_json_encode($data), 'EX', (string)$ttl] );
    }

    public static function session_get( string $session_id ): ?array {
        $raw = self::cmd( ['GET', self::SESSION_PREFIX . $session_id] );
        if ( ! $raw ) return null;
        return json_decode( (string)$raw, true ) ?: null;
    }

    public static function session_del( string $session_id ): void {
        self::cmd( ['DEL', self::SESSION_PREFIX . $session_id] );
    }

    // ── Fallback: WP transients when Redis unavailable ────────────────────────

    /**
     * Cache with automatic Redis → WP transient fallback.
     */
    public static function cache_set_safe( string $key, mixed $value, int $ttl = 300 ): void {
        if ( self::is_configured() ) {
            self::cache_set( $key, $value, $ttl );
        } else {
            set_transient( 'cias_' . md5($key), $value, $ttl );
        }
    }

    public static function cache_get_safe( string $key ): mixed {
        if ( self::is_configured() ) {
            return self::cache_get( $key );
        }
        return get_transient( 'cias_' . md5($key) ) ?: null;
    }

    // ── Health check ──────────────────────────────────────────────────────────

    public static function ping(): bool {
        $result = self::cmd( ['PING'] );
        return $result === 'PONG';
    }
}
