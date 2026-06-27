<?php
require_once __DIR__ . '/db.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || strtoupper($_SESSION['user_role']) !== 'ADMIN') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '관리자 권한이 필요합니다.']);
    exit;
}

try {
    $pdo = DB::getConnection();

    $stmt = $pdo->prepare("SELECT r.request_id, r.user_id, u.name AS user_name, u.username, r.amount, r.payment_method, r.depositor_name, r.status, r.reject_reason, r.requested_at, r.processed_at, r.approved_by FROM `request` r JOIN users u ON r.user_id = u.user_id ORDER BY r.requested_at DESC LIMIT 100");
    $stmt->execute();
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $requests]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '서버 오류가 발생했습니다.']);
}
