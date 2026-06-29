<?php
require_once __DIR__ . '/db.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '???? ?????.']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$type = isset($_POST['type']) ? $_POST['type'] : (isset($_GET['type']) ? $_GET['type'] : 'FLEX');
$reserve_date = isset($_POST['reserve_date']) ? $_POST['reserve_date'] : null;
$reserve_time = isset($_POST['reserve_time']) ? $_POST['reserve_time'] : null;
$slot_id = isset($_POST['slot_id']) ? (int)$_POST['slot_id'] : null;

if (!$reserve_date || !$reserve_time) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '?? ??? ??? ??? ???.']);
    exit;
}

try {
    $pdo = DB::getConnection();
    $pdo->beginTransaction();

    // determine teacher_id: if slot provided, use its teacher_id, otherwise find from user's teacher_id
    $teacher_id = null;
    if ($slot_id) {
        $stmt = $pdo->prepare("SELECT teacher_id FROM time_slots WHERE id = ?");
        $stmt->execute([$slot_id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r) $teacher_id = (int)$r['teacher_id'];
    }

    if (!$teacher_id) {
        $stmt = $pdo->prepare("SELECT teacher_id FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r && !empty($r['teacher_id'])) $teacher_id = (int)$r['teacher_id'];
    }

    if (!$teacher_id) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => '?? ??? ??? ?? ? ????.']);
        exit;
    }

    // basic conflict check: do not double-book same slot for same student
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE student_id = ? AND reserve_date = ? AND reserve_time = ? AND status IN ('CONFIRMED','confirmed')");
    $stmt->execute([$user_id, $reserve_date, $reserve_time]);
    $cnt = (int)$stmt->fetchColumn();
    if ($cnt > 0) {
        $pdo->rollBack();
        echo json_encode(['success'=>false, 'message'=>'?? ?? ??? ??? ?????.']);
        exit;
    }

    // insert reservation
    $stmt = $pdo->prepare("INSERT INTO reservations (student_id, teacher_id, class_type, reserve_date, reserve_time, status, created_at) VALUES (?, ?, ?, ?, ?, 'CONFIRMED', NOW())");
    $class_type = $type === 'GROUP' ? 'GROUP' : 'PRIVATE';
    $stmt->execute([$user_id, $teacher_id, $class_type, $reserve_date, $reserve_time]);

    $pdo->commit();

    echo json_encode(['success'=>true, 'message'=>'??? ???????. ??????? ?????.']);
    exit;
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success'=>false, 'message'=>'??? ??: '.$e->getMessage()]);
    exit;
}
