<?php
/**
 * CIAS Phase A – A3: AI Guru Entry Card on Student Profile
 *
 * Renders in:
 *   1. WP admin user-edit screen
 *   2. Front-end via shortcode [cias_ai_guru_card] or do_action('cias_student_profile_sections', $uid)
 *
 * Reads credit balance from cias_ai_credits.credits_remaining
 * (not user meta — matches how CIAS_AI_Bot stores credits).
 *
 * @package CIAS\PhaseA
 * @since   3.18.0
 */
defined( 'ABSPATH' ) || exit;

class CIAS_Student_Profile_Card {

    public static function init(): void {
        add_action( 'show_user_profile', [ __CLASS__, 'render_admin_card' ] );
        add_action( 'edit_user_profile', [ __CLASS__, 'render_admin_card' ] );
        add_action( 'cias_student_profile_sections', [ __CLASS__, 'render_frontend_card' ], 20 );
        add_shortcode( 'cias_ai_guru_card', [ __CLASS__, 'shortcode' ] );
        add_action( 'wp_enqueue_scripts',    [ __CLASS__, 'enqueue_frontend' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin'   ] );
    }

    // ── Data ──────────────────────────────────────────────────────────────────

    public static function get_stats( int $user_id ): array {
        global $wpdb;

        // Credits from cias_ai_credits table
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT credits_remaining, access_type FROM {$wpdb->prefix}cias_ai_credits WHERE user_id=%d",
            $user_id
        ) );
        $credits      = $row ? (int) $row->credits_remaining : 0;
        $access_type  = $row ? $row->access_type : 'free';

        // All-time credits spent from log
        $credits_used = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT ABS(SUM(credits)) FROM {$wpdb->prefix}cias_ai_credit_log
             WHERE user_id=%d AND action='usage'",
            $user_id
        ) );

        $msg_table = $wpdb->prefix . 'cias_chat_messages';

        $total_msgs = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $msg_table WHERE user_id=%d AND role='user'", $user_id
        ) );
        $month_msgs = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $msg_table WHERE user_id=%d AND role='user' AND created_at >= DATE_FORMAT(NOW(),'%%Y-%%m-01')",
            $user_id
        ) );
        $last_session = $wpdb->get_var( $wpdb->prepare(
            "SELECT created_at FROM $msg_table WHERE user_id=%d ORDER BY created_at DESC LIMIT 1",
            $user_id
        ) );
        $top_types = $wpdb->get_results( $wpdb->prepare(
            "SELECT message_type, COUNT(*) AS cnt FROM $msg_table
             WHERE user_id=%d AND message_type IS NOT NULL
             GROUP BY message_type ORDER BY cnt DESC LIMIT 3",
            $user_id
        ) );

        return compact('credits','credits_used','access_type','total_msgs','month_msgs','last_session','top_types');
    }

    // ── Admin card ─────────────────────────────────────────────────────────────

    public static function render_admin_card( WP_User $user ): void {
        if ( ! current_user_can('manage_options') && get_current_user_id() !== $user->ID ) return;
        $s = self::get_stats( $user->ID );
        $log_url  = admin_url( 'admin.php?page=cias-credit-log&filter_user=' . $user->ID );
        $chat_url = admin_url( 'admin.php?page=cias-chat-history&filter_user=' . $user->ID );
        ?>
        <h2>🧠 AI Guru Activity</h2>
        <table class="form-table cias-guru-card-table">
          <tr><td><?php self::render_stats_html( $s, $log_url, $chat_url ); ?></td></tr>
        </table>
        <?php
    }

    // ── Front-end card ─────────────────────────────────────────────────────────

    public static function render_frontend_card( int $user_id = 0 ): void {
        if ( ! $user_id ) $user_id = get_current_user_id();
        if ( ! $user_id ) return;
        $s = self::get_stats( $user_id );
        $log_url  = apply_filters( 'cias_frontend_credit_log_url', home_url('/'), $user_id );
        $chat_url = apply_filters( 'cias_frontend_chat_history_url', home_url('/'), $user_id );
        echo '<div class="cias-guru-profile-card">';
        echo '<h3 class="cias-guru-card-title">🧠 AI Guru Activity</h3>';
        self::render_stats_html( $s, $log_url, $chat_url, true );
        echo '</div>';
    }

    public static function shortcode(): string {
        ob_start();
        self::render_frontend_card( get_current_user_id() );
        return ob_get_clean();
    }

    // ── Shared stats HTML ──────────────────────────────────────────────────────

    private static function render_stats_html( array $s, string $log_url, string $chat_url, bool $frontend = false ): void {
        $last   = $s['last_session'] ? wp_date( get_option('date_format') . ' ' . get_option('time_format'), strtotime($s['last_session']) ) : 'Never';
        $labels = CIAS_Message_Classifier::type_labels();
        $colors = CIAS_Message_Classifier::type_colors();
        $acc_badge = $s['access_type'] === 'unlimited'
            ? '<span style="background:#D1FAE5;color:#065F46;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700">UNLIMITED</span>'
            : '<span style="background:#EDE9FE;color:#5B21B6;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700">' . ucfirst($s['access_type']) . '</span>';
        ?>
        <div class="cias-guru-stats-grid">
          <div class="cias-gs-box">
            <span class="cias-gs-val"><?php echo esc_html(number_format($s['credits'])); ?></span>
            <span class="cias-gs-lbl">Credits remaining <?php echo $acc_badge; ?></span>
          </div>
          <div class="cias-gs-box">
            <span class="cias-gs-val"><?php echo esc_html(number_format($s['credits_used'])); ?></span>
            <span class="cias-gs-lbl">Credits used (all-time)</span>
          </div>
          <div class="cias-gs-box">
            <span class="cias-gs-val"><?php echo esc_html(number_format($s['total_msgs'])); ?></span>
            <span class="cias-gs-lbl">Messages all-time</span>
          </div>
          <div class="cias-gs-box">
            <span class="cias-gs-val"><?php echo esc_html(number_format($s['month_msgs'])); ?></span>
            <span class="cias-gs-lbl">Messages this month</span>
          </div>
        </div>

        <p class="cias-gs-last"><strong>Last session:</strong> <?php echo esc_html($last); ?></p>

        <?php if ( ! empty($s['top_types']) ) : ?>
        <div class="cias-gs-types">
          <strong>Top message types:</strong>
          <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px;">
            <?php foreach ($s['top_types'] as $t) :
                $lbl = $labels[$t->message_type] ?? ucfirst(str_replace('_',' ',$t->message_type));
                $fg  = $colors[$t->message_type]['fg'] ?? '#374151';
                $bg  = $colors[$t->message_type]['bg'] ?? '#F3F4F6';
                ?>
              <span style="background:<?php echo esc_attr($bg); ?>;color:<?php echo esc_attr($fg); ?>;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600;">
                <?php echo esc_html($lbl); ?> <span style="opacity:.7"><?php echo esc_html($t->cnt); ?></span>
              </span>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <div style="display:flex;gap:14px;margin-top:14px;flex-wrap:wrap;">
          <a href="<?php echo esc_url($log_url); ?>" style="font-size:13px;color:#6C63FF;font-weight:600;text-decoration:none;">→ Credit History</a>
          <a href="<?php echo esc_url($chat_url); ?>" style="font-size:13px;color:#6C63FF;font-weight:600;text-decoration:none;">→ Chat History</a>
        </div>
        <?php
    }

    // ── Styles ─────────────────────────────────────────────────────────────────

    public static function enqueue_admin(): void {
        wp_add_inline_style('wp-admin', self::card_css());
    }
    public static function enqueue_frontend(): void {
        // Piggyback on CIAS frontend stylesheet if present
        $handle = wp_style_is('cias-style','registered') ? 'cias-style' : 'wp-block-library';
        wp_add_inline_style($handle, self::card_css());
    }
    private static function card_css(): string {
        return '
        .cias-guru-profile-card,.cias-guru-card-table td{padding:0}
        .cias-guru-card-title{font-size:16px;margin-bottom:14px}
        .cias-guru-stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin:12px 0}
        .cias-gs-box{background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px;padding:12px;text-align:center}
        .cias-gs-val{display:block;font-size:24px;font-weight:800;color:#6C63FF}
        .cias-gs-lbl{display:block;font-size:11px;color:#6B7280;margin-top:4px;text-transform:uppercase;letter-spacing:.04em}
        .cias-gs-last{font-size:13px;color:#374151;margin:10px 0}
        .cias-gs-types{margin:10px 0}
        ';
    }
}
