<?php
/**
 * CIAS Phase C – Frontend Controller
 *
 * Registers [cias_app] shortcode, enqueues assets, and registers
 * AJAX handlers for actions not yet covered by Phase B REST endpoints.
 *
 * @package CIAS\PhaseC
 * @since   3.19.0
 */
defined( 'ABSPATH' ) || exit;

class CIAS_Frontend {

    public static function init(): void {
        add_shortcode( 'cias_app', [ __CLASS__, 'render_app' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );

        // AJAX: vocabulary card rating
        add_action( 'wp_ajax_cias_vocab_rate', [ __CLASS__, 'ajax_vocab_rate' ] );

        // AJAX: sync writing score after REST evaluation completes
        add_action( 'wp_ajax_cias_job_poll', [ __CLASS__, 'ajax_job_poll' ] );

        // AJAX: direct guru chat fallback (when REST job path fails)
        // wp_ajax_cias_guru_direct removed — sync AI calls not permitted (ARCHITECTURE.md)

        // Redirect non-logged-in users from app page
        add_action( 'template_redirect', [ __CLASS__, 'maybe_redirect_login' ] );

        // ── App-page: hide WP admin bar + make viewport full-screen ──────────
        add_action( 'wp', [ __CLASS__, 'maybe_app_mode' ] );
    }

    /**
     * On the app page: remove the WP admin bar so the app fills the viewport.
     */
    public static function maybe_app_mode(): void {
        if ( ! self::is_app_page() ) return;

        // Remove admin bar for ALL users on the app page
        add_filter( 'show_admin_bar', '__return_false' );
        remove_action( 'wp_head', '_admin_bar_bump_cb' );

        // Inject full-screen viewport meta + body class early
        add_action( 'wp_head', function() {
            echo '<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">' . "\n";
            echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
            echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
            echo '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">' . "\n";
            echo '<meta name="theme-color" content="#1a1560">' . "\n";
        }, 1 );
    }

    // ── Shortcode ─────────────────────────────────────────────────────────────

    public static function render_app( array $atts ): string {
        $atts = shortcode_atts( [ 'tab' => 'home' ], $atts, 'cias_app' );

        // Must be logged in
        if ( ! is_user_logged_in() ) {
            ob_start();
            ?>
            <?php echo CIAS_Frontend::render_auth_page(); ?>
            <?php
            return ob_get_clean();
        }

        // Suppress default WP theme styles that fight the app layout
        add_filter( 'body_class', function( $classes ) {
            $classes[] = 'cias-app-page';
            return $classes;
        } );

        // Load bootstrap data
        $user_id   = get_current_user_id();
        $boot_data = CIAS_App_Data::bootstrap( $user_id );
        $boot_json = wp_json_encode( $boot_data );

        ob_start();
        ?>
        <script>var ciasApp = <?php echo $boot_json; /* already escaped via wp_json_encode */ ?>;</script>
        <?php
        include CIAS_PHASE_C_DIR . 'templates/app.php';
        return ob_get_clean();
    }

    // ── Asset enqueue ─────────────────────────────────────────────────────────

    public static function enqueue_assets(): void {
        if ( ! self::is_app_page() ) return;

        // Tabler icons
        wp_enqueue_style(
            'tabler-icons',
            'https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.19.0/tabler-icons.min.css',
            [],
            '3.19.0'
        );

        wp_enqueue_style(
            'cias-app',
            CIAS_PHASE_C_URL . 'assets/css/cias-app.css',
            [ 'tabler-icons' ],
            CIAS_PHASE_C_VERSION
        );

        // core/api.js — centralized REST client. Must load FIRST.
        // Provides: CIAS_API.restGet, restPost, ajaxPost
        // Handles: safe JSON parsing, nonce separation, timeouts, auth failure
        wp_enqueue_script(
            'cias-api',
            CIAS_PHASE_C_URL . 'assets/js/core/api.js',
            [],
            CIAS_PHASE_C_VERSION,
            true
        );

        // chat.js — AI Guru module. Depends on CIAS_API.
        wp_enqueue_script(
            'cias-chat',
            CIAS_PHASE_C_URL . 'assets/js/chat.js',
            [ 'cias-api' ],
            CIAS_PHASE_C_VERSION,
            true
        );

        // cias-app.js — main app. Depends on both CIAS_API and CIASChat.
        wp_enqueue_script(
            'cias-app',
            CIAS_PHASE_C_URL . 'assets/js/cias-app.js',
            [ 'cias-api', 'cias-chat' ],
            CIAS_PHASE_C_VERSION,
            true
        );
    }

    private static function is_app_page(): bool {
        global $post;
        return is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'cias_app' );
    }

    public static function maybe_redirect_login(): void {
        if ( self::is_app_page() && ! is_user_logged_in() ) {
            // Let shortcode handle the login message — no hard redirect
        }
    }

    // ── AJAX: Vocabulary card rating ──────────────────────────────────────────

    public static function ajax_vocab_rate(): void {
        check_ajax_referer( 'cias_app_nonce', 'nonce' );
        $user_id = get_current_user_id();
        $word_id = (int) ( $_POST['word_id'] ?? 0 );
        $rating  = sanitize_key( $_POST['rating'] ?? 'good' ); // hard | good | easy

        if ( ! $user_id || ! $word_id ) {
            wp_send_json_error( 'Invalid params', 400 );
        }

        // SM-2 algorithm: update ease factor and next review date
        global $wpdb;
        $table = $wpdb->prefix . 'cias_user_progress';

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d AND word_id = %d", $user_id, $word_id
        ) );

        $quality    = match( $rating ) { 'easy' => 5, 'good' => 3, 'hard' => 1, default => 3 };
        $ease       = $row ? (float) $row->ease_factor : 2.5;
        $repetitions= $row ? (int) $row->repetitions : 0;
        $interval   = $row ? (int) $row->interval_days : 1;

        if ( $quality >= 3 ) {
            $interval = match( $repetitions ) {
                0 => 1, 1 => 6, default => (int) round( $interval * $ease )
            };
            $repetitions++;
        } else {
            $repetitions = 0;
            $interval    = 1;
        }

        $new_ease  = max( 1.3, $ease + 0.1 - ( 5 - $quality ) * ( 0.08 + ( 5 - $quality ) * 0.02 ) );
        $next_date = gmdate( 'Y-m-d H:i:s', time() + $interval * DAY_IN_SECONDS );
        $mastered  = $new_ease >= 2.5 && $repetitions >= 4 ? 1 : 0;

        $wpdb->replace( $table, [
            'user_id'      => $user_id,
            'word_id'      => $word_id,
            'ease_factor'  => round( $new_ease, 2 ),
            'repetitions'  => $repetitions,
            'interval_days'=> $interval,
            'next_review'  => $next_date,
            'mastered'     => $mastered,
            'updated_at'   => current_time('mysql'),
        ] );

        wp_send_json_success( [
            'word_id'      => $word_id,
            'next_review'  => $next_date,
            'ease_factor'  => round( $new_ease, 2 ),
            'mastered'     => (bool) $mastered,
        ] );
    }

    // ajax_guru_direct removed — sync AI calls are not permitted.
    // All Guru chat goes through: POST /guru/chat (REST) → job queue → worker → poll
    // See ARCHITECTURE.md: "NEVER wait synchronously for Claude/OpenAI responses"


    // ── AJAX: Job status poll (wraps Phase B REST for non-REST clients) ───────

    public static function ajax_job_poll(): void {
        check_ajax_referer( 'cias_app_nonce', 'nonce' );
        $job_id = (int) ( $_POST['job_id'] ?? 0 );
        if ( ! $job_id ) { wp_send_json_error( 'Missing job_id', 400 ); }

        global $wpdb;
        $job = $wpdb->get_row( $wpdb->prepare(
            "SELECT status, result_json, type, finished_at FROM " . CIAS_JOB_QUEUE . " WHERE id = %d", $job_id
        ) );

        if ( ! $job ) { wp_send_json_error( 'Job not found', 404 ); }

        wp_send_json_success( [
            'status'      => $job->status,
            'type'        => $job->type,
            'result'      => $job->result_json ? json_decode( $job->result_json, true ) : null,
            'finished_at' => $job->finished_at,
        ] );
    }

    // ── Custom Auth Page (Login / Register / Forgot Password) ────────────────

    public static function render_auth_page(): string {
        // Handle form submissions
        $msg   = '';
        $error = false;
        $mode  = sanitize_key( $_GET['auth'] ?? 'login' );

        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['cias_auth_action'] ) ) {
            $action = sanitize_key( $_POST['cias_auth_action'] );

            if ( $action === 'login' ) {
                check_admin_referer( 'cias_login' );
                $creds = [
                    'user_login'    => sanitize_user( $_POST['username'] ?? '' ),
                    'user_password' => $_POST['password'] ?? '',
                    'remember'      => ! empty( $_POST['remember'] ),
                ];
                $user = wp_signon( $creds, is_ssl() );
                if ( is_wp_error( $user ) ) {
                    $msg   = 'Incorrect username or password. Please try again.';
                    $error = true;
                } else {
                    wp_redirect( get_permalink() );
                    exit;
                }
            }

            if ( $action === 'register' ) {
                check_admin_referer( 'cias_register' );
                $email    = sanitize_email( $_POST['email'] ?? '' );
                $username = sanitize_user( $_POST['username'] ?? '' );
                $password = $_POST['password'] ?? '';
                $fname    = sanitize_text_field( $_POST['firstname'] ?? '' );

                if ( ! is_email( $email ) )          { $msg = 'Please enter a valid email address.'; $error = true; }
                elseif ( strlen( $password ) < 8 )   { $msg = 'Password must be at least 8 characters.'; $error = true; }
                elseif ( username_exists( $username ) || email_exists( $email ) )
                                                       { $msg = 'An account with this username or email already exists.'; $error = true; }
                else {
                    $uid = wp_create_user( $username, $password, $email );
                    if ( is_wp_error( $uid ) ) {
                        $msg = $uid->get_error_message(); $error = true;
                    } else {
                        if ( $fname ) wp_update_user( [ 'ID' => $uid, 'first_name' => $fname, 'display_name' => $fname ] );
                        // Give 5 free credits on signup
                        global $wpdb;
                        $wpdb->insert( $wpdb->prefix . 'cias_ai_credits', [
                            'user_id'           => $uid,
                            'credits_remaining' => 5,
                            'access_type'       => 'free',
                            'created_at'        => current_time('mysql'),
                            'updated_at'        => current_time('mysql'),
                        ] );
                        // Auto-login
                        wp_set_auth_cookie( $uid, false );
                        wp_set_current_user( $uid );
                        wp_redirect( get_permalink() );
                        exit;
                    }
                }
                $mode = 'register';
            }

            if ( $action === 'forgot' ) {
                check_admin_referer( 'cias_forgot' );
                $email = sanitize_email( $_POST['email'] ?? '' );
                $user  = get_user_by( 'email', $email );
                if ( ! $user ) {
                    $msg = 'No account found with that email address.'; $error = true;
                } else {
                    $result = retrieve_password( $user->user_login );
                    if ( is_wp_error( $result ) ) { $msg = $result->get_error_message(); $error = true; }
                    else { $msg = 'Password reset link sent! Check your email inbox.'; $error = false; }
                }
                $mode = 'forgot';
            }
        }

        $page_url   = get_permalink();
        $nonce_map  = [ 'login' => 'cias_login', 'register' => 'cias_register', 'forgot' => 'cias_forgot' ];
        $nonce_name = $nonce_map[ $mode ] ?? 'cias_login';

        ob_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<meta name="theme-color" content="#1a1560">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<title>CIAS · <?php echo $mode === 'register' ? 'Create Account' : ( $mode === 'forgot' ? 'Reset Password' : 'Sign In' ); ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.19.0/tabler-icons.min.css">
<style>
*{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#0e0c1f;-webkit-font-smoothing:antialiased}
.auth-root{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px}
.auth-shell{width:100%;max-width:400px}
.auth-brand{text-align:center;margin-bottom:28px}
.auth-logo{width:54px;height:54px;background:#e8431a;border-radius:16px;display:inline-flex;align-items:center;justify-content:center;font-size:28px;color:#fff;margin-bottom:12px}
.auth-brand-name{color:#fff;font-size:22px;font-weight:800;letter-spacing:-.3px}
.auth-brand-sub{color:rgba(255,255,255,.45);font-size:13px;margin-top:3px}
.auth-card{background:#fff;border-radius:20px;padding:28px 24px;width:100%}
.auth-tab-row{display:flex;border-bottom:1.5px solid #f0f0f0;margin-bottom:22px;gap:4px}
.auth-tab{flex:1;text-align:center;padding:8px 4px;font-size:13px;font-weight:600;color:#9ca3af;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1.5px;text-decoration:none;transition:color .15s,border-color .15s}
.auth-tab.active{color:#1a1560;border-bottom-color:#6c63ff}
.auth-title{font-size:18px;font-weight:800;color:#1a1560;margin-bottom:4px}
.auth-subtitle{font-size:13px;color:#9ca3af;margin-bottom:20px}
.auth-field{margin-bottom:14px}
.auth-label{display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;letter-spacing:.02em}
.auth-input-wrap{position:relative;display:flex;align-items:center}
.auth-input-icon{position:absolute;left:12px;color:#9ca3af;font-size:18px;font-family:"tabler-icons";font-style:normal;line-height:1}
.auth-input{width:100%;padding:12px 12px 12px 40px;border:1.5px solid #e5e7eb;border-radius:12px;font-size:14px;color:#1f2937;font-family:inherit;outline:none;transition:border-color .15s}
.auth-input:focus{border-color:#6c63ff}
.auth-input::placeholder{color:#c0c0c0}
.auth-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
.auth-remember{display:flex;align-items:center;gap:6px;font-size:12px;color:#6b7280;cursor:pointer}
.auth-remember input{accent-color:#6c63ff;width:14px;height:14px}
.auth-forgot-link{font-size:12px;color:#6c63ff;font-weight:600;text-decoration:none}
.auth-btn{width:100%;background:#6c63ff;color:#fff;border:none;border-radius:14px;padding:14px;font-size:15px;font-weight:700;cursor:pointer;font-family:inherit;margin-top:4px;display:flex;align-items:center;justify-content:center;gap:8px;transition:background .15s}
.auth-btn:hover{background:#534AB7}
.auth-btn:active{background:#3d3597;transform:scale(.99)}
.auth-divider{display:flex;align-items:center;gap:10px;margin:18px 0;color:#d1d5db;font-size:12px}
.auth-divider::before,.auth-divider::after{content:'';flex:1;height:1px;background:#f0f0f0}
.auth-switch{text-align:center;font-size:13px;color:#9ca3af;margin-top:14px}
.auth-switch a{color:#6c63ff;font-weight:600;text-decoration:none}
.auth-msg{padding:10px 14px;border-radius:10px;font-size:13px;font-weight:500;margin-bottom:16px;display:flex;align-items:center;gap:8px}
.auth-msg.error{background:#fef2f2;color:#dc2626;border:1px solid #fecaca}
.auth-msg.success{background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0}
.auth-terms{font-size:11px;color:#9ca3af;text-align:center;margin-top:12px;line-height:1.5}
.auth-terms a{color:#6c63ff}
.auth-back{display:flex;align-items:center;gap:6px;font-size:13px;color:#6c63ff;font-weight:600;cursor:pointer;margin-bottom:18px;background:none;border:none;padding:0;font-family:inherit}
</style>
</head>
<body>
<div class="auth-root">
<div class="auth-shell">

  <div class="auth-brand">
    <div class="auth-logo"><i class="ti ti-flame" style="font-family:'tabler-icons';font-style:normal"></i></div>
    <div class="auth-brand-name">CIAS · UPSC Prep</div>
    <div class="auth-brand-sub">Your personal AI mentor for IAS 2026</div>
  </div>

  <div class="auth-card">

    <?php if ( $mode !== 'forgot' ): ?>
    <div class="auth-tab-row">
      <a class="auth-tab <?php echo $mode==='login'?'active':''; ?>"
         href="<?php echo esc_url( add_query_arg( 'auth', 'login', $page_url ) ); ?>">Sign In</a>
      <a class="auth-tab <?php echo $mode==='register'?'active':''; ?>"
         href="<?php echo esc_url( add_query_arg( 'auth', 'register', $page_url ) ); ?>">Create Account</a>
    </div>
    <?php endif; ?>

    <?php if ( $msg ): ?>
    <div class="auth-msg <?php echo $error ? 'error' : 'success'; ?>">
      <i class="ti <?php echo $error ? 'ti-alert-circle' : 'ti-circle-check'; ?>" style="font-family:'tabler-icons';font-style:normal;font-size:18px"></i>
      <?php echo esc_html( $msg ); ?>
    </div>
    <?php endif; ?>

    <?php if ( $mode === 'login' ): ?>
    <!-- ── LOGIN ── -->
    <div class="auth-title">Welcome back</div>
    <div class="auth-subtitle">Sign in to continue your UPSC journey</div>
    <form method="POST" autocomplete="on">
      <?php wp_nonce_field( 'cias_login' ); ?>
      <input type="hidden" name="cias_auth_action" value="login">
      <div class="auth-field">
        <label class="auth-label">Username or Email</label>
        <div class="auth-input-wrap">
          <i class="auth-input-icon ti ti-user"></i>
          <input class="auth-input" type="text" name="username" placeholder="Enter your username" autocomplete="username" required>
        </div>
      </div>
      <div class="auth-field">
        <label class="auth-label">Password</label>
        <div class="auth-input-wrap">
          <i class="auth-input-icon ti ti-lock"></i>
          <input class="auth-input" type="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
        </div>
      </div>
      <div class="auth-row">
        <label class="auth-remember"><input type="checkbox" name="remember"> Remember me</label>
        <a class="auth-forgot-link" href="<?php echo esc_url( add_query_arg( 'auth', 'forgot', $page_url ) ); ?>">Forgot password?</a>
      </div>
      <button type="submit" class="auth-btn">
        <i class="ti ti-login" style="font-family:'tabler-icons';font-style:normal"></i> Sign In
      </button>
    </form>
    <div class="auth-switch">New here? <a href="<?php echo esc_url( add_query_arg( 'auth', 'register', $page_url ) ); ?>">Create a free account →</a></div>

    <?php elseif ( $mode === 'register' ): ?>
    <!-- ── REGISTER ── -->
    <div class="auth-title">Create your account</div>
    <div class="auth-subtitle">Start your UPSC preparation with AI guidance</div>
    <form method="POST">
      <?php wp_nonce_field( 'cias_register' ); ?>
      <input type="hidden" name="cias_auth_action" value="register">
      <div class="auth-field">
        <label class="auth-label">First Name</label>
        <div class="auth-input-wrap">
          <i class="auth-input-icon ti ti-user"></i>
          <input class="auth-input" type="text" name="firstname" placeholder="Your first name" required>
        </div>
      </div>
      <div class="auth-field">
        <label class="auth-label">Username</label>
        <div class="auth-input-wrap">
          <i class="auth-input-icon ti ti-at"></i>
          <input class="auth-input" type="text" name="username" placeholder="Choose a username" autocomplete="username" required>
        </div>
      </div>
      <div class="auth-field">
        <label class="auth-label">Email Address</label>
        <div class="auth-input-wrap">
          <i class="auth-input-icon ti ti-mail"></i>
          <input class="auth-input" type="email" name="email" placeholder="your@email.com" autocomplete="email" required>
        </div>
      </div>
      <div class="auth-field">
        <label class="auth-label">Password <span style="color:#9ca3af;font-weight:400">(min 8 characters)</span></label>
        <div class="auth-input-wrap">
          <i class="auth-input-icon ti ti-lock"></i>
          <input class="auth-input" type="password" name="password" placeholder="Create a strong password" autocomplete="new-password" required minlength="8">
        </div>
      </div>
      <button type="submit" class="auth-btn">
        <i class="ti ti-user-plus" style="font-family:'tabler-icons';font-style:normal"></i> Create Account
      </button>
      <div class="auth-terms">By creating an account you agree to our <a href="#">Terms of Service</a> and <a href="#">Privacy Policy</a>.</div>
    </form>
    <div class="auth-switch">Already have an account? <a href="<?php echo esc_url( add_query_arg( 'auth', 'login', $page_url ) ); ?>">Sign in →</a></div>

    <?php else: ?>
    <!-- ── FORGOT PASSWORD ── -->
    <button class="auth-back" onclick="history.back()">
      <i class="ti ti-arrow-left" style="font-family:'tabler-icons';font-style:normal;font-size:16px"></i> Back to sign in
    </button>
    <div class="auth-title">Reset your password</div>
    <div class="auth-subtitle">Enter your email and we'll send a reset link</div>
    <form method="POST">
      <?php wp_nonce_field( 'cias_forgot' ); ?>
      <input type="hidden" name="cias_auth_action" value="forgot">
      <div class="auth-field">
        <label class="auth-label">Email Address</label>
        <div class="auth-input-wrap">
          <i class="auth-input-icon ti ti-mail"></i>
          <input class="auth-input" type="email" name="email" placeholder="your@email.com" required>
        </div>
      </div>
      <button type="submit" class="auth-btn">
        <i class="ti ti-send" style="font-family:'tabler-icons';font-style:normal"></i> Send Reset Link
      </button>
    </form>
    <?php endif; ?>

  </div>
</div>
</div>
</body>
</html>
<?php
        return ob_get_clean();
    }


}
