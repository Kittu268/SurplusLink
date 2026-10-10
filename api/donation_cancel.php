<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

$input = json_decode(file_get_contents('php://input'), true);
$donationId = $input['donation_id'] ?? null;

if (!$donationId) {
    http_response_code(400); echo json_encode(['error' => 'donation_id required']); exit;
}

try {
    $pdo->beginTransaction();
    
    // Check if there are any blocking claims
    $stmt = $pdo->prepare("SELECT id FROM claims WHERE donation_id = ? AND status IN ('ACTIVE', 'COLLECTED')");
    $stmt->execute([$donationId]);
    
    if ($stmt->rowCount() > 0) {
        $pdo->rollBack();
        http_response_code(422);
        echo json_encode(['error' => 'Cannot cancel: Donation has ACTIVE or COLLECTED claims.']);
        exit;
    }

    // Cancel it
    $stmt = $pdo->prepare("UPDATE donations SET cancelled = 1 WHERE id = ?");
    $stmt->execute([$donationId]);
    
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Donation cancelled.']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500); echo json_encode(['error' => 'Server error']);
}