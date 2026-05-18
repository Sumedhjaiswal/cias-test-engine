<?php
/**
 * CIAS Phase A – A1: Purchase Confirmation Email
 *
 * Hooks into 'cias_credits_purchased' (fired by CIAS_AI_Bot::add_credits()).
 * Sends a branded HTML email to the student with their new balance.
 *
 * @package CIAS\PhaseA
 * @since   3.18.0
 */
defined( 'ABSPATH' ) || exit;

class CIAS_Credit_Email {

    public static function init(): void {
        add_action( 'cias_credits_purchased', [ __CLASS__, 'on_credits_purchased' ], 10, 4 );
        add_filter( 'wp_mail_from',      [ __CLASS__, 'mail_from' ] );
        add_filter( 'wp_mail_from_name', [ __CLASS__, 'mail_from_name' ] );
    }

    // ── Triggered by CIAS_AI_Bot::add_credits() ───────────────────────────────

    public static function on_credits_purchased(
        int    $user_id,
        int    $credits_added,
               $order_id      = null,
        string $package_label = ''
    ): void {
        $user = get_userdata( $user_id );
        if ( ! $user || ! $user->user_email ) return;

        // Read new balance from actual credits table
        global $wpdb;
        $new_balance = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT credits_remaining FROM {$wpdb->prefix}cias_ai_credits WHERE user_id = %d",
            $user_id
        ) );

        // Derive package label from known packs if not supplied
        if ( ! $package_label ) {
            $packs = [ 50 => '50 Credits Pack', 120 => '120 Credits Pack' ];
            $package_label = $packs[ $credits_added ] ?? "{$credits_added} Credits Pack";
        }

        $site_name = get_bloginfo( 'name' );
        $subject   = sprintf(
            '[%s] ✅ %d AI Guru Credits added to your account',
            $site_name,
            $credits_added
        );

        $body = self::build_email_html( [
            'user'          => $user,
            'credits_added' => $credits_added,
            'new_balance'   => $new_balance,
            'order_id'      => $order_id,
            'package_label' => $package_label,
            'site_name'     => $site_name,
        ] );

        add_filter( 'wp_mail_content_type', [ __CLASS__, 'set_html' ] );
        wp_mail( $user->user_email, $subject, $body );
        remove_filter( 'wp_mail_content_type', [ __CLASS__, 'set_html' ] );

        do_action( 'cias_credit_email_sent', $user_id, $credits_added, $order_id );
    }

    // ── Email builder ─────────────────────────────────────────────────────────

    private static function build_email_html( array $d ): string {
        $accent     = apply_filters( 'cias_email_accent_color', '#6C63FF' );
        $first_name = $d['user']->first_name ?: $d['user']->display_name;
        $order_line = $d['order_id']
            ? '<p style="color:#6B7280;font-size:13px;">Order ID: <strong>' . esc_html( $d['order_id'] ) . '</strong></p>'
            : '';
        $dashboard_url = apply_filters( 'cias_student_dashboard_url', home_url( '/' ) );

        ob_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#F3F4F6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#F3F4F6;padding:40px 0;">
<tr><td align="center">
<table width="560" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.08);">

  <!-- Header -->
  <tr>
    <td style="background:<?php echo esc_attr($accent); ?>;padding:28px 40px;text-align:center;">
      <div style="font-size:36px;margin-bottom:8px;">🧠</div>
      <h1 style="margin:0;color:#fff;font-size:22px;font-weight:700;">Credits Added Successfully!</h1>
      <p style="margin:6px 0 0;color:rgba(255,255,255,.85);font-size:14px;">CIAS AI Guru</p>
    </td>
  </tr>

  <!-- Body -->
  <tr>
    <td style="padding:32px 40px;">
      <p style="color:#111827;font-size:16px;margin-top:0;">Hi <?php echo esc_html($first_name); ?> 👋</p>
      <p style="color:#374151;font-size:15px;line-height:1.6;">
        Your purchase was successful! AI Guru credits have been added and are ready to use right now.
      </p>

      <!-- Credit box -->
      <table width="100%" cellpadding="0" cellspacing="0"
             style="background:#F5F3FF;border:2px solid #DDD6FE;border-radius:10px;margin:20px 0;">
        <tr>
          <td style="padding:20px 24px;">
            <table width="100%">
              <tr>
                <td style="color:#6B7280;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;">Package</td>
                <td align="right" style="color:#374151;font-size:14px;font-weight:600;"><?php echo esc_html($d['package_label']); ?></td>
              </tr>
              <tr>
                <td colspan="2" style="padding:8px 0;"><hr style="border:none;border-top:1px solid #DDD6FE;margin:0;"></td>
              </tr>
              <tr>
                <td style="color:#6B7280;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;">Credits added</td>
                <td align="right" style="color:<?php echo esc_attr($accent); ?>;font-size:28px;font-weight:800;">+<?php echo esc_html(number_format($d['credits_added'])); ?></td>
              </tr>
              <tr>
                <td colspan="2" style="padding:8px 0;"><hr style="border:none;border-top:1px solid #DDD6FE;margin:0;"></td>
              </tr>
              <tr>
                <td style="color:#6B7280;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;">New balance</td>
                <td align="right" style="color:#111827;font-size:20px;font-weight:700;"><?php echo esc_html(number_format($d['new_balance'])); ?> credits</td>
              </tr>
            </table>
          </td>
        </tr>
      </table>

      <?php echo $order_line; ?>

      <p style="color:#374151;font-size:15px;line-height:1.6;">
        Head to your dashboard and start asking the AI Guru — it knows your full performance history and will guide you with personalised UPSC strategy. 🎯
      </p>

      <p style="text-align:center;margin:28px 0 0;">
        <a href="<?php echo esc_url($dashboard_url); ?>"
           style="display:inline-block;background:<?php echo esc_attr($accent); ?>;color:#fff;text-decoration:none;font-weight:700;font-size:15px;padding:14px 36px;border-radius:8px;">
          Open AI Guru →
        </a>
      </p>
    </td>
  </tr>

  <!-- Footer -->
  <tr>
    <td style="background:#F9FAFB;border-top:1px solid #E5E7EB;padding:18px 40px;text-align:center;">
      <p style="color:#9CA3AF;font-size:12px;margin:0;">
        © <?php echo esc_html(gmdate('Y')); ?> <?php echo esc_html($d['site_name']); ?> · Automated message, please do not reply.
      </p>
    </td>
  </tr>

</table>
</td></tr>
</table>
</body>
</html>
        <?php
        return ob_get_clean();
    }

    public static function set_html(): string      { return 'text/html'; }
    public static function mail_from( string $e ): string { return get_option( 'cias_email_from_address' ) ?: $e; }
    public static function mail_from_name( string $n ): string { return get_option( 'cias_email_from_name' ) ?: get_bloginfo('name'); }
}
