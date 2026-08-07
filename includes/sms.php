<?php
// ============================================================
//  FloodWatch — Textbelt SMS Service
//  Brgy. Baliwagan, San Enrique, Negros Occidental
//  Free: 1 SMS/day using key 'textbelt'
//  Paid: Get key at textbelt.com for more SMS
// ============================================================

define('TEXTBELT_API_KEY', 'textbelt'); // 'textbelt' = 1 free SMS per day
                                        // Replace with paid key from textbelt.com for unlimited
define('TEXTBELT_API_URL', 'https://textbelt.com/text');

/**
 * Normalize PH number to +639XXXXXXXXX (international format for Textbelt)
 */
function normalizePHNumber($number) {
    $number = preg_replace('/[^0-9]/', '', $number);
    if (strlen($number) === 11 && substr($number, 0, 2) === '09') {
        return '+63' . substr($number, 1); // 09XX → +639XX
    } elseif (strlen($number) === 10 && substr($number, 0, 1) === '9') {
        return '+63' . $number; // 9XX → +639XX
    } elseif (strlen($number) === 12 && substr($number, 0, 2) === '63') {
        return '+' . $number; // 63XX → +63XX
    } elseif (strlen($number) === 13 && substr($number, 0, 3) === '+63') {
        return $number; // already international
    }
    return '+63' . $number;
}

/**
 * Send a single SMS via Textbelt
 */
function sendSMS($number, $message) {
    $number = normalizePHNumber($number);

    $postData = http_build_query([
        'phone'   => $number,
        'message' => $message,
        'key'     => TEXTBELT_API_KEY,
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => TEXTBELT_API_URL,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'error' => 'Connection error: ' . $curlError];
    }

    $decoded = json_decode($response, true);

    // Textbelt returns: {"success":true,"quotaRemaining":0,"textId":"..."}
    // or {"success":false,"error":"Out of quota","quotaRemaining":0}
    if (isset($decoded['success']) && $decoded['success'] === true) {
        return [
            'success'        => true,
            'textId'         => $decoded['textId'] ?? '',
            'quotaRemaining' => $decoded['quotaRemaining'] ?? 0,
        ];
    }

    return [
        'success' => false,
        'error'   => $decoded['error'] ?? 'Unknown error',
        'quota'   => $decoded['quotaRemaining'] ?? 0,
    ];
}

/**
 * Send flood alert SMS to all households
 */
function sendFloodAlertSMS($conn, $level, $water_level, $purok_ids = []) {
    $messages = [
        'warning'  => "FLOODWATCH [WARNING] Brgy. Baliwagan: Water level at {$water_level}cm. Flooding possible. Monitor the situation and prepare. -FloodWatch EWS",
        'danger'   => "FLOODWATCH [DANGER] Brgy. Baliwagan: Water level at {$water_level}cm. HIGH FLOOD RISK. Prepare for evacuation. Move to higher ground now. -FloodWatch EWS",
        'critical' => "FLOODWATCH [CRITICAL] Brgy. Baliwagan: Water level at {$water_level}cm. EVACUATE IMMEDIATELY! Go to evacuation center. This is an emergency. -FloodWatch EWS",
    ];
    $message = $messages[$level] ?? $messages['warning'];

    $whereClause = '';
    if (!empty($purok_ids)) {
        $ids = implode(',', array_map('intval', $purok_ids));
        $whereClause = "AND h.purok_id IN ($ids)";
    }

    $result = $conn->query("
        SELECT h.id, h.head_of_household, h.contact_number, p.name as purok
        FROM households h
        JOIN puroks p ON h.purok_id = p.id
        WHERE h.contact_number IS NOT NULL AND h.contact_number != ''
        $whereClause
        ORDER BY p.name, h.head_of_household
    ");

    // Send only once per unique number to avoid duplicates
    $sentNumbers = [];
    $sent = $failed = $skipped = 0;
    $log  = [];
    $quotaExhausted = false;

    while ($h = $result->fetch_assoc()) {
        $normalized = normalizePHNumber($h['contact_number']);

        // Skip if quota already exhausted
        if ($quotaExhausted) {
            $skipped++;
            $log[] = [
                'household' => $h['head_of_household'],
                'purok'     => $h['purok'],
                'number'    => $h['contact_number'],
                'status'    => 'skipped',
                'reason'    => 'Daily quota exhausted',
            ];
            continue;
        }

        // Already sent to this number
        if (in_array($normalized, $sentNumbers)) {
            $sent++;
            $log[] = [
                'household' => $h['head_of_household'],
                'purok'     => $h['purok'],
                'number'    => $h['contact_number'],
                'status'    => 'sent',
                'reason'    => 'Same number — 1 SMS delivered',
            ];
            continue;
        }

        $res = sendSMS($normalized, $message);
        $sentNumbers[] = $normalized;

        if ($res['success']) {
            $sent++;
            $log[] = [
                'household' => $h['head_of_household'],
                'purok'     => $h['purok'],
                'number'    => $h['contact_number'],
                'status'    => 'sent',
                'quota'     => $res['quotaRemaining'] ?? '',
            ];
            // Check if quota is now 0
            if (isset($res['quotaRemaining']) && $res['quotaRemaining'] <= 0) {
                $quotaExhausted = true;
            }
        } else {
            // Check if it's a quota error
            if (isset($res['error']) && stripos($res['error'], 'quota') !== false) {
                $quotaExhausted = true;
                $skipped++;
                $log[] = [
                    'household' => $h['head_of_household'],
                    'purok'     => $h['purok'],
                    'number'    => $h['contact_number'],
                    'status'    => 'skipped',
                    'reason'    => 'Daily free quota exhausted (1 SMS/day limit)',
                ];
            } else {
                $failed++;
                $log[] = [
                    'household' => $h['head_of_household'],
                    'purok'     => $h['purok'],
                    'number'    => $h['contact_number'],
                    'status'    => 'failed',
                    'reason'    => $res['error'] ?? 'Unknown error',
                ];
            }
        }

        usleep(500000); // 0.5s between sends
    }

    $total   = $sent + $failed + $skipped;
    $summary = "SMS [$level] → $sent sent, $failed failed, $skipped skipped out of $total households";
    $conn->query("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (
        " . (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 'NULL') . ",
        'SMS_ALERT',
        '" . $conn->real_escape_string($summary) . "',
        '" . $conn->real_escape_string($_SERVER['REMOTE_ADDR'] ?? 'system') . "'
    )");

    return compact('sent', 'failed', 'skipped', 'total', 'log');
}
?>
