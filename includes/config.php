<?php
// ============================================================
//  FloodWatch — Database Configuration
//  Brgy. Baliwagan, San Enrique, Negros Occidental
// ============================================================

// Database Configuration - Use environment variables for Docker/Render
define('DB_HOST', getenv('DB_HOST') );
define('DB_USER', getenv('DB_USER') );
define('DB_PASS', getenv('DB_PASS') );
define('DB_NAME', getenv('DB_NAME') );
define('DB_PORT', getenv('DB_PORT') );

define('SITE_NAME', 'FloodWatch');
define('SITE_LOCATION', 'Brgy. Baliwagan, San Enrique, Negros Occidental');

// ── Auto-detect base URL (works with any folder name/location) ──
$docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
$rootDir = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
$baseUrl = str_replace($docRoot, '', $rootDir);
if ($baseUrl === '') $baseUrl = '';
define('BASE_URL', $baseUrl);

// ── Redirect helper ──────────────────────────────────────────
function redirect($path) {
    header('Location: ' . BASE_URL . '/' . ltrim($path, '/'));
    exit;
}

// ── DB connection ────────────────────────────────────────────
// Initialize mysqli without connecting
$conn = mysqli_init();

// Enable SSL for TiDB Cloud with CA certificate
// Try multiple common CA certificate paths
$ca_paths = [
    '/etc/ssl/certs/ca-certificates.crt',  // Debian/Ubuntu
    '/etc/ssl/certs/ca-bundle.crt',        // RHEL/CentOS
    '/etc/pki/tls/certs/ca-bundle.crt',    // RHEL/CentOS
    '/usr/local/ssl/cert.pem',             // Some systems
];

$ca_path = null;
foreach ($ca_paths as $path) {
    if (file_exists($path)) {
        $ca_path = $path;
        break;
    }
}

// If no CA found, try without specific CA (may work with system defaults)
if ($ca_path) {
    $conn->ssl_set(null, null, $ca_path, null, null);
}

// Connect with SSL
mysqli_real_connect($conn, DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT, MYSQLI_CLIENT_SSL);

if ($conn->connect_error) {
    die('<!DOCTYPE html><html><head><meta charset="UTF-8">
    <style>
      body{background:#050d1a;color:#ff3355;font-family:monospace;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}
      .box{text-align:center;padding:40px;border:1px solid #ff3355;max-width:520px;}
      h2{margin-bottom:12px;}p{color:#c8dff0;font-size:0.85rem;margin-bottom:8px;}
      small{color:#4a6a8a;font-size:0.75rem;}
      .steps{text-align:left;margin-top:20px;font-size:0.78rem;color:#c8dff0;line-height:2.2;}
    </style></head><body>
    <div class="box">
      <h2>⚠ Database Connection Failed</h2>
      <p>Could not connect to database <strong>if0_42571082_floodwatch_db</strong>.</p>
      <small>' . htmlspecialchars($conn->connect_error) . '</small>
      <div class="steps">
        <strong style="color:#00e5ff">Fix steps:</strong><br>
        1. Check your Infinity Free database credentials<br>
        2. Verify database exists in Infinity Free panel<br>
        3. Ensure remote MySQL access is enabled<br>
        4. Contact Infinity Free support if needed
      </div>
    </div></body></html>');
}

$conn->set_charset('utf8mb4');

// ── Session helpers ──────────────────────────────────────────
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        redirect('index.php');
    }
}

function requireAdmin() {
    requireLogin();
    if ($_SESSION['role'] !== 'admin') {
        redirect('dashboard.php');
    }
}

function getCurrentUser($conn) {
    if (!isLoggedIn()) return null;
    $id = (int)$_SESSION['user_id'];
    $res = $conn->query("SELECT * FROM users WHERE id = $id");
    return $res ? $res->fetch_assoc() : null;
}

// ── Activity log helper ──────────────────────────────────────
function logActivity($conn, $user_id, $action, $details = '') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param('isss', $user_id, $action, $details, $ip);
        $stmt->execute();
        $stmt->close();
    }
}
?>
