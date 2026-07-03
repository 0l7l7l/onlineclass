<?php
require_once 'db.php';
header('Content-Type: application/json; charset=utf-8');

$user_id = isset($_GET['user_id']) ? trim($_GET['user_id']) : '';
if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'user_id 필요']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT lesson_date, lesson_time, progress, status FROM schedules WHERE user_id = :user_id ORDER BY lesson_date DESC, lesson_time DESC LIMIT 20");
    $stmt->execute(['user_id' => $user_id]);
    $rows = $stmt->fetchAll();

    // schedules.progress에서 이전 진도(메모)를 반환
    echo json_encode(['success' => true, 'records' => $rows], JSON_UNESCAPED_UNICODE);
} catch (\PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'DB 오류: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}