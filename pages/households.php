<?php
session_start();
require_once '../includes/config.php';
requireLogin();

$pageTitle  = 'Households';
$activePage = 'households';
$msg = '';

// ── Add household ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $purok_id  = (int)$_POST['purok_id'];
    $head      = trim($_POST['head_of_household'] ?? '');
    $contact   = trim($_POST['contact_number'] ?? '');
    $members   = (int)($_POST['members_count'] ?? 1);

    if ($purok_id && $head) {
        // Get next ID for TiDB compatibility
        $result = $conn->query("SELECT MAX(id) as max_id FROM households");
        $row = $result->fetch_assoc();
        $nextId = ($row['max_id'] ?? 0) + 1;
        
        $stmt = $conn->prepare("INSERT INTO households (id, purok_id, head_of_household, contact_number, members_count) VALUES (?,?,?,?,?)");
        $stmt->bind_param('iissi', $nextId, $purok_id, $head, $contact, $members);
        $stmt->execute();
        logActivity($conn, $_SESSION['user_id'], 'ADD_HOUSEHOLD', "Added household: $head");
        $msg = 'success:Household added successfully.';
        $stmt->close();
    } else {
        $msg = 'error:Purok and Head of Household are required.';
    }
}

// ── Delete household ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    requireAdmin();
    $id = (int)$_POST['id'];
    $conn->query("DELETE FROM households WHERE id=$id");
    logActivity($conn, $_SESSION['user_id'], 'DELETE_HOUSEHOLD', "Deleted household #$id");
    $msg = 'success:Household deleted.';
}

// ── Edit household ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    requireAdmin();
    $id = (int)$_POST['id'];
    $purok_id = (int)$_POST['purok_id'];
    $head = trim($_POST['head_of_household'] ?? '');
    $contact = trim($_POST['contact_number'] ?? '');
    $members = (int)($_POST['members_count'] ?? 1);

    if ($purok_id && $head) {
        $stmt = $conn->prepare("UPDATE households SET purok_id=?, head_of_household=?, contact_number=?, members_count=? WHERE id=?");
        $stmt->bind_param('issii', $purok_id, $head, $contact, $members, $id);
        $stmt->execute();
        logActivity($conn, $_SESSION['user_id'], 'EDIT_HOUSEHOLD', "Edited household #$id: $head");
        $msg = 'success:Household updated successfully.';
        $stmt->close();
    } else {
        $msg = 'error:Purok and Head of Household are required.';
    }
}

$filterPurok = (int)($_GET['purok'] ?? 0);
$search      = trim($_GET['search'] ?? '');

$where = '1=1';
if ($filterPurok) $where .= " AND h.purok_id=$filterPurok";
if ($search)      $where .= " AND h.head_of_household LIKE '%" . $conn->real_escape_string($search) . "%'";

$households = $conn->query("
    SELECT h.*, p.name as purok
    FROM households h
    JOIN puroks p ON h.purok_id = p.id
    WHERE $where
    ORDER BY p.name, h.head_of_household
");

$puroks = $conn->query("SELECT * FROM puroks ORDER BY name");

// Summary per purok
$summary = $conn->query("
    SELECT p.name, COUNT(h.id) as total, COALESCE(SUM(h.members_count),0) as members
    FROM puroks p
    LEFT JOIN households h ON h.purok_id = p.id
    GROUP BY p.id, p.name ORDER BY p.name
");

[$msgType, $msgText] = $msg ? explode(':', $msg, 2) : ['', ''];

include '../includes/header.php';
?>

<?php if ($msgText): ?>
<div class="alert alert-<?= $msgType==='success'?'success':'danger' ?>">
  <?= $msgType==='success'?'✅':'⚠' ?> <?= htmlspecialchars($msgText) ?>
</div>
<?php endif; ?>

<!-- PUROK SUMMARY CARDS -->
<div class="stats-grid" style="margin-bottom:20px;">
<?php while ($s = $summary->fetch_assoc()): ?>
<div class="stat-card">
  <div class="stat-label"><?= htmlspecialchars($s['name']) ?></div>
  <div class="stat-value"><?= $s['total'] ?></div>
  <div class="stat-unit">households</div>
  <div class="stat-sub"><?= $s['members'] ?> members total</div>
</div>
<?php endwhile; ?>
</div>

<!-- SEARCH + FILTER + ADD -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
  <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;">
    <input type="text" name="search" class="form-control" placeholder="Search head of household..." value="<?= htmlspecialchars($search) ?>" style="width:220px;padding:7px 12px;">
    <select name="purok" class="form-control" style="width:auto;padding:7px 12px;" onchange="this.form.submit()">
      <option value="">All Puroks</option>
      <?php $puroks->data_seek(0); while ($p = $puroks->fetch_assoc()): ?>
      <option value="<?= $p['id'] ?>" <?= $filterPurok==$p['id']?'selected':'' ?>><?= htmlspecialchars($p['name']) ?></option>
      <?php endwhile; ?>
    </select>
    <button type="submit" class="btn">Search</button>
  </form>
  <button class="btn btn-primary" onclick="document.getElementById('modal-add').style.display='flex'">+ Add Household</button>
</div>

<!-- HOUSEHOLDS TABLE -->
<div class="card">
  <div class="card-title">
    <span>🏠 Household Registry</span>
    <span style="font-size:0.65rem;color:var(--muted)"><?= $households->num_rows ?> record(s)</span>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>#</th><th>Head of Household</th><th>Purok</th><th>Contact</th><th>Members</th><?php if ($_SESSION['role']==='admin'): ?><th>Actions</th><?php endif; ?></tr>
      </thead>
      <tbody>
      <?php if ($households->num_rows > 0): while ($h = $households->fetch_assoc()): ?>
      <tr>
        <td style="color:var(--muted)"><?= $h['id'] ?></td>
        <td style="color:#fff;font-weight:700;"><?= htmlspecialchars($h['head_of_household']) ?></td>
        <td><?= htmlspecialchars($h['purok']) ?></td>
        <td style="font-size:0.68rem;"><?= $h['contact_number'] ? htmlspecialchars($h['contact_number']) : '—' ?></td>
        <td style="text-align:center;"><?= $h['members_count'] ?></td>
        <?php if ($_SESSION['role']==='admin'): ?>
        <td>
          <div style="display:flex;gap:6px;">
            <button type="button" class="btn btn-sm" onclick="showEditModal(<?= $h['id'] ?>, <?= $h['purok_id'] ?>, '<?= htmlspecialchars($h['head_of_household']) ?>', '<?= htmlspecialchars($h['contact_number']) ?>', <?= $h['members_count'] ?>)">Edit</button>
            <button type="button" class="btn btn-danger btn-sm" onclick="showDeleteModal('household', <?= $h['id'] ?>, '<?= htmlspecialchars($h['head_of_household']) ?>')">DELETE</button>
          </div>
        </td>
        <?php endif; ?>
      </tr>
      <?php endwhile; else: ?>
      <tr><td colspan="7" style="text-align:center;padding:30px;color:var(--muted);">No households found.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ADD MODAL -->
<div id="modal-add" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:999;align-items:center;justify-content:center;">
  <div style="background:var(--panel);border:1px solid var(--border);padding:28px;width:100%;max-width:480px;position:relative;">
    <div style="font-family:var(--font-head);font-size:1rem;font-weight:800;color:#fff;margin-bottom:20px;">Add Household</div>
    <form method="POST">
      <input type="hidden" name="action" value="add">
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
        <label class="form-label">Head of Household *</label>
        <input type="text" name="head_of_household" class="form-control" placeholder="Full name" required>
      </div>
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Contact Number</label>
          <input type="text" name="contact_number" class="form-control" placeholder="09XX-XXX-XXXX">
        </div>
        <div class="form-group">
          <label class="form-label">No. of Members</label>
          <input type="number" name="members_count" class="form-control" value="1" min="1">
        </div>
      </div>
      <div style="display:flex;gap:10px;margin-top:8px;">
        <button type="submit" class="btn btn-primary">Add Household</button>
        <button type="button" class="btn" onclick="document.getElementById('modal-add').style.display='none'">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- EDIT MODAL -->
<div id="modal-edit" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:999;align-items:center;justify-content:center;">
  <div style="background:var(--panel);border:1px solid var(--border);padding:28px;width:100%;max-width:480px;position:relative;">
    <div style="font-family:var(--font-head);font-size:1rem;font-weight:800;color:#fff;margin-bottom:20px;">Edit Household</div>
    <form method="POST">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" id="edit_id">
      <div class="form-group">
        <label class="form-label">Purok *</label>
        <select name="purok_id" id="edit_purok_id" class="form-control" required>
          <option value="">Select Purok</option>
          <?php $puroks->data_seek(0); while ($p = $puroks->fetch_assoc()): ?>
          <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
          <?php endwhile; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Head of Household *</label>
        <input type="text" name="head_of_household" id="edit_head" class="form-control" placeholder="Full name" required>
      </div>
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Contact Number</label>
          <input type="text" name="contact_number" id="edit_contact" class="form-control" placeholder="09XX-XXX-XXXX">
        </div>
        <div class="form-group">
          <label class="form-label">No. of Members</label>
          <input type="number" name="members_count" id="edit_members" class="form-control" value="1" min="1">
        </div>
      </div>
      <div style="display:flex;gap:10px;margin-top:8px;">
        <button type="submit" class="btn btn-primary">Update Household</button>
        <button type="button" class="btn" onclick="document.getElementById('modal-edit').style.display='none'">Cancel</button>
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

function showEditModal(id, purokId, head, contact, members) {
  document.getElementById('edit_id').value = id;
  document.getElementById('edit_purok_id').value = purokId;
  document.getElementById('edit_head').value = head;
  document.getElementById('edit_contact').value = contact;
  document.getElementById('edit_members').value = members;
  document.getElementById('modal-edit').style.display = 'flex';
}
</script>

<?php include '../includes/footer.php'; ?>
