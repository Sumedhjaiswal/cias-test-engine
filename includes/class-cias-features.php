<?php
if (!defined('ABSPATH')) exit;

/* ══════════════════════════════════
   LEADERBOARD SHORTCODE
══════════════════════════════════ */
function cias_render_leaderboard() {
    if (!is_user_logged_in()) {
        return '<div class="cias-notice">Please <a href="' . esc_url(wp_login_url(get_permalink())) . '">log in</a> to view the leaderboard.</div>';
    }
    $user       = wp_get_current_user();
    $is_admin   = current_user_can('manage_options');
    $is_teacher = in_array('cias_teacher', (array)$user->roles);
    if (!$is_admin && !$is_teacher) return '<div class="cias-notice">Leaderboard is for teachers only.</div>';

    $db      = new CIAS_DB();
    $batches = $is_admin ? $db->get_batches_with_course() : $db->get_teacher_batches($user->ID);
    if (empty($batches)) return '<div class="cias-notice">No batches assigned yet.</div>';
    $subjects = $db->get_all('subjects');
    ob_start(); ?>
<div class="cias-app" id="cias-lb">
  <div class="cias-header" style="flex-wrap:wrap;gap:10px">
    <div style="font-size:16px;font-weight:500">🏆 Leaderboard</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <select id="lb-batch" onchange="CIAS_LB.load()">
        <?php foreach($batches as $b): ?>
        <option value="<?php echo intval($b->id); ?>"><?php echo esc_html(($b->course_name??'').' — '.$b->name); ?></option>
        <?php endforeach; ?>
      </select>
      <select id="lb-period" onchange="CIAS_LB.periodChange()">
        <option value="today">Today</option>
        <option value="week" selected>This week</option>
        <option value="month">This month</option>
        <option value="all">All time</option>
        <option value="custom">Custom range</option>
      </select>
      <div id="lb-custom-dates" style="display:none;gap:6px;align-items:center">
        <input type="date" id="lb-date-from" style="font-size:12px;padding:5px 8px;border-radius:6px">
        <span style="font-size:12px">to</span>
        <input type="date" id="lb-date-to" style="font-size:12px;padding:5px 8px;border-radius:6px">
        <button onclick="CIAS_LB.load()" class="cias-btn cias-btn-outline" style="font-size:12px;padding:5px 10px">Apply</button>
      </div>
      <select id="lb-subject" onchange="CIAS_LB.load()">
        <option value="0">All subjects</option>
        <?php foreach($subjects as $s): ?><option value="<?php echo intval($s->id); ?>"><?php echo esc_html($s->name); ?></option><?php endforeach; ?>
      </select>
      <button class="cias-btn cias-btn-outline" onclick="CIAS_LB.fullscreen()">⛶ Full screen</button>
    </div>
  </div>
  <div id="lb-board"><div class="cias-loading">Loading…</div></div>
  <div style="font-size:11px;color:var(--ct-muted);text-align:right;margin-top:8px">
    Auto-refreshes every 60 sec &nbsp;|&nbsp; <span id="lb-last-updated"></span>
  </div>
</div>
<style>
.lb-podium{display:grid;grid-template-columns:1fr 1.12fr 1fr;gap:10px;margin-bottom:14px;align-items:end}
.lb-pod{border:0.5px solid var(--ct-border);border-radius:16px;padding:16px 10px;text-align:center;background:var(--ct-card)}
.lb-pod.gold{border-color:#EF9F27;background:#FAEEDA}.lb-pod.silver{border-color:#B4B2A9;background:#F1EFE8}.lb-pod.bronze{border-color:#F0997B;background:#FAECE7}
.lb-pod-rank{font-size:22px;font-weight:500;margin-bottom:4px}
.lb-pod-rank.gold{color:#633806}.lb-pod-rank.silver{color:#444441}.lb-pod-rank.bronze{color:#712B13}
.lb-pod-av{width:48px;height:48px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:500;margin:0 auto 8px}
.lb-pod-av.gold{background:#FAC775;color:#412402}.lb-pod-av.silver{background:#D3D1C7;color:#2C2C2A}.lb-pod-av.bronze{background:#F5C4B3;color:#4A1B0C}
.lb-pod-name{font-size:13px;font-weight:500;margin-bottom:3px}.lb-pod-score{font-size:22px;font-weight:500;margin-bottom:3px}
.lb-pod-score.gold{color:#633806}.lb-pod-score.silver{color:#444441}.lb-pod-score.bronze{color:#712B13}
.lb-pod-sub{font-size:11px;color:var(--ct-muted)}
.lb-row{display:flex;align-items:center;gap:12px;padding:10px 14px;border:0.5px solid var(--ct-border);border-radius:12px;margin-bottom:6px;background:var(--ct-card)}
.lb-rnk{font-size:13px;font-weight:500;min-width:22px;color:var(--ct-muted)}.lb-av{width:32px;height:32px;border-radius:50%;background:#f0eeff;color:#534AB7;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:500}
.lb-name{flex:1;font-size:13px;font-weight:500}.lb-pct{font-size:14px;font-weight:500}.lb-meta{font-size:11px;color:var(--ct-muted)}
.lb-trend-up{color:var(--ct-green);font-size:11px}.lb-trend-dn{color:var(--ct-red);font-size:11px}.lb-trend-nc{color:var(--ct-muted);font-size:11px}
.lb-empty{text-align:center;padding:40px;color:var(--ct-muted)}
.cias-notice{padding:16px;background:var(--ct-bg);border-radius:12px;font-size:14px;color:var(--ct-muted)}
#cias-lb.fullscreen-mode{position:fixed;inset:0;z-index:99999;background:#fff;overflow-y:auto;padding:20px}
</style>
<script>
var CIAS_LB = {
  interval: null,
  load: function() {
    var period = jQuery('#lb-period').val();
    var from   = period==='custom' ? jQuery('#lb-date-from').val() : '';
    var to     = period==='custom' ? jQuery('#lb-date-to').val()   : '';
    jQuery('#lb-board').html('<div class="cias-loading">Loading…</div>');
    jQuery.post(CIASTest.ajax_url, {
      action:'cias_get_leaderboard', nonce:CIASTest.nonce,
      batch_id:   jQuery('#lb-batch').val(),
      period:     period,
      subject_id: jQuery('#lb-subject').val(),
      date_from:  from,
      date_to:    to
    }, function(r){
      if (r.success) {
        jQuery('#lb-board').html(r.data.html);
        var now = new Date();
        jQuery('#lb-last-updated').text('Updated ' + now.getHours()+':'+String(now.getMinutes()).padStart(2,'0'));
      }
    });
  },
  periodChange: function() {
    var p = jQuery('#lb-period').val();
    jQuery('#lb-custom-dates').css('display', p==='custom' ? 'flex' : 'none');
    if (p !== 'custom') CIAS_LB.load();
  },
  start: function() {
    this.load();
    this.interval = setInterval(function(){ CIAS_LB.load(); }, 60000);
  },
  fullscreen: function() {
    var el = document.getElementById('cias-lb');
    el.classList.toggle('fullscreen-mode');
    if (el.classList.contains('fullscreen-mode') && document.fullscreenElement === null) {
      el.requestFullscreen && el.requestFullscreen();
    } else {
      document.exitFullscreen && document.exitFullscreen();
    }
  }
};
jQuery(document).ready(function(){ CIAS_LB.start(); });
</script>
<?php
    return ob_get_clean();
}
add_shortcode('cias_leaderboard', 'cias_render_leaderboard');

/* ══════════════════════════════════
   TEACHER DASHBOARD SHORTCODE
══════════════════════════════════ */
function cias_render_teacher_dashboard() {
    if (!is_user_logged_in()) return '<div class="cias-notice">Please log in.</div>';
    $user     = wp_get_current_user();
    $is_admin = current_user_can('manage_options');
    $is_teacher = in_array('cias_teacher', (array)$user->roles);
    if (!$is_admin && !$is_teacher) return '<div class="cias-notice">Teacher access required.</div>';

    $db      = new CIAS_DB();
    $batches = $is_admin ? $db->get_batches_with_course() : $db->get_teacher_batches($user->ID);
    if (empty($batches)) return '<div class="cias-notice">No batches assigned.</div>';
    ob_start(); ?>
<div class="cias-app" id="cias-tdash">
  <div class="cias-header" style="flex-wrap:wrap;gap:10px">
    <div style="font-size:16px;font-weight:500">📊 Class Performance</div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <select id="td-batch" onchange="CIAS_TD.load()">
        <?php foreach($batches as $b): ?>
        <option value="<?php echo intval($b->id); ?>"><?php echo esc_html(($b->course_name??'').' — '.$b->name); ?></option>
        <?php endforeach; ?>
      </select>
      <select id="td-weeks" onchange="CIAS_TD.load()">
        <option value="4">Last 4 weeks</option>
        <option value="8">Last 8 weeks</option>
        <option value="12">Last 12 weeks</option>
      </select>
    </div>
  </div>
  <div id="td-content"><div class="cias-loading">Loading dashboard…</div></div>

  <!-- AI Overview Chat -->
  <div style="margin-top:16px;background:linear-gradient(135deg,#f0eeff 0%,#e8f4ff 100%);border:1px solid #c4b5fd;border-radius:16px;padding:16px">
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
      <span style="font-size:20px">🤖</span>
      <div>
        <div style="font-size:13px;font-weight:600;color:#4c1d95">AI Class Assistant</div>
        <div style="font-size:11px;color:#7c3aed">Ask anything about your batch performance</div>
      </div>
    </div>

    <!-- Quick prompt chips -->
    <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px" id="td-ai-chips">
      <?php foreach([
        'Who needs attention this week?',
        'What topics should I focus on tomorrow?',
        'Give me a full batch report',
        'Who is improving the most?',
        'Which students are at risk of dropping out?',
        'Compare performance across subjects',
      ] as $chip): ?>
      <button onclick="CIAS_TD.setQ(this.textContent)"
              style="background:#fff;border:1px solid #c4b5fd;border-radius:99px;padding:5px 12px;font-size:12px;color:#6C63FF;cursor:pointer;transition:all .15s"
              onmouseover="this.style.background='#6C63FF';this.style.color='#fff'"
              onmouseout="this.style.background='#fff';this.style.color='#6C63FF'">
        <?php echo esc_html($chip); ?>
      </button>
      <?php endforeach; ?>
    </div>

    <div style="display:flex;gap:8px">
      <input type="text" id="td-ai-input" placeholder="Ask about your class…"
             style="flex:1;padding:10px 14px;border:1px solid #c4b5fd;border-radius:10px;font-size:13px;outline:none;background:#fff"
             onkeydown="if(event.key==='Enter') CIAS_TD.askAI()">
      <button onclick="CIAS_TD.askAI()" id="td-ai-btn"
              style="background:#6C63FF;color:#fff;border:none;border-radius:10px;padding:10px 18px;font-size:13px;font-weight:600;cursor:pointer;white-space:nowrap">
        Ask AI ✨
      </button>
    </div>

    <!-- Chat history -->
    <div id="td-ai-chat" style="margin-top:10px;display:none;max-height:500px;overflow-y:auto"></div>
  </div>
</div>
<script>
var CIAS_TD = {
  currentBatch: 0,
  load: function() {
    CIAS_TD.currentBatch = jQuery('#td-batch').val();
    jQuery('#td-content').html('<div class="cias-loading">Loading…</div>');
    jQuery.post(CIASTest.ajax_url, {
      action:'cias_get_teacher_dashboard', nonce:CIASTest.nonce,
      batch_id: CIAS_TD.currentBatch,
      weeks:    jQuery('#td-weeks').val()
    }, function(r){
      if (r.success) jQuery('#td-content').html(r.data.html);
      else jQuery('#td-content').html('<p style="color:red;padding:20px">Error loading dashboard. Please refresh.</p>');
    });
  },
  loadStudent: function(uid, name) {
    jQuery('#td-student-name').text(name);
    jQuery('#td-student-panel').show();
    jQuery('#td-student-content').html('<div class="cias-loading">Loading ' + name + '\'s data…</div>');
    jQuery('html,body').animate({scrollTop: jQuery('#td-student-panel').offset().top - 20}, 400);
    jQuery.post(CIASTest.ajax_url, {
      action:'cias_get_student_detail', nonce:CIASTest.nonce,
      student_id: uid,
      batch_id:   CIAS_TD.currentBatch
    }, function(r){
      if (r.success) jQuery('#td-student-content').html(r.data.html);
    });
  },
  closeStudent: function() {
    jQuery('#td-student-panel').hide();
  },
  setQ: function(text) {
    jQuery('#td-ai-input').val(text).focus();
  },
  askAI: function() {
    var q = jQuery('#td-ai-input').val().trim();
    if (!q) return;

    var chat = jQuery('#td-ai-chat');
    chat.show();

    // Add user bubble
    chat.append(
      '<div style="display:flex;justify-content:flex-end;margin-bottom:8px">' +
      '<div style="background:#6C63FF;color:#fff;padding:9px 14px;border-radius:14px 14px 2px 14px;font-size:13px;max-width:80%">' +
      jQuery('<div>').text(q).html() + '</div></div>'
    );

    // Add loading bubble
    var loadId = 'ai-load-' + Date.now();
    chat.append(
      '<div id="' + loadId + '" style="display:flex;gap:8px;margin-bottom:8px;align-items:flex-start">' +
      '<span style="font-size:18px">🤖</span>' +
      '<div style="background:#fff;border:1px solid #e5e7eb;padding:9px 14px;border-radius:14px 14px 14px 2px;font-size:13px;color:#6b7280">Analysing class data…</div>' +
      '</div>'
    );
    chat.scrollTop(chat[0].scrollHeight);

    jQuery('#td-ai-input').val('').prop('disabled', true);
    jQuery('#td-ai-btn').text('…').prop('disabled', true);

    jQuery.post(CIASTest.ajax_url, {
      action:    'cias_ai_overview',
      nonce:     CIASTest.nonce,
      batch_id:  CIAS_TD.currentBatch,
      question:  q
    }, function(r) {
      jQuery('#' + loadId).remove();
      jQuery('#td-ai-input').prop('disabled', false);
      jQuery('#td-ai-btn').text('Ask AI ✨').prop('disabled', false);

      if (r.success) {
        chat.append(
          '<div style="display:flex;gap:8px;margin-bottom:12px;align-items:flex-start">' +
          '<span style="font-size:18px;flex-shrink:0">🤖</span>' +
          '<div style="background:#fff;border:1px solid #c4b5fd;padding:12px 16px;border-radius:14px 14px 14px 2px;font-size:13px;line-height:1.7;max-width:90%;box-shadow:0 1px 4px rgba(108,99,255,.1)">' +
          r.data.answer + '</div></div>'
        );
      } else {
        chat.append(
          '<div style="display:flex;gap:8px;margin-bottom:8px">' +
          '<span style="font-size:18px">⚠️</span>' +
          '<div style="background:#fef2f2;border:1px solid #fca5a5;padding:9px 14px;border-radius:14px;font-size:13px;color:#991b1b">' +
          (r.data.message || 'Something went wrong.') + '</div></div>'
        );
      }
      chat.scrollTop(chat[0].scrollHeight);
    });
  }
};
jQuery(document).ready(function(){ CIAS_TD.load(); });
</script>
<?php
    return ob_get_clean();
}
add_shortcode('cias_teacher_dashboard', 'cias_render_teacher_dashboard');

/* ══════════════════════════════════
   TEACHERS ADMIN PAGE
══════════════════════════════════ */
function cias_page_teachers() {
    $db = new CIAS_DB();

    // Handle save
    if (isset($_POST['cias_save_teacher']) && check_admin_referer('cias_teacher_save')) {
        $uid      = intval($_POST['teacher_user_id'] ?? 0);
        $batch_ids= array_map('intval', $_POST['batch_ids'] ?? []);

        // Assign role
        if ($uid) {
            $u = new WP_User($uid);
            $u->set_role('cias_teacher');
            $db->set_teacher_batches($uid, $batch_ids);
            echo '<div class="notice notice-success"><p>Teacher saved!</p></div>';
        }
    }

    // Handle revoke
    if (isset($_GET['revoke']) && current_user_can('cias_manage_teachers')) {
        $uid = intval($_GET['revoke']);
        $u   = new WP_User($uid);
        $u->remove_role('cias_teacher');
        $db->set_teacher_batches($uid, []);
        echo '<div class="notice notice-success"><p>Teacher access removed.</p></div>';
    }

    $batches  = $db->get_batches_with_course();
    $teachers = get_users(['role' => 'cias_teacher']);
    $all_users= get_users(['orderby' => 'display_name', 'role__not_in' => ['cias_teacher']]);
    ?>
<div class="wrap"><h1>Teachers</h1>
<div style="display:grid;grid-template-columns:360px 1fr;gap:24px;margin-top:16px">

  <div>
    <h3>Assign Teacher</h3>
    <form method="post"><?php wp_nonce_field('cias_teacher_save'); ?>
    <table class="form-table">
      <tr><th>User</th><td>
        <select name="teacher_user_id" style="width:100%">
          <option value="">— Select user —</option>
          <?php foreach($all_users as $u): ?>
          <option value="<?php echo $u->ID; ?>"><?php echo esc_html($u->display_name . ' (' . $u->user_email . ')'); ?></option>
          <?php endforeach; ?>
        </select>
        <p class="description">User will be assigned the CIAS Teacher role.</p>
      </td></tr>
      <tr><th>Assign to batches</th><td>
        <div style="max-height:200px;overflow-y:auto;border:1px solid #ddd;padding:10px;border-radius:4px">
          <?php foreach($batches as $b): ?>
          <label style="display:block;margin-bottom:6px">
            <input type="checkbox" name="batch_ids[]" value="<?php echo $b->id; ?>">
            <?php echo esc_html(($b->course_name??'').' — '.$b->name); ?>
          </label>
          <?php endforeach; ?>
        </div>
        <p class="description">Teacher will only see students and data from assigned batches.</p>
      </td></tr>
    </table>
    <p><input type="submit" name="cias_save_teacher" class="button button-primary" value="Assign Teacher"></p>
    </form>
  </div>

  <div>
    <h3>Current Teachers</h3>
    <table class="wp-list-table widefat fixed striped">
      <thead><tr><th>Name</th><th>Email</th><th>Assigned Batches</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach($teachers as $t):
          $t_batches = $db->get_teacher_batches($t->ID);
          $batch_names = array_map(function($b) { return ($b->course_name??'').' — '.$b->name; }, $t_batches);
      ?>
      <tr>
        <td><strong><?php echo esc_html($t->display_name); ?></strong></td>
        <td><?php echo esc_html($t->user_email); ?></td>
        <td>
          <?php if (empty($batch_names)): ?>
          <span style="color:#999">No batches assigned</span>
          <?php else: ?>
          <?php echo esc_html(implode(', ', $batch_names)); ?>
          <?php endif; ?>
        </td>
        <td>
          <a href="?page=cias-teachers&edit=<?php echo $t->ID; ?>" class="button button-small">Edit batches</a>
          &nbsp;
          <a href="<?php echo wp_nonce_url('?page=cias-teachers&revoke='.$t->ID,'cias_teacher_save'); ?>"
             class="button button-small"
             style="color:#dc2626"
             onclick="return confirm('Remove teacher access?')">Revoke</a>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if(empty($teachers)): ?><tr><td colspan="4" style="color:#999;text-align:center">No teachers assigned yet.</td></tr><?php endif; ?>
      </tbody>
    </table>

    <div style="margin-top:20px;padding:14px;background:#f0f9ff;border-radius:8px;font-size:13px">
      <strong>Shortcodes for teachers:</strong><br>
      <code>[cias_leaderboard]</code> — Classroom leaderboard (login required)<br>
      <code>[cias_teacher_dashboard]</code> — Class performance dashboard<br>
      Create pages with these shortcodes and share the URLs with your teachers.
    </div>
  </div>

</div></div>
<?php
}

/* ══════════════════════════════════
   OFFLINE TESTS ADMIN PAGE
══════════════════════════════════ */
function cias_page_offline_tests() {
    $db  = new CIAS_DB();
    $action = $_GET['action'] ?? 'list';

    // Release toggle
    if (isset($_GET['release']) && current_user_can('cias_release_offline')) {
        $db->toggle_offline_test_status(intval($_GET['release']));
        echo '<div class="notice notice-success"><p>Test results status updated.</p></div>';
    }

    // Delete
    if (isset($_GET['delete_ot']) && current_user_can('cias_release_offline')) {
        $db->delete_offline_test(intval($_GET['delete_ot']));
        echo '<div class="notice notice-success"><p>Offline test deleted.</p></div>';
    }

    // Save offline test details
    if (isset($_POST['cias_save_ot']) && check_admin_referer('cias_offline_test')) {
        $tid = intval($_POST['ot_id'] ?? 0);
        $data = [
            'title'          => sanitize_text_field($_POST['title']),
            'batch_id'       => intval($_POST['batch_id']),
            'subject_id'     => intval($_POST['subject_id']),
            'test_type'      => sanitize_text_field($_POST['test_type']),
            'date_conducted' => sanitize_text_field($_POST['date_conducted']),
            'max_marks'      => intval($_POST['max_marks']),
            'created_by'     => get_current_user_id(),
        ];
        if ($tid) {
            $db->update_offline_test($tid, $data);
        } else {
            $tid = $db->create_offline_test($data);
        }
        // Redirect to results entry
        wp_redirect(admin_url('admin.php?page=cias-offline&action=results&id=' . $tid));
        exit;
    }

    // Save results
    if (isset($_POST['cias_save_results']) && check_admin_referer('cias_offline_results')) {
        $tid     = intval($_POST['ot_id']);
        $test    = $db->get_offline_test($tid);
        $marks   = $_POST['marks'] ?? [];
        $absents = $_POST['absent'] ?? [];

        foreach ($marks as $uid => $m) {
            $uid = intval($uid);
            $m   = floatval($m);
            $is_absent = isset($absents[$uid]) ? 1 : 0;
            $pct = ($test && $test->max_marks > 0) ? round(($m / $test->max_marks) * 100, 1) : 0;
            $grade = $pct >= 75 ? 'Distinction' : ($pct >= 60 ? 'Pass' : ($pct >= 40 ? 'Average' : 'Below Average'));
            $db->save_offline_result($tid, $uid, $m, $is_absent, $pct, $grade);
        }
        echo '<div class="notice notice-success"><p>Results saved! Use the Release toggle to publish to students.</p></div>';
    }

    $batches  = $db->get_batches_with_course();
    $subjects = $db->get_all('subjects');

    if ($action === 'add' || $action === 'edit'):
        $editing = ($action === 'edit' && isset($_GET['id'])) ? $db->get_offline_test(intval($_GET['id'])) : null;
    ?>
<div class="wrap"><h1><?php echo $editing ? 'Edit' : 'Create'; ?> Offline Test <a href="?page=cias-offline" class="button" style="margin-left:10px">← Back</a></h1>
<form method="post" style="max-width:600px"><?php wp_nonce_field('cias_offline_test'); ?>
<input type="hidden" name="ot_id" value="<?php echo $editing ? intval($editing->id) : 0; ?>">
<table class="form-table">
  <tr><th>Test Name</th><td><input type="text" name="title" value="<?php echo esc_attr($editing->title??''); ?>" class="large-text" required placeholder="e.g. Weekly surprise test #4"></td></tr>
  <tr><th>Batch</th><td><select name="batch_id" required><?php foreach($batches as $b): ?><option value="<?php echo $b->id; ?>" <?php selected(($editing->batch_id??0),$b->id); ?>><?php echo esc_html(($b->course_name??'').' — '.$b->name); ?></option><?php endforeach; ?></select></td></tr>
  <tr><th>Subject</th><td><select name="subject_id"><option value="0">General</option><?php foreach($subjects as $s): ?><option value="<?php echo $s->id; ?>" <?php selected(($editing->subject_id??0),$s->id); ?>><?php echo esc_html($s->name); ?></option><?php endforeach; ?></select></td></tr>
  <tr><th>Test Type</th><td><select name="test_type"><option value="surprise" <?php selected(($editing->test_type??'surprise'),'surprise'); ?>>Surprise Test</option><option value="weekly" <?php selected(($editing->test_type??''),'weekly'); ?>>Weekly Test</option><option value="mock" <?php selected(($editing->test_type??''),'mock'); ?>>Mock Exam</option></select></td></tr>
  <tr><th>Date Conducted</th><td><input type="date" name="date_conducted" value="<?php echo esc_attr($editing->date_conducted??''); ?>" required></td></tr>
  <tr><th>Maximum Marks</th><td><input type="number" name="max_marks" value="<?php echo intval($editing->max_marks??100); ?>" min="1" max="1000" required></td></tr>
</table>
<p class="submit"><input type="submit" name="cias_save_ot" class="button button-primary" value="Save & Enter Marks →"></p>
</form></div>

    <?php elseif ($action === 'results' && isset($_GET['id'])):
        $tid  = intval($_GET['id']);
        $test = $db->get_offline_test($tid);
        if (!$test) { echo '<div class="wrap"><p>Test not found.</p></div>'; return; }
        $students  = $db->get_batch_students($test->batch_id);
        $existing  = $db->get_offline_results($tid);
        $res_map   = [];
        foreach ($existing as $r) $res_map[$r->user_id] = $r;
    ?>
<div class="wrap">
<h1>Enter Marks — <?php echo esc_html($test->title); ?> <a href="?page=cias-offline" class="button" style="margin-left:10px">← Back</a></h1>
<div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px">
  Batch: <strong><?php echo esc_html($test->batch_name??''); ?></strong> &nbsp;|&nbsp;
  Date: <strong><?php echo esc_html($test->date_conducted); ?></strong> &nbsp;|&nbsp;
  Max marks: <strong><?php echo intval($test->max_marks); ?></strong> &nbsp;|&nbsp;
  Status: <strong><?php echo $test->status === 'published' ? '🟢 Published' : '🟡 Draft (not visible to students)'; ?></strong>
</div>
<form method="post"><?php wp_nonce_field('cias_offline_results'); ?>
<input type="hidden" name="ot_id" value="<?php echo intval($tid); ?>">
<table class="wp-list-table widefat fixed striped">
  <thead><tr><th>Student</th><th>Marks Obtained</th><th>Out of</th><th>Auto Grade</th><th>Absent</th></tr></thead>
  <tbody>
  <?php foreach($students as $s):
    $r = $res_map[$s->ID] ?? null;
  ?>
  <tr>
    <td><strong><?php echo esc_html($s->display_name); ?></strong><br><small><?php echo esc_html($s->user_email); ?></small></td>
    <td><input type="number" name="marks[<?php echo $s->ID; ?>]" value="<?php echo $r ? floatval($r->marks_obtained) : ''; ?>" min="0" max="<?php echo intval($test->max_marks); ?>" step="0.5" style="width:80px" oninput="calcGrade(this,<?php echo intval($test->max_marks); ?>)"></td>
    <td><?php echo intval($test->max_marks); ?></td>
    <td class="grade-cell-<?php echo $s->ID; ?>" style="font-size:12px;color:#6b7280">
      <?php echo $r ? esc_html($r->grade . ' (' . $r->percentage . '%)') : '—'; ?>
    </td>
    <td style="text-align:center"><input type="checkbox" name="absent[<?php echo $s->ID; ?>]" value="1" <?php checked($r && $r->is_absent); ?>></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<div style="display:flex;gap:10px;margin-top:14px">
  <input type="submit" name="cias_save_results" class="button button-primary" value="💾 Save Results">
  <?php if (current_user_can('cias_release_offline')): ?>
  <a href="?page=cias-offline&release=<?php echo intval($tid); ?>" class="button <?php echo $test->status==='published'?'':'button-primary'; ?>" style="<?php echo $test->status==='published'?'color:#dc2626':''; ?>" onclick="return confirm('<?php echo $test->status==='published'?'Hide results from students?':'Release results to students?'; ?>')">
    <?php echo $test->status === 'published' ? '🔒 Unpublish results' : '🚀 Release to students'; ?>
  </a>
  <?php endif; ?>
</div>
</form>
<script>
function calcGrade(inp, max) {
  var v   = parseFloat(inp.value) || 0;
  var uid = inp.name.match(/\d+/)[0];
  var pct = max > 0 ? Math.round((v/max)*100) : 0;
  var g   = pct>=75?'Distinction':pct>=60?'Pass':pct>=40?'Average':'Below average';
  var col = pct>=60?'#16a34a':pct>=40?'#d97706':'#dc2626';
  document.querySelector('.grade-cell-'+uid).innerHTML = '<span style="color:'+col+'">'+g+' ('+pct+'%)</span>';
}
</script>
</div>

    <?php else:
        $tests = $db->get_offline_tests_list();
    ?>
<div class="wrap">
<h1>Offline Tests <a href="?page=cias-offline&action=add" class="button button-primary" style="margin-left:10px">+ Create Offline Test</a></h1>
<p class="description" style="margin:10px 0">Enter results of physical/surprise tests conducted in class. Use "Release" to make results visible to students.</p>
<table class="wp-list-table widefat fixed striped">
  <thead><tr><th>Test</th><th>Batch</th><th>Date</th><th>Type</th><th>Max Marks</th><th>Results Entered</th><th>Status</th><th>Actions</th></tr></thead>
  <tbody>
  <?php foreach($tests as $t): ?>
  <tr>
    <td><strong><?php echo esc_html($t->title); ?></strong></td>
    <td><?php echo esc_html($t->batch_name??'—'); ?></td>
    <td><?php echo $t->date_conducted ? date('d M Y', strtotime($t->date_conducted)) : '—'; ?></td>
    <td><?php echo esc_html($t->test_type); ?></td>
    <td><?php echo intval($t->max_marks); ?></td>
    <td><?php echo intval($t->result_count); ?> / <?php echo intval($t->student_count); ?> students</td>
    <td>
      <span style="padding:3px 10px;border-radius:99px;font-size:12px;background:<?php echo $t->status==='published'?'#dcfce7':'#fef3c7'; ?>;color:<?php echo $t->status==='published'?'#166534':'#92400e'; ?>">
        <?php echo $t->status === 'published' ? 'Released' : 'Draft'; ?>
      </span>
    </td>
    <td>
      <a href="?page=cias-offline&action=edit&id=<?php echo $t->id; ?>" class="button button-small">Edit</a>
      <a href="?page=cias-offline&action=results&id=<?php echo $t->id; ?>" class="button button-small">Enter marks</a>
      <?php if (current_user_can('cias_release_offline')): ?>
      <a href="?page=cias-offline&release=<?php echo $t->id; ?>" class="button button-small" style="<?php echo $t->status==='published'?'color:#dc2626':''; ?>" onclick="return confirm('<?php echo $t->status==='published'?'Unpublish?':'Release to students?'; ?>')">
        <?php echo $t->status==='published'?'Unpublish':'Release'; ?>
      </a>
      <?php endif; ?>
      <a href="?page=cias-offline&delete_ot=<?php echo $t->id; ?>" class="button button-small" style="color:#dc2626" onclick="return confirm('Delete this test and all its results?')">Delete</a>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if(empty($tests)): ?><tr><td colspan="8" style="color:#999;text-align:center">No offline tests yet. Create one above.</td></tr><?php endif; ?>
  </tbody>
</table></div>

<?php endif;
}
