<?php
namespace CIAS_LIVE\Services;

defined( 'ABSPATH' ) || exit;

/**
 * ZoomHostPool
 *
 * Manages multiple Zoom host accounts:
 * - One-time OAuth login per host
 * - Auto token refresh
 * - Conflict-aware auto-assignment
 * - Lock/unlock hosts
 */
class ZoomHostPool {

    private const ZOOM_AUTH_URL    = 'https://zoom.us/oauth/authorize';
    private const ZOOM_TOKEN_URL   = 'https://zoom.us/oauth/token';
    private const ZOOM_API_BASE    = 'https://api.zoom.us/v2';
    private const BUFFER_MINS      = 15; // buffer between classes on same host
    private const ENCRYPTION_KEY   = CIAS_LIVE_ENCRYPTION_KEY; // defined in wp-config.php

    // ── OAuth Flow ────────────────────────────────────────────────────────────

    /**
     * Step 1: Generate OAuth redirect URL — admin clicks "Connect Zoom Account"
     */
    public static function get_oauth_url(): string {
        global $wpdb;

        $state      = bin2hex( random_bytes( 16 ) );
        $expires_at = gmdate( 'Y-m-d H:i:s', time() + 600 );

        $wpdb->insert( $wpdb->prefix . 'cias_zoom_oauth_state', [
            'state'      => $state,
            'user_id'    => get_current_user_id(),
            'expires_at' => $expires_at,
        ] );

        return add_query_arg( [
            'response_type' => 'code',
            'client_id'     => CIAS_ZOOM_CLIENT_ID,
            'redirect_uri'  => self::get_callback_url(),
            'state'         => $state,
        ], self::ZOOM_AUTH_URL );
    }

    /**
     * Step 2: Handle OAuth callback — exchange code for tokens, save host
     */
    public static function handle_oauth_callback( string $code, string $state ): bool|\WP_Error {
        global $wpdb;

        // Validate state
        $saved = $wpdb->get_row( $wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}cias_zoom_oauth_state
             WHERE state = %s AND expires_at > NOW()",
            $state
        ) );

        if ( ! $saved ) {
            return new \WP_Error( 'invalid_state', 'Invalid or expired OAuth state.' );
        }

        // Delete used state
        $wpdb->delete( $wpdb->prefix . 'cias_zoom_oauth_state', [ 'state' => $state ] );

        // Exchange code for tokens
        $response = wp_remote_post( self::ZOOM_TOKEN_URL, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( CIAS_ZOOM_CLIENT_ID . ':' . CIAS_ZOOM_CLIENT_SECRET ),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'grant_type'   => 'authorization_code',
                'code'         => $code,
                'redirect_uri' => self::get_callback_url(),
            ],
        ] );

        if ( is_wp_error( $response ) ) return $response;

        $tokens = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $tokens['access_token'] ) ) {
            return new \WP_Error( 'token_error', 'Failed to get Zoom access token.' );
        }

        // Get Zoom user info
        $user_info = self::api_get( '/users/me', $tokens['access_token'] );
        if ( is_wp_error( $user_info ) ) return $user_info;

        // Save host
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}cias_zoom_hosts WHERE email = %s",
            $user_info['email']
        ) );

        $data = [
            'display_name'     => $user_info['display_name'] ?? $user_info['email'],
            'email'            => $user_info['email'],
            'zoom_user_id'     => $user_info['id'],
            'access_token'     => self::encrypt( $tokens['access_token'] ),
            'refresh_token'    => self::encrypt( $tokens['refresh_token'] ),
            'token_expires_at' => gmdate( 'Y-m-d H:i:s', time() + $tokens['expires_in'] ),
            'status'           => 'active',
            'connected_by'     => $saved->user_id,
        ];

        if ( $existing ) {
            $wpdb->update( $wpdb->prefix . 'cias_zoom_hosts', $data, [ 'id' => $existing ] );
        } else {
            $wpdb->insert( $wpdb->prefix . 'cias_zoom_hosts', $data );
        }

        return true;
    }

    // ── Auto-Assignment ───────────────────────────────────────────────────────

    /**
     * Find best available host for a time slot.
     * Returns host ID or WP_Error if none available.
     */
    public static function assign_host( string $start_time, string $end_time ): int|\WP_Error {
        global $wpdb;

        // Get all active (non-locked) hosts
        $hosts = $wpdb->get_results(
            "SELECT id FROM {$wpdb->prefix}cias_zoom_hosts
             WHERE status = 'active'
             ORDER BY id ASC",
            ARRAY_A
        );

        if ( empty( $hosts ) ) {
            return new \WP_Error( 'no_hosts', 'No Zoom accounts connected. Please connect a Zoom account first.' );
        }

        $buffer_start = gmdate( 'Y-m-d H:i:s', strtotime( $start_time ) - ( self::BUFFER_MINS * 60 ) );
        $buffer_end   = gmdate( 'Y-m-d H:i:s', strtotime( $end_time )   + ( self::BUFFER_MINS * 60 ) );

        foreach ( $hosts as $host ) {
            // Check for conflicts (including buffer)
            $conflict = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}cias_live_classes
                 WHERE zoom_host_id = %d
                 AND status NOT IN ('cancelled')
                 AND start_time < %s
                 AND end_time   > %s",
                $host['id'], $buffer_end, $buffer_start
            ) );

            if ( ! $conflict ) {
                return (int) $host['id'];
            }
        }

        return new \WP_Error(
            'no_hosts_available',
            'No Zoom host available at this time. All accounts have classes within 15 minutes of this slot.'
        );
    }

    // ── Token Refresh ─────────────────────────────────────────────────────────

    public static function get_valid_token( int $host_id ): string|\WP_Error {
        global $wpdb;

        $host = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cias_zoom_hosts WHERE id = %d",
            $host_id
        ) );

        if ( ! $host ) return new \WP_Error( 'not_found', 'Zoom host not found.' );

        // Refresh if expires within 5 minutes
        if ( strtotime( $host->token_expires_at ) < ( time() + 300 ) ) {
            $refreshed = self::refresh_token( $host );
            if ( is_wp_error( $refreshed ) ) return $refreshed;
            return $refreshed;
        }

        return self::decrypt( $host->access_token );
    }

    private static function refresh_token( object $host ): string|\WP_Error {
        global $wpdb;

        $response = wp_remote_post( self::ZOOM_TOKEN_URL, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( CIAS_ZOOM_CLIENT_ID . ':' . CIAS_ZOOM_CLIENT_SECRET ),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'grant_type'    => 'refresh_token',
                'refresh_token' => self::decrypt( $host->refresh_token ),
            ],
        ] );

        if ( is_wp_error( $response ) ) return $response;

        $tokens = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $tokens['access_token'] ) ) {
            // Mark host as disconnected
            $wpdb->update(
                $wpdb->prefix . 'cias_zoom_hosts',
                [ 'status' => 'disconnected' ],
                [ 'id' => $host->id ]
            );
            return new \WP_Error( 'refresh_failed', "Zoom token refresh failed for {$host->email}. Please reconnect." );
        }

        $wpdb->update( $wpdb->prefix . 'cias_zoom_hosts', [
            'access_token'     => self::encrypt( $tokens['access_token'] ),
            'refresh_token'    => self::encrypt( $tokens['refresh_token'] ),
            'token_expires_at' => gmdate( 'Y-m-d H:i:s', time() + $tokens['expires_in'] ),
        ], [ 'id' => $host->id ] );

        return $tokens['access_token'];
    }

    // ── Admin Actions ─────────────────────────────────────────────────────────

    public static function lock_host( int $host_id ): void {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'cias_zoom_hosts', [ 'status' => 'locked' ], [ 'id' => $host_id ] );
    }

    public static function unlock_host( int $host_id ): void {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'cias_zoom_hosts', [ 'status' => 'active' ], [ 'id' => $host_id ] );
    }

    public static function disconnect_host( int $host_id ): void {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'cias_zoom_hosts', [ 'status' => 'disconnected' ], [ 'id' => $host_id ] );
    }

    public static function get_all_hosts(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT id, display_name, email, status, token_expires_at, connected_by, created_at
             FROM {$wpdb->prefix}cias_zoom_hosts
             ORDER BY status ASC, display_name ASC",
            ARRAY_A
        ) ?: [];
    }

    // ── Zoom API Helper ───────────────────────────────────────────────────────

    public static function api_get( string $endpoint, string $access_token ): array|\WP_Error {
        $response = wp_remote_get( self::ZOOM_API_BASE . $endpoint, [
            'headers' => [ 'Authorization' => 'Bearer ' . $access_token ],
        ] );
        if ( is_wp_error( $response ) ) return $response;
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! empty( $body['code'] ) && $body['code'] !== 200 ) {
            return new \WP_Error( 'zoom_api', $body['message'] ?? 'Zoom API error.' );
        }
        return $body;
    }

    public static function api_post( string $endpoint, array $data, string $access_token ): array|\WP_Error {
        $response = wp_remote_post( self::ZOOM_API_BASE . $endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( $data ),
        ] );
        if ( is_wp_error( $response ) ) return $response;
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! empty( $body['code'] ) && ! in_array( $body['code'], [ 200, 201 ], true ) ) {
            return new \WP_Error( 'zoom_api', $body['message'] ?? 'Zoom API error.' );
        }
        return $body;
    }

    // ── Encryption ────────────────────────────────────────────────────────────

    private static function encrypt( string $data ): string {
        $key    = defined( 'CIAS_LIVE_ENCRYPTION_KEY' ) ? CIAS_LIVE_ENCRYPTION_KEY : wp_salt( 'auth' );
        $iv     = random_bytes( 16 );
        $enc    = openssl_encrypt( $data, 'AES-256-CBC', $key, 0, $iv );
        return base64_encode( $iv . $enc );
    }

    private static function decrypt( string $data ): string {
        $key    = defined( 'CIAS_LIVE_ENCRYPTION_KEY' ) ? CIAS_LIVE_ENCRYPTION_KEY : wp_salt( 'auth' );
        $raw    = base64_decode( $data );
        $iv     = substr( $raw, 0, 16 );
        $enc    = substr( $raw, 16 );
        return openssl_decrypt( $enc, 'AES-256-CBC', $key, 0, $iv );
    }

    private static function get_callback_url(): string {
        return rest_url( CIAS_LIVE_API_NS . '/' . CIAS_LIVE_API_BASE . '/zoom-callback' );
    }

    // ── Compatibility aliases & new methods ───────────────────────────────────

    /**
     * Get first available host — checks active status only (scheduling uses time-based conflict check separately)
     */
    public static function get_available_host(): ?array {
        global $wpdb;
        // Get any connected host (active or locked — we check time conflicts separately)
        $host = $wpdb->get_row(
            "SELECT id, display_name, email, status FROM {$wpdb->prefix}cias_zoom_hosts
             WHERE status IN ('active', 'locked')
             ORDER BY FIELD(status, 'active', 'locked') ASC, id ASC
             LIMIT 1",
            ARRAY_A
        );
        return $host ?: null;
    }

    /**
     * Create a Zoom meeting for a given host
     */
    public static function create_meeting( int $host_id, array $data ): array|\WP_Error {
        $token = self::get_valid_token( $host_id );
        if ( is_wp_error( $token ) ) return $token;

        $payload = [
            'topic'      => $data['topic'] ?? 'CIAS Live Class',
            'type'       => 2, // scheduled
            'start_time' => $data['start_time'],
            'duration'   => $data['duration'] ?? 60,
            'timezone'   => 'Asia/Kolkata',
            'settings'   => [
                'host_video'        => $data['host_video'] ?? true,
                'participant_video' => false,
                'mute_upon_entry'   => $data['mute_on_entry'] ?? true,
                'auto_recording'    => $data['auto_recording'] ?? 'none',
                'join_before_host'  => true,
            ],
        ];

        $result = self::api_post( '/users/me/meetings', $payload, $token );
        if ( is_wp_error( $result ) ) return $result;

        return [
            'id'        => $result['id'],
            'join_url'  => $result['join_url'],
            'start_url' => $result['start_url'],
        ];
    }

    /**
     * Delete a Zoom meeting
     */
    public static function delete_meeting( int $host_id, string $meeting_id ): bool {
        $token = self::get_valid_token( $host_id );
        if ( is_wp_error( $token ) ) return false;

        $response = wp_remote_request( self::ZOOM_API_BASE . '/meetings/' . $meeting_id, [
            'method'  => 'DELETE',
            'headers' => [ 'Authorization' => 'Bearer ' . $token ],
        ] );

        return ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 204;
    }

}
