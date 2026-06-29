<?php
require_once __DIR__ . '/db.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = DB::getConnection();
    $date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

    $stmt = $pdo->prepare("SELECT ts.id, ts.teacher_id, ts.start_time, ts.end_time, ts.lesson_type, ts.theme, ts.max_students, u.name AS teacher_name FROM time_slots ts LEFT JOIN users u ON ts.teacher_id = u.user_id WHERE DATE(ts.start_time) = ? ORDER BY u.user_id, ts.start_time ASC");
    $stmt->execute([$date]);
    $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success'=>true, 'data'=>$slots]);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success'=>false, 'message'=>$e->getMessage()]);
    exit;
}
