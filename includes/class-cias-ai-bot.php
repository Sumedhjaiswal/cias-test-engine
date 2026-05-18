<?php
if (!defined('ABSPATH')) exit;

/**
 * CIAS AI Study Bot
 * Handles student doubt-solving, credit management, Razorpay integration
 */
class CIAS_AI_Bot {

    const FREE_DAILY_LIMIT = 5;

    // ── Credit management ──────────────────────────────────

    public static function get_student_status(int $user_id): array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cias_ai_credits WHERE user_id=%d", $user_id
        ));
        if (!$row) {
            return [
                'access'       => 'free',
                'credits'      => 0,
                'revoked'      => false,
                'free_used_today' => self::get_free_usage_today($user_id),
                'free_limit'   => self::FREE_DAILY_LIMIT,
            ];
        }
        return [
            'access'          => $row->access_type,
            'credits'         => intval($row->credits_remaining),
            'revoked'         => (bool) $row->is_revoked,
            'free_used_today' => self::get_free_usage_today($user_id),
            'free_limit'      => self::FREE_DAILY_LIMIT,
        ];
    }

    public static function can_ask(int $user_id): array {
        $status = self::get_student_status($user_id);
        if ($status['revoked']) return ['allowed' => false, 'reason' => 'Bot access has been revoked by admin.'];

        if ($status['access'] === 'paid' || $status['access'] === 'unlimited') {
            if ($status['access'] === 'paid' && $status['credits'] <= 0)
                return ['allowed' => false, 'reason' => 'You have 0 credits remaining. Please purchase a credit pack.'];
            return ['allowed' => true, 'type' => $status['access'], 'credits' => $status['credits']];
        }

        // Free tier
        if ($status['free_used_today'] >= $status['free_limit'])
            return ['allowed' => false, 'reason' => "You've used {$status['free_limit']} free questions today. Buy a credit pack for unlimited access.", 'show_upgrade' => true];

        return ['allowed' => true, 'type' => 'free', 'remaining' => $status['free_limit'] - $status['free_used_today']];
    }

    public static function deduct_credit(int $user_id, string $access_type, string $session_id = ''): void {
        global $wpdb;
        $table = $wpdb->prefix . 'cias_ai_credits';
        if ($access_type === 'paid') {
            $wpdb->query($wpdb->prepare(
                "UPDATE $table SET credits_remaining = GREATEST(0, credits_remaining - 1), updated_at = NOW() WHERE user_id = %d",
                $user_id
            ));
            /**
             * Fired after a paid credit is consumed.
             * Phase A: CIAS_Credit_Log::record_usage() hooks here.
             *
             * @param int    $user_id
             * @param int    $credits_used  Always 1 per message
             * @param string $session_id
             */
            do_action('cias_credits_used', $user_id, 1, $session_id);
        }
    }

    public static function add_credits(int $user_id, int $credits, string $order_id = ''): void {
        global $wpdb;
        $table = $wpdb->prefix . 'cias_ai_credits';
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE user_id=%d", $user_id));
        if ($exists) {
            $wpdb->query($wpdb->prepare(
                "UPDATE $table SET credits_remaining = credits_remaining + %d, access_type='paid', updated_at=NOW() WHERE user_id=%d",
                $credits, $user_id
            ));
        } else {
            $wpdb->insert($table, [
                'user_id'          => $user_id,
                'access_type'      => 'paid',
                'credits_remaining'=> $credits,
                'is_revoked'       => 0,
                'created_at'       => current_time('mysql'),
                'updated_at'       => current_time('mysql'),
            ]);
        }
        // Log purchase in existing credit log
        $wpdb->insert($wpdb->prefix . 'cias_ai_credit_log', [
            'user_id'    => $user_id,
            'credits'    => $credits,
            'action'     => 'purchase',
            'order_id'   => $order_id,
            'created_at' => current_time('mysql'),
        ]);

        /**
         * Fired after credits are purchased.
         * Phase A: CIAS_Credit_Email sends confirmation; CIAS_Credit_Log writes richer log row.
         *
         * @param int    $user_id
         * @param int    $credits       Credits just added
         * @param string $order_id      Razorpay order ID
         * @param string $package_label Empty here; enriched by Phase A filter
         */
        do_action( 'cias_credits_purchased', $user_id, $credits, $order_id, '' );
    }

    /**
     * Manually adjust credits (admin). Fires 'cias_credits_adjusted' for Phase A.
     */
    public static function add_credits_manual( int $user_id, int $delta, string $note = '', int $admin_id = 0 ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'cias_ai_credits';
        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE user_id=%d", $user_id ) );
        if ( $exists ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE $table SET credits_remaining = GREATEST(0, credits_remaining + %d), updated_at=NOW() WHERE user_id=%d",
                $delta, $user_id
            ) );
        } else {
            $wpdb->insert( $table, [
                'user_id'           => $user_id,
                'access_type'       => 'paid',
                'credits_remaining' => max( 0, $delta ),
                'is_revoked'        => 0,
                'created_at'        => current_time( 'mysql' ),
                'updated_at'        => current_time( 'mysql' ),
            ] );
        }
        $wpdb->insert( $wpdb->prefix . 'cias_ai_credit_log', [
            'user_id'    => $user_id,
            'credits'    => $delta,
            'action'     => 'manual',
            'order_id'   => '',
            'note'       => $note,
            'created_at' => current_time( 'mysql' ),
        ] );
        do_action( 'cias_credits_adjusted', $user_id, $delta, $note, $admin_id ?: get_current_user_id() );
    }

    public static function revoke_access(int $user_id, bool $revoke = true): void {
        global $wpdb;
        $table = $wpdb->prefix . 'cias_ai_credits';
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE user_id=%d", $user_id));
        if ($exists) {
            $wpdb->update($table, ['is_revoked' => $revoke ? 1 : 0, 'updated_at' => current_time('mysql')], ['user_id' => $user_id]);
        } else {
            $wpdb->insert($table, [
                'user_id'          => $user_id,
                'access_type'      => 'free',
                'credits_remaining'=> 0,
                'is_revoked'       => $revoke ? 1 : 0,
                'created_at'       => current_time('mysql'),
                'updated_at'       => current_time('mysql'),
            ]);
        }
    }

    public static function grant_unlimited(int $user_id): void {
        global $wpdb;
        $table = $wpdb->prefix . 'cias_ai_credits';
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE user_id=%d", $user_id));
        if ($exists) {
            $wpdb->update($table, ['access_type' => 'unlimited', 'is_revoked' => 0, 'updated_at' => current_time('mysql')], ['user_id' => $user_id]);
        } else {
            $wpdb->insert($table, [
                'user_id'          => $user_id,
                'access_type'      => 'unlimited',
                'credits_remaining'=> 0,
                'is_revoked'       => 0,
                'created_at'       => current_time('mysql'),
                'updated_at'       => current_time('mysql'),
            ]);
        }
    }

    private static function get_free_usage_today(int $user_id): int {
        global $wpdb;
        $table = $wpdb->prefix . 'cias_ai_usage_log';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) return 0;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE user_id=%d AND context='bot' AND DATE(created_at)=%s",
            $user_id, current_time('Y-m-d')
        ));
    }

    // ── Answer generation ──────────────────────────────────

    public static function generate_answer(int $user_id, string $question, array $conversation_history = []): string {
        // Build student context
        $db = new CIAS_DB();
        $summary = $db->get_student_summary($user_id);
        $student = get_userdata($user_id);
        $name    = $student ? $student->display_name : 'Student';

        // Get enrolled batches/subjects for context
        global $wpdb;
        $subjects = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT s.name FROM ".CIAS_SUBJECTS." s
             JOIN ".CIAS_QUESTIONS." q ON q.subject_id=s.id
             JOIN ".CIAS_ATTEMPTS." a ON a.test_id IN (
                 SELECT test_id FROM ".CIAS_TEST_BATCH." tb
                 JOIN ".CIAS_ENROLLMENTS." e ON tb.batch_id=e.batch_id WHERE e.user_id=%d
             ) LIMIT 6", $user_id
        ));
        $subject_ctx = !empty($subjects) ? implode(', ', $subjects) : 'UPSC subjects';

        $weak_topics = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT tp.name FROM ".CIAS_TOPICS." tp
             JOIN ".CIAS_TOPIC_STATS." ts ON ts.topic_id=tp.id
             WHERE ts.user_id=%d AND ts.accuracy < 50 LIMIT 5", $user_id
        ));
        $weak_ctx = !empty($weak_topics) ? implode(', ', $weak_topics) : '';

        $system_context = "You are a UPSC coaching assistant for CIAS (Central India's Institute for Administrative Studies). "
            . "Student: {$name}. Enrolled subjects: {$subject_ctx}. "
            . ($weak_ctx ? "Weak areas: {$weak_ctx}. " : '')
            . ($summary['total'] > 0 ? "Tests taken: {$summary['total']}, avg score: {$summary['avg']}%. " : '')
            . "Answer UPSC exam doubts in simple language. Be concise (max 200 words). "
            . "For factual questions cite the source (Laxmikant chapter, NCERT, etc.) when known. "
            . "Support Hindi questions. Do not answer non-academic questions.";

        $messages = [];
        foreach ($conversation_history as $msg) {
            if (!empty($msg['role']) && !empty($msg['content'])) {
                $messages[] = ['role' => $msg['role'], 'content' => sanitize_textarea_field($msg['content'])];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $question];

        $key = CIAS_AI_Utils::get_api_key();
        if (empty($key)) return 'AI is not configured. Please contact your admin.';

        $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
            'timeout' => 30,
            'headers' => [
                'Content-Type'      => 'application/json',
                'x-api-key'         => $key,
                'anthropic-version' => '2023-06-01',
            ],
            'body' => wp_json_encode([
                'model'      => 'claude-haiku-4-5-20251001',
                'max_tokens' => 400,
                'system'     => $system_context,
                'messages'   => $messages,
            ]),
        ]);

        if (is_wp_error($response)) return 'Could not connect to AI. Please try again.';
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $text = trim($body['content'][0]['text'] ?? '');

        // Log
        CIAS_AI_Utils::log_usage(
            'claude-haiku-4-5-20251001',
            intval($body['usage']['input_tokens'] ?? 0),
            intval($body['usage']['output_tokens'] ?? 0),
            'bot',
            $user_id
        );

        return $text ?: 'Sorry, I could not generate an answer. Please try rephrasing your question.';
    }

    // ── Admin usage stats ──────────────────────────────────

    public static function get_admin_usage_stats(): array {
        global $wpdb;
        $log   = $wpdb->prefix . 'cias_ai_usage_log';
        $cred  = $wpdb->prefix . 'cias_ai_credits';
        if ($wpdb->get_var("SHOW TABLES LIKE '$log'") !== $log) return [];

        return $wpdb->get_results(
            "SELECT u.ID, u.display_name,
                COUNT(CASE WHEN DATE(l.created_at)=CURDATE() THEN 1 END) AS today_msgs,
                COUNT(CASE WHEN l.created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY) THEN 1 END) AS week_msgs,
                COUNT(*) AS total_msgs,
                ROUND(SUM(l.cost_usd),4) AS total_cost,
                c.access_type, c.credits_remaining, c.is_revoked
             FROM {$wpdb->users} u
             LEFT JOIN $log l ON l.user_id=u.ID AND l.context='bot'
             LEFT JOIN $cred c ON c.user_id=u.ID
             WHERE u.ID IN (
                 SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key='{$wpdb->prefix}capabilities'
                 AND meta_value LIKE '%vocab_student%'
             )
             GROUP BY u.ID, u.display_name, c.access_type, c.credits_remaining, c.is_revoked
             ORDER BY today_msgs DESC"
        );
    }

    // ── Razorpay order creation ────────────────────────────

    public static function create_razorpay_order(int $amount_paise, string $receipt): array {
        $key_id     = defined('CIAS_RAZORPAY_KEY_ID')     && CIAS_RAZORPAY_KEY_ID     ? CIAS_RAZORPAY_KEY_ID     : get_option('cias_razorpay_key_id', '');
        $key_secret = defined('CIAS_RAZORPAY_KEY_SECRET') && CIAS_RAZORPAY_KEY_SECRET ? CIAS_RAZORPAY_KEY_SECRET : get_option('cias_razorpay_key_secret', '');
        if (empty($key_id) || empty($key_secret)) return ['error' => 'Razorpay not configured.'];

        $response = wp_remote_post('https://api.razorpay.com/v1/orders', [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode("{$key_id}:{$key_secret}"),
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'amount'   => $amount_paise,
                'currency' => 'INR',
                'receipt'  => $receipt,
            ]),
        ]);

        if (is_wp_error($response)) return ['error' => $response->get_error_message()];
        return json_decode(wp_remote_retrieve_body($response), true) ?? ['error' => 'Invalid response'];
    }

    public static function verify_razorpay_signature(string $order_id, string $payment_id, string $signature): bool {
        $key_secret = defined('CIAS_RAZORPAY_KEY_SECRET') && CIAS_RAZORPAY_KEY_SECRET ? CIAS_RAZORPAY_KEY_SECRET : get_option('cias_razorpay_key_secret', '');
        $expected   = hash_hmac('sha256', $order_id . '|' . $payment_id, $key_secret);
        return hash_equals($expected, $signature);
    }
}
