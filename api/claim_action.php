<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

function respond(int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['error' => 'Use POST']);
}

$input = json_decode(file_get_contents('php://input'), true);
$claimId = $input['claim_id'] ?? null;
$action = $input['action'] ?? null; // Must be 'COLLECT' or 'CANCEL'

if (!$claimId || !in_array($action, ['COLLECT', 'CANCEL'])) {
    respond(400, ['error' => 'Valid claim_id and action (COLLECT or CANCEL) required']);
}

try {
    $pdo->beginTransaction();

    // 1. Get current clock
    $clock = $pdo->query('SELECT simulated_clock FROM app_state WHERE id = 1')->fetchColumn();
    $nowTs = strtotime($clock);

    // 2. Lock the specific claim and get donation times
    $stmt = $pdo->prepare("
        SELECT c.*, d.pickup_from, d.pickup_until 
        FROM claims c 
        JOIN donations d ON c.donation_id = d.id 
        WHERE c.id = ? FOR UPDATE
    ");
    $stmt->execute([$claimId]);
    $claim = $stmt->fetch();

    if (!$claim) {
        $pdo->rollBack();
        respond(404, ['error' => 'Claim not found']);
    }
    
    if ($claim['status'] !== 'ACTIVE') {
        $pdo->rollBack();
        respond(422, ['error' => 'Only ACTIVE claims can be modified. Current status: ' . $claim['status']]);
    }

    // 3. Process COLLECT
    if ($action === 'COLLECT') {
        $fromTs = strtotime($claim['pickup_from']);
        $untilTs = strtotime($claim['pickup_until']);
        
        if ($nowTs >= $fromTs && $nowTs <= $untilTs) {
            $pdo->prepare("UPDATE claims SET status = 'COLLECTED' WHERE id = ?")->execute([$claimId]);
        } else {
            $pdo->rollBack();
            respond(422, ['error' => 'Can only collect within the donation pickup window.']);
        }
    } 
    
    // 4. Process CANCEL
    elseif ($action === 'CANCEL') {
        $plannedTs = strtotime($claim['planned_at']);
        
        if ($nowTs < $plannedTs) {
            $pdo->prepare("UPDATE claims SET status = 'CANCELLED' WHERE id = ?")->execute([$claimId]);
        } else {
            $pdo->rollBack();
            respond(422, ['error' => 'It is too late to cancel. Clock has passed planned pickup time.']);
        }
    }

    $pdo->commit();
    respond(200, [
        'success' => true, 
        'message' => "Claim successfully marked as $action."
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Claim Action Error: ' . $e->getMessage());
    respond(500, ['error' => 'Internal server error.']);
}