<?php
/**
 * CIAS Phase B – Cloudflare R2 Client
 *
 * S3-compatible API client using only PHP + curl (no Composer, no AWS SDK).
 * Implements AWS Signature Version 4 for authentication.
 *
 * Configure via wp-config.php (preferred) or WP options:
 *   define( 'CIAS_R2_ACCOUNT_ID',  'xxx' );
 *   define( 'CIAS_R2_ACCESS_KEY',  'xxx' );
 *   define( 'CIAS_R2_SECRET_KEY',  'xxx' );
 *   define( 'CIAS_R2_BUCKET',      'cias-uploads' );
 *   define( 'CIAS_R2_PUBLIC_URL',  'https://cdn.yourdomain.com' ); // optional CDN
 *
 * @package CIAS\PhaseB
 * @since   3.19.0
 */
defined( 'ABSPATH' ) || exit;

class CIAS_R2 {

    // ── Config ────────────────────────────────────────────────────────────────

    public static function account_id(): string { return self::cfg( 'CIAS_R2_ACCOUNT_ID',  'r2_account_id' ); }
    public static function access_key(): string { return self::cfg( 'CIAS_R2_ACCESS_KEY',   'r2_access_key' ); }
    public static function secret_key(): string { return self::cfg( 'CIAS_R2_SECRET_KEY',   'r2_secret_key' ); }
    public static function bucket():     string { return self::cfg( 'CIAS_R2_BUCKET',        'r2_bucket', 'cias-uploads' ); }
    public static function public_url(): string { return rtrim( self::cfg( 'CIAS_R2_PUBLIC_URL', 'r2_public_url', '' ), '/' ); }

    private static function endpoint(): string {
        return 'https://' . self::account_id() . '.r2.cloudflarestorage.com';
    }

    private static function cfg( string $const, string $option, string $default = '' ): string {
        if ( defined( $const ) && constant( $const ) ) return (string) constant( $const );
        return (string) get_option( 'cias_' . $option, $default );
    }

    public static function is_configured(): bool {
        return self::account_id() && self::access_key() && self::secret_key() && self::bucket();
    }

    // ── Pre-signed URL (browser uploads direct to R2) ─────────────────────────

    /**
     * Generate a pre-signed PUT URL valid for $ttl seconds.
     * Browser uploads the binary directly to R2 — WordPress never handles the file bytes.
     *
     * @param string $object_key  e.g. 'answers/2026/05/user-42-abc123.jpg'
     * @param string $mime_type   e.g. 'image/jpeg'
     * @param int    $ttl         Seconds until URL expires (default 300 = 5 min)
     * @return string|null  Signed URL, or null on config error
     */
    public static function presigned_put_url( string $object_key, string $mime_type = 'image/jpeg', int $ttl = 300 ): ?string {
        if ( ! self::is_configured() ) return null;

        $bucket  = self::bucket();
        $region  = 'auto';
        $service = 's3';
        $host    = self::account_id() . '.r2.cloudflarestorage.com';
        $datetime= gmdate( 'Ymd\THis\Z' );
        $date    = gmdate( 'Ymd' );

        // Canonical request components
        $credential_scope = "{$date}/{$region}/{$service}/aws4_request";
        $credential       = self::access_key() . '/' . $credential_scope;

        $query_params = [
            'X-Amz-Algorithm'  => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $credential,
            'X-Amz-Date'       => $datetime,
            'X-Amz-Expires'    => (string) $ttl,
            'X-Amz-SignedHeaders' => 'content-type;host',
        ];
        ksort( $query_params );
        $canonical_qs = http_build_query( $query_params );

        $canonical_request = implode( "\n", [
            'PUT',
            '/' . $bucket . '/' . ltrim( $object_key, '/' ),
            $canonical_qs,
            "content-type:{$mime_type}\nhost:{$host}\n",
            'content-type;host',
            'UNSIGNED-PAYLOAD',
        ] );

        $string_to_sign = implode( "\n", [
            'AWS4-HMAC-SHA256',
            $datetime,
            $credential_scope,
            hash( 'sha256', $canonical_request ),
        ] );

        $signing_key = self::derive_signing_key( self::secret_key(), $date, $region, $service );
        $signature   = hash_hmac( 'sha256', $string_to_sign, $signing_key );

        $url = self::endpoint() . '/' . $bucket . '/' . ltrim( $object_key, '/' )
             . '?' . $canonical_qs . '&X-Amz-Signature=' . $signature;

        return $url;
    }

    // ── Upload (from worker — for small OCR thumbnails, etc.) ─────────────────

    /**
     * PUT an object to R2 directly from PHP (server-side).
     * Only use this for small files (<5MB). Large files must use pre-signed URLs.
     *
     * @param string $object_key
     * @param string $body        Raw binary content
     * @param string $mime_type
     * @return bool
     */
    public static function put_object( string $object_key, string $body, string $mime_type = 'application/octet-stream' ): bool {
        if ( ! self::is_configured() ) return false;

        $bucket   = self::bucket();
        $region   = 'auto';
        $service  = 's3';
        $host     = self::account_id() . '.r2.cloudflarestorage.com';
        $datetime = gmdate( 'Ymd\THis\Z' );
        $date     = gmdate( 'Ymd' );
        $hash     = hash( 'sha256', $body );
        $path     = '/' . $bucket . '/' . ltrim( $object_key, '/' );

        $headers_to_sign = [
            'content-type' => $mime_type,
            'host'         => $host,
            'x-amz-content-sha256' => $hash,
            'x-amz-date'   => $datetime,
        ];
        ksort( $headers_to_sign );

        $canonical_headers   = implode( "\n", array_map( fn($k,$v) => "{$k}:{$v}", array_keys($headers_to_sign), $headers_to_sign ) ) . "\n";
        $signed_headers_list = implode( ';', array_keys($headers_to_sign) );

        $canonical_request = "PUT\n{$path}\n\n{$canonical_headers}\n{$signed_headers_list}\n{$hash}";

        $credential_scope = "{$date}/{$region}/{$service}/aws4_request";
        $string_to_sign   = "AWS4-HMAC-SHA256\n{$datetime}\n{$credential_scope}\n" . hash('sha256', $canonical_request);
        $signing_key      = self::derive_signing_key( self::secret_key(), $date, $region, $service );
        $signature        = hash_hmac( 'sha256', $string_to_sign, $signing_key );

        $auth_header = "AWS4-HMAC-SHA256 Credential=" . self::access_key() . "/{$credential_scope}, "
                     . "SignedHeaders={$signed_headers_list}, Signature={$signature}";

        $ch = curl_init( self::endpoint() . $path );
        curl_setopt_array( $ch, [
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                "Authorization: {$auth_header}",
                "Content-Type: {$mime_type}",
                "x-amz-content-sha256: {$hash}",
                "x-amz-date: {$datetime}",
                "Content-Length: " . strlen( $body ),
            ],
        ] );
        $response = curl_exec( $ch );
        $http     = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        curl_close( $ch );

        return $http === 200;
    }

    /**
     * Delete an object from R2.
     */
    public static function delete_object( string $object_key ): bool {
        if ( ! self::is_configured() ) return false;

        $bucket   = self::bucket();
        $region   = 'auto';
        $service  = 's3';
        $host     = self::account_id() . '.r2.cloudflarestorage.com';
        $datetime = gmdate( 'Ymd\THis\Z' );
        $date     = gmdate( 'Ymd' );
        $hash     = hash( 'sha256', '' );
        $path     = '/' . $bucket . '/' . ltrim( $object_key, '/' );

        $headers_to_sign = [
            'host'                 => $host,
            'x-amz-content-sha256' => $hash,
            'x-amz-date'           => $datetime,
        ];
        ksort( $headers_to_sign );
        $canonical_headers   = implode( "\n", array_map( fn($k,$v) => "{$k}:{$v}", array_keys($headers_to_sign), $headers_to_sign ) ) . "\n";
        $signed_headers_list = implode( ';', array_keys($headers_to_sign) );

        $canonical_request = "DELETE\n{$path}\n\n{$canonical_headers}\n{$signed_headers_list}\n{$hash}";
        $credential_scope  = "{$date}/{$region}/{$service}/aws4_request";
        $string_to_sign    = "AWS4-HMAC-SHA256\n{$datetime}\n{$credential_scope}\n" . hash('sha256', $canonical_request);
        $signing_key       = self::derive_signing_key( self::secret_key(), $date, $region, $service );
        $signature         = hash_hmac( 'sha256', $string_to_sign, $signing_key );

        $auth = "AWS4-HMAC-SHA256 Credential=" . self::access_key() . "/{$credential_scope}, "
              . "SignedHeaders={$signed_headers_list}, Signature={$signature}";

        $ch = curl_init( self::endpoint() . $path );
        curl_setopt_array( $ch, [
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [ "Authorization: {$auth}", "x-amz-content-sha256: {$hash}", "x-amz-date: {$datetime}" ],
        ] );
        $http = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        curl_exec( $ch );
        curl_close( $ch );

        return $http === 204;
    }

    // ── URL helpers ───────────────────────────────────────────────────────────

    /**
     * Return the public URL for an object key.
     * If CIAS_R2_PUBLIC_URL is set, use that CDN domain.
     * Otherwise fall back to the R2 endpoint (not publicly accessible by default).
     */
    public static function public_url_for( string $object_key ): string {
        $cdn = self::public_url();
        if ( $cdn ) {
            return $cdn . '/' . ltrim( $object_key, '/' );
        }
        return self::endpoint() . '/' . self::bucket() . '/' . ltrim( $object_key, '/' );
    }

    /**
     * Build a structured R2 key for an answer submission.
     * Format: answers/{year}/{month}/u{user_id}/{uuid}.{ext}
     */
    public static function make_answer_key( int $user_id, string $mime ): string {
        $ext  = match( $mime ) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
            'application/pdf' => 'pdf',
            default      => 'bin',
        };
        return sprintf(
            'answers/%s/%s/u%d/%s.%s',
            gmdate( 'Y' ), gmdate( 'm' ),
            $user_id,
            wp_generate_uuid4(),
            $ext
        );
    }

    // ── AWS Signature v4 helpers ──────────────────────────────────────────────

    private static function derive_signing_key( string $secret, string $date, string $region, string $service ): string {
        $k_date    = hash_hmac( 'sha256', $date,              'AWS4' . $secret, true );
        $k_region  = hash_hmac( 'sha256', $region,            $k_date,          true );
        $k_service = hash_hmac( 'sha256', $service,           $k_region,        true );
        return       hash_hmac( 'sha256', 'aws4_request',     $k_service,       true );
    }

    // ── Download object (for worker use — OCR) ────────────────────────────────

    /**
     * Download an R2 object and return raw bytes.
     * Workers download the image, pass bytes to Claude Vision, then delete temp copy.
     */
    public static function get_object( string $object_key ): ?string {
        if ( ! self::is_configured() ) return null;

        $bucket   = self::bucket();
        $region   = 'auto';
        $service  = 's3';
        $host     = self::account_id() . '.r2.cloudflarestorage.com';
        $datetime = gmdate( 'Ymd\THis\Z' );
        $date     = gmdate( 'Ymd' );
        $hash     = hash( 'sha256', '' );
        $path     = '/' . $bucket . '/' . ltrim( $object_key, '/' );

        $headers_to_sign = [
            'host'                 => $host,
            'x-amz-content-sha256' => $hash,
            'x-amz-date'           => $datetime,
        ];
        ksort( $headers_to_sign );
        $canonical_headers   = implode( "\n", array_map( fn($k,$v) => "{$k}:{$v}", array_keys($headers_to_sign), $headers_to_sign ) ) . "\n";
        $signed_headers_list = implode( ';', array_keys($headers_to_sign) );
        $canonical_request   = "GET\n{$path}\n\n{$canonical_headers}\n{$signed_headers_list}\n{$hash}";
        $credential_scope    = "{$date}/{$region}/{$service}/aws4_request";
        $string_to_sign      = "AWS4-HMAC-SHA256\n{$datetime}\n{$credential_scope}\n" . hash('sha256', $canonical_request);
        $signing_key         = self::derive_signing_key( self::secret_key(), $date, $region, $service );
        $signature           = hash_hmac( 'sha256', $string_to_sign, $signing_key );

        $auth = "AWS4-HMAC-SHA256 Credential=" . self::access_key() . "/{$credential_scope}, "
              . "SignedHeaders={$signed_headers_list}, Signature={$signature}";

        $ch = curl_init( self::endpoint() . $path );
        curl_setopt_array( $ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => [ "Authorization: {$auth}", "x-amz-content-sha256: {$hash}", "x-amz-date: {$datetime}" ],
        ] );
        $body = curl_exec( $ch );
        $http = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        curl_close( $ch );

        return ( $http === 200 && $body !== false ) ? $body : null;
    }
}
