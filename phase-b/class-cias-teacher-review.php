<?php
/**
 * CIAS Phase B – Teacher Review Queue
 *
 * Admin page for teachers to review submissions flagged by the confidence gate.
 * Reads from cias_teacher_reviews (lightweight — no heavy aggregations on load).
 *
 * Features:
 *   - Review queue with priority/status filter
 *   - View submission image + extracted OCR text
 *   - Enter corrected text + manual score
 *   - Push to evaluation if teacher corrects OCR
 *   - Override AI score with manual score
 *
 * @package CIAS\PhaseB
 * @since   3.19.0
 */
defined( 'ABSPATH' ) || exit;

class CIAS_Teacher_Review {

    const PAGE_SLUG    = 'cias-review-queue';
    const NONCE_ACTION = 'cias_teacher_review_submit';

    public static function init(): void {
        add_action( 'admin_menu',            [ __CLASS__, 'register_menu'  ] );
        add_action( 'admin_init',            [ __CLASS__, 'handle_review'  ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue'        ] );
    }

    public static function register_menu(): void {
        add_submenu_page(
            'cias-tests',
            __('Review Queue', 'cias-test'),
            __('✍️ Review Queue', 'cias-test'),
            'cias_view_reports',
            self::PAGE_SLUG,
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function enqueue( string $hook ): void {
        if ( strpos($hook, self::PAGE_SLUG) === false ) return;
        wp_add_inline_style('wp-admin', self::inline_css());
    }

    // ── Handle review form submission ─────────────────────────────────────────

    public static function handle_review(): void {
        if ( empty($_POST['cias_review_nonce']) ) return;
        if ( ! wp_verify_nonce( sanitize_text_field(wp_unslash($_POST['cias_review_nonce'])), self::NONCE_ACTION ) )
            wp_die('Security check failed.');
        if ( ! current_user_can('cias_view_reports') && ! current_user_can('manage_options') )
            wp_die('Permission denied.');

        $review_id      = (int)($_POST['review_id']      ?? 0);
        $submission_id  = (int)($_POST['submission_id']  ?? 0);
        $action         = sanitize_key($_POST['review_action'] ?? '');
        $corrected_text = sanitize_textarea_field($_POST['corrected_text'] ?? '');
        $notes          = sanitize_textarea_field($_POST['teacher_notes'] ?? '');
        $override_score = isset($_POST['override_score']) && $_POST['override_score'] !== '' ? (int)$_POST['override_score'] : null;
        $override_fb    = sanitize_textarea_field($_POST['override_feedback'] ?? '');
        $teacher_id     = get_current_user_id();

        global $wpdb;

        if ( $action === 'approve_evaluate' ) {
            // Teacher confirmed OCR — push to evaluation queue
            $wpdb->update(CIAS_TEACHER_REVIEWS, [
                'status'       => 'reviewed',
                'teacher_id'   => $teacher_id,
                'teacher_notes'=> $notes,
                'reviewed_at'  => current_time('mysql'),
            ], ['id' => $review_id]);

            // Store corrected text in OCR result
            $wpdb->query($wpdb->prepare(
                "UPDATE " . CIAS_OCR_RESULTS . "
                 SET confirmed=1, confirmed_text=%s, confirmed_at=NOW()
                 WHERE submission_id=%d",
                $corrected_text, $submission_id
            ));

            $wpdb->update(CIAS_SUBMISSIONS, ['status'=>'ocr_confirmed', 'updated_at'=>current_time('mysql')], ['id'=>$submission_id]);

            // Get submission details for evaluation job
            $sub = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . CIAS_SUBMISSIONS . " WHERE id=%d", $submission_id));

            CIAS_DB_Phase_B::push_job('evaluate', [
                'submission_id' => $submission_id,
                'user_id'       => $sub->user_id ?? 0,
                'confirmed_text'=> $corrected_text,
                'question_id'   => $sub->question_id ?? null,
                'question_text' => $sub->question_text ?? null,
                'subject_id'    => $sub->subject_id ?? null,
                'topic_id'      => $sub->topic_id ?? null,
            ], priority: 2);

        } elseif ( $action === 'manual_score' ) {
            // Teacher enters manual score (no AI evaluation needed)
            $wpdb->update(CIAS_TEACHER_REVIEWS, [
                'status'           => 'reviewed',
                'teacher_id'       => $teacher_id,
                'teacher_notes'    => $notes,
                'override_score'   => $override_score,
                'override_feedback'=> $override_fb,
                'reviewed_at'      => current_time('mysql'),
            ], ['id' => $review_id]);

            if ( $override_score !== null ) {
                // Insert a teacher-created evaluation row
                $sub = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . CIAS_SUBMISSIONS . " WHERE id=%d", $submission_id));
                $wpdb->insert(CIAS_AI_EVALUATIONS, [
                    'submission_id' => $submission_id,
                    'user_id'       => $sub->user_id ?? 0,
                    'score'         => $override_score,
                    'max_score'     => 100,
                    'feedback_json' => wp_json_encode(['overall' => $override_fb]),
                    'model_used'    => 'teacher_manual',
                    'evaluated_at'  => current_time('mysql'),
                ]);
                $wpdb->update(CIAS_SUBMISSIONS, ['status'=>'evaluated', 'eval_id'=>$wpdb->insert_id, 'updated_at'=>current_time('mysql')], ['id'=>$submission_id]);
            }

        } elseif ( $action === 'escalate' ) {
            $wpdb->update(CIAS_TEACHER_REVIEWS, [
                'status'       => 'escalated',
                'teacher_notes'=> $notes,
            ], ['id' => $review_id]);
        }

        wp_safe_redirect(add_query_arg('reviewed', 1, admin_url('admin.php?page='.self::PAGE_SLUG)));
        exit;
    }

    // ── Page renderer ─────────────────────────────────────────────────────────

    public static function render_page(): void {
        $status_filter = sanitize_key($_GET['status'] ?? 'pending');
        $paged         = max(1, (int)($_GET['paged'] ?? 1));
        $per_page      = 10;
        $offset        = ($paged-1)*$per_page;

        global $wpdb;
        $teacher_id = get_current_user_id();
        $is_admin   = current_user_can('manage_options');

        $where = $is_admin ? "rv.status = %s" : "rv.status = %s AND (rv.teacher_id IS NULL OR rv.teacher_id = %d)";
        $args  = $is_admin ? [$status_filter] : [$status_filter, $teacher_id];

        $total = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . CIAS_TEACHER_REVIEWS . " rv WHERE {$where}", ...$args));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT rv.*, s.r2_key, s.question_text, s.subject_id, s.file_mime,
                    ocr.raw_text, ocr.confidence, ocr.legibility, ocr.confirmed_text,
                    ev.score AS ai_score,
                    u.display_name AS student_name,
                    t.display_name AS teacher_name
             FROM " . CIAS_TEACHER_REVIEWS . " rv
             JOIN " . CIAS_SUBMISSIONS . " s   ON s.id  = rv.submission_id
             LEFT JOIN " . CIAS_OCR_RESULTS . " ocr ON ocr.submission_id = rv.submission_id
             LEFT JOIN " . CIAS_AI_EVALUATIONS . " ev ON ev.submission_id = rv.submission_id
             LEFT JOIN {$wpdb->users} u ON u.ID = rv.user_id
             LEFT JOIN {$wpdb->users} t ON t.ID = rv.teacher_id
             WHERE {$where}
             ORDER BY rv.priority ASC, rv.created_at ASC
             LIMIT %d OFFSET %d",
            ...[...$args, $per_page, $offset]
        ));

        $total_pages = (int)ceil($total/$per_page);
        ?>
        <div class="wrap">
          <h1 class="wp-heading-inline">✍️ Answer Review Queue</h1>
          <hr class="wp-header-end">

          <?php if ( isset($_GET['reviewed']) ) : ?>
          <div class="notice notice-success is-dismissible"><p>Review submitted successfully.</p></div>
          <?php endif; ?>

          <!-- Status filter tabs -->
          <nav class="nav-tab-wrapper" style="margin-bottom:16px">
            <?php foreach (['pending'=>'⏳ Pending','in_review'=>'🔍 In Review','reviewed'=>'✅ Reviewed','escalated'=>'🚨 Escalated'] as $s=>$lbl) : ?>
              <a href="<?php echo esc_url(add_query_arg(['status'=>$s,'paged'=>1])); ?>"
                 class="nav-tab <?php echo $status_filter===$s ? 'nav-tab-active':'' ?>">
                <?php echo esc_html($lbl); ?>
              </a>
            <?php endforeach; ?>
            <span style="float:right;padding:8px 0;color:#6B7280;font-size:13px"><?php echo number_format($total); ?> items</span>
          </nav>

          <?php if (empty($rows)) : ?>
          <div style="background:#F9FAFB;border:1px dashed #E5E7EB;border-radius:8px;padding:40px;text-align:center;color:#9CA3AF">
            <div style="font-size:36px;margin-bottom:8px">✅</div>
            No submissions in this queue. All caught up!
          </div>
          <?php else: foreach ($rows as $row) : ?>
          <div class="cias-review-card" id="review-<?php echo esc_attr($row->id); ?>">
            <div class="cias-rc-header">
              <div>
                <strong><?php echo esc_html($row->student_name); ?></strong>
                <span class="cias-rc-badge"><?php echo esc_html(ucwords(str_replace('_',' ',$row->queue_reason ?? ''))); ?></span>
                <?php if ($row->confidence) printf('<span class="cias-rc-conf">OCR: %d%%</span>', round($row->confidence*100)); ?>
              </div>
              <span style="font-size:12px;color:#9CA3AF"><?php echo esc_html(wp_date('M j, g:i a', strtotime($row->created_at))); ?></span>
            </div>

            <div class="cias-rc-body">
              <!-- Image -->
              <div class="cias-rc-image">
                <img src="<?php echo esc_url(CIAS_R2::public_url_for($row->r2_key)); ?>"
                     alt="Answer submission" loading="lazy" style="max-width:100%;border-radius:6px;border:1px solid #E5E7EB">
              </div>

              <!-- OCR text + review form -->
              <div class="cias-rc-form">
                <?php if ($row->question_text) : ?>
                <div class="cias-rc-question">
                  <strong>Question:</strong> <?php echo esc_html($row->question_text); ?>
                </div>
                <?php endif; ?>

                <form method="post" action="">
                  <?php wp_nonce_field(self::NONCE_ACTION, 'cias_review_nonce'); ?>
                  <input type="hidden" name="review_id"     value="<?php echo esc_attr($row->id); ?>">
                  <input type="hidden" name="submission_id" value="<?php echo esc_attr($row->submission_id); ?>">

                  <label><strong>Extracted Text (edit if needed):</strong></label>
                  <textarea name="corrected_text" rows="8" class="widefat" style="font-family:monospace;font-size:13px"><?php
                    echo esc_textarea($row->confirmed_text ?: $row->raw_text ?: '');
                  ?></textarea>

                  <label style="margin-top:10px;display:block"><strong>Teacher Notes:</strong></label>
                  <textarea name="teacher_notes" rows="2" class="widefat" placeholder="Optional internal notes"></textarea>

                  <div class="cias-rc-score-row">
                    <div>
                      <label><strong>Manual Score (0-100):</strong></label>
                      <input type="number" name="override_score" min="0" max="100" style="width:80px" placeholder="—">
                    </div>
                    <div style="flex:1">
                      <label><strong>Score Feedback:</strong></label>
                      <input type="text" name="override_feedback" class="widefat" placeholder="Brief comment on score">
                    </div>
                  </div>

                  <div class="cias-rc-actions">
                    <button type="submit" name="review_action" value="approve_evaluate" class="button button-primary">
                      ✅ Confirm Text & Evaluate with AI
                    </button>
                    <button type="submit" name="review_action" value="manual_score" class="button button-secondary">
                      📝 Submit Manual Score
                    </button>
                    <button type="submit" name="review_action" value="escalate" class="button"
                            onclick="return confirm('Escalate to senior teacher?')">
                      🚨 Escalate
                    </button>
                  </div>
                </form>
              </div>
            </div>
          </div>
          <?php endforeach; endif; ?>

          <?php if ($total_pages > 1) : ?>
          <div class="tablenav" style="margin-top:16px">
            <div class="tablenav-pages">
              <?php echo paginate_links(['base'=>add_query_arg('paged','%#%'),'format'=>'','current'=>$paged,'total'=>$total_pages]); ?>
            </div>
          </div>
          <?php endif; ?>
        </div>
        <?php
    }

    private static function inline_css(): string {
        return '
        .cias-review-card{background:#fff;border:1px solid #E5E7EB;border-radius:8px;margin-bottom:20px;overflow:hidden}
        .cias-rc-header{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;background:#F9FAFB;border-bottom:1px solid #E5E7EB}
        .cias-rc-badge{background:#FEF3C7;color:#92400E;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;margin-left:8px}
        .cias-rc-conf{background:#DBEAFE;color:#1D4ED8;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;margin-left:6px}
        .cias-rc-body{display:flex;gap:16px;padding:16px}
        .cias-rc-image{flex:0 0 320px}
        .cias-rc-form{flex:1 1 0;min-width:0}
        .cias-rc-question{background:#F5F3FF;border-left:3px solid #6C63FF;padding:8px 12px;margin-bottom:12px;font-size:13px;border-radius:0 4px 4px 0}
        .cias-rc-score-row{display:flex;gap:12px;margin-top:10px;align-items:flex-end}
        .cias-rc-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
        ';
    }
}
