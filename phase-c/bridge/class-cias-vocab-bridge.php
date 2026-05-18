<?php
/**
 * CIAS Vocabulary Bridge
 *
 * Makes cias-test-engine and vocabulary-app aware of each other
 * WITHOUT modifying either plugin's core files.
 *
 * How it works:
 *   - Hooks into filters that CIAS_App_Data exposes (cias_due_words,
 *     cias_words_mastered, cias_vocab_streak, cias_activity_days)
 *   - Routes AJAX action 'cias_vocab_rate' through Vocab_DB::record_response()
 *   - Routes AJAX action 'cias_vocab_session' through Vocab_DB::get_session_words()
 *   - Exposes 'cias_vocab_bridge_active' flag in ciasApp JS bootstrap
 *   - Adds CIAS credit/streak data to the vocab portal stats via hook
 *
 * Activation: runs only when BOTH plugins are active (class_exists checks).
 * If either plugin is deactivated, this class does nothing.
 *
 * @package CIAS
 * @since   3.19.1
 */
defined( 'ABSPATH' ) || exit;

class CIAS_Vocab_Bridge {

    private static bool $booted = false;

    public static function init(): void {
        // Only activate when both plugins are fully loaded
        add_action( 'plugins_loaded', [ __CLASS__, 'maybe_boot' ], 50 );
    }

    public static function maybe_boot(): void {
        if ( self::$booted ) return;

        // Both plugins must be active
        if ( ! class_exists( 'Vocab_DB' ) || ! class_exists( 'CIAS_App_Data' ) ) return;

        self::$booted = true;
        self::register_data_filters();
        self::register_ajax_handlers();
        self::register_portal_hook();

        // Tell the JS bootstrap that the bridge is active
        add_filter( 'cias_app_bootstrap_extra', [ __CLASS__, 'add_bootstrap_flag' ] );
    }

    // ── Data filters: CIAS reads from vocab plugin ─────────────────────────────

    private static function register_data_filters(): void {

        // Override due words — use Vocab_DB::get_session_words() as source of truth
        add_filter( 'cias_due_words', function ( array $fallback, int $user_id ): array {
            $db    = new Vocab_DB();
            $words = $db->get_session_words( $user_id );
            if ( empty( $words ) ) return $fallback;
            return array_map( [ __CLASS__, 'normalize_vocab_word' ], $words );
        }, 10, 2 );

        // Override words_mastered stat
        add_filter( 'cias_words_mastered', function ( int $fallback, int $user_id ): int {
            $db    = new Vocab_DB();
            $stats = $db->get_user_stats( $user_id );
            return (int) ( $stats['mastered'] ?? $fallback );
        }, 10, 2 );

        // Override streak — merge vocab streak with CIAS activity streak (take the higher)
        add_filter( 'cias_vocab_streak', function ( int $fallback, int $user_id ): int {
            $db    = new Vocab_DB();
            $stats = $db->get_user_stats( $user_id );
            return max( (int)( $stats['streak'] ?? 0 ), $fallback );
        }, 10, 2 );

        // Override activity days — merge vocab last_reviewed days with CIAS chat days
        add_filter( 'cias_activity_days', function ( array $cias_days, int $user_id ): array {
            global $wpdb;
            $vocab_days = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT DATE(last_reviewed) AS d
                 FROM {$wpdb->prefix}vocab_progress
                 WHERE user_id = %d AND last_reviewed IS NOT NULL
                   AND last_reviewed >= DATE_SUB(CURDATE(), INTERVAL 31 DAY)
                 ORDER BY d DESC",
                $user_id
            ) );
            // Union: unique dates from both sources
            return array_values( array_unique( array_merge( $cias_days, $vocab_days ) ) );
        }, 10, 2 );

        // Override total words count from vocab table
        add_filter( 'cias_total_words', function ( int $fallback, int $user_id ): int {
            global $wpdb;
            $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}vocab_words" );
            return $total ?: $fallback;
        }, 10, 2 );

        // Override accuracy from vocab progress
        add_filter( 'cias_vocab_accuracy', function ( int $fallback, int $user_id ): int {
            $db    = new Vocab_DB();
            $stats = $db->get_user_stats( $user_id );
            return (int) ( $stats['accuracy'] ?? $fallback );
        }, 10, 2 );
    }

    // ── AJAX handlers: unified endpoints that route to vocab plugin ────────────

    private static function register_ajax_handlers(): void {

        // Vocabulary card rating → Vocab_DB::record_response()
        // Replaces CIAS_Frontend::ajax_vocab_rate() when bridge is active
        remove_action( 'wp_ajax_cias_vocab_rate', [ 'CIAS_Frontend', 'ajax_vocab_rate' ] );

        add_action( 'wp_ajax_cias_vocab_rate', function () {
            check_ajax_referer( 'cias_app_nonce', 'nonce' );
            $user_id = get_current_user_id();
            $word_id = (int) ( $_POST['word_id'] ?? 0 );
            $rating  = sanitize_key( $_POST['rating'] ?? 'good' );

            if ( ! $user_id || ! $word_id ) { wp_send_json_error( 'Invalid params', 400 ); }

            // Convert CIAS rating (hard/good/easy) to SM-2 quality (1/3/5)
            $quality = match ( $rating ) { 'easy' => 5, 'good' => 3, 'hard' => 1, default => 3 };

            $db     = new Vocab_DB();
            $result = $db->record_response( $user_id, $word_id, $quality );

            wp_send_json_success( [
                'word_id'      => $word_id,
                'next_review'  => $result['next_review'] ?? null,
                'ease_factor'  => round( $result['ease_factor'] ?? 2.5, 2 ),
                'interval'     => $result['interval'] ?? 1,
                'source'       => 'vocab_bridge',
            ] );
        } );

        // Fetch fresh session words → Vocab_DB::get_session_words()
        add_action( 'wp_ajax_cias_vocab_session', function () {
            check_ajax_referer( 'cias_app_nonce', 'nonce' );
            $user_id = get_current_user_id();
            if ( ! $user_id ) { wp_send_json_error( 'Not logged in', 401 ); }

            $db    = new Vocab_DB();
            $words = $db->get_session_words( $user_id );
            $normalized = array_map( [ __CLASS__, 'normalize_vocab_word' ], $words );

            // Also return MCQ distractors for quiz mode
            foreach ( $normalized as &$w ) {
                if ( $w['id'] ?? null ) {
                    $distractors = $db->get_mcq_distractors( $w['id'], 3 );
                    $w['distractors'] = array_map( fn( $d ) => $d->meaning, $distractors );
                }
            }

            wp_send_json_success( [
                'words'  => $normalized,
                'count'  => count( $normalized ),
                'source' => 'vocab_bridge',
            ] );
        } );

        // Fetch vocab dashboard stats → Vocab_DB::get_user_stats()
        add_action( 'wp_ajax_cias_vocab_stats', function () {
            check_ajax_referer( 'cias_app_nonce', 'nonce' );
            $user_id = get_current_user_id();
            if ( ! $user_id ) { wp_send_json_error( 'Not logged in', 401 ); }

            $db    = new Vocab_DB();
            $stats = $db->get_user_stats( $user_id );
            wp_send_json_success( array_merge( $stats, [ 'source' => 'vocab_bridge' ] ) );
        } );
    }

    // ── Portal hook: inject CIAS stats into vocab portal ──────────────────────

    private static function register_portal_hook(): void {
        // The vocab plugin's tpl_portal() already does class_exists('CIAS_DB') checks.
        // We add a WordPress filter so vocab portal can show AI credits without
        // knowing about CIAS internals.
        add_filter( 'vocab_portal_extra_stats', function ( array $stats, int $user_id ): array {
            if ( ! class_exists( 'CIAS_App_Data' ) ) return $stats;

            $credits = CIAS_App_Data::get_credits( $user_id );
            $stats['ai_credits_remaining'] = $credits['remaining'];
            $stats['ai_credits_monthly']   = $credits['monthly'];
            return $stats;
        }, 10, 2 );
    }

    // ── JS bootstrap flag ──────────────────────────────────────────────────────

    public static function add_bootstrap_flag( array $extra ): array {
        $user_id    = get_current_user_id();
        $db         = new Vocab_DB();
        $stats      = $db->get_user_stats( $user_id );

        $extra['vocab_bridge']         = true;
        $extra['vocab_total_words']    = (int) ( $stats['total_words']  ?? 0 );
        $extra['vocab_due_today']      = (int) ( $stats['due_today']    ?? 0 );
        $extra['vocab_mastered']       = (int) ( $stats['mastered']     ?? 0 );
        $extra['vocab_accuracy']       = (int) ( $stats['accuracy']     ?? 0 );
        $extra['vocab_streak']         = (int) ( $stats['streak']       ?? 0 );
        $extra['vocab_weak']           = (int) ( $stats['weak']         ?? 0 );
        $extra['vocab_learning']       = (int) ( $stats['learning']     ?? 0 );

        // AJAX actions the JS should use (bridge overrides these)
        $extra['vocab_rate_action']    = 'cias_vocab_rate';    // same action, bridge handles it
        $extra['vocab_session_action'] = 'cias_vocab_session';
        $extra['vocab_stats_action']   = 'cias_vocab_stats';

        return $extra;
    }

    // ── Normalizer: vocab plugin → CIAS JS expected format ────────────────────

    /**
     * Vocab_DB returns stdClass objects with: word, meaning, example,
     * synonyms, antonyms, usage_note, category, ease_factor, interval_days
     *
     * CIAS JS expects: id, word, definition, part_of_speech, difficulty,
     *                  example, synonyms, antonyms
     */
    public static function normalize_vocab_word( object|array $w ): array {
        $w = (array) $w;

        $ease       = (float) ( $w['ease_factor']   ?? 2.5 );
        $reviews    = (int)   ( $w['total_reviews']  ?? 0 );
        $quality    = (int)   ( $w['last_quality']   ?? 3 );

        // Derive difficulty from SM-2 ease factor
        if ( $reviews === 0 ) {
            $difficulty = 'new';
        } elseif ( $ease < 1.8 || $quality < 2 ) {
            $difficulty = 'hard';
        } elseif ( $ease < 2.3 ) {
            $difficulty = 'review';
        } else {
            $difficulty = 'easy';
        }

        return [
            'id'            => (int) ( $w['id'] ?? 0 ),
            'word'          => (string) ( $w['word']       ?? '' ),
            'definition'    => (string) ( $w['meaning']    ?? '' ),
            'part_of_speech'=> (string) ( $w['category']   ?? 'Word' ),
            'example'       => (string) ( $w['example']    ?? '' ),
            'synonyms'      => (string) ( $w['synonyms']   ?? '' ),
            'antonyms'      => (string) ( $w['antonyms']   ?? '' ),
            'usage_note'    => (string) ( $w['usage_note'] ?? '' ),
            'difficulty'    => $difficulty,
            'ease_factor'   => $ease,
            'interval_days' => (int) ( $w['interval_days'] ?? 1 ),
            'total_reviews' => $reviews,
        ];
    }

    // ── Health check (used by admin notice) ───────────────────────────────────

    public static function is_active(): bool {
        return self::$booted;
    }

    public static function status(): array {
        return [
            'vocab_plugin_active' => class_exists( 'Vocab_DB' ),
            'cias_plugin_active'  => class_exists( 'CIAS_App_Data' ),
            'bridge_active'       => self::$booted,
            'vocab_table_exists'  => self::table_exists( 'vocab_words' ),
            'progress_table_exists' => self::table_exists( 'vocab_progress' ),
        ];
    }

    private static function table_exists( string $suffix ): bool {
        global $wpdb;
        return (bool) $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $suffix )
        );
    }
}
