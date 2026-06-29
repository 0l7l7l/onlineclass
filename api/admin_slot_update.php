<?php
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
$mappedClassType = 'PRIVATE';
if ($classType === 'DUO_12') {
    $mappedClassType = 'DUO';
} elseif ($classType === 'GROUP') {
    $mappedClassType = 'GROUP';
}

try {
    $pdo = DB::getConnection();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT class_id, current_capacity FROM classes WHERE class_id = ? LIMIT 1");
    $stmt->execute([$slotId]);
    $slot = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$slot) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '?? ??? ?? ? ????.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $currentCapacity = (int)$slot['current_capacity'];
    if ($currentCapacity > $maxStudents) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => '현재 예약 인원보다 작은 정원으로 변경할 수 없습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $normalizedStartTime = strlen($startTime) === 5 ? $startTime . ':00' : $startTime;
    $normalizedEndTime = strlen($endTime) === 5 ? $endTime . ':00' : $endTime;

    $ustmt = $pdo->prepare("UPDATE classes SET teacher_id = ?, class_date = ?, start_time = ?, end_time = ?, class_type = ?, max_capacity = ? WHERE class_id = ?");
    $ustmt->execute([$teacherId, $lessonDate, $normalizedStartTime, $normalizedEndTime, $mappedClassType, $maxStudents, $slotId]);

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
