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
           sr.water_level, sr.alert_status, sr.recorded_at
    FROM sensors s
    JOIN puroks p ON s.purok_id = p.id
    LEFT JOIN (
        SELECT sensor_id, water_level, alert_status, recorded_at
        FROM sensor_readings
        WHERE (sensor_id, recorded_at) IN (
            SELECT sensor_id, MAX(recorded_at)
            FROM sensor_readings
            WHERE DATE(recorded_at) = CURDATE()
            GROUP BY sensor_id
        )
    ) sr ON sr.sensor_id = s.id
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
