<?php
// ── Shared HTML head + nav for all authenticated pages ───────
$pageTitle = $pageTitle ?? 'FloodWatch';
$activePage = $activePage ?? '';
$user = getCurrentUser($conn);

// Prevent caching of authenticated pages to prevent back button access after logout
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?> — FloodWatch</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Syne:wght@400;600;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css"/>
<style>
:root {
  --bg: #050d1a;
  --panel: #0a1628;
  --panel2: #0d1e35;
  --border: #1a3050;
  --blue: #00aaff;
  --cyan: #00e5ff;
  --warn: #ffaa00;
  --danger: #ff3355;
  --safe: #00e676;
  --text: #c8dff0;
  --muted: #4a6a8a;
  --font-head: 'Syne', sans-serif;
  --font-mono: 'Space Mono', monospace;
}
* { margin:0; padding:0; box-sizing:border-box; }
body {
  background: var(--bg);
  color: var(--text);
  font-family: var(--font-mono);
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  overflow-x: hidden;
}
body::before {
  content:'';
  position:fixed; inset:0;
  background-image: repeating-linear-gradient(180deg,transparent 0px,transparent 3px,rgba(0,170,255,0.03) 3px,rgba(0,170,255,0.03) 4px);
  background-size:100% 8px;
  animation: rain 0.5s linear infinite;
  pointer-events:none; z-index:0;
}
body::after {
  content:'';
  position:fixed; inset:0;
  background: repeating-linear-gradient(0deg,transparent,transparent 2px,rgba(0,0,0,0.15) 2px,rgba(0,0,0,0.15) 4px);
  pointer-events:none; z-index:0;
}
@keyframes rain { from{background-position:0 0} to{background-position:0 8px} }

/* ── SIDEBAR ── */
.sidebar {
  position: fixed;
  top: 0; left: 0;
  width: 240px; height: 100vh;
  background: var(--panel);
  border-right: 1px solid var(--border);
  display: flex; flex-direction: column;
  z-index: 100;
  overflow: hidden;
}
.sidebar::before {
  content:'';
  position:absolute; left:0; top:0; bottom:0; width:3px;
  background: linear-gradient(180deg, transparent, var(--cyan), transparent);
  animation: scanV 4s ease-in-out infinite;
}
@keyframes scanV { 0%,100%{opacity:0.3} 50%{opacity:1} }

.sidebar-logo {
  padding: 22px 20px 18px;
  border-bottom: 1px solid var(--border);
}
.sidebar-logo .logo-text {
  font-family: var(--font-head);
  font-size: 1.4rem;
  font-weight: 800;
  color: #fff;
  letter-spacing: -0.5px;
}
.sidebar-logo .logo-text span { color: var(--cyan); }
.sidebar-logo .logo-sub {
  font-size: 0.55rem;
  color: var(--muted);
  letter-spacing: 1.5px;
  text-transform: uppercase;
  margin-top: 2px;
}

.sidebar-nav {
  flex: 1;
  padding: 12px 0;
  overflow-y: auto;
}
.nav-section-label {
  font-size: 0.55rem;
  letter-spacing: 2.5px;
  text-transform: uppercase;
  color: var(--muted);
  padding: 10px 20px 4px;
}
.nav-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 20px;
  font-size: 0.7rem;
  letter-spacing: 1px;
  color: var(--muted);
  text-decoration: none;
  text-transform: uppercase;
  transition: all 0.2s;
  border-left: 3px solid transparent;
  position: relative;
}
.nav-item:hover { color: var(--text); background: rgba(0,170,255,0.05); }
.nav-item.active {
  color: var(--cyan);
  border-left-color: var(--cyan);
  background: rgba(0,229,255,0.06);
}
.nav-icon { font-size: 0.9rem; width: 18px; text-align: center; }

.sidebar-user {
  padding: 14px 20px;
  border-top: 1px solid var(--border);
  font-size: 0.65rem;
}
.user-name { color: #fff; font-weight: 700; margin-bottom: 2px; }
.user-role {
  display: inline-block;
  font-size: 0.55rem;
  letter-spacing: 1.5px;
  text-transform: uppercase;
  padding: 2px 6px;
  margin-bottom: 8px;
}
.role-admin { background: rgba(0,229,255,0.12); color: var(--cyan); }
.role-operator { background: rgba(0,170,255,0.1); color: var(--blue); }
.btn-logout {
  display: block; width: 100%;
  background: none;
  border: 1px solid #ff3355;
  color: #ff3355;
  font-family: var(--font-mono);
  font-size: 0.6rem;
  font-weight: 700;
  padding: 6px;
  cursor: pointer;
  letter-spacing: 1.5px;
  text-transform: uppercase;
  text-align: center;
  text-decoration: none;
  transition: all 0.2s;
}
.btn-logout:hover { background: rgba(255,51,85,0.12); }

/* ── MAIN CONTENT ── */
.main {
  margin-left: 240px;
  flex: 1;
  position: relative;
  z-index: 1;
  display: flex;
  flex-direction: column;
}
.topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 28px;
  border-bottom: 1px solid var(--border);
  background: var(--panel);
  position: sticky; top: 0; z-index: 50;
}
.topbar-title {
  font-family: var(--font-head);
  font-size: 1rem;
  font-weight: 800;
  color: #fff;
}
.topbar-meta {
  display: flex;
  align-items: center;
  gap: 20px;
  font-size: 0.7rem;
  color: #fff;
}
.live-badge {
  display: flex; align-items: center; gap: 6px;
  color: var(--safe); font-weight: 700; font-size: 0.7rem; letter-spacing: 1px;
}
.live-dot {
  width:7px; height:7px; border-radius:50%;
  background: var(--safe); box-shadow: 0 0 8px var(--safe);
  animation: pulse-dot 1.2s ease-in-out infinite;
}
@keyframes pulse-dot { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:0.4;transform:scale(0.7)} }
#clock { color: #fff; }
#live-date { color: #fff; }

.content { padding: 24px 28px; flex: 1; }

/* ── CARDS ── */
.card {
  background: var(--panel);
  border: 1px solid var(--border);
  padding: 20px;
  margin-bottom: 20px;
}
.card-title {
  font-family: var(--font-head);
  font-size: 0.75rem;
  font-weight: 600;
  letter-spacing: 3px;
  text-transform: uppercase;
  color: var(--muted);
  margin-bottom: 16px;
  padding-bottom: 10px;
  border-bottom: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between;
}

/* ── STATS GRID ── */
.stats-grid {
  display: grid;
  grid-template-columns: repeat(4,1fr);
  gap: 16px;
  margin-bottom: 20px;
}
.stat-card {
  background: var(--panel);
  border: 1px solid var(--border);
  padding: 18px 20px;
  position: relative; overflow: hidden;
}
.stat-card::after {
  content:''; position:absolute; bottom:0; left:0; right:0; height:2px;
  background: var(--blue); transform:scaleX(0); transition:transform 0.3s; transform-origin:left;
}
.stat-card:hover::after { transform:scaleX(1); }
.stat-label { font-size:0.65rem; color:#fff; letter-spacing:2px; text-transform:uppercase; margin-bottom:8px; }
.stat-value { font-family:var(--font-head); font-size:2rem; font-weight:800; color:#fff; line-height:1; margin-bottom:4px; }
.stat-unit { font-size:0.7rem; color:#fff; }
.stat-sub { font-size:0.68rem; margin-top:8px; color:#fff; }

/* ── TABLE ── */
.table-wrap { overflow-x: auto; }
table { width:100%; border-collapse:collapse; font-size:0.72rem; }
th {
  text-align:left; padding:10px 12px;
  font-size:0.6rem; letter-spacing:2px; text-transform:uppercase;
  color: var(--muted); border-bottom:1px solid var(--border);
  font-weight:400;
}
td { padding:10px 12px; border-bottom:1px solid rgba(26,48,80,0.4); vertical-align:middle; }
tr:hover td { background: rgba(0,170,255,0.03); }
tr:last-child td { border-bottom:none; }

/* ── BADGES ── */
.badge {
  display:inline-block; font-size:0.58rem; letter-spacing:1.5px;
  text-transform:uppercase; padding:3px 8px; font-weight:700;
}
.badge-safe { background:rgba(0,230,118,0.12); color:var(--safe); }
.badge-warning { background:rgba(255,170,0,0.12); color:var(--warn); }
.badge-danger { background:rgba(255,51,85,0.12); color:var(--danger); }
.badge-critical { background:rgba(255,51,85,0.25); color:#ff0044; }
.badge-admin { background:rgba(0,229,255,0.12); color:var(--cyan); }
.badge-operator { background:rgba(0,170,255,0.1); color:var(--blue); }
.badge-pending { background:rgba(255,170,0,0.1); color:var(--warn); }
.badge-approved { background:rgba(0,230,118,0.1); color:var(--safe); }
.badge-rejected { background:rgba(255,51,85,0.1); color:var(--danger); }
.badge-online { background:rgba(0,230,118,0.1); color:var(--safe); }
.badge-offline { background:rgba(255,51,85,0.1); color:var(--danger); }
.badge-open { background:rgba(255,170,0,0.1); color:var(--warn); }
.badge-ongoing { background:rgba(0,170,255,0.1); color:var(--blue); }
.badge-resolved { background:rgba(0,230,118,0.1); color:var(--safe); }

/* ── BUTTONS ── */
.btn {
  display:inline-block; background:none;
  border:1px solid var(--border); color:var(--text);
  font-family:var(--font-mono); font-size:0.65rem;
  padding:7px 14px; cursor:pointer; letter-spacing:1px;
  text-transform:uppercase; transition:all 0.2s; text-decoration:none;
}
.btn:hover { border-color:var(--cyan); color:var(--cyan); background:rgba(0,229,255,0.05); }
.btn-primary { border-color:var(--cyan); color:var(--cyan); }
.btn-primary:hover { background:var(--cyan); color:var(--bg); }
.btn-danger { border-color:var(--danger); color:var(--danger); }
.btn-danger:hover { background:var(--danger); color:#fff; }
.btn-success { border-color:var(--safe); color:var(--safe); }
.btn-success:hover { background:var(--safe); color:var(--bg); }
.btn-sm { padding:4px 10px; font-size:0.58rem; }

/* ── FORM ── */
.form-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
.form-group { display:flex; flex-direction:column; gap:4px; margin-bottom:14px; }
.form-label { font-size:0.6rem; letter-spacing:2px; text-transform:uppercase; color:var(--muted); }
.form-control {
  background:rgba(5,13,26,0.8); border:1px solid var(--border);
  color:var(--text); font-family:var(--font-mono); font-size:0.75rem;
  padding:9px 12px; outline:none; transition:border-color 0.25s;
  width:100%;
}
.form-control:focus { border-color:var(--cyan); }
select.form-control { -webkit-appearance:none; }
textarea.form-control { resize:vertical; min-height:80px; }

/* ── ALERTS ── */
.alert {
  padding:10px 16px; font-size:0.72rem;
  border:1px solid; margin-bottom:16px; display:flex; align-items:center; gap:10px;
}
.alert-success { border-color:var(--safe); background:rgba(0,230,118,0.06); color:var(--safe); }
.alert-danger { border-color:var(--danger); background:rgba(255,51,85,0.06); color:var(--danger); }
.alert-warning { border-color:var(--warn); background:rgba(255,170,0,0.06); color:var(--warn); }

/* ── PAGE FOOTER ── */
.page-footer {
  text-align:center; padding:14px;
  border-top:1px solid var(--border);
  font-size:0.62rem; color:var(--muted);
  letter-spacing:2px; text-transform:uppercase;
  background: var(--panel);
  position:relative; z-index:1;
}

/* ── MAP Z-INDEX FIX ── */
.leaflet-pane,
.leaflet-control,
.leaflet-popup {
  z-index: 10 !important;
}
#baliwagan-map {
  position: relative;
  z-index: 10;
}
</style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar">
  <div class="sidebar-logo">
    <div class="logo-text">Flood<span>Watch</span></div>
    <div class="logo-sub">Brgy. Baliwagan EWS</div>
  </div>

  <nav class="sidebar-nav">
    <div class="nav-section-label">Monitor</div>
    <a href="<?= BASE_URL ?>/dashboard.php" class="nav-item <?= $activePage==='dashboard' ? 'active' : '' ?>">
      Dashboard
    </a>

    <div class="nav-section-label">Management</div>
    <a href="<?= BASE_URL ?>/pages/incidents.php" class="nav-item <?= $activePage==='incidents' ? 'active' : '' ?>">
      Incident Logs
    </a>
    <a href="<?= BASE_URL ?>/pages/alert_preview.php" class="nav-item <?= $activePage==='alert_preview' ? 'active' : '' ?>">
      Alert Levels
    </a>
    <a href="<?= BASE_URL ?>/pages/alerts.php" class="nav-item <?= $activePage==='alerts' ? 'active' : '' ?>">
      Flood Alerts
    </a>
    <a href="<?= BASE_URL ?>/pages/sensors.php" class="nav-item <?= $activePage==='sensors' ? 'active' : '' ?>">
      Sensors
    </a>
    <a href="<?= BASE_URL ?>/pages/households.php" class="nav-item <?= $activePage==='households' ? 'active' : '' ?>">
      Households
    </a>
    <a href="<?= BASE_URL ?>/pages/evacuation.php" class="nav-item <?= $activePage==='evacuation' ? 'active' : '' ?>">
      Evacuation Planner
    </a>

    <?php if ($_SESSION['role'] === 'admin'): ?>
    <div class="nav-section-label">Admin</div>
    <a href="<?= BASE_URL ?>/pages/users.php" class="nav-item <?= $activePage==='users' ? 'active' : '' ?>">
      Users
    </a>
    <a href="<?= BASE_URL ?>/pages/activity.php" class="nav-item <?= $activePage==='activity' ? 'active' : '' ?>">
      Activity Logs
    </a>
    <?php endif; ?>
  </nav>

  <div class="sidebar-user">
    <div class="user-name"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></div>
    <span class="user-role role-<?= $user['role'] ?>"><?= ucfirst($user['role']) ?></span>
    <button type="button" class="btn-logout" onclick="showLogoutModal()">⏻ Logout</button>
  </div>
</aside>

<!-- MAIN -->
<div class="main">
  <div class="topbar">
    <div class="topbar-title"><?= htmlspecialchars($pageTitle) ?></div>
    <div class="topbar-meta">
      <div class="live-badge"><div class="live-dot"></div> LIVE</div>
      <span>BRGY. BALIWAGAN, SAN ENRIQUE, NEG. OCC.</span>
      <span id="live-date">---</span>
      <span id="clock">--:--:--</span>
    </div>
  </div>
  <div class="content">

<!-- Logout Confirmation Modal -->
<div id="logoutModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.7);z-index:1000;align-items:center;justify-content:center;">
  <div style="background:var(--panel);border:1px solid var(--border);padding:24px;max-width:400px;width:90%;border-radius:8px;">
    <div style="font-family:var(--font-head);font-size:1.1rem;font-weight:800;color:#fff;margin-bottom:12px;">Confirm Logout</div>
    <div style="font-size:.7rem;color:var(--muted);margin-bottom:20px;line-height:1.6;">Are you sure you want to log out of FloodWatch?</div>
    <div style="display:flex;gap:12px;justify-content:flex-end;">
      <button type="button" onclick="closeLogoutModal()" style="background:none;border:1px solid var(--border);color:var(--text);font-family:var(--font-mono);font-size:.65rem;padding:8px 16px;cursor:pointer;letter-spacing:1px;text-transform:uppercase;">Cancel</button>
      <a href="<?= BASE_URL ?>/logout.php" style="background:var(--danger);border:1px solid var(--danger);color:#fff;font-family:var(--font-mono);font-size:.65rem;padding:8px 16px;cursor:pointer;letter-spacing:1px;text-transform:uppercase;text-decoration:none;display:inline-block;">Logout</a>
    </div>
  </div>
</div>

<script>
function showLogoutModal() {
  document.getElementById('logoutModal').style.display = 'flex';
}

function closeLogoutModal() {
  document.getElementById('logoutModal').style.display = 'none';
}
</script>
