<?php
// ============================================================
//  FloodWatch — Public Sensor Data API
//  Returns sensor data for public display (no authentication)
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../includes/config.php';

// Fetch public sensor data
$publicSensors = $conn->query("
    SELECT s.id, s.sensor_code, s.purok_id, p.name as purok,
           sr.water_level, sr.alert_status, strftime('%Y-%m-%d %H:%M:%S', sr.recorded_at) as recorded_at
    FROM sensors s
    JOIN puroks p ON s.purok_id = p.id
    LEFT JOIN sensor_readings sr ON sr.sensor_id = s.id
    AND sr.recorded_at = (SELECT MAX(recorded_at) FROM sensor_readings WHERE sensor_id = s.id AND DATE(recorded_at) = CURDATE())
    WHERE s.status = 'online' AND (sr.recorded_at IS NULL OR DATE(sr.recorded_at) = CURDATE())
    ORDER BY p.id
");

$sensorData = [];
while ($r = $publicSensors->fetch_assoc()) {
    $sensorData[] = [
        'purok' => $r['purok'],
        'water_level' => round($r['water_level'] ?? 0, 1),
        'alert_status' => $r['alert_status'] ?? 'safe',
        'recorded_at' => $r['recorded_at']
    ];
}

echo json_encode([
    'status' => 'success',
    'data' => $sensorData,
    'timestamp' => date('Y-m-d H:i:s')
]);
?>
