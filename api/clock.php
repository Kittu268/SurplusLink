<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

function respond(int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Return current simulated clock
    $clock = $pdo->query('SELECT simulated_clock FROM app_state WHERE id = 1')->fetchColumn();
    respond(200, ['clock' => $clock]);
} 

elseif ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['new_time']) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $input['new_time'])) {
        respond(400, ['error' => 'Invalid format. Use YYYY-MM-DDTHH:MM']);
    }

    $newTime = $input['new_time'];
    $newTimeSql = str_replace('T', ' ', $newTime) . ':00';

    try {
        $pdo->beginTransaction();

        // 1. Get current clock to ensure we don't go backwards
        $currentClockSql = $pdo->query('SELECT simulated_clock FROM app_state WHERE id = 1 FOR UPDATE')->fetchColumn();
        
        if (strtotime($newTimeSql) < strtotime($currentClockSql)) {
            $pdo->rollBack();
            respond(422, ['error' => 'The clock can never move backwards.']);
        }

        // 2. Update the clock
        $stmt = $pdo->prepare('UPDATE app_state SET simulated_clock = ? WHERE id = 1');
        $stmt->execute([$newTimeSql]);

        // 3. The Magic Engine: Update ACTIVE claims to MISSED if the clock moved past pickup_until
        $updateClaims = $pdo->prepare("
            UPDATE claims c
            JOIN donations d ON c.donation_id = d.id
            SET c.status = 'MISSED'
            WHERE c.status = 'ACTIVE' 
            AND ? > d.pickup_until
        ");
        $updateClaims->execute([$newTimeSql]);
        $missedCount = $updateClaims->rowCount();

        $pdo->commit();

        respond(200, [
            'success' => true,
            'message' => 'Clock advanced.',
            'new_clock' => $newTime,
            'claims_marked_missed' => $missedCount
        ]);

    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Clock Update Error: ' . $e->getMessage());
        respond(500, ['error' => 'Internal server error while advancing clock.']);
    }
} else {
    header('Allow: GET, POST');
    respond(405, ['error' => 'Method not allowed.']);
}