<?php
namespace CIAS_LMS\Services;

defined( 'ABSPATH' ) || exit;

/**
 * ZoomService
 *
 * Uses Composio (peripheral integration) to create and fetch Zoom meetings.
 * Core CIAS logic (auth, queues, evaluation) is NOT touched here.
 *
 * Composio handles: Zoom OAuth, token refresh, rate limits.
 * We only call Composio's REST Tool API.
 */
class ZoomService {

    private const COMPOSIO_API = 'https://backend.composio.dev/api/v1/actions/execute';

    /**
     * Get the Zoom join link for a live lesson.
     * Creates the meeting if not yet scheduled.
     *
     * @return array|\WP_Error  { join_url, start_time, duration_mins }
     */
    public static function get_join_link( int $lesson_id, int $student_id ): array|\WP_Error {
        global $wpdb;

        // Check if meeting already exists
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT ls.join_url, ls.start_time, ls.duration_mins
             FROM {$wpdb->prefix}cias_lms_live_schedule ls
             WHERE ls.lesson_id = %d",
            $lesson_id
        ) );

        if ( $row ) {
            return [
                'join_url'     => $row->join_url,
                'start_time'   => $row->start_time,
                'duration_mins' => $row->duration_mins,
            ];
        }

        // Fetch lesson details to create the meeting
        $lesson = $wpdb->get_row( $wpdb->prepare(
            "SELECT l.title, l.duration_secs FROM {$wpdb->prefix}cias_lms_lessons l WHERE l.id = %d",
            $lesson_id
        ) );

        if ( ! $lesson ) {
            return new \WP_Error( 'not_found', 'Lesson not found.', [ 'status' => 404 ] );
        }

        // Create Zoom meeting via Composio
        $meeting = self::create_zoom_meeting_via_composio(
            $lesson->title,
            (int) ceil( $lesson->duration_secs / 60 ) ?: 60
        );

        if ( is_wp_error( $meeting ) ) return $meeting;

        // Store in DB for reuse
        $wpdb->insert( $wpdb->prefix . 'cias_lms_live_schedule', [
            'lesson_id'       => $lesson_id,
            'zoom_meeting_id' => $meeting['id'],
            'join_url'        => $meeting['join_url'],
            'start_time'      => $meeting['start_time'],
            'duration_mins'   => $meeting['duration'],
        ] );

        return [
            'join_url'     => $meeting['join_url'],
            'start_time'   => $meeting['start_time'],
            'duration_mins' => $meeting['duration'],
        ];
    }

    /**
     * Create a Zoom meeting via Composio Tool Router.
     * Composio manages Zoom OAuth — we just call the action.
     */
    private static function create_zoom_meeting_via_composio(
        string $title,
        int    $duration_mins
    ): array|\WP_Error {
        $api_key = defined( 'CIAS_COMPOSIO_API_KEY' ) ? CIAS_COMPOSIO_API_KEY : '';

        if ( ! $api_key ) {
            return new \WP_Error( 'config_error', 'Composio API key not configured.', [ 'status' => 500 ] );
        }

        $response = wp_remote_post( self::COMPOSIO_API, [
            'timeout' => 15,
            'headers' => [
                'x-api-key'    => $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode( [
                'actionName' => 'ZOOM_MEETINGS_CREATE_MEETING',
                'input'      => [
                    'topic'    => '[CIAS] ' . $title,
                    'type'     => 2, // scheduled
                    'duration' => $duration_mins,
                    'settings' => [
                        'waiting_room'       => true,
                        'join_before_host'   => false,
                        'mute_upon_entry'    => true,
                        'auto_recording'     => 'cloud',
                    ],
                ],
            ] ),
        ] );

        if ( is_wp_error( $response ) ) return $response;

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['successfull'] ) || empty( $body['data']['join_url'] ) ) {
            return new \WP_Error(
                'composio_error',
                'Failed to create Zoom meeting: ' . ( $body['error'] ?? 'unknown' ),
                [ 'status' => 500 ]
            );
        }

        return [
            'id'         => $body['data']['id']         ?? '',
            'join_url'   => $body['data']['join_url']   ?? '',
            'start_time' => $body['data']['start_time'] ?? gmdate( 'Y-m-d H:i:s' ),
            'duration'   => $body['data']['duration']   ?? $duration_mins,
        ];
    }
}
