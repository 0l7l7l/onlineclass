<?php
require_once 'db.php';
header('Content-Type: application/json; charset=utf-8');

$uid = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
if ($uid <= 0) {
    echo json_encode([]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT lesson_date, lesson_time, progress, status FROM schedules WHERE user_id = :uid ORDER BY lesson_date DESC, lesson_time DESC LIMIT 200");
    $stmt->execute(['uid' => $uid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($rows);
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB error']);
}
