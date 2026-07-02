<?php
require_once __DIR__ . '/db.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

// ??? ?? ??
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || strtoupper($_SESSION['user_role']) !== 'ADMIN') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '??? ??? ?????.']);
    exit;
}

try {
    $pdo = DB::getConnection();

    $stmt = $pdo->prepare("SELECT user_id, username, name, role, COALESCE(current_money,0) AS current_money FROM users ORDER BY user_id DESC LIMIT 500");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '??? ?? ?? ? ??? ??????.']);
    exit;
}
