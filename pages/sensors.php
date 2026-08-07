<?php
session_start();
require_once '../includes/config.php';
requireLogin();

$pageTitle  = 'Sensors';
$activePage = 'sensors';
$msg = '';

// ── Update sensor status ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $id = (int)$_POST['id'];
    if ($_POST['action'] === 'update_status') {
        $status = $_POST['status'];
        $stmt = $conn->prepare("UPDATE sensors SET status=? WHERE id=?");
        $stmt->bind_param('si', $status, $id);
        $stmt->execute();
        logActivity($conn, $_SESSION['user_id'], 'UPDATE_SENSOR', "Updated sensor #$id status to $status");
        $msg = 'success:Sensor status updated.';
        $stmt->close();
    }
}

$sensors = $conn->query("
    SELECT s.*, p.name as purok, p.id as purok_id,
           (SELECT COUNT(*) FROM sensor_readings WHERE sensor_id=s.id AND DATE(recorded_at) = CURDATE()) as reading_count,
           (SELECT water_level FROM sensor_readings WHERE sensor_id=s.id AND DATE(recorded_at) = CURDATE() ORDER BY recorded_at DESC LIMIT 1) as last_level,
           (SELECT alert_status FROM sensor_readings WHERE sensor_id=s.id AND DATE(recorded_at) = CURDATE() ORDER BY recorded_at DESC LIMIT 1) as last_status,
           (SELECT recorded_at FROM sensor_readings WHERE sensor_id=s.id AND DATE(recorded_at) = CURDATE() ORDER BY recorded_at DESC LIMIT 1) as last_reading
    FROM sensors s
    JOIN puroks p ON s.purok_id = p.id
    ORDER BY s.id
");

[$msgType, $msgText] = $msg ? explode(':', $msg, 2) : ['', ''];

include '../includes/header.php';
?>

<?php if ($msgText): ?>
<div class="alert alert-<?= $msgType==='success'?'success':'danger' ?>">
  <?= $msgType==='success'?'✅':'⚠' ?> <?= htmlspecialchars($msgText) ?>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-title">
    <span>🔧 IoT Sensor Devices</span>
    <span style="font-size:0.65rem;color:var(--muted)">Brgy. Baliwagan — 4 Sensors</span>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Sensor Code</th><th>Purok</th><th>Status</th><th>Last Level</th><th>Last Reading</th><th>Total Readings</th><th>Installed</th><th>Actions</th></tr>
      </thead>
      <tbody>
      <?php while ($s = $sensors->fetch_assoc()): ?>
      <?php
        $level = $s['last_level'] ?? 0;
        $lstatus = $s['last_status'] ?? 'safe';
        $colors = ['safe'=>'#00e676','warning'=>'#ffaa00','danger'=>'#ff3355','critical'=>'#ff0044'];
        $lcolor = $colors[$lstatus] ?? '#00e676';
      ?>
      <tr>
        <td style="color:var(--cyan);font-weight:700;"><?= htmlspecialchars($s['sensor_code']) ?></td>
        <td><?= htmlspecialchars($s['purok']) ?></td>
        <td><span class="badge badge-<?= $s['status'] ?>"><?= ucfirst($s['status']) ?></span></td>
        <td>
          <?php if ($s['last_level']): ?>
          <span style="color:<?= $lcolor ?>;font-weight:700;"><?= round($s['last_level']) ?> cm</span>
          <span class="badge" style="background:<?= $lcolor ?>22;color:<?= $lcolor ?>;margin-left:4px;"><?= ucfirst($lstatus) ?></span>
          <?php else: ?>
          <span style="color:var(--muted)">No data</span>
          <?php endif; ?>
        </td>
        <td style="font-size:0.65rem;color:var(--muted);">
          <?= $s['last_reading'] ? date('M d, Y h:i A', strtotime($s['last_reading'])) : '—' ?>
        </td>
        <td style="text-align:center;"><?= number_format($s['reading_count']) ?></td>
        <td style="font-size:0.65rem;color:var(--muted);"><?= $s['installed_at'] ? date('M d, Y', strtotime($s['installed_at'])) : '—' ?></td>
        <td>
          <form method="POST" style="display:inline-flex;gap:6px;align-items:center;">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="id" value="<?= $s['id'] ?>">
            <select name="status" class="form-control" style="width:auto;padding:4px 8px;font-size:0.6rem;" onchange="this.form.submit()">
              <option value="online" <?= $s['status']==='online'?'selected':'' ?>>Online</option>
              <option value="offline" <?= $s['status']==='offline'?'selected':'' ?>>Offline</option>
              <option value="maintenance" <?= $s['status']==='maintenance'?'selected':'' ?>>Maintenance</option>
            </select>
          </form>
        </td>
      </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Sensor Location Map -->
<div class="card">
  <div class="card-title">
    <span>📍 Sensor Locations</span>
  </div>
  <div style="height:360px;">
    <div id="sensor-map" style="width:100%;height:360px;"></div>
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
    LEFT JOIN sensor_readings sr ON sr.sensor_id=s.id
    AND sr.recorded_at=(SELECT MAX(recorded_at) FROM sensor_readings WHERE sensor_id=s.id AND DATE(recorded_at) = CURDATE())
    WHERE sr.recorded_at IS NULL OR DATE(sr.recorded_at) = CURDATE()
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

const map=L.map('sensor-map',{center:[10.4112,122.8888],zoom:15,zoomControl:true,attributionControl:false});
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

// Auto-refresh page every 30 seconds
setInterval(()=>{
  location.reload();
}, 30000);
</script>

<?php include '../includes/footer.php'; ?>
