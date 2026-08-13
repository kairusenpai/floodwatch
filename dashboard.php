<?php
session_start();
require_once __DIR__ . '/includes/config.php';
requireLogin();

$pageTitle  = 'Dashboard';
$activePage = 'dashboard';

// ── Stats ─────────────────────────────────────────────────────
$totalSensors    = $conn->query("SELECT COUNT(*) c FROM sensors")->fetch_assoc()['c'];
$onlineSensors   = $conn->query("SELECT COUNT(*) c FROM sensors WHERE status='online'")->fetch_assoc()['c'];
$activeAlerts    = $conn->query("SELECT COUNT(*) c FROM flood_alerts WHERE is_resolved=0")->fetch_assoc()['c'];
$totalHouseholds = $conn->query("SELECT COUNT(*) c FROM households")->fetch_assoc()['c'];

$latestReadings = $conn->query("
    SELECT s.id as sensor_id, s.sensor_code, p.name as purok,
           sr.water_level, sr.alert_status, sr.recorded_at
    FROM sensors s
    JOIN puroks p ON s.purok_id = p.id
    LEFT JOIN (
        SELECT sensor_id, water_level, alert_status, recorded_at,
               ROW_NUMBER() OVER (PARTITION BY sensor_id ORDER BY recorded_at DESC) as rn
        FROM sensor_readings
    ) sr ON sr.sensor_id = s.id AND sr.rn = 1
    ORDER BY s.id");

$recentAlerts = $conn->query("
    SELECT fa.*, p.name as purok, s.sensor_code FROM flood_alerts fa
    JOIN puroks p ON fa.purok_id=p.id JOIN sensors s ON fa.sensor_id=s.id
    WHERE DATE(fa.triggered_at) = CURDATE()
    ORDER BY fa.triggered_at DESC LIMIT 5");

$recentIncidents = $conn->query("
    SELECT il.*, p.name as purok, CONCAT(u.first_name,' ',u.last_name) as logged_by_name
    FROM incident_logs il JOIN puroks p ON il.purok_id=p.id JOIN users u ON il.logged_by=u.id
    WHERE DATE(il.created_at) = CURDATE()
    ORDER BY il.created_at DESC LIMIT 5");

include __DIR__ . '/includes/header.php';
?>

<!-- STATS -->
<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-label">Active Sensors</div>
    <div class="stat-value"><?=$onlineSensors?></div>
    <div class="stat-unit">of <?=$totalSensors?> online</div>
    <div class="stat-sub" style="color:var(--safe)">▲ All operational</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Active Alerts</div>
    <div class="stat-value" style="color:<?=$activeAlerts>0?'#ff0033':'var(--safe)'?>"><?=$activeAlerts?></div>
    <div class="stat-unit">unresolved flood alerts</div>
    <div class="stat-sub" style="color:<?=$activeAlerts>0?'#ff6600':'var(--safe)'?>">
      <?=$activeAlerts>0?"⚠ $activeAlerts alert(s) active":'✓ All clear'?>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Total Households</div>
    <div class="stat-value"><?=$totalHouseholds?></div>
    <div class="stat-unit">registered households</div>
    <div class="stat-sub">Across 4 Puroks</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Coverage</div>
    <div class="stat-value">4</div>
    <div class="stat-unit">puroks monitored</div>
    <div class="stat-sub" style="color:var(--cyan)">Brgy. Baliwagan</div>
  </div>
</div>

<!-- SENSOR NETWORK + MAP -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">
  <div class="card">
    <div class="card-title">
      <span>📡 Sensor Network — Live Readings</span>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
    <?php
    $ac=['safe'=>'#00ff00','warning'=>'#ffff00','danger'=>'#ff8c00','critical'=>'#ff0000'];
    if($latestReadings&&$latestReadings->num_rows>0):
      while($r=$latestReadings->fetch_assoc()):
        $lv=$r['water_level']??0;
        $st=$r['alert_status']??'safe';
        $pct=min(100,($lv/160)*100);
        $col=$ac[$st]??'#00ff00';
    ?>
    <div style="background:var(--panel2);border:1px solid var(--border);border-left:3px solid <?=$col?>;padding:14px;">
      <div style="font-size:.62rem;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);margin-bottom:4px;"><?=htmlspecialchars($r['sensor_code'])?></div>
      <div style="font-family:var(--font-head);font-size:.9rem;font-weight:600;color:#fff;margin-bottom:8px;"><?=htmlspecialchars($r['purok'])?></div>
      <div style="background:rgba(0,0,0,.4);height:12px;margin-bottom:8px;overflow:hidden;">
        <div style="width:<?=$pct?>%;height:100%;background:<?=$col?>;box-shadow:0 0 8px <?=$col?>44;transition:width 1s;"></div>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;font-size:.7rem;">
        <span style="color:#fff;font-weight:700;"><?=$lv?round($lv).' cm':'No data'?></span>
        <span style="font-size:.6rem;padding:2px 6px;text-transform:uppercase;background:<?=$col?>22;color:<?=$col?>;"><?=ucfirst($st)?></span>
      </div>
      <?php if($r['recorded_at']): ?>
      <div style="font-size:.58rem;color:var(--cyan);margin-top:6px;">Device sent: <?=date('M d, Y H:i:s',strtotime($r['recorded_at']))?></div>
      <?php endif; ?>
    </div>
    <?php endwhile; else: ?>
    <div style="grid-column:1/-1;text-align:center;padding:30px;color:var(--muted);font-size:.7rem;">
      No sensor readings yet.<br><small>Run a simulation or wait for sensors to transmit.</small>
    </div>
    <?php endif; ?>
    </div>
    <div style="display:flex;gap:14px;margin-top:14px;flex-wrap:wrap;">
      <div style="display:flex;align-items:center;gap:6px;font-size:.65rem;font-weight:700;"><div style="width:22px;height:3px;background:#00ff00"></div>SAFE &lt;70cm</div>
      <div style="display:flex;align-items:center;gap:6px;font-size:.65rem;font-weight:700;"><div style="width:22px;height:3px;background:#ffff00"></div>WARNING 70–100cm</div>
      <div style="display:flex;align-items:center;gap:6px;font-size:.65rem;font-weight:700;"><div style="width:22px;height:3px;background:#ff8c00"></div>DANGER 100–130cm</div>
      <div style="display:flex;align-items:center;gap:6px;font-size:.65rem;font-weight:700;"><div style="width:22px;height:3px;background:#ff0000;box-shadow:0 0 6px #ff0000"></div>CRITICAL &gt;130cm</div>
    </div>
  </div>

  <div class="card">
    <div class="card-title">
      <span>📍 Brgy. Baliwagan — Sensor Map</span>
      <span style="font-size:.62rem;color:var(--muted)">Live sensor locations</span>
    </div>
    <div style="height:340px;overflow:hidden;">
      <div id="baliwagan-map" style="width:100%;height:340px;"></div>
    </div>
  </div>
</div>

<!-- RECENT ALERTS + INCIDENTS -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">
  <div class="card">
    <div class="card-title">
      <span>🚨 Recent Flood Alerts</span>
      <a href="<?=BASE_URL?>/pages/alerts.php" class="btn btn-sm">View All</a>
    </div>
    <?php if($recentAlerts&&$recentAlerts->num_rows>0): ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Purok</th><th>Level</th><th>Alert</th><th>Time</th></tr></thead>
      <tbody>
      <?php
      $bc2=['warning'=>'#ffff00','danger'=>'#ff8c00','critical'=>'#ff0000'];
      while($a=$recentAlerts->fetch_assoc()):
        $bc=$bc2[$a['alert_level']]??'#00ff00';
      ?>
      <tr>
        <td><?=htmlspecialchars($a['purok'])?></td>
        <td style="font-weight:700;color:#fff;"><?=$a['water_level']?> cm</td>
        <td><span style="font-size:.58rem;padding:2px 8px;text-transform:uppercase;font-weight:700;background:<?=$bc?>22;color:<?=$bc?>;"><?=ucfirst($a['alert_level'])?></span></td>
        <td style="color:var(--cyan);font-size:.65rem;">SMS sent: <?=date('M d, Y H:i:s',strtotime($a['triggered_at']))?></td>
      </tr>
      <?php endwhile; ?>
      </tbody>
    </table></div>
    <?php else: ?>
    <div style="text-align:center;padding:24px;color:var(--muted);font-size:.7rem;">✅ No flood alerts on record</div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-title">
      <span>📋 Recent Incidents</span>
      <a href="<?=BASE_URL?>/pages/incidents.php" class="btn btn-sm">View All</a>
    </div>
    <?php if($recentIncidents&&$recentIncidents->num_rows>0): ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Title</th><th>Purok</th><th>Severity</th><th>Status</th></tr></thead>
      <tbody>
      <?php while($i=$recentIncidents->fetch_assoc()): ?>
      <tr>
        <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?=htmlspecialchars($i['title'])?></td>
        <td><?=htmlspecialchars($i['purok'])?></td>
        <td><span class="badge badge-<?=$i['severity']==='critical'?'critical':($i['severity']==='high'?'danger':($i['severity']==='moderate'?'warning':'safe'))?>"><?=ucfirst($i['severity'])?></span></td>
        <td><span class="badge badge-<?=$i['status']?>"><?=ucfirst($i['status'])?></span></td>
      </tr>
      <?php endwhile; ?>
      </tbody>
    </table></div>
    <?php else: ?>
    <div style="text-align:center;padding:24px;color:var(--muted);font-size:.7rem;">No incident logs yet</div>
    <?php endif; ?>
  </div>
</div>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css"/>
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script>
const SENSORS_DATA = <?php
$mapSensors = [];
$sRes = $conn->query("
    SELECT s.id, s.sensor_code, s.latitude, s.longitude, p.id as purok_id, p.name as purok,
           sr.water_level, sr.alert_status
    FROM sensors s JOIN puroks p ON s.purok_id=p.id
    LEFT JOIN (
        SELECT sensor_id, water_level, alert_status,
               ROW_NUMBER() OVER (PARTITION BY sensor_id ORDER BY recorded_at DESC) as rn
        FROM sensor_readings
    ) sr ON sr.sensor_id=s.id AND sr.rn=1
    ORDER BY s.id");
while($sr=$sRes->fetch_assoc()){
    // Use GPS coordinates if available, otherwise fallback to default
    $lat = $sr['latitude'] ?? 10.4112;
    $lng = $sr['longitude'] ?? 122.8888;
    $mapSensors[]=[
        'name'    => $sr['sensor_code'],
        'location'=> $sr['purok'],
        'level'   => (float)($sr['water_level']??0),
        'status'  => $sr['alert_status']??'safe',
        'lat'     => (float)$lat,
        'lng'     => (float)$lng,
    ];
}
echo json_encode($mapSensors);
?>;

function getColor(status,level){
  if(status==='critical'||level>=130) return '#ff0000';  // red for critical
  if(status==='danger'  ||level>=100) return '#ff8c00';  // orange for danger
  if(status==='warning' ||level>=70)  return '#ffff00';  // yellow for warning
  return '#00ff00';  // green for safe
}
function getStatusLabel(status,level){
  if(level<=0) return 'NO DATA';
  return status.toUpperCase();
}
function getZoneOpacity(status){ return {safe:.1,warning:.25,danger:.35,critical:.45}[status]||.1; }
function getZoneRadius(status){ return {safe:55,warning:70,danger:80,critical:90}[status]||55; }
function getDotRadius(status){ return {safe:9,warning:11,danger:12,critical:14}[status]||9; }

const map=L.map('baliwagan-map',{center:[10.4112,122.8888],zoom:15,zoomControl:true,attributionControl:false});
L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',{maxZoom:19}).addTo(map);
L.marker([10.4115,122.8894],{icon:L.divIcon({className:'',html:`<div style="font-family:'Space Mono',monospace;font-size:.6rem;color:#00e5ff;background:rgba(5,13,26,.85);border:1px solid #1a3050;padding:3px 8px;letter-spacing:1px;white-space:nowrap;pointer-events:none;">BRGY. BALIWAGAN</div>`,iconAnchor:[50,-6]})}).addTo(map);

SENSORS_DATA.forEach(s=>{
  const c   = getColor(s.status, s.level);
  const st  = getStatusLabel(s.status, s.level);
  const lv  = s.level > 0 ? s.level.toFixed(1)+' cm' : 'No data';
  const op  = getZoneOpacity(s.status);
  const zr  = getZoneRadius(s.status);
  const dr  = getDotRadius(s.status);

  // Flood zone colored circle
  L.circle([s.lat,s.lng],{
    radius: zr,
    color: c, fillColor: c,
    fillOpacity: op,
    weight: s.level>0 ? 2 : 1,
    dashArray: s.level>0 ? null : '5,5'
  }).addTo(map);

  // Outer glow ring for non-safe
  if(s.status !== 'safe' && s.level > 0){
    L.circleMarker([s.lat,s.lng],{
      radius: dr+7, fillColor:'transparent',
      color: c, weight:1.5, opacity:.35, fillOpacity:0
    }).addTo(map);
  }

  // Main sensor dot
  L.circleMarker([s.lat,s.lng],{
    radius: dr, fillColor: c,
    color:'#fff', weight:2, opacity:1, fillOpacity:.92
  }).addTo(map).bindPopup(`
    <div style="font-family:'Space Mono',monospace;font-size:.72rem;color:#c8dff0;line-height:2;min-width:170px;">
      <strong style="color:#00e5ff;display:block;margin-bottom:6px;font-size:.78rem;">${s.name}</strong>
      <span style="color:#4a6a8a">Purok:</span> ${s.location}<br>
      <span style="color:#4a6a8a">Water Level:</span> <strong style="color:${c};font-size:.85rem;">${lv}</strong><br>
      <span style="color:#4a6a8a">Status:</span> <strong style="color:${c}">${st}</strong>
    </div>
  `,{maxWidth:230});
});

window.addEventListener('resize',()=>map.invalidateSize());
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
