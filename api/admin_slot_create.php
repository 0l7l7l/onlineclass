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

$teacherId = isset($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : 0;
$reserveDate = isset($_POST['reserve_date']) ? trim($_POST['reserve_date']) : '';
$reserveTime = isset($_POST['reserve_time']) ? trim($_POST['reserve_time']) : '';
$classType = isset($_POST['class_type']) ? strtoupper(trim($_POST['class_type'])) : 'PRIVATE_11';
$maxStudentsInput = isset($_POST['max_students']) ? (int)$_POST['max_students'] : 0;
$studentIdsRaw = isset($_POST['student_ids']) ? trim($_POST['student_ids']) : '';

if ($teacherId <= 0 || !$reserveDate || !$reserveTime) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '?? ????? ???????.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$startAt = DateTime::createFromFormat('Y-m-d H:i:s', $reserveDate . ' ' . $reserveTime);
if (!$startAt) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '??/?? ??? ???? ????.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$endAt = clone $startAt;
$endAt->modify('+1 hour');

$lessonType = 'PRIVATE_25';
$maxStudents = 1;
if ($classType === 'DUO_12') {
    $lessonType = 'GROUP_25';
    $maxStudents = 2;
} elseif ($classType === 'GROUP') {
    $lessonType = 'GROUP_25';
    $maxStudents = max(2, $maxStudentsInput > 0 ? $maxStudentsInput : 5);
}

$studentIds = [];
if ($studentIdsRaw !== '') {
    foreach (explode(',', $studentIdsRaw) as $sid) {
        $n = (int)trim($sid);
        if ($n > 0) {
            $studentIds[$n] = $n;
        }
    }
    $studentIds = array_values($studentIds);
}

if ($classType === 'PRIVATE_11' && count($studentIds) !== 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '1:1 ??? ?? 1?? ?????.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($classType === 'DUO_12' && count($studentIds) < 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '?? ??? ?? 1?? ??? ?????.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (count($studentIds) > $maxStudents) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '??? ?? ?? ??? ??????.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = DB::getConnection();
    $pdo->beginTransaction();

    $slotStmt = $pdo->prepare("SELECT id FROM time_slots WHERE teacher_id = ? AND start_time = ? LIMIT 1");
    $slotStmt->execute([$teacherId, $startAt->format('Y-m-d H:i:s')]);
    $existingSlot = $slotStmt->fetch(PDO::FETCH_ASSOC);

    if ($existingSlot) {
        $slotId = (int)$existingSlot['id'];
        $updateSlot = $pdo->prepare("UPDATE time_slots SET end_time = ?, lesson_type = ?, max_students = ?, updated_at = NOW() WHERE id = ?");
        $updateSlot->execute([$endAt->format('Y-m-d H:i:s'), $lessonType, $maxStudents, $slotId]);
    } else {
        $insertSlot = $pdo->prepare("INSERT INTO time_slots (teacher_id, start_time, end_time, lesson_type, max_students, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $insertSlot->execute([$teacherId, $startAt->format('Y-m-d H:i:s'), $endAt->format('Y-m-d H:i:s'), $lessonType, $maxStudents]);
        $slotId = (int)$pdo->lastInsertId();
    }

    $createdReservations = 0;
    if (!empty($studentIds)) {
        $conflictStudentStmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE student_id = ? AND reserve_date = ? AND reserve_time = ? AND (UPPER(status) = 'CONFIRMED' OR status = '????')");
        $existSlotReservationStmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE student_id = ? AND teacher_id = ? AND reserve_date = ? AND reserve_time = ? AND (UPPER(status) = 'CONFIRMED' OR status = '????')");
        $insertReservationStmt = $pdo->prepare("INSERT INTO reservations (student_id, teacher_id, class_type, reserve_date, reserve_time, status, created_at) VALUES (?, ?, ?, ?, ?, 'CONFIRMED', NOW())");

        foreach ($studentIds as $studentId) {
            $conflictStudentStmt->execute([$studentId, $reserveDate, $startAt->format('H:i:s')]);
            if ((int)$conflictStudentStmt->fetchColumn() > 0) {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => '??? ?? ? ?? ??? ?? ??? ????.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $existSlotReservationStmt->execute([$studentId, $teacherId, $reserveDate, $startAt->format('H:i:s')]);
            if ((int)$existSlotReservationStmt->fetchColumn() > 0) {
                continue;
            }

            $reservationClassType = $classType === 'GROUP' ? 'GROUP' : 'PRIVATE';
            $insertReservationStmt->execute([$studentId, $teacherId, $reservationClassType, $reserveDate, $startAt->format('H:i:s')]);
            $createdReservations++;
        }
    }

    $pdo->commit();
    echo json_encode([
        'success' => true,
        'message' => '???? ???????.',
        'data' => [
            'slot_id' => $slotId,
            'created_reservations' => $createdReservations
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '??? ??: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
