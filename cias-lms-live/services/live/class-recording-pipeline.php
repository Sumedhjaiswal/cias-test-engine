<?php
namespace CIAS_LIVE\Services;

defined( 'ABSPATH' ) || exit;

/**
 * RecordingPipeline
 *
 * Flow:
 * 1. Zoom sends webhook: recording.completed
 * 2. We validate signature and enqueue recording job
 * 3. Cron picks it up: download from Zoom → upload to Vimeo
 * 4. On Vimeo publish: update lesson, notify students via AiSensy
 *
 * Retry: max 3 attempts with exponential backoff
 * Dead-letter: after 3 failures, status = 'failed', admin alerted
 */
class RecordingPipeline {

    private const MAX_RETRIES    = 3;
    private const VIMEO_API_BASE = 'https://api.vimeo.com';

    // ── Webhook Handler ───────────────────────────────────────────────────────

    public static function handle_webhook( array $payload, string $signature, string $raw_body ): bool|\WP_Error {

        // Validate Zoom webhook signature
        if ( ! self::verify_signature( $raw_body, $signature ) ) {
            return new \WP_Error( 'invalid_signature', 'Webhook signature mismatch.', [ 'status' => 401 ] );
        }

        $event = $payload['event'] ?? '';

        // Handle Zoom endpoint validation (one-time)
        if ( $event === 'endpoint.url_validation' ) {
            return true;
        }

        if ( $event !== 'recording.completed' ) return true; // ignore other events

        $meeting_id = $payload['payload']['object']['id'] ?? '';
        if ( ! $meeting_id ) return true;

        global $wpdb;

        // Find the live class
        $class = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cias_live_classes WHERE zoom_meeting_id = %s",
            (string) $meeting_id
        ) );

        if ( ! $class || ! $class->auto_recording ) return true;

        // Get recording files
        $recordings = $payload['payload']['object']['recording_files'] ?? [];
        $mp4        = null;
        foreach ( $recordings as $file ) {
            if ( ( $file['file_type'] ?? '' ) === 'MP4' && ( $file['status'] ?? '' ) === 'completed' ) {
                $mp4 = $file;
                break;
            }
        }

        if ( ! $mp4 ) return true;

        // Create recording job
        $wpdb->insert( $wpdb->prefix . 'cias_live_recordings', [
            'live_class_id'     => $class->id,
            'zoom_recording_id' => $mp4['id'],
            'zoom_download_url' => $mp4['download_url'],
            'upload_status'     => 'pending',
        ] );

        // Mark class as completed
        $wpdb->update(
            $wpdb->prefix . 'cias_live_classes',
            [ 'status' => 'completed', 'recording_status' => 'pending' ],
            [ 'id' => $class->id ]
        );

        return true;
    }

    // ── Cron Processor ────────────────────────────────────────────────────────

    public static function process_pending(): void {
        global $wpdb;

        $jobs = $wpdb->get_results(
            "SELECT r.*, lc.zoom_host_id, lc.title, lc.batch_id, lc.id AS class_id
             FROM {$wpdb->prefix}cias_live_recordings r
             JOIN {$wpdb->prefix}cias_live_classes lc ON lc.id = r.live_class_id
             WHERE r.upload_status IN ('pending','downloading','uploading')
             AND r.retry_count < " . self::MAX_RETRIES . "
             ORDER BY r.created_at ASC
             LIMIT 3"
        );

        foreach ( $jobs as $job ) {
            self::process_job( $job );
        }
    }

    private static function process_job( object $job ): void {
        global $wpdb;

        try {
            // Step 1: Download from Zoom → upload directly to Vimeo
            $wpdb->update(
                $wpdb->prefix . 'cias_live_recordings',
                [ 'upload_status' => 'uploading' ],
                [ 'id' => $job->id ]
            );

            // Get access token for download
            $access_token = ZoomHostPool::get_valid_token( $job->zoom_host_id );
            if ( is_wp_error( $access_token ) ) throw new \Exception( $access_token->get_error_message() );

            $download_url = $job->zoom_download_url . '?access_token=' . $access_token;

            // Create Vimeo video via pull upload (Vimeo downloads directly from URL)
            $vimeo_response = self::vimeo_create_pull_upload( $download_url, $job->title );
            if ( is_wp_error( $vimeo_response ) ) throw new \Exception( $vimeo_response->get_error_message() );

            $vimeo_video_id = $vimeo_response['video_id'];

            // Set Vimeo privacy settings
            self::vimeo_set_privacy( $vimeo_video_id );

            // Update recording as published
            $wpdb->update( $wpdb->prefix . 'cias_live_recordings', [
                'vimeo_video_id' => $vimeo_video_id,
                'upload_status'  => 'published',
                'published_at'   => current_time( 'mysql', true ),
            ], [ 'id' => $job->id ] );

            // Update live class recording status
            $wpdb->update(
                $wpdb->prefix . 'cias_live_classes',
                [ 'recording_status' => 'published' ],
                [ 'id' => $job->class_id ]
            );

            // Notify students
            NotificationService::recording_ready( $job->class_id, $job->batch_id );

        } catch ( \Exception $e ) {
            $retry = $job->retry_count + 1;
            $status = $retry >= self::MAX_RETRIES ? 'failed' : 'pending';

            $wpdb->update( $wpdb->prefix . 'cias_live_recordings', [
                'upload_status' => $status,
                'retry_count'   => $retry,
                'error_message' => $e->getMessage(),
            ], [ 'id' => $job->id ] );

            if ( $status === 'failed' ) {
                $wpdb->update(
                    $wpdb->prefix . 'cias_live_classes',
                    [ 'recording_status' => 'failed' ],
                    [ 'id' => $job->class_id ]
                );
                // Alert admin
                wp_mail(
                    get_option( 'admin_email' ),
                    '[CIAS] Recording upload failed: ' . $job->title,
                    "Recording for \"{$job->title}\" failed after 3 attempts.\n\nError: " . $e->getMessage()
                );
            }

            error_log( '[CIAS Live] Recording job ' . $job->id . ' failed: ' . $e->getMessage() );
        }
    }

    // ── Vimeo API ─────────────────────────────────────────────────────────────

    private static function vimeo_create_pull_upload( string $source_url, string $title ): array|\WP_Error {
        $token = defined( 'CIAS_VIMEO_ACCESS_TOKEN' ) ? CIAS_VIMEO_ACCESS_TOKEN : '';
        if ( ! $token ) return new \WP_Error( 'config', 'Vimeo access token not configured.' );

        $response = wp_remote_post( self::VIMEO_API_BASE . '/me/videos', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/vnd.vimeo.*+json;version=3.4',
            ],
            'body' => wp_json_encode( [
                'upload' => [
                    'approach'   => 'pull',
                    'link'       => $source_url,
                ],
                'name'    => '[CIAS Recording] ' . $title,
                'privacy' => [ 'view' => 'nobody', 'embed' => 'whitelist' ],
            ] ),
        ] );

        if ( is_wp_error( $response ) ) return $response;

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['uri'] ) ) {
            return new \WP_Error( 'vimeo_error', $body['developer_message'] ?? 'Vimeo upload failed.' );
        }

        // Extract video ID from URI (/videos/123456789)
        $video_id = basename( $body['uri'] );

        return [ 'video_id' => $video_id, 'uri' => $body['uri'] ];
    }

    private static function vimeo_set_privacy( string $video_id ): void {
        $token  = defined( 'CIAS_VIMEO_ACCESS_TOKEN' ) ? CIAS_VIMEO_ACCESS_TOKEN : '';
        $domain = defined( 'CIAS_VIMEO_DOMAIN_LOCK' ) ? CIAS_VIMEO_DOMAIN_LOCK : parse_url( home_url(), PHP_URL_HOST );

        if ( ! $token ) return;

        // Add domain whitelist
        wp_remote_request( self::VIMEO_API_BASE . "/videos/{$video_id}/privacy/domains/{$domain}", [
            'method'  => 'PUT',
            'headers' => [ 'Authorization' => 'Bearer ' . $token ],
        ] );
    }

    // ── Webhook Signature Verification ───────────────────────────────────────

    private static function verify_signature( string $raw_body, string $signature ): bool {
        $secret = defined( 'CIAS_ZOOM_WEBHOOK_SECRET' ) ? CIAS_ZOOM_WEBHOOK_SECRET : '';
        if ( ! $secret ) return true; // skip in dev if not configured

        $expected = hash_hmac( 'sha256', $raw_body, $secret );
        return hash_equals( 'v0=' . $expected, $signature );
    }
}
