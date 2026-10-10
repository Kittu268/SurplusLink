<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

function respond($status, $body) { http_response_code($status); echo json_encode($body); exit; }

$method = $_SERVER['REQUEST_METHOD'];
$clock = $pdo->query('SELECT simulated_clock FROM app_state WHERE id = 1')->fetchColumn();
$nowTs = strtotime($clock);

if ($method === 'GET') {
    $role = $_GET['role'] ?? ''; 
    $id = $_GET['id'] ?? '';

    // DONOR VIEW: See their own donations
    if ($role === 'donor') {
        $stmt = $pdo->prepare("SELECT * FROM donations WHERE donor_id = ?");
        $stmt->execute([$id]);
        respond(200, ['donations' => $stmt->fetchAll()]);
    } 
    
    // VOLUNTEER VIEW: See OPEN donations in their zones
    elseif ($role === 'volunteer') {
        $stmt = $pdo->prepare("SELECT zones FROM volunteers WHERE id = ?");
        $stmt->execute([$id]);
        $v = $stmt->fetch();
        if (!$v) respond(404, ['error' => 'Volunteer not found']);
        
        $zones = json_decode($v['zones'], true);
        $placeholders = implode(',', array_fill(0, count($zones), '?'));
        
        // OPEN rules: Not cancelled, time is valid, remaining_kg > 0
        $query = "
            SELECT d.*, 
            (d.quantity_kg - COALESCE((SELECT SUM(quantity_kg) FROM claims WHERE donation_id = d.id AND status != 'CANCELLED'), 0)) as remaining_kg,
            dn.zone
            FROM donations d
            JOIN donors dn ON d.donor_id = dn.id
            WHERE d.cancelled = 0 
            AND ? <= d.pickup_until
            AND dn.zone IN ($placeholders)
            HAVING remaining_kg > 0
        ";
        $params = array_merge([$clock], $zones);
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        respond(200, ['donations' => $stmt->fetchAll()]);
    }
    respond(400, ['error' => 'Invalid role']);
} 

// DONOR POSTING NEW DONATION
elseif ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $fromTs = strtotime($input['pickup_from']);
    $untilTs = strtotime($input['pickup_until']);
    
    // Problem Statement Rules for posting:
    if ($fromTs >= $untilTs) respond(422, ['error' => 'pickup_from must be before pickup_until']);
    if ($untilTs < $nowTs) respond(422, ['error' => 'pickup_until cannot be in the past']);
    if ($input['quantity_kg'] < 1) respond(422, ['error' => 'quantity_kg must be at least 1']);
    
    // Generate a quick unique ID (e.g., DN123456)
    $newId = 'DN' . substr(time(), -6); 
    
    $stmt = $pdo->prepare("INSERT INTO donations (id, donor_id, description, quantity_kg, pickup_from, pickup_until) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$newId, $input['donor_id'], $input['description'], $input['quantity_kg'], $input['pickup_from'], $input['pickup_until']]);
    
    respond(201, ['success' => true, 'id' => $newId]);
}