<?php
session_start();
require_once '../includes/config.php';
requireLogin();

$pageTitle = 'Incident Logs';
$activePage = 'incidents';

$msg = '';

// ── Add new incident ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $purok_id    = (int)($_POST['purok_id'] ?? 0);
    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $action_taken= trim($_POST['action_taken'] ?? '');
    $severity    = $_POST['severity'] ?? 'low';
    $alert_id    = !empty($_POST['alert_id']) ? (int)$_POST['alert_id'] : null;
    $logged_by   = $_SESSION['user_id'];

    if ($title && $purok_id) {
        // Get next ID for TiDB compatibility
        $result = $conn->query("SELECT MAX(id) as max_id FROM incident_logs");
        $row = $result->fetch_assoc();
        $nextId = ($row['max_id'] ?? 0) + 1;
        
        $stmt = $conn->prepare("INSERT INTO incident_logs (id, alert_id, purok_id, logged_by, title, description, action_taken, severity) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->bind_param('iiiissss', $nextId, $alert_id, $purok_id, $logged_by, $title, $description, $action_taken, $severity);
        if ($stmt->execute()) {
            logActivity($conn, $logged_by, 'ADD_INCIDENT', "Added incident: $title");
            $msg = 'success:Incident log added successfully.';
        } else {
            $msg = 'error:Failed to add incident log.';
        }
        $stmt->close();
    } else {
        $msg = 'error:Please fill in all required fields.';
    }
}

// ── Update status ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $id = (int)$_POST['id'];
    $status = $_POST['status'];
    $stmt = $conn->prepare("UPDATE incident_logs SET status=? WHERE id=?");
    $stmt->bind_param('si', $status, $id);
    $stmt->execute();
    logActivity($conn, $_SESSION['user_id'], 'UPDATE_INCIDENT', "Updated incident #$id status to $status");
    $msg = 'success:Status updated.';
    $stmt->close();
}

// ── Delete ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    requireAdmin();
    $id = (int)$_POST['id'];
    $conn->query("DELETE FROM incident_logs WHERE id=$id");
    logActivity($conn, $_SESSION['user_id'], 'DELETE_INCIDENT', "Deleted incident #$id");
    $msg = 'success:Incident deleted.';
}

// ── Filters ──────────────────────────────────────────────────
$filterStatus   = $_GET['status'] ?? '';
$filterSeverity = $_GET['severity'] ?? '';
$filterPurok    = (int)($_GET['purok'] ?? 0);

$where = '1=1';
if ($filterStatus)   $where .= " AND il.status='" . $conn->real_escape_string($filterStatus) . "'";
if ($filterSeverity) $where .= " AND il.severity='" . $conn->real_escape_string($filterSeverity) . "'";
if ($filterPurok)    $where .= " AND il.purok_id=$filterPurok";

$incidents = $conn->query("
    SELECT il.*, p.name as purok, CONCAT(u.first_name,' ',u.last_name) as logged_by_name
    FROM incident_logs il
    JOIN puroks p ON il.purok_id = p.id
    JOIN users u ON il.logged_by = u.id
    WHERE $where
    ORDER BY il.created_at DESC
");

$puroks = $conn->query("SELECT * FROM puroks ORDER BY name");
$alerts = $conn->query("SELECT id, alert_level, triggered_at FROM flood_alerts WHERE is_resolved=0 ORDER BY triggered_at DESC");

[$msgType, $msgText] = $msg ? explode(':', $msg, 2) : ['', ''];

include '../includes/header.php';
?>

<?php if ($msgText): ?>
<div class="alert alert-<?= $msgType === 'success' ? 'success' : 'danger' ?>">
  <?= $msgType === 'success' ? '✅' : '⚠' ?> <?= htmlspecialchars($msgText) ?>
</div>
<?php endif; ?>

<!-- FILTERS + ADD BUTTON -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
  <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <select name="status" class="form-control" style="width:auto;padding:7px 12px;" onchange="this.form.submit()">
      <option value="">All Statuses</option>
      <option value="open" <?= $filterStatus==='open'?'selected':'' ?>>Open</option>
      <option value="ongoing" <?= $filterStatus==='ongoing'?'selected':'' ?>>Ongoing</option>
      <option value="resolved" <?= $filterStatus==='resolved'?'selected':'' ?>>Resolved</option>
    </select>
    <select name="severity" class="form-control" style="width:auto;padding:7px 12px;" onchange="this.form.submit()">
      <option value="">All Severities</option>
      <option value="low" <?= $filterSeverity==='low'?'selected':'' ?>>Low</option>
      <option value="moderate" <?= $filterSeverity==='moderate'?'selected':'' ?>>Moderate</option>
      <option value="high" <?= $filterSeverity==='high'?'selected':'' ?>>High</option>
      <option value="critical" <?= $filterSeverity==='critical'?'selected':'' ?>>Critical</option>
    </select>
    <select name="purok" class="form-control" style="width:auto;padding:7px 12px;" onchange="this.form.submit()">
      <option value="">All Puroks</option>
      <?php $puroks->data_seek(0); while ($p = $puroks->fetch_assoc()): ?>
      <option value="<?= $p['id'] ?>" <?= $filterPurok==$p['id']?'selected':'' ?>><?= htmlspecialchars($p['name']) ?></option>
      <?php endwhile; ?>
    </select>
  </form>
  <button class="btn btn-primary" onclick="document.getElementById('modal-add').style.display='flex'">+ Log Incident</button>
</div>

<!-- INCIDENTS TABLE -->
<div class="card">
  <div class="card-title">
    <span>📋 Incident Logs</span>
    <span style="font-size:0.65rem;color:var(--muted)"><?= $incidents->num_rows ?> record(s)</span>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>#</th><th>Title</th><th>Purok</th><th>Severity</th><th>Status</th>
          <th>Logged By</th><th>Date</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($incidents->num_rows > 0): while ($i = $incidents->fetch_assoc()): ?>
      <tr>
        <td style="color:var(--muted)">#<?= $i['id'] ?></td>
        <td>
          <div style="font-weight:700;color:#fff;"><?= htmlspecialchars($i['title']) ?></div>
          <?php if ($i['description']): ?>
          <div style="font-size:0.62rem;color:var(--muted);margin-top:2px;"><?= htmlspecialchars(substr($i['description'],0,60)) ?>...</div>
          <?php endif; ?>
        </td>
        <td><?= htmlspecialchars($i['purok']) ?></td>
        <td>
          <span class="badge badge-<?= $i['severity']==='critical'?'critical':($i['severity']==='high'?'danger':($i['severity']==='moderate'?'warning':'safe')) ?>">
            <?= ucfirst($i['severity']) ?>
          </span>
        </td>
        <td><span class="badge badge-<?= $i['status'] ?>"><?= ucfirst($i['status']) ?></span></td>
        <td style="font-size:0.68rem;"><?= htmlspecialchars($i['logged_by_name']) ?></td>
        <td style="font-size:0.65rem;color:var(--muted);"><?= date('M d, Y H:i', strtotime($i['created_at'])) ?></td>
        <td>
          <div style="display:flex;gap:6px;flex-wrap:wrap;">
            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="update_status">
              <input type="hidden" name="id" value="<?= $i['id'] ?>">
              <select name="status" class="form-control" style="width:auto;padding:4px 8px;font-size:0.6rem;" onchange="this.form.submit()">
                <option value="open" <?= $i['status']==='open'?'selected':'' ?>>Open</option>
                <option value="ongoing" <?= $i['status']==='ongoing'?'selected':'' ?>>Ongoing</option>
                <option value="resolved" <?= $i['status']==='resolved'?'selected':'' ?>>Resolved</option>
              </select>
            </form>
            <?php if ($_SESSION['role']==='admin'): ?>
            <button type="button" class="btn btn-danger btn-sm" onclick="showDeleteModal('incident', <?= $i['id'] ?>, '<?= htmlspecialchars($i['incident_type']) ?>')">Del</button>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endwhile; else: ?>
      <tr><td colspan="8" style="text-align:center;padding:30px;color:var(--muted);">No incident logs found.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ADD INCIDENT MODAL -->
<div id="modal-add" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:999;align-items:center;justify-content:center;">
  <div style="background:var(--panel);border:1px solid var(--border);padding:28px;width:100%;max-width:560px;max-height:90vh;overflow-y:auto;position:relative;">
    <div style="font-family:var(--font-head);font-size:1rem;font-weight:800;color:#fff;margin-bottom:20px;">Log New Incident</div>
    <form method="POST">
      <input type="hidden" name="action" value="add">
      <div class="form-grid">
        <div class="form-group" style="grid-column:1/-1">
          <label class="form-label">Incident Title *</label>
          <input type="text" name="title" class="form-control" placeholder="Brief title of the incident" required>
        </div>
        <div class="form-group">
          <label class="form-label">Purok *</label>
          <select name="purok_id" class="form-control" required>
            <option value="">Select Purok</option>
            <?php $puroks->data_seek(0); while ($p = $puroks->fetch_assoc()): ?>
            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
            <?php endwhile; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Severity *</label>
          <select name="severity" class="form-control">
            <option value="low">Low</option>
            <option value="moderate">Moderate</option>
            <option value="high">High</option>
            <option value="critical">Critical</option>
          </select>
        </div>
        <div class="form-group" style="grid-column:1/-1">
          <label class="form-label">Link to Alert (optional)</label>
          <select name="alert_id" class="form-control">
            <option value="">None</option>
            <?php while ($al = $alerts->fetch_assoc()): ?>
            <option value="<?= $al['id'] ?>">Alert #<?= $al['id'] ?> — <?= ucfirst($al['alert_level']) ?> (<?= date('M d', strtotime($al['triggered_at'])) ?>)</option>
            <?php endwhile; ?>
          </select>
        </div>
        <div class="form-group" style="grid-column:1/-1">
          <label class="form-label">Description</label>
          <textarea name="description" class="form-control" placeholder="Describe what happened..."></textarea>
        </div>
        <div class="form-group" style="grid-column:1/-1">
          <label class="form-label">Action Taken</label>
          <textarea name="action_taken" class="form-control" placeholder="What actions were taken..."></textarea>
        </div>
      </div>
      <div style="display:flex;gap:10px;margin-top:8px;">
        <button type="submit" class="btn btn-primary">Save Incident</button>
        <button type="button" class="btn" onclick="document.getElementById('modal-add').style.display='none'">Cancel</button>
      </div>
    </form>
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
