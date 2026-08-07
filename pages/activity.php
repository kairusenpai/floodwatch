<?php
session_start();
require_once '../includes/config.php';
requireAdmin();

$pageTitle  = 'Activity Logs';
$activePage = 'activity';

$search = trim($_GET['search'] ?? '');
$where  = '1=1';
if ($search) $where .= " AND (al.action LIKE '%" . $conn->real_escape_string($search) . "%' OR al.details LIKE '%" . $conn->real_escape_string($search) . "%')";

$logs = $conn->query("
    SELECT al.*, CONCAT(u.first_name,' ',u.last_name) as user_name, u.role
    FROM activity_logs al
    LEFT JOIN users u ON al.user_id = u.id
    WHERE $where
    ORDER BY al.created_at DESC
    LIMIT 200
");

include '../includes/header.php';
?>

<form method="GET" style="display:flex;gap:8px;margin-bottom:16px;">
  <input type="text" name="search" class="form-control" placeholder="Search actions or details..." value="<?= htmlspecialchars($search) ?>" style="max-width:320px;">
  <button type="submit" class="btn">Search</button>
  <?php if ($search): ?><a href="?" class="btn">Clear</a><?php endif; ?>
</form>

<div class="card">
  <div class="card-title">
    <span>📜 System Activity Log</span>
    <span style="font-size:0.65rem;color:var(--muted)"><?= $logs->num_rows ?> recent entries</span>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>#</th><th>User</th><th>Action</th><th>Details</th><th>IP Address</th><th>Timestamp</th></tr>
      </thead>
      <tbody>
      <?php if ($logs->num_rows > 0): while ($l = $logs->fetch_assoc()):
        $actionColors = [
          'LOGIN'=>'#00e676','LOGOUT'=>'#00aaff','REGISTER'=>'#ffaa00',
          'APPROVE_USER'=>'#00e676','REJECT_USER'=>'#ff3355','DELETE_USER'=>'#ff3355',
          'RESOLVE_ALERT'=>'#00e676','ADD_INCIDENT'=>'#00aaff',
          'UPDATE_SENSOR'=>'#ffaa00','DELETE_INCIDENT'=>'#ff3355',
        ];
        $acolor = $actionColors[$l['action']] ?? 'var(--muted)';
      ?>
      <tr>
        <td style="color:var(--muted)"><?= $l['id'] ?></td>
        <td>
          <div style="color:#fff;font-weight:700;font-size:0.72rem;"><?= $l['user_name'] ? htmlspecialchars($l['user_name']) : '<span style="color:var(--muted)">System</span>' ?></div>
          <?php if ($l['role']): ?><span class="badge badge-<?= $l['role'] ?>" style="margin-top:2px;"><?= ucfirst($l['role']) ?></span><?php endif; ?>
        </td>
        <td><span style="font-size:0.65rem;letter-spacing:1px;color:<?= $acolor ?>;font-weight:700;"><?= htmlspecialchars($l['action']) ?></span></td>
        <td style="font-size:0.65rem;color:var(--muted);max-width:240px;"><?= $l['details'] ? htmlspecialchars($l['details']) : '—' ?></td>
        <td style="font-size:0.62rem;color:var(--muted);"><?= htmlspecialchars($l['ip_address'] ?? '—') ?></td>
        <td style="font-size:0.62rem;color:var(--muted);white-space:nowrap;"><?= date('M d, Y H:i:s', strtotime($l['created_at'])) ?></td>
      </tr>
      <?php endwhile; else: ?>
      <tr><td colspan="6" style="text-align:center;padding:30px;color:var(--muted);">No activity logs found.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include '../includes/footer.php'; ?>
