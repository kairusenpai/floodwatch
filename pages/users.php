<?php
session_start();
require_once '../includes/config.php';
requireAdmin();

$pageTitle  = 'Users';
$activePage = 'users';
$msg = '';

// ── Approve / Reject / Delete ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $id  = isset($_POST['id']) ? (int)$_POST['id'] : null;
    $uid = $_SESSION['user_id'];
    switch ($_POST['action']) {

        case 'add':
            $fname = trim($_POST['first_name'] ?? '');
            $lname = trim($_POST['last_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $role = $_POST['role'] ?? 'operator';
            $pass = $_POST['password'] ?? '';

            if (!$fname || !$lname || !$email || !$pass) {
                $msg = 'error:All fields are required.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $msg = 'error:Invalid email address.';
            } elseif (strlen($pass) < 8) {
                $msg = 'error:Password must be at least 8 characters.';
            } elseif ($role === 'admin') {
                $msg = 'error:Cannot add admin users. Only operator role is allowed.';
            } else {
                $chk = $conn->prepare("SELECT id FROM users WHERE email = ?");
                $chk->bind_param('s', $email);
                $chk->execute();
                $chk->store_result();
                if ($chk->num_rows > 0) {
                    $msg = 'error:This email is already registered.';
                } else {
                    $hashed = password_hash($pass, PASSWORD_BCRYPT);
                    $status = 'approved';
                    
                    // Get next ID for TiDB compatibility
                    $result = $conn->query("SELECT MAX(id) as max_id FROM users");
                    $row = $result->fetch_assoc();
                    $nextId = ($row['max_id'] ?? 0) + 1;
                    
                    $stmt = $conn->prepare("INSERT INTO users (id, first_name, last_name, email, password, role, status, created_at) VALUES (?,?,?,?,?,?,?,NOW())");
                    $stmt->bind_param('issssss', $nextId, $fname, $lname, $email, $hashed, $role, $status);
                    if ($stmt->execute()) {
                        $newId = $nextId;
                        logActivity($conn, $uid, 'ADD_USER', "Added user: $email as $role");
                        $msg = 'success:User added successfully.';
                    } else {
                        $msg = 'error:Failed to add user.';
                    }
                    $stmt->close();
                }
                $chk->close();
            }
            break;

        case 'delete':
            if ($id !== (int)$_SESSION['user_id']) {
                $conn->query("DELETE FROM users WHERE id=$id");
                logActivity($conn, $uid, 'DELETE_USER', "Deleted user #$id");
                $msg = 'success:User deleted.';
            } else {
                $msg = 'error:You cannot delete your own account.';
            }
            break;
        case 'change_role':
            $role = $_POST['role'];
            if ($role === 'admin') {
                $msg = 'error:Cannot assign admin role to users.';
            } else {
                $stmt = $conn->prepare("UPDATE users SET role=? WHERE id=?");
                $stmt->bind_param('si', $role, $id);
                $stmt->execute();
                logActivity($conn, $uid, 'CHANGE_ROLE', "Changed user #$id role to $role");
                $msg = 'success:Role updated.';
                $stmt->close();
            }
            break;
    }
}

$filterStatus = $_GET['status'] ?? '';
$where = '1=1';
if ($filterStatus) $where .= " AND status='" . $conn->real_escape_string($filterStatus) . "'";

$users = $conn->query("SELECT * FROM users WHERE $where ORDER BY created_at DESC");

$pendingCount = 0;

[$msgType, $msgText] = $msg ? explode(':', $msg, 2) : ['', ''];

include '../includes/header.php';
?>

<?php if ($msgText): ?>
<div class="alert alert-<?= $msgType==='success'?'success':'danger' ?>">
  <?= $msgType==='success'?'✅':'⚠' ?> <?= htmlspecialchars($msgText) ?>
</div>
<?php endif; ?>



<!-- FILTER -->
<div style="display:flex;gap:8px;margin-bottom:16px;justify-content:space-between;align-items:center;">
  <form method="GET" style="display:flex;gap:8px;">
    <select name="status" class="form-control" style="width:auto;padding:7px 12px;" onchange="this.form.submit()">
      <option value="">All Users</option>
      <option value="approved" <?= $filterStatus==='approved'?'selected':'' ?>>Approved</option>
    </select>
  </form>
  <button type="button" class="btn btn-primary btn-sm" onclick="showAddModal()">+ Add User</button>
</div>

<div class="card">
  <div class="card-title">
    <span>👥 System Users</span>
    <span style="font-size:0.65rem;color:var(--muted)"><?= $users->num_rows ?> user(s)</span>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>#</th><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Registered</th><th>Actions</th></tr>
      </thead>
      <tbody>
      <?php if ($users->num_rows > 0): while ($u = $users->fetch_assoc()): ?>
      <tr>
        <td style="color:var(--muted)"><?= $u['id'] ?></td>
        <td style="color:#fff;font-weight:700;">
          <?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?>
          <?php if ($u['id'] == $_SESSION['user_id']): ?>
          <span style="font-size:0.55rem;color:var(--cyan);margin-left:4px;">(you)</span>
          <?php endif; ?>
        </td>
        <td style="font-size:0.68rem;color:var(--muted);"><?= htmlspecialchars($u['email']) ?></td>
        <td><span class="badge badge-<?= $u['role'] ?>"><?= ucfirst($u['role']) ?></span></td>
        <td><span class="badge badge-<?= $u['status'] ?>"><?= ucfirst($u['status']) ?></span></td>
        <td style="font-size:0.65rem;color:var(--muted);"><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
        <td>
          <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">


            <?php if ($u['id'] != $_SESSION['user_id']): ?>
            <form method="POST" style="display:inline-flex;align-items:center;gap:4px;">
              <input type="hidden" name="action" value="change_role">
              <input type="hidden" name="id" value="<?= $u['id'] ?>">
              <select name="role" class="form-control" style="width:auto;padding:4px 8px;font-size:0.6rem;" onchange="this.form.submit()">
                <option value="operator" <?= $u['role']==='operator'?'selected':'' ?>>Operator</option>
              </select>
            </form>
            <button type="button" class="btn btn-danger btn-sm" onclick="showDeleteModal('user', <?= $u['id'] ?>, '<?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?>')">DELETE</button>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endwhile; else: ?>
      <tr><td colspan="7" style="text-align:center;padding:30px;color:var(--muted);">No users found.</td></tr>
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

<!-- Add User Modal -->
<div id="addModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.7);z-index:1000;align-items:center;justify-content:center;">
  <div style="background:var(--panel);border:1px solid var(--border);padding:24px;max-width:450px;width:90%;border-radius:8px;">
    <div style="font-family:var(--font-head);font-size:1.1rem;font-weight:800;color:#fff;margin-bottom:12px;">Add New User</div>
    <form method="POST" style="display:flex;flex-direction:column;gap:12px;">
      <input type="hidden" name="action" value="add">
      <div>
        <label style="font-size:.62rem;letter-spacing:2px;text-transform:uppercase;color:var(--muted);margin-bottom:4px;display:block;">First Name</label>
        <input type="text" name="first_name" required style="width:100%;background:rgba(5,13,26,0.8);border:1px solid var(--border);color:var(--text);font-family:var(--font-mono);font-size:.75rem;padding:8px 12px;">
      </div>
      <div>
        <label style="font-size:.62rem;letter-spacing:2px;text-transform:uppercase;color:var(--muted);margin-bottom:4px;display:block;">Last Name</label>
        <input type="text" name="last_name" required style="width:100%;background:rgba(5,13,26,0.8);border:1px solid var(--border);color:var(--text);font-family:var(--font-mono);font-size:.75rem;padding:8px 12px;">
      </div>
      <div>
        <label style="font-size:.62rem;letter-spacing:2px;text-transform:uppercase;color:var(--muted);margin-bottom:4px;display:block;">Email</label>
        <input type="email" name="email" required style="width:100%;background:rgba(5,13,26,0.8);border:1px solid var(--border);color:var(--text);font-family:var(--font-mono);font-size:.75rem;padding:8px 12px;">
      </div>
      <div>
        <label style="font-size:.62rem;letter-spacing:2px;text-transform:uppercase;color:var(--muted);margin-bottom:4px;display:block;">Role</label>
        <select name="role" style="width:100%;background:rgba(5,13,26,0.8);border:1px solid var(--border);color:var(--text);font-family:var(--font-mono);font-size:.75rem;padding:8px 12px;">
          <option value="operator">Operator</option>
        </select>
      </div>
      <div>
        <label style="font-size:.62rem;letter-spacing:2px;text-transform:uppercase;color:var(--muted);margin-bottom:4px;display:block;">Password</label>
        <input type="password" name="password" required minlength="8" style="width:100%;background:rgba(5,13,26,0.8);border:1px solid var(--border);color:var(--text);font-family:var(--font-mono);font-size:.75rem;padding:8px 12px;">
      </div>
      <div style="display:flex;gap:12px;justify-content:flex-end;margin-top:8px;">
        <button type="button" onclick="closeAddModal()" style="background:none;border:1px solid var(--border);color:var(--text);font-family:var(--font-mono);font-size:.65rem;padding:8px 16px;cursor:pointer;letter-spacing:1px;text-transform:uppercase;">Cancel</button>
        <button type="submit" style="background:var(--safe);border:1px solid var(--safe);color:#fff;font-family:var(--font-mono);font-size:.65rem;padding:8px 16px;cursor:pointer;letter-spacing:1px;text-transform:uppercase;">Add User</button>
      </div>
    </form>
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

function showAddModal() {
  document.getElementById('addModal').style.display = 'flex';
}

function closeAddModal() {
  document.getElementById('addModal').style.display = 'none';
}
</script>

<?php include '../includes/footer.php'; ?>
