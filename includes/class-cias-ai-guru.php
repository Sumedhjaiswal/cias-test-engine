<?php
if (!defined('ABSPATH')) exit;

/* ══════════════════════════════════════════════════════════════════
   CIAS AI GURU — merged into CIAS Test Engine
   Data layer, AI engine, AJAX handlers, Frontend renderer
   DB tables: caig_study_plans, caig_lectures, caig_rank_predictions
══════════════════════════════════════════════════════════════════ */

/* ══ DATA LAYER ══════════════════════════════════════════════════ */
class CAIG_Data {

    public static function get_profile(int $uid): array {
        global $wpdb;
        $user    = get_userdata($uid);
        $name    = $user ? ($user->first_name ?: $user->display_name) : 'Student';

        $attempts = $wpdb->get_results($wpdb->prepare(
            "SELECT percentage, score, total, submitted_at FROM " . CIAS_ATTEMPTS . "
             WHERE user_id=%d AND status='submitted' ORDER BY submitted_at DESC LIMIT 50", $uid
        ));
        $pcts = array_column($attempts, 'percentage');
        $avg  = $pcts ? round(array_sum($pcts) / count($pcts), 1) : 0;
        $best = $pcts ? max($pcts) : 0;

        $recent5 = array_slice($pcts, 0, 5);
        $prev5   = array_slice($pcts, 5, 5);
        $trend   = 'stable';
        if (count($recent5) >= 2 && count($prev5) >= 2) {
            $r_avg = array_sum($recent5) / count($recent5);
            $p_avg = array_sum($prev5) / count($prev5);
            $trend = ($r_avg > $p_avg + 3) ? 'improving' : (($r_avg < $p_avg - 3) ? 'declining' : 'stable');
        }

        $subject_stats = $wpdb->get_results($wpdb->prepare(
            "SELECT s.name AS subject, s.id AS subject_id, s.color,
                SUM(ts.correct_questions) AS correct,
                SUM(ts.total_questions) AS total,
                ROUND(SUM(ts.correct_questions)/NULLIF(SUM(ts.total_questions),0)*100,1) AS accuracy
             FROM " . CIAS_TOPIC_STATS . " ts
             JOIN " . CIAS_SUBJECTS . " s ON s.id = ts.subject_id
             WHERE ts.user_id=%d
             GROUP BY ts.subject_id ORDER BY accuracy ASC", $uid
        ));

        $weak_topics = $wpdb->get_results($wpdb->prepare(
            "SELECT tp.name AS topic, s.name AS subject, s.color,
                ROUND(ts.weighted_accuracy,1) AS accuracy, ts.next_revision
             FROM " . CIAS_TOPIC_STATS . " ts
             JOIN " . CIAS_TOPICS . " tp ON tp.id = ts.topic_id
             JOIN " . CIAS_SUBJECTS . " s ON s.id = ts.subject_id
             WHERE ts.user_id=%d AND ts.weighted_accuracy < 50
             ORDER BY ts.weighted_accuracy ASC LIMIT 8", $uid
        ));

        $strong_topics = $wpdb->get_results($wpdb->prepare(
            "SELECT tp.name AS topic, s.name AS subject,
                ROUND(ts.weighted_accuracy,1) AS accuracy
             FROM " . CIAS_TOPIC_STATS . " ts
             JOIN " . CIAS_TOPICS . " tp ON tp.id = ts.topic_id
             JOIN " . CIAS_SUBJECTS . " s ON s.id = ts.subject_id
             WHERE ts.user_id=%d AND ts.weighted_accuracy >= 80
             ORDER BY ts.weighted_accuracy DESC LIMIT 5", $uid
        ));

        $due_revisions = $wpdb->get_results($wpdb->prepare(
            "SELECT tp.name AS topic, s.name AS subject
             FROM " . CIAS_TOPIC_STATS . " ts
             JOIN " . CIAS_TOPICS . " tp ON tp.id = ts.topic_id
             JOIN " . CIAS_SUBJECTS . " s ON s.id = ts.subject_id
             WHERE ts.user_id=%d AND ts.next_revision <= CURDATE() LIMIT 5", $uid
        ));

        $batches = $wpdb->get_results($wpdb->prepare(
            "SELECT b.id, b.name, c.name AS course, b.start_date, b.end_date
             FROM " . CIAS_ENROLLMENTS . " e
             JOIN " . CIAS_BATCHES . " b ON b.id = e.batch_id
             JOIN " . CIAS_COURSES  . " c ON c.id = b.course_id
             WHERE e.user_id=%d AND e.status='active'", $uid
        ));

        $recent_dates = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT DATE(submitted_at) AS d FROM " . CIAS_ATTEMPTS . "
             WHERE user_id=%d AND status='submitted' ORDER BY d DESC LIMIT 30", $uid
        ));
        $streak = 0;
        $check  = date('Y-m-d');
        foreach ($recent_dates as $d) {
            if ($d === $check) { $streak++; $check = date('Y-m-d', strtotime($d . ' -1 day')); }
            else break;
        }

        $week_tests = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . CIAS_ATTEMPTS . "
             WHERE user_id=%d AND status='submitted'
             AND submitted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)", $uid
        ));

        return compact('uid','name','avg','best','trend','subject_stats','weak_topics',
            'strong_topics','due_revisions','batches','streak','week_tests','attempts');
    }

    public static function profile_to_context(array $p): string {
        $ctx  = "Student: {$p['name']}. ";
        $ctx .= "Tests: " . count($p['attempts']) . ", Avg: {$p['avg']}%, Best: {$p['best']}%, Trend: {$p['trend']}, Streak: {$p['streak']} days. ";
        if (!empty($p['subject_stats'])) {
            $parts = array_map(fn($s) => "{$s->subject}: {$s->accuracy}%", $p['subject_stats']);
            $ctx  .= "Subjects — " . implode(', ', $parts) . ". ";
        }
        if (!empty($p['weak_topics'])) {
            $w = array_map(fn($t) => "{$t->topic} ({$t->subject},{$t->accuracy}%)", $p['weak_topics']);
            $ctx .= "Weak: " . implode(', ', $w) . ". ";
        }
        if (!empty($p['due_revisions'])) {
            $r = array_map(fn($t) => $t->topic, $p['due_revisions']);
            $ctx .= "Due revision: " . implode(', ', $r) . ". ";
        }
        return $ctx;
    }

    public static function get_cached_plan(int $uid): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT plan_json FROM {$wpdb->prefix}caig_study_plans
             WHERE user_id=%d AND plan_date=CURDATE()", $uid
        ));
        return $row ? json_decode($row->plan_json, true) : null;
    }

    public static function cache_plan(int $uid, array $plan): void {
        global $wpdb;
        $wpdb->replace("{$wpdb->prefix}caig_study_plans", [
            'user_id'    => $uid,
            'plan_date'  => current_time('Y-m-d'),
            'plan_json'  => wp_json_encode($plan),
            'created_at' => current_time('mysql'),
        ]);
    }

    public static function save_rank_prediction(int $uid, array $data): void {
        global $wpdb;
        $wpdb->replace("{$wpdb->prefix}caig_rank_predictions", [
            'user_id'        => $uid,
            'prelims_low'    => intval($data['prelims_low']    ?? 0),
            'prelims_high'   => intval($data['prelims_high']   ?? 0),
            'mains_estimate' => intval($data['mains_estimate'] ?? 0),
            'confidence'     => intval($data['confidence']     ?? 0),
            'analysis_json'  => wp_json_encode($data),
            'predicted_at'   => current_time('mysql'),
        ]);
    }

    public static function get_lectures(?int $subject_id = null, ?int $topic_id = null): array {
        global $wpdb;
        $where_parts = ['1=1'];
        $args = [];
        if ($subject_id) { $where_parts[] = 'l.subject_id=%d'; $args[] = $subject_id; }
        if ($topic_id)   { $where_parts[] = 'l.topic_id=%d';   $args[] = $topic_id; }
        $where = implode(' AND ', $where_parts);
        $sql = "SELECT l.*, s.name AS subject_name, t.name AS topic_name
                FROM {$wpdb->prefix}caig_lectures l
                JOIN " . CIAS_SUBJECTS . " s ON s.id=l.subject_id
                LEFT JOIN " . CIAS_TOPICS . " t ON t.id=l.topic_id
                WHERE {$where}
                ORDER BY l.subject_id, l.lecture_number";
        return $args ? $wpdb->get_results($wpdb->prepare($sql, ...$args)) : $wpdb->get_results($sql);
    }
}

/* ══ AI ENGINE ═══════════════════════════════════════════════════ */
class CAIG_AI {
    const MODEL = 'claude-haiku-4-5-20251001';

    public static function guru_chat(array $profile, string $question, array $history = []): string {
        $key = CIAS_AI_Utils::get_api_key();
        if (empty($key)) return 'AI is not configured. Please contact your admin.';

        $ctx    = CAIG_Data::profile_to_context($profile);
        $system = "You are CIAS AI Guru — a warm, deeply knowledgeable senior UPSC mentor and life coach. "
            . "Student context: {$ctx} "
            . "You help with: UPSC content questions, study strategy, motivation, emotional support, "
            . "time management, answer writing, current affairs, and general encouragement. "
            . "When asked for motivation or inspiration, respond with a powerful, personalised pep talk. "
            . "When asked UPSC content questions, give specific data-driven guidance. "
            . "Use • for bullet points. Keep replies under 220 words. Support Hindi + English mixing.";

        $messages = [];
        foreach (array_slice($history, -6) as $msg) {
            if (!empty($msg['role']) && !empty($msg['content']))
                $messages[] = ['role' => $msg['role'], 'content' => sanitize_textarea_field($msg['content'])];
        }
        $messages[] = ['role' => 'user', 'content' => $question];

        $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
            'timeout' => 35,
            'headers' => [
                'Content-Type'      => 'application/json',
                'x-api-key'         => $key,
                'anthropic-version' => '2023-06-01',
            ],
            'body' => wp_json_encode([
                'model'      => self::MODEL,
                'max_tokens' => 500,
                'system'     => $system,
                'messages'   => $messages,
            ]),
        ]);

        if (is_wp_error($response)) return 'Could not connect to AI. Please try again.';
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $text = trim($body['content'][0]['text'] ?? '');
        CIAS_AI_Utils::log_usage(self::MODEL, intval($body['usage']['input_tokens']??0), intval($body['usage']['output_tokens']??0), 'guru', get_current_user_id());
        return $text ?: 'Could not generate a response. Please try rephrasing.';
    }

    public static function generate_study_plan(array $profile): array {
        $key = CIAS_AI_Utils::get_api_key();
        $ctx = CAIG_Data::profile_to_context($profile);

        if (empty($key)) return self::fallback_plan($profile);

        $weak_list = implode(', ', array_map(fn($t) => $t->topic, $profile['weak_topics']));
        $revision  = implode(', ', array_map(fn($t) => $t->topic, $profile['due_revisions']));

        $prompt = "Student: {$ctx}\n\n"
            . "Generate today's UPSC study plan. Return ONLY valid JSON:\n"
            . '{"date":"' . current_time('Y-m-d') . '",'
            . '"greeting":"short motivational sentence for ' . $profile['name'] . '",'
            . '"tasks":[{"type":"mcq","count":20,"subject":"...","topic":"...","why":"..."},'
            . '{"type":"vocab","deck":"...","count":15,"why":"..."},'
            . '{"type":"lecture","subject":"...","topic":"...","why":"..."},'
            . '{"type":"revision","topic":"...","subject":"...","why":"..."}],'
            . '"focus_tip":"one actionable tip","estimated_hours":3.5}'
            . "\n\nPrioritize weak topics: {$weak_list}. Due revisions: {$revision}.";

        $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
            'timeout' => 35,
            'headers' => ['Content-Type'=>'application/json','x-api-key'=>$key,'anthropic-version'=>'2023-06-01'],
            'body'    => wp_json_encode([
                'model'=>self::MODEL,'max_tokens'=>600,
                'system'=>'You are a UPSC study planner. Output ONLY valid JSON, no prose.',
                'messages'=>[['role'=>'user','content'=>$prompt]],
            ]),
        ]);

        if (is_wp_error($response)) return self::fallback_plan($profile);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $raw  = preg_replace('/```json|```/i', '', trim($body['content'][0]['text'] ?? ''));
        $plan = json_decode(trim($raw), true);
        CIAS_AI_Utils::log_usage(self::MODEL, intval($body['usage']['input_tokens']??0), intval($body['usage']['output_tokens']??0), 'guru', get_current_user_id());

        return (is_array($plan) && !empty($plan['tasks'])) ? $plan : self::fallback_plan($profile);
    }

    private static function fallback_plan(array $profile): array {
        $subject = !empty($profile['subject_stats']) ? $profile['subject_stats'][0]->subject : 'Polity';
        $topic   = !empty($profile['weak_topics'])   ? $profile['weak_topics'][0]->topic     : 'General';
        return [
            'date'            => current_time('Y-m-d'),
            'greeting'        => "Every question solved is a step closer to IAS, {$profile['name']}!",
            'tasks'           => [
                ['type'=>'mcq',      'count'=>20, 'subject'=>$subject, 'topic'=>$topic, 'why'=>'Weakest area needs daily practice'],
                ['type'=>'vocab',    'deck'=>"Today's Words",    'count'=>15, 'why'=>'Vocabulary builds comprehension'],
                ['type'=>'lecture',  'subject'=>$subject, 'topic'=>$topic, 'why'=>'Concept clarity before practice'],
                ['type'=>'revision', 'topic'=>$topic, 'subject'=>$subject, 'why'=>'Spaced repetition improves retention'],
            ],
            'focus_tip'       => 'Start with your hardest topic when your mind is fresh.',
            'estimated_hours' => 3.5,
        ];
    }

    public static function predict_rank(array $profile): array {
        $key = CIAS_AI_Utils::get_api_key();
        $ctx = CAIG_Data::profile_to_context($profile);

        if (empty($key)) return self::deterministic_rank($profile);

        $prompt = "Student: {$ctx}\n\nPredict UPSC Prelims score. Return ONLY JSON:\n"
            . '{"prelims_low":88,"prelims_high":96,"mains_estimate":720,"confidence":72,'
            . '"cutoff_comparison":"Above/Below/Near expected cutoff (~110)",'
            . '"key_factors":["factor1","factor2","factor3"],'
            . '"improvement_areas":["action1","action2"],'
            . '"disclaimer":"AI estimate based on practice data."}';

        $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
            'timeout' => 35,
            'headers' => ['Content-Type'=>'application/json','x-api-key'=>$key,'anthropic-version'=>'2023-06-01'],
            'body'    => wp_json_encode([
                'model'=>self::MODEL,'max_tokens'=>400,
                'system'=>'You are a UPSC rank prediction AI. Output ONLY valid JSON.',
                'messages'=>[['role'=>'user','content'=>$prompt]],
            ]),
        ]);

        if (is_wp_error($response)) return self::deterministic_rank($profile);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $raw  = preg_replace('/```json|```/i', '', trim($body['content'][0]['text'] ?? ''));
        $data = json_decode(trim($raw), true);
        CIAS_AI_Utils::log_usage(self::MODEL, intval($body['usage']['input_tokens']??0), intval($body['usage']['output_tokens']??0), 'guru', get_current_user_id());

        if (!is_array($data) || empty($data['prelims_low'])) return self::deterministic_rank($profile);
        CAIG_Data::save_rank_prediction(get_current_user_id(), $data);
        return $data;
    }

    private static function deterministic_rank(array $p): array {
        $avg  = (float) $p['avg'];
        $base = round($avg * 200 / 100 * 0.8);
        return [
            'prelims_low'       => max(70, $base - 8),
            'prelims_high'      => min(180, $base + 8),
            'mains_estimate'    => 0,
            'confidence'        => min(85, max(40, count($p['attempts']) * 3)),
            'cutoff_comparison' => 'Based on available data',
            'key_factors'       => ["Overall avg: {$avg}%", "Tests taken: " . count($p['attempts'])],
            'improvement_areas' => ['Increase test frequency', 'Focus on weak topics'],
            'disclaimer'        => 'AI estimate. Take at least 10 tests for accurate prediction.',
        ];
    }

    public static function get_lecture_recommendations(array $profile): array {
        global $wpdb;
        $weak_subjects = array_filter($profile['subject_stats'], fn($s) => (float)$s->accuracy < 60);
        $recs = [];

        foreach ($weak_subjects as $ws) {
            $weak_in = array_filter($profile['weak_topics'], fn($t) => $t->subject === $ws->subject);
            foreach ($weak_in as $wt) {
                $lectures = $wpdb->get_results($wpdb->prepare(
                    "SELECT l.*, s.name AS subject_name, t.name AS topic_name
                     FROM {$wpdb->prefix}caig_lectures l
                     JOIN " . CIAS_SUBJECTS . " s ON s.id=l.subject_id
                     LEFT JOIN " . CIAS_TOPICS . " t ON t.id=l.topic_id
                     WHERE l.subject_id=%d AND t.name LIKE %s
                     ORDER BY l.lecture_number ASC LIMIT 2",
                    $ws->subject_id, '%' . $wpdb->esc_like($wt->topic) . '%'
                ));
                foreach ($lectures as $lec) {
                    $recs[] = [
                        'lecture_id'     => $lec->id,
                        'lecture_number' => $lec->lecture_number,
                        'title'          => $lec->title,
                        'subject'        => $lec->subject_name,
                        'topic'          => $wt->topic,
                        'accuracy'       => $wt->accuracy,
                        'url'            => $lec->url,
                        'thumbnail'      => $lec->thumbnail ?: '',
                        'duration_min'   => $lec->duration_min,
                        'reason'         => "Your {$wt->topic} accuracy is {$wt->accuracy}% — this lecture will help.",
                        'color'          => $wt->color ?? '#6C63FF',
                    ];
                }
                if (empty($lectures)) {
                    $general = $wpdb->get_row($wpdb->prepare(
                        "SELECT l.*, s.name AS subject_name FROM {$wpdb->prefix}caig_lectures l
                         JOIN " . CIAS_SUBJECTS . " s ON s.id=l.subject_id
                         WHERE l.subject_id=%d ORDER BY l.lecture_number LIMIT 1", $ws->subject_id
                    ));
                    if ($general) {
                        $recs[] = [
                            'lecture_id'   => $general->id,
                            'lecture_number'=> $general->lecture_number,
                            'title'        => $general->title,
                            'subject'      => $general->subject_name,
                            'topic'        => $wt->topic,
                            'accuracy'     => $wt->accuracy,
                            'url'          => $general->url,
                            'thumbnail'    => $general->thumbnail ?: '',
                            'duration_min' => $general->duration_min,
                            'reason'       => "Start from the beginning of {$general->subject_name} — {$wt->topic} needs work.",
                            'color'        => $wt->color ?? '#6C63FF',
                        ];
                    }
                }
            }
        }
        return array_slice($recs, 0, 6);
    }
}

/* ══ FRONTEND RENDERER ═══════════════════════════════════════════ */
class CAIG_Frontend {

    public static function render(): void {
        $user = wp_get_current_user();
        $name = $user->first_name ?: $user->display_name;
        ?>
<div class="caig-app" id="caig-app" data-nonce="<?php echo esc_attr(wp_create_nonce('caig_nonce')); ?>">

  <!-- Sub-nav -->
  <div class="caig-subnav">
    <button class="caig-snav-btn active" data-panel="guru">
      <span class="caig-snav-icon">🧠</span>
      <span class="caig-snav-label">AI Mentor</span>
    </button>
    <button class="caig-snav-btn" data-panel="planner">
      <span class="caig-snav-icon">📅</span>
      <span class="caig-snav-label">Study Plan</span>
    </button>
    <button class="caig-snav-btn" data-panel="lectures">
      <span class="caig-snav-icon">🎬</span>
      <span class="caig-snav-label">Lectures</span>
    </button>
    <button class="caig-snav-btn" data-panel="rank">
      <span class="caig-snav-icon">🏆</span>
      <span class="caig-snav-label">Rank</span>
    </button>
    <button class="caig-snav-btn" data-panel="heatmap">
      <span class="caig-snav-icon">🔥</span>
      <span class="caig-snav-label">Heatmap</span>
    </button>
  </div>

  <!-- ══ AI MENTOR PANEL ══ -->
  <div class="caig-panel active" id="caig-panel-guru">
    <div class="caig-guru-hero">
      <div class="caig-guru-hero-left">
        <div class="caig-guru-avatar-wrap">
          <div class="caig-guru-avatar">🧠</div>
          <div class="caig-guru-pulse"></div>
        </div>
        <div>
          <h2 class="caig-guru-name">CIAS AI Guru</h2>
          <p class="caig-guru-tagline">Your personal UPSC mentor · Always available</p>
        </div>
      </div>
      <div class="caig-guru-hero-stats" id="caig-hero-stats">
        <div class="caig-hstat"><span class="caig-hstat-val" id="caig-stat-streak">—</span><span class="caig-hstat-lbl">Day streak</span></div>
        <div class="caig-hstat"><span class="caig-hstat-val" id="caig-stat-avg">—</span><span class="caig-hstat-lbl">Avg score</span></div>
        <div class="caig-hstat"><span class="caig-hstat-val" id="caig-stat-tests">—</span><span class="caig-hstat-lbl">Tests taken</span></div>
      </div>
    </div>

    <div class="caig-prompts-row">
      <button class="caig-chip" data-q="What should I study today based on my weak areas?">📚 What to study today?</button>
      <button class="caig-chip" data-q="What are my weakest topics and how do I improve?">⚠️ My weak areas</button>
      <button class="caig-chip" data-q="What is my predicted UPSC Prelims score?">🎯 Predicted score</button>
      <button class="caig-chip" data-q="Give me a strong motivational push for UPSC!">💪 Motivate me</button>
      <button class="caig-chip" data-q="Am I on track for UPSC Prelims? What should I do differently?">📊 Am I on track?</button>
      <button class="caig-chip" data-q="Which subjects need the most urgent attention?">🔴 Urgent topics</button>
    </div>

    <div class="caig-chat-box" id="caig-chat-box">
      <div class="caig-msg caig-ai">
        <div class="caig-msg-av">🧠</div>
        <div class="caig-msg-bbl">
          <strong>Namaste, <?php echo esc_html($name); ?>! 🙏</strong><br>
          I have access to your complete performance data — tests, scores, weak topics, and trends.<br><br>
          Ask me what to study today, your weakest areas, predicted Prelims score, or study strategy. I'm here to guide you!
        </div>
      </div>
    </div>

    <div class="caig-input-wrap">
      <textarea id="caig-input" class="caig-input" rows="1"
        placeholder="Ask your AI Guru anything… (Enter to send, Shift+Enter for new line)"></textarea>
      <button class="caig-send" id="caig-send">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
      </button>
    </div>
  </div>

  <!-- ══ STUDY PLANNER PANEL ══ -->
  <div class="caig-panel" id="caig-panel-planner" style="display:none">
    <div class="caig-panel-topbar">
      <div>
        <h2 class="caig-panel-title">📅 Daily Study Plan</h2>
        <p class="caig-panel-sub">AI-generated from your performance data · Refreshes daily</p>
      </div>
      <button class="caig-refresh-btn" id="caig-plan-refresh">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
        Regenerate
      </button>
    </div>
    <div id="caig-plan-wrap" class="caig-plan-wrap">
      <div class="caig-skeleton-wrap">
        <div class="caig-skeleton caig-sk-greeting"></div>
        <div class="caig-skeleton caig-sk-card"></div>
        <div class="caig-skeleton caig-sk-card"></div>
        <div class="caig-skeleton caig-sk-card"></div>
      </div>
    </div>
  </div>

  <!-- ══ LECTURES PANEL ══ -->
  <div class="caig-panel" id="caig-panel-lectures" style="display:none">
    <div class="caig-panel-topbar">
      <div>
        <h2 class="caig-panel-title">🎬 Recommended Lectures</h2>
        <p class="caig-panel-sub">Personalised based on your weak topics</p>
      </div>
    </div>
    <div id="caig-lec-wrap" class="caig-lec-wrap">
      <div class="caig-skeleton-wrap">
        <div class="caig-skeleton caig-sk-lec"></div>
        <div class="caig-skeleton caig-sk-lec"></div>
        <div class="caig-skeleton caig-sk-lec"></div>
      </div>
    </div>
  </div>

  <!-- ══ RANK PANEL ══ -->
  <div class="caig-panel" id="caig-panel-rank" style="display:none">
    <div class="caig-panel-topbar">
      <div>
        <h2 class="caig-panel-title">🏆 Rank Predictor</h2>
        <p class="caig-panel-sub">AI estimate based on your actual test performance</p>
      </div>
      <button class="caig-refresh-btn" id="caig-rank-refresh">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
        Refresh
      </button>
    </div>
    <div id="caig-rank-wrap" class="caig-rank-wrap">
      <div class="caig-skeleton-wrap">
        <div class="caig-skeleton caig-sk-rank"></div>
      </div>
    </div>
  </div>

  <!-- ══ HEATMAP PANEL ══ -->
  <div class="caig-panel" id="caig-panel-heatmap" style="display:none">
    <div class="caig-panel-topbar">
      <div>
        <h2 class="caig-panel-title">🔥 Performance Heatmap</h2>
        <p class="caig-panel-sub">Subject accuracy matrix — see exactly where you stand</p>
      </div>
    </div>
    <div id="caig-heatmap-wrap" class="caig-heatmap-wrap">
      <div class="caig-skeleton-wrap">
        <div class="caig-skeleton caig-sk-hmap"></div>
      </div>
    </div>
  </div>

</div>
    <?php
    }
}
