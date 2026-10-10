<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

echo json_encode([
    'success' => true,
    'database' => 'surpluslink',
    'donors' => (int)$pdo->query('SELECT COUNT(*) FROM donors')->fetchColumn(),
    'volunteers' => (int)$pdo->query('SELECT COUNT(*) FROM volunteers')->fetchColumn(),
    'donations' => (int)$pdo->query('SELECT COUNT(*) FROM donations')->fetchColumn()
]);
