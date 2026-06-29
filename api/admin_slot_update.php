<?php
require_once __DIR__ . '/db.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '???? ?????.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$role = strtoupper(trim($_SESSION['user_role'] ?? ''));
if (!in_array($role, ['ADMIN', 'SUPPORTER'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '??? ????.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$slotId = isset($_POST['slot_id']) ? (int)$_POST['slot_id'] : 0;
$teacherId = isset($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : 0;
$lessonDate = isset($_POST['lesson_date']) ? trim($_POST['lesson_date']) : '';
$startTime = isset($_POST['start_time']) ? trim($_POST['start_time']) : '';
$endTime = isset($_POST['end_time']) ? trim($_POST['end_time']) : '';
$classType = isset($_POST['class_type']) ? strtoupper(trim($_POST['class_type'])) : 'PRIVATE_11';
$maxStudents = isset($_POST['max_students']) ? (int)$_POST['max_students'] : 1;

if ($slotId <= 0 || $teacherId <= 0 || !$lessonDate || !$startTime || !$endTime) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '?? ????? ???????.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$maxStudents = max(1, $maxStudents);
$lessonType = in_array($classType, ['GROUP', 'DUO_12'], true) ? 'GROUP_25' : 'PRIVATE_25';

try {
    $pdo = DB::getConnection();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT id, teacher_id, start_time FROM time_slots WHERE id = ? LIMIT 1");
    $stmt->execute([$slotId]);
    $slot = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$slot) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '?? ??? ?? ? ????.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $newStart = $lessonDate . ' ' . $startTime . ':00';
    $newEnd = $lessonDate . ' ' . $endTime . ':00';

    $ustmt = $pdo->prepare("UPDATE time_slots SET teacher_id = ?, start_time = ?, end_time = ?, lesson_type = ?, max_students = ?, updated_at = NOW() WHERE id = ?");
    $ustmt->execute([$teacherId, $newStart, $newEnd, $lessonType, $maxStudents, $slotId]);

    $oldTeacherId = (int)$slot['teacher_id'];
    $oldDate = date('Y-m-d', strtotime($slot['start_time']));
    $oldTime = date('H:i:s', strtotime($slot['start_time']));

    $rstmt = $pdo->prepare("UPDATE reservations SET teacher_id = ?, reserve_date = ?, reserve_time = ?, updated_at = NOW() WHERE teacher_id = ? AND reserve_date = ? AND reserve_time = ?");
    $rstmt->execute([$teacherId, $lessonDate, $startTime . ':00', $oldTeacherId, $oldDate, $oldTime]);

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => '?? ??? ???????.'], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '??? ??: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
