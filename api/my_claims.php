<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

$volId =$_GET['id'] ?? '';

try {
    $stmt =$pdo->prepare("
        SELECT c.*, d.description 
        FROM claims c 
        JOIN donations d ON c.donation_id = d.id 
        WHERE c.volunteer_id = ?
        ORDER BY c.id DESC
    ");
    $stmt->execute([$volId]);
    
    echo json_encode(['claims' => $stmt->fetchAll()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
?>
