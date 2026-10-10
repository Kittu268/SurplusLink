<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';

function respond(int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, ['error' => 'Use POST with a JSON request body.']);
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    respond(400, ['error' => 'A valid JSON request body is required.']);
}

$donationId = $input['donation_id'] ?? null;
$volunteerId = $input['volunteer_id'] ?? null;
$kg = $input['quantity_kg'] ?? null;
$plannedAt = $input['planned_at'] ?? null;

// Invalid quantity is rejected separately.
if (
    !is_string($donationId) || $donationId === '' ||
    !is_string($volunteerId) || $volunteerId === '' ||
    !is_int($kg) || $kg < 1 ||
    !is_string($plannedAt) ||
    !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $plannedAt)
) {
    respond(422, [
        'error' => 'Invalid input. Quantity must be a whole number of at least 1 kg, and planned_at must use YYYY-MM-DDTHH:MM.'
    ]);
}

$plannedSql = str_replace('T', ' ', $plannedAt) . ':00';

try {
    $pdo->beginTransaction();

    // Lock the simulated clock so it cannot change during validation.
    $clock = $pdo->query(
        'SELECT simulated_clock FROM app_state WHERE id = 1 FOR UPDATE'
    )->fetchColumn();

    if (!$clock) {
        throw new RuntimeException('Simulated clock is not initialized.');
    }

    // Lock the volunteer row to serialize their competing claims.
    $stmt = $pdo->prepare(
        'SELECT * FROM volunteers WHERE id = ? FOR UPDATE'
    );
    $stmt->execute([$volunteerId]);
    $volunteer = $stmt->fetch();

    if (!$volunteer) {
        $pdo->rollBack();
        respond(404, ['error' => 'Volunteer not found.']);
    }

    // Lock the donation row to prevent concurrent overbooking.
    $stmt = $pdo->prepare(
        'SELECT * FROM donations WHERE id = ? FOR UPDATE'
    );
    $stmt->execute([$donationId]);
    $donation = $stmt->fetch();

    if (!$donation) {
        $pdo->rollBack();
        respond(404, ['error' => 'Donation not found.']);
    }

    $violations = [];

    $nowTs = strtotime($clock);
    $fromTs = strtotime($donation['pickup_from']);
    $untilTs = strtotime($donation['pickup_until']);
    $plannedTs = strtotime($plannedSql);

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(quantity_kg), 0)
         FROM claims
         WHERE donation_id = ? AND status <> 'CANCELLED'"
    );
    $stmt->execute([$donationId]);
    $claimedKg = (int)$stmt->fetchColumn();
    $remainingKg = (int)$donation['quantity_kg'] - $claimedKg;

    // Rule 1: donation must not be cancelled or expired.
    if ((int)$donation['cancelled'] === 1) {
        $violations[] = 'Donation is CANCELLED.';
    } elseif ($nowTs > $untilTs) {
        $violations[] = 'Donation is EXPIRED.';
    }

    // Rule 2: volunteer capacity.
    if ($kg > (int)$volunteer['capacity_kg']) {
        $violations[] = 'Quantity exceeds volunteer capacity of '
            . $volunteer['capacity_kg'] . ' kg.';
    }

    // Rule 3: remaining donation quantity.
    if ($kg > $remainingKg) {
        $violations[] = 'Quantity exceeds remaining donation of '
            . max(0, $remainingKg) . ' kg.';
    }

    // Rule 4: volunteer must serve the donation's zone.
    $stmt = $pdo->prepare(
        'SELECT zone FROM donors WHERE id = ?'
    );
    $stmt->execute([$donation['donor_id']]);
    $zone = $stmt->fetchColumn();

    $zones = json_decode($volunteer['zones'], true) ?: [];

    if (!$zone || !in_array($zone, $zones, true)) {
        $violations[] = 'Volunteer does not serve the donation zone.';
    }

    // Rule 5: planned pickup must be within the inclusive window.
    if ($plannedTs < $fromTs || $plannedTs > $untilTs) {
        $violations[] = 'Planned pickup must be within the pickup window.';
    }

    // Rule 6: planned pickup cannot be in the simulated past.
    if ($plannedTs < $nowTs) {
        $violations[] = 'Planned pickup cannot be before the simulated clock.';
    }

    // Rule 7: at least 60 minutes from every other ACTIVE/COLLECTED claim.
    $stmt = $pdo->prepare(
        "SELECT id, planned_at
         FROM claims
         WHERE volunteer_id = ?
           AND status IN ('ACTIVE', 'COLLECTED')"
    );
    $stmt->execute([$volunteerId]);

    foreach ($stmt->fetchAll() as $other) {
        $otherTs = strtotime($other['planned_at']);

        if (abs($plannedTs - $otherTs) < 3600) {
            $violations[] = 'Pickup must be at least 60 minutes from claim #'
                . $other['id'] . '.';
        }
    }

    if ($violations) {
        $pdo->rollBack();
        respond(422, [
            'success' => false,
            'violations' => $violations
        ]);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO claims
         (donation_id, volunteer_id, quantity_kg, planned_at, status)
         VALUES (?, ?, ?, ?, 'ACTIVE')"
    );
    $stmt->execute([
        $donationId,
        $volunteerId,
        $kg,
        $plannedSql
    ]);

    $claimId = (int)$pdo->lastInsertId();
    $pdo->commit();

    respond(201, [
        'success' => true,
        'message' => 'Claim created successfully.',
        'claim_id' => $claimId,
        'quantity_kg' => $kg,
        'status' => 'ACTIVE'
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('SurplusLink claim error: ' . $e->getMessage());

    respond(500, [
        'error' => 'Unable to process claim. Check the server log.'
    ]);
}
