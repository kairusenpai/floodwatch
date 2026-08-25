<?php
session_start();
require_once __DIR__ . '/includes/config.php';

// Already logged in → redirect to dashboard
if (isLoggedIn()) {
    redirect('dashboard.php');
}

$loginError = '';

// ── Fetch public sensor data ─────────────────────────────────────
$publicSensors = $conn->query("
    SELECT s.id, s.sensor_code, s.purok_id, p.name as purok,
           sr.water_level, sr.alert_status, sr.recorded_at
    FROM sensors s
    JOIN puroks p ON s.purok_id = p.id
    LEFT JOIN (
        SELECT sensor_id, water_level, alert_status, recorded_at,
               ROW_NUMBER() OVER (PARTITION BY sensor_id ORDER BY recorded_at DESC, id DESC) as rn
        FROM sensor_readings
    ) sr ON sr.sensor_id = s.id AND sr.rn = 1
    WHERE s.status = 'online'
    ORDER BY p.id
");
$publicSensorData = [];
while ($r = $publicSensors->fetch_assoc()) $publicSensorData[] = $r;

// ── Handle Login ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email && $password) {
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if (!$user) {
            $loginError = 'No account found with that email address.';
        } elseif (!password_verify($password, $user['password'])) {
            $loginError = 'Incorrect password. Please try again.';
        } else {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role']    = $user['role'];
            $_SESSION['name']    = $user['first_name'] . ' ' . $user['last_name'];
            logActivity($conn, $user['id'], 'LOGIN', 'User logged in from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            redirect('dashboard.php');
        }
    } else {
        $loginError = 'Please fill in all fields.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>FloodWatch — Access Portal</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=Space+Mono:ital,wght@0,400;0,700;1,400&display=swap" rel="stylesheet">
<style>
:root {
  --bg:#050d1a; --panel:#0a1628; --panel2:#0d1e35; --border:#1a3050; --border-hover:#2a4a70;
  --blue:#00aaff; --cyan:#00e5ff; --warn:#ffaa00; --danger:#ff3355; --safe:#00e676;
  --text:#fff; --muted:#fff;
  --font-head:'Syne',sans-serif; --font-mono:'Space Mono',monospace;
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
body{background:var(--bg);color:var(--text);font-family:var(--font-mono);min-height:100vh;display:flex;align-items:stretch;justify-content:center;overflow-y:auto;overflow-x:hidden;position:relative;}
.bg-grid{position:fixed;inset:0;background-image:linear-gradient(rgba(0,170,255,0.04) 1px,transparent 1px),linear-gradient(90deg,rgba(0,170,255,0.04) 1px,transparent 1px);background-size:40px 40px;animation:gridMove 20s linear infinite;pointer-events:none;}
@keyframes gridMove{from{background-position:0 0}to{background-position:40px 40px}}
.bg-rain{position:fixed;inset:0;pointer-events:none;overflow:hidden;}
.raindrop{position:absolute;width:1px;background:linear-gradient(transparent,rgba(0,170,255,0.3));animation:fall linear infinite;border-radius:1px;}
@keyframes fall{from{transform:translateY(-100px);opacity:0}10%{opacity:1}90%{opacity:0.4}to{transform:translateY(110vh);opacity:0}}
.orb{position:fixed;border-radius:50%;filter:blur(80px);pointer-events:none;opacity:0.12;}
.orb-1{width:400px;height:400px;background:var(--cyan);top:-100px;left:-100px;animation:orbFloat 8s ease-in-out infinite;}
.orb-2{width:300px;height:300px;background:var(--blue);bottom:-80px;right:-80px;animation:orbFloat 10s ease-in-out infinite reverse;}
@keyframes orbFloat{0%,100%{transform:translate(0,0)}50%{transform:translate(20px,30px)}}
.scanlines{position:fixed;inset:0;background:repeating-linear-gradient(0deg,transparent,transparent 2px,rgba(0,0,0,0.12) 2px,rgba(0,0,0,0.12) 4px);pointer-events:none;z-index:100;}
.page{position:relative;z-index:10;width:100%;max-width:980px;min-height:100vh;display:grid;grid-template-columns:1fr 1fr;gap:0;align-items:stretch;}
.left-panel{display:flex;flex-direction:column;justify-content:center;padding:60px 50px;border-right:1px solid var(--border);position:sticky;top:0;height:100vh;overflow:hidden;}
.left-panel::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:linear-gradient(180deg,transparent,var(--cyan),transparent);animation:scanV 4s ease-in-out infinite;}
@keyframes scanV{0%,100%{opacity:0.3}50%{opacity:1}}
.brand{margin-bottom:48px;}
.brand-logo{font-family:var(--font-head);font-size:2.4rem;font-weight:800;color:#fff;letter-spacing:-1px;line-height:1;margin-bottom:6px;}
.brand-logo span{color:var(--cyan);}
.brand-sub{font-size:0.65rem;letter-spacing:3px;text-transform:uppercase;color:var(--muted);}
.status-board{display:flex;flex-direction:column;gap:12px;}
.status-row{display:flex;align-items:center;gap:12px;font-size:0.72rem;color:var(--muted);padding:10px 14px;border:1px solid var(--border);background:rgba(10,22,40,0.6);transition:border-color 0.3s;}
.status-row:hover{border-color:var(--border-hover);}
.status-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0;}
.dot-green{background:var(--safe);box-shadow:0 0 8px var(--safe);animation:pulse-dot 2s infinite;}
.dot-yellow{background:var(--warn);box-shadow:0 0 8px var(--warn);animation:pulse-dot 1.5s infinite;}
.dot-blue{background:var(--blue);box-shadow:0 0 8px var(--blue);animation:pulse-dot 3s infinite;}
@keyframes pulse-dot{0%,100%{opacity:1}50%{opacity:0.4}}
.status-label{color:var(--text);flex:1;}
.status-val{color:var(--cyan);font-size:0.65rem;}
.footer-note{margin-top:40px;font-size:0.62rem;color:var(--muted);line-height:1.8;}
.right-panel{display:flex;flex-direction:column;justify-content:center;padding:48px 50px;background:rgba(10,22,40,0.5);}
.tabs{display:flex;gap:0;margin-bottom:16px;border-bottom:1px solid var(--border);}
.tab{padding:10px 24px;font-family:var(--font-mono);font-size:0.72rem;letter-spacing:2px;text-transform:uppercase;color:var(--muted);cursor:pointer;border:none;background:none;border-bottom:2px solid transparent;margin-bottom:-1px;transition:all 0.25s;}
.tab:hover{color:var(--text);}
.tab.active{color:var(--cyan);border-bottom-color:var(--cyan);}
.form-view{display:none;animation:fadeUp 0.35s ease both;}
.form-view.active{display:block;}
@keyframes fadeUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
.form-title{font-family:var(--font-head);font-size:1.3rem;font-weight:700;color:#fff;margin-bottom:2px;}
.form-desc{font-size:0.63rem;color:var(--muted);margin-bottom:12px;line-height:1.5;}
.field-group{display:flex;flex-direction:column;gap:8px;margin-bottom:12px;}
.field{display:flex;flex-direction:column;gap:3px;}
.field-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
label{font-size:0.62rem;letter-spacing:2px;text-transform:uppercase;color:var(--muted);}
.input-wrap{position:relative;display:flex;align-items:center;}
.input-icon{position:absolute;left:12px;font-size:0.8rem;pointer-events:none;opacity:0.5;}
input[type="text"],input[type="email"],input[type="password"],select{width:100%;background:rgba(5,13,26,0.8);border:1px solid var(--border);color:var(--text);font-family:var(--font-mono);font-size:0.75rem;padding:9px 12px 9px 36px;outline:none;transition:border-color 0.25s;border-radius:0;-webkit-appearance:none;}
input:focus,select:focus{border-color:var(--cyan);box-shadow:0 0 0 3px rgba(0,229,255,0.08);}
input.error{border-color:var(--danger);}
input.success{border-color:var(--safe);}
input::placeholder{color:var(--muted);font-size:0.72rem;}
select option{background:var(--panel);}
.field-error{font-size:0.62rem;color:var(--danger);margin-top:2px;display:none;}
.field-error.visible{display:block;}
.strength-bar{display:flex;gap:3px;margin-top:4px;}
.strength-seg{height:3px;flex:1;background:var(--border);transition:background 0.3s;}
.strength-label{font-size:0.6rem;color:var(--muted);margin-top:2px;}
.checkbox-row{display:flex;align-items:flex-start;gap:8px;font-size:0.65rem;color:var(--muted);cursor:pointer;line-height:1.4;}
.checkbox-row input[type="checkbox"]{width:16px;height:16px;min-width:16px;padding:0;border:1px solid var(--border);background:var(--bg);cursor:pointer;accent-color:var(--cyan);margin-top:2px;}
.checkbox-row a{color:var(--cyan);text-decoration:none;}
.btn-submit{width:100%;padding:13px;background:transparent;border:1px solid var(--cyan);color:var(--cyan);font-family:var(--font-mono);font-size:0.78rem;font-weight:700;letter-spacing:3px;text-transform:uppercase;cursor:pointer;position:relative;overflow:hidden;transition:all 0.3s;margin-top:20px;}
.btn-submit::before{content:'';position:absolute;inset:0;background:var(--cyan);transform:translateX(-101%);transition:transform 0.3s ease;z-index:0;}
.btn-submit:hover::before{transform:translateX(0);}
.btn-submit:hover{color:var(--bg);box-shadow:0 0 20px rgba(0,229,255,0.3);}
.btn-submit span{position:relative;z-index:1;}
.btn-submit.loading{pointer-events:none;opacity:0.7;}
.divider{display:flex;align-items:center;gap:12px;margin:20px 0;font-size:0.62rem;color:var(--muted);letter-spacing:2px;}
.divider::before,.divider::after{content:'';flex:1;height:1px;background:var(--border);}
.forgot-link{font-size:0.65rem;color:var(--muted);text-align:right;margin-top:-8px;}
.forgot-link a{color:var(--blue);text-decoration:none;}
.forgot-link a:hover{color:var(--cyan);}
.php-alert{padding:10px 14px;font-size:0.7rem;border:1px solid;margin-bottom:14px;display:flex;align-items:center;gap:8px;}
.php-alert-err{border-color:var(--danger);background:rgba(255,51,85,0.08);color:var(--danger);}
.php-alert-ok{border-color:var(--safe);background:rgba(0,230,118,0.07);color:var(--safe);}
@media(max-width:720px){.page{grid-template-columns:1fr}.left-panel{display:none}.right-panel{padding:40px 28px}}
</style>
</head>
<body>
<div class="bg-grid"></div>
<div class="bg-rain" id="rain"></div>
<div class="orb orb-1"></div>
<div class="orb orb-2"></div>
<div class="scanlines"></div>

<div class="page">
  <!-- LEFT PANEL -->
  <div class="left-panel">
    <div class="brand">
      <div class="brand-logo">Flood<span>Watch</span></div>
      <div class="brand-sub">Brgy. Baliwagan Early Warning Network</div>
    </div>
    <div class="status-board">
      <div class="status-row"><div class="status-dot dot-green"></div><span class="status-label">Sensor Network</span><span class="status-val">4 / 4 ONLINE</span></div>
      <div class="status-row"><div class="status-dot dot-green"></div><span class="status-label">Alert System</span><span class="status-val">ACTIVE</span></div>
      <div class="status-row"><div class="status-dot dot-green"></div><span class="status-label">Server Status</span><span class="status-val">99.9% UPTIME</span></div>
    </div>

    <!-- Public Sensor Monitor -->
    <div style="margin-top:32px;">
      <div style="font-size:0.65rem;letter-spacing:2.5px;text-transform:uppercase;color:var(--muted);margin-bottom:12px;">Live Water Level Monitor</div>
      <div style="display:flex;flex-direction:column;gap:10px;" id="sensor-container">
        <?php foreach($publicSensorData as $sensor): ?>
          <?php
            $level = $sensor['water_level'] ?? 0;
            $status = $sensor['alert_status'] ?? 'safe';
            // Simplified status for public - less alarming
            $publicStatus = 'Normal';
            $statusColor = 'var(--safe)';
            $statusIcon = '💧';
            if ($status === 'warning') {
              $publicStatus = 'Elevated';
              $statusColor = 'var(--warn)';
              $statusIcon = '⚠️';
            } elseif ($status === 'danger') {
              $publicStatus = 'High';
              $statusColor = '#ff6600';
              $statusIcon = '🔶';
            } elseif ($status === 'critical') {
              $publicStatus = 'Very High';
              $statusColor = 'var(--danger)';
              $statusIcon = '🔴';
            }
            $barWidth = min(100, ($level / 150) * 100);
          ?>
          <div style="display:flex;flex-direction:column;gap:8px;padding:14px 16px;border:1px solid var(--border);background:rgba(10,22,40,0.6);border-radius:4px;">
            <div style="display:flex;align-items:center;gap:12px;">
              <div style="width:10px;height:10px;border-radius:50%;background:<?=$statusColor?>;box-shadow:0 0 8px <?=$statusColor?>;"></div>
              <div style="flex:1;">
                <div style="font-weight:700;color:#fff;font-size:0.7rem;"><?=htmlspecialchars($sensor['purok'])?></div>
                <div style="font-size:0.58rem;color:var(--muted);"><?=round($level)?> cm • <?=$publicStatus?></div>
              </div>
              <div style="font-size:1.2rem;"><?=$statusIcon?></div>
            </div>
            <div style="background:rgba(0,0,0,0.3);height:6px;border-radius:3px;overflow:hidden;">
              <div style="width:<?=$barWidth?>%;height:100%;background:<?=$statusColor?>;transition:width 0.5s ease;"></div>
            </div>
            <?php if ($sensor['recorded_at']): ?>
            <div style="font-size:0.55rem;color:var(--muted);text-align:right;"><?=date('H:i',strtotime($sensor['recorded_at']))?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <div style="margin-top:12px;font-size:0.58rem;color:var(--muted);text-align:center;">
        <span id="public-refresh">Auto-refreshing in 30s</span>
      </div>
    </div>
    <div class="footer-note">
      Authorized personnel only.<br>
      Unauthorized access is monitored and prosecuted.<br>
      © 2026 FloodWatch Brgy. Baliwagan Operations
    </div>
  </div>

  <!-- RIGHT PANEL -->
  <div class="right-panel">
    <div class="form-title">Access Portal</div>
    <div class="form-desc">Sign in to monitor live flood sensor data and manage alerts.</div>

    <?php if ($loginError): ?>
    <div class="php-alert php-alert-err">⚠ <?= htmlspecialchars($loginError) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
      <input type="hidden" name="action" value="login">
      <div class="field-group">
        <div class="field">
          <label for="login-email">Email Address</label>
          <div class="input-wrap">
            <span class="input-icon">@</span>
            <input type="email" id="login-email" name="email" placeholder="operator@floodwatch.ph" autocomplete="email" required>
          </div>
        </div>
        <div class="field">
          <label for="login-password">Password</label>
          <div class="input-wrap">
            <span class="input-icon">🔒</span>
            <input type="password" id="login-password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
          </div>
        </div>
      </div>
      <button type="submit" class="btn-submit"><span>Authenticate</span></button>
    </form>

    <div style="margin-top:20px;font-size:0.65rem;color:var(--muted);text-align:center">
      Contact your administrator to request account access.
    </div>
  </div>
</div>

<script>
(function(){
  const rain=document.getElementById('rain');
  for(let i=0;i<60;i++){
    const d=document.createElement('div'); d.className='raindrop';
    d.style.left=Math.random()*100+'vw';
    d.style.height=(40+Math.random()*80)+'px';
    d.style.animationDuration=(1.5+Math.random()*3)+'s';
    d.style.animationDelay=(Math.random()*4)+'s';
    d.style.opacity=0.2+Math.random()*0.4;
    rain.appendChild(d);
  }
})();

// Auto-refresh public sensor data every 30 seconds using AJAX
let publicCountdown = 30;
const publicRefreshEl = document.getElementById('public-refresh');
const sensorContainer = document.getElementById('sensor-container');

function getPublicStatus(status) {
  if (status === 'warning') return { label: 'Elevated', color: 'var(--warn)', icon: '⚠️' };
  if (status === 'danger') return { label: 'High', color: '#ff6600', icon: '🔶' };
  if (status === 'critical') return { label: 'Very High', color: 'var(--danger)', icon: '🔴' };
  return { label: 'Normal', color: 'var(--safe)', icon: '💧' };
}

function formatTime(dateStr) {
  if (!dateStr) return '';
  const d = new Date(dateStr);
  // Convert to PH time (UTC+8)
  const phTime = new Date(d.getTime() + (8 * 60 * 60 * 1000) + (d.getTimezoneOffset() * 60 * 1000));
  return phTime.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: false });
}

function updateSensorDisplay(data) {
  if (!sensorContainer) return;
  
  let html = '';
  data.forEach(sensor => {
    const level = sensor.water_level || 0;
    const statusInfo = getPublicStatus(sensor.alert_status);
    const barWidth = Math.min(100, (level / 150) * 100);
    
    html += `
      <div style="display:flex;flex-direction:column;gap:8px;padding:14px 16px;border:1px solid var(--border);background:rgba(10,22,40,0.6);border-radius:4px;">
        <div style="display:flex;align-items:center;gap:12px;">
          <div style="width:10px;height:10px;border-radius:50%;background:${statusInfo.color};box-shadow:0 0 8px ${statusInfo.color};"></div>
          <div style="flex:1;">
            <div style="font-weight:700;color:#fff;font-size:0.7rem;">${sensor.purok}</div>
            <div style="font-size:0.58rem;color:var(--muted);">${Math.round(level)} cm • ${statusInfo.label}</div>
          </div>
          <div style="font-size:1.2rem;">${statusInfo.icon}</div>
        </div>
        <div style="background:rgba(0,0,0,0.3);height:6px;border-radius:3px;overflow:hidden;">
          <div style="width:${barWidth}%;height:100%;background:${statusInfo.color};transition:width 0.5s ease;"></div>
        </div>
        ${sensor.recorded_at ? `<div style="font-size:0.55rem;color:var(--muted);text-align:right;">${formatTime(sensor.recorded_at)}</div>` : ''}
      </div>
    `;
  });
  
  sensorContainer.innerHTML = html;
}

function fetchSensorData() {
  fetch('api/public_sensor_data.php')
    .then(response => response.json())
    .then(result => {
      if (result.status === 'success') {
        updateSensorDisplay(result.data);
      }
    })
    .catch(err => console.error('Failed to fetch sensor data:', err));
}

if (publicRefreshEl && sensorContainer) {
  setInterval(() => {
    publicCountdown--;
    publicRefreshEl.textContent = `Auto-refreshing in ${publicCountdown}s`;
    if (publicCountdown <= 0) {
      publicCountdown = 30;
      fetchSensorData();
    }
  }, 1000);
}
</script>
</body>
</html>
