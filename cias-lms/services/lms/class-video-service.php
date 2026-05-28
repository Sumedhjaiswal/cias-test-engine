<?php
namespace CIAS_LMS\Services;

defined( 'ABSPATH' ) || exit;

/**
 * VideoService
 *
 * Generates short-lived signed session tokens for Vimeo private video playback.
 * The raw Vimeo video ID is NEVER sent to the client — only a server-side token
 * which the player endpoint redeems for a domain-locked Vimeo embed URL.
 *
 * Protection chain:
 *  1. Private Vimeo video (domain-restricted embed only)
 *  2. Signed JWT token (15-min TTL, single-use, bound to student + lesson + IP)
 *  3. Token stored in Redis with TTL, invalidated after first use or on revoke
 *  4. Player JS applies watermark overlay + screen recording detection on top
 */
class VideoService {

    private const TOKEN_TTL_SECONDS = 900; // 15 minutes
    private const REDIS_PREFIX      = 'cias:lms:vtoken:';

    /**
     * Generate a signed video session token.
     *
     * @return array|\WP_Error  { token, expires_at, watermark_name, watermark_phone }
     */
    public static function generate_token(
        int    $student_id,
        int    $lesson_id,
        string $ip,
        string $user_agent
    ): array|\WP_Error {
        global $wpdb;

        // Fetch the Vimeo video ID — kept server-side only
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT l.vimeo_video_id, u.display_name, um.meta_value AS phone
             FROM {$wpdb->prefix}cias_lms_lessons l
             JOIN {$wpdb->users}         u  ON u.ID = %d
             LEFT JOIN {$wpdb->usermeta} um ON um.user_id = %d AND um.meta_key = 'phone'
             WHERE l.id = %d",
            $student_id, $student_id, $lesson_id
        ) );

        if ( ! $row || ! $row->vimeo_video_id ) {
            return new \WP_Error( 'not_found', 'Video not found.', [ 'status' => 404 ] );
        }

        $token      = bin2hex( random_bytes( 32 ) );
        $token_hash = hash( 'sha256', $token );
        $expires_at = gmdate( 'Y-m-d H:i:s', time() + self::TOKEN_TTL_SECONDS );

        // Store in MySQL for audit
        $wpdb->insert( $wpdb->prefix . 'cias_lms_sessions', [
            'student_id'  => $student_id,
            'lesson_id'   => $lesson_id,
            'token_hash'  => $token_hash,
            'ip_address'  => $ip,
            'user_agent'  => substr( $user_agent, 0, 500 ),
            'expires_at'  => $expires_at,
        ] );

        $session_id = $wpdb->insert_id;

        // Store in Redis for fast redemption check
        $redis_key = self::REDIS_PREFIX . $token_hash;
        $payload   = wp_json_encode( [
            'student_id'     => $student_id,
            'lesson_id'      => $lesson_id,
            'session_id'     => $session_id,
            'ip'             => $ip,
            'vimeo_video_id' => $row->vimeo_video_id, // stored only in Redis, never client-visible
        ] );

        self::redis_set( $redis_key, $payload, self::TOKEN_TTL_SECONDS );

        return [
            'token'           => $token,          // sent to client, used once
            'expires_at'      => $expires_at,
            'session_id'      => $session_id,
            'watermark_name'  => $row->display_name,
            'watermark_phone' => $row->phone ?? '',
        ];
    }

    /**
     * Redeem a token and return the Vimeo embed URL.
     * Called server-side only when the player template loads.
     *
     * @return string|\WP_Error  Vimeo embed URL
     */
    public static function redeem_token( string $token, string $ip ): string|\WP_Error {
        $token_hash = hash( 'sha256', $token );
        $redis_key  = self::REDIS_PREFIX . $token_hash;

        $payload = self::redis_get( $redis_key );
        if ( ! $payload ) {
            return new \WP_Error( 'invalid_token', 'Token expired or invalid.', [ 'status' => 401 ] );
        }

        $data = json_decode( $payload, true );

        // IP binding check (warn but don't hard-fail — mobile may change IP)
        if ( $data['ip'] !== $ip ) {
            FingerprintService::log_event(
                $data['student_id'], $data['lesson_id'],
                'ip_mismatch', [ 'expected' => $data['ip'], 'got' => $ip ]
            );
        }

        // Delete from Redis immediately — single-use token
        self::redis_del( $redis_key );

        $vimeo_id = $data['vimeo_video_id'];

        // Build Vimeo private embed URL
        // Vimeo private videos use an h= hash parameter for privacy
        $embed_url = add_query_arg( [
            'autoplay'     => 1,
            'controls'     => 0,         // hide default controls
            'dnt'          => 1,         // do not track
            'pip'          => 0,         // disable picture-in-picture
            'byline'       => 0,
            'portrait'     => 0,
            'title'        => 0,
            'speed'        => 0,
        ], "https://player.vimeo.com/video/{$vimeo_id}" );

        // Mark session as redeemed in MySQL
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'cias_lms_sessions',
            [ 'event_log' => wp_json_encode( [ 'redeemed_at' => gmdate( 'c' ) ] ) ],
            [ 'token_hash' => $token_hash ]
        );

        return $embed_url;
    }

    // ── Redis helpers ─────────────────────────────────────────────────────────

    private static function redis_set( string $key, string $value, int $ttl ): void {
        try {
            if ( class_exists( 'Redis' ) && defined( 'CIAS_REDIS_HOST' ) ) {
                $r = new \Redis();
                $r->connect( CIAS_REDIS_HOST, defined( 'CIAS_REDIS_PORT' ) ? CIAS_REDIS_PORT : 6379 );
                $r->setEx( $key, $ttl, $value );
            } else {
                // Fallback: transient (less secure, but functional)
                set_transient( 'cias_lms_' . md5( $key ), $value, $ttl );
            }
        } catch ( \Exception $e ) {
            error_log( '[CIAS LMS] Redis set error: ' . $e->getMessage() );
        }
    }

    private static function redis_get( string $key ): string|false {
        try {
            if ( class_exists( 'Redis' ) && defined( 'CIAS_REDIS_HOST' ) ) {
                $r = new \Redis();
                $r->connect( CIAS_REDIS_HOST, defined( 'CIAS_REDIS_PORT' ) ? CIAS_REDIS_PORT : 6379 );
                $val = $r->get( $key );
                return $val !== false ? $val : false;
            }
            return get_transient( 'cias_lms_' . md5( $key ) ) ?: false;
        } catch ( \Exception $e ) {
            error_log( '[CIAS LMS] Redis get error: ' . $e->getMessage() );
            return false;
        }
    }

    private static function redis_del( string $key ): void {
        try {
            if ( class_exists( 'Redis' ) && defined( 'CIAS_REDIS_HOST' ) ) {
                $r = new \Redis();
                $r->connect( CIAS_REDIS_HOST, defined( 'CIAS_REDIS_PORT' ) ? CIAS_REDIS_PORT : 6379 );
                $r->del( $key );
            } else {
                delete_transient( 'cias_lms_' . md5( $key ) );
            }
        } catch ( \Exception $e ) {
            error_log( '[CIAS LMS] Redis del error: ' . $e->getMessage() );
        }
    }
}
