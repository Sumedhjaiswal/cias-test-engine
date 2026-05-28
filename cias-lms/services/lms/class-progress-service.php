<?php
namespace CIAS_LMS\Services;

defined( 'ABSPATH' ) || exit;

class ProgressService {

    public static function upsert( int $student_id, int $lesson_id, int $watch_secs, bool $completed ): void {
        global $wpdb;

        $lesson = $wpdb->get_row( $wpdb->prepare(
            "SELECT course_id FROM {$wpdb->prefix}cias_lms_lessons WHERE id = %d", $lesson_id
        ) );
        if ( ! $lesson ) return;

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}cias_lms_progress
             WHERE student_id = %d AND lesson_id = %d",
            $student_id, $lesson_id
        ) );

        if ( $existing ) {
            $wpdb->update(
                $wpdb->prefix . 'cias_lms_progress',
                [
                    'watch_secs'   => $watch_secs,
                    'completed'    => (int) $completed,
                    'completed_at' => $completed ? current_time( 'mysql', true ) : null,
                ],
                [ 'student_id' => $student_id, 'lesson_id' => $lesson_id ]
            );
        } else {
            $wpdb->insert( $wpdb->prefix . 'cias_lms_progress', [
                'student_id'  => $student_id,
                'lesson_id'   => $lesson_id,
                'course_id'   => $lesson->course_id,
                'watch_secs'  => $watch_secs,
                'completed'   => (int) $completed,
                'completed_at' => $completed ? current_time( 'mysql', true ) : null,
            ] );
        }
    }

    public static function get_by_course( int $student_id, int $course_id ): array {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT lesson_id, watch_secs, completed, completed_at
             FROM {$wpdb->prefix}cias_lms_progress
             WHERE student_id = %d AND course_id = %d",
            $student_id, $course_id
        ), ARRAY_A );

        // Return keyed by lesson_id for easy frontend lookup
        $result = [];
        foreach ( $rows as $row ) {
            $result[ $row['lesson_id'] ] = [
                'watch_secs'   => (int) $row['watch_secs'],
                'completed'    => (bool) $row['completed'],
                'completed_at' => $row['completed_at'],
            ];
        }
        return $result;
    }
}
