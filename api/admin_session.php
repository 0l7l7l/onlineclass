<?php
require_once __DIR__ . '/db.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || strtoupper($_SESSION['user_role']) !== 'ADMIN') {
    echo json_encode(['success' => false, 'user' => null]);
    exit;
}

echo json_encode([
    'success' => true,
    'user' => [
        'user_id' => (int)$_SESSION['user_id'],
        'name' => $_SESSION['user_name'] ?? '관리자',
        'role' => strtoupper(trim($_SESSION['user_role']))
    ]
]);
