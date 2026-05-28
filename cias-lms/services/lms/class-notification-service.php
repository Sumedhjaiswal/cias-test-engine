<?php
namespace CIAS_LMS\Services;

defined( 'ABSPATH' ) || exit;

/**
 * NotificationService
 *
 * Sends WhatsApp notifications via AiSensy REST API directly.
 * NOT via Composio — AiSensy is called directly per CIAS architecture rules.
 *
 * Triggers: enrollment confirmed, new lesson available, live class reminder (1hr before).
 */
class NotificationService {

    private const AISENSY_API = 'https://backend.aisensy.com/campaign/t1/api/v2';

    /**
     * Send enrollment confirmation.
     */
    public static function enrollment_confirmed( int $student_id, string $course_title ): void {
        $phone = get_user_meta( $student_id, 'phone', true );
        $name  = get_userdata( $student_id )->display_name ?? 'Student';
        if ( ! $phone ) return;

        self::send( $phone, 'cias_lms_enrollment', [ $name, $course_title ] );
    }

    /**
     * Send live class reminder — triggered by wp-cron 1 hour before.
     */
    public static function live_class_reminder( int $student_id, string $lesson_title, string $join_url ): void {
        $phone = get_user_meta( $student_id, 'phone', true );
        $name  = get_userdata( $student_id )->display_name ?? 'Student';
        if ( ! $phone ) return;

        self::send( $phone, 'cias_lms_live_reminder', [ $name, $lesson_title, $join_url ] );
    }

    /**
     * Core AiSensy send.
     */
    private static function send( string $phone, string $campaign, array $params ): void {
        $api_key = defined( 'CIAS_AISENSY_API_KEY' ) ? CIAS_AISENSY_API_KEY : '';
        if ( ! $api_key ) return;

        // Normalize phone — ensure 91 prefix, digits only
        $phone = preg_replace( '/\D/', '', $phone );
        if ( ! str_starts_with( $phone, '91' ) ) {
            $phone = '91' . $phone;
        }

        wp_remote_post( self::AISENSY_API, [
            'timeout' => 10,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [
                'apiKey'         => $api_key,
                'campaignName'   => $campaign,
                'destination'    => $phone,
                'userName'       => 'CIAS',
                'templateParams' => $params,
            ] ),
        ] );
    }
}
