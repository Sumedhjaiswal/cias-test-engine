<?php
if (!defined('ABSPATH')) exit;

class CIAS_Email_Reports {

    /* ══════════════════════════════════
       CRON ENTRY POINTS
    ══════════════════════════════════ */
    public static function send_daily_reports($force = false) {
        if (!$force && get_option('cias_email_reports_enabled', '0') !== '1') return 0;
        $students = get_users(['role__in' => ['vocab_student'], 'fields' => ['ID']]);
        $sent = 0;
        foreach ($students as $s) {
            $parent_email = get_user_meta($s->ID, 'cias_parent_email', true);
            if (!$parent_email || !is_email($parent_email)) continue;
            if (self::send_report_for_student($s->ID, 'daily')) $sent++;
        }
        return $sent;
    }

    public static function send_weekly_reports($force = false) {
        if (!$force && get_option('cias_email_reports_enabled', '0') !== '1') return 0;
        $students = get_users(['role__in' => ['vocab_student'], 'fields' => ['ID']]);
        $sent = 0;
        foreach ($students as $s) {
            $parent_email = get_user_meta($s->ID, 'cias_parent_email', true);
            if (!$parent_email || !is_email($parent_email)) continue;
            if (self::send_report_for_student($s->ID, 'weekly')) $sent++;
        }
        return $sent;
    }

    /* ══════════════════════════════════
       POST-TEST INSTANT RESULT EMAIL
    ══════════════════════════════════ */
    public static function send_post_test_report($user_id, $test_id) {
        if (get_option('cias_email_reports_enabled', '0') !== '1') return false;
        if (get_option('cias_email_post_test', '1') !== '1') return false;
        $parent_email = get_user_meta($user_id, 'cias_parent_email', true);
        $parent_name  = get_user_meta($user_id, 'cias_parent_name',  true);
        if (!$parent_email || !is_email($parent_email)) return false;

        global $wpdb;
        $student = get_userdata($user_id);
        if (!$student) return false;

        $attempt = $wpdb->get_row($wpdb->prepare(
            "SELECT a.*, t.title AS test_title, s.name AS subject_name
             FROM " . CIAS_ATTEMPTS . " a
             LEFT JOIN " . CIAS_TESTS . " t ON a.test_id=t.id
             LEFT JOIN " . CIAS_SUBJECTS . " s ON t.subject_id=s.id
             WHERE a.user_id=%d AND a.test_id=%d AND a.status='submitted'
             ORDER BY a.id DESC LIMIT 1",
            $user_id, $test_id
        ));
        if (!$attempt) return false;

        $pass_pct  = intval(get_option('cias_pass_percentage', 60));
        $pct       = floatval($attempt->percentage);
        $passed    = $pct >= $pass_pct;
        $name      = $student->display_name;
        $greeting  = $parent_name ? "Dear {$parent_name}," : "Dear Parent,";
        $site_name = get_bloginfo('name');
        $site_url  = home_url();
        $date      = date('d M Y, g:i A');
        $score_col = $passed ? '#16a34a' : '#dc2626';
        $score_bg  = $passed ? '#f0fdf4' : '#fef2f2';
        $result    = $passed ? '✅ Passed' : '❌ Needs Improvement';

        $html = "<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#f9fafb;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif'>
<div style='max-width:600px;margin:0 auto;padding:20px'>
  <div style='background:linear-gradient(135deg,#6C63FF 0%,#534AB7 100%);border-radius:16px 16px 0 0;padding:24px 32px;text-align:center'>
    <div style='color:#fff;font-size:18px;font-weight:700'>{$site_name}</div>
    <div style='color:rgba(255,255,255,.8);font-size:13px;margin-top:4px'>📝 Test Result — {$date}</div>
  </div>
  <div style='background:#fff;padding:26px 32px;border:1px solid #e5e7eb;border-top:none'>
    <p style='font-size:14px;color:#374151;margin:0 0 16px'>{$greeting}</p>
    <p style='font-size:14px;color:#374151;margin:0 0 16px'><strong>" . esc_html($name) . "</strong> just completed:</p>
    <div style='background:#f9fafb;border-radius:10px;padding:14px;margin-bottom:16px;border-left:4px solid #6C63FF'>
      <div style='font-size:15px;font-weight:700;color:#374151'>" . esc_html($attempt->test_title ?? 'Test') . "</div>
      <div style='font-size:12px;color:#6b7280;margin-top:3px'>" . esc_html($attempt->subject_name ?? '') . "</div>
    </div>
    <div style='background:{$score_bg};border-radius:12px;padding:20px;text-align:center;margin-bottom:0'>
      <div style='font-size:42px;font-weight:800;color:{$score_col};line-height:1'>{$pct}%</div>
      <div style='font-size:14px;font-weight:600;color:{$score_col};margin-top:6px'>{$result}</div>
    </div>
  </div>
  <div style='background:#f3f4f6;border-radius:0 0 16px 16px;border:1px solid #e5e7eb;border-top:none;padding:14px 32px;text-align:center'>
    <a href='{$site_url}/mock-test/' style='color:#6C63FF;font-size:12px'>{$site_name} — {$site_url}</a>
  </div>
</div></body></html>";

        $test_title_short = mb_substr($attempt->test_title ?? 'Test', 0, 40);
        $subject = "{$name} scored {$pct}% in \"{$test_title_short}\" — CIAS";
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: CIAS — ' . $site_name . ' <' . get_option('admin_email') . '>',
        ];
        $result_send = wp_mail($parent_email, $subject, $html, $headers);

        global $wpdb;
        $wpdb->insert(CIAS_WA_LOG, [
            'user_id'      => $user_id,
            'parent_phone' => $parent_email,
            'message_type' => 'email_post_test',
            'status'       => $result_send ? 'sent' : 'failed',
            'error_message'=> $result_send ? '' : 'wp_mail returned false',
        ]);
        return $result_send;
    }

    /* ══════════════════════════════════
       SEND REPORT FOR ONE STUDENT
    ══════════════════════════════════ */
    public static function send_report_for_student($user_id, $type = 'daily') {
        $parent_email = get_user_meta($user_id, 'cias_parent_email', true);
        $parent_name  = get_user_meta($user_id, 'cias_parent_name',  true);
        if (!$parent_email || !is_email($parent_email)) return false;

        $student = get_userdata($user_id);
        if (!$student) return false;

        $data    = self::get_student_data($user_id, $type);
        $ai_note = '';
        if (get_option('cias_wa_ai_note', '0') === '1') {
            $ai_note = self::generate_ai_note($student->display_name, $data, $type);
        }

        $subject = self::build_subject($student->display_name, $type, $data);
        $html    = self::build_html($student->display_name, $parent_name, $data, $ai_note, $type);

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: CIAS — ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>',
        ];

        $sent = wp_mail($parent_email, $subject, $html, $headers);

        // Log
        global $wpdb;
        $wpdb->insert(CIAS_WA_LOG, [
            'user_id'          => $user_id,
            'parent_phone'     => $parent_email,
            'message_type'     => 'email_' . $type,
            'status'           => $sent ? 'sent' : 'failed',
            'brevo_message_id' => '',
            'error_message'    => $sent ? '' : 'wp_mail returned false',
        ]);

        return $sent;
    }

    /* ══════════════════════════════════
       FETCH STUDENT DATA
    ══════════════════════════════════ */
    private static function get_student_data($user_id, $type = 'daily') {
        global $wpdb;
        $days  = $type === 'weekly' ? 7 : 1;
        $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $attempts = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, t.title AS test_title, s.name AS subject_name
             FROM " . CIAS_ATTEMPTS . " a
             LEFT JOIN " . CIAS_TESTS . " t ON a.test_id=t.id
             LEFT JOIN " . CIAS_SUBJECTS . " s ON t.subject_id=s.id
             WHERE a.user_id=%d AND a.status='submitted' AND a.submitted_at>=%s
             ORDER BY a.submitted_at DESC",
            $user_id, $since
        ));

        $tests_taken = count($attempts);
        $avg_score   = $tests_taken > 0
            ? round(array_sum(array_map(function($a){ return $a->percentage; }, $attempts)) / $tests_taken, 1)
            : 0;

        $subjects = [];
        foreach ($attempts as $a) {
            $sn = $a->subject_name ?? 'General';
            if (!isset($subjects[$sn])) $subjects[$sn] = ['total'=>0,'count'=>0];
            $subjects[$sn]['total'] += $a->percentage;
            $subjects[$sn]['count']++;
        }
        $subject_scores = [];
        foreach ($subjects as $name => $s) {
            $subject_scores[$name] = round($s['total'] / $s['count'], 1);
        }

        $streak = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT DATE(submitted_at)) FROM " . CIAS_ATTEMPTS . "
             WHERE user_id=%d AND status='submitted'
             AND submitted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            $user_id
        )));

        $next_test = $wpdb->get_row($wpdb->prepare(
            "SELECT t.title, t.scheduled_at FROM " . CIAS_TEST_BATCH . " tb
             JOIN " . CIAS_TESTS . " t ON tb.test_id=t.id
             JOIN " . CIAS_ENROLLMENTS . " e ON tb.batch_id=e.batch_id
             WHERE e.user_id=%d AND t.status='published' AND t.scheduled_at > NOW()
             ORDER BY t.scheduled_at ASC LIMIT 1",
            $user_id
        ));

        $weak_topics = $wpdb->get_results($wpdb->prepare(
            "SELECT tp.name AS topic_name, ROUND(ts.weighted_accuracy,0) AS acc
             FROM " . CIAS_TOPIC_STATS . " ts
             LEFT JOIN " . CIAS_TOPICS . " tp ON ts.topic_id=tp.id
             WHERE ts.user_id=%d AND ts.weighted_accuracy < 50 AND ts.topic_id > 0
             ORDER BY ts.weighted_accuracy ASC LIMIT 3",
            $user_id
        ));

        // All time stats
        $all_attempts = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . CIAS_ATTEMPTS . " WHERE user_id=%d AND status='submitted'", $user_id
        )));
        $all_avg = floatval($wpdb->get_var($wpdb->prepare(
            "SELECT ROUND(AVG(percentage),1) FROM " . CIAS_ATTEMPTS . " WHERE user_id=%d AND status='submitted'", $user_id
        )));

        return compact('tests_taken','avg_score','subject_scores','streak','next_test','weak_topics','attempts','all_attempts','all_avg');
    }

    /* ══════════════════════════════════
       BUILD EMAIL SUBJECT
    ══════════════════════════════════ */
    private static function build_subject($name, $type, $data) {
        $date = date('d M Y');
        if ($type === 'weekly') {
            return "📊 CIAS साप्ताहिक रिपोर्ट — {$name} | {$date}";
        }
        if ($data['tests_taken'] === 0) {
            return "📖 CIAS Reminder — {$name} ने आज practice नहीं की";
        }
        $emoji = $data['avg_score'] >= 70 ? '🌟' : ($data['avg_score'] >= 50 ? '📈' : '⚠️');
        return "{$emoji} CIAS Daily Report — {$name} | {$data['avg_score']}% | {$date}";
    }

    /* ══════════════════════════════════
       BUILD HTML EMAIL
    ══════════════════════════════════ */
    private static function build_html($name, $parent_name, $data, $ai_note, $type) {
        $date       = date('d M Y');
        $site_url   = home_url();
        $site_name  = get_bloginfo('name');
        $period     = $type === 'weekly' ? 'इस सप्ताह / This week' : 'आज / Today';
        $type_label = $type === 'weekly' ? 'साप्ताहिक रिपोर्ट / Weekly Summary' : 'दैनिक रिपोर्ट / Daily Report';
        $greeting   = $parent_name ? "Dear {$parent_name}," : "Dear Parent,";

        $score_color  = $data['avg_score'] >= 70 ? '#16a34a' : ($data['avg_score'] >= 50 ? '#d97706' : '#dc2626');
        $score_bg     = $data['avg_score'] >= 70 ? '#f0fdf4' : ($data['avg_score'] >= 50 ? '#fffbeb' : '#fef2f2');
        $score_emoji  = $data['avg_score'] >= 70 ? '✅' : ($data['avg_score'] >= 50 ? '⚠️' : '❌');

        // No activity today
        if ($data['tests_taken'] === 0 && $type === 'daily') {
            return self::nudge_email($name, $parent_name, $site_url, $site_name);
        }

        // Subject breakdown rows
        $subject_rows = '';
        foreach ($data['subject_scores'] as $subject => $pct) {
            $sc = $pct >= 70 ? '#16a34a' : ($pct >= 50 ? '#d97706' : '#dc2626');
            $se = $pct >= 70 ? '✅' : ($pct >= 50 ? '⚠️' : '❌');
            $bar = intval($pct);
            $subject_rows .= "
            <tr>
              <td style='padding:8px 12px;font-size:13px;color:#374151'>{$subject}</td>
              <td style='padding:8px 12px'>
                <div style='background:#f3f4f6;border-radius:99px;height:8px;width:100%;overflow:hidden'>
                  <div style='background:{$sc};height:8px;width:{$bar}%;border-radius:99px'></div>
                </div>
              </td>
              <td style='padding:8px 12px;font-size:13px;font-weight:700;color:{$sc};text-align:right'>{$pct}% {$se}</td>
            </tr>";
        }

        // Weak topics
        $weak_html = '';
        if (!empty($data['weak_topics'])) {
            $weak_html = "<div style='background:#fef2f2;border-left:4px solid #dc2626;border-radius:0 8px 8px 0;padding:12px 16px;margin:16px 0'>
              <div style='font-size:13px;font-weight:700;color:#991b1b;margin-bottom:8px'>⚠️ ध्यान देने योग्य / Needs Attention</div>";
            foreach ($data['weak_topics'] as $wt) {
                $weak_html .= "<div style='font-size:13px;color:#7f1d1d;padding:3px 0'>• {$wt->topic_name} — {$wt->acc}% accuracy</div>";
            }
            $weak_html .= "</div>";
        }

        // Next test
        $next_test_html = '';
        if ($data['next_test']) {
            $test_date = date('d M Y, g:i A', strtotime($data['next_test']->scheduled_at));
            $next_test_html = "<div style='background:#eff6ff;border-left:4px solid #3b82f6;border-radius:0 8px 8px 0;padding:12px 16px;margin:16px 0'>
              <div style='font-size:13px;font-weight:700;color:#1e40af;margin-bottom:4px'>📝 अगला Test / Next Test</div>
              <div style='font-size:13px;color:#1e3a8a'>{$data['next_test']->title} — {$test_date}</div>
            </div>";
        }

        // AI note
        $ai_html = '';
        if (!empty($ai_note)) {
            $ai_html = "<div style='background:#f0fdf4;border-left:4px solid #16a34a;border-radius:0 8px 8px 0;padding:12px 16px;margin:16px 0'>
              <div style='font-size:13px;font-weight:700;color:#166534;margin-bottom:6px'>💡 Teacher's Note / शिक्षक की टिप्पणी</div>
              <div style='font-size:13px;color:#14532d;line-height:1.6'>" . nl2br(esc_html($ai_note)) . "</div>
            </div>";
        }

        // Recent tests table
        $recent_rows = '';
        foreach (array_slice($data['attempts'], 0, 5) as $a) {
            $pc = floatval($a->percentage);
            $rc = $pc >= 70 ? '#16a34a' : ($pc >= 50 ? '#d97706' : '#dc2626');
            $test_name = esc_html($a->test_title ?? 'Practice Test');
            $test_subj = esc_html($a->subject_name ?? '—');
            $test_date = date('d M', strtotime($a->submitted_at));
            $recent_rows .= "<tr>
              <td style='padding:7px 12px;font-size:12px;color:#374151'>{$test_name}</td>
              <td style='padding:7px 12px;font-size:12px;color:#6b7280'>{$test_subj}</td>
              <td style='padding:7px 12px;font-size:12px;color:#6b7280'>{$test_date}</td>
              <td style='padding:7px 12px;font-size:13px;font-weight:700;color:{$rc};text-align:right'>{$pc}%</td>
            </tr>";
        }

        return "<!DOCTYPE html>
<html><head><meta charset='UTF-8'><meta name='viewport' content='width=device-width,initial-scale=1'>
<title>CIAS {$type_label}</title></head>
<body style='margin:0;padding:0;background:#f9fafb;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif'>

<div style='max-width:600px;margin:0 auto;padding:20px'>

  <!-- Header -->
  <div style='background:linear-gradient(135deg,#6C63FF 0%,#534AB7 100%);border-radius:16px 16px 0 0;padding:28px 32px;text-align:center'>
    <div style='font-size:28px;margin-bottom:6px'>📊</div>
    <div style='color:#fff;font-size:20px;font-weight:700'>{$site_name}</div>
    <div style='color:rgba(255,255,255,.8);font-size:14px;margin-top:4px'>{$type_label} — {$date}</div>
  </div>

  <!-- Main card -->
  <div style='background:#fff;padding:28px 32px;border-left:1px solid #e5e7eb;border-right:1px solid #e5e7eb'>

    <p style='font-size:14px;color:#374151;margin:0 0 20px'>{$greeting}<br>
    Here is {$name}'s performance report for {$period}.</p>

    <!-- Score summary -->
    <div style='background:{$score_bg};border-radius:12px;padding:20px;text-align:center;margin-bottom:20px'>
      <div style='font-size:13px;color:#6b7280;margin-bottom:4px'>{$period} का औसत / Avg Score</div>
      <div style='font-size:48px;font-weight:800;color:{$score_color};line-height:1'>{$data['avg_score']}%</div>
      <div style='font-size:14px;color:{$score_color};margin-top:4px'>{$score_emoji}</div>
      <div style='display:flex;justify-content:center;gap:24px;margin-top:16px;flex-wrap:wrap'>
        <div style='text-align:center'>
          <div style='font-size:20px;font-weight:700;color:#374151'>{$data['tests_taken']}</div>
          <div style='font-size:11px;color:#6b7280'>Tests taken</div>
        </div>
        <div style='text-align:center'>
          <div style='font-size:20px;font-weight:700;color:#f59e0b'>🔥 {$data['streak']}</div>
          <div style='font-size:11px;color:#6b7280'>Day streak</div>
        </div>
        <div style='text-align:center'>
          <div style='font-size:20px;font-weight:700;color:#374151'>{$data['all_attempts']}</div>
          <div style='font-size:11px;color:#6b7280'>Total tests</div>
        </div>
      </div>
    </div>

    <!-- Subject breakdown -->
    " . (!empty($subject_rows) ? "
    <div style='margin-bottom:20px'>
      <div style='font-size:13px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px'>📚 विषय प्रदर्शन / Subject Performance</div>
      <table style='width:100%;border-collapse:collapse'>
        <tr style='background:#f9fafb;border-radius:8px'>
          <th style='padding:8px 12px;font-size:11px;text-align:left;color:#6b7280;font-weight:600;text-transform:uppercase'>Subject</th>
          <th style='padding:8px 12px;font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase'>Progress</th>
          <th style='padding:8px 12px;font-size:11px;text-align:right;color:#6b7280;font-weight:600;text-transform:uppercase'>Score</th>
        </tr>
        {$subject_rows}
      </table>
    </div>" : '') . "

    {$weak_html}
    {$ai_html}
    {$next_test_html}

    <!-- Recent tests -->
    " . (!empty($recent_rows) ? "
    <div style='margin-bottom:20px'>
      <div style='font-size:13px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px'>📋 Recent Tests</div>
      <table style='width:100%;border-collapse:collapse;border:1px solid #f3f4f6;border-radius:8px;overflow:hidden'>
        <tr style='background:#f9fafb'>
          <th style='padding:7px 12px;font-size:11px;text-align:left;color:#6b7280'>Test</th>
          <th style='padding:7px 12px;font-size:11px;text-align:left;color:#6b7280'>Subject</th>
          <th style='padding:7px 12px;font-size:11px;text-align:left;color:#6b7280'>Date</th>
          <th style='padding:7px 12px;font-size:11px;text-align:right;color:#6b7280'>Score</th>
        </tr>
        {$recent_rows}
      </table>
    </div>" : '') . "

    <!-- Motivational message -->
    <div style='background:#f0eeff;border-radius:12px;padding:16px;text-align:center;margin-top:8px'>
      " . ($data['avg_score'] >= 70
        ? "<div style='font-size:14px;color:#4c1d95;font-weight:500'>🌟 शानदार प्रदर्शन! Excellent work — keep it up!</div>"
        : ($data['avg_score'] >= 50
            ? "<div style='font-size:14px;color:#4c1d95;font-weight:500'>📈 अच्छी प्रगति! Good progress — push a little more!</div>"
            : "<div style='font-size:14px;color:#4c1d95;font-weight:500'>💪 हार मत मानो! Don't give up — every practice counts!</div>")
      ) . "
    </div>

  </div>

  <!-- Footer -->
  <div style='background:#f3f4f6;border-radius:0 0 16px 16px;border:1px solid #e5e7eb;padding:20px 32px;text-align:center'>
    <a href='{$site_url}/mock-test/' style='display:inline-block;background:#6C63FF;color:#fff;text-decoration:none;padding:10px 24px;border-radius:8px;font-size:14px;font-weight:600;margin-bottom:12px'>
      Practice Now →
    </a>
    <div style='font-size:11px;color:#9ca3af;margin-top:8px'>
      This report is sent automatically by {$site_name}<br>
      To stop receiving emails, contact your instructor.
    </div>
  </div>

</div>
</body></html>";
    }

    /* ── Nudge email for inactive students ── */
    private static function nudge_email($name, $parent_name, $site_url, $site_name) {
        $greeting = $parent_name ? "Dear {$parent_name}," : "Dear Parent,";
        return "<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#f9fafb;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif'>
<div style='max-width:600px;margin:0 auto;padding:20px'>
  <div style='background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:32px;text-align:center'>
    <div style='font-size:48px;margin-bottom:16px'>📖</div>
    <h2 style='color:#374151;margin:0 0 12px'>{$greeting}</h2>
    <p style='color:#6b7280;font-size:14px;line-height:1.7;margin:0 0 20px'>
      <strong>{$name}</strong> ने आज practice नहीं की।<br>
      <em>{$name} hasn't practiced today.</em><br><br>
      UPSC की तैयारी में consistency बहुत ज़रूरी है।<br>
      Sirf 20 minute roz — बड़ा फर्क पड़ता है!
    </p>
    <a href='{$site_url}/mock-test/' style='display:inline-block;background:#6C63FF;color:#fff;text-decoration:none;padding:12px 32px;border-radius:8px;font-size:15px;font-weight:600'>
      Practice Now →
    </a>
    <p style='color:#9ca3af;font-size:11px;margin-top:20px'>Sent by {$site_name}</p>
  </div>
</div></body></html>";
    }

    /* ══════════════════════════════════
       AI NOTE VIA CLAUDE HAIKU
    ══════════════════════════════════ */
    private static function generate_ai_note($name, $data, $type) {
        $api_key = get_option('cias_anthropic_key', '');
        if (empty($api_key)) return '';

        $subject_text = implode(', ', array_map(
            function($k, $v) { return "$k: {$v}%"; },
            array_keys($data['subject_scores']),
            array_values($data['subject_scores'])
        ));
        $weak_text = implode(', ', array_map(function($w){ return $w->topic_name; }, $data['weak_topics']));

        $prompt = "Write exactly 2 short sentences as a personalised teacher's note for a parent about their child {$name}.
One sentence in Hindi, one in English. Be encouraging, specific, and actionable.
Facts: tests={$data['tests_taken']}, avg={$data['avg_score']}%, streak={$data['streak']} days" .
            ($subject_text ? ", subjects={$subject_text}" : '') .
            ($weak_text    ? ", weak topics={$weak_text}" : '') . ".
No greetings. No emojis. Just 2 sentences.";

        $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
            'timeout' => 20,
            'headers' => [
                'Content-Type'      => 'application/json',
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
            ],
            'body' => wp_json_encode([
                'model'      => 'claude-haiku-4-5-20251001',
                'max_tokens' => 120,
                'messages'   => [['role'=>'user','content'=>$prompt]],
            ]),
        ]);

        if (is_wp_error($response)) return '';
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return trim($body['content'][0]['text'] ?? '');
    }
}
