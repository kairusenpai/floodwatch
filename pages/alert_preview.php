<?php
session_start();
require_once '../includes/config.php';
requireLogin();

$pageTitle  = 'Alert Level Preview';
$activePage = 'alert_preview';

// ── Fetch LIVE sensor readings from DB ───────────────────────
$sensorReadings = [];
$res = $conn->query("
    SELECT s.id as sensor_id, s.sensor_code, p.name as purok, p.id as purok_id,
           sr.water_level, sr.alert_status, sr.recorded_at
    FROM sensors s
    JOIN puroks p ON s.purok_id = p.id
    LEFT JOIN (
        SELECT id, sensor_id, water_level, alert_status, recorded_at
        FROM sensor_readings
        WHERE (sensor_id, recorded_at) IN (
            SELECT sensor_id, MAX(recorded_at)
            FROM sensor_readings
            GROUP BY sensor_id
        )
    ) sr ON sr.sensor_id = s.id
    ORDER BY s.id
");
while ($row = $res->fetch_assoc()) $sensorReadings[] = $row;

// ── Fetch active flood alerts ────────────────────────────────
$activeAlerts = [];
$ares = $conn->query("
    SELECT fa.*, p.name as purok, s.sensor_code
    FROM flood_alerts fa
    JOIN puroks p ON fa.purok_id = p.id
    JOIN sensors s ON fa.sensor_id = s.id
    WHERE fa.is_resolved = 0
    ORDER BY fa.triggered_at DESC
");
while ($row = $ares->fetch_assoc()) $activeAlerts[] = $row;

// ── Determine highest current alert level ────────────────────
$alertColors = ['safe'=>'#00e676','warning'=>'#ffaa00','danger'=>'#ff6600','critical'=>'#ff0033'];
$levelRank   = ['safe'=>0,'warning'=>1,'danger'=>2,'critical'=>3];
$highestLevel = 'safe';
foreach ($sensorReadings as $sr) {
    $st = $sr['alert_status'] ?? 'safe';
    if (($levelRank[$st] ?? 0) > ($levelRank[$highestLevel] ?? 0)) $highestLevel = $st;
}

// Active tab: from URL param, or from highest level, default to warning
$activeTab = $_GET['level'] ?? ($highestLevel !== 'safe' ? $highestLevel : 'warning');
if (!in_array($activeTab, ['warning','danger','critical'])) $activeTab = 'warning';

// ── Stats ────────────────────────────────────────────────────
$totalAlerts   = count($activeAlerts);
$affectedPuroks = count(array_unique(array_column($activeAlerts, 'purok_id')));
$hasData        = !empty(array_filter($sensorReadings, fn($s) => $s['water_level'] > 0));

// Build sensor card helper
function buildSensorCards($sensors, $alertColors, $highlight = null) {
    $html = '';
    foreach ($sensors as $s) {
        $level  = (float)($s['water_level'] ?? 0);
        $status = $s['alert_status'] ?? 'safe';
        if ($highlight && $status === 'safe' && $level == 0) {
            // No data for this sensor in this tab's context
        }
        $color  = $alertColors[$status] ?? '#00e676';
        $pct    = min(100, ($level / 160) * 100);
        $isCrit = $status === 'critical';
        $time   = $s['recorded_at'] ? date('M d, H:i', strtotime($s['recorded_at'])) : 'No data';

        $html .= "<div class='sensor-card-prev is-{$status}'>
            <div class='sc-code'>{$s['sensor_code']}</div>
            <div class='sc-purok'>{$s['purok']}</div>
            <div class='sc-bar-wrap'>
                <div class='sc-bar' style='width:{$pct}%;background:{$color};box-shadow:0 0 8px {$color}44'></div>
            </div>
            <div class='sc-reading'>
                <span class='sc-level'>" . ($level > 0 ? round($level).' cm' : '— cm') . "</span>
                <span class='sc-tag' style='background:{$color}22;color:{$color};'>" . ucfirst($status) . "</span>
            </div>
            <div class='sc-time'>Last: {$time}</div>";
        if ($isCrit) {
            $html .= "<div class='wave-wrap'><svg class='wave' viewBox='0 0 200 4' preserveAspectRatio='none'><path d='M0,2 C25,0 75,4 100,2 S175,0 200,2' fill='none' stroke='#ff0033' stroke-width='2'/></svg></div>";
        }
        $html .= "</div>";
    }
    return $html;
}

include '../includes/header.php';
?>

<style>
@keyframes critFlash  {0%,100%{background:rgba(255,0,51,.06)}50%{background:rgba(255,0,51,.18)}}
@keyframes dangerPulse{0%,100%{background:rgba(255,102,0,.06)}50%{background:rgba(255,102,0,.14)}}
@keyframes warnBlink  {0%,100%{opacity:1}50%{opacity:.55}}
@keyframes waveRise   {0%{transform:translateX(0)}100%{transform:translateX(-50%)}}
@keyframes ripple     {0%{transform:scale(.8);opacity:1}100%{transform:scale(2.2);opacity:0}}
@keyframes fadeInUp   {from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}

.level-tabs{display:flex;gap:0;border-bottom:1px solid var(--border);margin-bottom:24px;}
.level-tab{padding:12px 28px;font-family:var(--font-mono);font-size:.72rem;letter-spacing:2px;text-transform:uppercase;cursor:pointer;border:none;background:none;border-bottom:3px solid transparent;margin-bottom:-1px;transition:all .25s;display:flex;align-items:center;gap:8px;}
.level-tab:hover{color:#fff;}
.level-tab.tab-warn{color:#ffaa00;}.level-tab.tab-danger{color:#ff6600;}.level-tab.tab-crit{color:#ff0033;}
.level-tab.tab-warn.active{border-bottom-color:#ffaa00;background:rgba(255,170,0,.06);}
.level-tab.tab-danger.active{border-bottom-color:#ff6600;background:rgba(255,102,0,.06);}
.level-tab.tab-crit.active{border-bottom-color:#ff0033;background:rgba(255,0,51,.06);animation:critFlash 1.5s infinite;}

.alert-banner{padding:14px 22px;margin-bottom:20px;display:flex;align-items:center;gap:14px;border:1px solid;font-size:.8rem;letter-spacing:.5px;position:relative;overflow:hidden;}
.banner-warn  {border-color:#ffaa00;background:rgba(255,170,0,.08);color:#ffaa00;animation:warnBlink 2s infinite;}
.banner-danger{border-color:#ff6600;background:rgba(255,102,0,.1);color:#ff6600;animation:warnBlink 1.2s infinite;}
.banner-crit  {border-color:#ff0033;background:rgba(255,0,51,.12);color:#ff0033;animation:warnBlink .7s infinite;}
.banner-icon{font-size:1.4rem;flex-shrink:0;}.banner-text{flex:1;font-weight:700;}.banner-level{font-size:.6rem;letter-spacing:3px;opacity:.75;}

.sensors-preview{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin-bottom:20px;}
.sensor-card-prev{background:var(--panel2);border:1px solid var(--border);border-left:3px solid;padding:16px;position:relative;overflow:hidden;animation:fadeInUp .4s ease both;}
.sensor-card-prev.is-safe   {border-left-color:#00e676;}
.sensor-card-prev.is-warning{border-left-color:#ffaa00;}
.sensor-card-prev.is-danger {border-left-color:#ff6600;animation:dangerPulse 2s infinite;}
.sensor-card-prev.is-critical{border-left-color:#ff0033;animation:critFlash 1s infinite;}
.sc-code{font-size:.62rem;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);margin-bottom:4px;}
.sc-purok{font-family:var(--font-head);font-size:.95rem;font-weight:600;color:#fff;margin-bottom:8px;}
.sc-bar-wrap{background:rgba(0,0,0,.4);height:14px;margin-bottom:8px;overflow:hidden;}
.sc-bar{height:100%;transition:width 1s ease;position:relative;}
.sc-bar::after{content:'';position:absolute;right:0;top:0;bottom:0;width:3px;background:rgba(255,255,255,.5);box-shadow:0 0 6px rgba(255,255,255,.8);}
.sc-reading{display:flex;justify-content:space-between;align-items:center;font-size:.72rem;}
.sc-level{color:#fff;font-weight:700;font-size:.85rem;}
.sc-tag{font-size:.58rem;letter-spacing:1px;padding:3px 8px;text-transform:uppercase;font-weight:700;}
.sc-time{font-size:.58rem;color:var(--muted);margin-top:6px;}
.wave-wrap{position:absolute;bottom:0;left:0;right:0;height:4px;overflow:hidden;}
.wave{width:200%;height:100%;animation:waveRise 1.5s linear infinite;}

.stats-preview{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px;}
.stat-prev{background:var(--panel);border:1px solid var(--border);padding:16px 18px;}
.sp-label{font-size:.62rem;letter-spacing:2px;text-transform:uppercase;color:#fff;margin-bottom:6px;}
.sp-val{font-family:var(--font-head);font-size:1.8rem;font-weight:800;color:#fff;line-height:1;}
.sp-unit{font-size:.68rem;color:#fff;margin-top:3px;}
.sp-sub{font-size:.65rem;margin-top:6px;}

.section-label{font-size:.58rem;letter-spacing:3px;text-transform:uppercase;color:var(--muted);margin-bottom:10px;display:flex;align-items:center;gap:8px;}
.section-label::after{content:'';flex:1;height:1px;background:var(--border);}

.info-box{border:1px solid;padding:14px 18px;font-size:.7rem;line-height:1.8;margin-bottom:20px;display:flex;gap:14px;}
.info-box-warn  {border-color:#ffaa00;background:rgba(255,170,0,.05);color:#ffaa00;}
.info-box-danger{border-color:#ff6600;background:rgba(255,102,0,.05);color:#ff6600;}
.info-box-crit  {border-color:#ff0033;background:rgba(255,0,51,.07);color:#ff0033;}
.info-icon{font-size:1.8rem;flex-shrink:0;}
.info-content strong{display:block;font-size:.8rem;margin-bottom:4px;}
.info-content span{color:var(--text);}

.preview-view{display:none;animation:fadeInUp .35s ease both;}
.preview-view.active{display:block;}

/* Live indicator */
.live-source{display:inline-flex;align-items:center;gap:6px;font-size:.6rem;letter-spacing:1.5px;text-transform:uppercase;padding:3px 10px;border:1px solid;margin-bottom:16px;}
.live-source.is-live{border-color:var(--safe);color:var(--safe);background:rgba(0,230,118,.06);}
.live-source.is-demo{border-color:var(--muted);color:var(--muted);}
.live-dot-sm{width:6px;height:6px;border-radius:50%;background:currentColor;animation:warnBlink 1.5s infinite;}

/* Refresh bar */
#refresh-bar{position:fixed;bottom:0;left:220px;right:0;height:3px;background:var(--border);z-index:999;}
#refresh-fill{height:100%;background:var(--cyan);transition:width 1s linear;width:100%;}
</style>

<!-- HEADER -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
  <div>
    <div style="font-family:var(--font-head);font-size:1.2rem;font-weight:800;color:#fff;margin-bottom:4px;">Alert Level Preview</div>
    <div style="font-size:.68rem;color:var(--muted);">Connected to Sensor Monitor — displays real-time ESP32 readings.</div>
  </div>
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
    <?php if ($hasData): ?>
    <span class="live-source is-live"><span class="live-dot-sm"></span> LIVE — <?=$totalAlerts?> active alert<?=($totalAlerts!==1)?'s':''?> · <?=strtoupper($highestLevel)?></span>
    <?php else: ?>
    <span class="live-source is-demo">NO ACTIVE ALERTS</span>
    <?php endif; ?>
    <span id="refresh-timer" style="font-size:.6rem;color:var(--muted);">Refreshing in 15s</span>
  </div>
</div>

<?php if (!$hasData): ?>
<div style="background:rgba(255,170,0,.05);border:1px solid rgba(255,170,0,.2);padding:12px 18px;margin-bottom:16px;font-size:.7rem;color:#ffaa00;">
  <span>⚡ No sensor data yet. Wait for ESP32 devices to send readings.</span>
</div>
<?php endif; ?>

<!-- TABS -->
<div class="level-tabs">
  <button class="level-tab tab-warn <?=($activeTab==='warning'?'active':'')?>" onclick="showLevel('warning')">🟡 Warning Level</button>
  <button class="level-tab tab-danger <?=($activeTab==='danger'?'active':'')?>" onclick="showLevel('danger')">🟠 Danger Level</button>
  <button class="level-tab tab-crit <?=($activeTab==='critical'?'active':'')?>" onclick="showLevel('critical')">🔴 Critical Level</button>
</div>

<?php
// Build per-level sensor cards using LIVE data
// For each tab we show all sensors — highlighting those at or above that level
$levelData = [
    'warning'  => ['color'=>'#ffaa00','icon'=>'⚠️','bannerClass'=>'banner-warn', 'min'=>70, 'max'=>100],
    'danger'   => ['color'=>'#ff6600','icon'=>'🚨','bannerClass'=>'banner-danger','min'=>100,'max'=>130],
    'critical' => ['color'=>'#ff0033','icon'=>'🆘','bannerClass'=>'banner-crit', 'min'=>130,'max'=>999],
];
$infoTexts = [
    'warning'  => ['title'=>'WARNING LEVEL — 70 to 100 cm','desc'=>'Water levels are elevated and rising. Operators are alerted to monitor closely. No evacuation yet but residents near flood-prone areas should prepare.'],
    'danger'   => ['title'=>'DANGER LEVEL — 100 to 130 cm','desc'=>'High flood risk detected. Operators must immediately notify barangay officials. Residents in low-lying areas should begin preparing for evacuation.'],
    'critical' => ['title'=>'CRITICAL LEVEL — Above 130 cm','desc'=>'Extreme flood levels reached. Full system alert activated. All households must EVACUATE IMMEDIATELY. Contact DRRM without delay.'],
];

foreach (['warning','danger','critical'] as $tabLevel):
    $ld   = $levelData[$tabLevel];
    $info = $infoTexts[$tabLevel];
    $isActive = ($activeTab === $tabLevel) ? 'active' : '';

    // Count active alerts at this level
    $tabAlerts = array_filter($activeAlerts, fn($a) => $a['alert_level'] === $tabLevel);
    $affectedCount = count($tabAlerts);

    // Find highest level sensor for banner text
    $highestSensor = null;
    foreach ($sensorReadings as $sr) {
        if (($levelRank[$sr['alert_status']??'safe']??0) >= ($levelRank[$tabLevel]??0)) {
            if (!$highestSensor || ($sr['water_level']??0) > ($highestSensor['water_level']??0)) {
                $highestSensor = $sr;
            }
        }
    }

    $bannerText = match($tabLevel) {
        'warning'  => $highestSensor ? "ELEVATED WATER LEVELS — {$highestSensor['purok']} at " . round($highestSensor['water_level']) . "cm. Monitor closely." : "ELEVATED WATER LEVELS — Monitor closely.",
        'danger'   => $highestSensor ? "DANGER: HIGH FLOOD RISK — {$highestSensor['purok']} at " . round($highestSensor['water_level']) . "cm. Prepare for evacuation!" : "DANGER: HIGH FLOOD RISK — Prepare for evacuation.",
        'critical' => $highestSensor ? "CRITICAL FLOOD ALERT — {$highestSensor['purok']} EXCEEDS SAFE LIMITS at " . round($highestSensor['water_level']) . "cm! EVACUATE IMMEDIATELY!" : "CRITICAL FLOOD ALERT — EVACUATE IMMEDIATELY!",
    };

    // Stats for this tab
    $warnCount = count(array_filter($sensorReadings, fn($s) => in_array($s['alert_status']??'safe', ['warning','danger','critical'])));
?>

<div class="preview-view <?=$isActive?>" id="view-<?=$tabLevel?>">

  <!-- INFO BOX -->
  <div class="info-box info-box-<?=$tabLevel==='warning'?'warn':$tabLevel?>">
    <div class="info-icon"><?=$ld['icon']?></div>
    <div class="info-content">
      <strong><?=$info['title']?></strong>
      <span><?=$info['desc']?></span>
    </div>
  </div>

  <!-- ALERT BANNER -->
  <div class="alert-banner <?=$ld['bannerClass']?>">
    <span class="banner-icon"><?=$ld['icon']?></span>
    <span class="banner-text"><?=$bannerText?></span>
    <span class="banner-level"><?=strtoupper($tabLevel)?></span>
  </div>

  <!-- STATS -->
  <div class="section-label">Dashboard Stats</div>
  <div class="stats-preview">
    <div class="stat-prev">
      <div class="sp-label">Active Sensors</div>
      <div class="sp-val"><?=$onlineSensors=($conn->query("SELECT COUNT(*) c FROM sensors WHERE status='online'")->fetch_assoc()['c'])?></div>
      <div class="sp-unit">of 4 online</div>
      <div class="sp-sub" style="color:var(--safe)">▲ All operational</div>
    </div>
    <div class="stat-prev">
      <div class="sp-label">Active Alerts</div>
      <div class="sp-val" style="color:<?=$ld['color']?>"><?=$totalAlerts?></div>
      <div class="sp-unit">unresolved flood alerts</div>
      <div class="sp-sub" style="color:<?=$ld['color']?>"><?=$totalAlerts>0?"⚠ $totalAlerts alert(s) active":'No alerts'?></div>
    </div>
    <div class="stat-prev">
      <div class="sp-label">Affected Puroks</div>
      <div class="sp-val" style="color:#ffaa00;"><?=$affectedPuroks?></div>
      <div class="sp-unit">puroks with alerts</div>
      <div class="sp-sub" style="color:var(--muted);">Brgy. Baliwagan</div>
    </div>
    <div class="stat-prev">
      <div class="sp-label">Total Households</div>
      <div class="sp-val"><?=$conn->query("SELECT COUNT(*) c FROM households")->fetch_assoc()['c']?></div>
      <div class="sp-unit">registered households</div>
      <div class="sp-sub" style="color:<?=($totalAlerts>0)?'#ff0033':'var(--muted)'?>;"><?=($totalAlerts>0)?'⚠ At risk':'All safe'?></div>
    </div>
  </div>

  <!-- SENSOR CARDS -->
  <div class="section-label">Sensor Network — Live Readings</div>
  <div class="sensors-preview">
    <?= buildSensorCards($sensorReadings, $alertColors, $tabLevel) ?>
  </div>

  <!-- FLOOD ALERTS TABLE -->
  <div class="section-label">Active Flood Alerts</div>
  <div class="card">
    <div class="table-wrap"><table>
      <thead><tr><th>Sensor</th><th>Purok</th><th>Water Level</th><th>Alert</th><th>Triggered</th><th>Status</th></tr></thead>
      <tbody>
      <?php if (!empty($activeAlerts)): foreach($activeAlerts as $a):
        $ac = $alertColors[$a['alert_level']] ?? '#00e676';
      ?>
      <tr>
        <td style="color:var(--cyan)"><?=htmlspecialchars($a['sensor_code'])?></td>
        <td><?=htmlspecialchars($a['purok'])?></td>
        <td style="font-weight:700;color:#fff;"><?=$a['water_level']?> cm</td>
        <td><span style="font-size:.6rem;padding:2px 8px;font-weight:700;background:<?=$ac?>22;color:<?=$ac?>;"><?=ucfirst($a['alert_level'])?></span></td>
        <td style="color:var(--muted);font-size:.65rem;"><?=date('M d, H:i',strtotime($a['triggered_at']))?></td>
        <td><span style="font-size:.6rem;padding:2px 8px;font-weight:700;background:rgba(255,51,85,.12);color:var(--danger);">Active</span></td>
      </tr>
      <?php endforeach; else: ?>
      <tr><td colspan="6" style="text-align:center;padding:20px;color:var(--muted);font-size:.7rem;">
        No active alerts.
      </td></tr>
      <?php endif; ?>
      </tbody>
    </table></div>
  </div>

</div>
<?php endforeach; ?>

<!-- Refresh bar -->
<div id="refresh-bar"><div id="refresh-fill"></div></div>

<script>
function showLevel(level) {
  document.querySelectorAll('.preview-view').forEach(v => v.classList.remove('active'));
  document.querySelectorAll('.level-tab').forEach(t => t.classList.remove('active'));
  document.getElementById('view-' + level).classList.add('active');
  const cls = level === 'warning' ? 'warn' : level === 'critical' ? 'crit' : 'danger';
  document.querySelector('.tab-' + cls).classList.add('active');
  // Update URL without reload
  history.replaceState(null,'','?level='+level);
}

// Auto-refresh every 15 seconds
let countdown = 15;
const timerEl = document.getElementById('refresh-timer');
const fillEl  = document.getElementById('refresh-fill');

setInterval(() => {
  countdown--;
  if (timerEl) timerEl.textContent = `Refreshing in ${countdown}s`;
  if (fillEl)  fillEl.style.width = ((countdown / 15) * 100) + '%';
  if (countdown <= 0) window.location.reload();
}, 1000);
</script>

<?php include '../includes/footer.php'; ?>
