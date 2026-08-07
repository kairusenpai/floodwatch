<?php
session_start();
require_once '../includes/config.php';
requireLogin();

$pageTitle  = 'Evacuation Planner';
$activePage = 'evacuation';

// ── Constants ─────────────────────────────────────────────────
// 1 rescuer per 3 households
// 1 rescue vehicle (boat/truck) per 10 households
// 3 food packs per household (3 meals/day per family assumed as 1 unit)
// 1 evacuation center worker per 20 households
define('RESCUERS_PER_HH',   3);    // 1 rescuer per N households
define('VEHICLES_PER_HH',   10);   // 1 vehicle per N households
define('FOODPACKS_PER_HH',  3);    // food packs per household per day
define('DAYS_DEFAULT',      3);    // default days of food supply

// ── Fetch active alerts with purok info ───────────────────────
$activeAlerts = $conn->query("
    SELECT DISTINCT fa.purok_id, p.name as purok_name, fa.alert_level,
           fa.water_level, fa.triggered_at,
           COUNT(h.id) as total_households,
           COALESCE(SUM(h.members_count), COUNT(h.id)) as total_members
    FROM flood_alerts fa
    JOIN puroks p ON fa.purok_id = p.id
    LEFT JOIN households h ON h.purok_id = fa.purok_id
    WHERE fa.is_resolved = 0
    GROUP BY fa.purok_id, p.name, fa.alert_level, fa.water_level, fa.triggered_at
    ORDER BY FIELD(fa.alert_level,'critical','danger','warning')
");

// ── Fetch all puroks with household data for manual planning ──
$allPuroks = $conn->query("
    SELECT p.id, p.name,
           COUNT(h.id) as total_households,
           COALESCE(SUM(h.members_count), COUNT(h.id)) as total_members,
           (SELECT alert_level FROM flood_alerts fa WHERE fa.purok_id=p.id AND fa.is_resolved=0 ORDER BY triggered_at DESC LIMIT 1) as current_alert,
           (SELECT water_level FROM flood_alerts fa WHERE fa.purok_id=p.id AND fa.is_resolved=0 ORDER BY triggered_at DESC LIMIT 1) as current_level
    FROM puroks p
    LEFT JOIN households h ON h.purok_id = p.id
    GROUP BY p.id, p.name
    ORDER BY p.id
");

// ── Summary totals ────────────────────────────────────────────
$totals = $conn->query("
    SELECT COUNT(DISTINCT h.id) as total_hh,
           COALESCE(SUM(h.members_count), COUNT(h.id)) as total_members
    FROM households h
    JOIN flood_alerts fa ON fa.purok_id = h.purok_id
    WHERE fa.is_resolved = 0
")->fetch_assoc();

$affectedHH      = (int)($totals['total_hh'] ?? 0);
$affectedMembers = (int)($totals['total_members'] ?? 0);
$days            = max(1, (int)($_GET['days'] ?? DAYS_DEFAULT));

include '../includes/header.php';
?>

<style>
@keyframes fadeInUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
@keyframes critPulse{0%,100%{opacity:1}50%{opacity:.6}}
@keyframes countUp{from{opacity:0;transform:scale(.8)}to{opacity:1;transform:scale(1)}}

.evac-page{animation:fadeInUp .4s ease both;}

/* Hero */
.evac-hero{
  background:linear-gradient(135deg,rgba(5,13,26,.98),rgba(10,22,40,.98));
  border:1px solid var(--border);padding:28px 32px;margin-bottom:20px;
  position:relative;overflow:hidden;
}
.evac-hero::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--safe),var(--blue),var(--cyan));}
.evac-title{font-family:var(--font-head);font-size:1.4rem;font-weight:800;color:#fff;margin-bottom:4px;}
.evac-sub{font-size:.68rem;color:var(--muted);letter-spacing:1px;}

/* Summary cards */
.summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px;}
.summary-card{background:var(--panel);border:1px solid var(--border);padding:18px 20px;position:relative;overflow:hidden;transition:border-color .3s;}
.summary-card::after{content:'';position:absolute;bottom:0;left:0;right:0;height:2px;transform:scaleX(0);transition:transform .3s;transform-origin:left;}
.summary-card:hover::after{transform:scaleX(1);}
.sc-label{font-size:.6rem;letter-spacing:2px;text-transform:uppercase;color:var(--muted);margin-bottom:8px;}
.sc-val{font-family:var(--font-head);font-size:2rem;font-weight:800;line-height:1;margin-bottom:4px;animation:countUp .5s ease both;}
.sc-unit{font-size:.65rem;color:var(--muted);}
.sc-sub{font-size:.62rem;margin-top:6px;}

/* Resource cards */
.resource-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px;}
.resource-card{background:var(--panel);border:1px solid var(--border);padding:24px 20px;position:relative;overflow:hidden;text-align:center;}
.resource-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;}
.rc-icon{font-size:2.8rem;margin-bottom:12px;display:block;}
.rc-title{font-size:.62rem;letter-spacing:2.5px;text-transform:uppercase;color:var(--muted);margin-bottom:10px;}
.rc-number{font-family:var(--font-head);font-size:3rem;font-weight:800;line-height:1;margin-bottom:6px;animation:countUp .6s ease both;}
.rc-unit{font-size:.72rem;color:var(--muted);margin-bottom:14px;}
.rc-breakdown{background:rgba(0,0,0,.3);border:1px solid var(--border);padding:10px 14px;text-align:left;font-size:.65rem;line-height:2;}
.rc-formula{font-size:.6rem;color:var(--muted);margin-top:10px;padding-top:10px;border-top:1px solid var(--border);}

/* Purok breakdown table */
.purok-breakdown{margin-bottom:20px;}

/* Alert badges */
.alert-lvl{display:inline-block;font-size:.58rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;padding:3px 10px;}
.lvl-warning {background:rgba(255,170,0,.12);color:#ffaa00;}
.lvl-danger  {background:rgba(255,102,0,.12);color:#ff6600;}
.lvl-critical{background:rgba(255,0,51,.15);color:#ff0033;animation:critPulse 1s infinite;}
.lvl-safe    {background:rgba(0,230,118,.1);color:var(--safe);}
.lvl-none    {background:rgba(74,106,138,.1);color:var(--muted);}

/* Days selector */
.days-selector{display:flex;align-items:center;gap:10px;margin-bottom:20px;flex-wrap:wrap;}
.days-btn{background:none;border:1px solid var(--border);color:var(--muted);font-family:var(--font-mono);font-size:.65rem;padding:6px 16px;cursor:pointer;transition:all .2s;text-decoration:none;letter-spacing:1px;}
.days-btn:hover,.days-btn.active{border-color:var(--cyan);color:var(--cyan);background:rgba(0,229,255,.06);}

/* Print button */
.print-btn{display:inline-flex;align-items:center;gap:8px;background:none;border:1px solid var(--cyan);color:var(--cyan);font-family:var(--font-mono);font-size:.65rem;padding:8px 18px;cursor:pointer;transition:all .2s;letter-spacing:1px;text-transform:uppercase;}
.print-btn:hover{background:var(--cyan);color:var(--bg);}

/* No alerts state */
.no-alerts{text-align:center;padding:48px 24px;color:var(--muted);}
.no-alerts-icon{font-size:3rem;display:block;margin-bottom:16px;}

@media print{
  .sidebar,.topbar,.days-selector,.print-btn,.evac-hero::after{display:none!important;}
  body{background:#fff;color:#000;}
  .evac-title,.rc-title,.sc-label{color:#333;}
  .sc-val,.rc-number{color:#000;}
  .resource-card,.summary-card,.purok-breakdown{border:1px solid #ccc;}
}
</style>

<div class="evac-page">

<!-- HERO -->
<div class="evac-hero">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div>
      <div class="evac-title">🚨 Evacuation Resource Planner</div>
      <div class="evac-sub">Auto-calculates rescuers, vehicles, and food packs based on affected households per purok</div>
    </div>
    <button class="print-btn" onclick="window.print()">🖨 Print / Export</button>
  </div>
</div>

<?php if ($affectedHH === 0): ?>
<!-- NO ACTIVE ALERTS -->
<div class="card no-alerts">
  <span class="no-alerts-icon">✅</span>
  <div style="font-family:var(--font-head);font-size:1.1rem;font-weight:700;color:#fff;margin-bottom:8px;">No Active Flood Alerts</div>
  <div style="font-size:.72rem;color:var(--muted);margin-bottom:20px;">All sensors are showing normal water levels. No evacuation resources are currently required.</div>
  <a href="<?=BASE_URL?>/dashboard.php" class="print-btn" style="display:inline-flex;text-decoration:none;">📡 Go to Dashboard</a>
</div>

<?php else: ?>

<!-- DAYS SELECTOR -->
<div class="days-selector">
  <span style="font-size:.65rem;color:var(--muted);letter-spacing:1px;">Food supply duration:</span>
  <?php foreach([1,3,5,7] as $d): ?>
  <a href="?days=<?=$d?>" class="days-btn <?=($days==$d)?'active':''?>"><?=$d?> Day<?=($d>1)?'s':''?></a>
  <?php endforeach; ?>
</div>

<!-- SUMMARY STATS -->
<div class="summary-grid">
  <div class="summary-card" style="border-color:rgba(255,0,51,.3);">
    <div class="sc-label">Affected Puroks</div>
    <div class="sc-val" style="color:#ff0033;"><?=$activeAlerts->num_rows?></div>
    <div class="sc-unit">puroks with active alerts</div>
    <div class="sc-sub" style="color:#ff6600;">⚠ Immediate action required</div>
    <div style="position:absolute;bottom:0;left:0;right:0;height:2px;background:#ff0033;transform:scaleX(1);"></div>
  </div>
  <div class="summary-card" style="border-color:rgba(255,170,0,.3);">
    <div class="sc-label">Affected Households</div>
    <div class="sc-val" style="color:#ffaa00;"><?=$affectedHH?></div>
    <div class="sc-unit">households at risk</div>
    <div class="sc-sub" style="color:var(--muted);"><?=$affectedMembers?> total members</div>
    <div style="position:absolute;bottom:0;left:0;right:0;height:2px;background:#ffaa00;transform:scaleX(1);"></div>
  </div>
  <div class="summary-card" style="border-color:rgba(0,170,255,.3);">
    <div class="sc-label">Total Rescuers Needed</div>
    <div class="sc-val" style="color:var(--blue);"><?=ceil($affectedHH/RESCUERS_PER_HH)?></div>
    <div class="sc-unit">rescue personnel</div>
    <div class="sc-sub" style="color:var(--muted);">1 per <?=RESCUERS_PER_HH?> households</div>
    <div style="position:absolute;bottom:0;left:0;right:0;height:2px;background:var(--blue);transform:scaleX(1);"></div>
  </div>
  <div class="summary-card" style="border-color:rgba(0,229,255,.3);">
    <div class="sc-label">Food Packs (<?=$days?> day<?=($days>1)?'s':''?>)</div>
    <div class="sc-val" style="color:var(--cyan);"><?=$affectedHH*FOODPACKS_PER_HH*$days?></div>
    <div class="sc-unit">food packs total</div>
    <div class="sc-sub" style="color:var(--muted);"><?=FOODPACKS_PER_HH?> packs/household/day</div>
    <div style="position:absolute;bottom:0;left:0;right:0;height:2px;background:var(--cyan);transform:scaleX(1);"></div>
  </div>
</div>

<!-- MAIN RESOURCE CARDS -->
<div style="font-size:.6rem;letter-spacing:2.5px;text-transform:uppercase;color:var(--muted);margin-bottom:12px;display:flex;align-items:center;gap:10px;">
  Required Resources — All Affected Puroks Combined
  <div style="flex:1;height:1px;background:var(--border);"></div>
</div>

<div class="resource-grid">

  <!-- RESCUERS -->
  <?php $totalRescuers = ceil($affectedHH / RESCUERS_PER_HH); ?>
  <div class="resource-card" style="border-color:rgba(0,170,255,.3);">
    <div class="resource-card::before" style="background:var(--blue);"></div>
    <div style="position:absolute;top:0;left:0;right:0;height:3px;background:var(--blue);"></div>
    <span class="rc-icon">🧑‍🚒</span>
    <div class="rc-title">Rescue Personnel</div>
    <div class="rc-number" style="color:var(--blue);"><?=$totalRescuers?></div>
    <div class="rc-unit">rescuers required</div>
    <div class="rc-breakdown">
      <?php
      $activeAlerts->data_seek(0);
      while($a=$activeAlerts->fetch_assoc()):
        $r=ceil($a['total_households']/RESCUERS_PER_HH);
      ?>
      <div style="display:flex;justify-content:space-between;padding:2px 0;border-bottom:1px solid rgba(26,48,80,.4);">
        <span style="color:#fff;"><?=htmlspecialchars($a['purok_name'])?></span>
        <span style="color:var(--blue);font-weight:700;"><?=$r?> rescuer<?=($r>1)?'s':''?></span>
      </div>
      <?php endwhile; ?>
    </div>
    <div class="rc-formula">Formula: households ÷ <?=RESCUERS_PER_HH?> = rescuers needed</div>
  </div>

  <!-- VEHICLES -->
  <?php $totalVehicles = ceil($affectedHH / VEHICLES_PER_HH); ?>
  <div class="resource-card" style="border-color:rgba(0,229,255,.3);">
    <div style="position:absolute;top:0;left:0;right:0;height:3px;background:var(--cyan);"></div>
    <span class="rc-icon">🚐</span>
    <div class="rc-title">Rescue Vehicles</div>
    <div class="rc-number" style="color:var(--cyan);"><?=$totalVehicles?></div>
    <div class="rc-unit">vehicles / boats needed</div>
    <div class="rc-breakdown">
      <?php $activeAlerts->data_seek(0); while($a=$activeAlerts->fetch_assoc()): $v=ceil($a['total_households']/VEHICLES_PER_HH); ?>
      <div style="display:flex;justify-content:space-between;padding:2px 0;border-bottom:1px solid rgba(26,48,80,.4);">
        <span style="color:#fff;"><?=htmlspecialchars($a['purok_name'])?></span>
        <span style="color:var(--cyan);font-weight:700;"><?=$v?> vehicle<?=($v>1)?'s':''?></span>
      </div>
      <?php endwhile; ?>
    </div>
    <div class="rc-formula">Formula: households ÷ <?=VEHICLES_PER_HH?> = vehicles needed</div>
  </div>

  <!-- FOOD PACKS -->
  <?php $totalFood = $affectedHH * FOODPACKS_PER_HH * $days; ?>
  <div class="resource-card" style="border-color:rgba(0,230,118,.3);">
    <div style="position:absolute;top:0;left:0;right:0;height:3px;background:var(--safe);"></div>
    <span class="rc-icon">🍱</span>
    <div class="rc-title">Food Packs (<?=$days?> Day<?=($days>1)?'s':''?>)</div>
    <div class="rc-number" style="color:var(--safe);"><?=$totalFood?></div>
    <div class="rc-unit">food packs needed</div>
    <div class="rc-breakdown">
      <?php $activeAlerts->data_seek(0); while($a=$activeAlerts->fetch_assoc()): $f=$a['total_households']*FOODPACKS_PER_HH*$days; ?>
      <div style="display:flex;justify-content:space-between;padding:2px 0;border-bottom:1px solid rgba(26,48,80,.4);">
        <span style="color:#fff;"><?=htmlspecialchars($a['purok_name'])?></span>
        <span style="color:var(--safe);font-weight:700;"><?=$f?> packs</span>
      </div>
      <?php endwhile; ?>
    </div>
    <div class="rc-formula">Formula: households × <?=FOODPACKS_PER_HH?> packs × <?=$days?> days</div>
  </div>

</div>

<!-- PER-PUROK DETAILED TABLE -->
<div style="font-size:.6rem;letter-spacing:2.5px;text-transform:uppercase;color:var(--muted);margin-bottom:12px;display:flex;align-items:center;gap:10px;">
  Per-Purok Breakdown
  <div style="flex:1;height:1px;background:var(--border);"></div>
</div>

<div class="card purok-breakdown">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Purok</th>
          <th>Alert Level</th>
          <th>Water Level</th>
          <th>Households</th>
          <th>Members</th>
          <th style="color:var(--blue);">🧑‍🚒 Rescuers</th>
          <th style="color:var(--cyan);">🚐 Vehicles</th>
          <th style="color:var(--safe);">🍱 Food Packs</th>
          <th>Triggered</th>
        </tr>
      </thead>
      <tbody>
      <?php
      $activeAlerts->data_seek(0);
      $grandRescuers=$grandVehicles=$grandFood=$grandHH=$grandMembers=0;
      while($a=$activeAlerts->fetch_assoc()):
        $hh  = (int)$a['total_households'];
        $mem = (int)$a['total_members'];
        $r   = ceil($hh/RESCUERS_PER_HH);
        $v   = ceil($hh/VEHICLES_PER_HH);
        $f   = $hh*FOODPACKS_PER_HH*$days;
        $grandRescuers+=$r; $grandVehicles+=$v; $grandFood+=$f; $grandHH+=$hh; $grandMembers+=$mem;
        $lvl = $a['alert_level'];
        $lvlColors = ['warning'=>'#ffaa00','danger'=>'#ff6600','critical'=>'#ff0033'];
        $lc = $lvlColors[$lvl]??'#00e676';
      ?>
      <tr>
        <td style="font-weight:700;color:#fff;"><?=htmlspecialchars($a['purok_name'])?></td>
        <td><span class="alert-lvl lvl-<?=$lvl?>"><?=ucfirst($lvl)?></span></td>
        <td style="color:<?=$lc?>;font-weight:700;"><?=$a['water_level']?> cm</td>
        <td style="text-align:center;font-weight:700;color:#fff;"><?=$hh?></td>
        <td style="text-align:center;color:var(--muted);"><?=$mem?></td>
        <td style="text-align:center;font-weight:700;color:var(--blue);"><?=$r?></td>
        <td style="text-align:center;font-weight:700;color:var(--cyan);"><?=$v?></td>
        <td style="text-align:center;font-weight:700;color:var(--safe);"><?=$f?></td>
        <td style="font-size:.62rem;color:var(--muted);"><?=date('M d, H:i',strtotime($a['triggered_at']))?></td>
      </tr>
      <?php endwhile; ?>
      </tbody>
      <tfoot>
        <tr style="border-top:2px solid var(--border);">
          <td colspan="3" style="font-weight:700;color:#fff;padding:10px 12px;font-size:.72rem;letter-spacing:1px;">TOTAL</td>
          <td style="text-align:center;font-weight:700;color:#ffaa00;font-size:.85rem;"><?=$grandHH?></td>
          <td style="text-align:center;font-weight:700;color:var(--muted);"><?=$grandMembers?></td>
          <td style="text-align:center;font-weight:700;color:var(--blue);font-size:.85rem;"><?=$grandRescuers?></td>
          <td style="text-align:center;font-weight:700;color:var(--cyan);font-size:.85rem;"><?=$grandVehicles?></td>
          <td style="text-align:center;font-weight:700;color:var(--safe);font-size:.85rem;"><?=$grandFood?></td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<!-- ALL PUROKS REFERENCE TABLE -->
<div style="font-size:.6rem;letter-spacing:2.5px;text-transform:uppercase;color:var(--muted);margin-bottom:12px;display:flex;align-items:center;gap:10px;">
  All Puroks Reference (if full evacuation needed)
  <div style="flex:1;height:1px;background:var(--border);"></div>
</div>

<div class="card">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Purok</th>
          <th>Current Status</th>
          <th>Total HH</th>
          <th>Members</th>
          <th style="color:var(--blue);">🧑‍🚒 Rescuers</th>
          <th style="color:var(--cyan);">🚐 Vehicles</th>
          <th style="color:var(--safe);">🍱 Food (<?=$days?>d)</th>
        </tr>
      </thead>
      <tbody>
      <?php while($p=$allPuroks->fetch_assoc()):
        $hh=(int)$p['total_households']; $mem=(int)$p['total_members'];
        $r=ceil($hh/RESCUERS_PER_HH); $v=ceil($hh/VEHICLES_PER_HH); $f=$hh*FOODPACKS_PER_HH*$days;
        $lvl=$p['current_alert']??'none';
        $lv=$p['current_level']??0;
        $lvlColors=['warning'=>'#ffaa00','danger'=>'#ff6600','critical'=>'#ff0033','none'=>'var(--muted)'];
        $lc=$lvlColors[$lvl]??'var(--muted)';
      ?>
      <tr>
        <td style="font-weight:700;color:#fff;"><?=htmlspecialchars($p['name'])?></td>
        <td>
          <?php if($lvl!=='none'): ?>
          <span class="alert-lvl lvl-<?=$lvl?>"><?=ucfirst($lvl)?></span>
          <span style="font-size:.62rem;color:<?=$lc?>;margin-left:6px;"><?=$lv?> cm</span>
          <?php else: ?>
          <span class="alert-lvl lvl-safe">Safe</span>
          <?php endif; ?>
        </td>
        <td style="text-align:center;font-weight:700;color:#fff;"><?=$hh?></td>
        <td style="text-align:center;color:var(--muted);"><?=$mem?></td>
        <td style="text-align:center;font-weight:700;color:var(--blue);"><?=$r?></td>
        <td style="text-align:center;font-weight:700;color:var(--cyan);"><?=$v?></td>
        <td style="text-align:center;font-weight:700;color:var(--safe);"><?=$f?></td>
      </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- COMPUTATION NOTES -->
<div style="background:rgba(0,170,255,.03);border:1px solid rgba(0,170,255,.15);padding:16px 20px;margin-bottom:20px;">
  <div style="font-size:.6rem;letter-spacing:2px;text-transform:uppercase;color:var(--blue);margin-bottom:10px;">📋 Computation Basis</div>
  <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;font-size:.68rem;color:var(--muted);line-height:1.9;">
    <div><strong style="color:var(--blue);">🧑‍🚒 Rescuers:</strong><br>1 rescuer per <?=RESCUERS_PER_HH?> households.<br>Rounded up to nearest whole number.<br>Based on standard DRRM guidelines.</div>
    <div><strong style="color:var(--cyan);">🚐 Vehicles:</strong><br>1 rescue vehicle/boat per <?=VEHICLES_PER_HH?> households.<br>Assumes standard capacity vehicle.<br>Rounded up to nearest whole number.</div>
    <div><strong style="color:var(--safe);">🍱 Food Packs:</strong><br><?=FOODPACKS_PER_HH?> packs per household per day.<br>Selected duration: <?=$days?> day<?=($days>1)?'s':''?>.<br>Each pack = 1 family meal serving.</div>
  </div>
</div>

<?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>
