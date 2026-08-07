<?php
// ============================================================
//  FloodWatch — Get Household Phone Numbers API
//  Returns phone numbers for households in a sensor's purok
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../includes/config.php';

// Only accept GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed. Use GET.']);
    exit;
}

// Get sensor_code from query parameter
$sensorCode = isset($_GET['sensor_code']) ? trim($_GET['sensor_code']) : '';

if (empty($sensorCode)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing sensor_code parameter']);
    exit;
}

// Find sensor by code and get its purok_id
$stmt = $conn->prepare("SELECT id, purok_id, status FROM sensors WHERE sensor_code = ?");
$stmt->bind_param('s', $sensorCode);
$stmt->execute();
$result = $stmt->get_result();
$sensor = $result->fetch_assoc();
$stmt->close();

if (!$sensor) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Sensor not found']);
    exit;
}

// Check if sensor is online
if ($sensor['status'] !== 'online') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Sensor is not online']);
    exit;
}

// Get household phone numbers for this purok
$stmt = $conn->prepare("
    SELECT h.id, h.head_of_household, h.contact_number
    FROM households h
    WHERE h.purok_id = ? 
    AND h.contact_number IS NOT NULL 
    AND h.contact_number != ''
    ORDER BY h.head_of_household
");
$stmt->bind_param('i', $sensor['purok_id']);
$stmt->execute();
$result = $stmt->get_result();

$households = [];
while ($row = $result->fetch_assoc()) {
    $households[] = [
        'id' => $row['id'],
        'head_of_household' => $row['head_of_household'],
        'contact_number' => $row['contact_number']
    ];
}
$stmt->close();

http_response_code(200);
echo json_encode([
    'status' => 'success',
    'data' => [
        'sensor_code' => $sensorCode,
        'purok_id' => $sensor['purok_id'],
        'households' => $households,
        'count' => count($households)
    ]
]);
?>
