<?php
require_once __DIR__ . '/db.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '???? ?????.']);
    exit;
}

$studentId = (int)$_SESSION['user_id'];
$slotIdsRaw = isset($_POST['slot_ids']) ? trim($_POST['slot_ids']) : '';
$weeks = isset($_POST['weeks']) ? (int)$_POST['weeks'] : 4;
$weeks = max(1, min(8, $weeks));

$slotIds = [];
if ($slotIdsRaw !== '') {
    foreach (explode(',', $slotIdsRaw) as $id) {
        $n = (int)trim($id);
        if ($n > 0) $slotIds[$n] = $n;
    }
}
$slotIds = array_values($slotIds);

if (count($slotIds) === 0 || count($slotIds) > 2) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '?? ???? 1~2?? ?? ?? ??? ?????.']);
    exit;
}

try {
    $pdo = DB::getConnection();
    $pdo->beginTransaction();

    $tplStmt = $pdo->prepare("SELECT id, teacher_id, start_time, end_time, lesson_type, max_students FROM time_slots WHERE id = ? LIMIT 1");
    $findCandidatesStmt = $pdo->prepare(
        "SELECT id, teacher_id, start_time, max_students
         FROM time_slots
         WHERE teacher_id = ?
           AND lesson_type = 'GROUP_25'
           AND DATE(start_time) >= CURDATE()
           AND WEEKDAY(start_time) = ?
           AND TIME(start_time) = ?
         ORDER BY start_time ASC
         LIMIT 40"
    );
    $dupStmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE student_id = ? AND reserve_date = ? AND reserve_time = ? AND status IN ('CONFIRMED','confirmed','????')");
    $capStmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE teacher_id = ? AND reserve_date = ? AND reserve_time = ? AND status IN ('CONFIRMED','confirmed','????')");
    $insStmt = $pdo->prepare("INSERT INTO reservations (student_id, teacher_id, class_type, reserve_date, reserve_time, status, created_at) VALUES (?, ?, 'GROUP', ?, ?, 'CONFIRMED', NOW())");

    $totalBooked = 0;
    $results = [];

    foreach ($slotIds as $slotId) {
        $tplStmt->execute([$slotId]);
        $tpl = $tplStmt->fetch(PDO::FETCH_ASSOC);
        if (!$tpl || strtoupper((string)$tpl['lesson_type']) !== 'GROUP_25') {
            continue;
        }

        $weekday = (int)date('N', strtotime($tpl['start_time'])) - 1; // WEEKDAY: Mon=0
        $time = date('H:i:s', strtotime($tpl['start_time']));
        $teacherId = (int)$tpl['teacher_id'];

        $findCandidatesStmt->execute([$teacherId, $weekday, $time]);
        $candidates = $findCandidatesStmt->fetchAll(PDO::FETCH_ASSOC);

        $bookedForTemplate = 0;
        foreach ($candidates as $cand) {
            if ($bookedForTemplate >= $weeks) break;

            $reserveDate = date('Y-m-d', strtotime($cand['start_time']));
            $reserveTime = date('H:i:s', strtotime($cand['start_time']));

            $dupStmt->execute([$studentId, $reserveDate, $reserveTime]);
            if ((int)$dupStmt->fetchColumn() > 0) {
                continue;
            }

            $capStmt->execute([$teacherId, $reserveDate, $reserveTime]);
            $bookedCount = (int)$capStmt->fetchColumn();
            $maxStudents = (int)$cand['max_students'];
            if ($bookedCount >= $maxStudents) {
                continue;
            }

            $insStmt->execute([$studentId, $teacherId, $reserveDate, $reserveTime]);
            $bookedForTemplate++;
            $totalBooked++;
        }

        $results[] = [
            'slot_id' => $slotId,
            'requested_weeks' => $weeks,
            'booked_weeks' => $bookedForTemplate
        ];
    }

    if ($totalBooked === 0) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => '?? ?? ??? ?? ??? ????.']);
        exit;
    }

    $pdo->commit();
    echo json_encode([
        'success' => true,
        'message' => $totalBooked . '?? ?? ??? ?? ???????.',
        'data' => [
            'total_booked' => $totalBooked,
            'details' => $results
        ]
    ]);
    exit;
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '??? ??: ' . $e->getMessage()]);
    exit;
}
