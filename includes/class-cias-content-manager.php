<?php
if (!defined('ABSPATH')) exit;

class CIAS_Content_Manager {

    public static function render_page() {
        if (!current_user_can('cias_use_content_manager') && !current_user_can('manage_options')) {
            wp_die('You do not have permission to access this page.');
        }
        global $wpdb;
        $db  = new CIAS_DB();
        $tab = sanitize_text_field($_GET['cm_tab'] ?? 'generate');

        // Handle session resume
        $session_id = intval($_GET['session'] ?? 0);
        ?>
<div class="wrap">
<h1 style="display:flex;align-items:center;gap:10px;margin-bottom:16px">
  <span style="background:linear-gradient(135deg,#6C63FF,#8B5CF6);-webkit-background-clip:text;-webkit-text-fill-color:transparent;font-size:22px">CIAS Content Manager</span>
  <span style="font-size:12px;font-weight:400;color:#6b7280;-webkit-text-fill-color:var(--color-text-secondary,#6b7280)">v3.17</span>
</h1>

<div style="display:flex;gap:6px;margin-bottom:20px;flex-wrap:wrap">
  <?php $tabs = ['generate'=>'✨ AI Generate','bank'=>'📚 Question Bank','create_test'=>'📋 Create Test','reports'=>'📊 Reports']; ?>
  <?php foreach($tabs as $t=>$label): ?>
  <a href="?page=cias-content-manager&cm_tab=<?php echo $t; ?>"
     style="padding:8px 16px;border-radius:8px;border:<?php echo $tab===$t?'1px solid #6C63FF':'0.5px solid #d1d5db'; ?>;background:<?php echo $tab===$t?'#f0eeff':'none'; ?>;color:<?php echo $tab===$t?'#534AB7':'#6b7280'; ?>;font-size:13px;text-decoration:none">
    <?php echo $label; ?>
  </a>
  <?php endforeach; ?>
</div>

<?php
        if ($tab === 'generate') self::render_generate_tab($db);
        elseif ($tab === 'bank')  self::render_bank_tab($db);
        elseif ($tab === 'create_test') { ?>
        <script>window.location='<?php echo esc_url(admin_url('admin.php?page=cias-test-list&action=add')); ?>';</script>
        <?php }
        elseif ($tab === 'reports') self::render_reports_tab($db);
        ?>
</div>
        <?php
    }

    private static function render_generate_tab(CIAS_DB $db): void {
        $subjects    = $db->get_all('subjects');
        $all_topics  = $db->get_topics_with_subject();
        $all_subtopics = $db->get_subtopics_with_topic();

        // Check for pending sessions
        global $wpdb;
        $pending = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cias_ai_generations WHERE created_by=%d AND status='generated' ORDER BY created_at DESC LIMIT 5",
            get_current_user_id()
        ));
        ?>

<?php if (!empty($pending)): ?>
<div style="background:#fef3c7;border:1px solid #fde68a;border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:13px">
  📌 <strong>You have <?php echo count($pending); ?> pending session(s) with unapproved questions.</strong>
  <?php foreach($pending as $s): ?>
  <a href="?page=cias-content-manager&cm_tab=generate&session=<?php echo $s->id; ?>" style="color:#6C63FF;margin-left:8px">Resume session #<?php echo $s->id; ?> →</a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">

<!-- LEFT: Upload + Configure -->
<div>
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px;margin-bottom:14px">
  <div style="font-size:13px;font-weight:500;margin-bottom:12px;padding-bottom:8px;border-bottom:0.5px solid #f3f4f6">
    Step 1 — Source document
  </div>
  <div id="cm-upload-zone" style="border:1.5px dashed #d1d5db;border-radius:10px;padding:24px;text-align:center;background:#fafafa;cursor:pointer" onclick="document.getElementById('cm-file-input').click()">
    <div style="font-size:28px;margin-bottom:8px">📄</div>
    <p style="font-size:13px;color:#6b7280;margin-bottom:10px">Drop .docx, .pdf, or .txt<br><span style="font-size:11px;color:#9ca3af">or paste text below</span></p>
    <button type="button" class="button button-primary" style="font-size:12px">Choose file</button>
    <input type="file" id="cm-file-input" accept=".docx,.pdf,.txt" style="display:none">
  </div>
  <div id="cm-file-name" style="font-size:12px;color:#6b7280;margin-top:6px;display:none"></div>
  <textarea id="cm-paste-text" rows="5" style="width:100%;margin-top:10px;font-size:13px;padding:9px;border-radius:8px;border:0.5px solid #d1d5db;resize:vertical" placeholder="Or paste CA text / article here…"></textarea>
  <div id="cm-source-preview" style="display:none;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:10px;margin-top:8px;font-size:12px;color:#166534"></div>
</div>

<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px">
  <div style="font-size:13px;font-weight:500;margin-bottom:12px;padding-bottom:8px;border-bottom:0.5px solid #f3f4f6">
    Step 2 — Configure
  </div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
    <div>
      <label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px">Subject *</label>
      <select id="cm-subject" style="width:100%;padding:7px 9px;border-radius:8px;border:0.5px solid #d1d5db;font-size:13px">
        <option value="">— select —</option>
        <?php foreach($subjects as $s): ?><option value="<?php echo $s->id; ?>"><?php echo esc_html($s->name); ?></option><?php endforeach; ?>
      </select>
    </div>
    <div>
      <label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px">Topic</label>
      <select id="cm-topic" style="width:100%;padding:7px 9px;border-radius:8px;border:0.5px solid #d1d5db;font-size:13px">
        <option value="">— all topics —</option>
        <?php foreach($all_topics as $t): ?><option value="<?php echo $t->id; ?>" data-subject="<?php echo $t->subject_id ?? ''; ?>"><?php echo esc_html($t->name); ?></option><?php endforeach; ?>
      </select>
    </div>
    <div>
      <label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px">Subtopic</label>
      <select id="cm-subtopic" style="width:100%;padding:7px 9px;border-radius:8px;border:0.5px solid #d1d5db;font-size:13px">
        <option value="">— all —</option>
        <?php foreach($all_subtopics as $st): ?><option value="<?php echo $st->id; ?>" data-topic="<?php echo $st->topic_id ?? ''; ?>"><?php echo esc_html($st->name); ?></option><?php endforeach; ?>
      </select>
    </div>
    <div>
      <label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px">Question count</label>
      <select id="cm-count" style="width:100%;padding:7px 9px;border-radius:8px;border:0.5px solid #d1d5db;font-size:13px">
        <option value="5">5 questions</option>
        <option value="10" selected>10 questions</option>
        <option value="15">15 questions</option>
        <option value="20">20 questions</option>
      </select>
    </div>
    <div>
      <label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px">Difficulty mix</label>
      <select id="cm-difficulty" style="width:100%;padding:7px 9px;border-radius:8px;border:0.5px solid #d1d5db;font-size:13px">
        <option value="adaptive">Adaptive (auto)</option>
        <option value="easy">Easy-heavy</option>
        <option value="balanced">Balanced</option>
        <option value="hard">Hard-heavy</option>
      </select>
    </div>
    <div>
      <label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px">Question type</label>
      <select id="cm-qtype" style="width:100%;padding:7px 9px;border-radius:8px;border:0.5px solid #d1d5db;font-size:13px">
        <option value="standard">Standard MCQ</option>
        <option value="statement">Statement-based</option>
        <option value="mix">Mix</option>
      </select>
    </div>
    <div>
      <label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px">UPSC year tag</label>
      <input type="number" id="cm-year" min="2000" max="2030" placeholder="e.g. 2024" style="width:100%;padding:7px 9px;border-radius:8px;border:0.5px solid #d1d5db;font-size:13px">
    </div>
    <div>
      <label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px">Tags (comma-sep)</label>
      <input type="text" id="cm-tags" placeholder="e.g. economy, rbi" style="width:100%;padding:7px 9px;border-radius:8px;border:0.5px solid #d1d5db;font-size:13px">
    </div>
  </div>
  <button id="cm-generate-btn" class="button button-primary" style="width:100%;padding:11px;font-size:14px" onclick="cmGenerate()">
    ✨ Generate questions with AI
  </button>
  <div id="cm-generate-status" style="display:none;margin-top:8px;font-size:13px;text-align:center;color:#6b7280"></div>
</div>
</div>

<!-- RIGHT: Review panel -->
<div>
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;padding-bottom:8px;border-bottom:0.5px solid #f3f4f6">
    <div style="font-size:13px;font-weight:500">Step 3 — Review & approve</div>
    <div id="cm-approve-count" style="font-size:12px;color:#6b7280">0 of 0 approved</div>
  </div>
  <div id="cm-questions-list" style="max-height:560px;overflow-y:auto">
    <div style="text-align:center;padding:40px;color:#9ca3af;font-size:13px">
      Generated questions will appear here after Step 2
    </div>
  </div>
  <div id="cm-publish-bar" style="display:none;margin-top:12px;padding-top:12px;border-top:0.5px solid #f3f4f6;display:flex;justify-content:space-between;align-items:center">
    <div style="font-size:12px;color:#6b7280">
      <span id="cm-stat-generated">0</span> generated ·
      <span id="cm-stat-approved" style="color:#166534;font-weight:500">0</span> approved ·
      <span id="cm-stat-deleted" style="color:#dc2626">0</span> deleted
    </div>
    <button class="button button-primary" onclick="cmPublish()" style="font-size:13px">Publish approved → bank</button>
  </div>
</div>
</div>

</div>

<script>
var cmQuestions = [];
var cmSessionId = <?php echo intval($_GET['session'] ?? 0); ?>;

document.getElementById('cm-file-input').addEventListener('change', function(e) {
  var file = e.target.files[0];
  if (!file) return;
  document.getElementById('cm-file-name').style.display = 'block';
  document.getElementById('cm-file-name').textContent = '📎 ' + file.name;
  var reader = new FileReader();
  reader.onload = function(evt) {
    if (file.name.endsWith('.txt')) {
      document.getElementById('cm-paste-text').value = evt.target.result.substring(0, 8000);
    }
  };
  reader.readAsText(file);
});

document.getElementById('cm-subject').addEventListener('change', function() {
  var sid = this.value;
  document.querySelectorAll('#cm-topic option').forEach(function(o) {
    o.style.display = (!o.value || o.dataset.subject === sid || !sid) ? '' : 'none';
  });
});

function cmGenerate() {
  var text = document.getElementById('cm-paste-text').value.trim();
  if (!text) { alert('Please paste CA text or upload a file first.'); return; }
  var subject_id = document.getElementById('cm-subject').value;
  if (!subject_id) { alert('Please select a subject.'); return; }

  var btn = document.getElementById('cm-generate-btn');
  var status = document.getElementById('cm-generate-status');
  btn.disabled = true;
  btn.textContent = '⏳ Generating…';
  status.style.display = 'block';
  status.textContent = 'Sending to AI… this takes 10–20 seconds.';

  jQuery.post(ajaxurl, {
    action: 'cias_cm_generate',
    nonce: '<?php echo wp_create_nonce('cias_cm'); ?>',
    source_text:  text.substring(0, 6000),
    subject_id:   subject_id,
    topic_id:     document.getElementById('cm-topic').value,
    subtopic_id:  document.getElementById('cm-subtopic').value,
    count:        document.getElementById('cm-count').value,
    difficulty:   document.getElementById('cm-difficulty').value,
    qtype:        document.getElementById('cm-qtype').value,
    year:         document.getElementById('cm-year').value,
    tags:         document.getElementById('cm-tags').value,
  }, function(r) {
    btn.disabled = false;
    btn.textContent = '✨ Generate questions with AI';
    status.style.display = 'none';
    if (!r.success) { alert(r.data.message || 'Generation failed.'); return; }
    cmQuestions = r.data.questions;
    cmSessionId = r.data.session_id;
    cmRenderQuestions();
  }).fail(function() {
    btn.disabled = false;
    btn.textContent = '✨ Generate questions with AI';
    status.style.display = 'none';
    alert('Server error. Please try again.');
  });
}

function cmRenderQuestions() {
  var list = document.getElementById('cm-questions-list');
  if (!cmQuestions.length) { list.innerHTML = '<p style="text-align:center;color:#9ca3af">No questions generated.</p>'; return; }

  var html = '';
  cmQuestions.forEach(function(q, i) {
    if (q.deleted) return;
    var diffClass = q.difficulty === 'easy' ? 'background:#EAF3DE;color:#27500A' : (q.difficulty === 'hard' ? 'background:#FCEBEB;color:#791F1F' : 'background:#FAEEDA;color:#633806');
    html += '<div id="qcard-' + i + '" style="border:0.5px solid ' + (q.approved ? '#86efac' : '#e5e7eb') + ';background:' + (q.approved ? '#f0fdf4' : '#fff') + ';border-radius:8px;padding:12px;margin-bottom:8px">';
    html += '<div style="display:flex;justify-content:space-between;margin-bottom:6px">';
    html += '<span style="font-size:11px;background:#f3f4f6;color:#6b7280;padding:1px 8px;border-radius:99px">Q' + (i+1) + '</span>';
    html += '<div style="display:flex;gap:4px">';
    if (!q.approved) {
      html += '<button onclick="cmEdit(' + i + ')" style="font-size:11px;padding:3px 8px;border-radius:6px;border:0.5px solid #d1d5db;background:none;cursor:pointer">Edit</button>';
      html += '<button onclick="cmDelete(' + i + ')" style="font-size:11px;padding:3px 8px;border-radius:6px;border:0.5px solid #fca5a5;background:#fef2f2;color:#dc2626;cursor:pointer">Delete</button>';
      html += '<button onclick="cmApprove(' + i + ')" style="font-size:11px;padding:3px 8px;border-radius:6px;border:0.5px solid #86efac;background:#f0fdf4;color:#166534;cursor:pointer">Approve ✓</button>';
    } else {
      html += '<span style="font-size:11px;color:#166534">✅ Approved</span>';
      html += '<button onclick="cmUnapprove(' + i + ')" style="font-size:11px;padding:2px 6px;border-radius:6px;border:0.5px solid #d1d5db;background:none;color:#6b7280;cursor:pointer">Undo</button>';
    }
    html += '</div></div>';
    html += '<div style="font-size:13px;font-weight:500;margin-bottom:8px;line-height:1.5">' + q.question_text + '</div>';
    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:4px;margin-bottom:8px">';
    ['a','b','c','d'].forEach(function(opt) {
      var val = q['option_' + opt] || '';
      var correct = q.correct_option === opt;
      html += '<div style="font-size:12px;padding:5px 8px;border-radius:6px;' + (correct ? 'background:#dcfce7;border:0.5px solid #86efac;color:#166534;' : 'background:#f9fafb;border:0.5px solid #e5e7eb;color:#374151;') + '">' + opt.toUpperCase() + '. ' + val + (correct ? ' ✓' : '') + '</div>';
    });
    html += '</div>';
    html += '<div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">';
    html += '<span style="font-size:10px;padding:1px 8px;border-radius:99px;' + diffClass + '">' + q.difficulty + '</span>';
    html += '<span style="font-size:10px;background:#f0eeff;color:#534AB7;padding:1px 8px;border-radius:99px">AI generated</span>';
    if (q.explanation) html += '<span style="font-size:10px;color:#6b7280">💡 Has explanation</span>';
    html += '</div></div>';
  });
  list.innerHTML = html;
  cmUpdateStats();
  document.getElementById('cm-publish-bar').style.display = 'flex';
}

function cmApprove(i)   { cmQuestions[i].approved = true;  cmRenderQuestions(); }
function cmUnapprove(i) { cmQuestions[i].approved = false; cmRenderQuestions(); }
function cmDelete(i)    { cmQuestions[i].deleted = true;   cmRenderQuestions(); }
function cmEdit(i) {
  var q = cmQuestions[i];
  var nt = prompt('Edit question text:', q.question_text);
  if (nt !== null) { cmQuestions[i].question_text = nt; cmRenderQuestions(); }
}
function cmUpdateStats() {
  var total = cmQuestions.filter(function(q){ return !q.deleted; }).length;
  var approved = cmQuestions.filter(function(q){ return q.approved && !q.deleted; }).length;
  var deleted  = cmQuestions.filter(function(q){ return q.deleted; }).length;
  document.getElementById('cm-stat-generated').textContent = total;
  document.getElementById('cm-stat-approved').textContent  = approved;
  document.getElementById('cm-stat-deleted').textContent   = deleted;
  document.getElementById('cm-approve-count').textContent  = approved + ' of ' + total + ' approved';
}
function cmPublish() {
  var toPublish = cmQuestions.filter(function(q){ return q.approved && !q.deleted; });
  if (!toPublish.length) { alert('No approved questions to publish.'); return; }
  if (!confirm('Publish ' + toPublish.length + ' question(s) to the question bank?')) return;
  jQuery.post(ajaxurl, {
    action: 'cias_cm_publish',
    nonce: '<?php echo wp_create_nonce('cias_cm'); ?>',
    session_id:  cmSessionId,
    questions:   JSON.stringify(toPublish),
  }, function(r) {
    if (r.success) {
      alert('✅ ' + r.data.count + ' question(s) published to the question bank!');
      cmQuestions = cmQuestions.filter(function(q){ return !q.approved; });
      cmRenderQuestions();
    } else {
      alert(r.data.message || 'Publish failed.');
    }
  });
}

// Auto-load pending session
if (cmSessionId > 0) {
  jQuery.post(ajaxurl, {action:'cias_cm_load_session', nonce:'<?php echo wp_create_nonce('cias_cm'); ?>', session_id:cmSessionId}, function(r) {
    if (r.success) { cmQuestions = r.data.questions; cmRenderQuestions(); }
  });
}
</script>
        <?php
    }

    private static function render_bank_tab(CIAS_DB $db): void {
        $subjects = $db->get_all('subjects');
        $filter_sub = intval($_GET['q_sub'] ?? 0);
        $filter_status = sanitize_text_field($_GET['q_status'] ?? 'published');
        $page = max(1, intval($_GET['q_page'] ?? 1));
        $per = 20;
        $qs = $db->get_questions_list($filter_sub, $filter_status, []);
        $total = count($qs);
        $qs = array_slice($qs, ($page-1)*$per, $per);
        ?>
<div style="display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap;align-items:center">
  <select onchange="location='?page=cias-content-manager&cm_tab=bank&q_sub='+this.value+'&q_status=<?php echo urlencode($filter_status); ?>'" style="padding:6px 10px;border-radius:8px;border:0.5px solid #d1d5db;font-size:13px">
    <option value="0">All subjects</option>
    <?php foreach($subjects as $s): ?><option value="<?php echo $s->id; ?>" <?php selected($filter_sub,$s->id); ?>><?php echo esc_html($s->name); ?></option><?php endforeach; ?>
  </select>
  <select onchange="location='?page=cias-content-manager&cm_tab=bank&q_sub=<?php echo $filter_sub; ?>&q_status='+this.value" style="padding:6px 10px;border-radius:8px;border:0.5px solid #d1d5db;font-size:13px">
    <option value="published" <?php selected($filter_status,'published'); ?>>Published</option>
    <option value="draft" <?php selected($filter_status,'draft'); ?>>Draft</option>
    <option value="ai_pending" <?php selected($filter_status,'ai_pending'); ?>>AI Pending</option>
  </select>
  <span style="font-size:13px;color:#6b7280"><?php echo $total; ?> questions</span>
  <a href="?page=cias-questions&action=add" class="button button-small" style="margin-left:auto">+ Add question</a>
</div>
<table class="wp-list-table widefat fixed striped" style="border-radius:10px;overflow:hidden">
  <thead><tr><th style="width:55%">Question</th><th>Subject</th><th>Difficulty</th><th>Status</th><th>Actions</th></tr></thead>
  <tbody>
  <?php foreach($qs as $q): ?>
  <tr>
    <td><?php echo esc_html(mb_substr($q->question_text,0,80)); ?>…</td>
    <td style="font-size:12px"><?php echo esc_html($q->subject_name??'—'); ?></td>
    <td><span style="font-size:11px;padding:1px 8px;border-radius:99px;background:<?php echo $q->difficulty==='easy'?'#EAF3DE':($q->difficulty==='hard'?'#FCEBEB':'#FAEEDA'); ?>;color:<?php echo $q->difficulty==='easy'?'#27500A':($q->difficulty==='hard'?'#791F1F':'#633806'); ?>"><?php echo esc_html($q->difficulty); ?></span></td>
    <td><span style="font-size:11px;padding:1px 8px;border-radius:99px;background:<?php echo $q->status==='published'?'#dcfce7':'#fef3c7'; ?>;color:<?php echo $q->status==='published'?'#166534':'#92400e'; ?>"><?php echo esc_html($q->status); ?></span></td>
    <td><a href="?page=cias-questions&action=edit&id=<?php echo $q->id; ?>" style="font-size:12px">Edit</a></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
        <?php
    }

    private static function render_reports_tab(CIAS_DB $db): void {
        ?>
<div style="background:#f0eeff;border:1px solid #c4b5fd;border-radius:8px;padding:12px 16px;font-size:13px;margin-bottom:12px">
  Showing reports for your batches. <a href="?page=cias-reports" style="color:#6C63FF">Open full reports page →</a>
</div>
        <?php
        // Reuse reports page output for teacher's batches
        $batches = $db->get_teacher_batches(get_current_user_id());
        if (empty($batches)) {
            echo '<p style="color:#6b7280">No batches assigned to you yet.</p>';
            return;
        }
        $sel = intval($_GET['rpt_batch'] ?? $batches[0]->id);
        ?>
<select onchange="location='?page=cias-content-manager&cm_tab=reports&rpt_batch='+this.value" style="padding:6px 10px;border-radius:8px;border:0.5px solid #d1d5db;font-size:13px;margin-bottom:14px">
  <?php foreach($batches as $b): ?><option value="<?php echo $b->id; ?>" <?php selected($sel,$b->id); ?>><?php echo esc_html($b->name); ?></option><?php endforeach; ?>
</select>
        <?php
        $report = $db->get_batch_report($sel);
        echo '<table class="wp-list-table widefat fixed striped" style="border-radius:10px;overflow:hidden"><thead><tr><th>Rank</th><th>Student</th><th>Tests</th><th>Avg</th></tr></thead><tbody>';
        foreach ($report as $i => $r) {
            $pct = floatval($r->avg_pct);
            echo '<tr><td>' . ($i+1) . '</td><td>' . esc_html($r->display_name) . '</td><td>' . intval($r->total_attempts) . '</td>';
            echo '<td><span style="padding:2px 8px;border-radius:99px;font-size:11px;background:' . ($pct>=60?'#dcfce7':'#fee2e2') . ';color:' . ($pct>=60?'#166534':'#991b1b') . '">' . $pct . '%</span></td></tr>';
        }
        echo '</tbody></table>';
    }
}
