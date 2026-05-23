<?php
/**
 * Plugin Name: CIAS Test Engine
 * Plugin URI:  https://digitalsumedh.online
 * Description: Complete test & assessment system for CIAS — MCQ tests, batch management, AI question generation, and full reporting.
 * Version:     3.19.0
 * Author:      Digital Sumedh
 * License:     GPL2
 * Text Domain: cias-test
 */
if (!defined('ABSPATH')) exit;

define('CIAS_VERSION',     '3.19.1');
define('CIAS_PLUGIN_FILE', __FILE__);   // Required by Phase A loader

/* Force no-cache on all CIAS admin pages — bypasses Varnish/Breeze on wp-admin */
add_action('admin_init', function() {
    if (isset($_GET['page']) && strpos($_GET['page'], 'cias-') === 0) {
        nocache_headers();
        header('Surrogate-Control: no-store');
        header('Cloudways-No-Cache: 1');
    }
});
define('CIAS_PATH',     plugin_dir_path(__FILE__));
define('CIAS_URL',      plugin_dir_url(__FILE__));

global $wpdb;
define('CIAS_COURSES',          $wpdb->prefix . 'cias_courses');
define('CIAS_BATCHES',          $wpdb->prefix . 'cias_batches');
define('CIAS_SUBJECTS',         $wpdb->prefix . 'cias_subjects');
define('CIAS_TOPICS',           $wpdb->prefix . 'cias_topics');
define('CIAS_SUBTOPICS',        $wpdb->prefix . 'cias_subtopics');
define('CIAS_ENROLLMENTS',      $wpdb->prefix . 'cias_enrollments');
define('CIAS_TEACHER_BATCHES',  $wpdb->prefix . 'cias_teacher_batches');
define('CIAS_QUESTIONS',        $wpdb->prefix . 'cias_questions');
define('CIAS_TESTS',            $wpdb->prefix . 'cias_tests');
define('CIAS_TEST_BATCH',       $wpdb->prefix . 'cias_test_batches');
define('CIAS_TEST_Q',           $wpdb->prefix . 'cias_test_questions');
define('CIAS_ATTEMPTS',         $wpdb->prefix . 'cias_attempts');
define('CIAS_ANSWERS',          $wpdb->prefix . 'cias_answers');
define('CIAS_TOPIC_STATS',      $wpdb->prefix . 'cias_topic_stats');
define('CIAS_ADAPTIVE',         $wpdb->prefix . 'cias_adaptive_tests');
define('CIAS_OFFLINE_TESTS',    $wpdb->prefix . 'cias_offline_tests');
define('CIAS_OFFLINE_RESULTS',  $wpdb->prefix . 'cias_offline_results');
define('CIAS_WA_LOG',           $wpdb->prefix . 'cias_whatsapp_log');
define('CIAS_AI_CREDITS',       $wpdb->prefix . 'cias_ai_credits');
define('CIAS_AI_USAGE',         $wpdb->prefix . 'cias_ai_usage_log');
define('CIAS_AI_CREDIT_LOG',    $wpdb->prefix . 'cias_ai_credit_log');
define('CIAS_AI_GENERATIONS',   $wpdb->prefix . 'cias_ai_generations');

/**
 * Secure API key getter — prefers wp-config constants over DB options.
 * Usage: define('CIAS_ANTHROPIC_KEY', 'sk-...') in wp-config.php
 */
function cias_get_api_key(string $name): string {
    $const = 'CIAS_' . strtoupper(str_replace(['-', ' '], '_', $name));
    if (defined($const) && constant($const)) return (string) constant($const);
    return (string) get_option('cias_' . $name, '');
}

require_once CIAS_PATH . 'includes/class-cias-db.php';
require_once CIAS_PATH . 'includes/class-cias-adaptive.php';
require_once CIAS_PATH . 'includes/class-cias-ajax.php';
require_once CIAS_PATH . 'includes/class-cias-features.php';
require_once CIAS_PATH . 'includes/class-cias-importer.php';
require_once CIAS_PATH . 'includes/class-cias-whatsapp.php';
require_once CIAS_PATH . 'includes/class-cias-email-reports.php';
require_once CIAS_PATH . 'includes/class-cias-ai-utils.php';
require_once CIAS_PATH . 'includes/class-cias-ai-bot.php';
require_once CIAS_PATH . 'includes/class-cias-content-manager.php';
require_once CIAS_PATH . 'includes/class-cias-ai-guru.php';

// ── Phase A: Credits shown in AI Guru tab ────────────────────────────────────
require_once CIAS_PATH . 'phase-a/cias-phase-a.php';

// ── Phase B: Scalable async AI backend ───────────────────────────────────────
require_once CIAS_PATH . 'phase-b/cias-phase-b.php';

// ── Phase C: Student frontend app [cias_app] shortcode ───────────────────────
require_once CIAS_PATH . 'phase-c/cias-phase-c.php';

register_activation_hook(__FILE__, function () {
    CIAS_DB::create_tables();
    CIAS_DB::seed_defaults();
    CIAS_DB::setup_roles_and_caps();
    update_option('cias_db_version', CIAS_VERSION);
    add_option('cias_anthropic_key', '');
    add_option('cias_pass_percentage', 60);
    add_option('cias_show_answer_after', 'submit');
    add_option('cias_brevo_wa_key', '');
    add_option('cias_brevo_wa_sender', '');
    add_option('cias_wa_enabled', '0');

    // Schedule daily report at 8 PM IST (14:30 UTC)
    if (!wp_next_scheduled('cias_daily_parent_report')) {
        $time = strtotime('today 14:30:00 UTC');
        if ($time < time()) $time += DAY_IN_SECONDS;
        wp_schedule_event($time, 'daily', 'cias_daily_parent_report');
    }
    // Schedule weekly report every Sunday at 8 PM IST
    if (!wp_next_scheduled('cias_weekly_parent_report')) {
        $next_sunday = strtotime('next Sunday 14:30:00 UTC');
        wp_schedule_event($next_sunday, 'weekly', 'cias_weekly_parent_report');
    }
});

register_deactivation_hook(__FILE__, function () {
    remove_role('cias_teacher');
    remove_role('cias_content_manager');
    wp_clear_scheduled_hook('cias_daily_parent_report');
    wp_clear_scheduled_hook('cias_weekly_parent_report');
});

/* ── Hook cron events ── */
add_action('cias_daily_parent_report',  ['CIAS_WhatsApp',       'send_daily_reports']);
add_action('cias_weekly_parent_report', ['CIAS_WhatsApp',       'send_weekly_reports']);
add_action('cias_daily_parent_report',  ['CIAS_Email_Reports',  'send_daily_reports']);
add_action('cias_weekly_parent_report', ['CIAS_Email_Reports',  'send_weekly_reports']);

/* ── Post-test instant parent email ── */
add_action('cias_test_submitted', function($user_id, $test_id) {
    CIAS_Email_Reports::send_post_test_report($user_id, $test_id);
}, 10, 2);

/* ── Auto-reschedule crons if missing (e.g. after plugin update) ── */
add_action('init', function () {
    if (!wp_next_scheduled('cias_daily_parent_report')) {
        $time = strtotime('today 14:30:00 UTC');
        if ($time < time()) $time += DAY_IN_SECONDS;
        wp_schedule_event($time, 'daily', 'cias_daily_parent_report');
    }
    if (!wp_next_scheduled('cias_weekly_parent_report')) {
        $next_sunday = strtotime('next Sunday 14:30:00 UTC');
        wp_schedule_event($next_sunday, 'weekly', 'cias_weekly_parent_report');
    }
}, 2);

/* ── Auto-upgrade: runs create_tables() whenever plugin version changes.
   This means installing on a NEW site OR updating an existing site
   both work correctly without any manual steps. ── */
add_action('init', function () {
    $installed = get_option('cias_db_version', '0');
    if (version_compare($installed, CIAS_VERSION, '<')) {
        CIAS_DB::create_tables();
        CIAS_DB::seed_defaults();
        CIAS_DB::setup_roles_and_caps();
        update_option('cias_db_version', CIAS_VERSION);
    }
}, 1); // priority 1 — runs before anything else

class CIAS_Test_Engine {

    private static $instance = null;
    public static function get_instance() {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('init',               [$this, 'ensure_caps']);
        add_action('admin_menu',         [$this, 'admin_menu']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        add_shortcode('cias_tests',      [$this, 'render_frontend']);
        new CIAS_Ajax();
    }

    /* Ensure admin always has CIAS capabilities */
    public function ensure_caps() {
        // Handled by auto-upgrade on init — no-op here
    }

    /* ══════════════════════════════════
       FRONTEND ASSETS
    ══════════════════════════════════ */
    public function enqueue() {
        global $post;
        if (!is_a($post, 'WP_Post')) return;
        $has = has_shortcode($post->post_content, 'cias_tests')
            || has_shortcode($post->post_content, 'cias_leaderboard')
            || has_shortcode($post->post_content, 'cias_teacher_dashboard')
            || has_shortcode($post->post_content, 'cias_portal');
        if (!$has) return;
        wp_enqueue_style('cias-test', CIAS_URL . 'assets/css/cias-style.css', [], CIAS_VERSION);
        wp_enqueue_script('cias-test', CIAS_URL . 'assets/js/cias-test.js', ['jquery'], CIAS_VERSION, true);

        // Razorpay checkout — only when keys configured
        $rzp_key = cias_get_api_key('razorpay_key_id');
        if ($rzp_key) {
            wp_enqueue_script('razorpay-checkout', 'https://checkout.razorpay.com/v1/checkout.js', [], null, true);
        }

        $bot_status = is_user_logged_in() ? CIAS_AI_Bot::get_student_status(get_current_user_id()) : [];

        wp_localize_script('cias-test', 'CIASTest', [
            'ajax_url'      => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce('cias_nonce'),
            'caig_nonce'    => wp_create_nonce('caig_nonce'),
            'is_logged_in'  => is_user_logged_in(),
            'user_id'       => get_current_user_id(),
            'razorpay_key'  => $rzp_key ?: '',
            'bot_enabled'   => get_option('cias_ai_bot_enabled', '0') === '1',
            'bot_status'    => $bot_status,
            'bot_packs'     => [
                ['id'=>'pack_50',  'label'=>'50 credits',  'amount'=>9900,  'credits'=>50,  'price'=>'₹99'],
                ['id'=>'pack_120', 'label'=>'120 credits', 'amount'=>19900, 'credits'=>120, 'price'=>'₹199'],
            ],
        ]);
    }

    /* ══════════════════════════════════
       SHORTCODE FRONTEND
    ══════════════════════════════════ */
    public function render_frontend() {
        ob_start();
        if (!is_user_logged_in()) {
            echo '<div class="cias-notice">Please <a href="' . esc_url(wp_login_url(get_permalink())) . '">log in</a> to access your tests.</div>';
        } else {
            $this->tpl_app();
        }
        return ob_get_clean();
    }

    private function tpl_app() {
        $user  = wp_get_current_user();
        $name  = $user->first_name ?: $user->user_login;
        $db    = new CIAS_DB();
        $pending = $db->count_pending_tests(get_current_user_id());
        $due_rev = $db->count_due_revisions(get_current_user_id());
        ?>
<div class="cias-app" id="cias-app">

  <!-- Header -->
  <div class="cias-header">
    <div>
      <span class="cias-greeting">Tests — <?php echo esc_html($name); ?></span>
    </div>
    <div class="cias-header-right">
      <div class="cias-pill"><span class="cias-pill-num" id="cias-pending-count"><?php echo intval($pending); ?></span><span>Pending</span></div>
      <?php if ($due_rev > 0): ?>
      <div class="cias-pill" style="border:1px solid #fca5a5;background:#fef2f2"><span class="cias-pill-num" style="color:#dc2626"><?php echo intval($due_rev); ?></span><span style="color:#dc2626">Due revision</span></div>
      <?php endif; ?>
      <button class="cias-tab-btn active" data-tab="tests">📋 My Tests</button>
      <button class="cias-tab-btn" data-tab="practice">🎯 Practice</button>
      <button class="cias-tab-btn" data-tab="history">📊 History</button>
      <?php if (get_option('cias_ai_bot_enabled', '0') === '1'): ?>
      <button class="cias-tab-btn" data-tab="bot" style="position:relative">
        🤖 AI Tutor
        <?php
        $st = CIAS_AI_Bot::get_student_status(get_current_user_id());
        $free_left = max(0, CIAS_AI_Bot::FREE_DAILY_LIMIT - ($st['free_used_today'] ?? 0));
        if ($free_left > 0): ?>
        <span style="position:absolute;top:-4px;right:-4px;background:#6C63FF;color:#fff;border-radius:99px;font-size:9px;padding:1px 5px;font-weight:600"><?php echo $free_left; ?></span>
        <?php endif; ?>
      </button>
      <?php endif; ?>
      <button class="cias-tab-btn" data-tab="guru">🧠 AI Guru</button>
    </div>
  </div>

  <div class="cias-tab" id="cias-tab-tests">
    <div id="cias-tests-list"><div class="cias-loading">Loading your tests…</div></div>
  </div>

  <div class="cias-tab" id="cias-tab-practice" style="display:none">
    <div id="cias-practice-list"><div class="cias-loading">Loading practice…</div></div>
  </div>

  <div class="cias-tab" id="cias-tab-history" style="display:none">
    <div id="cias-history-list"><div class="cias-loading">Loading history…</div></div>
  </div>

  <div class="cias-tab" id="cias-tab-exam" style="display:none">
    <div id="cias-exam-wrap"></div>
  </div>

  <div class="cias-tab" id="cias-tab-results" style="display:none">
    <div id="cias-results-wrap"></div>
  </div>

  <?php if (get_option('cias_ai_bot_enabled', '0') === '1'): ?>
  <div class="cias-tab" id="cias-tab-bot" style="display:none">
    <div id="cias-bot-wrap"></div>
  </div>
  <?php endif; ?>

  <div class="cias-tab" id="cias-tab-guru" style="display:none">
    <div id="cias-guru-wrap"><?php CAIG_Frontend::render(); ?></div>
  </div>

</div>
    <?php
    }

    /* ══════════════════════════════════
       ADMIN MENU
    ══════════════════════════════════ */
    public function admin_menu() {
        add_menu_page('CIAS Tests', 'CIAS Tests', 'manage_options', 'cias-tests', [$this,'page_dashboard'], 'dashicons-clipboard', 26);
        add_submenu_page('cias-tests', 'Dashboard',       'Dashboard',       'manage_options',           'cias-tests',              [$this,'page_dashboard']);
        add_submenu_page('cias-tests', 'Courses',          'Courses',         'manage_options',           'cias-courses',            [$this,'page_courses']);
        add_submenu_page('cias-tests', 'Batches',          'Batches',         'manage_options',           'cias-batches',            [$this,'page_batches']);
        add_submenu_page('cias-tests', 'Subjects',         'Subjects',        'manage_options',           'cias-subjects',           [$this,'page_subjects']);
        add_submenu_page('cias-tests', 'Topics',           'Topics',          'manage_options',           'cias-topics',             [$this,'page_topics']);
        add_submenu_page('cias-tests', 'Subtopics',        'Subtopics',       'manage_options',           'cias-subtopics',          [$this,'page_subtopics']);
        add_submenu_page('cias-tests', 'Enrollments',      'Enrollments',     'manage_options',           'cias-enrollments',        [$this,'page_enrollments']);
        add_submenu_page('cias-tests', 'Teachers',         'Teachers',        'cias_manage_teachers',     'cias-teachers',           'cias_page_teachers');
        add_submenu_page('cias-tests', 'Questions',        'Questions',       'cias_add_questions',       'cias-questions',          [$this,'page_questions']);
        add_submenu_page('cias-tests', 'Import Questions', 'Import Questions','cias_add_questions',       'cias-import',             [$this,'page_import']);
        add_submenu_page('cias-tests', 'Tests',            'Tests',           'cias_create_tests',        'cias-test-list',          [$this,'page_tests']);
        add_submenu_page('cias-tests', 'Offline Tests',    'Offline Tests',   'cias_enter_offline',       'cias-offline',            'cias_page_offline_tests');
        add_submenu_page('cias-tests', '✨ Content Manager','✨ Content Mgr', 'cias_use_content_manager', 'cias-content-manager',    ['CIAS_Content_Manager','render_page']);
        add_submenu_page('cias-tests', 'Parents',          'Parents',         'manage_options',           'cias-parents',            [$this,'page_parents']);
        add_submenu_page('cias-tests', 'Communication Logs','Comms Logs',     'manage_options',           'cias-wa-logs',            [$this,'page_wa_logs']);
        add_submenu_page('cias-tests', '🤖 AI Usage',      '🤖 AI Usage',    'manage_options',           'cias-ai-usage',           [$this,'page_ai_usage']);
        add_submenu_page('cias-tests', 'Access Control',   'Access Control',  'manage_options',           'cias-access-control',     [$this,'page_access_control']);
        add_submenu_page('cias-tests', '🧠 AI Guru',        '🧠 AI Guru',      'manage_options',           'cias-ai-guru',            [$this,'page_ai_guru']);
        add_submenu_page('cias-tests', '🎬 Lecture Mgr',    '🎬 Lecture Mgr',  'cias_use_content_manager', 'cias-lecture-mgr',        [$this,'page_lecture_mgr']);
        add_submenu_page('cias-tests', 'Reports',          'Reports',         'cias_view_reports',        'cias-reports',            [$this,'page_reports']);
        add_submenu_page('cias-tests', 'Settings',         'Settings',        'manage_options',           'cias-settings',           [$this,'page_settings']);
    }

    /* ── Dashboard ── */
    public function page_dashboard() {
        $db = new CIAS_DB();
        $s  = $db->get_overview_stats();
        ?>
<div class="wrap">
  <h1>🎯 CIAS Test Engine</h1>
  <div style="display:flex;gap:16px;flex-wrap:wrap;margin:20px 0">
    <?php foreach([
        ['📚','Courses',    $s['courses']],
        ['👥','Batches',    $s['batches']],
        ['🎓','Students',   $s['students']],
        ['❓','Questions',  $s['questions']],
        ['📋','Tests',      $s['tests']],
        ['✅','Attempts',   $s['attempts']],
    ] as [$icon,$label,$val]): ?>
    <div class="cias-admin-card"><div class="cias-admin-card-icon"><?php echo $icon; ?></div><div class="cias-admin-card-num"><?php echo intval($val); ?></div><div><?php echo $label; ?></div></div>
    <?php endforeach; ?>
  </div>
  <hr>
  <h3>Quick Actions</h3>
  <a href="<?php echo admin_url('admin.php?page=cias-questions&action=add'); ?>" class="button button-primary">+ Add Question</a>&nbsp;
  <a href="<?php echo admin_url('admin.php?page=cias-test-list&action=add'); ?>" class="button button-primary">+ Create Test</a>&nbsp;
  <a href="<?php echo admin_url('admin.php?page=cias-enrollments&action=add'); ?>" class="button">+ Enroll Student</a>
  <hr>
  <p>Add <code>[cias_tests]</code> to any page to show the test interface for students.</p>
</div>
        <?php
    }

    /* ── Courses ── */
    public function page_courses() {
        $db = new CIAS_DB();
        if (isset($_POST['cias_save_course']) && check_admin_referer('cias_course')) {
            $id = intval($_POST['course_id'] ?? 0);
            $data = ['name'=>sanitize_text_field($_POST['name']),'description'=>sanitize_textarea_field($_POST['description']),'status'=>sanitize_text_field($_POST['status'])];
            $id ? $db->update('courses',$data,$id) : $db->insert('courses',$data);
            echo '<div class="notice notice-success"><p>Course saved!</p></div>';
        }
        if (isset($_GET['delete']) && current_user_can('manage_options')) {
            $db->delete('courses', intval($_GET['delete']));
            echo '<div class="notice notice-success"><p>Course deleted.</p></div>';
        }
        $editing = null;
        if (isset($_GET['edit'])) $editing = $db->get_by_id('courses', intval($_GET['edit']));
        $courses = $db->get_all('courses');
        ?>
<div class="wrap"><h1>Courses</h1>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-top:16px">
  <div>
    <h3><?php echo $editing ? 'Edit Course' : 'Add Course'; ?></h3>
    <form method="post"><?php wp_nonce_field('cias_course'); ?>
    <input type="hidden" name="course_id" value="<?php echo $editing ? intval($editing->id) : 0; ?>">
    <table class="form-table">
      <tr><th>Name</th><td><input type="text" name="name" value="<?php echo esc_attr($editing->name ?? ''); ?>" class="regular-text" required></td></tr>
      <tr><th>Description</th><td><textarea name="description" rows="3" class="large-text"><?php echo esc_textarea($editing->description ?? ''); ?></textarea></td></tr>
      <tr><th>Status</th><td><select name="status"><option value="active" <?php selected(($editing->status ?? 'active'),'active'); ?>>Active</option><option value="inactive" <?php selected(($editing->status ?? ''),'inactive'); ?>>Inactive</option></select></td></tr>
    </table>
    <p><input type="submit" name="cias_save_course" class="button button-primary" value="<?php echo $editing ? 'Update' : 'Add Course'; ?>">
    <?php if($editing): ?> <a href="<?php echo admin_url('admin.php?page=cias-courses'); ?>" class="button">Cancel</a><?php endif; ?></p>
    </form>
  </div>
  <div>
    <h3>All Courses</h3>
    <table class="wp-list-table widefat fixed striped"><thead><tr><th>Name</th><th>Status</th><th>Actions</th></tr></thead><tbody>
    <?php foreach($courses as $c): ?>
    <tr><td><strong><?php echo esc_html($c->name); ?></strong><br><small><?php echo esc_html($c->description); ?></small></td>
    <td><?php echo esc_html($c->status); ?></td>
    <td><a href="?page=cias-courses&edit=<?php echo $c->id; ?>">Edit</a> | <a href="?page=cias-courses&delete=<?php echo $c->id; ?>" onclick="return confirm('Delete this course?')">Delete</a></td></tr>
    <?php endforeach; ?>
    </tbody></table>
  </div>
</div></div>
        <?php
    }

    /* ── Batches ── */
    public function page_batches() {
        $db = new CIAS_DB();
        if (isset($_POST['cias_save_batch']) && check_admin_referer('cias_batch')) {
            $id = intval($_POST['batch_id'] ?? 0);
            $data = ['name'=>sanitize_text_field($_POST['name']),'course_id'=>intval($_POST['course_id']),'description'=>sanitize_textarea_field($_POST['description']),'start_date'=>sanitize_text_field($_POST['start_date']),'end_date'=>sanitize_text_field($_POST['end_date']),'status'=>sanitize_text_field($_POST['status'])];
            $id ? $db->update('batches',$data,$id) : $db->insert('batches',$data);
            echo '<div class="notice notice-success"><p>Batch saved!</p></div>';
        }
        if (isset($_GET['delete'])) { $db->delete('batches', intval($_GET['delete'])); echo '<div class="notice notice-success"><p>Batch deleted.</p></div>'; }
        $editing = isset($_GET['edit']) ? $db->get_by_id('batches', intval($_GET['edit'])) : null;
        $batches = $db->get_batches_with_course();
        $courses = $db->get_all('courses','status = "active"');
        ?>
<div class="wrap"><h1>Batches</h1>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-top:16px">
  <div>
    <h3><?php echo $editing ? 'Edit Batch' : 'Add Batch'; ?></h3>
    <form method="post"><?php wp_nonce_field('cias_batch'); ?>
    <input type="hidden" name="batch_id" value="<?php echo $editing ? intval($editing->id) : 0; ?>">
    <table class="form-table">
      <tr><th>Name</th><td><input type="text" name="name" value="<?php echo esc_attr($editing->name ?? ''); ?>" class="regular-text" required></td></tr>
      <tr><th>Course</th><td><select name="course_id"><?php foreach($courses as $c): ?><option value="<?php echo $c->id; ?>" <?php selected(($editing->course_id ?? 0),$c->id); ?>><?php echo esc_html($c->name); ?></option><?php endforeach; ?></select></td></tr>
      <tr><th>Description</th><td><textarea name="description" rows="2" class="large-text"><?php echo esc_textarea($editing->description ?? ''); ?></textarea></td></tr>
      <tr><th>Start Date</th><td><input type="date" name="start_date" value="<?php echo esc_attr($editing->start_date ?? ''); ?>"></td></tr>
      <tr><th>End Date</th><td><input type="date" name="end_date" value="<?php echo esc_attr($editing->end_date ?? ''); ?>"></td></tr>
      <tr><th>Status</th><td><select name="status"><option value="active" <?php selected(($editing->status ?? 'active'),'active'); ?>>Active</option><option value="inactive" <?php selected(($editing->status ?? ''),'inactive'); ?>>Inactive</option></select></td></tr>
    </table>
    <p><input type="submit" name="cias_save_batch" class="button button-primary" value="<?php echo $editing ? 'Update' : 'Add Batch'; ?>">
    <?php if($editing): ?><a href="?page=cias-batches" class="button">Cancel</a><?php endif; ?></p>
    </form>
  </div>
  <div>
    <h3>All Batches</h3>
    <table class="wp-list-table widefat fixed striped"><thead><tr><th>Batch</th><th>Course</th><th>Status</th><th>Actions</th></tr></thead><tbody>
    <?php foreach($batches as $b): ?>
    <tr><td><strong><?php echo esc_html($b->name); ?></strong></td><td><?php echo esc_html($b->course_name ?? '—'); ?></td><td><?php echo esc_html($b->status); ?></td>
    <td><a href="?page=cias-batches&edit=<?php echo $b->id; ?>">Edit</a> | <a href="?page=cias-batches&delete=<?php echo $b->id; ?>" onclick="return confirm('Delete?')">Delete</a></td></tr>
    <?php endforeach; ?>
    </tbody></table>
  </div>
</div></div>
        <?php
    }

    /* ── Subjects ── */
    public function page_subjects() {
        $db = new CIAS_DB();
        if (isset($_POST['cias_save_subject']) && check_admin_referer('cias_subject')) {
            $id = intval($_POST['subject_id'] ?? 0);
            $data = ['name'=>sanitize_text_field($_POST['name']),'description'=>sanitize_textarea_field($_POST['description']),'color'=>sanitize_hex_color($_POST['color']) ?: '#6C63FF'];
            $id ? $db->update('subjects',$data,$id) : $db->insert('subjects',$data);
        }
        if (isset($_GET['delete'])) $db->delete('subjects', intval($_GET['delete']));
        $editing  = isset($_GET['edit']) ? $db->get_by_id('subjects', intval($_GET['edit'])) : null;
        $subjects = $db->get_all('subjects');
        ?>
<div class="wrap"><h1>Subjects</h1>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-top:16px">
  <div><h3><?php echo $editing ? 'Edit' : 'Add'; ?> Subject</h3>
  <form method="post"><?php wp_nonce_field('cias_subject'); ?>
  <input type="hidden" name="subject_id" value="<?php echo $editing ? intval($editing->id) : 0; ?>">
  <table class="form-table">
    <tr><th>Name</th><td><input type="text" name="name" value="<?php echo esc_attr($editing->name ?? ''); ?>" class="regular-text" required></td></tr>
    <tr><th>Description</th><td><textarea name="description" rows="2" class="large-text"><?php echo esc_textarea($editing->description ?? ''); ?></textarea></td></tr>
    <tr><th>Color</th><td><input type="color" name="color" value="<?php echo esc_attr($editing->color ?? '#6C63FF'); ?>"></td></tr>
  </table>
  <p><input type="submit" name="cias_save_subject" class="button button-primary" value="Save">
  <?php if($editing): ?><a href="?page=cias-subjects" class="button">Cancel</a><?php endif; ?></p>
  </form></div>
  <div><h3>All Subjects</h3>
  <table class="wp-list-table widefat fixed striped"><thead><tr><th>Subject</th><th>Actions</th></tr></thead><tbody>
  <?php foreach($subjects as $s): ?>
  <tr><td><span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:<?php echo esc_attr($s->color); ?>;margin-right:6px"></span><strong><?php echo esc_html($s->name); ?></strong><br><small><?php echo esc_html($s->description); ?></small></td>
  <td><a href="?page=cias-subjects&edit=<?php echo $s->id; ?>">Edit</a> | <a href="?page=cias-subjects&delete=<?php echo $s->id; ?>" onclick="return confirm('Delete?')">Delete</a></td></tr>
  <?php endforeach; ?></tbody></table></div>
</div></div>
        <?php
    }

    /* ── Enrollments ── */
    public function page_enrollments() {
        $db = new CIAS_DB();
        if (isset($_POST['cias_enroll']) && check_admin_referer('cias_enrollment')) {
            $db->enroll_student(intval($_POST['user_id']), intval($_POST['batch_id']));
            echo '<div class="notice notice-success"><p>Student enrolled!</p></div>';
        }
        if (isset($_GET['unenroll'])) {
            $db->unenroll(intval($_GET['unenroll']));
            echo '<div class="notice notice-success"><p>Unenrolled.</p></div>';
        }
        $batches = $db->get_batches_with_course();
        $users   = get_users(['orderby'=>'display_name']);
        $enrollments = $db->get_enrollments_full();
        ?>
<div class="wrap"><h1>Enrollments</h1>
<div style="display:grid;grid-template-columns:340px 1fr;gap:24px;margin-top:16px">
  <div><h3>Enroll Student</h3>
  <form method="post"><?php wp_nonce_field('cias_enrollment'); ?>
  <table class="form-table">
    <tr><th>Student</th><td><select name="user_id" style="width:100%"><?php foreach($users as $u): ?><option value="<?php echo $u->ID; ?>"><?php echo esc_html($u->display_name . ' (' . $u->user_email . ')'); ?></option><?php endforeach; ?></select></td></tr>
    <tr><th>Batch</th><td><select name="batch_id" style="width:100%"><?php foreach($batches as $b): ?><option value="<?php echo $b->id; ?>"><?php echo esc_html(($b->course_name ?? '') . ' — ' . $b->name); ?></option><?php endforeach; ?></select></td></tr>
  </table>
  <p><input type="submit" name="cias_enroll" class="button button-primary" value="Enroll Student"></p>
  </form></div>
  <div><h3>Current Enrollments</h3>
  <table class="wp-list-table widefat fixed striped"><thead><tr><th>Student</th><th>Batch</th><th>Course</th><th>Enrolled</th><th>Action</th></tr></thead><tbody>
  <?php foreach($enrollments as $e): ?>
  <tr><td><?php echo esc_html($e->display_name); ?><br><small><?php echo esc_html($e->user_email); ?></small></td>
  <td><?php echo esc_html($e->batch_name); ?></td><td><?php echo esc_html($e->course_name ?? '—'); ?></td>
  <td><?php echo date('d M Y', strtotime($e->enrolled_at)); ?></td>
  <td><a href="?page=cias-enrollments&unenroll=<?php echo $e->id; ?>" onclick="return confirm('Unenroll?')">Remove</a></td></tr>
  <?php endforeach; ?></tbody></table></div>
</div></div>
        <?php
    }

    /* ── Topics ── */
    public function page_topics() {
        global $wpdb;
        $db = new CIAS_DB();

        // Ensure table exists right now regardless of cache
        $table = $wpdb->prefix . 'cias_topics';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $c = $wpdb->get_charset_collate();
            dbDelta("CREATE TABLE $table (
                id INT AUTO_INCREMENT PRIMARY KEY,
                subject_id INT NOT NULL,
                name VARCHAR(200) NOT NULL,
                description TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                KEY subject_id (subject_id)
            ) $c;");
            echo '<div class="notice notice-warning"><p>Topics table was missing — created now. Please try saving again.</p></div>';
        }

        if (isset($_POST['cias_save_topic']) && check_admin_referer('cias_topic')) {
            $id   = intval($_POST['topic_id'] ?? 0);
            $name = sanitize_text_field($_POST['name'] ?? '');
            $sid  = intval($_POST['subject_id'] ?? 0);

            if (empty($name)) {
                echo '<div class="notice notice-error"><p>Topic name is required.</p></div>';
            } elseif ($sid === 0) {
                echo '<div class="notice notice-error"><p>Please select a subject.</p></div>';
            } else {
                $data = [
                    'name'        => $name,
                    'subject_id'  => $sid,
                    'description' => sanitize_textarea_field($_POST['description'] ?? ''),
                ];
                if ($id) {
                    $result = $wpdb->update($table, $data, ['id' => $id]);
                } else {
                    $result = $wpdb->insert($table, $data);
                }

                if ($result === false) {
                    echo '<div class="notice notice-error"><p>❌ Database error: ' . esc_html($wpdb->last_error) . '</p></div>';
                } else {
                    echo '<div class="notice notice-success"><p>✅ Topic saved successfully!</p></div>';
                }
            }
        }

        if (isset($_GET['delete'])) {
            $wpdb->delete($table, ['id' => intval($_GET['delete'])]);
            wp_cache_flush();
            echo '<div class="notice notice-success"><p>Topic deleted.</p></div>';
        }

        $editing  = isset($_GET['edit']) ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", intval($_GET['edit']))) : null;
        $topics   = $wpdb->get_results(
            "SELECT t.*, s.name AS subject_name, s.color AS subject_color,
                (SELECT COUNT(*) FROM {$wpdb->prefix}cias_subtopics WHERE topic_id=t.id) AS subtopic_count,
                (SELECT COUNT(*) FROM {$wpdb->prefix}cias_questions WHERE topic_id=t.id) AS question_count
             FROM $table t
             LEFT JOIN {$wpdb->prefix}cias_subjects s ON t.subject_id=s.id
             ORDER BY s.name, t.name"
        );
        $subjects = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}cias_subjects ORDER BY name ASC");
        ?>
<div class="wrap">
<h1 style="display:flex;align-items:center;gap:12px">Topics
  <span style="font-size:13px;font-weight:400;background:#f0eeff;color:#6C63FF;padding:3px 12px;border-radius:99px"><?php echo count($topics); ?> total</span>
</h1>

<?php if (empty($subjects)): ?>
<div class="notice notice-warning"><p>⚠️ No subjects found. <a href="?page=cias-subjects">Add subjects first</a> before creating topics.</p></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:320px 1fr;gap:24px;margin-top:16px;align-items:start">

  <!-- Add / Edit Form -->
  <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px">
    <h3 style="margin:0 0 16px;font-size:15px"><?php echo $editing ? '✏️ Edit Topic' : '➕ Add Topic'; ?></h3>
    <form method="post"><?php wp_nonce_field('cias_topic'); ?>
    <input type="hidden" name="topic_id" value="<?php echo $editing ? intval($editing->id) : 0; ?>">

    <div style="margin-bottom:14px">
      <label style="display:block;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin-bottom:5px">Subject <span style="color:red">*</span></label>
      <select name="subject_id" required style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:14px">
        <option value="">— Select subject —</option>
        <?php foreach($subjects as $s): ?>
        <option value="<?php echo $s->id; ?>" <?php selected(($editing->subject_id ?? 0), $s->id); ?>>
          <?php echo esc_html($s->name); ?>
        </option>
        <?php endforeach; ?>
        <?php if(empty($subjects)): ?>
        <option disabled>⚠ No subjects yet — add subjects first</option>
        <?php endif; ?>
      </select>
      <p style="font-size:11px;color:#9ca3af;margin:4px 0 0"><?php echo count($subjects); ?> subject(s) available</p>
    </div>

    <div style="margin-bottom:14px">
      <label style="display:block;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin-bottom:5px">Topic Name <span style="color:red">*</span></label>
      <input type="text" name="name" value="<?php echo esc_attr($editing->name ?? ''); ?>"
             style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:14px"
             required placeholder="e.g. Fundamental Rights">
    </div>

    <div style="margin-bottom:16px">
      <label style="display:block;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin-bottom:5px">Description <span style="font-weight:400;color:#9ca3af">(optional)</span></label>
      <textarea name="description" rows="2"
                style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;resize:vertical"
                placeholder="Brief description"><?php echo esc_textarea($editing->description ?? ''); ?></textarea>
    </div>

    <div style="display:flex;gap:8px">
      <input type="submit" name="cias_save_topic" class="button button-primary"
             value="<?php echo $editing ? 'Update Topic' : 'Add Topic'; ?>"
             style="flex:1;padding:8px">
      <?php if($editing): ?><a href="?page=cias-topics" class="button" style="padding:8px 14px">Cancel</a><?php endif; ?>
    </div>
    </form>
  </div>

  <!-- Topics List -->
  <div>
    <?php if (empty($topics)): ?>
    <div style="background:#f9fafb;border:1px dashed #d1d5db;border-radius:12px;padding:40px;text-align:center;color:#9ca3af">
      <div style="font-size:32px;margin-bottom:8px">📚</div>
      <p>No topics yet. Add your first topic using the form.</p>
    </div>
    <?php else: ?>

    <?php
    // Group topics by subject for better display
    $grouped = [];
    foreach ($topics as $t) {
        $key = $t->subject_name ?? 'Uncategorised';
        $grouped[$key][] = $t;
    }
    ?>

    <?php foreach($grouped as $subject_name => $group_topics): ?>
    <div style="margin-bottom:20px">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#6b7280;padding:6px 0;border-bottom:2px solid #e5e7eb;margin-bottom:8px">
        📗 <?php echo esc_html($subject_name); ?>
        <span style="font-weight:400;color:#9ca3af">(<?php echo count($group_topics); ?>)</span>
      </div>
      <table class="wp-list-table widefat fixed striped" style="border-radius:8px;overflow:hidden">
        <thead style="background:#f9fafb">
          <tr>
            <th style="width:40%">Topic</th>
            <th style="width:15%">Subtopics</th>
            <th style="width:15%">Questions</th>
            <th style="width:30%">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($group_topics as $t): ?>
        <tr>
          <td>
            <strong><?php echo esc_html($t->name); ?></strong>
            <?php if($t->description): ?><br><small style="color:#9ca3af"><?php echo esc_html($t->description); ?></small><?php endif; ?>
          </td>
          <td>
            <?php $sc = intval($t->subtopic_count); ?>
            <span style="background:<?php echo $sc>0?'#f0fdf4':'#f9fafb'; ?>;color:<?php echo $sc>0?'#166534':'#6b7280'; ?>;padding:2px 8px;border-radius:99px;font-size:12px">
              <?php echo $sc; ?>
            </span>
          </td>
          <td>
            <?php $qc = intval($t->question_count); ?>
            <span style="background:<?php echo $qc>=20?'#f0fdf4':($qc>0?'#fef3c7':'#f9fafb'); ?>;color:<?php echo $qc>=20?'#166534':($qc>0?'#92400e':'#6b7280'); ?>;padding:2px 8px;border-radius:99px;font-size:12px">
              <?php echo $qc; ?><?php echo $qc<20?' ⚠':''; ?>
            </span>
          </td>
          <td>
            <a href="?page=cias-topics&edit=<?php echo $t->id; ?>" class="button button-small">Edit</a>
            &nbsp;
            <a href="?page=cias-topics&delete=<?php echo $t->id; ?>"
               class="button button-small"
               style="color:#dc2626"
               onclick="return confirm('Delete &quot;<?php echo esc_js($t->name); ?>&quot;?')">Delete</a>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

</div></div>
        <?php
    }

    /* ── Subtopics ── */
    public function page_subtopics() {
        global $wpdb;
        $db = new CIAS_DB();

        // Ensure subtopics table exists
        $stable = $wpdb->prefix . 'cias_subtopics';
        if ($wpdb->get_var("SHOW TABLES LIKE '$stable'") !== $stable) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $c = $wpdb->get_charset_collate();
            dbDelta("CREATE TABLE $stable (
                id INT AUTO_INCREMENT PRIMARY KEY,
                topic_id INT NOT NULL,
                name VARCHAR(200) NOT NULL,
                description TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                KEY topic_id (topic_id)
            ) $c;");
            echo '<div class="notice notice-warning"><p>Subtopics table was missing — created now.</p></div>';
        }

        if (isset($_POST['cias_save_subtopic']) && check_admin_referer('cias_subtopic')) {
            $name = sanitize_text_field($_POST['name'] ?? '');
            $tid  = intval($_POST['topic_id'] ?? 0);
            $id   = intval($_POST['subtopic_id'] ?? 0);

            if (empty($name)) {
                echo '<div class="notice notice-error"><p>Subtopic name is required.</p></div>';
            } elseif ($tid === 0) {
                echo '<div class="notice notice-error"><p>Please select a topic.</p></div>';
            } else {
                $data = ['name' => $name, 'topic_id' => $tid, 'description' => sanitize_textarea_field($_POST['description'] ?? '')];
                if ($id) {
                    $result = $wpdb->update($stable, $data, ['id' => $id]);
                } else {
                    $result = $wpdb->insert($stable, $data);
                }
                wp_cache_flush();
                if ($result === false) {
                    echo '<div class="notice notice-error"><p>❌ Database error: ' . esc_html($wpdb->last_error) . '</p></div>';
                } else {
                    echo '<div class="notice notice-success"><p>✅ Subtopic saved successfully!</p></div>';
                }
            }
        }

        if (isset($_GET['delete'])) {
            $wpdb->delete($stable, ['id' => intval($_GET['delete'])]);
            wp_cache_flush();
            echo '<div class="notice notice-success"><p>Subtopic deleted.</p></div>';
        }

        // Flush all cache layers before reading
        wp_suspend_cache_addition(true);
        $wpdb->flush();
        wp_cache_flush();

        $editing   = isset($_GET['edit']) ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $stable WHERE id=%d", intval($_GET['edit']))) : null;

        // Direct queries bypassing all cache
        $ttable    = $wpdb->prefix . 'cias_topics';
        $stable2   = $wpdb->prefix . 'cias_subtopics';
        $subjects  = $wpdb->prefix . 'cias_subjects';
        $questions = $wpdb->prefix . 'cias_questions';

        $topics = $wpdb->get_results(
            "SELECT t.*, s.name AS subject_name
             FROM $ttable t LEFT JOIN $subjects s ON t.subject_id=s.id
             ORDER BY s.name, t.name"
        );

        $subtopics = $wpdb->get_results(
            "SELECT st.*, t.name AS topic_name, s.name AS subject_name,
                (SELECT COUNT(*) FROM $questions WHERE subtopic_id=st.id AND status='published') AS question_count
             FROM $stable2 st
             LEFT JOIN $ttable t ON st.topic_id=t.id
             LEFT JOIN $subjects s ON t.subject_id=s.id
             ORDER BY s.name, t.name, st.name"
        );

        wp_suspend_cache_addition(false);
        ?>
<div class="wrap">
<h1 style="display:flex;align-items:center;gap:12px">Subtopics
  <span style="font-size:13px;font-weight:400;background:#f0eeff;color:#6C63FF;padding:3px 12px;border-radius:99px"><?php echo count($subtopics); ?> total</span>
</h1>

<?php if (empty($topics)): ?>
<div class="notice notice-warning"><p>⚠️ No topics found. <a href="?page=cias-topics">Add topics first</a> before creating subtopics.</p></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:320px 1fr;gap:24px;margin-top:16px;align-items:start">

  <!-- Form -->
  <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px">
    <h3 style="margin:0 0 16px;font-size:15px"><?php echo $editing ? '✏️ Edit Subtopic' : '➕ Add Subtopic'; ?></h3>
    <form method="post"><?php wp_nonce_field('cias_subtopic'); ?>
    <input type="hidden" name="subtopic_id" value="<?php echo $editing ? intval($editing->id) : 0; ?>">

    <div style="margin-bottom:14px">
      <label style="display:block;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin-bottom:5px">Topic <span style="color:red">*</span></label>
      <select name="topic_id" required style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:14px">
        <option value="">— Select topic —</option>
        <?php foreach($topics as $t): ?>
        <option value="<?php echo $t->id; ?>" <?php selected(($editing->topic_id ?? 0), $t->id); ?>>
          <?php echo esc_html(($t->subject_name ?? '') . ' › ' . $t->name); ?>
        </option>
        <?php endforeach; ?>
      </select>
      <p style="font-size:11px;color:#9ca3af;margin:4px 0 0"><?php echo count($topics); ?> topic(s) found</p>
    </div>

    <div style="margin-bottom:14px">
      <label style="display:block;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin-bottom:5px">Subtopic Name <span style="color:red">*</span></label>
      <input type="text" name="name" value="<?php echo esc_attr($editing->name ?? ''); ?>"
             style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:14px"
             required placeholder="e.g. Article 32 — Writs">
    </div>

    <div style="margin-bottom:16px">
      <label style="display:block;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin-bottom:5px">Description <span style="font-weight:400;color:#9ca3af">(optional)</span></label>
      <textarea name="description" rows="2"
                style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;resize:vertical"
                placeholder="Brief description"><?php echo esc_textarea($editing->description ?? ''); ?></textarea>
    </div>

    <div style="display:flex;gap:8px">
      <input type="submit" name="cias_save_subtopic" class="button button-primary"
             value="<?php echo $editing ? 'Update Subtopic' : 'Add Subtopic'; ?>"
             style="flex:1;padding:8px">
      <?php if($editing): ?><a href="?page=cias-subtopics" class="button" style="padding:8px 14px">Cancel</a><?php endif; ?>
    </div>
    </form>

    <div style="margin-top:16px;padding-top:14px;border-top:1px solid #f3f4f6;font-size:12px;color:#9ca3af">
      💡 Target: <strong>20–30 questions per subtopic</strong> for adaptive tests to work well.
    </div>
  </div>

  <!-- Subtopics List -->
  <div>
    <?php if (empty($subtopics)): ?>
    <div style="background:#f9fafb;border:1px dashed #d1d5db;border-radius:12px;padding:40px;text-align:center;color:#9ca3af">
      <div style="font-size:32px;margin-bottom:8px">📑</div>
      <p>No subtopics yet. Add your first subtopic using the form.</p>
    </div>
    <?php else:
      // Group by subject › topic
      $grouped = [];
      foreach ($subtopics as $st) {
          $key = ($st->subject_name ?? 'Uncategorised') . ' › ' . ($st->topic_name ?? '—');
          $grouped[$key][] = $st;
      }
    ?>
    <?php foreach($grouped as $group_key => $group_sts): ?>
    <div style="margin-bottom:20px">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#6b7280;padding:6px 0;border-bottom:2px solid #e5e7eb;margin-bottom:8px">
        📘 <?php echo esc_html($group_key); ?>
        <span style="font-weight:400;color:#9ca3af">(<?php echo count($group_sts); ?>)</span>
      </div>
      <table class="wp-list-table widefat fixed striped" style="border-radius:8px;overflow:hidden">
        <thead style="background:#f9fafb">
          <tr>
            <th style="width:45%">Subtopic</th>
            <th style="width:25%">Questions</th>
            <th style="width:30%">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($group_sts as $st):
          $qc = intval($st->question_count);
        ?>
        <tr>
          <td><strong><?php echo esc_html($st->name); ?></strong>
            <?php if($st->description): ?><br><small style="color:#9ca3af"><?php echo esc_html($st->description); ?></small><?php endif; ?>
          </td>
          <td>
            <?php if ($qc >= 20): ?>
              <span style="background:#f0fdf4;color:#166534;padding:3px 10px;border-radius:99px;font-size:12px">✅ <?php echo $qc; ?> questions</span>
            <?php elseif ($qc > 0): ?>
              <span style="background:#fef3c7;color:#92400e;padding:3px 10px;border-radius:99px;font-size:12px">⚠ <?php echo $qc; ?> / 20 needed</span>
            <?php else: ?>
              <span style="background:#fee2e2;color:#991b1b;padding:3px 10px;border-radius:99px;font-size:12px">❌ No questions yet</span>
            <?php endif; ?>
          </td>
          <td>
            <a href="?page=cias-subtopics&edit=<?php echo $st->id; ?>" class="button button-small">Edit</a>
            &nbsp;
            <a href="?page=cias-subtopics&delete=<?php echo $st->id; ?>"
               class="button button-small"
               style="color:#dc2626"
               onclick="return confirm('Delete &quot;<?php echo esc_js($st->name); ?>&quot;?')">Delete</a>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div></div>
        <?php
    }

    /* ── Questions ── */
    public function page_questions() {
        global $wpdb;
        $db = new CIAS_DB();

        if (isset($_POST['cias_save_q']) && check_admin_referer('cias_question')) {
            $qid  = intval($_POST['q_id'] ?? 0);
            $type = sanitize_text_field($_POST['question_type'] ?? 'standard');

            // Build statements JSON for statement-based questions
            $statements_json = null;
            if ($type === 'statement') {
                $stmts = array_filter(array_map('sanitize_textarea_field', $_POST['statements'] ?? []));
                if (!empty($stmts)) {
                    $statements_json = wp_json_encode(array_values($stmts));
                }
            }

            // Build tags string
            $tags_arr  = array_filter(array_map('sanitize_text_field', $_POST['question_tags'] ?? []));
            $tags_str  = implode(',', $tags_arr);

            $year = intval($_POST['year_asked'] ?? 0);

            $data = [
                'subject_id'     => intval($_POST['subject_id']),
                'topic_id'       => intval($_POST['topic_id']),
                'subtopic_id'    => intval($_POST['subtopic_id']),
                'question_type'  => $type,
                'question_text'  => wp_kses_post($_POST['question_text']),
                'statements'     => $statements_json,
                'question_tags'  => $tags_str,
                'year_asked'     => $year ?: null,
                'option_a'       => sanitize_text_field($_POST['option_a']),
                'option_b'       => sanitize_text_field($_POST['option_b']),
                'option_c'       => sanitize_text_field($_POST['option_c']),
                'option_d'       => sanitize_text_field($_POST['option_d']),
                'correct_option' => sanitize_text_field($_POST['correct_option']),
                'explanation'    => sanitize_textarea_field($_POST['explanation']),
                'difficulty'     => sanitize_text_field($_POST['difficulty']),
                'created_by'     => get_current_user_id(),
                'source'         => 'manual',
                'status'         => sanitize_text_field($_POST['q_status']),
            ];
            if ($qid) {
                $wpdb->update($wpdb->prefix.'cias_questions', $data, ['id'=>$qid]);
            } else {
                $wpdb->insert($wpdb->prefix.'cias_questions', $data);
            }
            wp_cache_flush();
            echo '<div class="notice notice-success"><p>✅ Question saved!</p></div>';
        }

        if (isset($_GET['delete'])) {
            $wpdb->delete($wpdb->prefix.'cias_questions', ['id'=>intval($_GET['delete'])]);
            wp_cache_flush();
            echo '<div class="notice notice-success"><p>Question deleted.</p></div>';
        }

        // ── AI question review: approve / reject ──
        if (isset($_GET['approve']) && current_user_can('manage_options')
            && check_admin_referer('cias_q_review')) {
            $wpdb->update($wpdb->prefix.'cias_questions',
                ['status'=>'published'], ['id'=>intval($_GET['approve'])]);
            wp_cache_flush();
            echo '<div class="notice notice-success"><p>✅ Question approved and published.</p></div>';
        }
        if (isset($_GET['reject']) && current_user_can('manage_options')
            && check_admin_referer('cias_q_review')) {
            $wpdb->update($wpdb->prefix.'cias_questions',
                ['status'=>'rejected'], ['id'=>intval($_GET['reject'])]);
            wp_cache_flush();
            echo '<div class="notice notice-success"><p>Question rejected (hidden from practice).</p></div>';
        }

        // ── Manual AI generation trigger (queues an async job) ──
        if (isset($_POST['cias_gen_questions']) && current_user_can('manage_options')
            && check_admin_referer('cias_gen_q')) {
            $gen_subject  = intval($_POST['gen_subject_id'] ?? 0);
            $gen_topic    = intval($_POST['gen_topic_id'] ?? 0);
            $gen_subtopic = intval($_POST['gen_subtopic_id'] ?? 0);
            $gen_count    = max(1, min(15, intval($_POST['gen_count'] ?? 5)));
            if ($gen_subject > 0 && class_exists('CIAS_DB_Phase_B')) {
                $job_id = CIAS_DB_Phase_B::push_job('generate_questions', [
                    'subject_id'  => $gen_subject,
                    'topic_id'    => $gen_topic,
                    'subtopic_id' => $gen_subtopic,
                    'count'       => $gen_count,
                ], 5, 0);
                if ($job_id) {
                    do_action('cias_generate_job_pushed', $job_id);
                    echo '<div class="notice notice-success"><p>🤖 Generation job #'.intval($job_id).' queued. Questions will appear here for review within a minute or two (once the worker runs).</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>Could not queue the generation job. Check the job queue.</p></div>';
                }
            } else {
                echo '<div class="notice notice-error"><p>Please choose a subject (and ensure Phase B is active).</p></div>';
            }
        }

        $action   = $_GET['action'] ?? 'list';
        $editing  = ($action === 'edit' && isset($_GET['id']))
            ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}cias_questions WHERE id=%d", intval($_GET['id'])))
            : null;

        $subjects      = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}cias_subjects ORDER BY name ASC");
        $all_topics    = $db->get_topics_with_subject();
        $all_subtopics = $db->get_subtopics_with_topic();

        // Decode existing statements for editing
        $existing_statements = [];
        if ($editing && $editing->statements) {
            $existing_statements = json_decode($editing->statements, true) ?: [];
        }
        $existing_tags = $editing ? array_filter(explode(',', $editing->question_tags ?? '')) : [];

        // Tag presets
        $subject_tags = ['Polity','Economy','History','Geography','Environment','Science & Tech','Ethics','Current Affairs','International Relations','Society'];
        $type_tags    = ['Static','Current','Conceptual','Factual','Analytical'];
        $prob_tags    = ['High probability','Medium probability','Low probability'];

        if ($action === 'add' || $action === 'edit'):
        $q_type = $editing->question_type ?? 'standard';
        ?>
<div class="wrap">
<h1 style="display:flex;align-items:center;gap:10px">
  <?php echo $editing ? 'Edit Question' : 'Add Question'; ?>
  <a href="?page=cias-questions" class="button" style="font-size:13px">← Back to list</a>
</h1>

<form method="post" id="cias-q-form" style="max-width:860px">
<?php wp_nonce_field('cias_question'); ?>
<input type="hidden" name="q_id" value="<?php echo $editing ? intval($editing->id) : 0; ?>">

<div style="display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start">

<!-- LEFT: Main form -->
<div>

<!-- Question Type -->
<div style="background:#f0eeff;border:1px solid #c4b5fd;border-radius:10px;padding:14px;margin-bottom:16px">
  <label style="font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:#6C63FF;display:block;margin-bottom:10px">Question Type</label>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php foreach([
      ['standard', '📝 Standard MCQ',    'Simple question with 4 options'],
      ['statement','📋 Statement Based', 'Stem + numbered statements (most common UPSC)'],
    ] as [$val,$label,$desc]): ?>
    <label style="flex:1;min-width:160px;border:2px solid <?php echo $q_type===$val?'#6C63FF':'#e5e7eb'; ?>;background:<?php echo $q_type===$val?'#fff':'#fafafa'; ?>;border-radius:10px;padding:10px 12px;cursor:pointer;display:flex;align-items:center;gap:8px">
      <input type="radio" name="question_type" value="<?php echo $val; ?>" <?php checked($q_type,$val); ?> onchange="qTypeChange(this.value)" style="accent-color:#6C63FF">
      <div><div style="font-size:13px;font-weight:600"><?php echo $label; ?></div><div style="font-size:11px;color:#6b7280"><?php echo $desc; ?></div></div>
    </label>
    <?php endforeach; ?>
  </div>
</div>

<!-- Taxonomy -->
<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:14px">
  <div>
    <label style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;display:block;margin-bottom:5px">Subject *</label>
    <select name="subject_id" id="q-subject" required style="width:100%;padding:7px 9px;border:1px solid #d1d5db;border-radius:8px;font-size:13px">
      <option value="">— Select —</option>
      <?php foreach($subjects as $s): ?><option value="<?php echo $s->id; ?>" <?php selected(($editing->subject_id??0),$s->id); ?>><?php echo esc_html($s->name); ?></option><?php endforeach; ?>
    </select>
  </div>
  <div>
    <label style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;display:block;margin-bottom:5px">Topic</label>
    <select name="topic_id" id="q-topic" style="width:100%;padding:7px 9px;border:1px solid #d1d5db;border-radius:8px;font-size:13px">
      <option value="">— Select —</option>
      <?php foreach($all_topics as $t): ?><option value="<?php echo $t->id; ?>" data-subject="<?php echo $t->subject_id; ?>" <?php selected(($editing->topic_id??0),$t->id); ?>><?php echo esc_html($t->name); ?></option><?php endforeach; ?>
    </select>
  </div>
  <div>
    <label style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;display:block;margin-bottom:5px">Subtopic</label>
    <select name="subtopic_id" id="q-subtopic" style="width:100%;padding:7px 9px;border:1px solid #d1d5db;border-radius:8px;font-size:13px">
      <option value="">— Optional —</option>
      <?php foreach($all_subtopics as $st): ?><option value="<?php echo $st->id; ?>" data-topic="<?php echo $st->topic_id; ?>" <?php selected(($editing->subtopic_id??0),$st->id); ?>><?php echo esc_html($st->name); ?></option><?php endforeach; ?>
    </select>
  </div>
</div>

<!-- Question Stem -->
<div style="margin-bottom:14px">
  <label style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;display:block;margin-bottom:5px">
    Question Stem *
    <span style="font-weight:400;color:#9ca3af;text-transform:none;letter-spacing:0" id="q-stem-hint">
      — The opening question text
    </span>
  </label>
  <textarea name="question_text" rows="3" required
    style="width:100%;padding:9px 11px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;resize:vertical;line-height:1.6"
    placeholder="e.g. With reference to Parliamentary Committees, which of the following is/are correct?"><?php echo esc_textarea($editing->question_text ?? ''); ?></textarea>
</div>

<!-- Statements (statement-based only) -->
<div id="q-statements-wrap" style="display:<?php echo $q_type==='statement'?'block':'none'; ?>;margin-bottom:14px">
  <label style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;display:block;margin-bottom:8px">
    Numbered Statements
    <span style="font-weight:400;color:#9ca3af;text-transform:none;letter-spacing:0">— Each one becomes a numbered item</span>
  </label>
  <div id="q-stmt-list">
    <?php
    $stmts_to_show = !empty($existing_statements) ? $existing_statements : ['','',''];
    foreach ($stmts_to_show as $i => $stmt): ?>
    <div class="q-stmt-row" style="display:flex;gap:8px;margin-bottom:8px;align-items:center">
      <span style="background:#6C63FF;color:#fff;width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0"><?php echo $i+1; ?></span>
      <input type="text" name="statements[]"
             value="<?php echo esc_attr($stmt); ?>"
             placeholder="Statement <?php echo $i+1; ?>"
             style="flex:1;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:13px">
      <?php if ($i >= 2): ?>
      <button type="button" onclick="removeStmt(this)" style="background:#fee2e2;color:#dc2626;border:none;border-radius:6px;padding:4px 8px;cursor:pointer;font-size:16px;line-height:1">×</button>
      <?php else: ?>
      <span style="width:30px"></span>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <div style="display:flex;gap:8px;align-items:center;margin-top:4px">
    <button type="button" onclick="addStmt()" style="background:#f0eeff;color:#6C63FF;border:1px solid #c4b5fd;border-radius:8px;padding:6px 14px;cursor:pointer;font-size:13px;font-weight:500">+ Add statement</button>
    <span style="font-size:11px;color:#9ca3af">Max 5 statements. "Select the correct answer:" is added automatically.</span>
  </div>
</div>

<!-- Options -->
<div style="margin-bottom:14px">
  <label style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;display:block;margin-bottom:8px">Answer Options *</label>
  <div style="display:grid;gap:8px">
    <?php foreach(['a'=>'A','b'=>'B','c'=>'C','d'=>'D'] as $key=>$label): ?>
    <div style="display:flex;gap:8px;align-items:center">
      <span style="width:28px;height:28px;border-radius:50%;background:#f3f4f6;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0"><?php echo $label; ?></span>
      <input type="text" name="option_<?php echo $key; ?>"
             value="<?php echo esc_attr($editing->{'option_'.$key} ?? ''); ?>"
             placeholder="Option <?php echo $label; ?>"
             required
             style="flex:1;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:13px">
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Correct Answer -->
<div style="margin-bottom:14px">
  <label style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;display:block;margin-bottom:8px">Correct Answer *</label>
  <div style="display:flex;gap:8px">
    <?php foreach(['a'=>'A','b'=>'B','c'=>'C','d'=>'D'] as $key=>$label): ?>
    <label style="flex:1;border:2px solid <?php echo ($editing->correct_option??'a')===$key?'#22c55e':'#e5e7eb'; ?>;border-radius:10px;padding:10px;text-align:center;cursor:pointer;background:<?php echo ($editing->correct_option??'a')===$key?'#f0fdf4':'#fff'; ?>">
      <input type="radio" name="correct_option" value="<?php echo $key; ?>" <?php checked(($editing->correct_option??'a'),$key); ?> style="display:none" onchange="highlightCorrect(this)">
      <span style="font-size:16px;font-weight:700;color:<?php echo ($editing->correct_option??'a')===$key?'#16a34a':'#6b7280'; ?>"><?php echo $label; ?></span>
    </label>
    <?php endforeach; ?>
  </div>
</div>

<!-- Explanation -->
<div style="margin-bottom:14px">
  <label style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;display:block;margin-bottom:5px">
    Explanation
    <span style="font-weight:400;color:#9ca3af;text-transform:none;letter-spacing:0">— Shown to students after submission</span>
  </label>
  <textarea name="explanation" rows="3"
    style="width:100%;padding:9px 11px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;resize:vertical;line-height:1.6"
    placeholder="Why is this the correct answer? Reference: ..."><?php echo esc_textarea($editing->explanation ?? ''); ?></textarea>
</div>

</div><!-- end LEFT -->

<!-- RIGHT: Metadata -->
<div>

<!-- Difficulty -->
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px;margin-bottom:12px">
  <label style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;display:block;margin-bottom:8px">Difficulty</label>
  <div style="display:flex;flex-direction:column;gap:5px">
    <?php foreach(['easy'=>['Easy','#22c55e','#f0fdf4'],'medium'=>['Medium','#f59e0b','#fffbeb'],'hard'=>['Hard','#ef4444','#fef2f2']] as $val=>[$lbl,$col,$bg]): ?>
    <label style="display:flex;align-items:center;gap:8px;padding:7px 10px;border:1px solid <?php echo ($editing->difficulty??'medium')===$val?$col:'#e5e7eb'; ?>;border-radius:8px;cursor:pointer;background:<?php echo ($editing->difficulty??'medium')===$val?$bg:'#fff'; ?>">
      <input type="radio" name="difficulty" value="<?php echo $val; ?>" <?php checked(($editing->difficulty??'medium'),$val); ?> style="accent-color:<?php echo $col; ?>">
      <span style="font-size:13px;font-weight:500;color:<?php echo ($editing->difficulty??'medium')===$val?$col:'#374151'; ?>"><?php echo $lbl; ?></span>
    </label>
    <?php endforeach; ?>
  </div>
</div>

<!-- Status + Year -->
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px;margin-bottom:12px">
  <div style="margin-bottom:10px">
    <label style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;display:block;margin-bottom:5px">Status</label>
    <select name="q_status" style="width:100%;padding:7px 9px;border:1px solid #d1d5db;border-radius:8px;font-size:13px">
      <option value="published" <?php selected(($editing->status??'published'),'published'); ?>>✅ Published</option>
      <option value="draft"     <?php selected(($editing->status??''),'draft'); ?>>📝 Draft (not visible)</option>
    </select>
  </div>
  <div>
    <label style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;display:block;margin-bottom:5px">Year Asked in UPSC</label>
    <input type="number" name="year_asked"
           value="<?php echo esc_attr($editing->year_asked ?? ''); ?>"
           placeholder="e.g. 2023"
           min="1990" max="2030"
           style="width:100%;padding:7px 9px;border:1px solid #d1d5db;border-radius:8px;font-size:13px">
    <p style="font-size:11px;color:#9ca3af;margin-top:4px">Leave blank if not a past year question</p>
  </div>
</div>

<!-- Tags -->
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px;margin-bottom:12px">
  <label style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;display:block;margin-bottom:8px">Question Tags</label>

  <div style="margin-bottom:8px">
    <div style="font-size:11px;color:#6b7280;margin-bottom:4px">Subject tags</div>
    <div style="display:flex;flex-wrap:wrap;gap:4px">
      <?php foreach($subject_tags as $tag): $checked = in_array($tag,$existing_tags); ?>
      <label style="padding:3px 9px;border-radius:99px;border:1px solid <?php echo $checked?'#6C63FF':'#d1d5db'; ?>;background:<?php echo $checked?'#f0eeff':'#f9fafb'; ?>;cursor:pointer;font-size:11px;font-weight:500;color:<?php echo $checked?'#6C63FF':'#6b7280'; ?>">
        <input type="checkbox" name="question_tags[]" value="<?php echo esc_attr($tag); ?>" <?php checked($checked); ?> style="display:none" onchange="toggleTagStyle(this)">
        <?php echo esc_html($tag); ?>
      </label>
      <?php endforeach; ?>
    </div>
  </div>

  <div style="margin-bottom:8px">
    <div style="font-size:11px;color:#6b7280;margin-bottom:4px">Type tags</div>
    <div style="display:flex;flex-wrap:wrap;gap:4px">
      <?php foreach($type_tags as $tag): $checked = in_array($tag,$existing_tags); ?>
      <label style="padding:3px 9px;border-radius:99px;border:1px solid <?php echo $checked?'#1D9E75':'#d1d5db'; ?>;background:<?php echo $checked?'#f0fdf4':'#f9fafb'; ?>;cursor:pointer;font-size:11px;font-weight:500;color:<?php echo $checked?'#1D9E75':'#6b7280'; ?>">
        <input type="checkbox" name="question_tags[]" value="<?php echo esc_attr($tag); ?>" <?php checked($checked); ?> style="display:none" onchange="toggleTagStyle(this)">
        <?php echo esc_html($tag); ?>
      </label>
      <?php endforeach; ?>
    </div>
  </div>

  <div>
    <div style="font-size:11px;color:#6b7280;margin-bottom:4px">Probability tags</div>
    <div style="display:flex;flex-wrap:wrap;gap:4px">
      <?php foreach($prob_tags as $tag): $checked = in_array($tag,$existing_tags); ?>
      <label style="padding:3px 9px;border-radius:99px;border:1px solid <?php echo $checked?'#f59e0b':'#d1d5db'; ?>;background:<?php echo $checked?'#fffbeb':'#f9fafb'; ?>;cursor:pointer;font-size:11px;font-weight:500;color:<?php echo $checked?'#92400e':'#6b7280'; ?>">
        <input type="checkbox" name="question_tags[]" value="<?php echo esc_attr($tag); ?>" <?php checked($checked); ?> style="display:none" onchange="toggleTagStyle(this)">
        <?php echo esc_html($tag); ?>
      </label>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- Save button -->
<button type="submit" name="cias_save_q" class="button button-primary"
        style="width:100%;padding:12px;font-size:15px;border-radius:10px">
  💾 Save Question
</button>
</div><!-- end RIGHT -->

</div><!-- end grid -->
</form>

<script>
function qTypeChange(type) {
  document.getElementById('q-statements-wrap').style.display = type === 'statement' ? 'block' : 'none';
  document.getElementById('q-stem-hint').textContent = type === 'statement'
    ? '— The opening question (before numbered statements)'
    : '— The full question text';
  // Update type card borders
  document.querySelectorAll('input[name="question_type"]').forEach(function(r) {
    var label = r.closest('label');
    label.style.borderColor = r.checked ? '#6C63FF' : '#e5e7eb';
    label.style.background  = r.checked ? '#fff' : '#fafafa';
  });
}

var stmtCount = <?php echo max(count($stmts_to_show ?? []), 3); ?>;

function addStmt() {
  if (stmtCount >= 5) { alert('Maximum 5 statements allowed.'); return; }
  stmtCount++;
  var div = document.createElement('div');
  div.className = 'q-stmt-row';
  div.style.cssText = 'display:flex;gap:8px;margin-bottom:8px;align-items:center';
  div.innerHTML = '<span style="background:#6C63FF;color:#fff;width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0">' + stmtCount + '</span>' +
    '<input type="text" name="statements[]" placeholder="Statement ' + stmtCount + '" style="flex:1;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:13px">' +
    '<button type="button" onclick="removeStmt(this)" style="background:#fee2e2;color:#dc2626;border:none;border-radius:6px;padding:4px 8px;cursor:pointer;font-size:16px;line-height:1">×</button>';
  document.getElementById('q-stmt-list').appendChild(div);
}

function removeStmt(btn) {
  btn.closest('.q-stmt-row').remove();
  // Renumber
  stmtCount = 0;
  document.querySelectorAll('.q-stmt-row').forEach(function(row) {
    stmtCount++;
    row.querySelector('span').textContent = stmtCount;
    var inp = row.querySelector('input');
    inp.placeholder = 'Statement ' + stmtCount;
  });
}

function highlightCorrect(radio) {
  document.querySelectorAll('input[name="correct_option"]').forEach(function(r) {
    var label = r.closest('label');
    var colors = {a:['#22c55e','#f0fdf4'],b:['#22c55e','#f0fdf4'],c:['#22c55e','#f0fdf4'],d:['#22c55e','#f0fdf4']};
    var col = colors[r.value][0];
    var bg  = colors[r.value][1];
    label.style.borderColor = r.checked ? col : '#e5e7eb';
    label.style.background  = r.checked ? bg : '#fff';
    label.querySelector('span').style.color = r.checked ? col : '#6b7280';
  });
}

function toggleTagStyle(cb) {
  var label = cb.closest('label');
  var colors = {'subject':'#6C63FF','type':'#1D9E75','prob':'#f59e0b'};
  // Detect tag group by order in DOM
  var groups = label.closest('div').parentElement.querySelectorAll('div');
  var groupIdx = Array.from(groups).indexOf(label.closest('div'));
  var col = groupIdx === 0 ? '#6C63FF' : groupIdx === 1 ? '#1D9E75' : '#f59e0b';
  var bg  = groupIdx === 0 ? '#f0eeff' : groupIdx === 1 ? '#f0fdf4' : '#fffbeb';
  var tc  = groupIdx === 0 ? '#6C63FF' : groupIdx === 1 ? '#1D9E75' : '#92400e';
  label.style.borderColor = cb.checked ? col : '#d1d5db';
  label.style.background  = cb.checked ? bg  : '#f9fafb';
  label.style.color       = cb.checked ? tc  : '#6b7280';
}

// Cascade dropdowns
(function(){
  var subjectSel  = document.getElementById('q-subject');
  var topicSel    = document.getElementById('q-topic');
  var subtopicSel = document.getElementById('q-subtopic');
  var allTopicOpts    = Array.from(topicSel.options).slice(1);
  var allSubtopicOpts = Array.from(subtopicSel.options).slice(1);
  function filterTopics() {
    var sid = subjectSel.value;
    topicSel.innerHTML = '<option value="">— Select —</option>';
    allTopicOpts.filter(o => !sid || o.dataset.subject == sid).forEach(o => topicSel.appendChild(o.cloneNode(true)));
    filterSubtopics();
  }
  function filterSubtopics() {
    var tid = topicSel.value;
    subtopicSel.innerHTML = '<option value="">— Optional —</option>';
    allSubtopicOpts.filter(o => !tid || o.dataset.topic == tid).forEach(o => subtopicSel.appendChild(o.cloneNode(true)));
  }
  subjectSel.addEventListener('change', filterTopics);
  topicSel.addEventListener('change', filterSubtopics);
  filterTopics();
  <?php if($editing && $editing->topic_id): ?>topicSel.value='<?php echo intval($editing->topic_id); ?>';filterSubtopics();<?php endif; ?>
  <?php if($editing && $editing->subtopic_id): ?>subtopicSel.value='<?php echo intval($editing->subtopic_id); ?>';<?php endif; ?>
})();
</script></div>

        <?php else:
            $filter_sub = intval($_GET['subject'] ?? 0);
            $filter_type= sanitize_text_field($_GET['qtype'] ?? '');
            $filter_status = sanitize_text_field($_GET['qstatus'] ?? '');
            $sql = "SELECT q.*, s.name AS subject_name FROM {$wpdb->prefix}cias_questions q LEFT JOIN {$wpdb->prefix}cias_subjects s ON q.subject_id=s.id WHERE 1=1";
            if ($filter_sub)    $sql .= $wpdb->prepare(" AND q.subject_id=%d", $filter_sub);
            if ($filter_type)   $sql .= $wpdb->prepare(" AND q.question_type=%s", $filter_type);
            if ($filter_status) $sql .= $wpdb->prepare(" AND q.status=%s", $filter_status);
            $sql .= " ORDER BY q.id DESC";
            $questions = $wpdb->get_results($sql);
            // Count AI questions awaiting review (for the banner)
            $pending_ai = intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cias_questions WHERE status='ai_pending_review'"));
            $type_labels = ['standard'=>'📝 Standard','statement'=>'📋 Statement','match'=>'🔀 Match','assertion'=>'⚖️ Assertion'];
        ?>
<div class="wrap">
<h1 style="display:flex;align-items:center;gap:10px">
  Questions
  <span style="font-size:13px;font-weight:400;background:#f0eeff;color:#6C63FF;padding:3px 12px;border-radius:99px"><?php echo count($questions); ?> total</span>
  <a href="?page=cias-questions&action=add" class="button button-primary" style="margin-left:auto">+ Add Question</a>
</h1>

<?php if ($pending_ai > 0): ?>
<div style="background:#fffbeb;border:1px solid #fcd34d;border-left:4px solid #f59e0b;border-radius:8px;padding:12px 16px;margin-bottom:14px;display:flex;align-items:center;gap:12px">
  <span style="font-size:20px">🤖</span>
  <div style="flex:1">
    <strong style="color:#92400e"><?php echo $pending_ai; ?> AI-generated question<?php echo $pending_ai === 1 ? '' : 's'; ?> awaiting your review</strong>
    <div style="font-size:12px;color:#b45309;margin-top:2px">These were auto-generated when the question bank ran short. They are hidden from students until you approve them.</div>
  </div>
  <a href="?page=cias-questions&qstatus=ai_pending_review" class="button" style="font-size:13px">Review now →</a>
</div>
<?php endif; ?>

<details style="margin-bottom:14px;border:1px solid #e5e7eb;border-radius:8px;padding:0 14px">
  <summary style="cursor:pointer;padding:12px 0;font-weight:600;color:#6C63FF">🤖 Generate practice questions with AI</summary>
  <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;padding:0 0 14px">
    <?php wp_nonce_field('cias_gen_q'); ?>
    <div>
      <label style="display:block;font-size:12px;color:#6b7280;margin-bottom:4px">Subject</label>
      <select name="gen_subject_id" required style="padding:6px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:13px">
        <option value="0">Select…</option>
        <?php foreach($subjects as $s): ?><option value="<?php echo $s->id; ?>"><?php echo esc_html($s->name); ?></option><?php endforeach; ?>
      </select>
    </div>
    <div>
      <label style="display:block;font-size:12px;color:#6b7280;margin-bottom:4px">Topic ID (optional)</label>
      <input type="number" name="gen_topic_id" value="0" min="0" style="width:120px;padding:6px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:13px">
    </div>
    <div>
      <label style="display:block;font-size:12px;color:#6b7280;margin-bottom:4px">How many</label>
      <select name="gen_count" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:13px">
        <option value="3">3</option><option value="5" selected>5</option><option value="10">10</option><option value="15">15</option>
      </select>
    </div>
    <button type="submit" name="cias_gen_questions" value="1" class="button button-primary">Queue generation →</button>
    <p style="width:100%;margin:8px 0 0;font-size:12px;color:#9ca3af">Questions are generated in the background and land here as <strong>pending review</strong>. They stay hidden from students until you approve them.</p>
  </form>
</details>

<div style="display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap">
  <select onchange="location='?page=cias-questions&subject='+this.value+'&qtype=<?php echo esc_attr($filter_type); ?>&qstatus=<?php echo esc_attr($filter_status); ?>'" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:13px">
    <option value="0">All subjects</option>
    <?php foreach($subjects as $s): ?><option value="<?php echo $s->id; ?>" <?php selected($filter_sub,$s->id); ?>><?php echo esc_html($s->name); ?></option><?php endforeach; ?>
  </select>
  <select onchange="location='?page=cias-questions&subject=<?php echo $filter_sub; ?>&qtype='+this.value+'&qstatus=<?php echo esc_attr($filter_status); ?>'" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:13px">
    <option value="">All types</option>
    <?php foreach($type_labels as $v=>$l): ?><option value="<?php echo $v; ?>" <?php selected($filter_type,$v); ?>><?php echo $l; ?></option><?php endforeach; ?>
  </select>
  <select onchange="location='?page=cias-questions&subject=<?php echo $filter_sub; ?>&qtype=<?php echo esc_attr($filter_type); ?>&qstatus='+this.value" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:13px">
    <option value="">All statuses</option>
    <option value="published" <?php selected($filter_status,'published'); ?>>Published</option>
    <option value="ai_pending_review" <?php selected($filter_status,'ai_pending_review'); ?>>AI — pending review</option>
    <option value="draft" <?php selected($filter_status,'draft'); ?>>Draft</option>
    <option value="rejected" <?php selected($filter_status,'rejected'); ?>>Rejected</option>
  </select>
</div>

<table class="wp-list-table widefat fixed striped" style="border-radius:10px;overflow:hidden">
  <thead style="background:#f9fafb">
    <tr>
      <th style="width:42%">Question</th>
      <th style="width:12%">Type</th>
      <th style="width:14%">Subject / Topic</th>
      <th style="width:10%">Difficulty</th>
      <th style="width:8%">Year</th>
      <th style="width:6%">Status</th>
      <th style="width:8%">Actions</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach($questions as $q):
    $tags = array_filter(explode(',', $q->question_tags ?? ''));
    $type_label = $type_labels[$q->question_type ?? 'standard'] ?? '📝 Standard';
    $stmts = $q->statements ? json_decode($q->statements, true) : [];
  ?>
  <tr>
    <td>
      <div style="font-size:13px;font-weight:500;line-height:1.4;margin-bottom:4px"><?php echo esc_html(mb_substr($q->question_text,0,90)); ?>…</div>
      <?php if (!empty($stmts)): ?>
      <div style="font-size:11px;color:#6b7280">📋 <?php echo count($stmts); ?> statements</div>
      <?php endif; ?>
      <?php if (!empty($tags)): ?>
      <div style="display:flex;gap:3px;flex-wrap:wrap;margin-top:4px">
        <?php foreach(array_slice($tags,0,4) as $tag): ?>
        <span style="background:#f0eeff;color:#6C63FF;padding:1px 7px;border-radius:99px;font-size:10px"><?php echo esc_html($tag); ?></span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php if ($q->year_asked): ?>
      <span style="font-size:10px;background:#fef3c7;color:#92400e;padding:1px 7px;border-radius:99px;margin-top:3px;display:inline-block">UPSC <?php echo intval($q->year_asked); ?></span>
      <?php endif; ?>
    </td>
    <td><span style="font-size:11px"><?php echo $type_label; ?></span></td>
    <td style="font-size:12px"><?php echo esc_html($q->subject_name??'—'); ?></td>
    <td><span style="font-size:11px;padding:2px 8px;border-radius:99px;background:<?php echo $q->difficulty==='easy'?'#dcfce7':($q->difficulty==='hard'?'#fee2e2':'#fef3c7'); ?>;color:<?php echo $q->difficulty==='easy'?'#166534':($q->difficulty==='hard'?'#991b1b':'#92400e'); ?>"><?php echo esc_html($q->difficulty); ?></span></td>
    <td style="font-size:12px"><?php echo $q->year_asked ? intval($q->year_asked) : '—'; ?></td>
    <td><span style="font-size:11px;padding:2px 8px;border-radius:99px;background:<?php
      echo $q->status==='published'?'#dcfce7':($q->status==='ai_pending_review'?'#fef3c7':($q->status==='rejected'?'#fee2e2':'#f3f4f6')); ?>;color:<?php
      echo $q->status==='published'?'#166534':($q->status==='ai_pending_review'?'#92400e':($q->status==='rejected'?'#991b1b':'#6b7280')); ?>"><?php
      echo $q->status==='ai_pending_review'?'AI · pending':esc_html($q->status); ?></span>
      <?php if (($q->source ?? 'manual')==='ai'): ?>
      <div style="font-size:10px;color:#7c3aed;margin-top:3px">🤖 AI-generated</div>
      <?php endif; ?>
    </td>
    <td>
      <?php if ($q->status==='ai_pending_review'): ?>
      <a href="<?php echo wp_nonce_url('?page=cias-questions&approve='.$q->id, 'cias_q_review'); ?>" style="font-size:12px;color:#166534;font-weight:600">Approve</a> |
      <a href="<?php echo wp_nonce_url('?page=cias-questions&reject='.$q->id, 'cias_q_review'); ?>" style="font-size:12px;color:#dc2626" onclick="return confirm('Reject this AI question? It will be hidden from practice.')">Reject</a><br>
      <?php endif; ?>
      <a href="?page=cias-questions&action=edit&id=<?php echo $q->id; ?>" style="font-size:12px">Edit</a> |
      <a href="?page=cias-questions&delete=<?php echo $q->id; ?>" style="font-size:12px;color:#dc2626" onclick="return confirm('Delete this question?')">Del</a>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if(empty($questions)): ?>
  <tr><td colspan="7" style="text-align:center;padding:30px;color:#9ca3af">No questions yet. <a href="?page=cias-questions&action=add">Add your first question →</a></td></tr>
  <?php endif; ?>
  </tbody>
</table></div>
        <?php endif;
    }

    /* ── Tests ── */
    public function page_tests() {
        global $wpdb;
        $db = new CIAS_DB();

        // ── Generate PIN — accessible by admin AND teachers ──
        if (isset($_GET['gen_pin']) && (current_user_can('manage_options') || current_user_can('cias_create_tests'))) {
            $tid    = intval($_GET['gen_pin']);
            $mins   = intval($_GET['pin_mins'] ?? 60);
            $result = $db->generate_test_pin($tid, $mins);
            echo '<div class="notice notice-success"><p>✅ PIN <strong style="font-size:20px;letter-spacing:4px;color:#6C63FF">' . $result['pin'] . '</strong> — valid for ' . $mins . ' minutes (expires ' . date('H:i', strtotime($result['expires_at'])) . ' IST). Write it on the board for students in class.</p></div>';
        }

        // ── Clear PIN ──
        if (isset($_GET['clear_pin']) && (current_user_can('manage_options') || current_user_can('cias_create_tests'))) {
            $db->clear_test_pin(intval($_GET['clear_pin']));
            echo '<div class="notice notice-success"><p>✅ PIN cleared.</p></div>';
        }

        // ── Kick student ──
        if (isset($_GET['kick']) && isset($_GET['tid']) && (current_user_can('manage_options') || current_user_can('cias_create_tests'))) {
            $db->kick_student_from_test(intval($_GET['tid']), intval($_GET['kick']));
            echo '<div class="notice notice-success"><p>✅ Student removed from active session.</p></div>';
        }

        // ── Save test ──
        if ((isset($_POST['cias_save_test']) || isset($_POST['save_as'])) && check_admin_referer('cias_test')) {
            $tid     = intval($_POST['test_id'] ?? 0);
            $save_as = sanitize_text_field($_POST['save_as'] ?? 'draft');

            $data = [
                'title'          => sanitize_text_field($_POST['title']),
                'subject_id'     => intval($_POST['subject_id']),
                'description'    => sanitize_textarea_field($_POST['description']),
                'time_limit'     => intval($_POST['time_limit']),
                'scheduled_date' => sanitize_text_field($_POST['scheduled_date']) ?: null,
                'end_date'       => sanitize_text_field($_POST['end_date']) ?: null,
                'status'         => $save_as === 'publish' ? 'published' : 'draft',
                'test_mode'      => sanitize_text_field($_POST['test_mode'] ?? 'online'),
                'teacher_id'     => intval($_POST['teacher_id'] ?? 0),
                'created_by'     => get_current_user_id(),
            ];

            $selected_qs      = array_map('intval', $_POST['question_ids'] ?? []);
            $selected_batches = array_map('intval', $_POST['batch_ids'] ?? []);

            // Duplicate check warning
            $duplicates = $db->check_duplicate_questions($selected_qs);
            $tid = $tid ? $db->update_test($tid, $data, $selected_qs, $selected_batches)
                        : $db->create_test($data, $selected_qs, $selected_batches);

            $status_label = $save_as === 'publish' ? 'Published ✅' : 'Saved as Draft 📝';
            echo '<div class="notice notice-success"><p>' . $status_label . ' — Test saved successfully!</p></div>';
            if (!empty($duplicates)) {
                echo '<div class="notice notice-warning"><p>⚠️ <strong>Duplicate/similar questions detected:</strong><br>';
                foreach ($duplicates as $d) {
                    echo "Q#{$d['q1']} and Q#{$d['q2']} are {$d['similarity']}% similar — consider removing one.<br>";
                }
                echo '</p></div>';
            }
        }

        if (isset($_GET['delete'])) {
            $db->delete_test(intval($_GET['delete']));
            echo '<div class="notice notice-success"><p>Test deleted.</p></div>';
        }

        $action  = $_GET['action'] ?? 'list';
        $editing = ($action === 'edit' && isset($_GET['id'])) ? $db->get_test_full(intval($_GET['id'])) : null;
        $subjects = $db->get_all('subjects');
        $batches  = $db->get_batches_with_course();
        $teachers = get_users(['role__in' => ['cias_teacher', 'administrator'], 'orderby' => 'display_name']);
        $all_topics    = $db->get_topics_with_subject();
        $all_subtopics = $db->get_subtopics_with_topic();

        if ($action === 'add' || $action === 'edit'):
            // Question filters
            $filter_sub      = intval($_GET['filter_sub']      ?? ($editing->subject_id ?? 0));
            $filter_topic    = intval($_GET['filter_topic']    ?? 0);
            $filter_subtopic = intval($_GET['filter_subtopic'] ?? 0);
            $filter_date_from= sanitize_text_field($_GET['filter_date_from'] ?? '');
            $filter_date_to  = sanitize_text_field($_GET['filter_date_to']   ?? '');
            $filter_qtype    = sanitize_text_field($_GET['filter_qtype']     ?? '');

            $all_questions = $db->get_questions_list($filter_sub, 'published', [
                'topic_id'    => $filter_topic,
                'subtopic_id' => $filter_subtopic,
                'date_from'   => $filter_date_from,
                'date_to'     => $filter_date_to,
                'qtype'       => $filter_qtype,
            ]);

            $selected_q_ids = $editing ? $db->get_test_question_ids($editing->id) : [];
            $selected_b_ids = $editing ? $db->get_test_batch_ids($editing->id)    : [];
            $q_type_labels  = ['standard' => '📝 Standard', 'statement' => '📋 Statement'];
        ?>
<div class="wrap">
<h1 style="display:flex;align-items:center;gap:10px">
  <?php echo $editing ? 'Edit Test' : 'Create Test'; ?>
  <a href="?page=cias-test-list" class="button" style="font-size:13px">← Back to Tests</a>
</h1>

<form method="post" id="cias-test-form">
<?php wp_nonce_field('cias_test'); ?>
<input type="hidden" name="test_id" value="<?php echo $editing ? intval($editing->id) : 0; ?>">

<div style="display:grid;grid-template-columns:360px 1fr;gap:20px;align-items:start">

<!-- LEFT: Test settings -->
<div>

  <!-- Basic info -->
  <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px;margin-bottom:14px">
    <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin-bottom:12px">Test Details</div>

    <div style="margin-bottom:12px">
      <label style="font-size:12px;font-weight:600;color:#6b7280;display:block;margin-bottom:4px">Test Title *</label>
      <input type="text" name="title" value="<?php echo esc_attr($editing->title ?? ''); ?>" required
             style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:14px">
    </div>

    <div style="margin-bottom:12px">
      <label style="font-size:12px;font-weight:600;color:#6b7280;display:block;margin-bottom:4px">Test Mode *</label>
      <div style="display:flex;gap:8px">
        <?php foreach(['online'=>['🌐 Online','Students take on device'],'offline'=>['📝 Offline / Classroom','Physical test in class']] as $val=>[$lbl,$desc]): ?>
        <label style="flex:1;border:2px solid <?php echo ($editing->test_mode??'online')===$val?'#6C63FF':'#e5e7eb'; ?>;background:<?php echo ($editing->test_mode??'online')===$val?'#f0eeff':'#fafafa'; ?>;border-radius:10px;padding:10px;cursor:pointer">
          <input type="radio" name="test_mode" value="<?php echo $val; ?>" <?php checked(($editing->test_mode??'online'),$val); ?> onchange="testModeChange(this.value)" style="display:none">
          <div style="font-size:13px;font-weight:600"><?php echo $lbl; ?></div>
          <div style="font-size:11px;color:#6b7280"><?php echo $desc; ?></div>
        </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div style="margin-bottom:12px">
      <label style="font-size:12px;font-weight:600;color:#6b7280;display:block;margin-bottom:4px">Subject</label>
      <select name="subject_id" style="width:100%;padding:7px 9px;border:1px solid #d1d5db;border-radius:8px;font-size:13px">
        <option value="0">— All Subjects —</option>
        <?php foreach($subjects as $s): ?><option value="<?php echo $s->id; ?>" <?php selected(($editing->subject_id??0),$s->id); ?>><?php echo esc_html($s->name); ?></option><?php endforeach; ?>
      </select>
    </div>

    <div style="margin-bottom:12px">
      <label style="font-size:12px;font-weight:600;color:#6b7280;display:block;margin-bottom:4px">Description</label>
      <textarea name="description" rows="2" style="width:100%;padding:7px 9px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;resize:vertical"><?php echo esc_textarea($editing->description ?? ''); ?></textarea>
    </div>

    <div style="margin-bottom:12px">
      <label style="font-size:12px;font-weight:600;color:#6b7280;display:block;margin-bottom:4px">Time Limit</label>
      <div style="display:flex;align-items:center;gap:8px">
        <input type="number" name="time_limit" value="<?php echo intval($editing->time_limit ?? 0); ?>" min="0" max="360"
               style="width:80px;padding:7px 9px;border:1px solid #d1d5db;border-radius:8px;font-size:13px">
        <span style="font-size:13px;color:#6b7280">minutes (0 = no limit)</span>
      </div>
    </div>

    <div style="margin-bottom:12px">
      <label style="font-size:12px;font-weight:600;color:#6b7280;display:block;margin-bottom:4px">Start Date &amp; Time</label>
      <input type="datetime-local" name="scheduled_date" value="<?php echo esc_attr(str_replace(' ','T',$editing->scheduled_date ?? '')); ?>"
             style="width:100%;padding:7px 9px;border:1px solid #d1d5db;border-radius:8px;font-size:13px">
    </div>

    <div style="margin-bottom:12px">
      <label style="font-size:12px;font-weight:600;color:#6b7280;display:block;margin-bottom:4px">End Date &amp; Time</label>
      <input type="datetime-local" name="end_date" value="<?php echo esc_attr(str_replace(' ','T',$editing->end_date ?? '')); ?>"
             style="width:100%;padding:7px 9px;border:1px solid #d1d5db;border-radius:8px;font-size:13px">
      <div style="font-size:11px;color:#9ca3af;margin-top:3px">After this time, test closes automatically</div>
    </div>

    <div style="margin-bottom:0">
      <label style="font-size:12px;font-weight:600;color:#6b7280;display:block;margin-bottom:4px">
        Conducted by (Teacher)
        <span style="font-weight:400;color:#9ca3af"> — admin reference only, not shown to students</span>
      </label>
      <select name="teacher_id" style="width:100%;padding:7px 9px;border:1px solid #d1d5db;border-radius:8px;font-size:13px">
        <option value="0">— Select teacher —</option>
        <?php foreach($teachers as $t): ?>
        <option value="<?php echo $t->ID; ?>" <?php selected(($editing->teacher_id??0),$t->ID); ?>><?php echo esc_html($t->display_name); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <!-- Batch assignment -->
  <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px;margin-bottom:14px">
    <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin-bottom:10px">Assign to Batches</div>
    <div style="max-height:160px;overflow-y:auto">
      <?php foreach($batches as $b): ?>
      <label style="display:flex;align-items:center;gap:8px;padding:5px 0;font-size:13px;cursor:pointer;border-bottom:0.5px solid #f3f4f6">
        <input type="checkbox" name="batch_ids[]" value="<?php echo $b->id; ?>" <?php echo in_array($b->id,$selected_b_ids)?'checked':''; ?>>
        <?php echo esc_html(($b->course_name??'').' — '.$b->name); ?>
      </label>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Save buttons -->
  <div style="display:flex;gap:8px">
    <button type="submit" name="save_as" value="draft" class="button" style="flex:1;padding:10px;font-size:13px">
      📝 Save as Draft
    </button>
    <button type="submit" name="save_as" value="publish" class="button button-primary" style="flex:1;padding:10px;font-size:14px;font-weight:600">
      ✅ Publish Test
    </button>
  </div>

</div>

<!-- RIGHT: Question picker -->
<div>
  <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
      <div style="font-size:13px;font-weight:700;color:#374151">
        Select Questions
        <span id="q-count-badge" style="background:#6C63FF;color:#fff;padding:2px 10px;border-radius:99px;font-size:11px;margin-left:6px"><?php echo count($selected_q_ids); ?> selected</span>
      </div>
      <button type="button" onclick="toggleAllQ()" class="button button-small">Toggle all</button>
    </div>

    <!-- Filters (not a form — avoids nested form HTML issue) -->
    <div id="q-filter-form" style="background:#f9fafb;border-radius:8px;padding:12px;margin-bottom:10px">
      <input type="hidden" id="ff-page" value="cias-test-list">
      <input type="hidden" id="ff-action" value="<?php echo $action; ?>">
      <input type="hidden" id="ff-id" value="<?php echo $editing ? intval($editing->id) : ''; ?>">
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:8px">
        <div>
          <label style="font-size:11px;color:#6b7280;display:block;margin-bottom:3px">Subject</label>
          <select id="ff-sub" onchange="applyQFilter()" style="width:100%;padding:5px 7px;border:1px solid #d1d5db;border-radius:6px;font-size:12px">
            <option value="0">All subjects</option>
            <?php foreach($subjects as $s): ?><option value="<?php echo $s->id; ?>" <?php selected($filter_sub,$s->id); ?>><?php echo esc_html($s->name); ?></option><?php endforeach; ?>
          </select>
        </div>
        <div>
          <label style="font-size:11px;color:#6b7280;display:block;margin-bottom:3px">Topic</label>
          <select id="ff-topic" onchange="applyQFilter()" style="width:100%;padding:5px 7px;border:1px solid #d1d5db;border-radius:6px;font-size:12px">
            <option value="0">All topics</option>
            <?php foreach($all_topics as $t): ?><option value="<?php echo $t->id; ?>" <?php selected($filter_topic,$t->id); ?>><?php echo esc_html($t->name); ?></option><?php endforeach; ?>
          </select>
        </div>
        <div>
          <label style="font-size:11px;color:#6b7280;display:block;margin-bottom:3px">Subtopic</label>
          <select id="ff-subtopic" onchange="applyQFilter()" style="width:100%;padding:5px 7px;border:1px solid #d1d5db;border-radius:6px;font-size:12px">
            <option value="0">All subtopics</option>
            <?php foreach($all_subtopics as $st): ?><option value="<?php echo $st->id; ?>" <?php selected($filter_subtopic,$st->id); ?>><?php echo esc_html($st->name); ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px">
        <div>
          <label style="font-size:11px;color:#6b7280;display:block;margin-bottom:3px">Type</label>
          <select id="ff-qtype" onchange="applyQFilter()" style="width:100%;padding:5px 7px;border:1px solid #d1d5db;border-radius:6px;font-size:12px">
            <option value="">All types</option>
            <option value="standard"  <?php selected($filter_qtype,'standard'); ?>>📝 Standard</option>
            <option value="statement" <?php selected($filter_qtype,'statement'); ?>>📋 Statement</option>
          </select>
        </div>
        <div>
          <label style="font-size:11px;color:#6b7280;display:block;margin-bottom:3px">Added from</label>
          <input type="date" id="ff-date-from" value="<?php echo esc_attr($filter_date_from); ?>" onchange="applyQFilter()" style="width:100%;padding:5px 7px;border:1px solid #d1d5db;border-radius:6px;font-size:12px">
        </div>
        <div>
          <label style="font-size:11px;color:#6b7280;display:block;margin-bottom:3px">Added to</label>
          <input type="date" id="ff-date-to" value="<?php echo esc_attr($filter_date_to); ?>" onchange="applyQFilter()" style="width:100%;padding:5px 7px;border:1px solid #d1d5db;border-radius:6px;font-size:12px">
        </div>
      </div>
      <?php if($filter_sub || $filter_topic || $filter_subtopic || $filter_qtype || $filter_date_from || $filter_date_to): ?>
      <div style="margin-top:8px"><a href="?page=cias-test-list&action=<?php echo $action; ?><?php echo $editing?'&id='.$editing->id:''; ?>" style="font-size:12px;color:#dc2626">✕ Clear filters</a></div>
      <?php endif; ?>
    </div>

    <div style="font-size:11px;color:#6b7280;margin-bottom:6px">Showing <?php echo count($all_questions); ?> questions — newest first. Check boxes to add to test.</div>

    <!-- Question list -->
    <div style="max-height:460px;overflow-y:auto;border:1px solid #f3f4f6;border-radius:8px">
      <?php foreach($all_questions as $q):
        $selected = in_array($q->id, $selected_q_ids);
        $tags = array_filter(explode(',', $q->question_tags ?? ''));
        $stmts = $q->statements ? json_decode($q->statements, true) : [];
      ?>
      <label style="display:flex;gap:10px;padding:10px 12px;border-bottom:0.5px solid #f3f4f6;cursor:pointer;background:<?php echo $selected?'#f0eeff':'#fff'; ?>;align-items:flex-start"
             onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background='<?php echo $selected?'#f0eeff':'#fff'; ?>'">
        <input type="checkbox" name="question_ids[]" value="<?php echo $q->id; ?>"
               class="q-cb" <?php echo $selected?'checked':''; ?>
               onchange="updateQCount()" style="margin-top:3px;accent-color:#6C63FF">
        <div style="flex:1">
          <div style="font-size:13px;font-weight:500;line-height:1.4;margin-bottom:3px"><?php echo esc_html(mb_substr($q->question_text, 0, 100)); ?>…</div>
          <div style="display:flex;gap:4px;flex-wrap:wrap;margin-top:3px">
            <?php if($q->subject_name): ?><span style="background:#f0eeff;color:#6C63FF;padding:1px 7px;border-radius:99px;font-size:10px"><?php echo esc_html($q->subject_name); ?></span><?php endif; ?>
            <?php if($q->topic_name): ?><span style="background:#f3f4f6;color:#6b7280;padding:1px 7px;border-radius:99px;font-size:10px"><?php echo esc_html($q->topic_name); ?></span><?php endif; ?>
            <?php if(!empty($stmts)): ?><span style="background:#fef3c7;color:#92400e;padding:1px 7px;border-radius:99px;font-size:10px">📋 <?php echo count($stmts); ?> statements</span><?php endif; ?>
            <span style="background:<?php echo $q->difficulty==='easy'?'#dcfce7':($q->difficulty==='hard'?'#fee2e2':'#fef3c7'); ?>;color:<?php echo $q->difficulty==='easy'?'#166534':($q->difficulty==='hard'?'#991b1b':'#92400e'); ?>;padding:1px 7px;border-radius:99px;font-size:10px"><?php echo esc_html($q->difficulty); ?></span>
            <?php if($q->year_asked): ?><span style="background:#fef3c7;color:#92400e;padding:1px 7px;border-radius:99px;font-size:10px">UPSC <?php echo intval($q->year_asked); ?></span><?php endif; ?>
            <span style="color:#9ca3af;font-size:10px;margin-left:auto">Added <?php echo date('d M Y', strtotime($q->created_at)); ?></span>
          </div>
        </div>
      </label>
      <?php endforeach; ?>
      <?php if(empty($all_questions)): ?>
      <div style="text-align:center;padding:30px;color:#9ca3af">No questions match filters. <a href="?page=cias-questions&action=add">Add questions →</a></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Duplicate warning (live) -->
  <div id="duplicate-warning" style="display:none;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;margin-top:10px;font-size:13px;color:#92400e">
    ⚠️ <strong>Tip:</strong> After saving, the system will check selected questions for duplicates and warn you.
  </div>
</div>
</div>
</form>

<script>
function updateQCount() {
  var c = document.querySelectorAll('.q-cb:checked').length;
  document.getElementById('q-count-badge').textContent = c + ' selected';
}
function applyQFilter() {
  var page    = document.getElementById('ff-page')    ? document.getElementById('ff-page').value    : 'cias-test-list';
  var action  = document.getElementById('ff-action')  ? document.getElementById('ff-action').value  : 'add';
  var id      = document.getElementById('ff-id')      ? document.getElementById('ff-id').value      : '';
  var sub     = document.getElementById('ff-sub')     ? document.getElementById('ff-sub').value     : '0';
  var topic   = document.getElementById('ff-topic')   ? document.getElementById('ff-topic').value   : '0';
  var subtop  = document.getElementById('ff-subtopic')? document.getElementById('ff-subtopic').value: '0';
  var qtype   = document.getElementById('ff-qtype')   ? document.getElementById('ff-qtype').value   : '';
  var dfrom   = document.getElementById('ff-date-from')? document.getElementById('ff-date-from').value: '';
  var dto     = document.getElementById('ff-date-to') ? document.getElementById('ff-date-to').value : '';

  var url = '?page=' + page + '&action=' + action;
  if (id)     url += '&id='             + encodeURIComponent(id);
  if (sub)    url += '&filter_sub='     + encodeURIComponent(sub);
  if (topic)  url += '&filter_topic='   + encodeURIComponent(topic);
  if (subtop) url += '&filter_subtopic='+ encodeURIComponent(subtop);
  if (qtype)  url += '&filter_qtype='   + encodeURIComponent(qtype);
  if (dfrom)  url += '&filter_date_from='+ encodeURIComponent(dfrom);
  if (dto)    url += '&filter_date_to=' + encodeURIComponent(dto);

  window.location.href = url;
}

function toggleAllQ() {
  var cbs = document.querySelectorAll('.q-cb');
  var anyChecked = Array.from(cbs).some(function(cb){ return cb.checked; });
  cbs.forEach(function(cb){ cb.checked = !anyChecked; });
  updateQCount();
}
function testModeChange(val) {
  document.querySelectorAll('input[name="test_mode"]').forEach(function(r){
    var label = r.closest('label');
    label.style.borderColor = r.checked ? '#6C63FF' : '#e5e7eb';
    label.style.background  = r.checked ? '#f0eeff' : '#fafafa';
  });
}
</script></div>

        <?php else:
            $tests = $db->get_tests_list();
        ?>
<div class="wrap">
<h1 style="display:flex;align-items:center;gap:10px">
  Tests
  <span style="font-size:13px;font-weight:400;background:#f0eeff;color:#6C63FF;padding:3px 12px;border-radius:99px"><?php echo count($tests); ?> total</span>
  <a href="?page=cias-test-list&action=add" class="button button-primary" style="margin-left:auto">+ Create Test</a>
</h1>

<table class="wp-list-table widefat fixed striped" style="border-radius:10px;overflow:hidden">
  <thead style="background:#f9fafb">
    <tr>
      <th style="width:22%">Title</th>
      <th style="width:8%">Mode</th>
      <th style="width:10%">Subject</th>
      <th style="width:6%">Qs</th>
      <th style="width:6%">Time</th>
      <th style="width:14%">Schedule</th>
      <th style="width:10%">Conducted by</th>
      <th style="width:8%">Status</th>
      <th style="width:16%">Actions</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach($tests as $t):
    $teacher = $t->teacher_id ? get_userdata($t->teacher_id) : null;
    $has_pin = !empty($t->access_pin) && (!$t->pin_expires_at || strtotime($t->pin_expires_at) > time());
    $sessions = $db->get_active_sessions($t->id);
  ?>
  <tr>
    <td>
      <strong><?php echo esc_html($t->title); ?></strong>
      <?php if($t->description): ?><br><small style="color:#6b7280"><?php echo esc_html(mb_substr($t->description,0,50)); ?></small><?php endif; ?>
    </td>
    <td>
      <span style="font-size:11px;padding:2px 8px;border-radius:99px;background:<?php echo ($t->test_mode??'online')==='offline'?'#fef3c7':'#dbeafe'; ?>;color:<?php echo ($t->test_mode??'online')==='offline'?'#92400e':'#1e40af'; ?>">
        <?php echo ($t->test_mode??'online')==='offline'?'📝 Offline':'🌐 Online'; ?>
      </span>
    </td>
    <td style="font-size:12px"><?php echo esc_html($t->subject_name ?? '—'); ?></td>
    <td><?php echo intval($t->q_count); ?></td>
    <td><?php echo $t->time_limit ? $t->time_limit.'m' : '∞'; ?></td>
    <td style="font-size:11px">
      <?php echo $t->scheduled_date ? date('d M, H:i', strtotime($t->scheduled_date)) : '—'; ?>
      <?php if($t->end_date): ?><br><span style="color:#6b7280">↳ <?php echo date('d M, H:i', strtotime($t->end_date)); ?></span><?php endif; ?>
    </td>
    <td style="font-size:12px"><?php echo $teacher ? esc_html($teacher->display_name) : '—'; ?></td>
    <td>
      <span style="padding:2px 8px;border-radius:99px;font-size:11px;background:<?php echo $t->status==='published'?'#dcfce7':($t->status==='closed'?'#f3f4f6':'#fef3c7'); ?>;color:<?php echo $t->status==='published'?'#166534':($t->status==='closed'?'#6b7280':'#92400e'); ?>">
        <?php echo esc_html($t->status); ?>
      </span>
    </td>
    <td style="font-size:12px">
      <a href="?page=cias-test-list&action=edit&id=<?php echo $t->id; ?>">Edit</a> |
      <a href="?page=cias-reports&test_id=<?php echo $t->id; ?>">Results</a><br>
      <?php if($t->status !== 'published'): ?>
      <a href="?page=cias-test-list&action=edit&id=<?php echo $t->id; ?>" style="color:#22c55e" onclick="event.preventDefault();document.querySelector('input[name=save_as][value=publish]')&&document.querySelector('input[name=save_as][value=publish]').click()">Publish</a> |
      <?php endif; ?>
      <a href="?page=cias-test-list&delete=<?php echo $t->id; ?>" style="color:#dc2626" onclick="return confirm('Delete test and all attempts?')">Delete</a>

      <?php if($t->status === 'published'): ?>
      <br>
      <?php if($has_pin): ?>
      <span style="background:#dcfce7;color:#166534;padding:2px 7px;border-radius:99px;font-size:10px;font-weight:700">🔐 PIN: <?php echo esc_html($t->access_pin); ?></span>
      <a href="?page=cias-test-list&clear_pin=<?php echo $t->id; ?>" style="font-size:10px;color:#dc2626">Clear</a>
      <?php else: ?>
      <a href="?page=cias-test-list&gen_pin=<?php echo $t->id; ?>&pin_mins=60" style="font-size:11px;color:#6C63FF">🔐 Generate PIN</a>
      <?php endif; ?>

      <?php if(!empty($sessions)): ?>
      <br><span style="font-size:10px;color:#6b7280">👥 <?php echo count($sessions); ?> active</span>
      <a href="?page=cias-test-list&show_sessions=<?php echo $t->id; ?>" style="font-size:10px;color:#6C63FF">Manage</a>
      <?php endif; ?>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if(empty($tests)): ?>
  <tr><td colspan="9" style="text-align:center;padding:30px;color:#9ca3af">No tests yet. <a href="?page=cias-test-list&action=add">Create your first test →</a></td></tr>
  <?php endif; ?>
  </tbody>
</table>

<?php
// Active sessions manager panel
if (isset($_GET['show_sessions'])):
    $show_tid = intval($_GET['show_sessions']);
    $sessions = $db->get_active_sessions($show_tid);
    $test_info = $db->get_by_id('tests', $show_tid);
?>
<div style="margin-top:20px;background:#fff;border:2px solid #6C63FF;border-radius:14px;overflow:hidden">
  <div style="background:#6C63FF;padding:12px 20px;display:flex;justify-content:space-between;align-items:center">
    <div style="color:#fff;font-weight:600">👥 Active Students — <?php echo esc_html($test_info->title ?? ''); ?></div>
    <a href="?page=cias-test-list" style="color:rgba(255,255,255,.7);font-size:13px">✕ Close</a>
  </div>
  <div style="padding:16px">
    <?php if(empty($sessions)): ?>
    <p style="color:#6b7280;text-align:center;padding:20px">No students currently active in this test.</p>
    <?php else: ?>
    <p style="font-size:13px;color:#6b7280;margin-bottom:12px">Students who started in the last 5 minutes. Click "Remove" to kick a student from the session immediately.</p>
    <table class="wp-list-table widefat fixed" style="border-radius:8px;overflow:hidden">
      <thead style="background:#f9fafb">
        <tr><th>Student</th><th>Started</th><th>Last seen</th><th>Progress</th><th>Action</th></tr>
      </thead>
      <tbody>
      <?php foreach($sessions as $sess):
        $elapsed = round((time() - strtotime($sess->last_seen)) / 60);
      ?>
      <tr>
        <td><strong><?php echo esc_html($sess->display_name); ?></strong></td>
        <td style="font-size:12px"><?php echo date('H:i', strtotime($sess->started_at)); ?></td>
        <td style="font-size:12px;color:<?php echo $elapsed < 2?'#166534':'#dc2626'; ?>"><?php echo $elapsed; ?>m ago</td>
        <td style="font-size:12px"><?php echo $sess->attempt_status === 'in_progress' ? '🟡 In progress' : '⬜ Not started'; ?></td>
        <td>
          <a href="?page=cias-test-list&show_sessions=<?php echo $show_tid; ?>&kick=<?php echo $sess->user_id; ?>&tid=<?php echo $show_tid; ?>"
             class="button button-small" style="color:#dc2626;border-color:#dc2626"
             onclick="return confirm('Remove <?php echo esc_js($sess->display_name); ?> from this test session?')">
             Remove
          </a>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <div style="margin-top:14px;padding-top:14px;border-top:1px solid #f3f4f6;font-size:13px;color:#6b7280">
      <strong>PIN Access Control:</strong>
      <?php
      $test_info_full = $wpdb->get_row($wpdb->prepare("SELECT access_pin, pin_expires_at FROM ".CIAS_TESTS." WHERE id=%d", $show_tid));
      $has_pin_now = !empty($test_info_full->access_pin) && (!$test_info_full->pin_expires_at || strtotime($test_info_full->pin_expires_at) > time());
      ?>
      <?php if($has_pin_now): ?>
      Current PIN: <strong style="font-size:16px;letter-spacing:3px;color:#6C63FF"><?php echo esc_html($test_info_full->access_pin); ?></strong>
      (expires <?php echo date('H:i', strtotime($test_info_full->pin_expires_at)); ?>)
      <a href="?page=cias-test-list&clear_pin=<?php echo $show_tid; ?>&show_sessions=<?php echo $show_tid; ?>" class="button button-small" style="margin-left:10px">Clear PIN</a>
      <?php else: ?>
      No PIN active.
      <a href="?page=cias-test-list&gen_pin=<?php echo $show_tid; ?>&pin_mins=30&show_sessions=<?php echo $show_tid; ?>" class="button button-small" style="margin-left:10px">Generate 30-min PIN</a>
      <a href="?page=cias-test-list&gen_pin=<?php echo $show_tid; ?>&pin_mins=60&show_sessions=<?php echo $show_tid; ?>" class="button button-small">Generate 60-min PIN</a>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>
</div>
        <?php endif;
    }

    /* ── Import Questions ── */
    public function page_import() {
        $result    = null;
        $questions = [];
        $raw_text  = '';

        // ── Handle: Save approved questions ──
        if (isset($_POST['cias_save_imported']) && check_admin_referer('cias_import_save')) {
            $to_save = [];
            $indices = $_POST['approve'] ?? [];
            $all_q   = json_decode(stripslashes($_POST['parsed_questions'] ?? '[]'), true);
            foreach ($indices as $idx) {
                if (isset($all_q[$idx])) $to_save[] = $all_q[$idx];
            }
            if (!empty($to_save)) {
                $saved = CIAS_Importer::save_questions($to_save);
                echo '<div class="notice notice-success"><p>✅ ' . $saved['saved'] . ' question(s) saved as Draft. <a href="?page=cias-questions">View in Questions →</a></p>';
                if (!empty($saved['errors'])) echo '<br>Errors: ' . implode(', ', $saved['errors']);
                echo '</p></div>';
            }
        }

        // ── Handle: Parse from Google Doc URL ──
        if (isset($_POST['cias_fetch_gdoc']) && check_admin_referer('cias_import')) {
            $url    = sanitize_url($_POST['gdoc_url'] ?? '');
            $fetch  = CIAS_Importer::fetch_google_doc($url);
            if (isset($fetch['error'])) {
                echo '<div class="notice notice-error"><p>❌ ' . esc_html($fetch['error']) . '</p></div>';
            } else {
                $raw_text = $fetch['text'];
                $result   = CIAS_Importer::parse_text($raw_text);
                $questions= $result['questions'];
            }
        }

        // ── Handle: Parse from pasted text ──
        if (isset($_POST['cias_parse_text']) && check_admin_referer('cias_import')) {
            $raw_text = wp_unslash($_POST['raw_text'] ?? '');
            $result   = CIAS_Importer::parse_text($raw_text);
            $questions= $result['questions'];
        }

        // ── Handle: Parse from DOCX upload ──
        if (isset($_POST['cias_upload_docx']) && check_admin_referer('cias_import')) {
            if (!empty($_FILES['docx_file']['tmp_name'])) {
                $ext = strtolower(pathinfo($_FILES['docx_file']['name'], PATHINFO_EXTENSION));
                if ($ext !== 'docx') {
                    echo '<div class="notice notice-error"><p>❌ Please upload a .docx file only.</p></div>';
                } else {
                    $parsed = CIAS_Importer::parse_docx($_FILES['docx_file']['tmp_name']);
                    if (isset($parsed['error'])) {
                        echo '<div class="notice notice-error"><p>❌ ' . esc_html($parsed['error']) . '</p></div>';
                    } else {
                        $raw_text = $parsed['text'];
                        $result   = CIAS_Importer::parse_text($raw_text);
                        $questions= $result['questions'];
                    }
                }
            }
        }
        ?>
<div class="wrap">
<h1 style="display:flex;align-items:center;gap:10px">
  📥 Import Questions
  <a href="?page=cias-questions" class="button" style="font-size:13px">← Back to Questions</a>
</h1>

<!-- Template guide -->
<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:12px;padding:16px 20px;margin-bottom:20px">
  <strong style="color:#166534;font-size:14px">📋 Simple template format</strong>
  <p style="color:#166534;font-size:13px;margin:6px 0 10px">Each question follows this pattern. Separate multiple questions with <code>===</code> on its own line.</p>
  <pre style="background:#fff;border:1px solid #86efac;border-radius:8px;padding:14px;font-size:12px;line-height:1.8;overflow-x:auto;color:#134e4a">Polity | Fundamental Rights | medium | Parliament, Static | 2023

With reference to Parliamentary Committees, which is/are correctly matched?

1. Public Accounts Committee — examines appropriation accounts
2. Estimates Committee — examines working of public sector undertakings
3. Committee on Public Undertakings — selects estimates for detailed examination

A. 1 only
B. 1 and 3 only
C. 2 and 3 only
D. 1, 2 and 3

ANSWER: B
EXPLANATION: PAC examines appropriation accounts of the Government of India.

===

Polity | Constitution | easy | Static

Which Article of the Indian Constitution deals with Equality before Law?

A. Article 12
B. Article 13
C. Article 14
D. Article 15

ANSWER: C</pre>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:12px;font-size:12px;color:#166534">
    <div><strong>Header line (pipe-separated):</strong><br>SUBJECT | TOPIC | DIFFICULTY | TAGS | YEAR<br>Only SUBJECT is required. Rest are optional.</div>
    <div><strong>Numbered statements (optional):</strong><br>Add <code>1. 2. 3.</code> lines for statement-based questions.<br>System auto-detects the type.</div>
  </div>
</div>

<!-- Three import methods -->
<?php if (!$result): ?>
<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:20px">

  <!-- Method 1: Google Doc URL -->
  <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px">
    <h3 style="margin:0 0 6px;font-size:14px">🔗 Google Doc URL</h3>
    <p style="font-size:12px;color:#6b7280;margin-bottom:12px">Paste the shareable Google Doc link. Doc must be set to "Anyone with the link can view".</p>
    <form method="post"><?php wp_nonce_field('cias_import'); ?>
      <input type="url" name="gdoc_url" placeholder="https://docs.google.com/document/d/..."
             style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;margin-bottom:10px" required>
      <button type="submit" name="cias_fetch_gdoc" class="button button-primary" style="width:100%;padding:9px">
        📥 Fetch & Parse
      </button>
    </form>
  </div>

  <!-- Method 2: DOCX upload -->
  <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px">
    <h3 style="margin:0 0 6px;font-size:14px">📄 Upload .docx File</h3>
    <p style="font-size:12px;color:#6b7280;margin-bottom:12px">Download your Google Doc as Word (.docx) and upload it here. Works offline too.</p>
    <form method="post" enctype="multipart/form-data"><?php wp_nonce_field('cias_import'); ?>
      <input type="file" name="docx_file" accept=".docx"
             style="width:100%;margin-bottom:10px;font-size:13px" required>
      <button type="submit" name="cias_upload_docx" class="button button-primary" style="width:100%;padding:9px">
        📥 Upload & Parse
      </button>
    </form>
  </div>

  <!-- Method 3: Paste text -->
  <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px">
    <h3 style="margin:0 0 6px;font-size:14px">📝 Paste Text</h3>
    <p style="font-size:12px;color:#6b7280;margin-bottom:12px">Copy text from any doc and paste it directly. Good for quick single questions.</p>
    <form method="post"><?php wp_nonce_field('cias_import'); ?>
      <textarea name="raw_text" rows="5"
                style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:12px;resize:vertical;margin-bottom:10px;font-family:monospace"
                placeholder="Paste your question text here..." required></textarea>
      <button type="submit" name="cias_parse_text" class="button button-primary" style="width:100%;padding:9px">
        📥 Parse Questions
      </button>
    </form>
  </div>

</div>

<?php else: ?>

<!-- Parse results -->
<?php
$valid   = array_filter($questions, function($q) { return is_array($q); });
$invalid = $result['errors'] ?? [];
?>

<div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;align-items:center">
  <span style="background:#dcfce7;color:#166534;padding:5px 14px;border-radius:99px;font-size:13px;font-weight:500">✅ <?php echo count($valid); ?> questions ready</span>
  <?php if(!empty($invalid)): ?>
  <span style="background:#fee2e2;color:#991b1b;padding:5px 14px;border-radius:99px;font-size:13px;font-weight:500">❌ <?php echo count($invalid); ?> could not be parsed</span>
  <?php endif; ?>
  <a href="?page=cias-import" class="button" style="margin-left:auto">← Try again</a>
</div>

<?php if(!empty($invalid)): ?>
<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:10px;padding:14px;margin-bottom:16px">
  <strong style="color:#991b1b;font-size:13px">Parse errors — fix these in your doc and re-import:</strong>
  <ul style="margin:8px 0 0 16px;font-size:12px;color:#991b1b">
    <?php foreach($invalid as $e): ?><li><?php echo esc_html($e); ?></li><?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<?php if(!empty($valid)): ?>
<form method="post">
  <?php wp_nonce_field('cias_import_save'); ?>
  <input type="hidden" name="parsed_questions" value="<?php echo esc_attr(wp_json_encode(array_values($questions))); ?>">

  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
    <div style="font-size:13px;font-weight:500">Review questions before saving — uncheck any you don't want:</div>
    <div style="display:flex;gap:8px">
      <button type="button" onclick="toggleAll(true)"  class="button">✅ Select all</button>
      <button type="button" onclick="toggleAll(false)" class="button">⬜ Deselect all</button>
      <button type="submit" name="cias_save_imported" class="button button-primary" style="padding:8px 20px">💾 Save selected as Draft</button>
    </div>
  </div>

  <?php foreach(array_values($questions) as $i => $q): if(!is_array($q)) continue; ?>
  <?php
  $stmts = [];
  if ($q['statements']) $stmts = json_decode($q['statements'], true) ?: [];
  $tags  = array_filter(explode(',', $q['question_tags'] ?? ''));
  global $wpdb;
  $subject_name = $wpdb->get_var($wpdb->prepare("SELECT name FROM {$wpdb->prefix}cias_subjects WHERE id=%d", $q['subject_id']));
  ?>
  <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin-bottom:10px;display:flex;gap:14px">
    <div style="padding-top:2px">
      <input type="checkbox" name="approve[]" value="<?php echo $i; ?>" checked style="width:18px;height:18px;accent-color:#6C63FF">
    </div>
    <div style="flex:1">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;flex-wrap:wrap">
        <span style="background:#f0eeff;color:#6C63FF;padding:2px 9px;border-radius:99px;font-size:11px;font-weight:600"><?php echo esc_html($subject_name); ?></span>
        <span style="background:<?php echo $q['question_type']==='statement'?'#fef3c7':'#f3f4f6'; ?>;color:<?php echo $q['question_type']==='statement'?'#92400e':'#6b7280'; ?>;padding:2px 9px;border-radius:99px;font-size:11px">
          <?php echo $q['question_type']==='statement'?'📋 Statement':'📝 Standard'; ?>
        </span>
        <span style="background:<?php echo $q['difficulty']==='easy'?'#dcfce7':($q['difficulty']==='hard'?'#fee2e2':'#fef3c7'); ?>;color:<?php echo $q['difficulty']==='easy'?'#166534':($q['difficulty']==='hard'?'#991b1b':'#92400e'); ?>;padding:2px 9px;border-radius:99px;font-size:11px">
          <?php echo esc_html($q['difficulty']); ?>
        </span>
        <?php if($q['year_asked']): ?>
        <span style="background:#fef3c7;color:#92400e;padding:2px 9px;border-radius:99px;font-size:11px">UPSC <?php echo intval($q['year_asked']); ?></span>
        <?php endif; ?>
        <?php foreach($tags as $t): ?>
        <span style="background:#f9fafb;color:#6b7280;padding:2px 9px;border-radius:99px;font-size:11px;border:1px solid #e5e7eb"><?php echo esc_html(trim($t)); ?></span>
        <?php endforeach; ?>
      </div>

      <div style="font-size:14px;font-weight:500;line-height:1.5;margin-bottom:6px"><?php echo esc_html($q['question_text']); ?></div>

      <?php if(!empty($stmts)): ?>
      <div style="background:#f9fafb;border-radius:8px;padding:10px 14px;margin:6px 0">
        <?php foreach($stmts as $si => $s): ?>
        <div style="font-size:13px;padding:3px 0;display:flex;gap:8px"><span style="font-weight:700;color:#6C63FF"><?php echo $si+1; ?>.</span><span><?php echo esc_html($s); ?></span></div>
        <?php endforeach; ?>
      </div>
      <div style="font-size:12px;color:#9ca3af;margin-bottom:6px">Select the correct answer:</div>
      <?php endif; ?>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px;margin-bottom:6px">
        <?php foreach(['a'=>'A','b'=>'B','c'=>'C','d'=>'D'] as $k=>$l): ?>
        <div style="font-size:12px;padding:5px 10px;border-radius:6px;background:<?php echo $q['correct_option']===$k?'#dcfce7':'#f9fafb'; ?>;color:<?php echo $q['correct_option']===$k?'#166534':'#374151'; ?>;border:1px solid <?php echo $q['correct_option']===$k?'#86efac':'#f3f4f6'; ?>">
          <strong><?php echo $l; ?>.</strong> <?php echo esc_html($q['option_'.$k]); ?><?php echo $q['correct_option']===$k?' ✓':''; ?>
        </div>
        <?php endforeach; ?>
      </div>

      <?php if($q['explanation']): ?>
      <div style="font-size:12px;color:#6b7280;background:#fffbeb;border-radius:6px;padding:6px 10px">
        💡 <?php echo esc_html($q['explanation']); ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <div style="text-align:right;margin-top:12px">
    <button type="submit" name="cias_save_imported" class="button button-primary" style="padding:10px 28px;font-size:14px">
      💾 Save selected as Draft
    </button>
  </div>
</form>
<?php endif; ?>
<?php endif; ?>

</div>

<script>
function toggleAll(state) {
  document.querySelectorAll('input[name="approve[]"]').forEach(function(cb) { cb.checked = state; });
}
</script>
        <?php
    }

    /* ── Parents admin page ── */
    public function page_parents() {
        global $wpdb;

        // Save parent contacts
        if (isset($_POST['cias_save_parents']) && check_admin_referer('cias_parents')) {
            foreach ($_POST['parent_phone'] ?? [] as $uid => $phone) {
                $phone = preg_replace('/[^0-9+]/', '', sanitize_text_field($phone));
                if ($phone) update_user_meta(intval($uid), 'cias_parent_phone', $phone);
                else delete_user_meta(intval($uid), 'cias_parent_phone');
            }
            foreach ($_POST['parent_name'] ?? [] as $uid => $name) {
                update_user_meta(intval($uid), 'cias_parent_name', sanitize_text_field($name));
            }
            foreach ($_POST['parent_email'] ?? [] as $uid => $email) {
                $email = sanitize_email($email);
                if ($email) update_user_meta(intval($uid), 'cias_parent_email', $email);
                else delete_user_meta(intval($uid), 'cias_parent_email');
            }
            echo '<div class="notice notice-success"><p>✅ Parent details saved!</p></div>';
        }

        // ── Manual custom email ──
        if (isset($_POST['cias_send_manual_email']) && check_admin_referer('cias_manual_email')) {
            $subject    = sanitize_text_field($_POST['manual_subject'] ?? '');
            $message    = wp_kses_post($_POST['manual_message'] ?? '');
            $recipients = $_POST['manual_recipients'] ?? [];
            $sent = 0; $failed = 0;

            if (empty($subject) || empty($message)) {
                echo '<div class="notice notice-error"><p>❌ Subject and message are required.</p></div>';
            } elseif (empty($recipients)) {
                echo '<div class="notice notice-error"><p>❌ Select at least one recipient.</p></div>';
            } else {
                $from     = 'CIAS — ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>';
                $headers  = ['Content-Type: text/html; charset=UTF-8', "From: {$from}"];
                $site_url = home_url();

                foreach ($recipients as $uid) {
                    $uid   = intval($uid);
                    $email = get_user_meta($uid, 'cias_parent_email', true);
                    if (!$email || !is_email($email)) { $failed++; continue; }

                    $student     = get_userdata($uid);
                    $parent_name = get_user_meta($uid, 'cias_parent_name', true);
                    $greeting    = $parent_name ? "Dear {$parent_name}," : "Dear Parent,";
                    $student_name = $student ? $student->display_name : '';

                    // Replace placeholders in message
                    $personalised = str_replace(
                        ['{student_name}', '{parent_name}', '{site_url}'],
                        [$student_name,   $parent_name ?: 'Parent', $site_url],
                        $message
                    );

                    $html = "<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#f9fafb;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif'>
<div style='max-width:600px;margin:0 auto;padding:20px'>
  <div style='background:linear-gradient(135deg,#6C63FF 0%,#534AB7 100%);border-radius:16px 16px 0 0;padding:24px 32px'>
    <div style='color:#fff;font-size:18px;font-weight:700'>" . get_bloginfo('name') . "</div>
    <div style='color:rgba(255,255,255,.8);font-size:13px;margin-top:4px'>Message from CIAS</div>
  </div>
  <div style='background:#fff;padding:28px 32px;border:1px solid #e5e7eb;border-top:none'>
    <p style='font-size:14px;color:#374151;margin:0 0 16px'>{$greeting}</p>
    <div style='font-size:14px;color:#374151;line-height:1.8'>" . nl2br($personalised) . "</div>
  </div>
  <div style='background:#f3f4f6;border-radius:0 0 16px 16px;border:1px solid #e5e7eb;border-top:none;padding:16px 32px;text-align:center'>
    <a href='{$site_url}' style='color:#6C63FF;font-size:12px;text-decoration:none'>" . get_bloginfo('name') . " — {$site_url}</a>
  </div>
</div></body></html>";

                    $result = wp_mail($email, $subject, $html, $headers);
                    if ($result) {
                        $sent++;
                        $wpdb->insert(CIAS_WA_LOG, [
                            'user_id'      => $uid,
                            'parent_phone' => $email,
                            'message_type' => 'email_manual',
                            'status'       => 'sent',
                        ]);
                    } else {
                        $failed++;
                        $wpdb->insert(CIAS_WA_LOG, [
                            'user_id'      => $uid,
                            'parent_phone' => $email,
                            'message_type' => 'email_manual',
                            'status'       => 'failed',
                            'error_message'=> 'wp_mail returned false',
                        ]);
                    }
                }

                $msg = "✅ Sent to {$sent} parent(s).";
                if ($failed) $msg .= " ❌ {$failed} failed (no email set or delivery error).";
                echo '<div class="notice notice-' . ($failed && !$sent ? 'error' : 'success') . '"><p>' . $msg . '</p></div>';
            }
        }

        // Manual report trigger
        if (isset($_GET['send_test']) && current_user_can('manage_options')) {
            $uid    = intval($_GET['send_test']);
            $result = false;
            if (get_option('cias_email_reports_enabled','0') === '1') {
                $result = CIAS_Email_Reports::send_report_for_student($uid, 'daily');
                $method = 'Email';
            } else {
                $result = CIAS_WhatsApp::send_report_for_student($uid, 'daily');
                $method = 'WhatsApp';
            }
            echo '<div class="notice notice-' . ($result?'success':'error') . '"><p>' . ($result ? "✅ Test {$method} sent!" : "❌ Failed. Check Logs.") . '</p></div>';
        }

        $db      = new CIAS_DB();
        $batches = $db->get_batches_with_course();
        $filter_batch = intval($_GET['batch'] ?? 0);
        $students = $filter_batch ? $db->get_batch_students($filter_batch) : get_users(['role__in'=>['vocab_student','cias_teacher'],'orderby'=>'display_name']);
        ?>
<div class="wrap">
<h1 style="display:flex;align-items:center;gap:10px">
  👨‍👩‍👧 Parent Contacts
  <a href="?page=cias-wa-logs" class="button" style="font-size:13px">View Email/WA Logs →</a>
</h1>

<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:14px 18px;margin:12px 0;font-size:13px">
  <strong>📧 Automated reports</strong> send at 8 PM IST daily + every Sunday. <strong>Use the compose panel below</strong> to send custom emails anytime — for test reminders, fee notices, event updates, or anything else.
</div>

<!-- Manual Email Compose Panel -->
<div style="background:#fff;border:2px solid #e5e7eb;border-radius:14px;padding:0;margin-bottom:20px;overflow:hidden">
  <div style="display:flex;align-items:center;gap:10px;padding:16px 20px;cursor:pointer;background:#f9fafb;border-bottom:1px solid #e5e7eb"
       onclick="document.getElementById('compose-panel').style.display=document.getElementById('compose-panel').style.display==='none'?'block':'none';this.querySelector('.arr').style.transform=this.querySelector('.arr').style.transform==='rotate(180deg)'?'':'rotate(180deg)'">
    <span style="font-size:20px">✉️</span>
    <div style="flex:1">
      <div style="font-size:14px;font-weight:600;color:#374151">Send Custom Email to Parents</div>
      <div style="font-size:12px;color:#6b7280">Write your own message — test reminders, fee notices, event alerts, or anything. Sent instantly.</div>
    </div>
    <span class="arr" style="font-size:18px;color:#6b7280;transition:transform .2s">▼</span>
  </div>

  <div id="compose-panel" style="display:none;padding:20px">
    <form method="post">
      <?php wp_nonce_field('cias_manual_email'); ?>

      <!-- Recipient selector -->
      <div style="margin-bottom:16px">
        <label style="font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;display:block;margin-bottom:8px">To — select recipients</label>
        <div style="display:flex;gap:8px;margin-bottom:8px;flex-wrap:wrap">
          <button type="button" onclick="document.querySelectorAll('.rcb:not(:disabled)').forEach(function(c){c.checked=true})" class="button button-small">✅ All parents</button>
          <button type="button" onclick="document.querySelectorAll('.rcb').forEach(function(c){c.checked=false})" class="button button-small">⬜ None</button>
          <?php
          $db      = new CIAS_DB();
          $batches = $db->get_batches_with_course();
          if (!empty($batches)): ?>
          <select onchange="selectBatch(this.value)" style="padding:5px 10px;border-radius:6px;border:1px solid #d1d5db;font-size:12px">
            <option value="">Filter by batch...</option>
            <?php foreach($batches as $b): ?><option value="<?php echo $b->id; ?>"><?php echo esc_html(($b->course_name??'').' — '.$b->name); ?></option><?php endforeach; ?>
          </select>
          <?php endif; ?>
        </div>
        <div style="max-height:200px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:8px;padding:8px 12px;background:#f9fafb">
          <?php
          $all_students = get_users(['role__in'=>['vocab_student'],'orderby'=>'display_name']);
          foreach ($all_students as $s):
            $pemail = get_user_meta($s->ID, 'cias_parent_email', true);
            $pname  = get_user_meta($s->ID, 'cias_parent_name', true);
            $batches_of = $wpdb->get_col($wpdb->prepare("SELECT batch_id FROM ".CIAS_ENROLLMENTS." WHERE user_id=%d", $s->ID));
          ?>
          <label style="display:flex;align-items:center;gap:10px;padding:5px 0;cursor:pointer;border-bottom:0.5px solid #f3f4f6"
                 class="rrow" data-batch="<?php echo esc_attr(implode(',', $batches_of)); ?>">
            <input type="checkbox" name="manual_recipients[]" value="<?php echo $s->ID; ?>"
                   class="rcb" <?php echo !$pemail ? 'disabled' : ''; ?>>
            <span style="flex:1;font-size:13px;<?php echo !$pemail?'color:#9ca3af':'color:#374151'; ?>"><?php echo esc_html($s->display_name); ?></span>
            <?php if($pemail): ?>
              <span style="font-size:11px;color:#6b7280"><?php echo $pname ? esc_html($pname).' — ' : ''; ?><?php echo esc_html($pemail); ?></span>
              <span style="background:#dcfce7;color:#166534;padding:1px 8px;border-radius:99px;font-size:10px;font-weight:600">✓</span>
            <?php else: ?>
              <span style="font-size:11px;color:#dc2626">No email</span>
            <?php endif; ?>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Subject line -->
      <div style="margin-bottom:12px">
        <label style="font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;display:block;margin-bottom:5px">Subject *</label>
        <input type="text" name="manual_subject" required style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px" placeholder="e.g. Mock Test #4 scheduled for 15 May">
      </div>

      <!-- Quick templates -->
      <div style="margin-bottom:10px">
        <label style="font-size:11px;font-weight:600;color:#6b7280">Quick templates:</label>
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:5px">
          <?php
          $templates = [
            'Test Reminder' => "Dear {parent_name},\n\nThis is a reminder that {student_name}'s upcoming Mock Test is scheduled on [DATE] at [TIME].\n\nPlease ensure your ward logs in on time at: " . home_url('/mock-test/') . "\n\nBest regards,\nCIAS Team",
            'Low Performance Alert' => "Dear {parent_name},\n\nWe wanted to share that {student_name} has been struggling in recent tests and needs extra attention.\n\nWe recommend 30 minutes of daily practice. Please encourage your ward to log in and practice regularly.\n\nBest regards,\nCIAS Team",
            'Great Performance' => "Dear {parent_name},\n\nWe are happy to share that {student_name} has shown excellent performance this week!\n\nKeep up the great work. Consistency is key to UPSC success.\n\nBest regards,\nCIAS Team",
            'Fee/Admin Notice' => "Dear {parent_name},\n\nThis is an important notice regarding [DETAILS].\n\nPlease contact us at [CONTACT] for any queries.\n\nBest regards,\nCIAS Team",
          ];
          foreach ($templates as $label => $body): ?>
          <button type="button" onclick="fillTemplate(this)" data-body="<?php echo esc_attr($body); ?>"
                  style="background:#f0eeff;color:#6C63FF;border:1px solid #c4b5fd;border-radius:99px;padding:4px 12px;font-size:11px;cursor:pointer">
            <?php echo esc_html($label); ?>
          </button>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Message body -->
      <div style="margin-bottom:16px">
        <label style="font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;display:block;margin-bottom:5px">
          Message *
          <span style="font-weight:400;text-transform:none;letter-spacing:0;color:#9ca3af"> — use <code style="background:#f3f4f6;padding:1px 5px;border-radius:4px">{student_name}</code> and <code style="background:#f3f4f6;padding:1px 5px;border-radius:4px">{parent_name}</code></span>
        </label>
        <textarea name="manual_message" id="manual-msg" rows="7" required
                  style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;resize:vertical;line-height:1.7;font-family:inherit"
                  placeholder="Write your message here. Use {student_name} and {parent_name} — each parent gets a personalised copy."></textarea>
      </div>

      <div style="display:flex;gap:10px;align-items:center">
        <button type="submit" name="cias_send_manual_email" class="button button-primary" style="padding:10px 28px;font-size:14px;border-radius:8px">
          📤 Send Now
        </button>
        <span style="font-size:12px;color:#6b7280">Delivered instantly. Check <a href="?page=cias-wa-logs">Email Logs</a> for delivery status.</span>
      </div>
    </form>
  </div>
</div>

<script>
function selectBatch(batchId) {
  if (!batchId) return;
  document.querySelectorAll('.rrow').forEach(function(row) {
    var batches = row.getAttribute('data-batch').split(',').filter(Boolean);
    var cb = row.querySelector('.rcb');
    if (cb && !cb.disabled) cb.checked = batches.indexOf(batchId) !== -1;
  });
}
function fillTemplate(btn) {
  document.getElementById('manual-msg').value = btn.getAttribute('data-body');
}
</script>

<!-- Batch filter -->
<div style="margin-bottom:14px;display:flex;gap:10px;align-items:center">
  <label style="font-size:13px">Filter by batch:</label>
  <select onchange="location='?page=cias-parents&batch='+this.value" style="padding:6px 10px;border-radius:8px;border:1px solid #d1d5db">
    <option value="0">All students</option>
    <?php foreach($batches as $b): ?><option value="<?php echo $b->id; ?>" <?php selected($filter_batch,$b->id); ?>><?php echo esc_html(($b->course_name??'').' — '.$b->name); ?></option><?php endforeach; ?>
  </select>
</div>

<form method="post"><?php wp_nonce_field('cias_parents'); ?>
<table class="wp-list-table widefat fixed striped" style="border-radius:10px;overflow:hidden">
  <thead style="background:#f9fafb">
    <tr>
      <th style="width:20%">Student</th>
      <th style="width:18%">Parent Name</th>
      <th style="width:16%">Parent Email</th>
      <th style="width:14%">Parent WhatsApp</th>
      <th style="width:10%">Status</th>
      <th style="width:12%">Last Report</th>
      <th style="width:10%">Actions</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach($students as $s):
    $uid          = $s->ID ?? $s->id;
    $display_name = $s->display_name;
    $parent_phone = get_user_meta($uid, 'cias_parent_phone', true);
    $parent_name  = get_user_meta($uid, 'cias_parent_name',  true);
    $parent_email = get_user_meta($uid, 'cias_parent_email', true);
    $last_sent    = $wpdb->get_var($wpdb->prepare(
        "SELECT sent_at FROM ".CIAS_WA_LOG." WHERE user_id=%d ORDER BY sent_at DESC LIMIT 1", $uid
    ));
    $wa_enabled   = get_option('cias_wa_enabled', '0') === '1';
    $em_enabled   = get_option('cias_email_reports_enabled', '0') === '1';
  ?>
  <tr>
    <td><strong><?php echo esc_html($display_name); ?></strong></td>
    <td><input type="text" name="parent_name[<?php echo $uid; ?>]" value="<?php echo esc_attr($parent_name); ?>" placeholder="Parent name" style="width:100%;padding:5px 8px;border:1px solid #d1d5db;border-radius:6px;font-size:12px"></td>
    <td><input type="email" name="parent_email[<?php echo $uid; ?>]" value="<?php echo esc_attr($parent_email); ?>" placeholder="parent@email.com" style="width:100%;padding:5px 8px;border:1px solid #d1d5db;border-radius:6px;font-size:12px"></td>
    <td><input type="text" name="parent_phone[<?php echo $uid; ?>]" value="<?php echo esc_attr($parent_phone); ?>" placeholder="+919876543210" style="width:100%;padding:5px 8px;border:1px solid #d1d5db;border-radius:6px;font-size:12px">
      <div style="font-size:10px;color:#9ca3af;margin-top:2px">+91 for WhatsApp</div>
    </td>
    <td>
      <?php
      $has_email = !empty($parent_email);
      $has_phone = !empty($parent_phone);
      if ($has_email) echo '<span style="background:#dbeafe;color:#1e40af;padding:2px 7px;border-radius:99px;font-size:10px;display:block;margin-bottom:2px">📧 Email</span>';
      if ($has_phone) echo '<span style="background:#dcfce7;color:#166534;padding:2px 7px;border-radius:99px;font-size:10px;display:block">📱 WhatsApp</span>';
      if (!$has_email && !$has_phone) echo '<span style="background:#f3f4f6;color:#6b7280;padding:2px 8px;border-radius:99px;font-size:11px">Not set</span>';
      ?>
    </td>
    <td style="font-size:11px;color:#6b7280"><?php echo $last_sent ? date('d M, H:i', strtotime($last_sent)) : '—'; ?></td>
    <td>
      <?php if($parent_email && $em_enabled): ?>
      <a href="?page=cias-parents&send_test=<?php echo $uid; ?><?php echo $filter_batch?'&batch='.$filter_batch:''; ?>" class="button button-small" onclick="return confirm('Send test email to <?php echo esc_js($parent_email); ?>?')">Test email</a>
      <?php elseif($parent_phone && $wa_enabled): ?>
      <a href="?page=cias-parents&send_test=<?php echo $uid; ?><?php echo $filter_batch?'&batch='.$filter_batch:''; ?>" class="button button-small" onclick="return confirm('Send test WhatsApp?')">Test WA</a>
      <?php else: ?>
      <span style="font-size:11px;color:#9ca3af">Add contact</span>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<div style="margin-top:14px">
  <input type="submit" name="cias_save_parents" class="button button-primary" value="💾 Save Parent Details">
</div>
</form></div>
        <?php
    }

    /* ── Communication Logs page ── */
    public function page_wa_logs() {
        global $wpdb;

        // Send to selected students
        if (isset($_POST['cias_send_selected']) && check_admin_referer('cias_send_selected') && current_user_can('manage_options')) {
            $uids    = array_map('intval', $_POST['send_uids'] ?? []);
            $channel = sanitize_text_field($_POST['send_channel'] ?? 'email');
            $sent    = 0;
            foreach ($uids as $uid) {
                if ($channel === 'email' || $channel === 'both') {
                    if (CIAS_Email_Reports::send_report_for_student($uid, 'daily')) $sent++;
                }
                if ($channel === 'whatsapp' || $channel === 'both') {
                    if (get_option('cias_wa_enabled','0') === '1') {
                        if (CIAS_WhatsApp::send_report_for_student($uid, 'daily')) $sent++;
                    }
                }
            }
            echo '<div class="notice notice-success"><p>✅ Sent to ' . intval($sent) . ' parent(s).</p></div>';
        }

        $type_filter = sanitize_text_field($_GET['type_filter'] ?? 'all');
        $where_type  = '';
        if ($type_filter === 'email')    $where_type = "AND l.message_type LIKE 'email%'";
        if ($type_filter === 'whatsapp') $where_type = "AND l.message_type LIKE 'wa%'";

        $logs = $wpdb->get_results(
            "SELECT l.*, u.display_name FROM ".CIAS_WA_LOG." l
             LEFT JOIN {$wpdb->users} u ON l.user_id=u.ID
             WHERE 1=1 $where_type
             ORDER BY l.sent_at DESC LIMIT 100"
        );

        $all_students = get_users(['role__in'=>['vocab_student'],'orderby'=>'display_name']);
        $batches      = (new CIAS_DB())->get_batches_with_course();
        ?>
<div class="wrap">
<h1 style="display:flex;align-items:center;gap:10px">
  📡 Communication Logs
  <a href="?page=cias-parents" class="button" style="font-size:13px">← Parents</a>
</h1>

<!-- Send with selection -->
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;margin-bottom:16px;overflow:hidden">
  <div style="background:#f9fafb;border-bottom:0.5px solid #e5e7eb;padding:12px 18px;font-size:13px;font-weight:500;cursor:pointer"
       onclick="document.getElementById('cl-send-panel').style.display=document.getElementById('cl-send-panel').style.display==='none'?'block':'none'">
    📤 Send reports to selected parents <span style="color:#6b7280;font-weight:400;margin-left:8px">(click to expand)</span>
  </div>
  <div id="cl-send-panel" style="display:none;padding:16px 18px">
    <form method="post">
      <?php wp_nonce_field('cias_send_selected'); ?>
      <div style="display:flex;gap:10px;margin-bottom:12px;flex-wrap:wrap;align-items:center">
        <select onchange="filterBatch(this.value)" style="padding:6px 10px;border-radius:8px;border:0.5px solid #d1d5db;font-size:13px">
          <option value="">Filter by batch</option>
          <?php foreach($batches as $b): ?><option value="<?php echo $b->id; ?>"><?php echo esc_html(($b->course_name??'').' — '.$b->name); ?></option><?php endforeach; ?>
        </select>
        <button type="button" onclick="document.querySelectorAll('.send-cb:not(:disabled)').forEach(function(c){c.checked=true})" class="button button-small">✅ All</button>
        <button type="button" onclick="document.querySelectorAll('.send-cb').forEach(function(c){c.checked=false})" class="button button-small">⬜ None</button>
        <label style="font-size:13px">Channel:
          <select name="send_channel" style="padding:5px 8px;border-radius:6px;border:0.5px solid #d1d5db;font-size:13px;margin-left:4px">
            <option value="email">Email only</option>
            <option value="whatsapp">WhatsApp only</option>
            <option value="both">Both</option>
          </select>
        </label>
      </div>
      <div style="max-height:160px;overflow-y:auto;border:1px solid #f3f4f6;border-radius:8px;padding:8px">
        <?php foreach($all_students as $s):
          $email  = get_user_meta($s->ID, 'cias_parent_email', true);
          $phone  = get_user_meta($s->ID, 'cias_parent_phone', true);
          $bids   = $wpdb->get_col($wpdb->prepare("SELECT batch_id FROM ".CIAS_ENROLLMENTS." WHERE user_id=%d", $s->ID));
        ?>
        <label style="display:flex;align-items:center;gap:8px;padding:4px 0;font-size:13px;cursor:pointer" class="send-row" data-batch="<?php echo esc_attr(implode(',', $bids)); ?>">
          <input type="checkbox" name="send_uids[]" value="<?php echo $s->ID; ?>" class="send-cb" <?php echo (!$email && !$phone) ? 'disabled title="No contact set"' : ''; ?>>
          <span style="flex:1"><?php echo esc_html($s->display_name); ?></span>
          <?php if($email): ?><span style="font-size:11px;color:#1D9E75">✉ email</span><?php endif; ?>
          <?php if($phone): ?><span style="font-size:11px;color:#22c55e">📱 WA</span><?php endif; ?>
          <?php if(!$email && !$phone): ?><span style="font-size:11px;color:#dc2626">No contact</span><?php endif; ?>
        </label>
        <?php endforeach; ?>
      </div>
      <button type="submit" name="cias_send_selected" class="button button-primary" style="margin-top:10px">📤 Send to selected</button>
    </form>
  </div>
</div>
<script>
function filterBatch(bid) {
  if (!bid) return;
  document.querySelectorAll('.send-row').forEach(function(r){
    var cbs = r.getAttribute('data-batch').split(',');
    var cb = r.querySelector('.send-cb');
    if (cb && !cb.disabled) cb.checked = cbs.indexOf(bid) !== -1;
  });
}
</script>

<!-- Type filter tabs -->
<div style="display:flex;gap:6px;margin-bottom:14px">
  <?php foreach(['all'=>'All','email'=>'📧 Email','whatsapp'=>'💬 WhatsApp'] as $k=>$lbl): ?>
  <a href="?page=cias-wa-logs&type_filter=<?php echo $k; ?>"
     style="padding:7px 16px;border-radius:8px;border:<?php echo $type_filter===$k?'1px solid #6C63FF':'0.5px solid #d1d5db'; ?>;background:<?php echo $type_filter===$k?'#f0eeff':'none'; ?>;color:<?php echo $type_filter===$k?'#534AB7':'#6b7280'; ?>;font-size:13px;text-decoration:none">
    <?php echo $lbl; ?>
  </a>
  <?php endforeach; ?>
  <span style="margin-left:auto;font-size:12px;color:#9ca3af;align-self:center">Showing last 100 records · 8 PM IST daily auto-send</span>
</div>

<table class="wp-list-table widefat fixed striped" style="border-radius:10px;overflow:hidden">
  <thead style="background:#f9fafb">
    <tr>
      <th>Student</th>
      <th>Contact</th>
      <th>Channel</th>
      <th>Type</th>
      <th>Status</th>
      <th>Sent at</th>
      <th>Error</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach($logs as $l):
    $is_email = strpos($l->message_type, 'email') === 0;
    $channel_badge = $is_email
      ? '<span style="background:#dbeafe;color:#1e40af;padding:1px 7px;border-radius:99px;font-size:11px">📧 Email</span>'
      : '<span style="background:#dcfce7;color:#166534;padding:1px 7px;border-radius:99px;font-size:11px">💬 WA</span>';
    $type_clean = str_replace(['email_','wa_'], '', $l->message_type);
  ?>
  <tr>
    <td><?php echo esc_html($l->display_name ?? '—'); ?></td>
    <td style="font-size:11px;color:#6b7280"><?php echo esc_html($l->parent_phone); ?></td>
    <td><?php echo $channel_badge; ?></td>
    <td><span style="background:#f0eeff;color:#6C63FF;padding:1px 7px;border-radius:99px;font-size:11px"><?php echo esc_html($type_clean); ?></span></td>
    <td>
      <span style="padding:2px 8px;border-radius:99px;font-size:11px;background:<?php echo $l->status==='sent'?'#dcfce7':($l->status==='failed'?'#fee2e2':'#fef3c7'); ?>;color:<?php echo $l->status==='sent'?'#166534':($l->status==='failed'?'#991b1b':'#92400e'); ?>">
        <?php echo esc_html($l->status); ?>
      </span>
    </td>
    <td style="font-size:12px"><?php echo $l->sent_at ? date('d M Y, H:i', strtotime($l->sent_at)) : '—'; ?></td>
    <td style="font-size:11px;color:#dc2626"><?php echo esc_html($l->error_message ?? ''); ?></td>
  </tr>
  <?php endforeach; ?>
  <?php if(empty($logs)): ?><tr><td colspan="7" style="text-align:center;padding:30px;color:#9ca3af">No messages sent yet.</td></tr><?php endif; ?>
  </tbody>
</table></div>
        <?php
    }

    /* ── AI Usage Dashboard ── */
    public function page_ai_usage() {
        $stats = CIAS_AI_Bot::get_admin_usage_stats();
        global $wpdb;
        $today_cost = round(array_sum(array_column((array)$stats, 'total_cost')), 4);
        ?>
<div class="wrap">
<h1>🤖 AI Usage — Student Bot</h1>

<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px">
  <?php
  $total_qs = array_sum(array_column((array)$stats,'total_msgs'));
  $today_qs = array_sum(array_column((array)$stats,'today_msgs'));
  $revoked  = count(array_filter((array)$stats, fn($r) => $r->is_revoked));
  foreach([['Total questions asked',$total_qs,'#6C63FF'],['Asked today',$today_qs,'#1D9E75'],['Total cost (USD)','$'.number_format($today_cost,4),'#f59e0b'],['Access revoked',$revoked,'#dc2626']] as [$lbl,$val,$col]):
  ?>
  <div style="background:#f9fafb;border-radius:10px;padding:14px;text-align:center">
    <div style="font-size:22px;font-weight:500;color:<?php echo $col; ?>"><?php echo $val; ?></div>
    <div style="font-size:12px;color:#6b7280"><?php echo $lbl; ?></div>
  </div>
  <?php endforeach; ?>
</div>

<?php if(!empty($stats)): ?>
<table class="wp-list-table widefat fixed striped" style="border-radius:10px;overflow:hidden">
  <thead style="background:#f9fafb">
    <tr>
      <th>Student</th>
      <th>Today</th>
      <th>This week</th>
      <th>All time</th>
      <th>Cost (USD)</th>
      <th>Access</th>
      <th>Credits</th>
      <th>Actions</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach($stats as $s): ?>
  <tr>
    <td><strong><?php echo esc_html($s->display_name); ?></strong></td>
    <td><?php echo intval($s->today_msgs); ?></td>
    <td><?php echo intval($s->week_msgs); ?></td>
    <td><?php echo intval($s->total_msgs); ?></td>
    <td style="font-size:11px;color:#6b7280">$<?php echo number_format(floatval($s->total_cost),4); ?></td>
    <td>
      <?php if($s->is_revoked): ?>
      <span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:99px;font-size:11px">Revoked</span>
      <?php else: ?>
      <span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:99px;font-size:11px"><?php echo esc_html($s->access_type ?: 'free'); ?></span>
      <?php endif; ?>
    </td>
    <td><?php echo intval($s->credits_remaining); ?></td>
    <td style="display:flex;gap:4px;flex-wrap:wrap">
      <a href="?page=cias-access-control&action=add_credits&uid=<?php echo $s->ID; ?>&credits=50" class="button button-small" style="font-size:11px">+50</a>
      <a href="?page=cias-access-control&action=add_credits&uid=<?php echo $s->ID; ?>&credits=120" class="button button-small" style="font-size:11px">+120</a>
      <?php if($s->is_revoked): ?>
      <a href="?page=cias-access-control&action=restore&uid=<?php echo $s->ID; ?>" class="button button-small" style="font-size:11px;color:#1D9E75">Restore</a>
      <?php else: ?>
      <a href="?page=cias-access-control&action=revoke&uid=<?php echo $s->ID; ?>" class="button button-small" style="font-size:11px;color:#dc2626" onclick="return confirm('Revoke AI bot access for this student?')">Revoke</a>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php else: ?>
<p style="color:#9ca3af;padding:30px;text-align:center">No AI usage data yet. Enable the bot in Settings and students will appear here once they start using it.</p>
<?php endif; ?>
</div>
        <?php
    }

    /* ── Access Control ── */
    public function page_access_control() {
        global $wpdb;

        // Handle actions
        if (isset($_GET['action']) && isset($_GET['uid']) && current_user_can('manage_options')) {
            $uid    = intval($_GET['uid']);
            $action = sanitize_text_field($_GET['action']);
            if ($action === 'revoke') {
                CIAS_AI_Bot::revoke_access($uid, true);
                echo '<div class="notice notice-warning"><p>⛔ Bot access revoked for this student.</p></div>';
            } elseif ($action === 'restore') {
                CIAS_AI_Bot::revoke_access($uid, false);
                echo '<div class="notice notice-success"><p>✅ Bot access restored.</p></div>';
            } elseif ($action === 'unlimited') {
                CIAS_AI_Bot::grant_unlimited($uid);
                echo '<div class="notice notice-success"><p>✅ Unlimited access granted.</p></div>';
            } elseif ($action === 'add_credits' && isset($_GET['credits'])) {
                $credits = intval($_GET['credits']);
                CIAS_AI_Bot::add_credits($uid, $credits, 'manual_admin');
                echo '<div class="notice notice-success"><p>✅ ' . $credits . ' credits added.</p></div>';
            }
        }

        // Handle capability grant/revoke for Content Manager
        if (isset($_POST['cias_save_caps']) && check_admin_referer('cias_access_caps')) {
            $all_teachers = get_users(['role__in'=>['cias_teacher','cias_content_manager'],'fields'=>['ID']]);
            foreach ($all_teachers as $u) {
                $user = get_userdata($u->ID);
                $has_cm = !empty($_POST['cap_cm'][$u->ID]);
                $has_cm ? $user->add_cap('cias_use_content_manager') : $user->remove_cap('cias_use_content_manager');
            }
            echo '<div class="notice notice-success"><p>✅ Permissions updated.</p></div>';
        }

        $teachers = get_users(['role__in'=>['cias_teacher','cias_content_manager'],'orderby'=>'display_name']);
        $students = CIAS_AI_Bot::get_admin_usage_stats();
        ?>
<div class="wrap">
<h1>🔐 Access Control</h1>

<!-- Content Manager permissions -->
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px;margin-bottom:20px">
  <h2 style="font-size:15px;font-weight:500;margin:0 0 12px">Content Manager access</h2>
  <p style="font-size:13px;color:#6b7280;margin-bottom:14px">Tick to grant a teacher access to <strong>CIAS Tests → Content Manager</strong> (AI question generation).</p>
  <form method="post">
    <?php wp_nonce_field('cias_access_caps'); ?>
    <table class="wp-list-table widefat fixed striped" style="border-radius:8px;overflow:hidden">
      <thead><tr><th>Teacher</th><th>Role</th><th>Content Manager</th></tr></thead>
      <tbody>
      <?php foreach($teachers as $t): $has = user_can($t->ID, 'cias_use_content_manager'); ?>
      <tr>
        <td><strong><?php echo esc_html($t->display_name); ?></strong><br><small style="color:#6b7280"><?php echo esc_html($t->user_email); ?></small></td>
        <td><?php echo esc_html(implode(', ', array_keys($t->roles))); ?></td>
        <td><label><input type="checkbox" name="cap_cm[<?php echo $t->ID; ?>]" value="1" <?php checked($has); ?>> Allow</label></td>
      </tr>
      <?php endforeach; ?>
      <?php if(empty($teachers)): ?><tr><td colspan="3" style="color:#9ca3af;text-align:center">No teachers found.</td></tr><?php endif; ?>
      </tbody>
    </table>
    <button type="submit" name="cias_save_caps" class="button button-primary" style="margin-top:12px">Save permissions</button>
  </form>
</div>

<!-- AI Bot student access -->
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px">
  <h2 style="font-size:15px;font-weight:500;margin:0 0 6px">AI Bot student access</h2>
  <p style="font-size:13px;color:#6b7280;margin-bottom:14px">Manage per-student AI bot credits and access. Full usage stats available at <a href="?page=cias-ai-usage">AI Usage →</a></p>
  <table class="wp-list-table widefat fixed striped" style="border-radius:8px;overflow:hidden">
    <thead><tr><th>Student</th><th>Access type</th><th>Credits</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach($students as $s): ?>
    <tr>
      <td><strong><?php echo esc_html($s->display_name); ?></strong></td>
      <td><?php echo esc_html($s->access_type ?: 'free'); ?></td>
      <td><?php echo intval($s->credits_remaining); ?></td>
      <td>
        <?php if($s->is_revoked): ?>
        <span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:99px;font-size:11px">Revoked</span>
        <?php else: ?>
        <span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:99px;font-size:11px">Active</span>
        <?php endif; ?>
      </td>
      <td>
        <a href="?page=cias-access-control&action=add_credits&uid=<?php echo $s->ID; ?>&credits=50" class="button button-small" style="font-size:11px">+50 credits</a>
        <a href="?page=cias-access-control&action=add_credits&uid=<?php echo $s->ID; ?>&credits=120" class="button button-small" style="font-size:11px">+120</a>
        <a href="?page=cias-access-control&action=unlimited&uid=<?php echo $s->ID; ?>" class="button button-small" style="font-size:11px;color:#6C63FF">Unlimited</a>
        <?php if($s->is_revoked): ?>
        <a href="?page=cias-access-control&action=restore&uid=<?php echo $s->ID; ?>" class="button button-small" style="font-size:11px;color:#1D9E75">Restore</a>
        <?php else: ?>
        <a href="?page=cias-access-control&action=revoke&uid=<?php echo $s->ID; ?>" class="button button-small" style="font-size:11px;color:#dc2626" onclick="return confirm('Revoke?')">Revoke</a>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
</div>
        <?php
    }

    /* ── AI Guru Admin Overview ── */
    public function page_ai_guru() {
        global $wpdb;
        $lecture_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}caig_lectures");
        $plan_count    = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}caig_study_plans WHERE plan_date=CURDATE()");
        ?>
<div class="wrap">
<h1>🧠 CIAS AI Guru</h1>
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px">
  <?php foreach([
    ['Lectures in DB', $lecture_count, '#6C63FF', 'Add lectures at Lecture Mgr →'],
    ["Plans today", $plan_count, '#1D9E75', 'Students who got a plan today'],
    ['Shortcode', '[cias_ai_guru]', '#f59e0b', 'Add to any page to show AI Guru'],
  ] as [$lbl,$val,$col,$desc]): ?>
  <div style="background:#fff;border-radius:12px;padding:18px;border:1px solid #e5e7eb">
    <div style="font-size:22px;font-weight:500;color:<?php echo $col; ?>"><?php echo esc_html($val); ?></div>
    <div style="font-size:13px;font-weight:500;margin-top:4px"><?php echo $lbl; ?></div>
    <div style="font-size:12px;color:#6b7280;margin-top:2px"><?php echo $desc; ?></div>
  </div>
  <?php endforeach; ?>
</div>
<div style="background:#f0eeff;border:1px solid #c4b5fd;border-radius:10px;padding:14px 18px;font-size:13px">
  <strong>Setup:</strong> The AI Guru tab appears automatically in the student portal (<code>[cias_tests]</code> shortcode page).
  Add lectures at <a href="?page=cias-lecture-mgr">🎬 Lecture Mgr</a> so the recommendation engine can suggest them to students.
  No separate plugin install needed — AI Guru is now part of CIAS Test Engine v3.17.
</div>
</div>
        <?php
    }

    /* ── Lecture Manager ── */
    public function page_lecture_mgr() {
        global $wpdb;
        $subjects = $wpdb->get_results("SELECT id,name FROM " . CIAS_SUBJECTS . " ORDER BY name");
        $topics   = $wpdb->get_results("SELECT id,subject_id,name FROM " . CIAS_TOPICS . " ORDER BY name");
        $nonce    = wp_create_nonce('caig_nonce');
        ?>
<div class="wrap">
<h1 style="display:flex;align-items:center;gap:10px">🎬 Lecture Manager
  <span style="font-size:12px;font-weight:400;color:#6b7280">Used by AI Guru to recommend videos based on student weak areas</span>
</h1>

<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px;margin-bottom:16px">
  <h2 style="font-size:14px;font-weight:500;margin:0 0 14px">Add / Edit Lecture</h2>
  <form id="lec-form">
    <input type="hidden" id="lec-id">
    <div style="display:grid;grid-template-columns:1fr 1fr 120px;gap:10px;margin-bottom:10px">
      <div>
        <label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px">Subject *</label>
        <select id="lec-subject" style="width:100%;padding:7px 9px;border:0.5px solid #d1d5db;border-radius:8px;font-size:13px">
          <option value="">— Select —</option>
          <?php foreach($subjects as $s): ?><option value="<?php echo $s->id; ?>"><?php echo esc_html($s->name); ?></option><?php endforeach; ?>
        </select>
      </div>
      <div>
        <label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px">Topic</label>
        <select id="lec-topic" style="width:100%;padding:7px 9px;border:0.5px solid #d1d5db;border-radius:8px;font-size:13px">
          <option value="">— All topics —</option>
          <?php foreach($topics as $t): ?>
          <option value="<?php echo $t->id; ?>" data-sub="<?php echo $t->subject_id; ?>"><?php echo esc_html($t->name); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px">Lec # *</label>
        <input type="number" id="lec-num" min="1" style="width:100%;padding:7px 9px;border:0.5px solid #d1d5db;border-radius:8px;font-size:13px" placeholder="1">
      </div>
    </div>
    <div style="margin-bottom:10px">
      <label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px">Title *</label>
      <input type="text" id="lec-title" style="width:100%;padding:7px 9px;border:0.5px solid #d1d5db;border-radius:8px;font-size:13px" placeholder="e.g. Fundamental Rights — Part 1">
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:12px">
      <div>
        <label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px">Video URL</label>
        <input type="url" id="lec-url" style="width:100%;padding:7px 9px;border:0.5px solid #d1d5db;border-radius:8px;font-size:13px" placeholder="https://youtube.com/...">
      </div>
      <div>
        <label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px">Thumbnail URL</label>
        <input type="url" id="lec-thumb" style="width:100%;padding:7px 9px;border:0.5px solid #d1d5db;border-radius:8px;font-size:13px" placeholder="https://...">
      </div>
      <div>
        <label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px">Duration (min)</label>
        <input type="number" id="lec-dur" min="0" style="width:100%;padding:7px 9px;border:0.5px solid #d1d5db;border-radius:8px;font-size:13px" placeholder="45">
      </div>
    </div>
    <div style="display:flex;gap:8px;align-items:center">
      <button type="submit" class="button button-primary">💾 Save Lecture</button>
      <button type="button" id="lec-clear" class="button">✕ Clear</button>
      <span id="lec-msg" style="font-size:13px;color:#1D9E75"></span>
    </div>
  </form>
</div>

<table class="wp-list-table widefat fixed striped" style="border-radius:10px;overflow:hidden">
  <thead style="background:#f9fafb">
    <tr><th style="width:5%">#</th><th style="width:5%">Lec</th><th>Title</th><th>Subject</th><th>Topic</th><th>Video</th><th>Duration</th><th style="width:12%">Actions</th></tr>
  </thead>
  <tbody id="lec-tbody"><tr><td colspan="8" style="text-align:center;padding:20px;color:#9ca3af">Loading…</td></tr></tbody>
</table>

<script>
(function($){
  const nonce='<?php echo $nonce; ?>';
  const ajax='<?php echo admin_url('admin-ajax.php'); ?>';
  const topics=<?php echo wp_json_encode($topics); ?>;

  function loadLectures(){
    $.post(ajax,{action:'caig_get_lectures',nonce},function(r){
      if(!r.success){$('#lec-tbody').html('<tr><td colspan="8" style="text-align:center;color:#9ca3af">No lectures yet. Add one above.</td></tr>');return;}
      const rows=r.data.lectures;
      if(!rows.length){$('#lec-tbody').html('<tr><td colspan="8" style="text-align:center;padding:20px;color:#9ca3af">No lectures yet. Add one above.</td></tr>');return;}
      let html='';
      rows.forEach((l,i)=>{
        const topicName=topics.find(t=>t.id==l.topic_id)?.name||'—';
        html+=`<tr>
          <td>${i+1}</td><td><strong>${l.lecture_number}</strong></td>
          <td>${l.title}</td><td>${l.subject_name||l.subject_id}</td>
          <td style="font-size:12px;color:#6b7280">${topicName}</td>
          <td>${l.url?`<a href="${l.url}" target="_blank" style="font-size:12px">▶ View</a>`:'—'}</td>
          <td style="font-size:12px">${l.duration_min?l.duration_min+' min':'—'}</td>
          <td>
            <button class="button button-small lec-edit" data-id="${l.id}" style="font-size:11px">Edit</button>
            <button class="button button-small lec-del" data-id="${l.id}" style="font-size:11px;color:#dc2626">Delete</button>
          </td>
        </tr>`;
      });
      $('#lec-tbody').html(html);
    });
  }
  loadLectures();

  $('#lec-subject').on('change',function(){
    const sid=$(this).val();
    $('#lec-topic option').each(function(){
      if(!$(this).val())return;
      $(this).toggle(!sid||$(this).data('sub')==sid);
    });
    $('#lec-topic').val('');
  });

  $('#lec-form').on('submit',function(e){
    e.preventDefault();
    $.post(ajax,{
      action:'caig_save_lecture',nonce,
      id:$('#lec-id').val(),subject_id:$('#lec-subject').val(),topic_id:$('#lec-topic').val(),
      lecture_number:$('#lec-num').val(),title:$('#lec-title').val(),
      url:$('#lec-url').val(),thumbnail:$('#lec-thumb').val(),duration_min:$('#lec-dur').val()
    },function(r){
      if(r.success){$('#lec-msg').text('✅ Saved!').css('color','#1D9E75');loadLectures();$('#lec-id').val('');setTimeout(()=>$('#lec-msg').text(''),3000);}
      else $('#lec-msg').text(r.data?.message||'Error').css('color','#dc2626');
    });
  });

  $('#lec-clear').on('click',function(){
    $('#lec-id,#lec-num,#lec-title,#lec-url,#lec-thumb,#lec-dur').val('');
    $('#lec-subject,#lec-topic').val('');
  });

  $(document).on('click','.lec-del',function(){
    if(!confirm('Delete this lecture?'))return;
    $.post(ajax,{action:'caig_delete_lecture',nonce,id:$(this).data('id')},()=>loadLectures());
  });

  $(document).on('click','.lec-edit',function(){
    const id=$(this).data('id');
    const row=$(this).closest('tr');
    $('#lec-id').val(id);
    // Reload form from API data
    $.post(ajax,{action:'caig_get_lectures',nonce},function(r){
      const lec=r.data?.lectures?.find(l=>l.id==id);
      if(!lec)return;
      $('#lec-subject').val(lec.subject_id).trigger('change');
      $('#lec-topic').val(lec.topic_id);
      $('#lec-num').val(lec.lecture_number);
      $('#lec-title').val(lec.title);
      $('#lec-url').val(lec.url);
      $('#lec-thumb').val(lec.thumbnail);
      $('#lec-dur').val(lec.duration_min);
      $('html,body').animate({scrollTop:0},300);
    });
  });
})(jQuery);
</script>
</div>
        <?php
    }

    /* ── Reports ── */
    public function page_reports() {
        global $wpdb;
        $db       = new CIAS_DB();
        $is_admin   = current_user_can('manage_options');
        $is_teacher = current_user_can('cias_view_reports');
        $tab      = sanitize_text_field($_GET['tab'] ?? 'overview');
        $test_id  = intval($_GET['test_id'] ?? 0);
        if ($test_id) $tab = 'test';

        // For teachers, restrict to their batches
        $teacher_batch_ids = [];
        if (!$is_admin && $is_teacher) {
            $teacher_batch_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT batch_id FROM ".CIAS_TEACHER_BATCHES." WHERE user_id=%d", get_current_user_id()
            ));
        }

        $batches = $is_admin ? $db->get_batches_with_course() : $db->get_teacher_batches(get_current_user_id());
        $pass_pct = intval(get_option('cias_pass_percentage', 60));
        ?>
<div class="wrap">
<h1 style="display:flex;align-items:center;gap:10px">
  📊 Reports
  <?php if(!$is_admin): ?><span style="font-size:12px;font-weight:400;color:#6b7280">Showing your batch data</span><?php endif; ?>
</h1>
<nav class="nav-tab-wrapper" style="margin-bottom:0">
  <a href="?page=cias-reports&tab=overview"  class="nav-tab <?php echo $tab==='overview'?'nav-tab-active':''; ?>">📈 Overview</a>
  <a href="?page=cias-reports&tab=students"  class="nav-tab <?php echo $tab==='students'?'nav-tab-active':''; ?>">👤 By Student</a>
  <a href="?page=cias-reports&tab=batches"   class="nav-tab <?php echo $tab==='batches'?'nav-tab-active':''; ?>">👥 By Batch</a>
  <a href="?page=cias-reports&tab=test"      class="nav-tab <?php echo $tab==='test'?'nav-tab-active':''; ?>">📋 By Test</a>
  <a href="?page=cias-reports&tab=offline"   class="nav-tab <?php echo $tab==='offline'?'nav-tab-active':''; ?>">📝 Offline Tests</a>
</nav>
<div style="background:#fff;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 12px 12px;padding:20px;margin-bottom:20px">

<?php
        // ══ OVERVIEW TAB ══
        if ($tab === 'overview') {
            $sel_bid = intval($_GET['batch_id'] ?? 0);
            ?>
            <div style="margin-bottom:16px;display:flex;gap:10px;align-items:center">
              <label style="font-size:13px;font-weight:500">Batch:</label>
              <select onchange="location='?page=cias-reports&tab=overview&batch_id='+this.value" style="padding:6px 10px;border-radius:8px;border:1px solid #d1d5db;font-size:13px">
                <option value="0">All batches</option>
                <?php foreach($batches as $b): ?><option value="<?php echo $b->id; ?>" <?php selected($sel_bid,$b->id); ?>><?php echo esc_html(($b->course_name??'').' — '.$b->name); ?></option><?php endforeach; ?>
              </select>
            </div>
            <?php
            $batch_sql = $sel_bid ? $wpdb->prepare("AND e.batch_id=%d", $sel_bid) : '';
            if (!$is_admin && !empty($teacher_batch_ids)) {
                $in = implode(',', array_map('intval', $teacher_batch_ids));
                $batch_sql .= " AND e.batch_id IN($in)";
            }

            $online_stats = $wpdb->get_row("SELECT COUNT(*) AS total_tests, ROUND(AVG(a.percentage),1) AS avg_pct,
                COUNT(DISTINCT a.user_id) AS students_active
                FROM ".CIAS_ATTEMPTS." a
                JOIN ".CIAS_ENROLLMENTS." e ON a.user_id=e.user_id
                JOIN ".CIAS_TESTS." t ON a.test_id=t.id
                WHERE a.status='submitted' AND t.test_mode='online' $batch_sql");

            $offline_stats = $wpdb->get_row("SELECT COUNT(*) AS total_tests, ROUND(AVG(r.percentage),1) AS avg_pct,
                COUNT(DISTINCT r.user_id) AS students_active
                FROM ".CIAS_OFFLINE_RESULTS." r
                JOIN ".CIAS_ENROLLMENTS." e ON r.user_id=e.user_id
                JOIN ".CIAS_OFFLINE_TESTS." ot ON r.offline_test_id=ot.id
                WHERE ot.status='published' $batch_sql");
            ?>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px">
              <div style="background:#dbeafe;border-radius:12px;padding:18px">
                <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#1e40af;margin-bottom:12px">🌐 Online Tests</div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px">
                  <?php foreach([['Total Attempts',$online_stats->total_tests??0],['Avg Score',($online_stats->avg_pct??0).'%'],['Active Students',$online_stats->students_active??0]] as [$lbl,$val]): ?>
                  <div style="background:#fff;border-radius:8px;padding:10px;text-align:center">
                    <div style="font-size:20px;font-weight:700;color:#1e40af"><?php echo $val; ?></div>
                    <div style="font-size:11px;color:#6b7280"><?php echo $lbl; ?></div>
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>
              <div style="background:#fef3c7;border-radius:12px;padding:18px">
                <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#92400e;margin-bottom:12px">📝 Offline / Classroom Tests</div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px">
                  <?php foreach([['Total Results',$offline_stats->total_tests??0],['Avg Score',($offline_stats->avg_pct??0).'%'],['Students',$offline_stats->students_active??0]] as [$lbl,$val]): ?>
                  <div style="background:#fff;border-radius:8px;padding:10px;text-align:center">
                    <div style="font-size:20px;font-weight:700;color:#92400e"><?php echo $val; ?></div>
                    <div style="font-size:11px;color:#6b7280"><?php echo $lbl; ?></div>
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>

            <?php
            // Top performers
            $top_students = $wpdb->get_results("SELECT u.display_name, ROUND(AVG(a.percentage),1) AS avg_pct,
                COUNT(a.id) AS tests_taken
                FROM ".CIAS_ATTEMPTS." a
                JOIN {$wpdb->users} u ON a.user_id=u.ID
                JOIN ".CIAS_ENROLLMENTS." e ON a.user_id=e.user_id
                WHERE a.status='submitted' $batch_sql
                GROUP BY a.user_id ORDER BY avg_pct DESC LIMIT 10");
            if (!empty($top_students)): ?>
            <div style="margin-bottom:16px">
              <div style="font-size:13px;font-weight:700;margin-bottom:10px">🏆 Top Performers</div>
              <table class="wp-list-table widefat fixed striped" style="border-radius:8px;overflow:hidden">
                <thead><tr><th>Rank</th><th>Student</th><th>Tests Taken</th><th>Avg Score</th></tr></thead>
                <tbody>
                <?php foreach($top_students as $i => $s): $pct=floatval($s->avg_pct); ?>
                <tr>
                  <td><?php echo $i===0?'🥇':($i===1?'🥈':($i===2?'🥉':'#'.($i+1))); ?></td>
                  <td><?php echo esc_html($s->display_name); ?></td>
                  <td><?php echo intval($s->tests_taken); ?></td>
                  <td><span style="background:<?php echo $pct>=$pass_pct?'#dcfce7':'#fee2e2'; ?>;color:<?php echo $pct>=$pass_pct?'#166534':'#991b1b'; ?>;padding:2px 10px;border-radius:99px;font-weight:600"><?php echo $pct; ?>%</span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php endif;

        // ══ BY STUDENT TAB ══
        } elseif ($tab === 'students') {
            $sel_uid  = intval($_GET['user_id'] ?? 0);
            $mode_filter = sanitize_text_field($_GET['mode'] ?? '');
            $students = get_users(['role__in'=>['vocab_student'],'orderby'=>'display_name']);
            ?>
            <div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;align-items:center">
              <select onchange="location='?page=cias-reports&tab=students&user_id='+this.value+'&mode=<?php echo esc_attr($mode_filter); ?>'" style="padding:6px 10px;border-radius:8px;border:1px solid #d1d5db;font-size:13px">
                <option value="0">— Select Student —</option>
                <?php foreach($students as $u): ?><option value="<?php echo $u->ID; ?>" <?php selected($sel_uid,$u->ID); ?>><?php echo esc_html($u->display_name); ?></option><?php endforeach; ?>
              </select>
              <select onchange="location='?page=cias-reports&tab=students&user_id=<?php echo $sel_uid; ?>&mode='+this.value" style="padding:6px 10px;border-radius:8px;border:1px solid #d1d5db;font-size:13px">
                <option value="">All tests</option>
                <option value="online"  <?php selected($mode_filter,'online'); ?>>🌐 Online only</option>
                <option value="offline" <?php selected($mode_filter,'offline'); ?>>📝 Offline only</option>
              </select>
            </div>
            <?php
            if ($sel_uid) {
                $stu   = get_userdata($sel_uid);
                $summ  = $db->get_student_summary($sel_uid);
                echo '<h3 style="margin-bottom:14px">'.esc_html($stu->display_name).' — Performance</h3>';
                echo '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:20px">';
                foreach([['Tests Taken',$summ['total'],'#6C63FF'],['Avg Score',$summ['avg'].'%','#1D9E75'],['Best Score',$summ['best'].'%','#f59e0b'],['Pass Rate',$summ['pass_rate'].'%','#22c55e']] as [$lbl,$val,$col]):
                    echo "<div style='background:#f9fafb;border-radius:10px;padding:14px;text-align:center'><div style='font-size:24px;font-weight:700;color:{$col}'>{$val}</div><div style='font-size:12px;color:#6b7280'>{$lbl}</div></div>";
                endforeach;
                echo '</div>';

                // Online tests
                if (!$mode_filter || $mode_filter === 'online') {
                    $attempts = $db->get_student_attempts($sel_uid);
                    echo '<div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#1e40af;margin-bottom:8px">🌐 Online Tests</div>';
                    echo '<table class="wp-list-table widefat fixed striped" style="border-radius:8px;overflow:hidden;margin-bottom:20px"><thead><tr><th>Test</th><th>Date</th><th>Score</th><th>%</th><th>Result</th></tr></thead><tbody>';
                    if (empty($attempts)) echo '<tr><td colspan="5" style="text-align:center;color:#9ca3af">No online tests taken yet</td></tr>';
                    foreach ($attempts as $a) {
                        $pct = $a->total > 0 ? round(($a->score/$a->total)*100) : 0;
                        $pass_label = $pct>=$pass_pct ? '<span style="color:#166534;background:#dcfce7;padding:2px 8px;border-radius:99px;font-size:11px">Pass</span>' : '<span style="color:#991b1b;background:#fee2e2;padding:2px 8px;border-radius:99px;font-size:11px">Fail</span>';
                        echo "<tr><td>".esc_html($a->test_title)."</td><td>".date('d M Y',strtotime($a->submitted_at))."</td><td>{$a->score}/{$a->total}</td><td style='font-weight:600'>{$pct}%</td><td>{$pass_label}</td></tr>";
                    }
                    echo '</tbody></table>';
                }

                // Offline tests
                if (!$mode_filter || $mode_filter === 'offline') {
                    $offline = $db->get_student_offline_results($sel_uid);
                    echo '<div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#92400e;margin-bottom:8px">📝 Offline / Classroom Tests</div>';
                    echo '<table class="wp-list-table widefat fixed striped" style="border-radius:8px;overflow:hidden"><thead><tr><th>Test</th><th>Date</th><th>Marks</th><th>%</th><th>Grade</th></tr></thead><tbody>';
                    if (empty($offline)) echo '<tr><td colspan="5" style="text-align:center;color:#9ca3af">No offline test results yet</td></tr>';
                    foreach ($offline as $r):
                        if ($r->is_absent) { echo "<tr><td>".esc_html($r->title)."</td><td>".esc_html($r->date_conducted)."</td><td colspan='3' style='color:#9ca3af'>Absent</td></tr>"; continue; }
                        $pct2 = floatval($r->percentage);
                    ?>
                    <tr><td><?php echo esc_html($r->title); ?></td><td><?php echo esc_html($r->date_conducted); ?></td>
                    <td><?php echo floatval($r->marks_obtained); ?>/<?php echo intval($r->max_marks); ?></td>
                    <td style="font-weight:600"><?php echo $pct2; ?>%</td>
                    <td><span style="padding:2px 8px;border-radius:99px;font-size:11px;background:<?php echo $pct2>=$pass_pct?'#dcfce7':'#fee2e2'; ?>;color:<?php echo $pct2>=$pass_pct?'#166534':'#991b1b'; ?>"><?php echo esc_html($r->grade); ?></span></td>
                    </tr>
                    <?php endforeach;
                    echo '</tbody></table>';
                }
            }

        // ══ BY BATCH TAB ══
        } elseif ($tab === 'batches') {
            $sel_bid     = intval($_GET['batch_id'] ?? 0);
            $mode_filter = sanitize_text_field($_GET['mode'] ?? '');
            ?>
            <div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;align-items:center">
              <select onchange="location='?page=cias-reports&tab=batches&batch_id='+this.value+'&mode=<?php echo esc_attr($mode_filter); ?>'" style="padding:6px 10px;border-radius:8px;border:1px solid #d1d5db;font-size:13px">
                <option value="0">— Select Batch —</option>
                <?php foreach($batches as $b): ?><option value="<?php echo $b->id; ?>" <?php selected($sel_bid,$b->id); ?>><?php echo esc_html(($b->course_name??'').' — '.$b->name); ?></option><?php endforeach; ?>
              </select>
              <select onchange="location='?page=cias-reports&tab=batches&batch_id=<?php echo $sel_bid; ?>&mode='+this.value" style="padding:6px 10px;border-radius:8px;border:1px solid #d1d5db;font-size:13px">
                <option value="">All</option>
                <option value="online" <?php selected($mode_filter,'online'); ?>>🌐 Online</option>
                <option value="offline" <?php selected($mode_filter,'offline'); ?>>📝 Offline</option>
              </select>
            </div>
            <?php
            if ($sel_bid) {
                $report = $db->get_batch_report($sel_bid);
                echo '<div style="font-size:13px;font-weight:700;margin-bottom:10px">👥 Student-wise batch performance — click name for individual report</div>';
                echo '<table class="wp-list-table widefat fixed striped" style="border-radius:8px;overflow:hidden"><thead><tr><th>Rank</th><th>Student</th><th>Tests Taken</th><th>Avg Score</th><th>Best</th><th>Action</th></tr></thead><tbody>';
                foreach ($report as $i => $r) {
                    $pct = floatval($r->avg_pct);
                    echo "<tr>
                      <td style='font-weight:700'>".($i===0?'🥇':($i===1?'🥈':($i===2?'🥉':'#'.($i+1))))."</td>
                      <td><strong>".esc_html($r->display_name)."</strong></td>
                      <td>".intval($r->total_attempts)."</td>
                      <td><span style='background:".($pct>=$pass_pct?'#dcfce7':'#fee2e2').";color:".($pct>=$pass_pct?'#166534':'#991b1b').";padding:2px 10px;border-radius:99px;font-weight:600'>{$pct}%</span></td>
                      <td>".floatval($r->best_pct)."%</td>
                      <td><a href='?page=cias-reports&tab=students&user_id=".intval($r->user_id ?? 0)."' style='font-size:12px'>View details →</a></td>
                    </tr>";
                }
                if (empty($report)) echo '<tr><td colspan="6" style="text-align:center;color:#9ca3af">No attempts recorded yet</td></tr>';
                echo '</tbody></table>';
            }

        // ══ BY TEST TAB ══
        } elseif ($tab === 'test') {
            $mode_filter = sanitize_text_field($_GET['mode'] ?? '');
            $tests = $db->get_tests_list();
            ?>
            <div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;align-items:center">
              <select onchange="location='?page=cias-reports&tab=test&test_id='+this.value+'&mode=<?php echo esc_attr($mode_filter); ?>'" style="padding:6px 10px;border-radius:8px;border:1px solid #d1d5db;font-size:13px">
                <option value="0">— Select Test —</option>
                <?php foreach($tests as $t): ?><option value="<?php echo $t->id; ?>" <?php selected($test_id,$t->id); ?>><?php echo esc_html('['.($t->test_mode==='offline'?'Offline':'Online').'] '.$t->title); ?></option><?php endforeach; ?>
              </select>
            </div>
            <?php
            if ($test_id) {
                $test    = $db->get_by_id('tests', $test_id);
                $results = $db->get_test_results($test_id);
                $q_analysis = $db->get_question_analysis($test_id);
                $teacher = ($test->teacher_id ?? 0) ? get_userdata($test->teacher_id) : null;
                $mode_label = isset($test->test_mode) && $test->test_mode === 'offline' ? '📝 Offline' : '🌐 Online';
                echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:14px;align-items:center">';
                echo '<h3 style="margin:0">'.esc_html($test->title ?? '').'</h3>';
                echo '<span style="background:'.($test->test_mode==='offline'?'#fef3c7':'#dbeafe').';color:'.($test->test_mode==='offline'?'#92400e':'#1e40af').';padding:3px 12px;border-radius:99px;font-size:12px">'.$mode_label.'</span>';
                if ($teacher) echo '<span style="font-size:12px;color:#6b7280">Conducted by: '.esc_html($teacher->display_name).'</span>';
                echo '</div>';

                // Summary stats
                $total_attempts = count($results);
                $avg = $total_attempts > 0 ? round(array_sum(array_column((array)$results, 'percentage')) / $total_attempts, 1) : 0;
                $passed = count(array_filter((array)$results, function($r) use($pass_pct){ return $r->percentage >= $pass_pct; }));
                echo '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px">';
                foreach([['Attempts',$total_attempts,'#6C63FF'],['Avg Score',$avg.'%','#1D9E75'],['Passed',$passed,'#22c55e'],['Failed',$total_attempts-$passed,'#dc2626']] as [$lbl,$val,$col]):
                    echo "<div style='background:#f9fafb;border-radius:10px;padding:12px;text-align:center'><div style='font-size:22px;font-weight:700;color:{$col}'>{$val}</div><div style='font-size:11px;color:#6b7280'>{$lbl}</div></div>";
                endforeach;
                echo '</div>';

                echo '<table class="wp-list-table widefat fixed striped" style="border-radius:8px;overflow:hidden;margin-bottom:20px"><thead><tr><th>Student</th><th>Score</th><th>%</th><th>Time</th><th>Submitted</th><th>Result</th></tr></thead><tbody>';
                foreach ($results as $r) {
                    $pct = floatval($r->percentage);
                    $pass_label = $pct>=$pass_pct?'<span style="color:#166534;background:#dcfce7;padding:2px 8px;border-radius:99px;font-size:11px">Pass</span>':'<span style="color:#991b1b;background:#fee2e2;padding:2px 8px;border-radius:99px;font-size:11px">Fail</span>';
                    echo "<tr><td><a href='?page=cias-reports&tab=students&user_id=".intval($r->user_id ?? 0)."'>".esc_html($r->display_name)."</a></td><td>{$r->score}/{$r->total}</td><td style='font-weight:600'>{$pct}%</td><td>".gmdate('i:s',$r->time_taken ?? 0)."</td><td>".date('d M, H:i',strtotime($r->submitted_at))."</td><td>{$pass_label}</td></tr>";
                }
                if (empty($results)) echo '<tr><td colspan="6" style="text-align:center;color:#9ca3af">No attempts yet</td></tr>';
                echo '</tbody></table>';

                if (!empty($q_analysis)) {
                    echo '<div style="font-size:13px;font-weight:700;margin-bottom:10px">📊 Question-wise Accuracy</div>';
                    echo '<table class="wp-list-table widefat fixed striped" style="border-radius:8px;overflow:hidden"><thead><tr><th style="width:55%">Question</th><th>✅ Correct</th><th>❌ Wrong</th><th>Accuracy</th></tr></thead><tbody>';
                    foreach ($q_analysis as $q) {
                        $total_ans = $q->correct + $q->wrong;
                        $acc = $total_ans > 0 ? round($q->correct/$total_ans*100) : 0;
                        $bar = "<div style='background:#e5e7eb;height:8px;border-radius:99px;overflow:hidden;margin-top:3px'><div style='background:".($acc>=60?'#22c55e':'#ef4444').";height:100%;width:{$acc}%'></div></div>";
                        echo "<tr><td>".esc_html(mb_substr($q->question_text,0,80))."…</td><td>✅ {$q->correct}</td><td>❌ {$q->wrong}</td><td><strong>{$acc}%</strong>{$bar}</td></tr>";
                    }
                    echo '</tbody></table>';
                }
            }

        // ══ OFFLINE TESTS TAB ══
        } elseif ($tab === 'offline') {
            $sel_bid = intval($_GET['batch_id'] ?? 0);
            ?>
            <div style="display:flex;gap:10px;margin-bottom:16px;align-items:center">
              <label style="font-size:13px;font-weight:500">Batch:</label>
              <select onchange="location='?page=cias-reports&tab=offline&batch_id='+this.value" style="padding:6px 10px;border-radius:8px;border:1px solid #d1d5db;font-size:13px">
                <option value="0">All batches</option>
                <?php foreach($batches as $b): ?><option value="<?php echo $b->id; ?>" <?php selected($sel_bid,$b->id); ?>><?php echo esc_html(($b->course_name??'').' — '.$b->name); ?></option><?php endforeach; ?>
              </select>
            </div>
            <?php
            $batch_cond = $sel_bid ? $wpdb->prepare("AND ot.batch_id=%d", $sel_bid) : '';
            if (!$is_admin && !empty($teacher_batch_ids)) {
                $in = implode(',', array_map('intval', $teacher_batch_ids));
                $batch_cond .= " AND ot.batch_id IN($in)";
            }
            $offline_tests = $wpdb->get_results("SELECT ot.*, s.name AS subject_name,
                COUNT(DISTINCT r.user_id) AS total_students,
                ROUND(AVG(CASE WHEN r.is_absent=0 THEN r.percentage END),1) AS avg_pct,
                SUM(CASE WHEN r.is_absent=1 THEN 1 ELSE 0 END) AS absent_count
                FROM ".CIAS_OFFLINE_TESTS." ot
                LEFT JOIN ".CIAS_SUBJECTS." s ON ot.subject_id=s.id
                LEFT JOIN ".CIAS_OFFLINE_RESULTS." r ON r.offline_test_id=ot.id
                WHERE ot.status='published' $batch_cond
                GROUP BY ot.id ORDER BY ot.date_conducted DESC");
            ?>
            <table class="wp-list-table widefat fixed striped" style="border-radius:8px;overflow:hidden">
              <thead><tr><th>Test</th><th>Subject</th><th>Date</th><th>Max Marks</th><th>Students</th><th>Absent</th><th>Avg %</th><th>Actions</th></tr></thead>
              <tbody>
              <?php foreach($offline_tests as $ot): $avg=floatval($ot->avg_pct); ?>
              <tr>
                <td><strong><?php echo esc_html($ot->title); ?></strong><br><small style="color:#6b7280"><?php echo esc_html($ot->test_type ?? ''); ?></small></td>
                <td><?php echo esc_html($ot->subject_name ?? '—'); ?></td>
                <td><?php echo $ot->date_conducted ? date('d M Y', strtotime($ot->date_conducted)) : '—'; ?></td>
                <td><?php echo intval($ot->max_marks); ?></td>
                <td><?php echo intval($ot->total_students); ?></td>
                <td style="color:<?php echo intval($ot->absent_count)>0?'#dc2626':'#166534'; ?>"><?php echo intval($ot->absent_count); ?></td>
                <td><span style="background:<?php echo $avg>=$pass_pct?'#dcfce7':'#fee2e2'; ?>;color:<?php echo $avg>=$pass_pct?'#166534':'#991b1b'; ?>;padding:2px 10px;border-radius:99px;font-weight:600"><?php echo $avg; ?>%</span></td>
                <td><a href="?page=cias-reports&tab=offline&view_ot=<?php echo $ot->id; ?>&batch_id=<?php echo $sel_bid; ?>" style="font-size:12px">View results →</a></td>
              </tr>
              <?php endforeach; ?>
              <?php if(empty($offline_tests)): ?><tr><td colspan="8" style="text-align:center;color:#9ca3af">No offline tests published yet</td></tr><?php endif; ?>
              </tbody>
            </table>

            <?php
            // Individual offline test results
            $view_ot = intval($_GET['view_ot'] ?? 0);
            if ($view_ot):
                $ot_info = $wpdb->get_row($wpdb->prepare("SELECT ot.*, s.name AS subject_name FROM ".CIAS_OFFLINE_TESTS." ot LEFT JOIN ".CIAS_SUBJECTS." s ON ot.subject_id=s.id WHERE ot.id=%d", $view_ot));
                $ot_results = $wpdb->get_results($wpdb->prepare(
                    "SELECT r.*, u.display_name FROM ".CIAS_OFFLINE_RESULTS." r JOIN {$wpdb->users} u ON r.user_id=u.ID WHERE r.offline_test_id=%d ORDER BY r.percentage DESC", $view_ot
                ));
            ?>
            <div style="margin-top:20px;background:#fffbeb;border:1px solid #fde68a;border-radius:12px;padding:18px">
              <h3 style="margin:0 0 14px">📝 <?php echo esc_html($ot_info->title ?? ''); ?> — Individual Results</h3>
              <table class="wp-list-table widefat fixed striped" style="border-radius:8px;overflow:hidden">
                <thead><tr><th>Student</th><th>Marks</th><th>%</th><th>Grade</th><th>Attendance</th></tr></thead>
                <tbody>
                <?php foreach($ot_results as $i => $r): $pct=floatval($r->percentage); ?>
                <tr>
                  <td><a href="?page=cias-reports&tab=students&user_id=<?php echo $r->user_id; ?>"><?php echo esc_html($r->display_name); ?></a></td>
                  <td><?php echo $r->is_absent ? '—' : floatval($r->marks_obtained).'/'.intval($ot_info->max_marks); ?></td>
                  <td style="font-weight:600"><?php echo $r->is_absent ? '—' : $pct.'%'; ?></td>
                  <td><?php echo $r->is_absent ? '—' : '<span style="padding:2px 8px;border-radius:99px;font-size:11px;background:'.($pct>=$pass_pct?'#dcfce7':'#fee2e2').';color:'.($pct>=$pass_pct?'#166534':'#991b1b').'">'.esc_html($r->grade).'</span>'; ?></td>
                  <td><?php echo $r->is_absent ? '<span style="color:#dc2626">❌ Absent</span>' : '<span style="color:#166534">✅ Present</span>'; ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php endif; ?>

<?php } ?>
</div></div>
        <?php
    }

    /* ── Settings ── */
    public function page_settings() {
        // Handle manual send via GET (avoids nested form / nonce conflict)
        if (isset($_GET['send_test_daily']) && check_admin_referer('cias_send_test')) {
            $count    = CIAS_Email_Reports::send_daily_reports(true);
            $count_wa = get_option('cias_wa_enabled','0') === '1' ? CIAS_WhatsApp::send_daily_reports() : 0;
            echo '<div class="notice notice-success"><p>✅ Daily reports sent — Email: ' . intval($count) . ' | WhatsApp: ' . intval($count_wa) . '</p></div>';
        }
        if (isset($_GET['send_test_weekly']) && check_admin_referer('cias_send_test')) {
            $count    = CIAS_Email_Reports::send_weekly_reports(true);
            $count_wa = get_option('cias_wa_enabled','0') === '1' ? CIAS_WhatsApp::send_weekly_reports() : 0;
            echo '<div class="notice notice-success"><p>✅ Weekly reports sent — Email: ' . intval($count) . ' | WhatsApp: ' . intval($count_wa) . '</p></div>';
        }

        if (isset($_POST['cias_save_settings']) && check_admin_referer('cias_settings')) {
            // Only save API keys to DB if not defined in wp-config
            if (!defined('CIAS_ANTHROPIC_KEY') || !CIAS_ANTHROPIC_KEY)
                update_option('cias_anthropic_key', sanitize_text_field($_POST['anthropic_key'] ?? ''));
            if (!defined('CIAS_RAZORPAY_KEY_ID') || !CIAS_RAZORPAY_KEY_ID)
                update_option('cias_razorpay_key_id', sanitize_text_field($_POST['razorpay_key_id'] ?? ''));
            if (!defined('CIAS_RAZORPAY_KEY_SECRET') || !CIAS_RAZORPAY_KEY_SECRET)
                update_option('cias_razorpay_key_secret', sanitize_text_field($_POST['razorpay_key_secret'] ?? ''));

            update_option('cias_pass_percentage',       intval($_POST['pass_pct']));
            update_option('cias_show_answer_after',     sanitize_text_field($_POST['show_answer_after']));
            update_option('cias_brevo_wa_key',          sanitize_text_field($_POST['brevo_wa_key']));
            update_option('cias_brevo_wa_sender',       sanitize_text_field($_POST['brevo_wa_sender']));
            update_option('cias_wa_enabled',            isset($_POST['wa_enabled']) ? '1' : '0');
            update_option('cias_wa_ai_note',            isset($_POST['wa_ai_note']) ? '1' : '0');
            update_option('cias_email_reports_enabled', isset($_POST['email_reports_enabled']) ? '1' : '0');
            update_option('cias_email_post_test',       isset($_POST['email_post_test'])        ? '1' : '0');
            update_option('cias_ai_bot_enabled',        isset($_POST['ai_bot_enabled'])         ? '1' : '0');
            update_option('cias_ai_daily_call_limit',   intval($_POST['ai_daily_call_limit'] ?? 500));
            echo '<div class="notice notice-success"><p>✅ Settings saved!</p></div>';
        }
        $next_daily  = wp_next_scheduled('cias_daily_parent_report');
        $next_weekly = wp_next_scheduled('cias_weekly_parent_report');
        ?>
<div class="wrap"><h1>CIAS Test Engine — Settings</h1>
<form method="post"><?php wp_nonce_field('cias_settings'); ?>

<h2 style="margin:16px 0 8px">AI Settings</h2>
<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 14px;margin-bottom:12px;font-size:12px;color:#1e40af">
  🔒 <strong>Security tip:</strong> Add API keys to <code>wp-config.php</code> instead of here for maximum security:<br>
  <code>define('CIAS_ANTHROPIC_KEY', 'sk-ant-...');</code><br>
  <code>define('CIAS_RAZORPAY_KEY_ID', 'rzp_live_...');</code><br>
  <code>define('CIAS_RAZORPAY_KEY_SECRET', '...');</code>
</div>
<table class="form-table">
  <tr><th>Anthropic API Key</th><td>
    <?php if (defined('CIAS_ANTHROPIC_KEY') && CIAS_ANTHROPIC_KEY): ?>
    <div style="background:#d4edda;border:1px solid #c3e6cb;padding:8px 12px;border-radius:6px;font-size:13px">✅ Configured via wp-config.php (last 4: ...<?php echo substr(CIAS_ANTHROPIC_KEY,-4); ?>)</div>
    <?php else: ?>
    <input type="password" name="anthropic_key" value="<?php echo esc_attr(get_option('cias_anthropic_key','')); ?>" class="large-text" placeholder="sk-ant-...">
    <p class="description">⚠️ Move to wp-config.php for better security.</p>
    <?php endif; ?>
  </td></tr>
  <tr><th>Daily AI call limit</th><td>
    <input type="number" name="ai_daily_call_limit" value="<?php echo intval(get_option('cias_ai_daily_call_limit',500)); ?>" min="0" max="10000" style="width:100px"> calls/day (0 = unlimited)
    <p class="description">Hard cap on total Claude API calls per day across all features.</p>
  </td></tr>
  <tr><th>Pass Percentage</th><td>
    <input type="number" name="pass_pct" value="<?php echo intval(get_option('cias_pass_percentage',60)); ?>" min="1" max="100"> %
  </td></tr>
  <tr><th>Show Answer Key</th><td>
    <select name="show_answer_after">
      <option value="submit"   <?php selected(get_option('cias_show_answer_after','submit'),'submit'); ?>>Immediately after submission</option>
      <option value="deadline" <?php selected(get_option('cias_show_answer_after','submit'),'deadline'); ?>>After test deadline passes</option>
      <option value="never"    <?php selected(get_option('cias_show_answer_after','submit'),'never'); ?>>Never (admin only)</option>
    </select>
  </td></tr>
</table>

<h2 style="margin:24px 0 8px">🤖 AI Study Bot</h2>
<div style="background:#f0eeff;border:1px solid #c4b5fd;border-radius:8px;padding:10px 14px;margin-bottom:12px;font-size:13px">
  Students get <strong>5 free questions/day</strong>. They can buy credit packs via Razorpay to unlock more. Manage access at <a href="?page=cias-access-control">Access Control</a>.
</div>
<table class="form-table">
  <tr><th>Enable AI Bot</th><td>
    <label><input type="checkbox" name="ai_bot_enabled" <?php checked(get_option('cias_ai_bot_enabled','0'),'1'); ?>>
    Show "AI Tutor" tab in student portal</label>
  </td></tr>
  <tr><th>Razorpay Key ID (public)</th><td>
    <?php if (defined('CIAS_RAZORPAY_KEY_ID') && CIAS_RAZORPAY_KEY_ID): ?>
    <div style="background:#d4edda;border:1px solid #c3e6cb;padding:8px 12px;border-radius:6px;font-size:13px">✅ Configured via wp-config.php</div>
    <?php else: ?>
    <input type="text" name="razorpay_key_id" value="<?php echo esc_attr(get_option('cias_razorpay_key_id','')); ?>" class="large-text" placeholder="rzp_live_...">
    <?php endif; ?>
  </td></tr>
  <tr><th>Razorpay Key Secret</th><td>
    <?php if (defined('CIAS_RAZORPAY_KEY_SECRET') && CIAS_RAZORPAY_KEY_SECRET): ?>
    <div style="background:#d4edda;border:1px solid #c3e6cb;padding:8px 12px;border-radius:6px;font-size:13px">✅ Configured via wp-config.php</div>
    <?php else: ?>
    <input type="password" name="razorpay_key_secret" value="<?php echo esc_attr(get_option('cias_razorpay_key_secret','')); ?>" class="large-text" placeholder="Your Razorpay secret">
    <p class="description">⚠️ Add to wp-config.php: <code>define('CIAS_RAZORPAY_KEY_SECRET','...');</code></p>
    <?php endif; ?>
  </td></tr>
</table>

<h2 style="margin:24px 0 8px">📧 Email Parent Reports</h2>
<?php if (get_option('cias_email_reports_enabled','0') !== '1'): ?>
<div style="background:#fef2f2;border:2px solid #fca5a5;border-radius:8px;padding:12px 16px;margin-bottom:14px;font-size:13px;display:flex;align-items:center;gap:10px">
  <span style="font-size:20px">⚠️</span>
  <div><strong style="color:#dc2626">Email reports are currently DISABLED.</strong> This is why "Send Reports Now" sends 0 emails.
  Enable the checkbox below and click Save Settings to activate.</div>
</div>
<?php else: ?>
<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:12px 16px;margin-bottom:14px;font-size:13px">
  ✅ <strong>Email reports enabled.</strong> Automated reports send at 8 PM IST daily. Post-test instant emails also active.
</div>
<?php endif; ?>
<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px 16px;margin-bottom:14px;font-size:13px">
  <strong>Zero cost — uses your existing Brevo SMTP.</strong> Parents get a report at <strong>8 PM IST daily</strong>, every <strong>Sunday</strong> (weekly), and <strong>instantly when a student finishes a test</strong>.
  Add parent emails in <strong>CIAS Tests → Parents</strong>.
</div>
<table class="form-table">
  <tr><th>Enable Email Reports</th><td>
    <label><input type="checkbox" name="email_reports_enabled" <?php checked(get_option('cias_email_reports_enabled','0'),'1'); ?>>
    <strong>Send email reports to parents</strong> (daily at 8 PM IST + instant post-test)</label>
    <p class="description">Requires WP Mail SMTP configured with Brevo. Test at WP Mail SMTP → Tools → Email Test.</p>
  </td></tr>
  <tr><th>Post-test Instant Email</th><td>
    <label><input type="checkbox" name="email_post_test" <?php checked(get_option('cias_email_post_test','1'),'1'); ?>>
    Send result email to parent immediately when student finishes a test</label>
    <p class="description">Parent receives score and pass/fail result within seconds of the student submitting.</p>
  </td></tr>
  <tr><th>AI Personalised Note</th><td>
    <label><input type="checkbox" name="wa_ai_note" <?php checked(get_option('cias_wa_ai_note','0'),'1'); ?>>
    Include Claude AI-written personalised note in daily/weekly reports (~₹0.30/student/day)</label>
  </td></tr>
  <tr><th>Test email delivery</th><td>
    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=cias-settings&send_test_daily=1'), 'cias_send_test')); ?>"
       class="button" onclick="return confirm('Send daily reports to all parents now?')">
       📤 Send Daily Reports Now
    </a>
    <p class="description">Sends immediately to all parents with email set — bypasses enabled/disabled check. Check <a href="?page=cias-wa-logs">Email Logs</a> for status.</p>
  </td></tr>
</table>

<h2 style="margin:24px 0 8px">📱 WhatsApp Parent Reports — AiSensy</h2>
<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:12px 16px;margin-bottom:14px;font-size:13px">
  <strong>Setup guide:</strong>
  <ol style="margin:6px 0 0 16px;line-height:1.8">
    <li>Sign up at <a href="https://app.aisensy.com — free plan available!)</li>
    <li>Connect your WhatsApp Business number</li>
    <li>Go to <strong>Manage → API Key — copy your API key</li>
    <li>Create message templates for daily + weekly reports (we provide the template text below)</li>
    <li>Paste API key here → Save → Add parent numbers in <strong>CIAS Tests → Parents</strong></li>
  </ol>
</div>
<table class="form-table">
  <tr><th>Enable WhatsApp Reports</th><td>
    <label><input type="checkbox" name="wa_enabled" <?php checked(get_option('cias_wa_enabled','0'),'1'); ?>> Send daily + weekly WhatsApp reports to parents</label>
  </td></tr>
  <tr><th>AI Personalised Note</th><td>
    <label><input type="checkbox" name="wa_ai_note" <?php checked(get_option('cias_wa_ai_note','0'),'1'); ?>> Include Claude AI-written personalised note per student (~₹0.30/student/day)</label>
  </td></tr>
  <tr><th>AiSensy API Key</th><td>
    <input type="password" name="brevo_wa_key" value="<?php echo esc_attr(get_option('cias_brevo_wa_key','')); ?>" class="large-text" placeholder="Your AiSensy API key">
    <p class="description">Found in AiSensy Dashboard → Manage → API Key</p>
  </td></tr>
  <tr><th>Campaign Name</th><td>
    <input type="text" name="brevo_wa_sender" value="<?php echo esc_attr(get_option('cias_brevo_wa_sender','cias_daily_report')); ?>" class="regular-text" placeholder="cias_daily_report">
    <p class="description">Must match your AiSensy API Campaign name exactly.
  </td></tr>
  <tr><th>Message Templates</th><td>
    <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:12px;font-size:12px;font-family:monospace;white-space:pre-wrap;line-height:1.7">Daily template body:
📊 CIAS Daily Report - {{1}}
आज / Today: {{2}} tests | Avg: {{3}}% | Streak: {{4}} days
{{5}}
_CIAS - www.digitalsumedh.online_</div>
    <p class="description">Create template in AiSensy → category Utility → name cias_daily_report → body {{1}} → submit for approval. Then create an API Campaign linking this template.</p>
  </td></tr>
  <tr><th>Cron Status</th><td>
    <?php
    $next_daily  = wp_next_scheduled('cias_daily_parent_report');
    $next_weekly = wp_next_scheduled('cias_weekly_parent_report');
    ?>
    <div style="font-size:13px;margin-bottom:12px">
      <strong>Scheduled:</strong><br>
      Daily (8 PM IST): <strong><?php echo $next_daily ? '✅ '.date('d M Y, H:i T',$next_daily) : '❌ Not scheduled'; ?></strong><br>
      Weekly Sunday: <strong><?php echo $next_weekly ? '✅ '.date('d M Y, H:i T',$next_weekly) : '❌ Not scheduled'; ?></strong>
    </div>

    <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px;margin-bottom:12px;font-size:12px">
      <strong style="color:#1e40af">⚙️ Cloudways Setup (required):</strong><br>
      For automatic reports to run, you need a system cron on Cloudways:<br>
      1. Log in to Cloudways → Your server → Cron<br>
      2. Add: <code style="background:#fff;padding:2px 6px;border-radius:4px">0 14 * * * curl -s https://www.digitalsumedh.online/wp-cron.php?doing_wp_cron > /dev/null 2>&1</code><br>
      3. This triggers the cron at 14:30 UTC (8 PM IST) daily<br>
      Without this, reports only send when someone visits the site.
    </div>

    <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:12px;font-size:12px">
      <strong>📧 Manual Send (for testing):</strong>
      <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">
        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=cias-settings&send_test_daily=1'), 'cias_send_test')); ?>"
           class="button button-small" onclick="return confirm('Send daily reports to all parents now?')">
           📤 Send Daily Reports Now
        </a>
        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=cias-settings&send_test_weekly=1'), 'cias_send_test')); ?>"
           class="button button-small" onclick="return confirm('Send weekly reports to all parents now?')">
           📤 Send Weekly Reports Now
        </a>
      </div>
    </div>
  </td></tr>
</table>

<p class="submit"><input type="submit" name="cias_save_settings" class="button button-primary" value="Save Settings"></p>
</form>
</div>
        <?php
    }
}

CIAS_Test_Engine::get_instance();
