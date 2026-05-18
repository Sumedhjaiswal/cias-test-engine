<?php
/**
 * CIAS Phase B – REST: Pre-signed Upload Endpoint
 *
 * POST /wp-json/cias/v1/upload/presign
 *
 * Returns a signed R2 URL. Browser uploads directly to R2.
 * WordPress never touches the binary — no disk I/O, no memory pressure.
 *
 * Flow:
 *   1. POST /cias/v1/upload/presign { mime_type, file_size, submission_type, question_id }
 *   2. Server validates, returns { presign_url, object_key, expires_in }
 *   3. Browser PUT to presign_url (raw binary, Content-Type header required)
 *   4. Browser POST to /cias/v1/answer/submit { object_key, ... }
 *
 * @package CIAS\PhaseB
 * @since   3.19.0
 */
defined( 'ABSPATH' ) || exit;

class CIAS_REST_Upload {

    const NAMESPACE = 'cias/v1';
    const MAX_SIZE  = 10 * 1024 * 1024; // 10 MB

    public static function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/upload/presign', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle_presign' ],
            'permission_callback' => [ __CLASS__, 'is_logged_in' ],
            'args'                => [
                'mime_type'       => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'file_size'       => [ 'required' => false, 'type' => 'integer', 'default' => 0 ],
                'submission_type' => [ 'required' => false, 'type' => 'string',  'default' => 'answer_writing', 'sanitize_callback' => 'sanitize_key' ],
                'question_id'     => [ 'required' => false, 'type' => 'integer', 'default' => 0 ],
            ],
        ] );
    }

    public static function handle_presign( WP_REST_Request $request ): WP_REST_Response {
        $user_id = get_current_user_id();
        $mime    = $request->get_param('mime_type');
        $size    = (int) $request->get_param('file_size');
        $type    = $request->get_param('submission_type');

        // ── Validate mime type ─────────────────────────────────────────────
        $allowed_mimes = [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf' ];
        if ( ! in_array( $mime, $allowed_mimes, true ) ) {
            return new WP_REST_Response( [ 'error' => 'File type not allowed. Allowed: JPEG, PNG, WebP, GIF, PDF.' ], 422 );
        }

        // ── Validate size ──────────────────────────────────────────────────
        if ( $size > self::MAX_SIZE ) {
            return new WP_REST_Response( [ 'error' => 'File too large. Maximum 10 MB.' ], 422 );
        }

        // ── Rate limit ─────────────────────────────────────────────────────
        $rl = CIAS_Queue::rate_check( $user_id, 'upload_presign' );
        if ( ! $rl['allowed'] ) {
            return new WP_REST_Response( [ 'error' => 'Too many uploads. Please wait a moment.' ], 429 );
        }

        // ── Check R2 is configured ─────────────────────────────────────────
        if ( ! CIAS_R2::is_configured() ) {
            return new WP_REST_Response( [ 'error' => 'File storage not configured. Contact admin.' ], 503 );
        }

        // ── Build object key and sign ──────────────────────────────────────
        $object_key  = CIAS_R2::make_answer_key( $user_id, $mime );
        $ttl         = 300; // 5 minutes
        $presign_url = CIAS_R2::presigned_put_url( $object_key, $mime, $ttl );

        if ( ! $presign_url ) {
            return new WP_REST_Response( [ 'error' => 'Could not generate upload URL.' ], 500 );
        }

        return new WP_REST_Response( [
            'presign_url' => $presign_url,
            'object_key'  => $object_key,
            'bucket'      => CIAS_R2::bucket(),
            'mime_type'   => $mime,
            'expires_in'  => $ttl,
            'public_url'  => CIAS_R2::public_url_for( $object_key ),
        ], 200 );
    }

    public static function is_logged_in(): bool {
        return is_user_logged_in();
    }
}
