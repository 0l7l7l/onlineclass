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

$schedule_id = isset($_POST['schedule_id']) ? intval($_POST['schedule_id']) : 0;
if ($schedule_id <= 0) {
    echo json_encode(['success' => false, 'message' => '유효한 일정 ID가 필요합니다.']);
    exit;
}

try {
    $stmt = $pdo->prepare('DELETE FROM schedules WHERE id = :id');
    $stmt->execute(['id' => $schedule_id]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => '삭제할 일정이 없습니다.']);
    } else {
        echo json_encode(['success' => true]);
    }
} catch (Throwable $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

ob_end_flush();
