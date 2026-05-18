<?php
/**
 * CIAS Phase A – A4 / A5 / A6: Chat History Recorder
 *
 * A4 – Records every caig_guru_chat message to {prefix}cias_chat_messages.
 * A5 – Images attached in chat → WP Media Library, media_id stored on row.
 * A6 – Classifies every user message via CIAS_Message_Classifier.
 *
 * No front-end UI changes. Silent background recording.
 *
 * Hooks fired by class-cias-ajax.php (patched in Phase A merge):
 *   do_action( 'cias_guru_user_message',      [...] )
 *   do_action( 'cias_guru_assistant_message', [...] )
 *
 * @package CIAS\PhaseA
 * @since   3.18.0
 */
defined( 'ABSPATH' ) || exit;

class CIAS_Chat_History {

    public static function init(): void {
        add_action( 'cias_guru_user_message',      [ __CLASS__, 'record_user'      ], 10, 1 );
        add_action( 'cias_guru_assistant_message', [ __CLASS__, 'record_assistant' ], 10, 1 );
    }

    // ── Recorders ─────────────────────────────────────────────────────────────

    public static function record_user( array $args ): void {
        $args = self::sanitize( $args );
        if ( ! $args ) return;

        $media_id  = null;
        $media_url = null;
        $has_image = false;

        // A5 – Store image in Media Library if present
        if ( ! empty( $args['image_data'] ) ) {
            [ $media_id, $media_url ] = self::store_image(
                $args['image_data'],
                $args['image_mime'] ?? 'image/jpeg',
                $args['image_name'] ?? 'chat-image',
                $args['user_id'],
                $args['session_id']
            );
            $has_image = (bool) $media_id;
        }

        // A6 – Classify
        $message_type = CIAS_Message_Classifier::classify( $args['body'], $has_image );

        // A4 – Write row
        self::insert_message( [
            'session_id'      => $args['session_id'],
            'user_id'         => $args['user_id'],
            'role'            => 'user',
            'body'            => $args['body'],
            'message_type'    => $message_type,
            'media_id'        => $media_id,
            'media_url'       => $media_url,
            'tokens_used'     => $args['tokens']  ?? null,
            'credits_charged' => $args['credits'] ?? null,
        ] );

        do_action( 'cias_chat_message_recorded', $args, $message_type, $media_id );
    }

    public static function record_assistant( array $args ): void {
        $args = self::sanitize( $args );
        if ( ! $args ) return;

        self::insert_message( [
            'session_id'  => $args['session_id'],
            'user_id'     => $args['user_id'],
            'role'        => 'assistant',
            'body'        => $args['body'],
            'tokens_used' => $args['tokens'] ?? null,
        ] );
    }

    // ── DB helper ─────────────────────────────────────────────────────────────

    private static function insert_message( array $data ): bool {
        global $wpdb;
        $allowed = ['session_id','user_id','role','body','message_type','media_id','media_url','tokens_used','credits_charged'];
        $row = array_intersect_key( $data, array_flip($allowed) );
        return (bool) $wpdb->insert( $wpdb->prefix . 'cias_chat_messages', $row );
    }

    // ── A5: Image → WP Media Library ──────────────────────────────────────────

    private static function store_image( string $data, string $mime, string $filename, int $user_id, string $session_id ): array {
        // Strip data-URI prefix
        if ( str_contains( $data, ',' ) ) {
            $data = preg_replace( '/^data:[^;]+;base64,/', '', $data );
        }

        $binary = base64_decode( $data );
        if ( ! $binary || strlen($binary) < 100 ) return [null, null];

        $max = (int) apply_filters( 'cias_chat_image_max_bytes', 8 * 1024 * 1024 );
        if ( strlen($binary) > $max ) return [null, null];

        $ext_map = [ 'image/jpeg'=>'jpg', 'image/png'=>'png', 'image/gif'=>'gif', 'image/webp'=>'webp' ];
        $ext     = $ext_map[$mime] ?? 'jpg';

        $upload = wp_upload_dir();
        if ( ! empty($upload['error']) ) return [null, null];

        $safe      = sanitize_file_name( preg_replace('/\.[a-z]+$/', '', $filename) );
        $file_name = 'cias-guru-' . gmdate('Ymd-His') . '-' . substr($session_id,-6) . '-' . $safe . '.' . $ext;
        $file_path = trailingslashit( $upload['path'] ) . $file_name;

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        if ( file_put_contents( $file_path, $binary ) === false ) return [null, null];
        chmod( $file_path, 0644 );

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attach_id = wp_insert_attachment([
            'post_mime_type' => $mime,
            'post_title'     => 'AI Guru Chat Image ' . gmdate('Y-m-d H:i:s'),
            'post_status'    => 'inherit',
            'post_author'    => $user_id,
        ], $file_path);

        if ( is_wp_error($attach_id) ) { @unlink($file_path); return [null,null]; }

        wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata($attach_id, $file_path) );
        update_post_meta( $attach_id, '_cias_chat_session', $session_id );
        update_post_meta( $attach_id, '_cias_chat_user',    $user_id    );

        return [ $attach_id, wp_get_attachment_url($attach_id) ?: null ];
    }

    // ── Sanitise args ─────────────────────────────────────────────────────────

    private static function sanitize( array $args ): ?array {
        $session_id = sanitize_text_field( $args['session_id'] ?? '' );
        $user_id    = (int) ( $args['user_id'] ?? 0 );
        $body       = wp_kses_post( $args['body'] ?? '' );
        if ( ! $session_id || ! $user_id || ! $body ) return null;
        return array_merge( $args, [ 'session_id'=>$session_id, 'user_id'=>$user_id, 'body'=>$body ] );
    }
}
