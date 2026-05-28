<?php
namespace CIAS_LMS\Services;

defined( 'ABSPATH' ) || exit;

class EnrollService {

    public static function enroll( int $student_id, int $course_id ): bool|\WP_Error {
        global $wpdb;

        // Verify course exists and is published
        $course = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, title, access_type FROM {$wpdb->prefix}cias_lms_courses
             WHERE id = %d AND status = 'published'",
            $course_id
        ) );

        if ( ! $course ) {
            return new \WP_Error( 'not_found', 'Course not found.', [ 'status' => 404 ] );
        }

        // Already enrolled?
        if ( self::is_enrolled( $student_id, $course_id ) ) {
            return true;
        }

        $wpdb->insert( $wpdb->prefix . 'cias_lms_enrollments', [
            'student_id' => $student_id,
            'course_id'  => $course_id,
            'status'     => 'active',
        ] );

        // WhatsApp confirmation
        NotificationService::enrollment_confirmed( $student_id, $course->title );

        return true;
    }

    public static function is_enrolled( int $student_id, int $course_id ): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}cias_lms_enrollments
             WHERE student_id = %d AND course_id = %d AND status = 'active'
             AND (expires_at IS NULL OR expires_at > NOW())",
            $student_id, $course_id
        ) );
    }

    public static function can_access_lesson( int $student_id, int $lesson_id ): bool {
        global $wpdb;

        $lesson = $wpdb->get_row( $wpdb->prepare(
            "SELECT l.course_id, l.is_preview, c.access_type
             FROM {$wpdb->prefix}cias_lms_lessons l
             JOIN {$wpdb->prefix}cias_lms_courses c ON c.id = l.course_id
             WHERE l.id = %d",
            $lesson_id
        ) );

        if ( ! $lesson ) return false;
        if ( $lesson->is_preview ) return true;             // preview lesson: open
        if ( $lesson->access_type === 'free' ) return true; // free course: open

        return self::is_enrolled( $student_id, (int) $lesson->course_id );
    }

    public static function get_enrolled_courses( int $student_id ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT c.id, c.title, c.slug, c.description, c.thumbnail_url,
                    e.enrolled_at, e.expires_at
             FROM {$wpdb->prefix}cias_lms_enrollments e
             JOIN {$wpdb->prefix}cias_lms_courses c ON c.id = e.course_id
             WHERE e.student_id = %d AND e.status = 'active'
             ORDER BY e.enrolled_at DESC",
            $student_id
        ), ARRAY_A );
    }

    public static function get_course_with_lessons( int $course_id, int $student_id ): ?array {
        global $wpdb;

        $course = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, title, slug, description, thumbnail_url
             FROM {$wpdb->prefix}cias_lms_courses WHERE id = %d",
            $course_id
        ), ARRAY_A );

        if ( ! $course ) return null;

        $sections = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, title, sort_order
             FROM {$wpdb->prefix}cias_lms_sections
             WHERE course_id = %d ORDER BY sort_order",
            $course_id
        ), ARRAY_A );

        foreach ( $sections as &$section ) {
            $lessons = $wpdb->get_results( $wpdb->prepare(
                "SELECT l.id, l.title, l.type, l.duration_secs, l.is_preview, l.sort_order,
                        p.completed, p.watch_secs
                 FROM {$wpdb->prefix}cias_lms_lessons l
                 LEFT JOIN {$wpdb->prefix}cias_lms_progress p
                    ON p.lesson_id = l.id AND p.student_id = %d
                 WHERE l.section_id = %d
                 ORDER BY l.sort_order",
                $student_id, $section['id']
            ), ARRAY_A );

            // Never expose vimeo_video_id or r2_pdf_key to client listing
            $section['lessons'] = array_map( static function ( $l ) {
                return [
                    'id'           => $l['id'],
                    'title'        => $l['title'],
                    'type'         => $l['type'],
                    'duration_secs' => $l['duration_secs'],
                    'is_preview'   => (bool) $l['is_preview'],
                    'sort_order'   => $l['sort_order'],
                    'completed'    => (bool) $l['completed'],
                    'watch_secs'   => (int) $l['watch_secs'],
                ];
            }, $lessons );
        }

        $course['sections'] = $sections;
        return $course;
    }
}
