<?php
namespace CIAS_LMS\Services;

defined( 'ABSPATH' ) || exit;

/**
 * PDFService
 *
 * Generates short-lived pre-signed Cloudflare R2 URLs for PDF access.
 * The PDF is never served through PHP — client gets a signed URL directly to R2.
 * The client-side PDF viewer renders via Canvas (PDF.js) — no native browser PDF,
 * no print, no download dialog.
 */
class PDFService {

    private const TOKEN_TTL_SECONDS = 600; // 10 minutes

    /**
     * Generate a signed R2 URL for a PDF lesson.
     *
     * @return array|\WP_Error { signed_url, expires_at, watermark_name, watermark_phone }
     */
    public static function generate_signed_url( int $student_id, int $lesson_id ): array|\WP_Error {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT l.r2_pdf_key, u.display_name, um.meta_value AS phone
             FROM {$wpdb->prefix}cias_lms_lessons l
             JOIN {$wpdb->users}         u  ON u.ID = %d
             LEFT JOIN {$wpdb->usermeta} um ON um.user_id = %d AND um.meta_key = 'phone'
             WHERE l.id = %d AND l.type = 'pdf'",
            $student_id, $student_id, $lesson_id
        ) );

        if ( ! $row || ! $row->r2_pdf_key ) {
            return new \WP_Error( 'not_found', 'PDF not found.', [ 'status' => 404 ] );
        }

        $signed_url = self::sign_r2_url( $row->r2_pdf_key );
        if ( is_wp_error( $signed_url ) ) return $signed_url;

        return [
            'signed_url'      => $signed_url,
            'expires_at'      => gmdate( 'c', time() + self::TOKEN_TTL_SECONDS ),
            'watermark_name'  => $row->display_name,
            'watermark_phone' => $row->phone ?? '',
        ];
    }

    /**
     * Generate a pre-signed URL using AWS Signature V4 (R2 is S3-compatible).
     */
    private static function sign_r2_url( string $object_key ): string|\WP_Error {
        $account_id  = defined( 'CIAS_R2_ACCOUNT_ID' )   ? CIAS_R2_ACCOUNT_ID   : '';
        $bucket      = defined( 'CIAS_R2_BUCKET' )        ? CIAS_R2_BUCKET       : '';
        $access_key  = defined( 'CIAS_R2_ACCESS_KEY' )    ? CIAS_R2_ACCESS_KEY   : '';
        $secret_key  = defined( 'CIAS_R2_SECRET_KEY' )    ? CIAS_R2_SECRET_KEY   : '';

        if ( ! $account_id || ! $bucket || ! $access_key || ! $secret_key ) {
            return new \WP_Error( 'config_error', 'R2 credentials not configured.', [ 'status' => 500 ] );
        }

        $endpoint   = "https://{$account_id}.r2.cloudflarestorage.com";
        $region     = 'auto';
        $service    = 's3';
        $ttl        = self::TOKEN_TTL_SECONDS;
        $now        = time();
        $date_stamp = gmdate( 'Ymd', $now );
        $amz_date   = gmdate( 'Ymd\THis\Z', $now );

        $host        = "{$account_id}.r2.cloudflarestorage.com";
        $uri         = "/{$bucket}/" . ltrim( $object_key, '/' );
        $credential  = "{$access_key}/{$date_stamp}/{$region}/{$service}/aws4_request";

        $query = http_build_query( [
            'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential'    => $credential,
            'X-Amz-Date'          => $amz_date,
            'X-Amz-Expires'       => $ttl,
            'X-Amz-SignedHeaders' => 'host',
        ] );

        $canonical_request = implode( "\n", [
            'GET',
            $uri,
            $query,
            "host:{$host}\n",
            'host',
            'UNSIGNED-PAYLOAD',
        ] );

        $string_to_sign = implode( "\n", [
            'AWS4-HMAC-SHA256',
            $amz_date,
            "{$date_stamp}/{$region}/{$service}/aws4_request",
            hash( 'sha256', $canonical_request ),
        ] );

        $signing_key = hash_hmac( 'sha256', 'aws4_request',
            hash_hmac( 'sha256', $service,
                hash_hmac( 'sha256', $region,
                    hash_hmac( 'sha256', $date_stamp, 'AWS4' . $secret_key, true ),
                true ),
            true ),
        true );

        $signature = hash_hmac( 'sha256', $string_to_sign, $signing_key );

        return "{$endpoint}{$uri}?{$query}&X-Amz-Signature={$signature}";
    }
}
