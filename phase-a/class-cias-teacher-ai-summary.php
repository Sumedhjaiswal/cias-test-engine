<?php
/**
 * CIAS Phase A – A7: Teacher Dashboard – AI Activity Summary per Student
 *
 * Adds "🤖 AI Activity" submenu under CIAS Tests (visible to cias_view_reports cap).
 * Shows per-student AI Guru stats for students in the teacher's batches
 * (from cias_teacher_batches → cias_enrollments).
 * Admins see all students.
 *
 * Columns: Student | Access Type | Credits Left | All-time Msgs | This Month | Last Session | Type Breakdown | Actions
 *
 * @package CIAS\PhaseA
 * @since   3.18.0
 */
defined( 'ABSPATH' ) || exit;

class CIAS_Teacher_AI_Summary {

    const PAGE_SLUG = 'cias-ai-activity';
    const PER_PAGE  = 25;

    public static function init(): void {
        add_action( 'admin_menu',            [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue'       ] );
        add_action( 'wp_ajax_cias_ai_activity_detail', [ __CLASS__, 'ajax_detail' ] );
    }

    public static function register_menu(): void {
        add_submenu_page(
            'cias-tests',
            __( 'Student AI Activity', 'cias-test' ),
            __( '🤖 AI Activity', 'cias-test' ),
            'cias_view_reports',
            self::PAGE_SLUG,
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function enqueue( string $hook ): void {
        if ( strpos( $hook, self::PAGE_SLUG ) === false ) return;
        wp_add_inline_style( 'wp-admin', self::inline_css() );
    }

    // ── Get student IDs visible to current user ────────────────────────────────

    private static function get_student_ids(): array {
        global $wpdb;

        if ( current_user_can('manage_options') ) {
            // Admin: all enrolled students
            return $wpdb->get_col(
                "SELECT DISTINCT user_id FROM " . CIAS_ENROLLMENTS . " WHERE status='active'"
            );
        }

        $teacher_id = get_current_user_id();

        // Teacher sees students in their assigned batches
        $batch_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT batch_id FROM " . CIAS_TEACHER_BATCHES . " WHERE teacher_id=%d",
            $teacher_id
        ) );

        if ( empty($batch_ids) ) return [];

        $placeholders = implode(',', array_fill(0, count($batch_ids), '%d'));
        return $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT user_id FROM " . CIAS_ENROLLMENTS . "
             WHERE batch_id IN ($placeholders) AND status='active'",
            ...$batch_ids
        ) );
    }

    // ── Main page ─────────────────────────────────────────────────────────────

    public static function render_page(): void {
        if ( ! current_user_can('cias_view_reports') && ! current_user_can('manage_options') ) {
            wp_die('Access denied.');
        }

        $student_ids = self::get_student_ids();
        $search      = sanitize_text_field( $_GET['s']        ?? '' );
        $sort_by     = sanitize_key(        $_GET['sort_by']  ?? 'display_name' );
        $sort_dir    = strtoupper( sanitize_key( $_GET['sort_dir'] ?? 'ASC' ) ) === 'DESC' ? 'DESC' : 'ASC';
        $paged       = max(1, (int) ($_GET['paged'] ?? 1));
        $offset      = ($paged-1) * self::PER_PAGE;

        global $wpdb;

        if ( empty($student_ids) ) {
            echo '<div class="wrap"><h1>🤖 Student AI Activity</h1>';
            echo '<p>No students found in your batches.</p></div>';
            return;
        }

        $placeholders = implode(',', array_fill(0, count($student_ids), '%d'));
        $msg_table = $wpdb->prefix . 'cias_chat_messages';

        $search_where = '';
        $search_param = [];
        if ($search) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $search_where = "AND (u.display_name LIKE %s OR u.user_email LIKE %s)";
            $search_param = [$like, $like];
        }

        $allowed_sorts = ['display_name','credits_remaining','total_msgs','month_msgs','last_session'];
        if (!in_array($sort_by,$allowed_sorts,true)) $sort_by='display_name';

        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT u.ID) FROM {$wpdb->users} u
             WHERE u.ID IN ($placeholders) $search_where",
            array_merge($student_ids, $search_param)
        ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT u.ID AS user_id, u.display_name, u.user_email,
                COALESCE(ac.credits_remaining,0) AS credits_remaining,
                COALESCE(ac.access_type,'free') AS access_type,
                COUNT(m.id) AS total_msgs,
                SUM(m.created_at >= DATE_FORMAT(NOW(),'%%Y-%%m-01') AND m.role='user') AS month_msgs,
                MAX(m.created_at) AS last_session
             FROM {$wpdb->users} u
             LEFT JOIN {$wpdb->prefix}cias_ai_credits ac ON ac.user_id=u.ID
             LEFT JOIN $msg_table m ON m.user_id=u.ID AND m.role='user'
             WHERE u.ID IN ($placeholders) $search_where
             GROUP BY u.ID
             ORDER BY $sort_by $sort_dir
             LIMIT %d OFFSET %d",
            array_merge($student_ids, $search_param, [self::PER_PAGE, $offset])
        ) );

        $total_pages = (int) ceil($total/self::PER_PAGE);
        $type_labels = CIAS_Message_Classifier::type_labels();
        $type_colors = CIAS_Message_Classifier::type_colors();

        ?>
        <div class="wrap">
          <h1 class="wp-heading-inline">🤖 Student AI Activity</h1>
          <hr class="wp-header-end">

          <!-- Filter bar -->
          <form method="get" class="cias-ai-act-filter">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">
            <input type="hidden" name="sort_by" value="<?php echo esc_attr($sort_by); ?>">
            <input type="hidden" name="sort_dir" value="<?php echo esc_attr($sort_dir); ?>">
            <input type="search" name="s" placeholder="Search students…" value="<?php echo esc_attr($search); ?>" class="regular-text">
            <?php submit_button('Search','secondary','',false); ?>
            <?php if ($search) : ?><a href="<?php echo esc_url(admin_url('admin.php?page='.self::PAGE_SLUG)); ?>" class="button">Clear</a><?php endif; ?>
            <span class="cias-ai-total"><?php echo number_format($total); ?> students</span>
          </form>

          <table class="wp-list-table widefat fixed striped cias-ai-act-table">
            <thead><tr>
              <?php
              $cols = [
                'display_name'      => 'Student',
                'access_type_label' => 'Access',
                'credits_remaining' => 'Credits',
                'total_msgs'        => 'All-time Msgs',
                'month_msgs'        => 'This Month',
                'last_session'      => 'Last Session',
                'type_chart'        => 'Type Breakdown',
                'actions'           => 'Actions',
              ];
              $sortable = ['display_name','credits_remaining','total_msgs','month_msgs','last_session'];
              foreach ($cols as $key => $label) :
                  if (in_array($key,$sortable,true)) :
                      $new_dir = ($sort_by === $key && $sort_dir === 'ASC') ? 'DESC' : 'ASC';
                      $arrow   = $sort_by === $key ? ($sort_dir==='ASC' ? ' ▲' : ' ▼') : '';
                      $url     = add_query_arg(['sort_by'=>$key,'sort_dir'=>$new_dir,'page'=>self::PAGE_SLUG,'s'=>$search], admin_url('admin.php'));
                      echo "<th><a href='".esc_url($url)."' style='text-decoration:none;color:inherit'>".esc_html($label.$arrow)."</a></th>";
                  else :
                      echo "<th>".esc_html($label)."</th>";
                  endif;
              endforeach; ?>
            </tr></thead>
            <tbody>
            <?php if (empty($rows)) : ?>
              <tr><td colspan="8" style="text-align:center;padding:28px;color:#9CA3AF">No AI activity recorded yet.</td></tr>
            <?php else: foreach ($rows as $row) :
                $types = $wpdb->get_results($wpdb->prepare(
                    "SELECT message_type, COUNT(*) AS cnt FROM $msg_table
                     WHERE user_id=%d AND message_type IS NOT NULL
                     GROUP BY message_type ORDER BY cnt DESC LIMIT 5",
                    $row->user_id
                ));
                $total_typed = array_sum(array_column((array)$types,'cnt'));
                $last = $row->last_session ? wp_date('M j, Y g:i a', strtotime($row->last_session)) : '—';

                $acc_cls = match($row->access_type) {
                    'unlimited' => 'background:#D1FAE5;color:#065F46',
                    'paid'      => 'background:#EDE9FE;color:#5B21B6',
                    default     => 'background:#F3F4F6;color:#374151',
                };
                ?>
              <tr>
                <td>
                  <strong><a href="<?php echo esc_url(get_edit_user_link($row->user_id)); ?>"><?php echo esc_html($row->display_name); ?></a></strong>
                  <span style="display:block;font-size:11px;color:#9CA3AF"><?php echo esc_html($row->user_email); ?></span>
                </td>
                <td>
                  <span style="<?php echo esc_attr($acc_cls); ?>;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700">
                    <?php echo esc_html(ucfirst($row->access_type)); ?>
                  </span>
                </td>
                <td class="<?php echo (int)$row->credits_remaining < 5 ? 'cias-low' : ''; ?>">
                  <?php echo esc_html(number_format((int)$row->credits_remaining)); ?>
                </td>
                <td><?php echo esc_html(number_format((int)$row->total_msgs)); ?></td>
                <td><?php echo esc_html(number_format((int)$row->month_msgs)); ?></td>
                <td style="font-size:12px"><?php echo esc_html($last); ?></td>
                <td>
                  <?php if ($total_typed > 0) : ?>
                    <div class="cias-type-bar" title="<?php
                        $tt = [];
                        foreach ($types as $t) {
                            $lbl = $type_labels[$t->message_type] ?? $t->message_type;
                            $tt[] = $lbl . ': ' . $t->cnt;
                        }
                        echo esc_attr(implode(' | ',$tt));
                    ?>">
                      <?php foreach ($types as $t) :
                          $pct   = ($t->cnt / $total_typed) * 100;
                          $color = $type_colors[$t->message_type]['fg'] ?? '#6C63FF';
                          ?>
                        <span style="width:<?php echo round($pct); ?>%;background:<?php echo esc_attr($color); ?>;display:block;height:100%"></span>
                      <?php endforeach; ?>
                    </div>
                    <div class="cias-type-leg">
                      <?php foreach (array_slice((array)$types,0,3) as $t) :
                          $lbl = $type_labels[$t->message_type] ?? ucfirst($t->message_type);
                          $fg  = $type_colors[$t->message_type]['fg'] ?? '#6C63FF';
                          ?>
                        <span><span style="background:<?php echo esc_attr($fg); ?>;width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:3px"></span><?php echo esc_html($lbl); ?></span>
                      <?php endforeach; ?>
                    </div>
                  <?php else: ?>
                    <span style="color:#D1D5DB">—</span>
                  <?php endif; ?>
                </td>
                <td style="white-space:nowrap">
                  <a href="<?php echo esc_url(admin_url('admin.php?page=cias-credit-log&filter_user='.$row->user_id)); ?>" class="button button-small">Credits</a>
                  <a href="<?php echo esc_url(admin_url('admin.php?page=cias-reports&student='.$row->user_id)); ?>" class="button button-small">Reports</a>
                </td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>

          <?php if ($total_pages > 1) : ?>
          <div class="tablenav bottom" style="margin-top:10px"><div class="tablenav-pages">
            <?php echo paginate_links(['base'=>add_query_arg('paged','%#%'),'format'=>'','current'=>$paged,'total'=>$total_pages,'prev_text'=>'&laquo;','next_text'=>'&raquo;']); ?>
          </div></div>
          <?php endif; ?>
        </div>
        <?php
    }

    // ── AJAX detail ───────────────────────────────────────────────────────────

    public static function ajax_detail(): void {
        check_ajax_referer('cias_nonce','nonce');
        if (!current_user_can('cias_view_reports') && !current_user_can('manage_options'))
            wp_send_json_error('Unauthorized',403);

        $user_id = (int)($_POST['user_id'] ?? 0);
        if (!$user_id) wp_send_json_error('Missing user_id',400);

        global $wpdb;
        $table = $wpdb->prefix . 'cias_chat_messages';

        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT role,body,message_type,created_at,media_url FROM $table
             WHERE user_id=%d ORDER BY created_at DESC LIMIT 10", $user_id
        ));
        $daily = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_at) AS day, COUNT(*) AS cnt FROM $table
             WHERE user_id=%d AND role='user' AND created_at >= DATE_SUB(NOW(),INTERVAL 30 DAY)
             GROUP BY day ORDER BY day ASC", $user_id
        ));
        wp_send_json_success(['messages'=>$messages,'daily'=>$daily]);
    }

    private static function inline_css(): string {
        return '
        .cias-ai-act-filter{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:12px 0}
        .cias-ai-total{margin-left:auto;color:#9CA3AF;font-size:12px}
        .cias-ai-act-table .cias-low{color:#DC2626;font-weight:700}
        .cias-type-bar{height:10px;border-radius:5px;overflow:hidden;display:flex;width:110px;margin-bottom:4px}
        .cias-type-leg{display:flex;flex-wrap:wrap;gap:4px;font-size:10px;color:#6B7280}
        .cias-type-leg span{display:flex;align-items:center}
        ';
    }
}
