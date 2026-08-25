<?php
// ============================================================
//  FloodWatch — Public Sensor Data API
//  Returns sensor data for public display (no authentication)
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../includes/config.php';

// Fetch public sensor data — every registered sensor, not just those
// currently marked 'online', so a sensor that goes silent mid-event
// still shows its last known reading (flagged stale) instead of
// disappearing from the public view entirely.
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
    ORDER BY p.id
");

if ($publicSensors === false) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Could not fetch sensor data']);
    exit;
}

// Readings older than this are flagged stale rather than hidden,
// so the frontend can show a "no recent data" warning instead of
// silently showing an old level as if it were current.
const STALE_THRESHOLD_SECONDS = 3600; // 1 hour

$sensorData = [];
while ($r = $publicSensors->fetch_assoc()) {
    $recordedAt = $r['recorded_at'];
    $isStale = $recordedAt === null || (time() - strtotime($recordedAt)) > STALE_THRESHOLD_SECONDS;

    $sensorData[] = [
        'sensor_code'  => $r['sensor_code'],
        'purok'        => $r['purok'],
        'water_level'  => round($r['water_level'] ?? 0, 1),
        'alert_status' => $r['alert_status'] ?? 'safe',
        'recorded_at'  => $recordedAt,
        'is_stale'     => $isStale
    ];
}

echo json_encode([
    'status' => 'success',
    'data' => $sensorData,
    'timestamp' => date('Y-m-d H:i:s')
]);
?>