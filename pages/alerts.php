<?php
session_start();
require_once '../includes/config.php';
requireLogin();

$pageTitle  = 'Flood Alerts';
$activePage = 'alerts';
$msg = '';

// ── Resolve alert ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resolve') {
    $id = (int)$_POST['id'];
    $uid = $_SESSION['user_id'];
    $stmt = $conn->prepare("UPDATE flood_alerts SET is_resolved=1, resolved_at=NOW(), resolved_by=? WHERE id=?");
    $stmt->bind_param('ii', $uid, $id);
    $stmt->execute();
    logActivity($conn, $uid, 'RESOLVE_ALERT', "Resolved flood alert #$id");
    $msg = 'success:Alert marked as resolved.';
    $stmt->close();
}

// ── Delete ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    requireAdmin();
    $id = (int)$_POST['id'];
    $conn->query("DELETE FROM flood_alerts WHERE id=$id");
    logActivity($conn, $_SESSION['user_id'], 'DELETE_ALERT', "Deleted alert #$id");
    $msg = 'success:Alert deleted.';
}

$filterLevel    = $_GET['level'] ?? '';
$filterResolved = $_GET['resolved'] ?? '';

$where = '1=1';
if ($filterLevel)    $where .= " AND fa.alert_level='" . $conn->real_escape_string($filterLevel) . "'";
if ($filterResolved === 'active')   $where .= " AND fa.is_resolved=0";
if ($filterResolved === 'resolved') $where .= " AND fa.is_resolved=1";

$alerts = $conn->query("
    SELECT fa.*, p.name as purok, s.sensor_code,
           CONCAT(u.first_name,' ',u.last_name) as resolved_by_name
    FROM flood_alerts fa
    JOIN puroks p ON fa.purok_id = p.id
    JOIN sensors s ON fa.sensor_id = s.id
    LEFT JOIN users u ON fa.resolved_by = u.id
    WHERE $where
    ORDER BY fa.triggered_at DESC
");

// Stats
$totalAlerts    = $conn->query("SELECT COUNT(*) c FROM flood_alerts")->fetch_assoc()['c'];
$activeAlerts   = $conn->query("SELECT COUNT(*) c FROM flood_alerts WHERE is_resolved=0")->fetch_assoc()['c'];
$criticalAlerts = $conn->query("SELECT COUNT(*) c FROM flood_alerts WHERE alert_level='critical'")->fetch_assoc()['c'];

[$msgType, $msgText] = $msg ? explode(':', $msg, 2) : ['', ''];

include '../includes/header.php';
?>

<?php if ($msgText): ?>
<div class="alert alert-<?= $msgType==='success'?'success':'danger' ?>">
  <?= $msgType==='success'?'✅':'⚠' ?> <?= htmlspecialchars($msgText) ?>
</div>
<?php endif; ?>

<!-- STATS -->
<div class="stats-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:20px;">
  <div class="stat-card">
    <div class="stat-label">Total Alerts</div>
    <div class="stat-value"><?= $totalAlerts ?></div>
    <div class="stat-unit">all time</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Active Alerts</div>
    <div class="stat-value" style="color:<?= $activeAlerts>0?'var(--danger)':'var(--safe)' ?>"><?= $activeAlerts ?></div>
    <div class="stat-unit">unresolved</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Critical</div>
    <div class="stat-value" style="color:<?= $criticalAlerts>0?'#ff0044':'var(--safe)' ?>"><?= $criticalAlerts ?></div>
    <div class="stat-unit">critical level alerts</div>
  </div>
</div>

<!-- FILTERS -->
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;">
  <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;">
    <select name="level" class="form-control" style="width:auto;padding:7px 12px;" onchange="this.form.submit()">
      <option value="">All Levels</option>
      <option value="warning" <?= $filterLevel==='warning'?'selected':'' ?>>Warning</option>
      <option value="danger" <?= $filterLevel==='danger'?'selected':'' ?>>Danger</option>
      <option value="critical" <?= $filterLevel==='critical'?'selected':'' ?>>Critical</option>
    </select>
    <select name="resolved" class="form-control" style="width:auto;padding:7px 12px;" onchange="this.form.submit()">
      <option value="">All Alerts</option>
      <option value="active" <?= $filterResolved==='active'?'selected':'' ?>>Active Only</option>
      <option value="resolved" <?= $filterResolved==='resolved'?'selected':'' ?>>Resolved Only</option>
    </select>
  </form>
</div>

<!-- ALERTS TABLE -->
<div class="card">
  <div class="card-title">
    <span>🚨 Flood Alert Records</span>
    <span style="font-size:0.65rem;color:var(--muted)"><?= $alerts->num_rows ?> record(s)</span>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>#</th><th>Sensor</th><th>Purok</th><th>Level</th><th>Water (cm)</th><th>Triggered</th><th>Status</th><th>Actions</th></tr>
      </thead>
      <tbody>
      <?php if ($alerts->num_rows > 0): while ($a = $alerts->fetch_assoc()): ?>
      <tr>
        <td style="color:var(--muted)">#<?= $a['id'] ?></td>
        <td style="font-size:0.68rem;color:var(--cyan);"><?= htmlspecialchars($a['sensor_code']) ?></td>
        <td><?= htmlspecialchars($a['purok']) ?></td>
        <td><span class="badge badge-<?= $a['alert_level'] ?>"><?= ucfirst($a['alert_level']) ?></span></td>
        <td style="font-weight:700;color:#fff;"><?= $a['water_level'] ?> cm</td>
        <td style="font-size:0.65rem;color:var(--cyan);">SMS sent: <?= date('M d, Y H:i', strtotime($a['triggered_at'])) ?></td>
        <td>
          <?php if ($a['is_resolved']): ?>
            <span class="badge badge-approved">Resolved</span>
            <?php if ($a['resolved_by_name']): ?>
            <div style="font-size:0.58rem;color:var(--muted);margin-top:2px;">by <?= htmlspecialchars($a['resolved_by_name']) ?></div>
            <?php endif; ?>
          <?php else: ?>
            <span class="badge badge-danger">Active</span>
          <?php endif; ?>
        </td>
        <td>
          <div style="display:flex;gap:6px;">
            <?php if (!$a['is_resolved']): ?>
            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="resolve">
              <input type="hidden" name="id" value="<?= $a['id'] ?>">
              <button type="submit" class="btn btn-success btn-sm">✓ Resolve</button>
            </form>
            <?php endif; ?>
            <?php if ($_SESSION['role']==='admin'): ?>
            <button type="button" class="btn btn-danger btn-sm" onclick="showDeleteModal('alert', <?= $a['id'] ?>, '<?= htmlspecialchars($a['alert_level']) ?> alert for Purok <?= $a['purok_id'] ?>')">Del</button>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endwhile; else: ?>
      <tr><td colspan="8" style="text-align:center;padding:30px;color:var(--muted);">No flood alerts found.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.7);z-index:1000;align-items:center;justify-content:center;">
  <div style="background:var(--panel);border:1px solid var(--border);padding:24px;max-width:400px;width:90%;border-radius:8px;">
    <div style="font-family:var(--font-head);font-size:1.1rem;font-weight:800;color:#fff;margin-bottom:12px;">Confirm Deletion</div>
    <div style="font-size:.7rem;color:var(--muted);margin-bottom:20px;line-height:1.6;" id="deleteMessage">Are you sure you want to delete this item?</div>
    <div style="display:flex;gap:12px;justify-content:flex-end;">
      <button type="button" onclick="closeDeleteModal()" style="background:none;border:1px solid var(--border);color:var(--text);font-family:var(--font-mono);font-size:.65rem;padding:8px 16px;cursor:pointer;letter-spacing:1px;text-transform:uppercase;">Cancel</button>
      <button type="button" onclick="confirmDelete()" style="background:var(--danger);border:1px solid var(--danger);color:#fff;font-family:var(--font-mono);font-size:.65rem;padding:8px 16px;cursor:pointer;letter-spacing:1px;text-transform:uppercase;">Delete</button>
    </div>
  </div>
</div>

<form id="deleteForm" method="POST" style="display:none;">
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="deleteId">
</form>

<script>
let deleteType = '';
let deleteId = '';

function showDeleteModal(type, id, name) {
  deleteType = type;
  deleteId = id;
  document.getElementById('deleteId').value = id;
  document.getElementById('deleteMessage').textContent = `Are you sure you want to delete ${type}: ${name}? This action cannot be undone.`;
  document.getElementById('deleteModal').style.display = 'flex';
}

function closeDeleteModal() {
  document.getElementById('deleteModal').style.display = 'none';
}

function confirmDelete() {
  document.getElementById('deleteForm').submit();
}
</script>

<?php include '../includes/footer.php'; ?>
