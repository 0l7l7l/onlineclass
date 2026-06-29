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

$mappedClassType = 'PRIVATE';
$maxStudents = 1;
if ($classType === 'DUO_12') {
    $mappedClassType = 'DUO';
    $maxStudents = 2;
} elseif ($classType === 'GROUP') {
    $mappedClassType = 'GROUP';
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

if (count($studentIds) > $maxStudents) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '??? ?? ?? ??? ??????.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = DB::getConnection();
    $pdo->beginTransaction();

    if (!empty($studentIds) && in_array($classType, ['PRIVATE_11', 'DUO_12'], true)) {
        $matchStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE user_id = ? AND teacher_id = ? AND (UPPER(role) = 'STUDENT' OR LOWER(role) LIKE '%student%')");
        foreach ($studentIds as $studentId) {
            $matchStmt->execute([$studentId, $teacherId]);
            if ((int)$matchStmt->fetchColumn() <= 0) {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => '선택한 선생님의 담당 학생만 지정할 수 있습니다.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    }

    $reserveDate = $startAt->format('Y-m-d');
    $reserveStartTime = $startAt->format('H:i:s');
    $reserveEndTime = $endAt->format('H:i:s');

    $slotStmt = $pdo->prepare("SELECT class_id, current_capacity FROM classes WHERE teacher_id = ? AND class_date = ? AND start_time = ? LIMIT 1");
    $slotStmt->execute([$teacherId, $reserveDate, $reserveStartTime]);
    $existingSlot = $slotStmt->fetch(PDO::FETCH_ASSOC);

    if ($existingSlot) {
        $slotId = (int)$existingSlot['class_id'];
        $updateSlot = $pdo->prepare("UPDATE classes SET end_time = ?, class_type = ?, max_capacity = ?, status = 'AVAILABLE' WHERE class_id = ?");
        $updateSlot->execute([$reserveEndTime, $mappedClassType, $maxStudents, $slotId]);
        $currentCapacity = (int)$existingSlot['current_capacity'];
        if ($currentCapacity > $maxStudents) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => '현재 예약 인원보다 작은 정원으로는 변경할 수 없습니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } else {
        $insertSlot = $pdo->prepare("INSERT INTO classes (teacher_id, class_type, class_date, start_time, end_time, max_capacity, current_capacity, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, 'AVAILABLE', NOW())");
        $insertSlot->execute([$teacherId, $mappedClassType, $reserveDate, $reserveStartTime, $reserveEndTime, $maxStudents]);
        $slotId = (int)$pdo->lastInsertId();
        $currentCapacity = 0;
    }

    $createdReservations = 0;
    if (!empty($studentIds)) {
        $conflictStudentStmt = $pdo->prepare("SELECT COUNT(*) FROM reservations r INNER JOIN classes c ON c.class_id = r.class_id WHERE r.user_id = ? AND c.class_date = ? AND c.start_time = ? AND UPPER(r.status) IN ('CONFIRMED', 'ATTENDED')");
        $existSlotReservationStmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE user_id = ? AND class_id = ? AND UPPER(status) IN ('CONFIRMED', 'ATTENDED')");
        $ticketStmt = $pdo->prepare("SELECT ut.user_ticket_id, ut.remaining_count FROM user_tickets ut LEFT JOIN products p ON p.product_id = ut.product_id WHERE ut.user_id = ? AND ut.status = 'ACTIVE' AND ut.remaining_count > 0 AND ut.expired_at > NOW() AND (? <> 'GROUP' OR p.class_type = 'GROUP') ORDER BY ut.expired_at ASC, ut.user_ticket_id ASC LIMIT 1");
        $insertReservationStmt = $pdo->prepare("INSERT INTO reservations (user_id, class_id, user_ticket_id, status, reserved_at) VALUES (?, ?, ?, 'CONFIRMED', NOW())");
        $ticketUseStmt = $pdo->prepare("UPDATE user_tickets SET remaining_count = remaining_count - 1 WHERE user_ticket_id = ? AND remaining_count > 0");
        $ticketStatusStmt = $pdo->prepare("UPDATE user_tickets SET status = 'EXHAUSTED' WHERE user_ticket_id = ? AND remaining_count <= 0");
        $capacityStmt = $pdo->prepare("UPDATE classes SET current_capacity = current_capacity + 1 WHERE class_id = ?");

        foreach ($studentIds as $studentId) {
            $conflictStudentStmt->execute([$studentId, $reserveDate, $reserveStartTime]);
            if ((int)$conflictStudentStmt->fetchColumn() > 0) {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => '학생 시간표가 겹쳐 예약할 수 없습니다.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if ($currentCapacity >= $maxStudents) {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => '정원이 가득 찼습니다.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $existSlotReservationStmt->execute([$studentId, $slotId]);
            if ((int)$existSlotReservationStmt->fetchColumn() > 0) {
                continue;
            }

            $ticketStmt->execute([$studentId, $mappedClassType]);
            $ticket = $ticketStmt->fetch(PDO::FETCH_ASSOC);
            if (!$ticket) {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => '학생의 사용 가능한 수강권이 없습니다.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $ticketId = (int)$ticket['user_ticket_id'];
            $insertReservationStmt->execute([$studentId, $slotId, $ticketId]);
            $ticketUseStmt->execute([$ticketId]);
            $ticketStatusStmt->execute([$ticketId]);
            $capacityStmt->execute([$slotId]);

            $currentCapacity++;
            $createdReservations++;
        }
    }

    $pdo->commit();
    echo json_encode([
        'success' => true,
        'message' => '수업이 등록되었습니다.',
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
    echo json_encode(['success' => false, 'message' => '시스템 오류: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
