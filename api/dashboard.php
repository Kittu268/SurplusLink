<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

try {
    // 1. Get the current simulated clock
    $clock = $pdo->query('SELECT simulated_clock FROM app_state WHERE id = 1')->fetchColumn();
    $nowTs = strtotime($clock);

    // 2. Fetch all active donations (NOT cancelled)
    $donations = $pdo->query('SELECT * FROM donations WHERE cancelled = 0')->fetchAll();
    
    // 3. Initialize our math buckets
    $stats = [
        'Listed' => 0,
        'Collected' => 0,
        'Missed' => 0,
        'Wasted' => 0,
        'In_progress' => 0,
        'Still_open' => 0,
    ];

    $donor_stats = []; 
    $vol_stats = [];   

    // Setup arrays for donors and volunteers so everyone shows up even if they have 0
    $donors = $pdo->query('SELECT id, name FROM donors')->fetchAll();
    foreach ($donors as $d) $donor_stats[$d['id']] = ['name' => $d['name'], 'collected_kg' => 0];

    $vols = $pdo->query('SELECT id, name FROM volunteers')->fetchAll();
    foreach ($vols as $v) $vol_stats[$v['id']] = ['name' => $v['name'], 'collected_kg' => 0, 'missed_claims_count' => 0];

    // 4. Calculate everything
    foreach ($donations as $don) {
        $stats['Listed'] += $don['quantity_kg'];
        $is_expired = $nowTs > strtotime($don['pickup_until']);

        // Get claims for this donation
        $stmt = $pdo->prepare("SELECT * FROM claims WHERE donation_id = ? AND status != 'CANCELLED'");
        $stmt->execute([$don['id']]);
        $claims = $stmt->fetchAll();

        $claimed_kg = 0;
        foreach ($claims as $c) {
            $claimed_kg += $c['quantity_kg'];
            
            if ($c['status'] === 'COLLECTED') {
                $stats['Collected'] += $c['quantity_kg'];
                $donor_stats[$don['donor_id']]['collected_kg'] += $c['quantity_kg'];
                $vol_stats[$c['volunteer_id']]['collected_kg'] += $c['quantity_kg'];
            } 
            elseif ($c['status'] === 'MISSED') {
                $stats['Missed'] += $c['quantity_kg'];
                // Requirement: "number of MISSED claims" (not kg)
                $vol_stats[$c['volunteer_id']]['missed_claims_count'] += 1; 
            }
            elseif ($c['status'] === 'ACTIVE') {
                $stats['In_progress'] += $c['quantity_kg'];
            }
        }

        $remaining_kg = $don['quantity_kg'] - $claimed_kg;

        // Wasted vs Still Open
        if ($is_expired) {
            $stats['Wasted'] += $remaining_kg;
        } else {
            $stats['Still_open'] += $remaining_kg;
        }
    }

    // Requirement: Show whether Listed equals the sum of the other five
    $sum = $stats['Collected'] + $stats['Missed'] + $stats['Wasted'] + $stats['In_progress'] + $stats['Still_open'];
    $stats['Math_Is_Valid'] = ($stats['Listed'] === $sum);

    echo json_encode([
        'success' => true,
        'current_clock' => $clock,
        'global_stats' => $stats,
        'donor_totals' => array_values($donor_stats),
        'volunteer_totals' => array_values($vol_stats)
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
