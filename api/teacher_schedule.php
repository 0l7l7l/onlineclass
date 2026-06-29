<?php
require_once __DIR__ . '/db.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '???? ?????.']);
    exit;
}

try {
    $pdo = DB::getConnection();
    $user_id = (int)$_SESSION['user_id'];

    // find assigned teacher for this user
    $stmt = $pdo->prepare("SELECT teacher_id FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['teacher_id'])) {
        echo json_encode(['success' => false, 'message' => '?? ???? ???? ?????.']);
        exit;
    }

    $teacher_id = (int)$row['teacher_id'];

    $stmt = $pdo->prepare("SELECT id AS slot_id, teacher_id, start_time, end_time, lesson_type, theme, max_students FROM time_slots WHERE teacher_id = ? AND start_time >= DATE(NOW()) ORDER BY start_time ASC LIMIT 200");
    $stmt->execute([$teacher_id]);
    $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // normalize output
    $out = array_map(function($s){
        return [
            'slot_id' => (int)$s['slot_id'],
            'teacher_id' => (int)$s['teacher_id'],
            'start_time' => $s['start_time'],
            'end_time' => $s['end_time'],
            'lesson_type' => $s['lesson_type'],
            'theme' => $s['theme'],
            'max_students' => (int)$s['max_students']
        ];
    }, $slots);

    echo json_encode(['success'=>true, 'data'=>$out]);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success'=>false, 'message'=>'??? ??: '.$e->getMessage()]);
    exit;
}
