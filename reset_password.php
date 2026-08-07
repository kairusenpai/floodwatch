<?php
// ============================================================
//  FloodWatch — Admin Password Reset Utility
//  Run this ONCE to set the admin password, then DELETE it.
// ============================================================
require_once __DIR__ . '/includes/config.php';

$newPassword = 'Admin@2026';
$hashed = password_hash($newPassword, PASSWORD_BCRYPT);

$stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = 'admin@floodwatch.ph'");
$stmt->bind_param('s', $hashed);

if ($stmt->execute() && $stmt->affected_rows > 0) {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
    <style>body{background:#050d1a;color:#00e676;font-family:monospace;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}
    .box{text-align:center;padding:40px;border:1px solid #00e676;max-width:480px;}
    a{color:#00e5ff;}</style></head><body>
    <div class="box">
      <h2>✅ Password Reset Successful</h2>
      <p style="color:#c8dff0;margin:16px 0;">Admin password has been set to:<br>
      <strong style="color:#00e5ff;font-size:1.1rem;">Admin@2026</strong></p>
      <p style="color:#4a6a8a;font-size:0.75rem;margin-bottom:20px;">⚠ Delete this file (reset_password.php) after logging in!</p>
      <a href="' . BASE_URL . '/index.php">→ Go to Login</a>
    </div></body></html>';
} else {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
    <style>body{background:#050d1a;color:#ff3355;font-family:monospace;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}
    .box{text-align:center;padding:40px;border:1px solid #ff3355;max-width:480px;}</style></head><body>
    <div class="box">
      <h2>⚠ Reset Failed</h2>
      <p style="color:#c8dff0;">Could not find admin@floodwatch.ph in the database.<br>Make sure you have imported floodwatch_db.sql first.</p>
    </div></body></html>';
}
$stmt->close();
?>
