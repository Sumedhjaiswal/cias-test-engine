<?php
/**
 * CIAS Phase A – A2: Admin Credit Log
 *
 * Adds a "Credit Log" submenu under CIAS Tests.
 * Reads from the existing 'cias_ai_credit_log' table (extended in Phase A DB upgrade
 * with 'balance_after', 'admin_user_id', 'note' columns).
 * Manual credit form calls CIAS_AI_Bot::add_credits_manual() (new static method
 * added to class-cias-ai-bot.php) so all listeners are notified.
 *
 * Source badges: purchase | manual | usage | refund | adjustment
 *
 * @package CIAS\PhaseA
 * @since   3.18.0
 */
defined( 'ABSPATH' ) || exit;

class CIAS_Credit_Log_Admin {

    const PAGE_SLUG    = 'cias-credit-log';
    const NONCE_ACTION = 'cias_phase_a_manual_credit';
    const PER_PAGE     = 30;

    public static function init(): void {
        // Record Phase A log rows on every credit event
        add_action( 'cias_credits_purchased', [ __CLASS__, 'record_purchase' ], 10, 4 );
        add_action( 'cias_credits_adjusted',  [ __CLASS__, 'record_manual'   ], 10, 4 );
        add_action( 'cias_credits_used',      [ __CLASS__, 'record_usage'    ], 10, 3 );

        add_action( 'admin_menu',            [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_init',            [ __CLASS__, 'handle_form'   ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue'       ] );
    }

    // ── Recorders ─────────────────────────────────────────────────────────────
    // These update the 'balance_after' and 'admin_user_id' columns added in
    // the DB upgrade. They update the LAST inserted row for this user+action
    // because CIAS_AI_Bot already inserted the base row.

    public static function record_purchase( int $user_id, int $credits, $order_id = null, string $label = '' ): void {
        global $wpdb;
        $balance = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT credits_remaining FROM {$wpdb->prefix}cias_ai_credits WHERE user_id=%d", $user_id
        ) );
        // Update the last 'purchase' row for this user (just inserted by add_credits)
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}cias_ai_credit_log SET balance_after=%d, note=%s
             WHERE user_id=%d AND action='purchase'
             ORDER BY id DESC LIMIT 1",
            $balance, $label ?: '', $user_id
        ) );
    }

    public static function record_manual( int $user_id, int $delta, string $note = '', int $admin_id = 0 ): void {
        global $wpdb;
        $balance = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT credits_remaining FROM {$wpdb->prefix}cias_ai_credits WHERE user_id=%d", $user_id
        ) );
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}cias_ai_credit_log SET balance_after=%d, admin_user_id=%d
             WHERE user_id=%d AND action='manual'
             ORDER BY id DESC LIMIT 1",
            $balance, $admin_id ?: get_current_user_id(), $user_id
        ) );
    }

    public static function record_usage( int $user_id, int $used, string $session_id = '' ): void {
        global $wpdb;
        $balance = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT credits_remaining FROM {$wpdb->prefix}cias_ai_credits WHERE user_id=%d", $user_id
        ) );
        // Usage rows aren't inserted by add_credits — insert directly
        $wpdb->insert( $wpdb->prefix . 'cias_ai_credit_log', [
            'user_id'      => $user_id,
            'credits'      => -abs( $used ),
            'action'       => 'usage',
            'order_id'     => $session_id,
            'balance_after'=> $balance,
            'created_at'   => current_time( 'mysql' ),
        ] );
    }

    // ── Admin menu ─────────────────────────────────────────────────────────────

    public static function register_menu(): void {
        add_submenu_page(
            'cias-tests',
            __( 'Credit Log', 'cias-test' ),
            __( '💳 Credit Log', 'cias-test' ),
            'manage_options',
            self::PAGE_SLUG,
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function enqueue( string $hook ): void {
        if ( strpos( $hook, self::PAGE_SLUG ) === false ) return;
        wp_add_inline_style( 'wp-admin', self::inline_css() );
    }

    // ── Manual form handler ───────────────────────────────────────────────────

    public static function handle_form(): void {
        if ( empty( $_POST['cias_pa_credit_nonce'] ) ) return;
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cias_pa_credit_nonce'] ) ), self::NONCE_ACTION ) )
            wp_die( 'Security check failed.' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );

        $user_id = (int) ( $_POST['target_user_id'] ?? 0 );
        $delta   = (int) ( $_POST['credit_delta']   ?? 0 );
        $note    = sanitize_textarea_field( $_POST['credit_note'] ?? '' );

        if ( ! $user_id || ! $delta ) {
            wp_safe_redirect( add_query_arg( 'pa_notice', 'invalid', wp_get_referer() ) );
            exit;
        }

        // CIAS_AI_Bot::add_credits_manual fires 'cias_credits_adjusted'
        CIAS_AI_Bot::add_credits_manual( $user_id, $delta, $note, get_current_user_id() );

        wp_safe_redirect( add_query_arg( 'pa_notice', 'saved', self::page_url() ) );
        exit;
    }

    // ── Page renderer ──────────────────────────────────────────────────────────

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;
        global $wpdb;

        $filter_user   = (int)    ( $_GET['filter_user']   ?? 0 );
        $filter_source = sanitize_key( $_GET['filter_source'] ?? '' );
        $paged         = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $offset        = ( $paged - 1 ) * self::PER_PAGE;
        $notice        = sanitize_key( $_GET['pa_notice'] ?? '' );

        $table = $wpdb->prefix . 'cias_ai_credit_log';

        // Build WHERE
        $where  = [];
        $params = [];
        if ( $filter_user )   { $where[] = 'cl.user_id=%d'; $params[] = $filter_user; }
        if ( $filter_source ) { $where[] = 'cl.action=%s';  $params[] = $filter_source; }
        $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = (int) ( $params
            ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table cl $where_sql", ...$params ) )
            : $wpdb->get_var( "SELECT COUNT(*) FROM $table cl $where_sql" ) );

        $rows_sql = "SELECT cl.*, u.display_name, u.user_email, au.display_name AS admin_name,
                            ac.credits_remaining AS current_balance
                     FROM $table cl
                     LEFT JOIN {$wpdb->users} u  ON u.ID  = cl.user_id
                     LEFT JOIN {$wpdb->users} au ON au.ID = cl.admin_user_id
                     LEFT JOIN {$wpdb->prefix}cias_ai_credits ac ON ac.user_id = cl.user_id
                     $where_sql
                     ORDER BY cl.id DESC
                     LIMIT %d OFFSET %d";
        $query_args = array_merge( $params, [ self::PER_PAGE, $offset ] );
        $rows = $wpdb->get_results( $wpdb->prepare( $rows_sql, ...$query_args ) );

        $total_pages = (int) ceil( $total / self::PER_PAGE );

        // User list for filter
        $users = $wpdb->get_results(
            "SELECT DISTINCT cl.user_id, u.display_name
             FROM $table cl LEFT JOIN {$wpdb->users} u ON u.ID=cl.user_id
             ORDER BY u.display_name ASC LIMIT 300"
        );

        $sources = [ 'purchase'=>'Purchased', 'manual'=>'Manual', 'usage'=>'Usage', 'refund'=>'Refund', 'adjustment'=>'Adjustment' ];
        ?>
        <div class="wrap cias-cl-wrap">
          <h1 class="wp-heading-inline">💳 AI Guru Credit Log</h1>
          <hr class="wp-header-end">

          <?php if ( $notice === 'saved' ) : ?>
            <div class="notice notice-success is-dismissible"><p>Credits updated.</p></div>
          <?php elseif ( $notice === 'invalid' ) : ?>
            <div class="notice notice-error is-dismissible"><p>Invalid input.</p></div>
          <?php endif; ?>

          <div class="cias-cl-layout">

            <!-- Sidebar: manual credit form -->
            <div class="cias-cl-sidebar">
              <div class="cias-cl-box">
                <h3>⚙️ Manually Adjust Credits</h3>
                <form method="post" action="">
                  <?php wp_nonce_field( self::NONCE_ACTION, 'cias_pa_credit_nonce' ); ?>
                  <p>
                    <label>Student</label>
                    <?php wp_dropdown_users([
                        'name'             => 'target_user_id',
                        'show_option_none' => '— Select student —',
                        'role__in'         => ['subscriber', 'student', 'cias_teacher'],
                        'orderby'          => 'display_name',
                        'selected'         => 0,
                    ]); ?>
                  </p>
                  <p>
                    <label>Amount <span style="color:#9CA3AF">(− to deduct)</span></label>
                    <input type="number" name="credit_delta" step="1" class="small-text" required>
                  </p>
                  <p>
                    <label>Note (optional)</label>
                    <textarea name="credit_note" rows="2" class="widefat"></textarea>
                  </p>
                  <?php submit_button('Apply Credits', 'primary', 'submit', false); ?>
                </form>
              </div>

              <!-- Quick stats -->
              <?php
              $total_purchased = (int) $wpdb->get_var("SELECT SUM(credits) FROM $table WHERE action='purchase' AND credits>0");
              $total_used      = (int) $wpdb->get_var("SELECT ABS(SUM(credits)) FROM $table WHERE action='usage'");
              $total_manual    = (int) $wpdb->get_var("SELECT SUM(credits) FROM $table WHERE action='manual'");
              ?>
              <div class="cias-cl-box cias-cl-stats">
                <h3>📊 All-time Stats</h3>
                <div class="cias-cl-stat"><span><?php echo number_format($total_purchased); ?></span>Purchased</div>
                <div class="cias-cl-stat"><span><?php echo number_format($total_used); ?></span>Used</div>
                <div class="cias-cl-stat"><span><?php echo number_format(abs($total_manual)); ?></span>Manual adj.</div>
              </div>
            </div>

            <!-- Main table -->
            <div class="cias-cl-main">
              <form method="get" class="cias-cl-filter">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">
                <select name="filter_source">
                  <option value="">All sources</option>
                  <?php foreach ($sources as $k => $v) : ?>
                    <option value="<?php echo esc_attr($k); ?>" <?php selected($filter_source,$k); ?>><?php echo esc_html($v); ?></option>
                  <?php endforeach; ?>
                </select>
                <select name="filter_user">
                  <option value="">All students</option>
                  <?php foreach ($users as $u) : ?>
                    <option value="<?php echo esc_attr($u->user_id); ?>" <?php selected($filter_user,$u->user_id); ?>><?php echo esc_html($u->display_name); ?></option>
                  <?php endforeach; ?>
                </select>
                <?php submit_button('Filter','secondary','',false); ?>
                <a href="<?php echo esc_url(self::page_url()); ?>" class="button">Reset</a>
                <span class="cias-cl-total"><?php echo number_format($total); ?> rows</span>
              </form>

              <table class="wp-list-table widefat fixed striped cias-cl-table">
                <thead>
                  <tr>
                    <th style="width:140px">Date</th>
                    <th>Student</th>
                    <th style="width:100px">Source</th>
                    <th style="width:80px;text-align:right">Change</th>
                    <th style="width:100px;text-align:right">Balance after</th>
                    <th style="width:120px">Reference</th>
                    <th>Note / By</th>
                  </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)) : ?>
                  <tr><td colspan="7" style="text-align:center;padding:28px;color:#9CA3AF;">No transactions yet.</td></tr>
                <?php else: foreach ($rows as $row) :
                    $delta_int = (int) $row->credits;
                    $delta_str = ($delta_int >= 0 ? '+' : '') . number_format($delta_int);
                    $delta_cls = $delta_int >= 0 ? 'cias-cl-pos' : 'cias-cl-neg';
                    $badge_cls = 'cias-cl-badge-' . ($row->action ?: 'other');
                    $badge_lbl = $sources[$row->action] ?? ucfirst($row->action);
                    $when      = wp_date( get_option('date_format') . ' ' . get_option('time_format'), strtotime($row->created_at) );
                    ?>
                  <tr>
                    <td style="font-size:12px"><?php echo esc_html($when); ?></td>
                    <td>
                      <a href="<?php echo esc_url(get_edit_user_link($row->user_id)); ?>"><?php echo esc_html($row->display_name ?: "User #{$row->user_id}"); ?></a>
                      <span class="cias-cl-email"><?php echo esc_html($row->user_email); ?></span>
                    </td>
                    <td><span class="cias-cl-badge <?php echo esc_attr($badge_cls); ?>"><?php echo esc_html($badge_lbl); ?></span></td>
                    <td class="<?php echo esc_attr($delta_cls); ?>" style="text-align:right;font-weight:700"><?php echo esc_html($delta_str); ?></td>
                    <td style="text-align:right"><?php echo $row->balance_after !== null ? esc_html(number_format((int)$row->balance_after)) : '<span style="color:#D1D5DB">—</span>'; ?></td>
                    <td style="font-size:12px"><?php echo esc_html($row->order_id ?: '—'); ?></td>
                    <td>
                      <?php if ($row->note) echo '<span class="cias-cl-note">' . esc_html($row->note) . '</span>'; ?>
                      <?php if ($row->admin_name) echo '<span class="cias-cl-by">by ' . esc_html($row->admin_name) . '</span>'; ?>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
                </tbody>
              </table>

              <?php if ($total_pages > 1) : ?>
                <div class="tablenav bottom"><div class="tablenav-pages">
                  <?php echo paginate_links(['base'=>add_query_arg('paged','%#%'),'format'=>'','current'=>$paged,'total'=>$total_pages,'prev_text'=>'&laquo;','next_text'=>'&raquo;']); ?>
                </div></div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php
    }

    private static function page_url(): string {
        return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
    }

    private static function inline_css(): string {
        return '
        .cias-cl-wrap { max-width:1200px; }
        .cias-cl-layout { display:flex;gap:20px;margin-top:16px;align-items:flex-start; }
        .cias-cl-sidebar { flex:0 0 260px; }
        .cias-cl-main { flex:1 1 0;min-width:0; }
        .cias-cl-box { background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px;margin-bottom:16px; }
        .cias-cl-box h3 { margin:0 0 12px;font-size:13px; }
        .cias-cl-box label { display:block;font-weight:600;margin-bottom:4px;font-size:13px; }
        .cias-cl-box p { margin-bottom:10px; }
        .cias-cl-stats .cias-cl-stat { display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #F3F4F6;font-size:13px; }
        .cias-cl-stats .cias-cl-stat span { font-weight:700;color:#6C63FF; }
        .cias-cl-filter { display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px; }
        .cias-cl-total { margin-left:auto;color:#9CA3AF;font-size:12px; }
        .cias-cl-email,.cias-cl-by { display:block;font-size:11px;color:#9CA3AF; }
        .cias-cl-note { display:block;font-size:12px;color:#374151; }
        .cias-cl-pos { color:#16A34A; }
        .cias-cl-neg { color:#DC2626; }
        .cias-cl-badge { display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600; }
        .cias-cl-badge-purchase { background:#EDE9FE;color:#5B21B6; }
        .cias-cl-badge-manual { background:#FEF9C3;color:#854D0E; }
        .cias-cl-badge-usage { background:#F3F4F6;color:#374151; }
        .cias-cl-badge-refund { background:#DCFCE7;color:#166534; }
        .cias-cl-badge-adjustment { background:#FEE2E2;color:#991B1B; }
        ';
    }
}
