<?php
if (!defined('ABSPATH')) exit;

class CIAS_WhatsApp {

    /* ══════════════════════════════════
       CRON ENTRY POINTS
    ══════════════════════════════════ */
    public static function send_daily_reports() {
        if (get_option('cias_wa_enabled', '0') !== '1') return 0;
        $students = get_users(['role__in' => ['vocab_student'], 'fields' => ['ID']]);
        $sent = 0;
        foreach ($students as $s) {
            $phone = get_user_meta($s->ID, 'cias_parent_phone', true);
            if (!$phone) continue;
            if (self::send_report_for_student($s->ID, 'daily')) $sent++;
            // Small delay to avoid rate limiting
            usleep(300000); // 0.3 seconds
        }
        return $sent;
    }

    public static function send_weekly_reports() {
        if (get_option('cias_wa_enabled', '0') !== '1') return 0;
        $students = get_users(['role__in' => ['vocab_student'], 'fields' => ['ID']]);
        $sent = 0;
        foreach ($students as $s) {
            $phone = get_user_meta($s->ID, 'cias_parent_phone', true);
            if (!$phone) continue;
            if (self::send_report_for_student($s->ID, 'weekly')) $sent++;
            usleep(300000);
        }
        return $sent;
    }

    /* ══════════════════════════════════
       BUILD + SEND REPORT FOR ONE STUDENT
    ══════════════════════════════════ */
    public static function send_report_for_student($user_id, $type = 'daily') {
        $phone = get_user_meta($user_id, 'cias_parent_phone', true);
        if (!$phone) return false;

        $student = get_userdata($user_id);
        if (!$student) return false;

        $db   = new CIAS_DB();
        $data = self::get_student_today_data($user_id, $type);

        // Build AI note if enabled
        $ai_note = '';
        if (get_option('cias_wa_ai_note', '0') === '1') {
            $ai_note = self::generate_ai_note($student->display_name, $data, $type);
        }

        // Build message
        $message = self::build_message($student->display_name, $data, $ai_note, $type);

        // Send via Brevo
        $result = self::send_via_brevo($phone, $message);

        // Log
        global $wpdb;
        $wpdb->insert(CIAS_WA_LOG, [
            'user_id'          => $user_id,
            'parent_phone'     => $phone,
            'message_type'     => $type,
            'status'           => $result['success'] ? 'sent' : 'failed',
            'brevo_message_id' => $result['message_id'] ?? '',
            'error_message'    => $result['error'] ?? '',
        ]);

        return $result['success'];
    }

    /* ══════════════════════════════════
       FETCH TODAY'S / WEEKLY DATA
    ══════════════════════════════════ */
    private static function get_student_today_data($user_id, $type = 'daily') {
        global $wpdb;

        $days = $type === 'weekly' ? 7 : 1;
        $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        // Attempts in period
        $attempts = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, t.title AS test_title, s.name AS subject_name
             FROM " . CIAS_ATTEMPTS . " a
             LEFT JOIN " . CIAS_TESTS . " t ON a.test_id = t.id
             LEFT JOIN " . CIAS_SUBJECTS . " s ON t.subject_id = s.id
             WHERE a.user_id=%d AND a.status='submitted' AND a.submitted_at >= %s
             ORDER BY a.submitted_at DESC",
            $user_id, $since
        ));

        $tests_taken = count($attempts);
        $avg_score   = $tests_taken > 0 ? round(array_sum(array_column($attempts, 'percentage')) / $tests_taken, 1) : 0;

        // Subject breakdown
        $subjects = [];
        foreach ($attempts as $a) {
            $sn = $a->subject_name ?? 'General';
            if (!isset($subjects[$sn])) $subjects[$sn] = ['total' => 0, 'count' => 0];
            $subjects[$sn]['total'] += $a->percentage;
            $subjects[$sn]['count']++;
        }
        $subject_scores = [];
        foreach ($subjects as $name => $s) {
            $subject_scores[$name] = round($s['total'] / $s['count'], 1);
        }

        // Vocab practiced
        $vocab_count = 0; // placeholder — can hook into vocab app tables if needed

        // Streak (days with at least one attempt in last 30 days)
        $streak = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT DATE(submitted_at)) FROM " . CIAS_ATTEMPTS . "
             WHERE user_id=%d AND status='submitted'
             AND submitted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            $user_id
        )));

        // Upcoming test
        $next_test = $wpdb->get_row($wpdb->prepare(
            "SELECT t.title, t.scheduled_at FROM " . CIAS_TEST_BATCH . " tb
             JOIN " . CIAS_TESTS . " t ON tb.test_id=t.id
             JOIN " . CIAS_ENROLLMENTS . " e ON tb.batch_id=e.batch_id
             WHERE e.user_id=%d AND t.status='published' AND t.scheduled_at > NOW()
             ORDER BY t.scheduled_at ASC LIMIT 1",
            $user_id
        ));

        // Weak topics
        $weak_topics = $wpdb->get_results($wpdb->prepare(
            "SELECT tp.name AS topic_name, ROUND(ts.weighted_accuracy,0) AS acc
             FROM " . CIAS_TOPIC_STATS . " ts
             LEFT JOIN " . CIAS_TOPICS . " tp ON ts.topic_id=tp.id
             WHERE ts.user_id=%d AND ts.weighted_accuracy < 50 AND ts.topic_id > 0
             ORDER BY ts.weighted_accuracy ASC LIMIT 2",
            $user_id
        ));

        return compact('tests_taken','avg_score','subject_scores','vocab_count','streak','next_test','weak_topics','attempts');
    }

    /* ══════════════════════════════════
       BUILD BILINGUAL MESSAGE
    ══════════════════════════════════ */
    private static function build_message($name, $data, $ai_note, $type) {
        $date = date('d M Y');

        if ($data['tests_taken'] === 0 && $type === 'daily') {
            // Short nudge for inactive students
            return
                "📖 *CIAS — Gentle Reminder / याद दिलाना*\n\n" .
                "Namaste! {$name} ne aaj practice nahi ki.\n" .
                "(Namaste! {$name} hasn't practiced today.)\n\n" .
                "UPSC ki taiyari mein consistency bahut zaroori hai! Sirf 20 minute roz kaafi hain.\n" .
                "(Consistency is key to UPSC success! Even 20 minutes daily makes a huge difference.)\n\n" .
                "🔗 Practice: www.digitalsumedh.online/mock-test/\n\n" .
                "_— CIAS Team_";
        }

        $type_label = $type === 'weekly' ? 'Weekly Summary / साप्ताहिक रिपोर्ट' : 'Daily Report / दैनिक रिपोर्ट';
        $period     = $type === 'weekly' ? 'इस सप्ताह / This week' : 'आज / Today';

        $msg = "📊 *CIAS {$type_label}*\n";
        $msg .= "👤 *{$name}*\n";
        $msg .= "📅 {$date}\n\n";

        // Activity
        $msg .= "🎯 *{$period} की गतिविधि / Activity*\n";
        $msg .= "• Tests लिए / Tests taken: *{$data['tests_taken']}*\n";

        if ($data['tests_taken'] > 0) {
            $score_emoji = $data['avg_score'] >= 70 ? '✅' : ($data['avg_score'] >= 50 ? '⚠️' : '❌');
            $msg .= "• औसत अंक / Avg score: *{$data['avg_score']}%* {$score_emoji}\n";
        }

        $msg .= "• Streak: 🔥 *{$data['streak']} days*\n";

        // Subject breakdown
        if (!empty($data['subject_scores'])) {
            $msg .= "\n📚 *विषय प्रदर्शन / Subject Performance*\n";
            foreach ($data['subject_scores'] as $subject => $pct) {
                $e = $pct >= 70 ? '✅' : ($pct >= 50 ? '⚠️' : '❌');
                $msg .= "• {$subject}: *{$pct}%* {$e}\n";
            }
        }

        // Weak areas
        if (!empty($data['weak_topics'])) {
            $msg .= "\n⚠️ *ध्यान देने योग्य / Needs Attention*\n";
            foreach ($data['weak_topics'] as $wt) {
                $msg .= "• {$wt->topic_name} ({$wt->acc}%)\n";
            }
        }

        // AI personalised note
        if (!empty($ai_note)) {
            $msg .= "\n💡 *शिक्षक की टिप्पणी / Teacher's Note*\n";
            $msg .= $ai_note . "\n";
        }

        // Next test
        if ($data['next_test']) {
            $test_date = date('d M Y', strtotime($data['next_test']->scheduled_at));
            $msg .= "\n📝 *अगला Test / Next Test*\n";
            $msg .= "• {$data['next_test']->title} — {$test_date}\n";
        }

        $msg .= "\n_CIAS — www.digitalsumedh.online_\n";
        $msg .= "_Reply STOP to unsubscribe_";

        return $msg;
    }

    /* ══════════════════════════════════
       GENERATE AI NOTE VIA CLAUDE HAIKU
    ══════════════════════════════════ */
    private static function generate_ai_note($name, $data, $type) {
        $api_key = get_option('cias_anthropic_key', '');
        if (empty($api_key)) return '';

        $subject_text = '';
        foreach ($data['subject_scores'] as $s => $p) {
            $subject_text .= "$s: {$p}%, ";
        }

        $weak_text = '';
        foreach ($data['weak_topics'] as $wt) {
            $weak_text .= $wt->topic_name . ', ';
        }

        $prompt = "Write exactly 2 short sentences (one in Hindi, one in English) as a personalised teacher's note for a parent about their child named {$name}.
Facts: tests taken={$data['tests_taken']}, avg score={$data['avg_score']}%, streak={$data['streak']} days, subjects={$subject_text} weak topics={$weak_text}.
Be encouraging, specific, and actionable. No greetings. No emojis. Just 2 sentences.";

        $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
            'timeout' => 20,
            'headers' => [
                'Content-Type'      => 'application/json',
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
            ],
            'body' => wp_json_encode([
                'model'      => 'claude-haiku-4-5-20251001',
                'max_tokens' => 100,
                'messages'   => [['role' => 'user', 'content' => $prompt]],
            ]),
        ]);

        if (is_wp_error($response)) return '';
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return trim($body['content'][0]['text'] ?? '');
    }

    /* ══════════════════════════════════
       SEND VIA AISENSY API
    ══════════════════════════════════ */
    private static function send_via_brevo($phone, $message) {
        $api_key       = get_option('cias_brevo_wa_key', '');
        $campaign_name = get_option('cias_brevo_wa_sender', 'cias_daily_report');

        if (empty($api_key)) {
            return ['success' => false, 'error' => 'AiSensy API key not configured in Settings.'];
        }

        // Clean phone — remove all non-digits, ensure no + prefix for AiSensy
        $phone = preg_replace('/[^0-9]/', '', $phone);
        // Remove leading 91 if present, AiSensy adds it
        if (substr($phone, 0, 2) === '91' && strlen($phone) === 12) {
            $phone = substr($phone, 2);
        }

        // AiSensy API payload
        $payload = [
            'apiKey'         => $api_key,
            'campaignName'   => $campaign_name,
            'destination'    => $phone,
            'userName'       => 'CIAS Parent',
            'source'         => 'CIAS Platform',
            'templateParams' => [$message], // {{1}} in template = full message
            'tags'           => ['parent_report'],
        ];

        $response = wp_remote_post('https://backend.aisensy.com/campaign/t1/api/v2', [
            'timeout' => 30,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'error' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 200 && $code < 300) {
            return ['success' => true, 'message_id' => $body['id'] ?? ''];
        }

        return [
            'success' => false,
            'error'   => ($body['message'] ?? $body['error'] ?? 'Unknown error') . " (HTTP {$code})",
        ];
    }
}
