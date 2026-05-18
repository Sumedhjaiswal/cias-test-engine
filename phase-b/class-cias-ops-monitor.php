<?php
/**
 * CIAS Operations Monitor
 *
 * Adds a "⚡ Ops Monitor" submenu under CIAS Tests with four tabs:
 *   1. Overview     – live queue counters, health badges, cost summary
 *   2. Job Queue    – filterable job list with retry / delete actions
 *   3. AI Logs      – per-model token + cost breakdown, recent calls
 *   4. Worker Health– last-seen worker timestamps, stale job detector
 *
 * USAGE — add these two lines to cias-phase-b.php (after the other require_onces):
 *
 *   require_once CIAS_PHASE_B_DIR . 'class-cias-ops-monitor.php';
 *
 * And inside the 'plugins_loaded' boot block (after CIAS_Teacher_Review::init()):
 *
 *   CIAS_Ops_Monitor::init();
 *
 * No other changes needed.  All AJAX endpoints are self-registered.
 *
 * @package CIAS\PhaseB
 * @since   3.19.2
 */
defined( 'ABSPATH' ) || exit;

class CIAS_Ops_Monitor {

    // ── Boot ─────────────────────────────────────────────────────────────────

    public static function init(): void {
        add_action( 'admin_menu',         [ __CLASS__, 'register_menu'  ], 30 );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue'     ] );
        add_action( 'wp_ajax_cias_ops_data',     [ __CLASS__, 'ajax_ops_data'    ] );
        add_action( 'wp_ajax_cias_ops_retry',    [ __CLASS__, 'ajax_retry_job'   ] );
        add_action( 'wp_ajax_cias_ops_delete',   [ __CLASS__, 'ajax_delete_job'  ] );
        add_action( 'wp_ajax_cias_ops_purge',    [ __CLASS__, 'ajax_purge_dead'  ] );
        add_action( 'wp_ajax_cias_ops_requeue',  [ __CLASS__, 'ajax_requeue_stale'] );
        add_action( 'wp_ajax_cias_ops_timeline', [ __CLASS__, 'ajax_timeline'    ] );
        add_action( 'wp_ajax_cias_ops_activity', [ __CLASS__, 'ajax_activity'    ] );
        add_action( 'wp_ajax_cias_ops_payload',  [ __CLASS__, 'ajax_payload'     ] );
    }

    // ── Admin menu ────────────────────────────────────────────────────────────

    public static function register_menu(): void {
        add_submenu_page(
            'cias-tests',
            '⚡ Ops Monitor',
            '⚡ Ops Monitor',
            'manage_options',
            'cias-ops-monitor',
            [ __CLASS__, 'render_page' ]
        );
    }

    // ── Enqueue assets (only on our page) ────────────────────────────────────

    public static function enqueue( string $hook ): void {
        if ( strpos( $hook, 'cias-ops-monitor' ) === false ) return;
        // Inline – no external deps needed
        wp_add_inline_style( 'wp-admin', self::inline_css() );
    }

    // ── Main page render ──────────────────────────────────────────────────────

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Access denied.' );

        $tab = sanitize_key( $_GET['ops_tab'] ?? 'overview' );
        $tabs = [
            'overview'   => '📊 Overview',
            'queue'      => '📋 Job Queue',
            'latency'    => '⏱ Latency',
            'logs'       => '🤖 AI Logs',
            'errors'     => '🔴 Error Intel',
            'costs'      => '💰 Cost Forecast',
            'deadletter' => '💀 Dead Letters',
            'workers'    => '⚙️ Workers',
            'abuse'      => '🚨 Abuse',
            'accuracy'   => '🎯 AI Accuracy',
            'activity'   => '📡 Live Activity',
        ];

        // Handle single-job actions (retry/delete) from GET links
        self::handle_inline_action();

        $nonce = wp_create_nonce( 'cias_ops_nonce' );
        ?>
<div class="wrap" id="cias-ops-wrap">
<h1 style="display:flex;align-items:center;gap:10px;margin-bottom:0">
    <span>⚡ CIAS Ops Monitor</span>
    <span id="ops-live-badge" style="font-size:11px;font-weight:400;background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;padding:3px 10px;border-radius:99px;cursor:pointer" onclick="opsRefresh()" title="Click to refresh">● LIVE</span>
    <span style="margin-left:auto;font-size:12px;color:#9ca3af" id="ops-last-refresh">—</span>
</h1>

<!-- Tab nav -->
<nav class="ops-tabs">
<?php foreach ( $tabs as $key => $label ): ?>
    <a href="?page=cias-ops-monitor&ops_tab=<?php echo $key; ?>"
       class="ops-tab <?php echo $tab === $key ? 'ops-tab--active' : ''; ?>">
        <?php echo $label; ?>
    </a>
<?php endforeach; ?>
</nav>

<div id="ops-content" class="ops-panel">
<?php
        match ( $tab ) {
            'queue'      => self::render_queue_tab(),
            'latency'    => self::tab_latency(),
            'logs'       => self::render_logs_tab(),
            'errors'     => self::tab_errors(),
            'costs'      => self::tab_costs(),
            'deadletter' => self::tab_deadletter(),
            'workers'    => self::render_workers_tab(),
            'abuse'      => self::tab_abuse(),
            'accuracy'   => self::tab_accuracy(),
            'activity'   => self::tab_activity(),
            default      => self::render_overview_tab(),
        };
?>
<!-- Timeline chart -->
<script>if(document.getElementById('ops-counters-row')){fetch(opsAjax+'?action=cias_ops_timeline&nonce='+opsNonce).then(r=>r.json()).then(d=>{if(d.success)opsDrawTimeline(d.data.buckets)});}</script>
</div><!-- ops-content -->
</div><!-- wrap -->

<script>
const opsPollInterval = 30000; // 30 s auto-refresh
const opsNonce = '<?php echo $nonce; ?>';
const opsAjax  = '<?php echo admin_url('admin-ajax.php'); ?>';
let   opsPollTimer = null;

function opsRefresh() {
    fetch(opsAjax + '?action=cias_ops_data&nonce=' + opsNonce)
        .then(r => r.json())
        .then(data => {
            if (data.counters)  updateCounters(data.counters);
            if (data.alert_bar) updateAlertBar(data.alert_bar);
            document.getElementById('ops-last-refresh').textContent =
                'Refreshed ' + new Date().toLocaleTimeString();
        })
        .catch(() => {});
}

function updateCounters(c) {
    Object.keys(c).forEach(key => {
        const el = document.getElementById('cnt-' + key);
        if (el) el.textContent = c[key];
    });
}

function updateAlertBar(alerts) {
    const bar = document.getElementById('ops-alert-bar');
    if (!bar) return;
    if (!alerts || alerts.length === 0) { bar.style.display = 'none'; return; }
    bar.style.display = 'block';
    bar.innerHTML = alerts.map(a =>
        `<div class="ops-alert ops-alert--${a.level}">${a.icon} ${a.message}</div>`
    ).join('');
}

function opsJobAction(action, jobId) {
    if (!confirm('Confirm: ' + action + ' job #' + jobId + '?')) return;
    fetch(opsAjax, {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `action=cias_ops_${action}&nonce=${opsNonce}&job_id=${jobId}`
    }).then(r => r.json()).then(d => {
        if (d.success) location.reload();
        else alert('Error: ' + (d.data || 'unknown'));
    });
}

function opsPurge() {
    if (!confirm('Delete ALL dead jobs? This cannot be undone.')) return;
    fetch(opsAjax, {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `action=cias_ops_purge&nonce=${opsNonce}`
    }).then(r => r.json()).then(d => {
        if (d.success) location.reload();
        else alert('Error: ' + (d.data || 'unknown'));
    });
}

function opsRequeueStale() {
    if (!confirm('Re-queue all stale processing jobs?')) return;
    fetch(opsAjax, {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `action=cias_ops_requeue&nonce=${opsNonce}`
    }).then(r => r.json()).then(d => {
        alert(d.data || 'Done');
        if (d.success) location.reload();
    });
}

// Auto-refresh on overview tab
if (document.getElementById('ops-counters-row')) {
    opsRefresh();
    opsPollTimer = setInterval(opsRefresh, opsPollInterval);
}

// ── Payload inspect modal ─────────────────────────────────────────────────
function opsInspect(id) {
    fetch(opsAjax + '?action=cias_ops_payload&nonce=' + opsNonce + '&job_id=' + id)
        .then(r => r.json()).then(d => {
            if (!d.success) { alert('Error: ' + d.data); return; }
            document.getElementById('ops-modal-id').textContent = '#' + id;
            document.getElementById('ops-modal-err').textContent = d.data.error || '(none)';
            document.getElementById('ops-modal-payload').textContent = d.data.payload;
            const dlBtn = document.getElementById('ops-modal-dl');
            dlBtn.onclick = function() {
                const blob = new Blob([JSON.stringify({error:d.data.error,payload:d.data.payload},null,2)],{type:'application/json'});
                const a = document.createElement('a'); a.href = URL.createObjectURL(blob);
                a.download = 'job-' + id + '-debug.json'; a.click();
            };
            document.getElementById('ops-payload-modal').style.display = 'flex';
        });
}

// ── Timeline chart ────────────────────────────────────────────────────────
const TL_COLORS = {ocr:'#3b82f6',evaluate:'#8b5cf6',evaluate_batch:'#06b6d4',guru_chat:'#10b981',analytics:'#f59e0b',failed:'#ef4444'};
function opsLoadTimeline() {
    fetch(opsAjax + '?action=cias_ops_timeline&nonce=' + opsNonce)
        .then(r => r.json()).then(d => { if (d.success) opsDrawTimeline(d.data.buckets); }).catch(()=>{});
}
function opsDrawTimeline(buckets) {
    const canvas = document.getElementById('ops-tl-canvas'); if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const W = canvas.offsetWidth, H = 130; canvas.width = W; canvas.height = H;
    if (!buckets || !buckets.length) { ctx.fillStyle='#9ca3af'; ctx.font='13px sans-serif'; ctx.fillText('No queue activity in last 60 min',20,70); return; }
    let maxV = 1; buckets.forEach(b => { const s = Object.values(b.types||{}).reduce((a,v)=>a+v,0); if(s>maxV) maxV=s; });
    const padL=30,padR=8,padT=10,padB=28,plotW=W-padL-padR,plotH=H-padT-padB,bw=plotW/buckets.length;
    [.25,.5,.75,1].forEach(f => { const y=padT+plotH*(1-f); ctx.strokeStyle='#f3f4f6'; ctx.lineWidth=1; ctx.beginPath(); ctx.moveTo(padL,y); ctx.lineTo(W-padR,y); ctx.stroke(); ctx.fillStyle='#d1d5db'; ctx.font='9px sans-serif'; ctx.fillText(Math.round(maxV*f),2,y+3); });
    const types = Object.keys(TL_COLORS);
    buckets.forEach((b,i) => { let yOff=0; types.forEach(t => { const v=b.types[t]||0; if(!v) return; const bH=(v/maxV)*plotH,x=padL+i*bw,y=padT+plotH-yOff-bH; ctx.fillStyle=TL_COLORS[t]||'#9ca3af'; ctx.fillRect(x+1,y,bw-2,bH); yOff+=bH; }); if(i%3===0){ctx.fillStyle='#9ca3af';ctx.font='9px sans-serif';ctx.fillText(b.label,padL+i*bw,H-2);} });
    const leg = document.getElementById('ops-tl-legend');
    if (leg) leg.innerHTML = types.map(t => `<span style="display:inline-flex;align-items:center;gap:3px"><span style="width:9px;height:9px;background:${TL_COLORS[t]};border-radius:2px;display:inline-block"></span><span style="color:#6b7280;font-size:11px">${t}</span></span>`).join('');
}
if (document.getElementById('ops-tl-canvas')) opsLoadTimeline();

// ── Activity refresh ──────────────────────────────────────────────────────
function opsRefreshActivity() {
    const btn = document.getElementById('ops-act-refresh');
    if (btn) { btn.disabled=true; btn.textContent='⟳'; }
    fetch(opsAjax + '?action=cias_ops_activity&nonce=' + opsNonce)
        .then(r=>r.json()).then(d => {
            if (d.success) { const c=document.getElementById('ops-act-wrap'); if(c) c.innerHTML=d.data.html; }
            if (btn) { btn.disabled=false; btn.textContent='↺ Refresh'; }
        }).catch(()=>{ if(btn){btn.disabled=false;btn.textContent='↺ Refresh';} });
}
if (document.getElementById('ops-act-wrap')) setInterval(opsRefreshActivity, 15000);
</script>

<!-- Payload inspect modal -->
<div id="ops-payload-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:12px;width:720px;max-width:95vw;max-height:85vh;overflow:hidden;display:flex;flex-direction:column">
    <div style="padding:14px 20px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between">
      <strong>Job <span id="ops-modal-id"></span> — Debug Inspect</strong>
      <button onclick="document.getElementById('ops-payload-modal').style.display='none'" style="background:none;border:none;cursor:pointer;font-size:22px;color:#6b7280">×</button>
    </div>
    <div style="padding:16px 20px;overflow:auto;flex:1">
      <div style="margin-bottom:12px">
        <div style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;margin-bottom:6px">Error Message</div>
        <div id="ops-modal-err" style="font-size:13px;color:#dc2626;background:#fef2f2;padding:10px;border-radius:6px;word-break:break-all"></div>
      </div>
      <div>
        <div style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;margin-bottom:6px">Payload JSON</div>
        <pre id="ops-modal-payload" style="font-size:12px;background:#f9fafb;padding:12px;border-radius:6px;overflow:auto;white-space:pre-wrap;word-break:break-all;max-height:350px"></pre>
      </div>
    </div>
    <div style="padding:12px 20px;border-top:1px solid #e5e7eb;display:flex;gap:8px">
      <button id="ops-modal-dl" class="ops-btn">⬇ Download JSON</button>
      <button onclick="document.getElementById('ops-payload-modal').style.display='none'" class="ops-btn ops-btn--ghost">Close</button>
    </div>
  </div>
</div>
<?php
    }

    // ──────────────────────────────────────────────────────────────────────────
    // TAB: Overview
    // ──────────────────────────────────────────────────────────────────────────

    private static function render_overview_tab(): void {
        global $wpdb;

        // ── Queue counters ────────────────────────────────────────────────────
        $queue_rows = $wpdb->get_results(
            "SELECT type, status, COUNT(*) AS cnt
             FROM `" . CIAS_JOB_QUEUE . "`
             GROUP BY type, status
             ORDER BY type, status"
        );

        $by_status = [];
        $by_type   = [];
        foreach ( $queue_rows as $r ) {
            $by_status[ $r->status ] = ( $by_status[ $r->status ] ?? 0 ) + (int) $r->cnt;
            $by_type[ $r->type ][ $r->status ] = (int) $r->cnt;
        }

        $pending    = $by_status['pending']    ?? 0;
        $processing = $by_status['processing'] ?? 0;
        $done       = $by_status['done']       ?? 0;
        $failed     = $by_status['failed']     ?? 0;
        $dead       = $by_status['dead']       ?? 0;

        // ── AI cost (last 7 days) ─────────────────────────────────────────────
        $ai_table = $wpdb->prefix . 'cias_ai_usage_log';
        $cost7d   = (float) $wpdb->get_var(
            "SELECT COALESCE(SUM(cost_usd),0) FROM `{$ai_table}` WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        $cost_today = (float) $wpdb->get_var(
            "SELECT COALESCE(SUM(cost_usd),0) FROM `{$ai_table}` WHERE DATE(created_at)=CURDATE()"
        );
        $calls_today = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$ai_table}` WHERE DATE(created_at)=CURDATE()"
        );

        // ── Submission pipeline ───────────────────────────────────────────────
        $sub_rows = $wpdb->get_results(
            "SELECT status, COUNT(*) AS cnt FROM `" . CIAS_SUBMISSIONS . "`
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             GROUP BY status"
        );
        $sub_by_status = [];
        foreach ( $sub_rows as $r ) $sub_by_status[ $r->status ] = (int) $r->cnt;

        // ── R2 + Redis health ─────────────────────────────────────────────────
        $r2_ok    = CIAS_R2::is_configured();
        $redis_ok = CIAS_Queue::is_configured() ? CIAS_Queue::ping() : null;

        // ── Stale processing jobs ─────────────────────────────────────────────
        $stale_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `" . CIAS_JOB_QUEUE . "`
             WHERE status='processing' AND started_at < DATE_SUB(NOW(), INTERVAL 8 MINUTE)"
        );

        // ── Build alerts ──────────────────────────────────────────────────────
        $alerts = self::build_alerts( $dead, $stale_count, $r2_ok, $redis_ok, $pending );

        ?>
<!-- Alert bar -->
<div id="ops-alert-bar" style="<?php echo empty($alerts) ? 'display:none' : ''; ?>margin-bottom:16px">
<?php foreach ( $alerts as $a ): ?>
    <div class="ops-alert ops-alert--<?php echo esc_attr($a['level']); ?>">
        <?php echo esc_html($a['icon'] . ' ' . $a['message']); ?>
    </div>
<?php endforeach; ?>
</div>

<!-- KPI counters -->
<div class="ops-grid ops-grid--5" id="ops-counters-row">
<?php
        $cards = [
            [ 'label' => 'Pending Jobs',    'id' => 'pending',    'val' => $pending,    'color' => '#f59e0b', 'icon' => '⏳' ],
            [ 'label' => 'Processing',      'id' => 'processing', 'val' => $processing, 'color' => '#3b82f6', 'icon' => '⚙️' ],
            [ 'label' => 'Dead Jobs (24h)', 'id' => 'dead',       'val' => $dead,       'color' => $dead > 0 ? '#dc2626' : '#16a34a', 'icon' => '💀' ],
            [ 'label' => 'Failed (retry)',  'id' => 'failed',     'val' => $failed,     'color' => $failed > 0 ? '#ea580c' : '#6b7280', 'icon' => '🔁' ],
            [ 'label' => 'AI Calls Today',  'id' => 'calls_today','val' => $calls_today,'color' => '#8b5cf6', 'icon' => '🤖' ],
        ];
        foreach ( $cards as $c ):
?>
    <div class="ops-kpi">
        <div class="ops-kpi__icon"><?php echo $c['icon']; ?></div>
        <div class="ops-kpi__val" id="cnt-<?php echo $c['id']; ?>" style="color:<?php echo $c['color']; ?>">
            <?php echo number_format( $c['val'] ); ?>
        </div>
        <div class="ops-kpi__label"><?php echo esc_html( $c['label'] ); ?></div>
    </div>
<?php endforeach; ?>
</div>

<!-- Live Queue Timeline -->
<div class="ops-card" style="margin-top:20px" id="ops-tl-wrap">
  <div class="ops-card__title" style="display:flex;align-items:center;justify-content:space-between">
    <span>📈 Live Queue Timeline (last 60 min)</span>
    <button class="ops-btn ops-btn--sm" onclick="opsLoadTimeline()">↺</button>
  </div>
  <canvas id="ops-tl-canvas" style="width:100%;height:130px;display:block"></canvas>
  <div id="ops-tl-legend" style="display:flex;gap:14px;margin-top:6px;flex-wrap:wrap"></div>
</div>

<!-- Two-column: queue by type + infrastructure health -->
<div class="ops-grid ops-grid--2" style="margin-top:20px">

    <!-- Queue by type -->
    <div class="ops-card">
        <div class="ops-card__title">Job Queue by Type</div>
        <table class="ops-table">
            <thead><tr><th>Type</th><th>Pending</th><th>Processing</th><th>Failed</th><th>Dead</th><th>Done (all)</th></tr></thead>
            <tbody>
            <?php
            $all_types = [ 'ocr', 'evaluate', 'evaluate_batch', 'guru_chat', 'analytics' ];
            foreach ( $all_types as $type ):
                $td = $by_type[ $type ] ?? [];
            ?>
            <tr>
                <td><code><?php echo esc_html( $type ); ?></code></td>
                <td><?php echo (int)($td['pending']    ?? 0); ?></td>
                <td><?php echo (int)($td['processing'] ?? 0); ?></td>
                <td style="color:<?php echo ($td['failed']??0)>0?'#ea580c':'inherit'; ?>">
                    <?php echo (int)($td['failed'] ?? 0); ?></td>
                <td style="color:<?php echo ($td['dead']??0)>0?'#dc2626':'inherit'; ?>">
                    <?php echo (int)($td['dead'] ?? 0); ?></td>
                <td style="color:#9ca3af"><?php echo (int)($td['done'] ?? 0); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Infrastructure health -->
    <div class="ops-card">
        <div class="ops-card__title">Infrastructure Health</div>
        <div class="ops-health-list">
            <?php
            self::health_row( 'Cloudflare R2',      $r2_ok    ? 'ok'   : 'err',  $r2_ok    ? 'Configured' : 'Not configured — file uploads will fail' );
            self::health_row( 'Upstash Redis',      $redis_ok === null ? 'warn' : ($redis_ok ? 'ok' : 'err'),
                              $redis_ok === null ? 'Not configured (workers use MySQL polling)' : ($redis_ok ? 'PONG received' : 'Ping failed') );
            self::health_row( 'Claude API Key',     CIAS_AI_Utils::get_api_key() ? 'ok' : 'err',
                              CIAS_AI_Utils::get_api_key() ? 'Key configured' : 'Missing — AI jobs will fail' );
            self::health_row( 'Stale Processing',   $stale_count > 0 ? 'warn' : 'ok',
                              $stale_count > 0 ? "{$stale_count} job(s) stuck >8 min" : 'None detected' );
            self::health_row( 'Dead Jobs (24h)',    $dead > 5 ? 'err' : ($dead > 0 ? 'warn' : 'ok'),
                              $dead === 0 ? 'None' : "{$dead} job(s) exhausted retries" );
            $mem_mb = round( memory_get_usage(true) / 1048576, 1 );
            $db_conn = (int) $wpdb->get_var("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE User != 'event_scheduler'");
            self::health_row( 'PHP Memory', $mem_mb > 128 ? 'warn' : 'ok', "{$mem_mb} MB used (limit: " . ini_get('memory_limit') . ")" );
            self::health_row( 'DB Connections', $db_conn > 20 ? 'warn' : 'ok', "{$db_conn} active connections" );
            ?>
        </div>

        <?php if ( $stale_count > 0 ): ?>
        <button class="ops-btn ops-btn--warn" onclick="opsRequeueStale()" style="margin-top:12px">
            🔄 Re-queue <?php echo $stale_count; ?> stale job(s)
        </button>
        <?php endif; ?>

        <?php if ( $dead > 0 ): ?>
        <button class="ops-btn ops-btn--danger" onclick="opsPurge()" style="margin-top:8px">
            🗑 Purge all dead jobs
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Cost + submission pipeline -->
<div class="ops-grid ops-grid--2" style="margin-top:16px">

    <div class="ops-card">
        <div class="ops-card__title">AI Cost</div>
        <div class="ops-stat-list">
            <div class="ops-stat-row"><span>Today</span><strong>$<?php echo number_format($cost_today, 4); ?></strong></div>
            <div class="ops-stat-row"><span>Last 7 days</span><strong>$<?php echo number_format($cost7d, 4); ?></strong></div>
            <div class="ops-stat-row"><span>API calls today</span><strong><?php echo number_format($calls_today); ?></strong></div>
            <?php
            $day_of_month = (int) date('j'); $days_in_month = (int) date('t');
            if ($cost_today > 0 && $day_of_month > 0) {
                $projected = $cost_today / $day_of_month * $days_in_month;
                echo "<div class='ops-stat-row'><span>Projected (month)</span><strong style='color:" . ($projected > 10 ? '#dc2626' : '#16a34a') . "'>\$" . number_format($projected, 2) . "</strong></div>";
            }
            ?>
        </div>
        <a href="?page=cias-ops-monitor&ops_tab=costs" class="ops-link" style="display:block;margin-top:10px;font-size:12px">Full Cost Forecast →</a>
    </div>

    <div class="ops-card">
        <div class="ops-card__title">Submission Pipeline (last 24h)</div>
        <div class="ops-stat-list">
            <?php
            $pipeline_statuses = [
                'queued'             => [ '🆕', '#6b7280', 'Uploaded'           ],
                'ocr_processing'     => [ '🔍', '#3b82f6', 'OCR Processing'     ],
                'needs_confirmation' => [ '✋', '#f59e0b', 'Needs Confirmation'  ],
                'ocr_done'           => [ '✅', '#16a34a', 'OCR Complete'        ],
                'evaluating'         => [ '🤖', '#8b5cf6', 'AI Evaluating'       ],
                'evaluated'          => [ '🎯', '#16a34a', 'Evaluated'           ],
                'ocr_failed'         => [ '❌', '#dc2626', 'OCR Failed'          ],
                'eval_failed'        => [ '❌', '#dc2626', 'Eval Failed'         ],
                'teacher_review'     => [ '👨‍🏫', '#ea580c', 'Teacher Review'   ],
            ];
            $total_sub = array_sum( $sub_by_status );
            foreach ( $pipeline_statuses as $st => [$icon, $col, $label] ):
                $cnt = $sub_by_status[ $st ] ?? 0;
                $pct = $total_sub > 0 ? round( $cnt / $total_sub * 100 ) : 0;
            ?>
            <div style="display:flex;align-items:center;gap:8px;padding:3px 0">
                <span style="width:140px;font-size:12px"><?php echo $icon; ?> <?php echo esc_html( $label ); ?></span>
                <strong style="color:<?php echo $col; ?>;width:24px;text-align:right"><?php echo $cnt; ?></strong>
                <div style="flex:1;background:#f3f4f6;border-radius:3px;height:6px">
                    <div style="width:<?php echo $pct; ?>%;background:<?php echo $col; ?>;height:6px;border-radius:3px"></div>
                </div>
                <span style="font-size:10px;color:#9ca3af;width:28px"><?php echo $pct; ?>%</span>
            </div>
            <?php endforeach; ?>
            <?php if ( empty( array_filter( $sub_by_status ) ) ): ?>
            <p style="color:#9ca3af;font-size:13px;text-align:center;padding:12px 0">No submissions in last 24h</p>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php
    }

    // ──────────────────────────────────────────────────────────────────────────
    // TAB: Job Queue
    // ──────────────────────────────────────────────────────────────────────────

    private static function render_queue_tab(): void {
        global $wpdb;

        $type   = sanitize_key( $_GET['jtype']   ?? '' );
        $status = sanitize_key( $_GET['jstatus']  ?? '' );
        $page   = max( 1, (int)( $_GET['jpage'] ?? 1 ) );
        $per    = 25;

        $where  = '1=1';
        $params = [];
        if ( $type )   { $where .= ' AND type = %s';   $params[] = $type; }
        if ( $status ) { $where .= ' AND status = %s'; $params[] = $status; }

        $offset = ( $page - 1 ) * $per;
        $total  = (int) $wpdb->get_var( $params
            ? $wpdb->prepare( "SELECT COUNT(*) FROM `" . CIAS_JOB_QUEUE . "` WHERE {$where}", ...$params )
            : "SELECT COUNT(*) FROM `" . CIAS_JOB_QUEUE . "` WHERE {$where}" );

        $jobs = $params
            ? $wpdb->get_results( $wpdb->prepare(
                "SELECT id, type, status, priority, attempts, max_attempts, error_message,
                        worker_id, created_at, started_at, finished_at, available_at
                 FROM `" . CIAS_JOB_QUEUE . "` WHERE {$where}
                 ORDER BY id DESC LIMIT %d OFFSET %d",
                ...[...$params, $per, $offset]
              ) )
            : $wpdb->get_results( $wpdb->prepare(
                "SELECT id, type, status, priority, attempts, max_attempts, error_message,
                        worker_id, created_at, started_at, finished_at, available_at
                 FROM `" . CIAS_JOB_QUEUE . "` WHERE 1=1
                 ORDER BY id DESC LIMIT %d OFFSET %d",
                $per, $offset
              ) );

        $base_url = '?page=cias-ops-monitor&ops_tab=queue';
        if ( $type )   $base_url .= '&jtype='   . $type;
        if ( $status ) $base_url .= '&jstatus=' . $status;

        $dead_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `" . CIAS_JOB_QUEUE . "` WHERE status='dead'" );
        ?>

<!-- Filters -->
<div class="ops-filters">
    <form method="get" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <input type="hidden" name="page" value="cias-ops-monitor">
        <input type="hidden" name="ops_tab" value="queue">

        <select name="jtype" class="ops-select">
            <option value="">All types</option>
            <?php foreach ( ['ocr','evaluate','evaluate_batch','guru_chat','analytics'] as $t ): ?>
            <option value="<?php echo $t; ?>" <?php selected($type,$t); ?>><?php echo $t; ?></option>
            <?php endforeach; ?>
        </select>

        <select name="jstatus" class="ops-select">
            <option value="">All statuses</option>
            <?php foreach ( ['pending','processing','done','failed','dead'] as $s ): ?>
            <option value="<?php echo $s; ?>" <?php selected($status,$s); ?>><?php echo $s; ?></option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="ops-btn">Filter</button>
        <a href="?page=cias-ops-monitor&ops_tab=queue" class="ops-btn ops-btn--ghost">Reset</a>

        <span style="margin-left:auto;color:#9ca3af;font-size:13px"><?php echo number_format($total); ?> jobs</span>

        <?php if ( $dead_count > 0 ): ?>
        <button type="button" class="ops-btn ops-btn--danger" onclick="opsPurge()">
            🗑 Purge <?php echo $dead_count; ?> dead
        </button>
        <?php endif; ?>
    </form>
</div>

<div class="ops-card" style="padding:0;overflow:hidden">
<table class="ops-table ops-table--full">
    <thead>
        <tr>
            <th style="width:60px">ID</th>
            <th>Type</th>
            <th>Status</th>
            <th>Attempts</th>
            <th>Worker</th>
            <th>Created</th>
            <th>Finished</th>
            <th>Error</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php if ( empty($jobs) ): ?>
    <tr><td colspan="9" style="text-align:center;color:#9ca3af;padding:24px">No jobs found.</td></tr>
    <?php else: ?>
    <?php foreach ( $jobs as $j ): ?>
    <tr>
        <td style="font-family:monospace;color:#9ca3af">#<?php echo $j->id; ?></td>
        <td><code class="ops-type-badge"><?php echo esc_html($j->type); ?></code></td>
        <td><?php echo self::status_badge($j->status); ?></td>
        <td style="font-size:12px">
            <?php echo (int)$j->attempts; ?>/<?php echo (int)$j->max_attempts; ?>
        </td>
        <td style="font-size:11px;color:#9ca3af;max-width:120px;overflow:hidden;text-overflow:ellipsis" title="<?php echo esc_attr($j->worker_id ?? ''); ?>">
            <?php echo $j->worker_id ? esc_html( substr( $j->worker_id, 0, 20 ) . '…' ) : '—'; ?>
        </td>
        <td style="font-size:12px;white-space:nowrap"><?php echo esc_html( self::human_time($j->created_at) ); ?></td>
        <td style="font-size:12px;white-space:nowrap"><?php echo $j->finished_at ? esc_html(self::human_time($j->finished_at)) : '—'; ?></td>
        <td style="font-size:11px;color:#dc2626;max-width:200px">
            <?php if ( $j->error_message ): ?>
            <span title="<?php echo esc_attr($j->error_message); ?>">
                <?php echo esc_html( substr($j->error_message, 0, 60) ); ?>…
            </span>
            <?php else: ?>—<?php endif; ?>
        </td>
        <td style="white-space:nowrap">
            <button class="ops-btn ops-btn--sm" onclick="opsInspect(<?php echo $j->id; ?>)" title="Inspect">🔍</button>
            <?php if ( in_array($j->status, ['failed','dead'], true) ): ?>
            <button class="ops-btn ops-btn--sm ops-btn--warn"
                    onclick="opsJobAction('retry', <?php echo $j->id; ?>)">↩ Retry</button>
            <?php endif; ?>
            <button class="ops-btn ops-btn--sm ops-btn--danger"
                    onclick="opsJobAction('delete', <?php echo $j->id; ?>)">🗑</button>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>
</div>

<!-- Pagination -->
<?php if ( $total > $per ): ?>
<div class="ops-pagination">
    <?php
    $pages = ceil( $total / $per );
    for ( $i = 1; $i <= min($pages, 20); $i++ ):
    ?>
    <a href="<?php echo esc_url( $base_url . '&jpage=' . $i ); ?>"
       class="ops-page-btn <?php echo $page === $i ? 'ops-page-btn--active' : ''; ?>">
        <?php echo $i; ?>
    </a>
    <?php endfor; ?>
</div>
<?php endif; ?>
<?php
    }

    // ──────────────────────────────────────────────────────────────────────────
    // TAB: AI Logs
    // ──────────────────────────────────────────────────────────────────────────

    private static function render_logs_tab(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'cias_ai_usage_log';

        // ── Per-model aggregation ─────────────────────────────────────────────
        $model_stats = $wpdb->get_results(
            "SELECT model, context,
                    COUNT(*) AS calls,
                    SUM(input_tokens)  AS in_tok,
                    SUM(output_tokens) AS out_tok,
                    SUM(cost_usd)      AS cost,
                    MAX(created_at)    AS last_call
             FROM `{$table}`
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY model, context
             ORDER BY cost DESC"
        );

        // ── Daily cost (last 14 days) ─────────────────────────────────────────
        $daily = $wpdb->get_results(
            "SELECT DATE(created_at) AS day,
                    SUM(cost_usd) AS cost,
                    COUNT(*) AS calls
             FROM `{$table}`
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
             GROUP BY DATE(created_at)
             ORDER BY day DESC"
        );

        // ── Recent call log ───────────────────────────────────────────────────
        $recent = $wpdb->get_results(
            "SELECT l.id, l.model, l.context, l.input_tokens, l.output_tokens,
                    l.cost_usd, l.created_at, u.display_name
             FROM `{$table}` l
             LEFT JOIN {$wpdb->users} u ON u.ID = l.user_id
             ORDER BY l.id DESC LIMIT 50"
        );

        // ── 429 / error rate from job queue ──────────────────────────────────
        $rate_limit_jobs = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `" . CIAS_JOB_QUEUE . "`
             WHERE error_message LIKE '%rate limit%' OR error_message LIKE '%429%' OR error_message LIKE '%529%'
               AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        ?>

<!-- Per-model breakdown -->
<div class="ops-card" style="margin-bottom:16px">
    <div class="ops-card__title">Per-Model Usage (last 30 days)</div>
    <table class="ops-table ops-table--full">
        <thead><tr>
            <th>Model</th><th>Context</th><th>Calls</th>
            <th>Input tokens</th><th>Output tokens</th>
            <th>Total cost</th><th>Last call</th>
        </tr></thead>
        <tbody>
        <?php if ( empty($model_stats) ): ?>
        <tr><td colspan="7" style="text-align:center;color:#9ca3af;padding:20px">No AI usage logged yet.</td></tr>
        <?php else: ?>
        <?php foreach ( $model_stats as $r ): ?>
        <tr>
            <td><code style="font-size:11px"><?php echo esc_html($r->model); ?></code></td>
            <td><span class="ops-type-badge"><?php echo esc_html($r->context); ?></span></td>
            <td><?php echo number_format($r->calls); ?></td>
            <td><?php echo number_format($r->in_tok); ?></td>
            <td><?php echo number_format($r->out_tok); ?></td>
            <td><strong style="color:<?php echo floatval($r->cost) > 1 ? '#dc2626' : '#16a34a'; ?>">
                $<?php echo number_format(floatval($r->cost), 4); ?>
            </strong></td>
            <td style="font-size:12px;color:#9ca3af"><?php echo esc_html(self::human_time($r->last_call)); ?></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Daily cost + rate-limit alert -->
<div class="ops-grid ops-grid--2" style="margin-bottom:16px">
    <div class="ops-card">
        <div class="ops-card__title">Daily Cost (last 14 days)</div>
        <?php if ( empty($daily) ): ?>
        <p style="color:#9ca3af;text-align:center;padding:12px 0">No data.</p>
        <?php else: ?>
        <div class="ops-sparkbar-wrap">
        <?php
        $max_cost = max( array_map( fn($d) => floatval($d->cost), $daily ) ) ?: 1;
        foreach ( $daily as $d ):
            $pct = min( 100, round( floatval($d->cost) / $max_cost * 100 ) );
        ?>
        <div class="ops-sparkbar-item" title="$<?php echo number_format(floatval($d->cost),4); ?> — <?php echo $d->calls; ?> calls">
            <div class="ops-sparkbar-bar" style="height:<?php echo max(4,$pct); ?>%"></div>
            <div class="ops-sparkbar-label"><?php echo date('d M', strtotime($d->day)); ?></div>
            <div class="ops-sparkbar-val">$<?php echo number_format(floatval($d->cost),2); ?></div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="ops-card">
        <div class="ops-card__title">Rate Limit Hits (last 24h)</div>
        <div style="text-align:center;padding:20px 0">
            <div style="font-size:40px;font-weight:700;color:<?php echo $rate_limit_jobs > 0 ? '#dc2626' : '#16a34a'; ?>">
                <?php echo $rate_limit_jobs; ?>
            </div>
            <div style="color:#6b7280;font-size:13px">jobs failed with rate-limit error</div>
            <?php if ( $rate_limit_jobs > 0 ): ?>
            <div style="margin-top:12px;background:#fef2f2;border-radius:8px;padding:10px;font-size:12px;color:#dc2626">
                ⚠️ Consider adding exponential backoff to the evaluator and OCR classes.
                See the <code>429/529</code> bug report.
            </div>
            <?php else: ?>
            <div style="margin-top:12px;font-size:12px;color:#16a34a">✅ No rate-limit errors detected</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Recent log -->
<div class="ops-card" style="padding:0;overflow:hidden">
    <div class="ops-card__title" style="padding:14px 18px;border-bottom:1px solid #f3f4f6">
        Recent AI Calls (last 50)
    </div>
    <table class="ops-table ops-table--full">
        <thead><tr>
            <th>ID</th><th>Model</th><th>Context</th><th>User</th>
            <th>In tokens</th><th>Out tokens</th><th>Cost</th><th>When</th>
        </tr></thead>
        <tbody>
        <?php foreach ( $recent as $r ): ?>
        <tr>
            <td style="color:#9ca3af;font-size:11px">#<?php echo $r->id; ?></td>
            <td style="font-size:11px"><code><?php echo esc_html(substr($r->model,0,28)); ?></code></td>
            <td><span class="ops-type-badge"><?php echo esc_html($r->context); ?></span></td>
            <td style="font-size:12px"><?php echo $r->display_name ? esc_html($r->display_name) : '<span style="color:#9ca3af">system</span>'; ?></td>
            <td><?php echo number_format($r->input_tokens); ?></td>
            <td><?php echo number_format($r->output_tokens); ?></td>
            <td>$<?php echo number_format(floatval($r->cost_usd),5); ?></td>
            <td style="font-size:12px;color:#9ca3af"><?php echo esc_html(self::human_time($r->created_at)); ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if ( empty($recent) ): ?>
        <tr><td colspan="8" style="text-align:center;color:#9ca3af;padding:20px">No AI calls logged.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php
    }

    // ──────────────────────────────────────────────────────────────────────────
    // TAB: Worker Health
    // ──────────────────────────────────────────────────────────────────────────

    private static function render_workers_tab(): void {
        global $wpdb;

        // Last seen: most recent job claimed by each worker type (via worker_id prefix)
        $last_workers = $wpdb->get_results(
            "SELECT type,
                    MAX(started_at) AS last_started,
                    MAX(finished_at) AS last_finished,
                    worker_id,
                    COUNT(*) AS jobs_claimed
             FROM `" . CIAS_JOB_QUEUE . "`
             WHERE started_at IS NOT NULL
               AND started_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
             GROUP BY type
             ORDER BY last_started DESC"
        );

        // Stale processing jobs per worker
        $stale_jobs = $wpdb->get_results(
            "SELECT id, type, worker_id, started_at,
                    TIMESTAMPDIFF(MINUTE, started_at, NOW()) AS mins_ago
             FROM `" . CIAS_JOB_QUEUE . "`
             WHERE status='processing'
               AND started_at < DATE_SUB(NOW(), INTERVAL 8 MINUTE)
             ORDER BY started_at ASC"
        );

        // Job throughput (done jobs last 60 min)
        $throughput = $wpdb->get_results(
            "SELECT type,
                    COUNT(*) AS done_jobs,
                    AVG(TIMESTAMPDIFF(SECOND, started_at, finished_at)) AS avg_duration_s
             FROM `" . CIAS_JOB_QUEUE . "`
             WHERE status='done'
               AND finished_at >= DATE_SUB(NOW(), INTERVAL 60 MINUTE)
             GROUP BY type"
        );

        // Dead-letter trend (last 7 days, grouped by day)
        $dead_trend = $wpdb->get_results(
            "SELECT DATE(created_at) AS day, type, COUNT(*) AS cnt
             FROM `" . CIAS_JOB_QUEUE . "`
             WHERE status='dead' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY DATE(created_at), type
             ORDER BY day DESC, cnt DESC"
        );

        // Cron schedule check via WP options
        $cron_jobs  = _get_cron_array() ?: [];
        $wp_crons   = [];
        foreach ( $cron_jobs as $timestamp => $hooks ) {
            foreach ( $hooks as $hook => $events ) {
                if ( strpos($hook, 'cias') !== false ) {
                    $wp_crons[] = [ 'hook' => $hook, 'next' => $timestamp ];
                }
            }
        }
        ?>

<!-- Worker last-seen -->
<div class="ops-card" style="margin-bottom:16px">
    <div class="ops-card__title">Worker Last Seen (last 2 hours)</div>
    <?php if ( empty($last_workers) ): ?>
    <div class="ops-alert ops-alert--warn" style="margin:0">
        ⚠️ No worker activity detected in the last 2 hours. Are your cron jobs running?
        Check Cloudways → Cron Job Manager and confirm workers are scheduled.
    </div>
    <?php else: ?>
    <table class="ops-table ops-table--full">
        <thead><tr>
            <th>Worker type</th><th>Last started</th><th>Last finished</th>
            <th>Jobs claimed (2h)</th><th>Last worker ID</th><th>Status</th>
        </tr></thead>
        <tbody>
        <?php foreach ( $last_workers as $w ):
            $mins = $w->last_started ? (int)((time() - strtotime($w->last_started)) / 60) : 999;
            $health = $mins < 2 ? 'ok' : ($mins < 5 ? 'warn' : 'err');
        ?>
        <tr>
            <td><code><?php echo esc_html($w->type); ?></code></td>
            <td style="font-size:12px"><?php echo esc_html(self::human_time($w->last_started)); ?></td>
            <td style="font-size:12px"><?php echo $w->last_finished ? esc_html(self::human_time($w->last_finished)) : '—'; ?></td>
            <td><?php echo (int)$w->jobs_claimed; ?></td>
            <td style="font-size:11px;color:#9ca3af" title="<?php echo esc_attr($w->worker_id); ?>">
                <?php echo esc_html(substr($w->worker_id ?? '', 0, 30)); ?>
            </td>
            <td><?php echo self::status_badge(
                    $health === 'ok' ? 'done' :
                    ($health === 'warn' ? 'failed' : 'dead'),
                    $health === 'ok' ? 'Active' :
                    ($health === 'warn' ? "{$mins}m ago" : "Last seen {$mins}m ago")
                ); ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- Stale jobs + Throughput -->
<div class="ops-grid ops-grid--2" style="margin-bottom:16px">

    <div class="ops-card">
        <div class="ops-card__title">Stale Processing Jobs (&gt;8 min)</div>
        <?php if ( empty($stale_jobs) ): ?>
        <p style="color:#16a34a;font-size:13px;text-align:center;padding:12px 0">✅ No stale jobs</p>
        <?php else: ?>
        <table class="ops-table ops-table--full" style="font-size:12px">
            <thead><tr><th>Job ID</th><th>Type</th><th>Stuck for</th><th>Worker</th><th></th></tr></thead>
            <tbody>
            <?php foreach ( $stale_jobs as $j ): ?>
            <tr>
                <td>#<?php echo $j->id; ?></td>
                <td><code><?php echo esc_html($j->type); ?></code></td>
                <td style="color:#ea580c"><?php echo $j->mins_ago; ?>m</td>
                <td style="color:#9ca3af;font-size:11px"><?php echo esc_html(substr($j->worker_id ?? '', 0, 20)); ?></td>
                <td>
                    <button class="ops-btn ops-btn--sm ops-btn--warn"
                            onclick="opsJobAction('retry', <?php echo $j->id; ?>)">↩ Requeue</button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <button class="ops-btn ops-btn--warn" onclick="opsRequeueStale()" style="margin-top:12px;width:100%">
            🔄 Requeue all stale jobs
        </button>
        <?php endif; ?>
    </div>

    <div class="ops-card">
        <div class="ops-card__title">Throughput (last 60 min)</div>
        <?php if ( empty($throughput) ): ?>
        <p style="color:#9ca3af;font-size:13px;text-align:center;padding:12px 0">No completed jobs in last hour.</p>
        <?php else: ?>
        <table class="ops-table ops-table--full">
            <thead><tr><th>Type</th><th>Done</th><th>Avg duration</th></tr></thead>
            <tbody>
            <?php foreach ( $throughput as $t ): ?>
            <tr>
                <td><code><?php echo esc_html($t->type); ?></code></td>
                <td><?php echo (int)$t->done_jobs; ?></td>
                <td><?php echo $t->avg_duration_s !== null ? round(floatval($t->avg_duration_s)) . 's' : '—'; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- Dead-letter trend -->
<div class="ops-card" style="margin-bottom:16px">
    <div class="ops-card__title">Dead Job Trend (last 7 days)</div>
    <?php if ( empty($dead_trend) ): ?>
    <p style="color:#16a34a;text-align:center;padding:12px 0">✅ No dead jobs in the last 7 days</p>
    <?php else: ?>
    <table class="ops-table ops-table--full">
        <thead><tr><th>Date</th><th>Type</th><th>Dead jobs</th></tr></thead>
        <tbody>
        <?php foreach ( $dead_trend as $d ): ?>
        <tr>
            <td><?php echo esc_html($d->day); ?></td>
            <td><code><?php echo esc_html($d->type); ?></code></td>
            <td style="color:#dc2626;font-weight:500"><?php echo (int)$d->cnt; ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- WP Cron scheduled CIAS hooks -->
<div class="ops-card">
    <div class="ops-card__title">WP-Cron CIAS Hooks (informational)</div>
    <?php if ( empty($wp_crons) ): ?>
    <p style="color:#9ca3af;font-size:13px">No CIAS WP-Cron hooks scheduled.
        This is expected if you use real system cron (Cloudways) to run workers directly.</p>
    <?php else: ?>
    <table class="ops-table ops-table--full" style="font-size:12px">
        <thead><tr><th>Hook</th><th>Next run</th></tr></thead>
        <tbody>
        <?php foreach ( $wp_crons as $c ): ?>
        <tr>
            <td><code><?php echo esc_html($c['hook']); ?></code></td>
            <td><?php echo esc_html(self::human_time(date('Y-m-d H:i:s', $c['next']))); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
    <p style="font-size:12px;color:#9ca3af;margin-top:12px">
        Workers are designed for real system cron (Cloudways Cron Job Manager).
        See <code>phase-b/workers/bootstrap.php</code> for the recommended schedule.
    </p>
</div>
<?php
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AJAX handlers
    // ──────────────────────────────────────────────────────────────────────────

    public static function ajax_ops_data(): void {
        if ( ! current_user_can('manage_options') || ! check_ajax_referer('cias_ops_nonce','nonce',false) ) {
            wp_send_json_error('Unauthorized', 403);
        }
        global $wpdb;

        $by_status = $wpdb->get_results(
            "SELECT status, COUNT(*) AS cnt FROM `" . CIAS_JOB_QUEUE . "` GROUP BY status"
        );
        $counters = [];
        foreach ( $by_status as $r ) $counters[ $r->status ] = (int) $r->cnt;
        $counters['calls_today'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$wpdb->prefix}cias_ai_usage_log` WHERE DATE(created_at)=CURDATE()"
        );

        $dead    = $counters['dead']       ?? 0;
        $stale   = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `" . CIAS_JOB_QUEUE . "`
             WHERE status='processing' AND started_at < DATE_SUB(NOW(), INTERVAL 8 MINUTE)"
        );
        $alerts  = self::build_alerts( $dead, $stale, CIAS_R2::is_configured(), null, $counters['pending'] ?? 0 );

        wp_send_json_success( [ 'counters' => $counters, 'alert_bar' => $alerts ] );
    }

    public static function ajax_retry_job(): void {
        self::require_admin_ajax();
        $job_id = (int)( $_POST['job_id'] ?? 0 );
        if ( ! $job_id ) wp_send_json_error( 'Invalid job ID' );

        global $wpdb;
        $updated = $wpdb->update(
            CIAS_JOB_QUEUE,
            [ 'status' => 'pending', 'worker_id' => null, 'started_at' => null, 'error_message' => null ],
            [ 'id'     => $job_id ]
        );
        $updated !== false ? wp_send_json_success("Job #{$job_id} re-queued.") : wp_send_json_error('DB error.');
    }

    public static function ajax_delete_job(): void {
        self::require_admin_ajax();
        $job_id = (int)( $_POST['job_id'] ?? 0 );
        if ( ! $job_id ) wp_send_json_error( 'Invalid job ID' );

        global $wpdb;
        $deleted = $wpdb->delete( CIAS_JOB_QUEUE, [ 'id' => $job_id ], ['%d'] );
        $deleted ? wp_send_json_success("Job #{$job_id} deleted.") : wp_send_json_error('DB error.');
    }

    public static function ajax_purge_dead(): void {
        self::require_admin_ajax();
        global $wpdb;
        $n = $wpdb->query( "DELETE FROM `" . CIAS_JOB_QUEUE . "` WHERE status='dead'" );
        wp_send_json_success( "Purged {$n} dead jobs." );
    }

    public static function ajax_requeue_stale(): void {
        self::require_admin_ajax();
        global $wpdb;
        $n = $wpdb->query(
            "UPDATE `" . CIAS_JOB_QUEUE . "`
             SET status='pending', worker_id=NULL, started_at=NULL
             WHERE status='processing'
               AND started_at < DATE_SUB(NOW(), INTERVAL 8 MINUTE)
               AND attempts < max_attempts"
        );
        wp_send_json_success( "Re-queued {$n} stale job(s)." );
    }

    private static function require_admin_ajax(): void {
        if ( ! current_user_can('manage_options') || ! check_ajax_referer('cias_ops_nonce','nonce',false) ) {
            wp_send_json_error('Unauthorized', 403);
        }
    }

    // Handle GET-based inline actions (from links rather than JS)
    private static function handle_inline_action(): void {
        if ( empty($_GET['ops_action']) ) return;
        if ( ! current_user_can('manage_options') ) return;
        if ( ! isset($_GET['_wpnonce']) || ! wp_verify_nonce($_GET['_wpnonce'], 'cias_ops_inline') ) return;

        global $wpdb;
        $action = sanitize_key($_GET['ops_action']);
        $job_id = (int)($_GET['job_id'] ?? 0);

        if ( $action === 'retry' && $job_id ) {
            $wpdb->update( CIAS_JOB_QUEUE,
                [ 'status' => 'pending', 'worker_id' => null, 'started_at' => null ],
                [ 'id' => $job_id ]
            );
            echo '<div class="notice notice-success"><p>Job #' . $job_id . ' re-queued.</p></div>';
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────


    // ═══════════════════════════════════════════════════════════════════════
    // NEW TAB: Queue Latency
    // ═══════════════════════════════════════════════════════════════════════
    public static function tab_latency(): void {
        global $wpdb;
        $stats=$wpdb->get_results("SELECT type,COUNT(*) AS cnt,AVG(TIMESTAMPDIFF(SECOND,created_at,started_at)) AS avg_wait,MAX(TIMESTAMPDIFF(SECOND,created_at,started_at)) AS max_wait,AVG(TIMESTAMPDIFF(SECOND,started_at,finished_at)) AS avg_proc,MAX(TIMESTAMPDIFF(SECOND,started_at,finished_at)) AS max_proc FROM `".CIAS_JOB_QUEUE."` WHERE status='done' AND finished_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR) AND started_at IS NOT NULL GROUP BY type ORDER BY avg_wait DESC");
        $longest=$wpdb->get_results("SELECT id,type,created_at,TIMESTAMPDIFF(MINUTE,created_at,NOW()) AS wait_min FROM `".CIAS_JOB_QUEUE."` WHERE status='pending' ORDER BY created_at ASC LIMIT 10");
        $hourly=$wpdb->get_results("SELECT DATE_FORMAT(finished_at,'%H:00') AS hr,AVG(TIMESTAMPDIFF(SECOND,created_at,started_at)) AS avg_wait,COUNT(*) AS jobs FROM `".CIAS_JOB_QUEUE."` WHERE status='done' AND finished_at>=DATE_SUB(NOW(),INTERVAL 12 HOUR) AND started_at IS NOT NULL GROUP BY hr ORDER BY hr");
        $p95_n=max(0,(int)($wpdb->get_var("SELECT COUNT(*) FROM `".CIAS_JOB_QUEUE."` WHERE status='done' AND finished_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR) AND started_at IS NOT NULL")*0.05));
        $p95=(float)$wpdb->get_var("SELECT TIMESTAMPDIFF(SECOND,created_at,started_at) FROM `".CIAS_JOB_QUEUE."` WHERE status='done' AND finished_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR) AND started_at IS NOT NULL ORDER BY TIMESTAMPDIFF(SECOND,created_at,started_at) DESC LIMIT 1 OFFSET {$p95_n}");
        $aw=!empty($stats)?array_sum(array_column((array)$stats,'avg_wait'))/count($stats):0;
        $ap=!empty($stats)?array_sum(array_column((array)$stats,'avg_proc'))/count($stats):0;
        ?>
<div class="ops-grid ops-grid--4" style="margin-bottom:16px">
<div class="ops-kpi"><div class="ops-kpi__icon">⏳</div><div class="ops-kpi__val" style="color:<?php echo $aw>60?'#ef4444':($aw>20?'#f59e0b':'#16a34a');?>"><?php echo self::fmt_dur($aw);?></div><div class="ops-kpi__label">Avg Queue Wait</div></div>
<div class="ops-kpi"><div class="ops-kpi__icon">⚙️</div><div class="ops-kpi__val" style="color:#3b82f6"><?php echo self::fmt_dur($ap);?></div><div class="ops-kpi__label">Avg Processing</div></div>
<div class="ops-kpi"><div class="ops-kpi__icon">📊</div><div class="ops-kpi__val" style="color:<?php echo $p95>120?'#ef4444':'#374151';?>"><?php echo self::fmt_dur($p95);?></div><div class="ops-kpi__label">P95 Wait</div></div>
<div class="ops-kpi"><div class="ops-kpi__icon">🔢</div><div class="ops-kpi__val" style="color:#8b5cf6"><?php echo count($longest);?></div><div class="ops-kpi__label">Currently Pending</div></div>
</div>
<div class="ops-grid ops-grid--2" style="margin-bottom:16px">
<div class="ops-card"><div class="ops-card__title">Latency by Type (last 24h)</div>
<?php if(empty($stats)):?><p style="color:#9ca3af;text-align:center;padding:20px">No completed jobs in last 24h.</p><?php else:?>
<table class="ops-table ops-table--full"><thead><tr><th>Type</th><th>Jobs</th><th>Avg Wait</th><th>Max Wait</th><th>Avg Process</th><th>Max Process</th></tr></thead><tbody>
<?php foreach($stats as $r):?>
<tr><td><code><?php echo esc_html($r->type);?></code></td><td><?php echo number_format($r->cnt);?></td>
<td style="color:<?php echo $r->avg_wait>60?'#ef4444':($r->avg_wait>20?'#f59e0b':'#16a34a');?>;font-weight:600"><?php echo self::fmt_dur($r->avg_wait);?></td>
<td style="color:#9ca3af;font-size:12px"><?php echo self::fmt_dur($r->max_wait);?></td>
<td><?php echo self::fmt_dur($r->avg_proc);?></td>
<td style="color:#9ca3af;font-size:12px"><?php echo self::fmt_dur($r->max_proc);?></td></tr>
<?php endforeach;?></tbody></table><?php endif;?>
</div>
<div class="ops-card"><div class="ops-card__title">Longest Waiting Pending Jobs</div>
<?php if(empty($longest)):?><p style="color:#16a34a;text-align:center;padding:20px">✅ No pending jobs</p><?php else:?>
<table class="ops-table ops-table--full"><thead><tr><th>Job</th><th>Type</th><th>Waiting</th></tr></thead><tbody>
<?php foreach($longest as $j):?>
<tr><td style="color:#9ca3af">#<?php echo $j->id;?></td><td><code><?php echo esc_html($j->type);?></code></td>
<td style="color:<?php echo $j->wait_min>10?'#ef4444':($j->wait_min>3?'#f59e0b':'#374151');?>;font-weight:600"><?php echo $j->wait_min;?> min</td></tr>
<?php endforeach;?></tbody></table><?php endif;?>
</div></div>
<?php if(!empty($hourly)):?>
<div class="ops-card"><div class="ops-card__title">Avg Queue Wait — Hourly Trend (last 12h)</div>
<?php $mw=max(array_map(fn($r)=>floatval($r->avg_wait),$hourly))?:1;?>
<div style="display:flex;align-items:flex-end;gap:8px;height:80px">
<?php foreach($hourly as $r):$pct=min(100,round(floatval($r->avg_wait)/$mw*100));$col=floatval($r->avg_wait)>60?'#ef4444':(floatval($r->avg_wait)>20?'#f59e0b':'#3b82f6');?>
<div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;gap:2px" title="<?php echo $r->hr;?>: <?php echo self::fmt_dur($r->avg_wait);?> (<?php echo $r->jobs;?> jobs)">
<div style="width:100%;background:<?php echo $col;?>;border-radius:3px 3px 0 0;height:<?php echo max(4,$pct);?>%"></div>
<div style="font-size:9px;color:#9ca3af"><?php echo $r->hr;?></div>
</div>
<?php endforeach;?></div>
<div style="font-size:11px;color:#9ca3af;margin-top:6px;text-align:right">🟦 &lt;20s &nbsp; 🟡 20–60s &nbsp; 🔴 &gt;60s</div>
</div>
<?php endif;?>
<?php
    }

    // ═══════════════════════════════════════════════════════════════════════
    // NEW TAB: Error Intelligence
    // ═══════════════════════════════════════════════════════════════════════
    public static function tab_errors(): void {
        global $wpdb;
        $raw=$wpdb->get_results("SELECT error_message,type,COUNT(*) AS cnt,MAX(created_at) AS last_seen FROM `".CIAS_JOB_QUEUE."` WHERE status IN ('failed','dead') AND error_message IS NOT NULL AND created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY) GROUP BY error_message,type ORDER BY cnt DESC LIMIT 200");
        $cats=['Rate Limit (429/529)'=>0,'Claude Timeout'=>0,'OCR Parse Failed'=>0,'Invalid / Bad Image'=>0,'Redis Unavailable'=>0,'JSON Decode Error'=>0,'Network / HTTP Error'=>0,'Memory Exceeded'=>0,'Other'=>0];
        $total_err=0;
        foreach($raw as $r){$m=strtolower($r->error_message??'');$total_err+=$r->cnt;
            if(preg_match('/429|529|rate.?limit|too.?many/i',$m))$cats['Rate Limit (429/529)']+=$r->cnt;
            elseif(preg_match('/timeout|timed.?out|deadline/i',$m))$cats['Claude Timeout']+=$r->cnt;
            elseif(preg_match('/ocr|parse.?fail|no.?text/i',$m))$cats['OCR Parse Failed']+=$r->cnt;
            elseif(preg_match('/invalid.?image|bad.?image|image.?format|unsupported/i',$m))$cats['Invalid / Bad Image']+=$r->cnt;
            elseif(preg_match('/redis|predis|upstash/i',$m))$cats['Redis Unavailable']+=$r->cnt;
            elseif(preg_match('/json.?decode|malformed.?json/i',$m))$cats['JSON Decode Error']+=$r->cnt;
            elseif(preg_match('/curl|http.*error|connection|network/i',$m))$cats['Network / HTTP Error']+=$r->cnt;
            elseif(preg_match('/memory|out.?of.?memory/i',$m))$cats['Memory Exceeded']+=$r->cnt;
            else $cats['Other']+=$r->cnt;
        }
        arsort($cats);
        $trend=$wpdb->get_results("SELECT DATE(created_at) AS day,status,COUNT(*) AS cnt FROM `".CIAS_JOB_QUEUE."` WHERE status IN ('failed','dead') AND created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY) GROUP BY day,status ORDER BY day DESC");
        $t24=(int)$wpdb->get_var("SELECT COUNT(*) FROM `".CIAS_JOB_QUEUE."` WHERE created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)");
        $e24=(int)$wpdb->get_var("SELECT COUNT(*) FROM `".CIAS_JOB_QUEUE."` WHERE status IN ('failed','dead') AND created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)");
        $erate=$t24>0?round($e24/$t24*100,1):0;
        $ccols=['Rate Limit (429/529)'=>'#dc2626','Claude Timeout'=>'#ea580c','OCR Parse Failed'=>'#d97706','Invalid / Bad Image'=>'#ca8a04','Redis Unavailable'=>'#7c3aed','JSON Decode Error'=>'#0891b2','Network / HTTP Error'=>'#0369a1','Memory Exceeded'=>'#be123c','Other'=>'#9ca3af'];
        ?>
<div class="ops-grid ops-grid--4" style="margin-bottom:16px">
<div class="ops-kpi"><div class="ops-kpi__icon">📛</div><div class="ops-kpi__val" style="color:<?php echo $e24>0?'#dc2626':'#16a34a';?>"><?php echo $e24;?></div><div class="ops-kpi__label">Errors (24h)</div></div>
<div class="ops-kpi"><div class="ops-kpi__icon">📉</div><div class="ops-kpi__val" style="color:<?php echo $erate>10?'#dc2626':($erate>2?'#f59e0b':'#16a34a');?>"><?php echo $erate;?>%</div><div class="ops-kpi__label">Error Rate (24h)</div></div>
<div class="ops-kpi"><div class="ops-kpi__icon">🔴</div><div class="ops-kpi__val" style="color:#8b5cf6"><?php echo $total_err;?></div><div class="ops-kpi__label">Total Errors (7d)</div></div>
<div class="ops-kpi"><div class="ops-kpi__icon">🏷</div><div class="ops-kpi__val"><?php echo count(array_filter($cats));?></div><div class="ops-kpi__label">Error Categories</div></div>
</div>
<div class="ops-grid ops-grid--2" style="margin-bottom:16px">
<div class="ops-card"><div class="ops-card__title">Error Categories (last 7 days)</div>
<?php if(!$total_err):?><p style="color:#16a34a;text-align:center;padding:20px">✅ No errors in last 7 days!</p><?php else:?>
<table class="ops-table ops-table--full"><thead><tr><th>Error Type</th><th>Count</th><th>Share</th></tr></thead><tbody>
<?php foreach($cats as $cat=>$cnt):if(!$cnt)continue;$pct=round($cnt/$total_err*100);$col=$ccols[$cat]??'#9ca3af';?>
<tr><td style="font-weight:500"><?php echo esc_html($cat);?></td><td style="color:<?php echo $col;?>;font-weight:700"><?php echo number_format($cnt);?></td>
<td><div style="display:flex;align-items:center;gap:6px"><div style="flex:1;background:#f3f4f6;border-radius:3px;height:8px"><div style="width:<?php echo $pct;?>%;background:<?php echo $col;?>;height:8px;border-radius:3px"></div></div><span style="font-size:11px;color:#6b7280;width:30px"><?php echo $pct;?>%</span></div></td></tr>
<?php endforeach;?></tbody></table><?php endif;?>
</div>
<div class="ops-card"><div class="ops-card__title">Error Trend (last 7 days)</div>
<?php if(empty($trend)):?><p style="color:#16a34a;text-align:center;padding:20px">✅ No errors</p><?php else:?>
<table class="ops-table ops-table--full"><thead><tr><th>Date</th><th>Status</th><th>Count</th></tr></thead><tbody>
<?php foreach($trend as $r):?><tr><td style="font-size:12px"><?php echo esc_html($r->day);?></td><td><?php echo self::status_badge($r->status);?></td><td style="font-weight:600;color:<?php echo $r->status==='dead'?'#dc2626':'#ea580c';?>"><?php echo $r->cnt;?></td></tr>
<?php endforeach;?></tbody></table><?php endif;?>
</div></div>
<?php if(!empty($raw)):?>
<div class="ops-card" style="padding:0;overflow:hidden"><div class="ops-card__title" style="padding:14px 18px;border-bottom:1px solid #f3f4f6">Raw Error Log (last 7d)</div>
<table class="ops-table ops-table--full"><thead><tr><th>Type</th><th>Count</th><th>Last Seen</th><th>Error Message</th></tr></thead><tbody>
<?php foreach(array_slice((array)$raw,0,30) as $r):?>
<tr><td><code><?php echo esc_html($r->type);?></code></td><td style="font-weight:600;color:#dc2626"><?php echo $r->cnt;?></td><td style="font-size:12px;color:#9ca3af;white-space:nowrap"><?php echo esc_html(self::human_time($r->last_seen));?></td><td style="font-size:12px;max-width:400px" title="<?php echo esc_attr($r->error_message??'');?>"><?php echo esc_html(substr($r->error_message??'',0,120));?></td></tr>
<?php endforeach;?></tbody></table></div>
<?php endif;?>
<?php
    }

    // ═══════════════════════════════════════════════════════════════════════
    // NEW TAB: Cost Forecast
    // ═══════════════════════════════════════════════════════════════════════
    public static function tab_costs(): void {
        global $wpdb;
        $ai_t=$wpdb->prefix.'cias_ai_usage_log';
        $today_cost=(float)$wpdb->get_var("SELECT COALESCE(SUM(cost_usd),0) FROM `{$ai_t}` WHERE DATE(created_at)=CURDATE()");
        $today_stu=(int)$wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM `{$ai_t}` WHERE DATE(created_at)=CURDATE()");
        $month_act=(float)$wpdb->get_var("SELECT COALESCE(SUM(cost_usd),0) FROM `{$ai_t}` WHERE created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)");
        $doy=(int)date('j');$dim=(int)date('t');$proj=$doy>0?$today_cost/$doy*$dim:0;
        $per_stu=$today_stu>0?$today_cost/$today_stu:0;
        $by_mod=$wpdb->get_results("SELECT context,SUM(cost_usd) AS cost,COUNT(*) AS calls,SUM(input_tokens+output_tokens) AS tokens FROM `{$ai_t}` WHERE created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) GROUP BY context ORDER BY cost DESC");
        $by_model=$wpdb->get_results("SELECT model,SUM(cost_usd) AS cost,COUNT(*) AS calls,AVG(input_tokens+output_tokens) AS avg_tok FROM `{$ai_t}` WHERE created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) GROUP BY model ORDER BY cost DESC");
        $daily=$wpdb->get_results("SELECT DATE(created_at) AS day,SUM(cost_usd) AS cost,COUNT(DISTINCT user_id) AS stu FROM `{$ai_t}` WHERE created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) GROUP BY DATE(created_at) ORDER BY day");
        $tmc=array_sum(array_column((array)$by_mod,'cost'))?:1;
        $icons=['guru'=>'🧙','ocr'=>'🔍','evaluation'=>'📝','evaluation_batch'=>'📦','guru_vision'=>'👁'];
        ?>
<div class="ops-grid ops-grid--4" style="margin-bottom:16px">
<div class="ops-kpi"><div class="ops-kpi__icon">💸</div><div class="ops-kpi__val">$<?php echo number_format($today_cost,4);?></div><div class="ops-kpi__label">Spent Today</div></div>
<div class="ops-kpi"><div class="ops-kpi__icon">📅</div><div class="ops-kpi__val" style="color:<?php echo $proj>10?'#dc2626':($proj>5?'#f59e0b':'#16a34a');?>">$<?php echo number_format($proj,2);?></div><div class="ops-kpi__label">Projected (this month)</div></div>
<div class="ops-kpi"><div class="ops-kpi__icon">👤</div><div class="ops-kpi__val" style="color:#8b5cf6">$<?php echo number_format($per_stu,4);?></div><div class="ops-kpi__label">Per-Student Today</div></div>
<div class="ops-kpi"><div class="ops-kpi__icon">📆</div><div class="ops-kpi__val" style="color:#3b82f6">$<?php echo number_format($month_act,2);?></div><div class="ops-kpi__label">Last 30 Days Actual</div></div>
</div>
<?php if($proj>0):?><div class="ops-alert ops-alert--<?php echo $proj>20?'error':($proj>10?'warn':'info');?>" style="margin-bottom:16px">📊 Day <?php echo $doy;?>/<?php echo $dim;?> · today $<?php echo number_format($today_cost,4);?> → projected <strong>$<?php echo number_format($proj,2);?>/month</strong>.<?php if($proj>10):?> Consider enabling prompt caching or batch evaluation.<?php endif;?></div><?php endif;?>
<div class="ops-grid ops-grid--2" style="margin-bottom:16px">
<div class="ops-card"><div class="ops-card__title">Cost by Module (last 30 days)</div>
<?php if(empty($by_mod)):?><p style="color:#9ca3af;text-align:center;padding:20px">No data yet.</p><?php else:?>
<?php foreach($by_mod as $m):$pct=round(floatval($m->cost)/$tmc*100);$icon=$icons[$m->context]??'🔧';?>
<div style="margin-bottom:14px">
<div style="display:flex;justify-content:space-between;margin-bottom:4px"><span style="font-weight:500"><?php echo $icon;?> <?php echo esc_html($m->context);?></span><span style="font-size:12px;color:#6b7280">$<?php echo number_format(floatval($m->cost),4);?> (<?php echo $pct;?>%)</span></div>
<div style="background:#f3f4f6;border-radius:4px;height:10px"><div style="width:<?php echo $pct;?>%;background:#3b82f6;height:10px;border-radius:4px"></div></div>
<div style="font-size:11px;color:#9ca3af;margin-top:2px"><?php echo number_format($m->calls);?> calls · <?php echo number_format($m->tokens);?> tokens</div>
</div>
<?php endforeach;endif;?></div>
<div class="ops-card"><div class="ops-card__title">Daily Spend Trend (last 30 days)</div>
<?php if(empty($daily)):?><p style="color:#9ca3af;text-align:center;padding:20px">No data yet.</p><?php else:$md=max(array_map(fn($d)=>floatval($d->cost),$daily))?:1;?>
<div style="display:flex;align-items:flex-end;gap:3px;height:100px">
<?php foreach($daily as $d):$pct=min(100,round(floatval($d->cost)/$md*100));?>
<div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end" title="<?php echo $d->day;?>: $<?php echo number_format(floatval($d->cost),4);?>"><div style="width:100%;background:#3b82f6;border-radius:2px 2px 0 0;height:<?php echo max(2,$pct);?>%"></div></div>
<?php endforeach;?></div>
<div style="display:flex;justify-content:space-between;font-size:10px;color:#9ca3af;margin-top:4px"><span><?php echo $daily[0]->day??'';?></span><span>Today</span></div>
<div style="margin-top:14px;padding-top:12px;border-top:1px solid #f3f4f6" class="ops-stat-list">
<div class="ops-stat-row"><span>Active students today</span><strong><?php echo $today_stu;?></strong></div>
<div class="ops-stat-row"><span>Avg daily cost (30d)</span><strong>$<?php echo number_format(count($daily)?$month_act/count($daily):0,4);?></strong></div>
</div><?php endif;?>
</div></div>
<?php if(!empty($by_model)):?>
<div class="ops-card"><div class="ops-card__title">Model Comparison (last 30 days)</div>
<table class="ops-table ops-table--full"><thead><tr><th>Model</th><th>Calls</th><th>Total Cost</th><th>$/Call</th><th>Avg Tokens</th><th>Share</th></tr></thead><tbody>
<?php $tmmc=array_sum(array_column((array)$by_model,'cost'))?:1;foreach($by_model as $m):$cpc=$m->calls>0?floatval($m->cost)/$m->calls:0;$sh=round(floatval($m->cost)/$tmmc*100);?>
<tr><td><code style="font-size:11px"><?php echo esc_html($m->model);?></code></td><td><?php echo number_format($m->calls);?></td><td style="font-weight:600">$<?php echo number_format(floatval($m->cost),4);?></td><td style="color:#8b5cf6">$<?php echo number_format($cpc,6);?></td><td><?php echo number_format($m->avg_tok);?></td>
<td><div style="display:flex;align-items:center;gap:4px"><div style="flex:1;background:#f3f4f6;border-radius:3px;height:6px"><div style="width:<?php echo $sh;?>%;background:#3b82f6;height:6px;border-radius:3px"></div></div><span style="font-size:11px;color:#6b7280;width:28px"><?php echo $sh;?>%</span></div></td></tr>
<?php endforeach;?></tbody></table></div>
<?php endif;?>
<?php
    }

    // ═══════════════════════════════════════════════════════════════════════
    // NEW TAB: Dead-Letter Queue
    // ═══════════════════════════════════════════════════════════════════════
    public static function tab_deadletter(): void {
        global $wpdb;
        $pg=max(1,(int)($_GET['dlpage']??1));$per=20;
        $total=(int)$wpdb->get_var("SELECT COUNT(*) FROM `".CIAS_JOB_QUEUE."` WHERE status='dead'");
        $jobs=$wpdb->get_results($wpdb->prepare("SELECT id,type,attempts,max_attempts,error_message,created_at,finished_at FROM `".CIAS_JOB_QUEUE."` WHERE status='dead' ORDER BY id DESC LIMIT %d OFFSET %d",$per,($pg-1)*$per));
        $by_type=$wpdb->get_results("SELECT type,COUNT(*) AS cnt FROM `".CIAS_JOB_QUEUE."` WHERE status='dead' GROUP BY type ORDER BY cnt DESC");
        ?>
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
<div><h2 style="margin:0;font-size:18px">💀 Dead-Letter Queue</h2><p style="margin:4px 0 0;color:#6b7280;font-size:13px">Jobs that exhausted all retries. Inspect payload, fix root cause, then retry.</p></div>
<?php if($total>0):?><div style="display:flex;gap:8px"><button class="ops-btn ops-btn--warn" onclick="opsRetryAllDead()">↩ Retry All</button><button class="ops-btn ops-btn--danger" onclick="opsPurge()">🗑 Purge All (<?php echo $total;?>)</button></div><?php endif;?>
</div>
<?php if(!$total):?><div class="ops-alert ops-alert--info">✅ No dead jobs — queue is healthy!</div><?php else:?>
<?php if(!empty($by_type)):?>
<div class="ops-grid ops-grid--<?php echo min(5,count($by_type));?>" style="margin-bottom:16px">
<?php foreach($by_type as $dt):?><div class="ops-kpi"><div class="ops-kpi__icon">⚰️</div><div class="ops-kpi__val" style="color:#dc2626"><?php echo $dt->cnt;?></div><div class="ops-kpi__label"><?php echo esc_html($dt->type);?></div></div><?php endforeach;?>
</div><?php endif;?>
<div class="ops-card" style="padding:0;overflow:hidden">
<table class="ops-table ops-table--full"><thead><tr><th>ID</th><th>Type</th><th>Attempts</th><th>Died At</th><th>Error</th><th>Actions</th></tr></thead><tbody>
<?php foreach($jobs as $j):?>
<tr><td style="font-family:monospace;color:#9ca3af">#<?php echo $j->id;?></td><td><code class="ops-type-badge"><?php echo esc_html($j->type);?></code></td>
<td style="color:#dc2626;font-weight:600"><?php echo (int)$j->attempts;?>/<?php echo (int)$j->max_attempts;?></td>
<td style="font-size:12px;white-space:nowrap"><?php echo esc_html(self::human_time($j->finished_at?:$j->created_at));?></td>
<td style="font-size:12px;color:#dc2626;max-width:260px" title="<?php echo esc_attr($j->error_message??'');?>"><?php echo esc_html(substr($j->error_message??'(none)',0,90));?></td>
<td style="white-space:nowrap"><button class="ops-btn ops-btn--sm" onclick="opsInspect(<?php echo $j->id;?>)">🔍 Inspect</button> <button class="ops-btn ops-btn--sm ops-btn--warn" onclick="opsJobAction('retry',<?php echo $j->id;?>)">↩</button> <button class="ops-btn ops-btn--sm ops-btn--danger" onclick="opsJobAction('delete',<?php echo $j->id;?>)">🗑</button></td></tr>
<?php endforeach;?></tbody></table></div>
<?php if($total>$per):?><div class="ops-pagination"><?php for($i=1;$i<=ceil($total/$per);$i++):?><a href="?page=cias-ops-monitor&ops_tab=deadletter&dlpage=<?php echo $i;?>" class="ops-page-btn <?php echo $pg===$i?'ops-page-btn--active':'';?>"><?php echo $i;?></a><?php endfor;?></div><?php endif;?>
<?php endif;?>
<script>
function opsRetryAllDead(){if(!confirm('Re-queue ALL dead jobs?'))return;fetch(opsAjax,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`action=cias_ops_retry_all_dead&nonce=${opsNonce}`}).then(r=>r.json()).then(d=>{alert(d.data||'Done');if(d.success)location.reload();});}
</script>
<?php
    }

    // ═══════════════════════════════════════════════════════════════════════
    // NEW TAB: Abuse Detection
    // ═══════════════════════════════════════════════════════════════════════
    public static function tab_abuse(): void {
        global $wpdb;
        $ai_t=$wpdb->prefix.'cias_ai_usage_log';
        $WC=50;$HC=150;$WT=50000;$HT=200000;
        $top=$wpdb->get_results("SELECT l.user_id,u.display_name,u.user_email,COUNT(*) AS calls,SUM(l.input_tokens+l.output_tokens) AS tokens,SUM(l.cost_usd) AS cost,COUNT(DISTINCT l.context) AS contexts FROM `{$ai_t}` l LEFT JOIN {$wpdb->users} u ON u.ID=l.user_id WHERE DATE(l.created_at)=CURDATE() AND l.user_id>0 GROUP BY l.user_id ORDER BY calls DESC LIMIT 20");
        $g=$wpdb->get_row("SELECT COUNT(*) AS calls,SUM(input_tokens+output_tokens) AS tokens,SUM(cost_usd) AS cost,COUNT(DISTINCT user_id) AS students FROM `{$ai_t}` WHERE DATE(created_at)=CURDATE()");
        $flagged=count(array_filter((array)$top,fn($u)=>(int)$u->calls>=$WC||(int)$u->tokens>=$WT));
        $avg=$g->students>0?round($g->calls/$g->students,1):0;
        ?>
<div class="ops-alert ops-alert--info" style="margin-bottom:16px">🛡 Thresholds: <strong><?php echo $WC;?> calls</strong> or <strong><?php echo number_format($WT);?> tokens</strong>/day = ⚠️ Watch &nbsp;|&nbsp; <?php echo $HC;?> calls / <?php echo number_format($HT);?> tokens = 🚨 High</div>
<div class="ops-grid ops-grid--4" style="margin-bottom:16px">
<div class="ops-kpi"><div class="ops-kpi__icon">🤖</div><div class="ops-kpi__val"><?php echo number_format($g->calls??0);?></div><div class="ops-kpi__label">AI Calls Today</div></div>
<div class="ops-kpi"><div class="ops-kpi__icon">👥</div><div class="ops-kpi__val"><?php echo number_format($g->students??0);?></div><div class="ops-kpi__label">Active Students</div></div>
<div class="ops-kpi"><div class="ops-kpi__icon">📊</div><div class="ops-kpi__val" style="font-size:20px"><?php echo $avg;?></div><div class="ops-kpi__label">Avg Calls/Student</div></div>
<div class="ops-kpi"><div class="ops-kpi__icon">🚨</div><div class="ops-kpi__val" style="color:<?php echo $flagged>0?'#dc2626':'#16a34a';?>"><?php echo $flagged;?></div><div class="ops-kpi__label">Flagged Users</div></div>
</div>
<div class="ops-card" style="padding:0;overflow:hidden"><div class="ops-card__title" style="padding:14px 18px;border-bottom:1px solid #f3f4f6">Student AI Usage Today (top 20)</div>
<table class="ops-table ops-table--full"><thead><tr><th>Student</th><th>Email</th><th>Calls</th><th>Tokens</th><th>Cost</th><th>Flag</th></tr></thead><tbody>
<?php if(empty($top)):?><tr><td colspan="6" style="text-align:center;color:#9ca3af;padding:20px">No AI usage today.</td></tr><?php else:?>
<?php foreach($top as $u):$fl='ok';if((int)$u->calls>=$HC||(int)$u->tokens>=$HT)$fl='high';elseif((int)$u->calls>=$WC||(int)$u->tokens>=$WT)$fl='warn';?>
<tr style="<?php echo $fl!=='ok'?'background:#fffbeb':'';?>">
<td style="font-weight:<?php echo $fl!=='ok'?'600':'400';?>"><?php echo esc_html($u->display_name?:"User #{$u->user_id}");?></td>
<td style="font-size:12px;color:#6b7280"><?php echo $u->user_email?esc_html($u->user_email):'—';?></td>
<td style="color:<?php echo(int)$u->calls>=$WC?'#dc2626':'#374151';?>;font-weight:<?php echo(int)$u->calls>=$WC?'700':'400';?>"><?php echo number_format($u->calls);?></td>
<td style="color:<?php echo(int)$u->tokens>=$WT?'#dc2626':'#374151';?>;font-weight:<?php echo(int)$u->tokens>=$WT?'700':'400';?>"><?php echo number_format($u->tokens);?></td>
<td>$<?php echo number_format(floatval($u->cost),4);?></td>
<td><?php if($fl==='high'):?><span style="background:#fee2e2;color:#991b1b;padding:3px 8px;border-radius:99px;font-size:11px;font-weight:600">🚨 HIGH</span><?php elseif($fl==='warn'):?><span style="background:#fefce8;color:#854d0e;padding:3px 8px;border-radius:99px;font-size:11px;font-weight:600">⚠️ Watch</span><?php else:?><span style="color:#9ca3af;font-size:12px">Normal</span><?php endif;?></td>
</tr>
<?php endforeach;endif;?></tbody></table></div>
<?php
    }

    // ═══════════════════════════════════════════════════════════════════════
    // NEW TAB: AI Accuracy
    // ═══════════════════════════════════════════════════════════════════════
    public static function tab_accuracy(): void {
        global $wpdb;
        $es=$wpdb->get_row("SELECT COUNT(e.id) AS total,AVG(e.score) AS avg_ai,SUM(CASE WHEN r.override_score IS NOT NULL THEN 1 ELSE 0 END) AS overridden,AVG(CASE WHEN r.override_score IS NOT NULL THEN r.override_score END) AS avg_teacher FROM `".CIAS_AI_EVALUATIONS."` e LEFT JOIN `".CIAS_TEACHER_REVIEWS."` r ON r.eval_id=e.id WHERE e.evaluated_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)");
        $total=(int)($es->total??0);$ov=(int)($es->overridden??0);$ovr=$total>0?round($ov/$total*100,1):0;$acc=100-$ovr;
        $ocrs=$wpdb->get_row("SELECT COUNT(*) AS total,SUM(confirmed) AS conf FROM `".CIAS_OCR_RESULTS."` WHERE created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)");
        $ocr_tot=(int)($ocrs->total??0);$ocr_conf=(int)($ocrs->conf??0);$ocr_rate=$ocr_tot>0?round($ocr_conf/$ocr_tot*100,1):0;
        $diffs=$wpdb->get_results("SELECT e.score AS ai,r.override_score AS teacher,(r.override_score-e.score) AS diff FROM `".CIAS_AI_EVALUATIONS."` e INNER JOIN `".CIAS_TEACHER_REVIEWS."` r ON r.eval_id=e.id WHERE r.override_score IS NOT NULL AND e.evaluated_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) ORDER BY ABS(r.override_score-e.score) DESC LIMIT 100");
        $avg_diff=!empty($diffs)?array_sum(array_column((array)$diffs,'diff'))/count($diffs):0;
        $by_conf=$wpdb->get_results("SELECT CASE WHEN confidence>=0.90 THEN 'High (>=90%)' WHEN confidence>=0.70 THEN 'Medium (70-90%)' ELSE 'Low (<70%)' END AS band,COUNT(*) AS total,SUM(confirmed) AS conf FROM `".CIAS_OCR_RESULTS."` WHERE created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) GROUP BY band ORDER BY MIN(confidence) DESC");
        ?>
<div class="ops-grid ops-grid--4" style="margin-bottom:16px">
<div class="ops-kpi"><div class="ops-kpi__icon">🎯</div><div class="ops-kpi__val" style="color:<?php echo $acc>=90?'#16a34a':($acc>=80?'#f59e0b':'#dc2626');?>"><?php echo $acc;?>%</div><div class="ops-kpi__label">AI Evals Accepted</div></div>
<div class="ops-kpi"><div class="ops-kpi__icon">✏️</div><div class="ops-kpi__val" style="color:<?php echo $ovr>20?'#dc2626':($ovr>10?'#f59e0b':'#16a34a');?>"><?php echo $ovr;?>%</div><div class="ops-kpi__label">Teacher Override Rate</div></div>
<div class="ops-kpi"><div class="ops-kpi__icon">🔍</div><div class="ops-kpi__val" style="color:<?php echo $ocr_rate>=90?'#16a34a':($ocr_rate>=75?'#f59e0b':'#dc2626');?>"><?php echo $ocr_rate;?>%</div><div class="ops-kpi__label">OCR Confirmation Rate</div></div>
<div class="ops-kpi"><div class="ops-kpi__icon">📐</div><div class="ops-kpi__val" style="font-size:22px;color:<?php echo abs($avg_diff)>10?'#dc2626':($avg_diff!=0?'#f59e0b':'#374151');?>"><?php echo($avg_diff>=0?'+':'').round($avg_diff,1);?></div><div class="ops-kpi__label">Avg Score Diff</div></div>
</div>
<?php if(!$total):?><div class="ops-alert ops-alert--info">📊 No AI evaluations in last 30 days. Will populate as students submit answers.</div><?php else:?>
<div class="ops-grid ops-grid--2" style="margin-bottom:16px">
<div class="ops-card"><div class="ops-card__title">Evaluation Accuracy (last 30 days)</div>
<div class="ops-stat-list">
<div class="ops-stat-row"><span>Total AI evaluations</span><strong><?php echo number_format($total);?></strong></div>
<div class="ops-stat-row"><span>Accepted by teachers</span><strong style="color:#16a34a"><?php echo number_format($total-$ov);?></strong></div>
<div class="ops-stat-row"><span>Overridden by teachers</span><strong style="color:#dc2626"><?php echo number_format($ov);?></strong></div>
<div class="ops-stat-row"><span>Avg AI score</span><strong><?php echo round($es->avg_ai??0,1);?>/100</strong></div>
<div class="ops-stat-row"><span>Avg teacher score (on overrides)</span><strong><?php echo round($es->avg_teacher??0,1);?>/100</strong></div>
</div>
<div style="margin-top:16px"><div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px"><span style="color:#16a34a">✅ Accepted: <?php echo $acc;?>%</span><span style="color:#dc2626">✏️ Overridden: <?php echo $ovr;?>%</span></div>
<div style="background:#fee2e2;border-radius:6px;height:16px;overflow:hidden"><div style="width:<?php echo $acc;?>%;background:#16a34a;height:16px;border-radius:6px"></div></div></div>
<?php if($ovr>20):?><div class="ops-alert ops-alert--warn" style="margin-top:12px">⚠️ High override rate (<?php echo $ovr;?>%). Review AI evaluation prompts.</div><?php endif;?>
</div>
<div class="ops-card"><div class="ops-card__title">OCR Accuracy (last 30 days)</div>
<div class="ops-stat-list">
<div class="ops-stat-row"><span>Total OCR runs</span><strong><?php echo number_format($ocr_tot);?></strong></div>
<div class="ops-stat-row"><span>Student-confirmed</span><strong style="color:#16a34a"><?php echo number_format($ocr_conf);?></strong></div>
<div class="ops-stat-row"><span>Unconfirmed / corrected</span><strong style="color:#f59e0b"><?php echo number_format($ocr_tot-$ocr_conf);?></strong></div>
</div>
<?php if(!empty($by_conf)):?>
<div style="margin-top:14px"><div style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;margin-bottom:8px">By Confidence Band</div>
<table class="ops-table" style="font-size:12px"><thead><tr><th>Band</th><th>Total</th><th>Confirmed</th><th>Rate</th></tr></thead><tbody>
<?php foreach($by_conf as $b):$r=$b->total>0?round($b->conf/$b->total*100):0;?>
<tr><td><?php echo esc_html($b->band);?></td><td><?php echo $b->total;?></td><td><?php echo $b->conf;?></td><td style="color:<?php echo $r>=90?'#16a34a':($r>=70?'#f59e0b':'#dc2626');?>;font-weight:600"><?php echo $r;?>%</td></tr>
<?php endforeach;?></tbody></table></div>
<?php endif;?></div></div>
<?php if(!empty($diffs)):?>
<div class="ops-card" style="padding:0;overflow:hidden"><div class="ops-card__title" style="padding:14px 18px;border-bottom:1px solid #f3f4f6">Biggest AI vs Teacher Gaps (last 30d)</div>
<table class="ops-table ops-table--full"><thead><tr><th>AI Score</th><th>Teacher Score</th><th>Difference</th><th>Direction</th></tr></thead><tbody>
<?php foreach(array_slice((array)$diffs,0,10) as $d):?>
<tr><td><?php echo $d->ai;?>/100</td><td><?php echo $d->teacher;?>/100</td><td style="font-weight:700;color:<?php echo abs($d->diff)>20?'#dc2626':($d->diff!=0?'#f59e0b':'#16a34a');?>"><?php echo($d->diff>=0?'+':'').$d->diff;?> pts</td><td style="font-size:12px;color:#6b7280"><?php echo $d->diff>0?'⬆ Teacher scored higher':($d->diff<0?'⬇ AI scored higher':'✅ Agreed');?></td></tr>
<?php endforeach;?></tbody></table></div>
<?php endif;?>
<?php endif;?>
<?php
    }

    // ═══════════════════════════════════════════════════════════════════════
    // NEW TAB: Live Activity
    // ═══════════════════════════════════════════════════════════════════════
    public static function tab_activity(): void {
        global $wpdb;
        $subs=$wpdb->get_results("SELECT s.id,s.user_id,u.display_name,s.status,s.created_at,s.updated_at FROM `".CIAS_SUBMISSIONS."` s LEFT JOIN {$wpdb->users} u ON u.ID=s.user_id ORDER BY s.updated_at DESC LIMIT 30");
        $evals=$wpdb->get_results("SELECT e.id,e.user_id,u.display_name,e.score,e.evaluated_at FROM `".CIAS_AI_EVALUATIONS."` e LEFT JOIN {$wpdb->users} u ON u.ID=e.user_id ORDER BY e.evaluated_at DESC LIMIT 20");
        $ovs=$wpdb->get_results("SELECT r.id,r.user_id,r.teacher_id,u.display_name AS sn,t.display_name AS tn,r.override_score,r.reviewed_at FROM `".CIAS_TEACHER_REVIEWS."` r LEFT JOIN {$wpdb->users} u ON u.ID=r.user_id LEFT JOIN {$wpdb->users} t ON t.ID=r.teacher_id WHERE r.override_score IS NOT NULL ORDER BY r.reviewed_at DESC LIMIT 10");
        ?>
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
<div><h2 style="margin:0;font-size:18px">📡 Live Activity Feed</h2><p style="margin:4px 0 0;color:#6b7280;font-size:13px">Real-time events. Auto-refreshes every 15s.</p></div>
<button class="ops-btn" id="ops-act-refresh" onclick="opsRefreshActivity()">↺ Refresh</button>
</div>
<div id="ops-act-wrap"><?php self::render_events($subs,$evals,$ovs);?></div>
<?php
    }

    private static function render_events(array $subs,array $evals,array $ovs):void {
        $lbl=['queued'=>['🆕','uploaded an answer'],'ocr_processing'=>['🔍','OCR started'],'needs_confirmation'=>['✋','OCR needs confirmation'],'ocr_done'=>['✅','OCR completed'],'evaluating'=>['🤖','AI evaluation started'],'evaluated'=>['🎯','AI evaluation complete'],'ocr_failed'=>['❌','OCR failed'],'eval_failed'=>['❌','Evaluation failed'],'teacher_review'=>['👨‍🏫','sent to teacher review']];
        $ev=[];
        foreach($subs as $s){[$ic,$verb]=$lbl[$s->status]??['📄','status: '.$s->status];$ev[]=['ts'=>strtotime($s->updated_at?:$s->created_at),'icon'=>$ic,'text'=>'<strong>'.esc_html($s->display_name?:"Student #{$s->user_id}").'</strong> '.esc_html($verb),'meta'=>self::human_time($s->updated_at?:$s->created_at)];}
        foreach($evals as $e){$ev[]=['ts'=>strtotime($e->evaluated_at),'icon'=>'🎯','text'=>'<strong>'.esc_html($e->display_name?:"Student #{$e->user_id}").'</strong> got AI score <strong>'.(int)$e->score.'/100</strong>','meta'=>self::human_time($e->evaluated_at)];}
        foreach($ovs as $r){$ev[]=['ts'=>strtotime($r->reviewed_at??'now'),'icon'=>'✏️','text'=>'<strong>'.esc_html($r->tn?:'Teacher').'</strong> override → <strong>'.(int)$r->override_score.'/100</strong>'.($r->sn?' ('.esc_html($r->sn).')':''),'meta'=>self::human_time($r->reviewed_at??'')];}
        usort($ev,fn($a,$b)=>$b['ts']-$a['ts']);$ev=array_slice($ev,0,50);
        if(empty($ev)){echo '<div class="ops-alert ops-alert--info">📭 No recent activity. Platform is idle.</div>';return;}
        echo '<div class="ops-card" style="padding:0"><div style="padding:10px 18px;border-bottom:1px solid #f3f4f6;font-size:12px;color:#9ca3af">'.count($ev).' recent events · auto-refreshes every 15s</div><div style="max-height:600px;overflow-y:auto">';
        foreach($ev as $e){echo '<div style="display:flex;align-items:flex-start;gap:12px;padding:10px 18px;border-bottom:1px solid #f9fafb"><div style="font-size:18px;line-height:1.2;flex-shrink:0;margin-top:1px">'.$e['icon'].'</div><div style="flex:1;font-size:13px;color:#374151;line-height:1.4">'.$e['text'].'</div><div style="font-size:11px;color:#9ca3af;white-space:nowrap;flex-shrink:0">'.esc_html($e['meta']).'</div></div>';}
        echo '</div></div>';
    }

    // ═══════════════════════════════════════════════════════════════════════
    // NEW AJAX: Timeline, Activity, Payload, Retry-All-Dead
    // ═══════════════════════════════════════════════════════════════════════
    public static function ajax_timeline(): void {
        if(!current_user_can('manage_options')||!check_ajax_referer('cias_ops_nonce','nonce',false))wp_send_json_error('Unauthorized',403);
        global $wpdb;
        $rows=$wpdb->get_results("SELECT FLOOR(TIMESTAMPDIFF(MINUTE,DATE_SUB(NOW(),INTERVAL 60 MINUTE),created_at)/5) AS bucket,type,status,COUNT(*) AS cnt FROM `".CIAS_JOB_QUEUE."` WHERE created_at>=DATE_SUB(NOW(),INTERVAL 60 MINUTE) GROUP BY bucket,type,status");
        $buckets=[];for($i=0;$i<12;$i++)$buckets[$i]=['label'=>'-'.(60-$i*5).'m','types'=>[]];
        foreach($rows as $r){$b=(int)$r->bucket;if($b<0||$b>=12)continue;$k=in_array($r->status,['failed','dead'])?'failed':$r->type;$buckets[$b]['types'][$k]=($buckets[$b]['types'][$k]??0)+(int)$r->cnt;}
        wp_send_json_success(['buckets'=>array_values($buckets)]);
    }

    public static function ajax_activity(): void {
        if(!current_user_can('manage_options')||!check_ajax_referer('cias_ops_nonce','nonce',false))wp_send_json_error('Unauthorized',403);
        global $wpdb;
        $subs=$wpdb->get_results("SELECT s.id,s.user_id,u.display_name,s.status,s.created_at,s.updated_at FROM `".CIAS_SUBMISSIONS."` s LEFT JOIN {$wpdb->users} u ON u.ID=s.user_id ORDER BY s.updated_at DESC LIMIT 30");
        $evals=$wpdb->get_results("SELECT e.id,e.user_id,u.display_name,e.score,e.evaluated_at FROM `".CIAS_AI_EVALUATIONS."` e LEFT JOIN {$wpdb->users} u ON u.ID=e.user_id ORDER BY e.evaluated_at DESC LIMIT 20");
        $ovs=$wpdb->get_results("SELECT r.id,r.user_id,r.teacher_id,u.display_name AS sn,t.display_name AS tn,r.override_score,r.reviewed_at FROM `".CIAS_TEACHER_REVIEWS."` r LEFT JOIN {$wpdb->users} u ON u.ID=r.user_id LEFT JOIN {$wpdb->users} t ON t.ID=r.teacher_id WHERE r.override_score IS NOT NULL ORDER BY r.reviewed_at DESC LIMIT 10");
        ob_start();self::render_events($subs,$evals,$ovs);$html=ob_get_clean();
        wp_send_json_success(['html'=>$html]);
    }

    public static function ajax_payload(): void {
        if(!current_user_can('manage_options')||!check_ajax_referer('cias_ops_nonce','nonce',false))wp_send_json_error('Unauthorized',403);
        $id=(int)($_GET['job_id']??0);if(!$id)wp_send_json_error('Invalid job ID');
        global $wpdb;
        $job=$wpdb->get_row($wpdb->prepare("SELECT payload_json,error_message FROM `".CIAS_JOB_QUEUE."` WHERE id=%d",$id));
        if(!$job)wp_send_json_error('Job not found');
        $pretty=json_encode(json_decode($job->payload_json,true),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
        wp_send_json_success(['payload'=>$pretty?:$job->payload_json,'error'=>$job->error_message]);
    }

    // Helper: duration formatter
    private static function fmt_dur(float $s):string {
        if($s<1)return '<1s';if($s<60)return round($s).'s';
        if($s<3600)return round($s/60,1).'m';return round($s/3600,1).'h';
    }


        private static function build_alerts( int $dead, int $stale, bool $r2_ok, ?bool $redis_ok, int $pending ): array {
        $alerts = [];
        if ( $dead >= 5 )   $alerts[] = [ 'level' => 'error', 'icon' => '💀', 'message' => "{$dead} jobs have exhausted all retries and are dead. Check AI Logs for error details." ];
        if ( $dead > 0 && $dead < 5 ) $alerts[] = [ 'level' => 'warn', 'icon' => '⚠️', 'message' => "{$dead} dead job(s) — click Job Queue tab to inspect." ];
        if ( $stale > 0 )   $alerts[] = [ 'level' => 'warn', 'icon' => '⏱',  'message' => "{$stale} job(s) have been stuck in 'processing' for >8 minutes. Workers may have crashed." ];
        if ( ! $r2_ok )     $alerts[] = [ 'level' => 'error', 'icon' => '❌', 'message' => 'Cloudflare R2 is not configured. File uploads will fail.' ];
        if ( $redis_ok === false ) $alerts[] = [ 'level' => 'warn', 'icon' => '🔴', 'message' => 'Upstash Redis ping failed. Workers will fall back to MySQL polling (slower).' ];
        if ( $pending > 50 ) $alerts[] = [ 'level' => 'warn', 'icon' => '⏳', 'message' => "{$pending} jobs pending — queue is backing up. Are workers running?" ];
        return $alerts;
    }

    private static function health_row( string $label, string $status, string $detail ): void {
        $color = match($status) { 'ok' => '#16a34a', 'warn' => '#d97706', default => '#dc2626' };
        $icon  = match($status) { 'ok' => '✅', 'warn' => '⚠️', default => '❌' };
        echo "<div class='ops-health-row'>"
           . "<span class='ops-health-icon' style='color:{$color}'>{$icon}</span>"
           . "<div><strong style='font-size:13px'>" . esc_html($label) . "</strong>"
           . "<div style='font-size:12px;color:#6b7280'>" . esc_html($detail) . "</div></div>"
           . "</div>";
    }

    private static function status_badge( string $status, string $label = '' ): string {
        $label = $label ?: $status;
        $styles = [
            'pending'    => 'background:#fef3c7;color:#92400e',
            'processing' => 'background:#dbeafe;color:#1e40af',
            'done'       => 'background:#dcfce7;color:#166534',
            'failed'     => 'background:#fed7aa;color:#9a3412',
            'dead'       => 'background:#fee2e2;color:#991b1b',
        ];
        $style = $styles[$status] ?? 'background:#f3f4f6;color:#374151';
        return "<span style='display:inline-block;{$style};padding:2px 8px;border-radius:99px;font-size:11px;font-weight:500'>"
             . esc_html($label) . "</span>";
    }

    private static function human_time( ?string $mysql_time ): string {
        if ( ! $mysql_time ) return '—';
        $ts   = strtotime( $mysql_time );
        $diff = time() - $ts;
        if ( $diff < 60 )    return 'Just now';
        if ( $diff < 3600 )  return round($diff/60) . 'm ago';
        if ( $diff < 86400 ) return round($diff/3600) . 'h ago';
        return date('d M, H:i', $ts);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Inline CSS
    // ──────────────────────────────────────────────────────────────────────────

    private static function inline_css(): string {
        return <<<CSS
/* ── CIAS Ops Monitor ─────────────────────────────────────────── */
#cias-ops-wrap { max-width:1280px }

.ops-tabs {
    display:flex; gap:0; margin:16px 0 0; border-bottom:2px solid #e5e7eb;
    flex-wrap:wrap;
}
.ops-tab {
    padding:10px 18px; font-size:13px; font-weight:500; color:#6b7280;
    text-decoration:none; border-bottom:2px solid transparent; margin-bottom:-2px;
    transition:color .15s,border-color .15s;
}
.ops-tab:hover { color:#111827 }
.ops-tab--active { color:#2563eb; border-color:#2563eb }

.ops-panel { padding:20px 0 }

.ops-grid { display:grid; gap:16px }
.ops-grid--2 { grid-template-columns:1fr 1fr }
.ops-grid--4 { grid-template-columns:repeat(4,1fr) }
.ops-grid--5 { grid-template-columns:repeat(5,1fr) }

@media(max-width:1100px) {
    .ops-grid--4 { grid-template-columns:repeat(2,1fr) }
    .ops-grid--5 { grid-template-columns:repeat(3,1fr) }
}
@media(max-width:900px) {
    .ops-grid--2 { grid-template-columns:1fr }
    .ops-grid--3 { grid-template-columns:1fr }
    .ops-grid--4,.ops-grid--5 { grid-template-columns:repeat(2,1fr) }
}

.ops-kpi {
    background:#fff; border:1px solid #e5e7eb; border-radius:12px;
    padding:18px 12px; text-align:center;
    transition:box-shadow .15s;
}
.ops-kpi:hover { box-shadow:0 2px 12px rgba(0,0,0,.07) }
.ops-kpi__icon { font-size:22px; margin-bottom:6px }
.ops-kpi__val  { font-size:28px; font-weight:700; line-height:1.1; margin-bottom:4px }
.ops-kpi__label{ font-size:12px; color:#6b7280 }

.ops-card {
    background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px 18px;
}
.ops-card__title {
    font-size:13px; font-weight:600; color:#374151; margin-bottom:12px;
    text-transform:uppercase; letter-spacing:.04em;
}

.ops-table { width:100%; border-collapse:collapse; font-size:13px }
.ops-table th {
    text-align:left; font-size:11px; font-weight:600; color:#9ca3af;
    text-transform:uppercase; letter-spacing:.05em; padding:8px 12px;
    border-bottom:1px solid #f3f4f6; background:#fafafa;
}
.ops-table td { padding:8px 12px; border-bottom:1px solid #f9fafb; vertical-align:middle }
.ops-table tbody tr:hover { background:#fafafa }
.ops-table--full { border-radius:0 }

.ops-type-badge {
    background:#f3f4f6; color:#374151; font-size:11px;
    padding:2px 7px; border-radius:4px; font-family:monospace;
}

.ops-health-list { display:flex; flex-direction:column; gap:10px }
.ops-health-row { display:flex; align-items:flex-start; gap:10px }
.ops-health-icon { font-size:16px; line-height:1.4; flex-shrink:0 }

.ops-stat-list { display:flex; flex-direction:column; gap:8px }
.ops-stat-row {
    display:flex; justify-content:space-between; align-items:center;
    font-size:13px; padding:4px 0; border-bottom:1px solid #f9fafb;
}

.ops-filters { margin-bottom:14px }
.ops-select {
    padding:6px 10px; border:1px solid #d1d5db; border-radius:8px;
    font-size:13px; background:#fff; height:34px;
}

.ops-btn {
    display:inline-block; padding:6px 14px; font-size:12px; font-weight:500;
    border:1px solid #d1d5db; border-radius:8px; background:#fff; cursor:pointer;
    transition:all .15s; text-decoration:none; color:#374151;
}
.ops-btn:hover { background:#f3f4f6; color:#111 }
.ops-btn--ghost { background:transparent }
.ops-btn--warn  { background:#fef3c7; border-color:#fcd34d; color:#92400e }
.ops-btn--warn:hover { background:#fde68a }
.ops-btn--danger{ background:#fee2e2; border-color:#fca5a5; color:#991b1b }
.ops-btn--danger:hover { background:#fecaca }
.ops-btn--sm    { padding:3px 9px; font-size:11px }

.ops-alert {
    padding:10px 14px; border-radius:8px; font-size:13px; margin-bottom:8px;
    display:flex; align-items:center; gap:8px;
}
.ops-alert--error { background:#fee2e2; color:#991b1b; border:1px solid #fecaca }
.ops-alert--warn  { background:#fefce8; color:#854d0e; border:1px solid #fde68a }
.ops-alert--info  { background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe }

.ops-pagination { display:flex; gap:4px; margin-top:14px; flex-wrap:wrap }
.ops-page-btn {
    display:inline-flex; align-items:center; justify-content:center;
    width:32px; height:32px; border-radius:6px; font-size:13px;
    border:1px solid #e5e7eb; text-decoration:none; color:#374151;
    background:#fff; transition:all .15s;
}
.ops-page-btn:hover { background:#f3f4f6 }
.ops-page-btn--active { background:#2563eb; color:#fff; border-color:#2563eb }

.ops-link { color:#2563eb; text-decoration:none; font-size:13px }
.ops-link:hover { text-decoration:underline }

.ops-sparkbar-wrap {
    display:flex; align-items:flex-end; gap:6px; height:100px; padding:0 4px;
}
.ops-sparkbar-item {
    flex:1; display:flex; flex-direction:column; align-items:center;
    justify-content:flex-end; gap:2px; cursor:default;
}
.ops-sparkbar-bar {
    width:100%; background:#3b82f6; border-radius:3px 3px 0 0;
    transition:height .3s; min-height:4px;
}
.ops-sparkbar-label { font-size:9px; color:#9ca3af; text-align:center }
.ops-sparkbar-val   { font-size:10px; color:#6b7280 }
/* ── end CIAS Ops Monitor ─────────────────────────────────────── */
CSS;
    }
}
