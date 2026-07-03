<?php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(0);
ob_start();

require_once 'db.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => '잘못된 요청입니다.']);
    exit;
}

$user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
if ($user_id <= 0) {
    echo json_encode(['success' => false, 'message' => '유효한 학생 ID가 필요합니다.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 관련된 일정 먼저 삭제 (외래 키 제약 조건 대비)
    $stmt = $pdo->prepare('DELETE FROM schedules WHERE user_id = :user_id');
    $stmt->execute(['user_id' => $user_id]);

    $stmt = $pdo->prepare('DELETE FROM Users WHERE user_id = :user_id');
    $stmt->execute(['user_id' => $user_id]);

    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ob_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

ob_end_flush();
