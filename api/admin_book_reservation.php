<?php
require_once __DIR__ . '/db.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

// Only allow admin or supporter roles to book on behalf of students
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
    http_response_code(401);
    echo json_encode(['success'=>false,'message'=>'???? ?????.']);
    exit;
}
$role = strtoupper(trim($_SESSION['user_role']));
if ($role !== 'ADMIN' && $role !== 'SUPPORTER') {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'??? ????.']);
    exit;
}

$student_id = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
$teacher_id = isset($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : 0;
$reserve_date = isset($_POST['reserve_date']) ? $_POST['reserve_date'] : null;
$reserve_time = isset($_POST['reserve_time']) ? $_POST['reserve_time'] : null;
$class_type = isset($_POST['class_type']) ? $_POST['class_type'] : 'PRIVATE';

if (!$student_id || !$teacher_id || !$reserve_date || !$reserve_time) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'????? ?????.']);
    exit;
}

try {
    $pdo = DB::getConnection();
    $pdo->beginTransaction();

    // basic conflict check
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE student_id = ? AND reserve_date = ? AND reserve_time = ? AND status IN ('CONFIRMED','confirmed')");
    $stmt->execute([$student_id, $reserve_date, $reserve_time]);
    $cnt = (int)$stmt->fetchColumn();
    if ($cnt > 0) {
        $pdo->rollBack();
        echo json_encode(['success'=>false,'message'=>'?? ??? ?? ?? ??? ?? ?????.']);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO reservations (student_id, teacher_id, class_type, reserve_date, reserve_time, status, created_at) VALUES (?, ?, ?, ?, ?, 'CONFIRMED', NOW())");
    $stmt->execute([$student_id, $teacher_id, $class_type, $reserve_date, $reserve_time]);

    $pdo->commit();
    echo json_encode(['success'=>true,'message'=>'??? ???????.']);
    exit;
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    exit;
}
