<?php
if (!defined('ABSPATH')) exit;

class CIAS_Ajax {

    public function __construct() {
        $actions = [
            'cias_get_tests','cias_start_test','cias_save_answer',
            'cias_submit_test','cias_get_results','cias_get_history',
            'cias_get_practice','cias_start_adaptive','cias_get_due_revisions',
            'cias_practice_options',
            'cias_get_leaderboard','cias_get_teacher_dashboard',
            'cias_get_student_detail','cias_get_offline_history',
            'cias_verify_pin','cias_session_heartbeat','cias_ai_overview',
            'cias_ask_bot','cias_bot_status',
            'cias_create_razorpay_order','cias_verify_payment',
            'cias_cm_generate','cias_cm_approve','cias_cm_publish',
            'caig_guru_chat','caig_get_study_plan','caig_get_heatmap',
            'caig_get_rank_prediction','caig_get_lecture_recs',
            'caig_save_lecture','caig_delete_lecture','caig_get_lectures',
        ];
        foreach ($actions as $a) add_action("wp_ajax_{$a}", [$this, $a]);
    }

    private function check($nonce_key = 'cias_nonce') {
        // Accept cias_nonce (old portal) and cias_app_nonce (new CIAS app)
        $valid = check_ajax_referer($nonce_key, 'nonce', false)
              || check_ajax_referer('cias_app_nonce', 'nonce', false);
        if (!$valid || !is_user_logged_in())
            wp_send_json_error(['message' => 'Authentication failed.']);
    }

    /* ── Get student test list ── */
    public function cias_get_tests() {
        $this->check();
        $uid   = get_current_user_id();
        $db    = new CIAS_DB();
        $tests = $db->get_student_tests($uid);
        $pass  = intval(get_option('cias_pass_percentage', 60));
        $now   = current_time('timestamp');

        $html = '';
        if (empty($tests)) {
            $html = '<div class="cias-empty"><div class="cias-empty-icon">📋</div><p>No tests assigned to your batch yet.</p><p><small>Ask your instructor to assign tests to your batch.</small></p></div>';
        } else {
            // Separate into upcoming vs available vs completed
            $upcoming  = [];
            $available = [];
            $completed = [];

            foreach ($tests as $t) {
                $is_done     = !empty($t->attempt_id);
                $in_progress = ($t->my_attempt_status === 'in_progress');
                $scheduled_ts= $t->scheduled_date ? strtotime($t->scheduled_date) : 0;
                $is_upcoming = $scheduled_ts > 0 && $scheduled_ts > $now && !$is_done && !$in_progress;

                if ($is_done)         $completed[] = $t;
                elseif ($is_upcoming) $upcoming[]  = $t;
                else                  $available[] = $t;
            }

            // ── Available Tests ──
            if (!empty($available)) {
                $html .= '<div class="cias-section-label">📋 Available Now</div>';
                foreach ($available as $t) {
                    $color       = esc_attr($t->subject_color ?? '#6C63FF');
                    $subject     = esc_html($t->subject_name ?? 'General');
                    $title       = esc_html($t->title);
                    $q_count     = intval($t->q_count);
                    $time_str    = $t->time_limit ? $t->time_limit . ' min' : 'No limit';
                    $in_progress = ($t->my_attempt_status === 'in_progress');
                    $desc        = esc_html($t->description ?? '');
                    $has_pin     = !empty($t->access_pin) &&
                                   (!$t->pin_expires_at || strtotime($t->pin_expires_at) > time());
                    $pin_js      = $has_pin ? 'true' : 'false';
                    $mode_badge  = isset($t->test_mode) && $t->test_mode === 'offline'
                                   ? '<span class="cias-result-badge" style="background:#fef3c7;color:#92400e">📝 Classroom</span>'
                                   : '';

                    if ($in_progress) {
                        $status_badge = '<span class="cias-result-badge progress">🔄 In Progress</span>';
                        $btn = '<button class="cias-btn cias-btn-primary" onclick="CIASApp.startTest(' . intval($t->id) . ',' . $pin_js . ')">Continue →</button>';
                    } else {
                        $status_badge = '<span class="cias-result-badge available">🟢 Available</span>';
                        $btn = $has_pin
                            ? '<button class="cias-btn cias-btn-primary" onclick="CIASApp.startTest(' . intval($t->id) . ',true)">🔐 Enter PIN to Start</button>'
                            : '<button class="cias-btn cias-btn-primary" onclick="CIASApp.startTest(' . intval($t->id) . ',false)">Start Test →</button>';
                    }

                    $html .= '<div class="cias-test-card cias-card-available">
                        <div class="cias-test-card-top">
                            <span class="cias-subject-tag" style="background:' . $color . '20;color:' . $color . ';border-color:' . $color . '40">' . $subject . '</span>
                            ' . $status_badge . '
                            ' . $mode_badge . '
                        </div>
                        <h3 class="cias-test-title">' . $title . '</h3>
                        ' . ($desc ? '<p class="cias-test-desc">' . $desc . '</p>' : '') . '
                        <div class="cias-test-meta">
                            <span>❓ ' . $q_count . ' Questions</span>
                            <span>⏱ ' . $time_str . '</span>
                            <span>🎯 Pass: ' . intval(get_option('cias_pass_percentage',60)) . '%</span>
                        </div>
                        <div class="cias-test-actions">' . $btn . '</div>
                    </div>';
                }
            }

            // ── Upcoming Tests ──
            if (!empty($upcoming)) {
                $html .= '<div class="cias-section-label">⏰ Upcoming Tests</div>';
                foreach ($upcoming as $t) {
                    $color       = esc_attr($t->subject_color ?? '#6C63FF');
                    $subject     = esc_html($t->subject_name ?? 'General');
                    $title       = esc_html($t->title);
                    $q_count     = intval($t->q_count);
                    $time_str    = $t->time_limit ? $t->time_limit . ' min' : 'No limit';
                    $scheduled_ts= strtotime($t->scheduled_date);
                    $desc        = esc_html($t->description ?? '');

                    // Human-friendly start time
                    $diff_secs  = $scheduled_ts - $now;
                    $diff_days  = floor($diff_secs / 86400);
                    $diff_hours = floor(($diff_secs % 86400) / 3600);
                    $diff_mins  = floor(($diff_secs % 3600) / 60);

                    if ($diff_days > 0)
                        $starts_in = 'Starts in ' . $diff_days . 'd ' . $diff_hours . 'h';
                    elseif ($diff_hours > 0)
                        $starts_in = 'Starts in ' . $diff_hours . 'h ' . $diff_mins . 'm';
                    else
                        $starts_in = 'Starts in ' . $diff_mins . ' min';

                    $start_date = date('D, d M Y', $scheduled_ts);
                    $start_time = date('h:i A', $scheduled_ts);

                    $html .= '<div class="cias-test-card cias-card-upcoming">
                        <div class="cias-test-card-top">
                            <span class="cias-subject-tag" style="background:' . $color . '20;color:' . $color . ';border-color:' . $color . '40">' . $subject . '</span>
                            <span class="cias-result-badge upcoming">🕐 ' . esc_html($starts_in) . '</span>
                        </div>
                        <h3 class="cias-test-title">' . $title . '</h3>
                        ' . ($desc ? '<p class="cias-test-desc">' . $desc . '</p>' : '') . '
                        <div class="cias-countdown-box">
                            <div class="cias-countdown-label">📅 Scheduled for</div>
                            <div class="cias-countdown-date">' . $start_date . '</div>
                            <div class="cias-countdown-time">' . $start_time . '</div>
                            <div class="cias-countdown-timer" data-ts="' . $scheduled_ts . '">Computing…</div>
                        </div>
                        <div class="cias-test-meta">
                            <span>❓ ' . $q_count . ' Questions</span>
                            <span>⏱ ' . $time_str . '</span>
                        </div>
                        <button class="cias-btn cias-btn-disabled" disabled>Not Available Yet</button>
                    </div>';
                }
            }

            // ── Completed Tests ──
            if (!empty($completed)) {
                $html .= '<div class="cias-section-label">✅ Completed</div>';
                foreach ($completed as $t) {
                    $color   = esc_attr($t->subject_color ?? '#6C63FF');
                    $subject = esc_html($t->subject_name ?? 'General');
                    $title   = esc_html($t->title);
                    $q_count = intval($t->q_count);
                    $pct     = floatval($t->my_pct);
                    $passed  = $pct >= $pass;

                    $badge = $passed
                        ? '<span class="cias-result-badge pass">✅ ' . $pct . '% — Passed</span>'
                        : '<span class="cias-result-badge fail">❌ ' . $pct . '% — Failed</span>';

                    $html .= '<div class="cias-test-card cias-card-done">
                        <div class="cias-test-card-top">
                            <span class="cias-subject-tag" style="background:' . $color . '20;color:' . $color . ';border-color:' . $color . '40">' . $subject . '</span>
                            ' . $badge . '
                        </div>
                        <h3 class="cias-test-title">' . $title . '</h3>
                        <div class="cias-test-meta"><span>❓ ' . $q_count . ' Questions</span></div>
                        <div class="cias-test-actions">
                            <button class="cias-btn cias-btn-outline" onclick="CIASApp.viewResults(' . intval($t->attempt_id) . ')">📊 View Results & Answer Key</button>
                        </div>
                    </div>';
                }
            }
        }

        // Build structured data for the new app UI
        $structured = [];
        if (!empty($tests)) {
            $now_ts = current_time('timestamp');
            foreach ($tests as $t) {
                $is_done      = !empty($t->attempt_id);
                $in_progress  = ($t->my_attempt_status === 'in_progress');
                $sched_ts     = $t->scheduled_date ? strtotime($t->scheduled_date) : 0;
                $is_upcoming  = $sched_ts > 0 && $sched_ts > $now_ts && !$is_done && !$in_progress;
                $has_pin      = !empty($t->access_pin) && (!$t->pin_expires_at || strtotime($t->pin_expires_at) > time());

                $end_ts = !empty($t->end_date) ? strtotime($t->end_date) : 0;
                $is_expired = $end_ts > 0 && $end_ts < $now_ts && !$is_done && !$in_progress;

                if ($is_done)         $status = 'completed';
                elseif ($is_upcoming) $status = 'upcoming';
                elseif ($is_expired)  $status = 'expired';
                elseif ($in_progress) $status = 'in_progress';
                else                  $status = 'available';

                $structured[] = [
                    'id'           => (int)$t->id,
                    'attempt_id'   => $is_done ? (int)$t->attempt_id : 0,
                    'title'        => $t->title,
                    'subject_name' => $t->subject_name ?? 'General',
                    'subject_color'=> $t->subject_color ?? '#6C63FF',
                    'description'  => $t->description ?? '',
                    'q_count'      => (int)($t->q_count ?? 0),
                    'time_limit'   => (int)($t->time_limit ?? 0),
                    'scheduled_date'=> $t->scheduled_date ?? null,
                    'end_date'     => $t->end_date ?? null,
                    'status'       => $status,
                    'score'        => $is_done ? round((float)($t->my_pct ?? 0), 1) : null,
                    'has_pin'      => $has_pin,
                    'test_mode'    => $t->test_mode ?? 'online',
                    'pass_mark'    => $pass,
                ];
            }
        }

        wp_send_json_success(['html' => $html, 'tests' => $structured]);
    }

    /* ── Start / resume a test ── */
    public function cias_start_test() {
        $this->check();
        $uid     = get_current_user_id();
        $test_id = intval($_POST['test_id'] ?? 0);
        if (!$test_id) wp_send_json_error(['message' => 'Invalid test.']);

        $db   = new CIAS_DB();
        $test = $db->get_by_id('tests', $test_id);
        if (!$test || $test->status !== 'published') wp_send_json_error(['message' => 'Test not available.']);

        // ── Schedule window enforcement ──────────────────────────────────
        $now_ts = current_time('timestamp');
        if (!empty($test->scheduled_date)) {
            $start_ts = strtotime($test->scheduled_date);
            if ($start_ts && $now_ts < $start_ts) {
                wp_send_json_error(['message' => 'This test has not started yet. It opens at ' . date_i18n('M j, g:i A', $start_ts) . '.']);
            }
        }
        if (!empty($test->end_date)) {
            $end_ts = strtotime($test->end_date);
            if ($end_ts && $now_ts > $end_ts) {
                wp_send_json_error(['message' => 'This test has ended. The window closed at ' . date_i18n('M j, g:i A', $end_ts) . '.']);
            }
        }

        $existing_done = get_transient('cias_done_' . $uid . '_' . $test_id);
        if (!$existing_done) {
            global $wpdb;
            $done = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM " . CIAS_ATTEMPTS . " WHERE test_id=%d AND user_id=%d AND status='submitted'", $test_id, $uid
            ));
            if ($done) wp_send_json_error(['message' => 'You have already completed this test.']);
        }

        $attempt_id = $db->start_attempt($test_id, $uid);
        $questions  = $db->get_test_questions_for_exam($test_id);
        $saved      = $db->get_saved_answers($attempt_id);

        wp_send_json_success([
            'attempt_id'  => $attempt_id,
            'test_title'  => $test->title,
            'time_limit'  => intval($test->time_limit),
            'questions'   => $questions,
            'saved'       => $saved,
        ]);
    }

    /* ── Auto-save a single answer ── */
    public function cias_save_answer() {
        $this->check();
        $uid        = get_current_user_id();
        $attempt_id = intval($_POST['attempt_id'] ?? 0);
        $q_id       = intval($_POST['question_id'] ?? 0);
        $option     = sanitize_text_field($_POST['selected'] ?? '');

        if (!$attempt_id || !$q_id || !in_array($option, ['a','b','c','d']))
            wp_send_json_error(['message' => 'Invalid data.']);

        global $wpdb;
        $owner = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM ".CIAS_ATTEMPTS." WHERE id=%d",$attempt_id));
        if (intval($owner) !== $uid) wp_send_json_error(['message'=>'Unauthorized.']);

        $db = new CIAS_DB();
        $db->save_answer($attempt_id, $q_id, $option);
        wp_send_json_success(['saved' => true]);
    }

    /* ── Submit test ── */
    public function cias_submit_test() {
        $this->check();
        $uid        = get_current_user_id();
        $attempt_id = intval($_POST['attempt_id'] ?? 0);
        if (!$attempt_id) wp_send_json_error(['message'=>'Invalid attempt.']);

        // ── Ownership validation — ensure attempt belongs to current user ──
        global $wpdb;
        $owner = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT user_id FROM " . CIAS_ATTEMPTS . " WHERE id = %d", $attempt_id )
        );
        if ( $owner !== $uid ) {
            wp_send_json_error(['message' => 'Unauthorized.'], 403);
        }

        $db     = new CIAS_DB();
        $result = $db->submit_attempt($attempt_id, $uid);
        $pass   = intval(get_option('cias_pass_percentage', 60));
        $passed = $result->percentage >= $pass;

        // Update per-topic performance stats
        $db->update_topic_stats($uid, $attempt_id);

        set_transient('cias_done_' . $uid . '_' . $result->test_id, 1, DAY_IN_SECONDS);

        // Fire post-test parent email immediately (non-blocking, async via WP action)
        do_action('cias_test_submitted', $uid, $result->test_id);

        wp_send_json_success([
            'attempt_id'   => $attempt_id,
            'score'        => $result->score,
            'total'        => $result->total,
            'percentage'   => $result->percentage,
            'passed'       => $passed,
            'pass_mark'    => $pass,
            'time_taken'   => $result->time_taken,
            'due_revisions'=> $db->count_due_revisions($uid),
        ]);
    }

    /* ── Get results with answer key ── */
    public function cias_get_results() {
        $this->check();
        $uid        = get_current_user_id();
        $attempt_id = intval($_POST['attempt_id'] ?? 0);
        if (!$attempt_id) wp_send_json_error(['message'=>'Invalid.']);

        $show_answer = get_option('cias_show_answer_after', 'submit');
        if ($show_answer === 'never' && !current_user_can('manage_options'))
            wp_send_json_error(['message'=>'Answer key not available yet.']);

        $db   = new CIAS_DB();
        $data = $db->get_attempt_with_answers($attempt_id, $uid);
        if (!$data) wp_send_json_error(['message'=>'Results not found.']);

        $attempt   = $data['attempt'];
        $questions = $data['questions'];
        $pass      = intval(get_option('cias_pass_percentage', 60));
        $passed    = $attempt->percentage >= $pass;

        ob_start();
        ?>
<div class="cias-results">
  <div class="cias-result-hero <?php echo $passed ? 'pass' : 'fail'; ?>">
    <div class="cias-result-icon"><?php echo $passed ? '🎉' : '📚'; ?></div>
    <h2><?php echo $passed ? 'Well Done!' : 'Keep Practising'; ?></h2>
    <div class="cias-score-big"><?php echo $attempt->score; ?><span>/<?php echo $attempt->total; ?></span></div>
    <div class="cias-score-pct"><?php echo $attempt->percentage; ?>%</div>
  </div>
  <div class="cias-result-stats">
    <div class="cias-rs-item cias-rs-green"><div><?php echo $attempt->score; ?></div><small>Correct</small></div>
    <div class="cias-rs-item cias-rs-red"><div><?php echo $attempt->total - $attempt->score; ?></div><small>Wrong</small></div>
    <div class="cias-rs-item cias-rs-blue"><div><?php echo gmdate('i:s', intval($attempt->time_taken)); ?></div><small>Time Taken</small></div>
    <div class="cias-rs-item <?php echo $passed?'cias-rs-green':'cias-rs-red'; ?>"><div><?php echo $passed?'Pass':'Fail'; ?></div><small>Result</small></div>
  </div>
  <div class="cias-answer-key">
    <h3>Answer Key & Explanations</h3>
    <?php foreach($questions as $i => $q):
        $opts    = ['a'=>$q->option_a,'b'=>$q->option_b,'c'=>$q->option_c,'d'=>$q->option_d];
        $correct = strtolower($q->correct_option);
        $selected= strtolower($q->selected_option ?? '');
        $status  = !$selected ? 'skipped' : ($q->is_correct ? 'correct' : 'wrong');
    ?>
    <div class="cias-ak-item cias-ak-<?php echo $status; ?>">
      <div class="cias-ak-header">
        <span class="cias-ak-num">Q<?php echo $i+1; ?></span>
        <span class="cias-ak-status"><?php echo $status==='correct'?'✅ Correct':($status==='wrong'?'❌ Wrong':'⬜ Skipped'); ?></span>
      </div>
      <p class="cias-ak-question"><?php echo esc_html($q->question_text); ?></p>
      <?php
      // Show statements if statement-based
      if (!empty($q->question_type) && $q->question_type === 'statement' && !empty($q->statements)) {
          $stmts = json_decode($q->statements, true) ?: [];
          if ($stmts): ?>
      <div class="cias-q-statements" style="margin:8px 0">
        <?php foreach($stmts as $si => $stmt): ?>
        <div class="cias-q-stmt"><span class="cias-q-stmt-num"><?php echo $si+1; ?>.</span><span><?php echo esc_html($stmt); ?></span></div>
        <?php endforeach; ?>
      </div>
      <div class="cias-q-select-hint">Select the correct answer:</div>
      <?php endif;
      }
      // Show tags
      $tags = array_filter(explode(',', $q->question_tags ?? ''));
      if (!empty($tags) || !empty($q->year_asked)): ?>
      <div class="cias-q-tags" style="margin-bottom:8px">
        <?php foreach($tags as $tag): ?>
        <span class="cias-q-tag" style="background:#f0eeff;color:#6C63FF;border-color:#c4b5fd"><?php echo esc_html($tag); ?></span>
        <?php endforeach; ?>
        <?php if($q->year_asked): ?>
        <span class="cias-q-tag" style="background:#fef3c7;color:#92400e;border-color:#fde68a">UPSC <?php echo intval($q->year_asked); ?></span>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <div class="cias-ak-options">
        <?php foreach($opts as $key => $val): ?>
        <div class="cias-ak-option
          <?php echo $key===$correct ? ' cias-opt-correct' : ''; ?>
          <?php echo ($key===$selected && $key!==$correct) ? ' cias-opt-wrong' : ''; ?>">
          <span class="cias-opt-letter"><?php echo strtoupper($key); ?></span>
          <?php echo esc_html($val); ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php if($q->explanation): ?>
      <div class="cias-ak-explanation"><strong>💡 Explanation:</strong> <?php echo esc_html($q->explanation); ?></div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="cias-result-actions">
    <button class="cias-btn cias-btn-primary" onclick="CIASApp.goTests()">← Back to Tests</button>
  </div>
</div>
        <?php
        $html = ob_get_clean();
        wp_send_json_success(['html'=>$html]);
    }

    /* ── History ── */
    public function cias_get_history() {
        $this->check();
        $uid      = get_current_user_id();
        $db       = new CIAS_DB();
        $attempts = $db->get_student_attempts($uid);
        $summ     = $db->get_student_summary($uid);
        $pass     = intval(get_option('cias_pass_percentage', 60));

        ob_start();
        if (empty($attempts)): ?>
<div class="cias-empty"><div class="cias-empty-icon">📊</div><p>No tests completed yet. Start a test to see your history here.</p></div>
        <?php else: ?>
<div class="cias-history-summary">
  <div class="cias-hs-item"><span class="cias-hs-num"><?php echo $summ['total']; ?></span><span>Tests Taken</span></div>
  <div class="cias-hs-item"><span class="cias-hs-num"><?php echo $summ['avg']; ?>%</span><span>Avg Score</span></div>
  <div class="cias-hs-item"><span class="cias-hs-num"><?php echo $summ['best']; ?>%</span><span>Best Score</span></div>
  <div class="cias-hs-item"><span class="cias-hs-num"><?php echo $summ['pass_rate']; ?>%</span><span>Pass Rate</span></div>
</div>
<div class="cias-history-list">
  <?php foreach($attempts as $a):
    $passed = $a->percentage >= $pass;
  ?>
  <div class="cias-history-item">
    <div class="cias-hi-left">
      <div class="cias-hi-title"><?php echo esc_html($a->test_title); ?></div>
      <div class="cias-hi-date"><?php echo date('d M Y, H:i',strtotime($a->submitted_at)); ?></div>
    </div>
    <div class="cias-hi-right">
      <span class="cias-score-tag <?php echo $passed?'pass':'fail'; ?>"><?php echo $a->score.'/'.$a->total; ?> (<?php echo $a->percentage; ?>%)</span>
      <button class="cias-btn cias-btn-sm" onclick="CIASApp.viewResults(<?php echo $a->id; ?>)">View</button>
    </div>
  </div>
  <?php endforeach; ?>
</div>
        <?php endif;
        wp_send_json_success(['html'=>ob_get_clean()]);
    }

    /* ── Practice tab: subject stats + weak topics + due revisions ── */
    public function cias_get_practice() {
        $this->check();
        $uid        = get_current_user_id();
        $subject_id = intval($_POST['subject_id'] ?? 0);
        $db         = new CIAS_DB();
        $stats      = $db->get_student_topic_stats($uid, $subject_id);
        $subjects   = $db->get_all('subjects');
        $due        = $db->get_due_revisions($uid);

        ob_start();
        ?>
<div class="cias-practice-wrap">

  <?php if (!empty($due)): ?>
  <div class="cias-section-label" style="color:var(--ct-red)">Revision due — <?php echo count($due); ?> topic(s)</div>
  <?php foreach($due as $d):
    $color = esc_attr($d->color ?? '#6C63FF');
    $topic_label = implode(' › ', array_filter([$d->subject_name,$d->topic_name,$d->subtopic_name]));
  ?>
  <div class="cias-test-card cias-card-available" style="border-left-color:var(--ct-red)">
    <div class="cias-test-card-top">
      <span class="cias-subject-tag" style="background:<?php echo $color; ?>20;color:<?php echo $color; ?>;border-color:<?php echo $color; ?>40"><?php echo esc_html($d->subject_name); ?></span>
      <span class="cias-result-badge" style="background:#fee2e2;color:#991b1b">Revision due</span>
    </div>
    <h3 class="cias-test-title"><?php echo esc_html($topic_label); ?></h3>
    <div class="cias-test-meta">
      <span>Current accuracy: <?php echo round($d->weighted_accuracy); ?>%</span>
      <span>Level: <?php echo ucfirst($d->level); ?></span>
    </div>
    <button class="cias-btn cias-btn-primary" onclick="CIASApp.startAdaptive(<?php echo intval($d->subject_id); ?>,<?php echo intval($d->topic_id); ?>,<?php echo intval($d->subtopic_id); ?>,'revision')">Start Revision →</button>
  </div>
  <?php endforeach; endif; ?>

  <!-- Subject selector -->
  <div class="cias-section-label">Practice test</div>
  <div class="cias-practice-select">
    <div class="cias-prac-field">
      <label>Subject</label>
      <select id="prac-subject" onchange="CIASApp.loadPracticeSubject(this.value)">
        <option value="0">Select subject…</option>
        <?php foreach($subjects as $s): ?>
        <option value="<?php echo $s->id; ?>" <?php selected($subject_id,$s->id); ?>><?php echo esc_html($s->name); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="cias-prac-field">
      <label>Topic</label>
      <select id="prac-topic" onchange="CIASApp.loadPracticeTopic(this.value)">
        <option value="0">All topics</option>
      </select>
    </div>
    <div class="cias-prac-field">
      <label>Subtopic</label>
      <select id="prac-subtopic">
        <option value="0">All subtopics</option>
      </select>
    </div>
    <div class="cias-prac-field">
      <label>Questions</label>
      <select id="prac-count">
        <option value="10">10 questions</option>
        <option value="15" selected>15 questions</option>
        <option value="20">20 questions</option>
        <option value="25">25 questions</option>
      </select>
    </div>
    <button class="cias-btn cias-btn-primary" onclick="CIASApp.startAdaptive(parseInt(document.getElementById('prac-subject').value),parseInt(document.getElementById('prac-topic').value),parseInt(document.getElementById('prac-subtopic').value),'practice')">Start Practice →</button>
  </div>

  <?php if (!empty($stats)): ?>
  <div class="cias-section-label" style="margin-top:20px">Your topic performance</div>
  <?php foreach($stats as $st):
    $pct   = round($st->weighted_accuracy);
    $level = $st->level;
    $color = $level==='strong' ? '#22c55e' : ($level==='mid' ? '#f59e0b' : '#ef4444');
    $bg    = $level==='strong' ? '#dcfce7' : ($level==='mid' ? '#fef3c7' : '#fee2e2');
    $label = implode(' › ', array_filter([$st->topic_name,$st->subtopic_name])) ?: '(General)';
  ?>
  <div class="cias-topic-stat-row">
    <div class="cias-tsr-info">
      <span class="cias-tsr-subject"><?php echo esc_html($st->subject_name); ?></span>
      <span class="cias-tsr-topic"><?php echo esc_html($label); ?></span>
    </div>
    <div class="cias-tsr-bar-wrap">
      <div class="cias-tsr-bar-track"><div class="cias-tsr-bar-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $color; ?>"></div></div>
      <span class="cias-tsr-pct"><?php echo $pct; ?>%</span>
    </div>
    <span class="cias-result-badge" style="background:<?php echo $bg; ?>;color:<?php echo $color; ?>"><?php echo ucfirst($level); ?></span>
    <?php if($st->topic_id): ?>
    <button class="cias-btn cias-btn-sm" onclick="CIASApp.startAdaptive(<?php echo intval($st->subject_id); ?>,<?php echo intval($st->topic_id); ?>,<?php echo intval($st->subtopic_id); ?>,'drill')" title="Drill this topic">Drill</button>
    <?php endif; ?>
  </div>
  <?php endforeach; endif; ?>

</div>
<style>
.cias-practice-select{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;padding:16px;background:var(--ct-bg);border-radius:14px;margin-bottom:4px}
.cias-prac-field{display:flex;flex-direction:column;gap:5px;font-size:12px;color:var(--ct-muted)}
.cias-prac-field select{padding:8px 12px;border-radius:10px;border:1.5px solid var(--ct-border);background:var(--ct-card);font-size:14px;color:var(--ct-text)}
.cias-topic-stat-row{display:flex;align-items:center;gap:12px;padding:10px 14px;background:var(--ct-card);border-radius:12px;margin-bottom:8px;box-shadow:0 1px 4px rgba(0,0,0,.04);flex-wrap:wrap}
.cias-tsr-info{flex:1;min-width:120px}
.cias-tsr-subject{display:block;font-size:11px;color:var(--ct-muted);text-transform:uppercase;letter-spacing:.05em}
.cias-tsr-topic{display:block;font-size:14px;font-weight:600;color:var(--ct-text)}
.cias-tsr-bar-wrap{display:flex;align-items:center;gap:8px;min-width:140px}
.cias-tsr-bar-track{flex:1;height:7px;background:var(--ct-border);border-radius:99px;overflow:hidden}
.cias-tsr-bar-fill{height:100%;border-radius:99px;transition:width .6s ease}
.cias-tsr-pct{font-size:13px;font-weight:600;color:var(--ct-text);min-width:36px}
</style>
        <?php
        wp_send_json_success(['html'=>ob_get_clean()]);
    }

    /* ── Cascading topic/subtopic options for practice filters ── */
    public function cias_practice_options() {
        $this->check();
        $db          = new CIAS_DB();
        $subject_id  = intval($_POST['subject_id'] ?? 0);
        $topic_id    = intval($_POST['topic_id'] ?? 0);

        $topics    = [];
        $subtopics = [];

        if ($subject_id > 0) {
            // Only topics in this subject that have published questions
            $all_topics = $db->get_topics_with_subject();
            foreach ($all_topics as $t) {
                if (intval($t->subject_id) === $subject_id && intval($t->question_count) > 0) {
                    $topics[] = ['id' => (int)$t->id, 'name' => $t->name, 'q' => (int)$t->question_count];
                }
            }
        }
        if ($topic_id > 0) {
            $rows = $db->get_subtopics_by_topic($topic_id);
            foreach ($rows as $st) {
                $subtopics[] = ['id' => (int)$st->id, 'name' => $st->name];
            }
        }
        wp_send_json_success(['topics' => $topics, 'subtopics' => $subtopics]);
    }

    /* ── Start adaptive/practice/drill/revision test ── */
    public function cias_start_adaptive() {
        $this->check();
        $uid         = get_current_user_id();
        $subject_id  = intval($_POST['subject_id'] ?? 0);
        $topic_id    = intval($_POST['topic_id']   ?? 0);
        $subtopic_id = intval($_POST['subtopic_id']?? 0);
        $type        = sanitize_text_field($_POST['adaptive_type'] ?? 'practice');
        $q_count     = min(25, max(10, intval($_POST['q_count'] ?? 15)));

        if (!$subject_id) wp_send_json_error(['message'=>'Please select a subject.']);

        $db     = new CIAS_DB();
        $result = CIAS_Adaptive::generate($uid, $subject_id, $q_count, $topic_id, $subtopic_id);

        if (empty($result['questions'])) {
            wp_send_json_error(['message'=>'Not enough questions in the bank for this topic yet. Ask your instructor to add more questions.']);
        }

        // Create a virtual test + attempt in the DB so answer save/submit works
        global $wpdb;
        $subject = $db->get_by_id('subjects', $subject_id);
        $title   = $type === 'revision' ? 'Revision — '.($subject->name??'')
                 : ($type === 'drill'   ? 'Drill — '.($subject->name??'')
                 :                        'Practice — '.($subject->name??''));

        $wpdb->insert(CIAS_TESTS, [
            'title'      => $title,
            'subject_id' => $subject_id,
            'time_limit' => 0,
            'status'     => 'published',
            'created_by' => $uid,
        ]);
        $test_id = $wpdb->insert_id;

        foreach (array_values($result['questions']) as $pos => $q) {
            $wpdb->insert(CIAS_TEST_Q, ['test_id'=>$test_id,'question_id'=>$q->id,'position'=>$pos]);
        }

        $attempt_id = $db->start_attempt($test_id, $uid);

        wp_send_json_success([
            'attempt_id'  => $attempt_id,
            'test_title'  => $title,
            'time_limit'  => 0,
            'questions'   => $result['questions'],
            'saved'       => [],
            'level'       => $result['level'],
            'adaptive'    => true,
        ]);
    }

    /* ── Get due revisions count ── */
    public function cias_get_due_revisions() {
        $this->check();
        $db  = new CIAS_DB();
        $due = $db->count_due_revisions(get_current_user_id());
        wp_send_json_success(['count'=>$due]);
    }

    public function cias_get_leaderboard() {
        $this->check();
        if (!current_user_can('manage_options') && !current_user_can('cias_view_reports'))
            wp_send_json_error(['message'=>'Access denied.']);

        $batch_id   = intval($_POST['batch_id']    ?? 0);
        $period     = sanitize_text_field($_POST['period'] ?? 'week');
        $subject_id = intval($_POST['subject_id']  ?? 0);
        $date_from  = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to    = sanitize_text_field($_POST['date_to']   ?? '');
        if (!$batch_id) wp_send_json_error(['message'=>'No batch selected.']);

        global $wpdb;
        // Build date filter
        if ($period === 'today') {
            $date_filter = "AND DATE(a.submitted_at) = CURDATE()";
        } elseif ($period === 'week') {
            $date_filter = "AND a.submitted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        } elseif ($period === 'month') {
            $date_filter = "AND a.submitted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        } elseif ($period === 'custom' && $date_from && $date_to) {
            $date_filter = $wpdb->prepare("AND DATE(a.submitted_at) BETWEEN %s AND %s", $date_from, $date_to);
        } else {
            $date_filter = ''; // all time
        }

        $subject_filter = $subject_id ? $wpdb->prepare("AND t.subject_id=%d", $subject_id) : '';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT u.ID AS user_id, u.display_name,
                COUNT(DISTINCT a.id)       AS total_tests,
                ROUND(AVG(a.percentage),1) AS avg_pct,
                MAX(a.percentage)          AS best_pct,
                (SELECT COUNT(DISTINCT DATE(a2.submitted_at))
                 FROM ".CIAS_ATTEMPTS." a2
                 WHERE a2.user_id=u.ID AND a2.status='submitted'
                 AND a2.submitted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS streak
             FROM ".CIAS_ENROLLMENTS." e
             JOIN {$wpdb->users} u ON e.user_id=u.ID
             LEFT JOIN ".CIAS_ATTEMPTS." a ON a.user_id=u.ID AND a.status='submitted' $date_filter
             LEFT JOIN ".CIAS_TESTS." t ON a.test_id=t.id $subject_filter
             WHERE e.batch_id=%d AND e.status='active'
             GROUP BY u.ID
             ORDER BY avg_pct DESC, total_tests DESC
             LIMIT 30",
            intval($batch_id)
        ));

        $db    = new CIAS_DB();
        $batch = $db->get_by_id('batches', $batch_id);
        $medals = ['gold','silver','bronze'];
        $period_label = ['today'=>'Today','week'=>'This week','month'=>'This month','all'=>'All time','custom'=>"$date_from → $date_to"][$period] ?? 'This week';

        ob_start();
        if (empty($rows)): ?>
<div class="cias-empty" style="text-align:center;padding:40px;color:var(--ct-muted)">No test attempts for this period yet.</div>
        <?php else:
            $top3 = array_slice($rows, 0, 3);
            $rest = array_slice($rows, 3);
            $podium_order = [1=>$top3[1]??null, 0=>$top3[0], 2=>$top3[2]??null];
            $medal_order  = [1=>'silver', 0=>'gold', 2=>'bronze'];
        ?>
<div style="text-align:center;margin-bottom:12px;font-size:13px;color:var(--ct-muted)">
  <?php echo esc_html($batch->name ?? ''); ?> &nbsp;·&nbsp; <?php echo esc_html($period_label); ?>
</div>
<div class="lb-podium">
  <?php foreach($podium_order as $pos => $s):
    if (!$s) { echo '<div></div>'; continue; }
    $m = $medal_order[$pos];
    $initials = strtoupper(substr($s->display_name,0,1).(strpos($s->display_name,' ')!==false?substr($s->display_name,strpos($s->display_name,' ')+1,1):''));
    $rank_label = $pos===0?'🥇':($pos===1?'🥈':'🥉');
  ?>
  <div class="lb-pod <?php echo $m; ?>" <?php echo $m==='gold'?'style="padding-top:8px"':''; ?>>
    <div class="lb-pod-rank <?php echo $m; ?>"><?php echo $rank_label; ?></div>
    <div class="lb-pod-av <?php echo $m; ?>"><?php echo esc_html($initials); ?></div>
    <div class="lb-pod-name"><?php echo esc_html($s->display_name); ?></div>
    <div class="lb-pod-score <?php echo $m; ?>"><?php echo floatval($s->avg_pct ?: 0); ?>%</div>
    <div class="lb-pod-sub"><?php echo intval($s->total_tests); ?> tests &nbsp;·&nbsp; <?php echo intval($s->streak); ?> day streak</div>
  </div>
  <?php endforeach; ?>
</div>
<?php foreach($rest as $idx => $s):
  $rank = $idx + 4;
  $initials = strtoupper(substr($s->display_name,0,1).(strpos($s->display_name,' ')!==false?substr($s->display_name,strpos($s->display_name,' ')+1,1):''));
?>
<div class="lb-row">
  <span class="lb-rnk"><?php echo $rank; ?></span>
  <div class="lb-av"><?php echo esc_html($initials); ?></div>
  <span class="lb-name"><?php echo esc_html($s->display_name); ?></span>
  <span class="lb-meta"><?php echo intval($s->total_tests); ?> tests</span>
  <span class="lb-pct" style="color:<?php echo ($s->avg_pct??0)>=60?'var(--ct-green)':'var(--ct-amber)'; ?>"><?php echo floatval($s->avg_pct ?: 0); ?>%</span>
</div>
<?php endforeach; endif;
        wp_send_json_success(['html'=>ob_get_clean()]);
    }

    /* ── Teacher dashboard ── */
    public function cias_get_teacher_dashboard() {
        $this->check();
        if (!current_user_can('manage_options') && !current_user_can('cias_view_reports'))
            wp_send_json_error(['message'=>'Access denied.']);

        $batch_id = intval($_POST['batch_id'] ?? 0);
        $weeks    = intval($_POST['weeks']    ?? 4);
        if (!$batch_id) wp_send_json_error(['message'=>'No batch.']);

        $db           = new CIAS_DB();
        $overview     = $db->get_batch_overview($batch_id);
        $curve        = $db->get_batch_weekly_curve($batch_id, $weeks);
        $heatmap      = $db->get_batch_subject_heatmap($batch_id);
        $inactive     = $db->get_batch_inactive_students($batch_id, 7);
        $students     = $db->get_leaderboard($batch_id, 'all', 0);
        $topic_stats  = $db->get_batch_topic_accuracy($batch_id);
        $weak_topics  = $db->get_batch_weak_topics($batch_id, 5);
        $top_students = $db->get_batch_most_improved($batch_id);

        ob_start(); ?>

<!-- Overview metrics -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px">
  <?php foreach([
    ['👥 Enrolled',     $overview['enrolled'],                          ''],
    ['📊 Avg score',    $overview['avg_pct'].'%',                       $overview['avg_pct']>=60?'color:var(--ct-green)':'color:var(--ct-amber)'],
    ['📋 Tests taken',  $overview['tests_done'],                        ''],
    ['⚡ Active week',  $overview['active_week'].'/'.$overview['enrolled'], $overview['active_week']>=$overview['enrolled']*0.7?'color:var(--ct-green)':'color:var(--ct-amber)'],
  ] as [$lbl,$val,$style]): ?>
  <div style="background:var(--ct-bg);border-radius:12px;padding:12px;text-align:center">
    <div style="font-size:11px;color:var(--ct-muted);margin-bottom:4px"><?php echo $lbl; ?></div>
    <div style="font-size:22px;font-weight:600<?php echo $style?';'.$style:''; ?>"><?php echo esc_html($val); ?></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Weekly learning curve -->
<?php if (!empty($curve)): ?>
<div class="cias-test-card" style="margin-bottom:12px">
  <div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--ct-muted);margin-bottom:12px">📈 Weekly learning curve — batch avg accuracy</div>
  <div style="display:flex;align-items:flex-end;gap:6px;height:90px">
  <?php foreach($curve as $w):
    $h   = max(4, round(($w['avg'] ?: 0) * 0.85));
    $col = ($w['avg'] ?: 0)>=70?'var(--ct-green)':(($w['avg'] ?: 0)>=50?'var(--ct-amber)':'var(--ct-red)');
  ?>
  <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:3px">
    <span style="font-size:10px;font-weight:500"><?php echo $w['avg']?$w['avg'].'%':'—'; ?></span>
    <div style="width:100%;height:<?php echo $h; ?>px;background:<?php echo $col; ?>;border-radius:4px 4px 0 0;opacity:.85"></div>
    <span style="font-size:10px;color:var(--ct-muted)"><?php echo esc_html($w['week']); ?></span>
  </div>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Subject accuracy heatmap -->
<?php if (!empty($heatmap)): ?>
<div class="cias-test-card" style="margin-bottom:12px">
  <div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--ct-muted);margin-bottom:10px">🗺 Subject accuracy — class average</div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:6px">
  <?php foreach($heatmap as $h):
    $pct=$h->avg_pct ?: 0;
    $bg=$pct>=70?'#EAF3DE':($pct>=50?'#FAEEDA':'#FCEBEB');
    $tc=$pct>=70?'#27500A':($pct>=50?'#633806':'#791F1F');
    $bar_w = intval($pct);
  ?>
  <div style="background:<?php echo $bg; ?>;border-radius:10px;padding:10px 12px">
    <div style="font-size:12px;font-weight:600;color:<?php echo $tc; ?>;margin-bottom:4px"><?php echo esc_html($h->subject_name); ?></div>
    <div style="font-size:20px;font-weight:700;color:<?php echo $tc; ?>"><?php echo floatval($pct); ?>%</div>
    <div style="height:4px;background:rgba(0,0,0,.1);border-radius:99px;margin-top:6px;overflow:hidden">
      <div style="height:100%;width:<?php echo $bar_w; ?>%;background:<?php echo $tc; ?>;border-radius:99px"></div>
    </div>
    <div style="font-size:10px;color:<?php echo $tc; ?>;opacity:.7;margin-top:3px"><?php echo intval($h->test_count); ?> tests</div>
  </div>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Topic/subtopic accuracy — NEW from PrepMonkey insight -->
<?php if (!empty($topic_stats)): ?>
<div class="cias-test-card" style="margin-bottom:12px">
  <div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--ct-muted);margin-bottom:10px">📚 Topic-wise accuracy — class average</div>
  <table style="width:100%;border-collapse:collapse;font-size:12px">
    <thead>
      <tr style="border-bottom:1px solid var(--ct-border)">
        <th style="text-align:left;padding:5px 8px;color:var(--ct-muted);font-weight:500">Topic</th>
        <th style="text-align:left;padding:5px 8px;color:var(--ct-muted);font-weight:500">Subject</th>
        <th style="text-align:right;padding:5px 8px;color:var(--ct-muted);font-weight:500">Avg accuracy</th>
        <th style="text-align:right;padding:5px 8px;color:var(--ct-muted);font-weight:500">Questions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach($topic_stats as $ts):
      $pct = floatval($ts->avg_accuracy ?: 0);
      $col = $pct>=70?'#166534':($pct>=50?'#92400e':'#991b1b');
      $bg  = $pct>=70?'#dcfce7':($pct>=50?'#fef3c7':'#fee2e2');
    ?>
    <tr style="border-bottom:0.5px solid var(--ct-border)">
      <td style="padding:7px 8px;font-weight:500"><?php echo esc_html($ts->topic_name ?? '—'); ?></td>
      <td style="padding:7px 8px;color:var(--ct-muted)"><?php echo esc_html($ts->subject_name ?? '—'); ?></td>
      <td style="padding:7px 8px;text-align:right">
        <span style="background:<?php echo $bg; ?>;color:<?php echo $col; ?>;padding:2px 10px;border-radius:99px;font-weight:600"><?php echo round($pct); ?>%</span>
      </td>
      <td style="padding:7px 8px;text-align:right;color:var(--ct-muted)"><?php echo intval($ts->total_questions); ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- Class weak spots — PrepMonkey-inspired "where to focus" -->
<?php if (!empty($weak_topics)): ?>
<div class="cias-test-card" style="margin-bottom:12px;border-left:3px solid var(--ct-red)">
  <div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--ct-red);margin-bottom:10px">🎯 Focus here — class weak spots</div>
  <p style="font-size:12px;color:var(--ct-muted);margin-bottom:10px">Topics where the class average is lowest — suggest focusing next session on these.</p>
  <?php foreach($weak_topics as $i => $wt):
    $pct = floatval($wt->avg_accuracy ?: 0);
  ?>
  <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:0.5px solid var(--ct-border)">
    <span style="font-size:18px;font-weight:700;color:var(--ct-red);min-width:24px"><?php echo $i+1; ?></span>
    <div style="flex:1">
      <div style="font-size:13px;font-weight:500"><?php echo esc_html($wt->topic_name ?? '—'); ?></div>
      <div style="font-size:11px;color:var(--ct-muted)"><?php echo esc_html($wt->subject_name ?? '—'); ?></div>
    </div>
    <div style="text-align:right">
      <div style="font-size:15px;font-weight:700;color:var(--ct-red)"><?php echo round($pct); ?>%</div>
      <div style="font-size:10px;color:var(--ct-muted)"><?php echo intval($wt->student_count); ?> students attempted</div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Inactive students alert -->
<?php if (!empty($inactive)): ?>
<div class="cias-test-card" style="margin-bottom:12px">
  <div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--ct-muted);margin-bottom:10px">⚠️ Attention needed — inactive 7+ days</div>
  <?php foreach($inactive as $s):
    $days_ago = $s->last_active ? round((time()-strtotime($s->last_active))/86400) : null;
  ?>
  <div style="display:flex;align-items:center;gap:10px;padding:8px 12px;background:var(--ct-bg);border:0.5px solid #fca5a5;border-radius:10px;margin-bottom:6px">
    <div style="width:32px;height:32px;border-radius:50%;background:#fee2e2;color:#dc2626;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:500"><?php echo strtoupper(substr($s->display_name,0,1)); ?></div>
    <div style="flex:1">
      <div style="font-size:13px;font-weight:500"><?php echo esc_html($s->display_name); ?></div>
      <div style="font-size:11px;color:var(--ct-red)"><?php echo $days_ago ? "Last active {$days_ago} days ago" : "Never logged in"; ?></div>
    </div>
    <div style="font-size:12px;color:var(--ct-muted)"><?php echo $s->avg_pct ? floatval($s->avg_pct).'% avg' : 'No tests'; ?></div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Student breakdown with mini progress bars — clickable -->
<div class="cias-test-card">
  <div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--ct-muted);margin-bottom:10px">👤 Student breakdown — click for details</div>
  <?php foreach($students as $idx => $s):
    $rank     = $idx + 1;
    $initials = strtoupper(substr($s->display_name,0,1).(strpos($s->display_name,' ')!==false?substr($s->display_name,strpos($s->display_name,' ')+1,1):''));
    $pct      = floatval($s->avg_pct ?: 0);
    $col      = $pct>=70?'var(--ct-green)':($pct>=50?'var(--ct-amber)':'var(--ct-red)');
  ?>
  <div style="display:flex;align-items:center;gap:10px;padding:10px 12px;border:0.5px solid var(--ct-border);border-radius:10px;margin-bottom:5px;background:var(--ct-card);cursor:pointer;transition:border-color .15s"
       onclick="CIAS_TD.loadStudent(<?php echo intval($s->user_id); ?>, '<?php echo esc_js($s->display_name); ?>')"
       onmouseover="this.style.borderColor='#6C63FF'" onmouseout="this.style.borderColor='var(--ct-border)'">
    <span style="font-size:12px;color:var(--ct-muted);min-width:20px;font-weight:600">#<?php echo $rank; ?></span>
    <div style="width:34px;height:34px;border-radius:50%;background:#f0eeff;color:#534AB7;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:600;flex-shrink:0"><?php echo esc_html($initials); ?></div>
    <div style="flex:1">
      <div style="font-size:13px;font-weight:500;margin-bottom:3px"><?php echo esc_html($s->display_name); ?></div>
      <div style="height:5px;background:var(--ct-border);border-radius:99px;overflow:hidden">
        <div style="height:100%;width:<?php echo intval($pct); ?>%;background:<?php echo $col; ?>;border-radius:99px"></div>
      </div>
    </div>
    <div style="text-align:right;min-width:70px">
      <div style="font-size:15px;font-weight:600;color:<?php echo $col; ?>"><?php echo $pct; ?>%</div>
      <div style="font-size:11px;color:var(--ct-muted)"><?php echo intval($s->total_tests); ?> tests</div>
    </div>
    <span style="font-size:16px;color:var(--ct-muted)">›</span>
  </div>
  <?php endforeach; ?>
  <?php if(empty($students)): ?><p style="color:var(--ct-muted);text-align:center;padding:20px">No attempts recorded yet.</p><?php endif; ?>
</div>

<!-- Student detail panel (slides in) -->
<div id="td-student-panel" style="display:none;margin-top:14px;border:0.5px solid #6C63FF;border-radius:14px;overflow:hidden">
  <div style="background:#6C63FF;padding:10px 14px;display:flex;justify-content:space-between;align-items:center">
    <span style="color:#fff;font-weight:600;font-size:13px" id="td-student-name">Student</span>
    <button onclick="CIAS_TD.closeStudent()" style="background:rgba(255,255,255,.2);border:none;color:#fff;border-radius:6px;padding:3px 10px;cursor:pointer;font-size:12px">✕ Close</button>
  </div>
  <div id="td-student-content" style="padding:14px">
    <div class="cias-loading">Loading…</div>
  </div>
</div>

        <?php
        wp_send_json_success(['html'=>ob_get_clean()]);
    }

    /* ── Student detail for teacher ── */
    public function cias_get_student_detail() {
        $this->check();
        if (!current_user_can('manage_options') && !current_user_can('cias_view_reports'))
            wp_send_json_error(['message'=>'Access denied.']);

        $user_id  = intval($_POST['student_id'] ?? 0);
        $batch_id = intval($_POST['batch_id']   ?? 0);
        if (!$user_id) wp_send_json_error(['message'=>'No student selected.']);

        $student  = get_userdata($user_id);
        $db       = new CIAS_DB();
        $data     = $db->get_student_detail_for_teacher($user_id, $batch_id);

        ob_start();
        $name = $student ? $student->display_name : 'Unknown';
        $initials = strtoupper(substr($name,0,1).(strpos($name,' ')!==false?substr($name,strpos($name,' ')+1,1):''));
        ?>
<div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
  <div style="width:48px;height:48px;border-radius:50%;background:#f0eeff;color:#6C63FF;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:700"><?php echo esc_html($initials); ?></div>
  <div>
    <div style="font-size:16px;font-weight:600"><?php echo esc_html($name); ?></div>
    <div style="font-size:12px;color:var(--ct-muted)"><?php echo $student ? esc_html($student->user_email) : ''; ?></div>
  </div>
</div>

<?php if(!empty($data['subjects'])): ?>
<div class="cias-test-card" style="margin-bottom:10px">
  <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--ct-muted);margin-bottom:10px">Subject accuracy</div>
  <?php foreach($data['subjects'] as $s):
    $pct = floatval($s->avg_pct ?: 0);
    $col = $pct>=70?'var(--ct-green)':($pct>=50?'var(--ct-amber)':'var(--ct-red)');
  ?>
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
    <span style="font-size:12px;min-width:90px"><?php echo esc_html($s->subject_name); ?></span>
    <div style="flex:1;height:8px;background:var(--ct-border);border-radius:99px;overflow:hidden">
      <div style="height:100%;width:<?php echo intval($pct); ?>%;background:<?php echo $col; ?>;border-radius:99px"></div>
    </div>
    <span style="font-size:12px;font-weight:600;color:<?php echo $col; ?>;min-width:36px;text-align:right"><?php echo $pct; ?>%</span>
    <span style="font-size:11px;color:var(--ct-muted)"><?php echo intval($s->tests_taken); ?> tests</span>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if(!empty($data['topics'])): ?>
<div class="cias-test-card" style="margin-bottom:10px">
  <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--ct-muted);margin-bottom:8px">Weakest topics</div>
  <?php foreach($data['topics'] as $t):
    $acc = floatval($t->accuracy ?: 0);
    $level_col = $t->level==='strong'?'#166534':($t->level==='mid'?'#92400e':'#991b1b');
    $level_bg  = $t->level==='strong'?'#dcfce7':($t->level==='mid'?'#fef3c7':'#fee2e2');
  ?>
  <div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:0.5px solid var(--ct-border)">
    <div style="flex:1">
      <div style="font-size:12px;font-weight:500"><?php echo esc_html($t->topic_name ?? '—'); ?></div>
      <div style="font-size:11px;color:var(--ct-muted)"><?php echo esc_html($t->subject_name ?? ''); ?></div>
    </div>
    <span style="font-size:10px;padding:2px 8px;border-radius:99px;background:<?php echo $level_bg; ?>;color:<?php echo $level_col; ?>;font-weight:600"><?php echo ucfirst($t->level ?? 'beginner'); ?></span>
    <span style="font-size:13px;font-weight:700;color:<?php echo $acc>=70?'var(--ct-green)':($acc>=50?'var(--ct-amber)':'var(--ct-red)'); ?>"><?php echo $acc; ?>%</span>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if(!empty($data['attempts'])): ?>
<div class="cias-test-card" style="margin-bottom:10px">
  <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--ct-muted);margin-bottom:8px">Recent tests</div>
  <?php foreach($data['attempts'] as $a):
    $pct = floatval($a->percentage ?: 0);
  ?>
  <div style="display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:0.5px solid var(--ct-border)">
    <div style="flex:1">
      <div style="font-size:12px;font-weight:500"><?php echo esc_html($a->test_title ?? 'Practice'); ?></div>
      <div style="font-size:11px;color:var(--ct-muted)"><?php echo esc_html($a->subject_name ?? ''); ?> &nbsp;·&nbsp; <?php echo $a->submitted_at ? date('d M', strtotime($a->submitted_at)) : ''; ?></div>
    </div>
    <span style="font-size:13px;font-weight:700;color:<?php echo $pct>=70?'var(--ct-green)':($pct>=50?'var(--ct-amber)':'var(--ct-red)'); ?>"><?php echo $pct; ?>%</span>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if(!empty($data['offline'])): ?>
<div class="cias-test-card">
  <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--ct-muted);margin-bottom:8px">Offline / surprise tests</div>
  <?php foreach($data['offline'] as $r):
    $pct = floatval($r->percentage ?: 0);
  ?>
  <div style="display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:0.5px solid var(--ct-border)">
    <div style="flex:1">
      <div style="font-size:12px;font-weight:500"><?php echo esc_html($r->title); ?></div>
      <div style="font-size:11px;color:var(--ct-muted)"><?php echo $r->date_conducted ? date('d M Y', strtotime($r->date_conducted)) : ''; ?></div>
    </div>
    <?php if($r->is_absent): ?>
    <span style="font-size:11px;color:var(--ct-muted)">Absent</span>
    <?php else: ?>
    <span style="font-size:12px;font-weight:600;color:<?php echo $pct>=60?'var(--ct-green)':'var(--ct-red)'; ?>"><?php echo floatval($r->marks_obtained); ?>/<?php echo intval($r->max_marks); ?></span>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if(empty($data['attempts']) && empty($data['subjects'])): ?>
<div style="text-align:center;padding:30px;color:var(--ct-muted)">No activity recorded yet for this student.</div>
<?php endif; ?>
        <?php
        wp_send_json_success(['html'=>ob_get_clean()]);
    }

    /* ── Verify PIN ── */
    public function cias_verify_pin() {
        $this->check();
        $test_id = intval($_POST['test_id'] ?? 0);
        $pin     = sanitize_text_field($_POST['pin'] ?? '');
        $db      = new CIAS_DB();
        $valid   = $db->verify_test_pin($test_id, $pin);
        if ($valid) {
            // Log session
            global $wpdb;
            $wpdb->replace($wpdb->prefix . 'cias_active_sessions', [
                'test_id'      => $test_id,
                'user_id'      => get_current_user_id(),
                'pin_verified' => 1,
                'kicked'       => 0,
                'started_at'   => current_time('mysql'),
                'last_seen'    => current_time('mysql'),
            ]);
            wp_send_json_success(['verified' => true]);
        }
        wp_send_json_error(['message' => 'Incorrect PIN or PIN has expired. Ask your teacher for the current PIN.']);
    }

    /* ── Session heartbeat ── */
    public function cias_session_heartbeat() {
        $this->check();
        global $wpdb;
        $test_id = intval($_POST['test_id'] ?? 0);
        $uid     = get_current_user_id();

        // Check if kicked
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT kicked FROM {$wpdb->prefix}cias_active_sessions WHERE test_id=%d AND user_id=%d",
            $test_id, $uid
        ));
        if ($session && $session->kicked) {
            wp_send_json_error(['kicked' => true, 'message' => 'You have been removed from this test session by your teacher.']);
        }

        // Update last_seen
        $wpdb->update(
            $wpdb->prefix . 'cias_active_sessions',
            ['last_seen' => current_time('mysql')],
            ['test_id' => $test_id, 'user_id' => $uid]
        );
        wp_send_json_success(['alive' => true]);
    }

    /* ── AI Bot status ── */
    public function cias_bot_status() {
        $this->check();
        $uid = get_current_user_id();
        wp_send_json_success(CIAS_AI_Bot::get_student_status($uid));
    }

    /* ── AI Bot — ask a question ── */
    public function cias_ask_bot() {
        $this->check();
        if (get_option('cias_ai_bot_enabled','0') !== '1')
            wp_send_json_error(['message'=>'AI Bot is not enabled.']);

        $uid      = get_current_user_id();
        $question = sanitize_textarea_field($_POST['question'] ?? '');
        $history  = isset($_POST['history']) ? json_decode(stripslashes($_POST['history']), true) : [];

        if (empty($question)) wp_send_json_error(['message'=>'Please enter a question.']);

        $can = CIAS_AI_Bot::can_ask($uid);
        if (!$can['allowed']) {
            wp_send_json_error([
                'message'      => $can['reason'],
                'show_upgrade' => $can['show_upgrade'] ?? false,
                'status'       => CIAS_AI_Bot::get_student_status($uid),
            ]);
        }

        $answer = CIAS_AI_Bot::generate_answer($uid, $question, is_array($history) ? $history : []);
        CIAS_AI_Bot::deduct_credit($uid, $can['type']);

        wp_send_json_success([
            'answer' => $answer,
            'status' => CIAS_AI_Bot::get_student_status($uid),
        ]);
    }

    /* ── Create Razorpay order ── */
    public function cias_create_razorpay_order() {
        $this->check();
        $pack_id = sanitize_text_field($_POST['pack_id'] ?? '');
        $packs   = [
            'pack_50'  => ['amount' => 9900,  'credits' => 50],
            'pack_120' => ['amount' => 19900, 'credits' => 120],
        ];
        if (!isset($packs[$pack_id])) wp_send_json_error(['message'=>'Invalid pack.']);

        $receipt = 'cias_' . get_current_user_id() . '_' . time();
        $order   = CIAS_AI_Bot::create_razorpay_order($packs[$pack_id]['amount'], $receipt);

        if (isset($order['error'])) wp_send_json_error(['message' => $order['error']]);
        if (empty($order['id']))    wp_send_json_error(['message' => 'Could not create payment order.']);

        wp_send_json_success([
            'order_id'    => $order['id'],
            'amount'      => $order['amount'],
            'currency'    => $order['currency'],
            'credits'     => $packs[$pack_id]['credits'],
            'site_name'   => get_bloginfo('name'),
            'user_name'   => wp_get_current_user()->display_name,
            'user_email'  => wp_get_current_user()->user_email,
        ]);
    }

    /* ── Verify Razorpay payment ── */
    public function cias_verify_payment() {
        $this->check();
        $order_id   = sanitize_text_field($_POST['razorpay_order_id']   ?? '');
        $payment_id = sanitize_text_field($_POST['razorpay_payment_id'] ?? '');
        $signature  = sanitize_text_field($_POST['razorpay_signature']  ?? '');
        $credits    = intval($_POST['credits'] ?? 0);

        if (!$order_id || !$payment_id || !$signature || $credits <= 0)
            wp_send_json_error(['message'=>'Invalid payment data.']);

        if (!CIAS_AI_Bot::verify_razorpay_signature($order_id, $payment_id, $signature))
            wp_send_json_error(['message'=>'Payment verification failed. Contact admin.']);

        $uid = get_current_user_id();
        CIAS_AI_Bot::add_credits($uid, $credits, $order_id);

        wp_send_json_success([
            'message' => "✅ Payment verified! {$credits} credits added to your account.",
            'status'  => CIAS_AI_Bot::get_student_status($uid),
        ]);
    }

    /* ── Content Manager: Generate questions ── */
    public function cias_cm_generate() {
        $this->check();
        if (!current_user_can('cias_use_content_manager') && !current_user_can('manage_options'))
            wp_send_json_error(['message'=>'Permission denied.']);

        $text        = sanitize_textarea_field($_POST['source_text'] ?? '');
        $subject_id  = intval($_POST['subject_id']  ?? 0);
        $topic_id    = intval($_POST['topic_id']    ?? 0);
        $subtopic_id = intval($_POST['subtopic_id'] ?? 0);
        $count       = min(20, max(5, intval($_POST['count'] ?? 10)));
        $difficulty  = sanitize_text_field($_POST['difficulty'] ?? 'adaptive');
        $q_type      = sanitize_text_field($_POST['q_type']    ?? 'standard');

        if (strlen($text) < 100)
            wp_send_json_error(['message'=>'Please provide at least 100 characters of source text.']);

        $diff_instruction = $difficulty === 'adaptive' ? 'Mix of easy (40%), medium (40%), hard (20%).'
            : "All questions should be {$difficulty} difficulty.";

        $type_instruction = $q_type === 'statement'
            ? 'Use statement-based format: provide 3-4 statements (Some/All/Only X is/are correct options).'
            : 'Use standard MCQ format with a direct question.';

        $prompt = "You are an expert UPSC exam question setter. Generate EXACTLY {$count} MCQ questions from the following text.\n\n"
            . "Rules:\n"
            . "- {$diff_instruction}\n"
            . "- {$type_instruction}\n"
            . "- All 4 options must be plausible but exactly one correct\n"
            . "- Include a concise explanation (1-2 sentences)\n"
            . "- Respond ONLY with a valid JSON array, no prose, no markdown\n\n"
            . "Format each object EXACTLY as:\n"
            . '[{"question_text":"...","option_a":"...","option_b":"...","option_c":"...","option_d":"...","correct_option":"a","explanation":"...","difficulty":"easy|medium|hard","question_type":"standard|statement"}]'
            . "\n\nSource text:\n" . mb_substr($text, 0, 4000);

        $raw = CIAS_AI_Utils::call($prompt, 'claude-haiku-4-5-20251001', 2000);
        if (empty($raw)) wp_send_json_error(['message'=>'AI did not return a response. Check API key and limits.']);

        // Strip markdown fences if present
        $raw = preg_replace('/^```json?\s*/m', '', $raw);
        $raw = preg_replace('/```\s*$/m', '', $raw);
        $questions = json_decode(trim($raw), true);

        if (!is_array($questions) || empty($questions))
            wp_send_json_error(['message'=>'Could not parse AI response. Try again.', 'raw'=>substr($raw,0,300)]);

        // Save session
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'cias_ai_generations', [
            'created_by'       => get_current_user_id(),
            'source_text_hash' => md5($text),
            'config_json'      => wp_json_encode(compact('subject_id','topic_id','subtopic_id','count','difficulty','q_type')),
            'status'           => 'generated',
            'questions_json'   => wp_json_encode($questions),
            'created_at'       => current_time('mysql'),
            'updated_at'       => current_time('mysql'),
        ]);
        $session_id = $wpdb->insert_id;

        wp_send_json_success([
            'questions'   => $questions,
            'session_id'  => $session_id,
            'count'       => count($questions),
        ]);
    }

    /* ── Content Manager: Publish approved questions ── */
    public function cias_cm_publish() {
        $this->check();
        if (!current_user_can('cias_use_content_manager') && !current_user_can('manage_options'))
            wp_send_json_error(['message'=>'Permission denied.']);

        $questions   = json_decode(stripslashes($_POST['questions'] ?? '[]'), true);
        $subject_id  = intval($_POST['subject_id']  ?? 0);
        $topic_id    = intval($_POST['topic_id']    ?? 0);
        $subtopic_id = intval($_POST['subtopic_id'] ?? 0);
        $session_id  = intval($_POST['session_id']  ?? 0);

        if (!is_array($questions) || empty($questions))
            wp_send_json_error(['message'=>'No questions to publish.']);

        global $wpdb;
        $created = 0;
        foreach ($questions as $q) {
            $correct = strtolower(trim($q['correct_option'] ?? 'a'));
            if (!in_array($correct, ['a','b','c','d'])) $correct = 'a';
            $wpdb->insert(CIAS_QUESTIONS, [
                'subject_id'    => $subject_id,
                'topic_id'      => $topic_id ?: null,
                'subtopic_id'   => $subtopic_id ?: null,
                'question_text' => sanitize_textarea_field($q['question_text'] ?? ''),
                'option_a'      => sanitize_text_field($q['option_a'] ?? ''),
                'option_b'      => sanitize_text_field($q['option_b'] ?? ''),
                'option_c'      => sanitize_text_field($q['option_c'] ?? ''),
                'option_d'      => sanitize_text_field($q['option_d'] ?? ''),
                'correct_option'=> $correct,
                'explanation'   => sanitize_textarea_field($q['explanation'] ?? ''),
                'difficulty'    => in_array($q['difficulty']??'medium',['easy','medium','hard']) ? $q['difficulty'] : 'medium',
                'question_type' => in_array($q['question_type']??'standard',['standard','statement']) ? $q['question_type'] : 'standard',
                'status'        => 'published',
                'created_by'    => get_current_user_id(),
                'created_at'    => current_time('mysql'),
            ]);
            if ($wpdb->insert_id) $created++;
        }

        // Update session status
        if ($session_id) {
            $wpdb->update($wpdb->prefix . 'cias_ai_generations',
                ['status' => 'published', 'updated_at' => current_time('mysql')],
                ['id' => $session_id]
            );
        }

        wp_send_json_success(['created' => $created, 'message' => "{$created} questions published to question bank."]);
    }

    /* ── AI Guru Chat ── */
    public function caig_guru_chat() {
        $this->check();
        $uid        = get_current_user_id();
        $question   = sanitize_textarea_field( $_POST['question']   ?? '' );
        $history    = json_decode( stripslashes( $_POST['history']  ?? '[]' ), true ) ?: [];
        $session_id = sanitize_text_field( $_POST['session_id']     ?? '' );
        $image_b64  = sanitize_text_field( $_POST['image_base64']   ?? '' );
        $image_mime = sanitize_text_field( $_POST['image_mime']     ?? 'image/jpeg' );
        $image_name = sanitize_text_field( $_POST['image_name']     ?? 'chat-image' );

        if ( empty( $question ) ) wp_send_json_error( ['message' => 'Question cannot be empty.'] );

        // Derive or generate a session ID (stable within a 10-min window)
        if ( ! $session_id ) {
            $session_id = 'ses_' . substr( md5( $uid . '_' . (int)( time() / 600 ) ), 0, 16 );
        }

        $profile  = CAIG_Data::get_profile( $uid );
        $response = CAIG_AI::guru_chat( $profile, $question, $history );

        /**
         * Phase A – A4/A5/A6: Record user message (fires classification + image save).
         */
        do_action( 'cias_guru_user_message', [
            'session_id' => $session_id,
            'user_id'    => $uid,
            'body'       => $question,
            'image_data' => $image_b64 ?: null,
            'image_mime' => $image_mime,
            'image_name' => $image_name,
            'tokens'     => null,
            'credits'    => null,
        ] );

        /**
         * Phase A – A4: Record assistant reply.
         */
        do_action( 'cias_guru_assistant_message', [
            'session_id' => $session_id,
            'user_id'    => $uid,
            'body'       => $response,
            'tokens'     => null,
        ] );

        wp_send_json_success( [
            'response'   => $response,
            'session_id' => $session_id,
            'profile'    => [
                'streak' => $profile['streak'],
                'avg'    => $profile['avg'],
                'tests'  => count( $profile['attempts'] ),
            ],
        ] );
    }

    /* ── Study Plan ── */
    public function caig_get_study_plan() {
        $this->check();
        $uid   = get_current_user_id();
        $force = !empty($_POST['force_refresh']);
        if (!$force) {
            $cached = CAIG_Data::get_cached_plan($uid);
            if ($cached) { wp_send_json_success(['plan' => $cached, 'cached' => true]); return; }
        }
        $profile = CAIG_Data::get_profile($uid);
        $plan    = CAIG_AI::generate_study_plan($profile);
        CAIG_Data::cache_plan($uid, $plan);
        wp_send_json_success(['plan' => $plan, 'cached' => false]);
    }

    /* ── Heatmap ── */
    public function caig_get_heatmap() {
        $this->check();
        global $wpdb;
        $uid  = get_current_user_id();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT s.name AS subject, s.color,
                ROUND(ts.weighted_accuracy,1) AS accuracy,
                ts.total_questions, tp.name AS topic, ts.level
             FROM " . CIAS_TOPIC_STATS . " ts
             JOIN " . CIAS_SUBJECTS . " s ON s.id=ts.subject_id
             LEFT JOIN " . CIAS_TOPICS . " tp ON tp.id=ts.topic_id
             WHERE ts.user_id=%d AND ts.total_questions >= 3
             ORDER BY s.name, accuracy ASC", $uid
        ));
        $subjects = [];
        foreach ($rows as $r) {
            if (!isset($subjects[$r->subject])) $subjects[$r->subject] = ['total'=>0,'correct'=>0,'color'=>$r->color,'topics'=>[]];
            $subjects[$r->subject]['total']   += $r->total_questions;
            $subjects[$r->subject]['correct']  += round($r->total_questions * $r->accuracy / 100);
            $subjects[$r->subject]['topics'][] = ['name'=>$r->topic,'accuracy'=>(float)$r->accuracy];
        }
        $heatmap = [];
        foreach ($subjects as $name => $s) {
            $acc = $s['total'] > 0 ? round($s['correct'] / $s['total'] * 100, 1) : 0;
            $heatmap[] = [
                'subject'    => $name,
                'accuracy'   => $acc,
                'color'      => $s['color'],
                'topics'     => $s['topics'],
                'confidence' => $acc >= 80 ? 'high' : ($acc >= 60 ? 'medium' : ($acc >= 40 ? 'low' : 'critical')),
                'label'      => $acc >= 80 ? 'Strong' : ($acc >= 60 ? 'Moderate' : ($acc >= 40 ? 'Needs Work' : 'Critical')),
            ];
        }
        usort($heatmap, fn($a,$b) => $b['accuracy'] <=> $a['accuracy']);
        wp_send_json_success(['heatmap' => $heatmap]);
    }

    /* ── Rank Predictor ── */
    public function caig_get_rank_prediction() {
        $this->check();
        global $wpdb;
        $uid   = get_current_user_id();
        $force = !empty($_POST['force_refresh']);
        if (!$force) {
            $cached = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}caig_rank_predictions WHERE user_id=%d
                 AND predicted_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)", $uid
            ));
            if ($cached) {
                wp_send_json_success(['prediction'=>json_decode($cached->analysis_json,true),'cached'=>true,'predicted_at'=>$cached->predicted_at]);
                return;
            }
        }
        $profile = CAIG_Data::get_profile($uid);
        if (count($profile['attempts']) < 3) {
            wp_send_json_error(['message'=>'Take at least 3 tests to get a rank prediction. Keep practising!']);
            return;
        }
        $prediction = CAIG_AI::predict_rank($profile);
        wp_send_json_success(['prediction'=>$prediction,'cached'=>false,'predicted_at'=>current_time('mysql')]);
    }

    /* ── Lecture Recommendations ── */
    public function caig_get_lecture_recs() {
        $this->check();
        $uid     = get_current_user_id();
        $profile = CAIG_Data::get_profile($uid);
        $recs    = CAIG_AI::get_lecture_recommendations($profile);
        wp_send_json_success(['recommendations' => $recs]);
    }

    /* ── Lecture CRUD (admin) ── */
    public function caig_save_lecture() {
        $this->check();
        if (!current_user_can('manage_options') && !current_user_can('cias_use_content_manager'))
            wp_send_json_error(['message'=>'Unauthorized.']);
        global $wpdb;
        $data = [
            'subject_id'     => intval($_POST['subject_id']    ?? 0),
            'topic_id'       => intval($_POST['topic_id']      ?? 0),
            'lecture_number' => intval($_POST['lecture_number']?? 1),
            'title'          => sanitize_text_field($_POST['title']      ?? ''),
            'description'    => sanitize_textarea_field($_POST['description'] ?? ''),
            'url'            => esc_url_raw($_POST['url']        ?? ''),
            'thumbnail'      => esc_url_raw($_POST['thumbnail'] ?? ''),
            'duration_min'   => intval($_POST['duration_min']  ?? 0),
        ];
        if (!$data['subject_id'] || !$data['title']) wp_send_json_error(['message'=>'Subject and title required.']);
        $id = intval($_POST['id'] ?? 0);
        if ($id) $wpdb->update("{$wpdb->prefix}caig_lectures", $data, ['id'=>$id]);
        else     { $wpdb->insert("{$wpdb->prefix}caig_lectures", $data); $id = $wpdb->insert_id; }
        wp_send_json_success(['id'=>$id]);
    }

    public function caig_delete_lecture() {
        $this->check();
        if (!current_user_can('manage_options') && !current_user_can('cias_use_content_manager'))
            wp_send_json_error(['message'=>'Unauthorized.']);
        global $wpdb;
        $wpdb->delete("{$wpdb->prefix}caig_lectures", ['id'=>intval($_POST['id']??0)]);
        wp_send_json_success();
    }

    public function caig_get_lectures() {
        $this->check();
        wp_send_json_success(['lectures' => CAIG_Data::get_lectures()]);
    }

    /* ── AI batch/student overview ── */
    public function cias_ai_overview() {
        $this->check();
        if (!current_user_can('manage_options') && !current_user_can('cias_view_reports'))
            wp_send_json_error(['message' => 'Access denied.']);

        $batch_id = intval($_POST['batch_id'] ?? 0);
        $question = sanitize_textarea_field($_POST['question'] ?? '');
        if (!$batch_id || empty($question))
            wp_send_json_error(['message' => 'Missing batch or question.']);

        $api_key = get_option('cias_anthropic_key', '');
        if (empty($api_key))
            wp_send_json_error(['message' => 'Anthropic API key not set. Go to CIAS Tests → Settings to add it.']);

        // Build data context
        $db       = new CIAS_DB();
        $overview = $db->get_batch_overview($batch_id);
        $heatmap  = $db->get_batch_subject_heatmap($batch_id);
        $inactive = $db->get_batch_inactive_students($batch_id, 7);
        $students = $db->get_leaderboard($batch_id, 'all', 0);
        $weak     = $db->get_batch_weak_topics($batch_id, 5);
        $curve    = $db->get_batch_weekly_curve($batch_id, 4);
        $batch    = $db->get_by_id('batches', $batch_id);

        // Build compact context string for Claude
        $student_list = [];
        foreach ($students as $s) {
            $student_list[] = [
                'name'       => $s->display_name,
                'avg_pct'    => floatval($s->avg_pct ?: 0),
                'tests_taken'=> intval($s->total_tests),
                'streak_days'=> intval($s->streak),
            ];
        }

        $subject_acc = [];
        foreach ($heatmap as $h) {
            $subject_acc[$h->subject_name] = floatval($h->avg_pct ?: 0);
        }

        $weak_topics = [];
        foreach ($weak as $w) {
            $weak_topics[] = $w->topic_name . ' (' . $w->subject_name . ') — ' . round($w->avg_accuracy ?: 0) . '%';
        }

        $inactive_list = [];
        foreach ($inactive as $s) {
            $days = $s->last_active ? round((time() - strtotime($s->last_active)) / 86400) : null;
            $inactive_list[] = $s->display_name . ($days ? " — last active {$days} days ago" : " — never logged in");
        }

        $weekly_trend = [];
        foreach ($curve as $w) {
            $weekly_trend[] = $w['week'] . ': ' . ($w['avg'] ?: 0) . '%';
        }

        $context = "You are an expert UPSC coaching assistant helping a teacher understand their class performance. " .
            "Be specific, actionable, and empathetic. Use the data below to answer the teacher's question. " .
            "Keep your response concise but insightful — 3 to 6 short paragraphs. Use bullet points where helpful.\n\n" .
            "BATCH: " . ($batch->name ?? 'Unknown') . "\n" .
            "OVERVIEW:\n" .
            "- Enrolled students: " . $overview['enrolled'] . "\n" .
            "- Batch average score: " . $overview['avg_pct'] . "%\n" .
            "- Total tests taken: " . $overview['tests_done'] . "\n" .
            "- Active this week: " . $overview['active_week'] . "/" . $overview['enrolled'] . "\n\n" .
            "WEEKLY TREND (last 4 weeks): " . implode(', ', $weekly_trend) . "\n\n" .
            "STUDENT BREAKDOWN:\n" . json_encode($student_list, JSON_PRETTY_PRINT) . "\n\n" .
            "SUBJECT ACCURACY (class average):\n" . json_encode($subject_acc, JSON_PRETTY_PRINT) . "\n\n" .
            "WEAKEST TOPICS:\n- " . implode("\n- ", $weak_topics ?: ['No topic data yet']) . "\n\n" .
            "STUDENTS NEEDING ATTENTION (inactive 7+ days):\n- " . implode("\n- ", $inactive_list ?: ['All students are active']) . "\n";

        // Call Claude API
        $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
            'timeout' => 45,
            'headers' => [
                'Content-Type'      => 'application/json',
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
            ],
            'body' => wp_json_encode([
                'model'      => 'claude-haiku-4-5-20251001',
                'max_tokens' => 800,
                'system'     => $context,
                'messages'   => [
                    ['role' => 'user', 'content' => $question]
                ],
            ]),
        ]);

        if (is_wp_error($response))
            wp_send_json_error(['message' => 'API call failed: ' . $response->get_error_message()]);

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['content'][0]['text']))
            wp_send_json_error(['message' => 'No response from AI. Check API key in Settings.']);

        $answer = $body['content'][0]['text'];

        // Convert markdown to basic HTML for display
        $answer = htmlspecialchars($answer, ENT_QUOTES, 'UTF-8');
        $answer = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $answer);
        $answer = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $answer);
        $answer = preg_replace('/^#{1,3} (.+)$/m', '<strong style="font-size:14px">$1</strong>', $answer);
        $answer = preg_replace('/^- (.+)$/m', '• $1', $answer);
        $answer = nl2br($answer);

        wp_send_json_success(['answer' => $answer, 'question' => esc_html($question)]);
    }

    /* ── Student offline test history ── */
    public function cias_get_offline_history() {
        $this->check();
        $uid  = get_current_user_id();
        $db   = new CIAS_DB();
        $rows = $db->get_student_offline_results($uid);
        ob_start();
        if (empty($rows)): ?>
<div class="cias-empty"><div class="cias-empty-icon">📝</div><p>No offline test results published yet.</p></div>
        <?php else: ?>
<div style="font-size:13px;font-weight:500;margin-bottom:10px">Offline & Surprise Tests</div>
<?php foreach($rows as $r):
  $passed = $r->percentage >= 60;
  $col    = $passed ? 'var(--ct-green)' : 'var(--ct-red)';
?>
<div style="display:flex;align-items:center;gap:12px;padding:12px 14px;background:var(--ct-card);border:0.5px solid var(--ct-border);border-radius:12px;margin-bottom:7px;flex-wrap:wrap">
  <div style="flex:1">
    <div style="font-size:14px;font-weight:500"><?php echo esc_html($r->title); ?></div>
    <div style="font-size:12px;color:var(--ct-muted)">
      <?php echo $r->date_conducted ? date('d M Y', strtotime($r->date_conducted)) : ''; ?>
      <?php if($r->subject_name): ?>&nbsp;·&nbsp;<?php echo esc_html($r->subject_name); ?><?php endif; ?>
    </div>
  </div>
  <span style="font-size:11px;background:#eff6ff;color:#185FA5;padding:3px 10px;border-radius:99px"><?php echo esc_html($r->test_type); ?></span>
  <?php if($r->is_absent): ?>
  <span style="font-size:12px;color:var(--ct-muted);padding:4px 12px;border-radius:99px;background:var(--ct-bg)">Absent</span>
  <?php else: ?>
  <span style="font-size:13px;font-weight:500;color:<?php echo $col; ?>"><?php echo floatval($r->marks_obtained); ?>/<?php echo intval($r->max_marks); ?> (<?php echo floatval($r->percentage); ?>%)</span>
  <span style="font-size:11px;font-weight:500;padding:3px 10px;border-radius:99px;background:<?php echo $passed?'#dcfce7':'#fee2e2'; ?>;color:<?php echo $passed?'#166534':'#991b1b'; ?>"><?php echo esc_html($r->grade); ?></span>
  <?php endif; ?>
</div>
<?php endforeach; endif;
        wp_send_json_success(['html'=>ob_get_clean()]);
    }
}
