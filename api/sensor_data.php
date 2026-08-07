<?php
// ============================================================
//  FloodWatch — Sensor Data API Endpoint
//  Accepts water level readings from ESP32 devices
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../includes/config.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed. Use POST.']);
    exit;
}

// Get JSON input
$jsonInput = file_get_contents('php://input');
$data = json_decode($jsonInput, true);

// Fallback to form data if JSON is empty
if (empty($data)) {
    $data = $_POST;
}

// Validate required fields
$requiredFields = ['sensor_code', 'water_level'];
foreach ($requiredFields as $field) {
    if (!isset($data[$field]) || $data[$field] === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => "Missing required field: $field"]);
        exit;
    }
}

$sensorCode = trim($data['sensor_code']);
$waterLevel = floatval($data['water_level']);
$timestamp = $data['timestamp'] ?? date('Y-m-d H:i:s');
$latitude = isset($data['latitude']) ? floatval($data['latitude']) : null;
$longitude = isset($data['longitude']) ? floatval($data['longitude']) : null;

// Validate water level range
if ($waterLevel < 0 || $waterLevel > 300) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid water level. Must be between 0-300 cm.']);
    exit;
}

// Find sensor by code
$stmt = $conn->prepare("SELECT id, purok_id, status FROM sensors WHERE sensor_code = ?");
$stmt->bind_param('s', $sensorCode);
$stmt->execute();
$result = $stmt->get_result();
$sensor = $result->fetch_assoc();
$stmt->close();

if (!$sensor) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Sensor not found. Invalid sensor_code.']);
    exit;
}

// Check if sensor is online
if ($sensor['status'] !== 'online') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Sensor is not online. Status: ' . $sensor['status']]);
    exit;
}

// Determine alert status based on water level
// Thresholds based on sensor physical readings:
// - Safe: < 70 cm (sensor tip to half underwater)
// - Warning: 70-100 cm (full sensor underwater)
// - Danger: 100-130 cm (beyond full sensor)
// - Critical: > 130 cm (extreme flood levels)
$alertStatus = 'safe';
if ($waterLevel >= 130) {
    $alertStatus = 'critical';
} elseif ($waterLevel >= 100) {
    $alertStatus = 'danger';
} elseif ($waterLevel >= 70) {
    $alertStatus = 'warning';
}

// Insert sensor reading with manual ID for TiDB compatibility
$result = $conn->query("SELECT MAX(id) as max_id FROM sensor_readings");
$row = $result->fetch_assoc();
$nextId = ($row['max_id'] ?? 0) + 1;

$stmt = $conn->prepare("INSERT INTO sensor_readings (id, sensor_id, water_level, alert_status, recorded_at) VALUES (?, ?, ?, ?, ?)");
$stmt->bind_param('iidss', $nextId, $sensor['id'], $waterLevel, $alertStatus, $timestamp);
$stmt->execute();
$readingId = $nextId;
$stmt->close();

// Update sensor GPS coordinates if provided
if ($latitude !== null && $longitude !== null) {
    $stmt = $conn->prepare("UPDATE sensors SET latitude = ?, longitude = ? WHERE id = ?");
    $stmt->bind_param('ddi', $latitude, $longitude, $sensor['id']);
    $stmt->execute();
    $stmt->close();
}

// Check if there's an existing unresolved alert for this sensor
$existingAlert = $conn->query("SELECT id FROM flood_alerts WHERE sensor_id = {$sensor['id']} AND is_resolved = 0 LIMIT 1")->fetch_assoc();

// Create or update flood alert if not safe
if ($alertStatus !== 'safe') {
    $messages = [
        'warning'  => 'Elevated water levels detected. Monitor the situation.',
        'danger'   => 'High flood risk. Prepare for possible evacuation.',
        'critical' => 'Extreme flood levels! EVACUATION IMMEDIATELY!'
    ];
    $message = $messages[$alertStatus];

    if ($existingAlert) {
        // Update existing alert
        $stmt = $conn->prepare("UPDATE flood_alerts SET alert_level = ?, water_level = ?, message = ?, triggered_at = ? WHERE id = ?");
        $stmt->bind_param('sdssi', $alertStatus, $waterLevel, $message, $timestamp, $existingAlert['id']);
        $stmt->execute();
        $stmt->close();
    } else {
        // Create new alert
        $sql = "INSERT INTO flood_alerts (sensor_id, purok_id, alert_level, water_level, message, triggered_at) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            $error = "Prepare failed: " . $conn->error . " | SQL: " . $sql;
            error_log($error);
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Database prepare failed', 'debug' => $conn->error]);
            exit;
        }
        $stmt->bind_param('iisdss', $sensor['id'], $sensor['purok_id'], $alertStatus, $waterLevel, $message, $timestamp);
        if (!$stmt->execute()) {
            $error = "Execute failed: " . $stmt->error;
            error_log($error);
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Database execute failed', 'debug' => $stmt->error]);
            exit;
        }
        $alertId = $stmt->insert_id;
        $stmt->close();

        // Send SMS alert to affected households
        require_once '../includes/sms.php';
        $smsResult = sendFloodAlertSMS($conn, $alertStatus, $waterLevel, [$sensor['purok_id']]);
    }
} elseif ($existingAlert && $alertStatus === 'safe') {
    // Auto-resolve alert if water level is now safe
    $stmt = $conn->prepare("UPDATE flood_alerts SET is_resolved = 1, resolved_at = ? WHERE id = ?");
    $stmt->bind_param('si', $timestamp, $existingAlert['id']);
    $stmt->execute();
    $stmt->close();
}

// Log the activity (system activity, no user)
logActivity($conn, null, 'SENSOR_DATA_RECEIVED', "Sensor $sensorCode: $waterLevel cm ($alertStatus)");

// Return success response
http_response_code(200);
echo json_encode([
    'status' => 'success',
    'message' => 'Sensor reading recorded successfully',
    'data' => [
        'sensor_code' => $sensorCode,
        'water_level' => $waterLevel,
        'alert_status' => $alertStatus,
        'timestamp' => $timestamp,
        'reading_id' => $readingId
    ]
]);
?>
