<?php
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

    $tplStmt = $pdo->prepare("SELECT class_id, teacher_id, class_date, start_time, end_time, class_type, max_capacity FROM classes WHERE class_id = ? AND status = 'AVAILABLE' LIMIT 1");
    $findCandidatesStmt = $pdo->prepare(
        "SELECT class_id, teacher_id, class_date, start_time, max_capacity, current_capacity
         FROM classes
         WHERE teacher_id = ?
           AND class_type = 'GROUP'
           AND class_date >= CURDATE()
           AND WEEKDAY(class_date) = ?
           AND start_time = ?
           AND status = 'AVAILABLE'
         ORDER BY class_date ASC
         LIMIT 40"
    );
    $dupStmt = $pdo->prepare("SELECT COUNT(*) FROM reservations r INNER JOIN classes c ON c.class_id = r.class_id WHERE r.user_id = ? AND c.class_date = ? AND c.start_time = ? AND UPPER(r.status) IN ('CONFIRMED','ATTENDED')");
    $existClassStmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE user_id = ? AND class_id = ? AND UPPER(status) IN ('CONFIRMED','ATTENDED')");
    $ticketStmt = $pdo->prepare("SELECT ut.user_ticket_id FROM user_tickets ut INNER JOIN products p ON p.product_id = ut.product_id WHERE ut.user_id = ? AND ut.status = 'ACTIVE' AND ut.remaining_count > 0 AND ut.expired_at > NOW() AND p.class_type = 'GROUP' ORDER BY ut.expired_at ASC, ut.user_ticket_id ASC LIMIT 1");
    $insStmt = $pdo->prepare("INSERT INTO reservations (user_id, class_id, user_ticket_id, status, reserved_at) VALUES (?, ?, ?, 'CONFIRMED', NOW())");
    $ticketUseStmt = $pdo->prepare("UPDATE user_tickets SET remaining_count = remaining_count - 1 WHERE user_ticket_id = ? AND remaining_count > 0");
    $ticketStatusStmt = $pdo->prepare("UPDATE user_tickets SET status = 'EXHAUSTED' WHERE user_ticket_id = ? AND remaining_count <= 0");
    $capacityStmt = $pdo->prepare("UPDATE classes SET current_capacity = current_capacity + 1 WHERE class_id = ?");

    $totalBooked = 0;
    $results = [];

    foreach ($slotIds as $slotId) {
        $tplStmt->execute([$slotId]);
        $tpl = $tplStmt->fetch(PDO::FETCH_ASSOC);
        if (!$tpl || strtoupper((string)$tpl['class_type']) !== 'GROUP') {
            continue;
        }

        $weekday = (int)date('N', strtotime($tpl['class_date'])) - 1; // WEEKDAY: Mon=0
        $time = $tpl['start_time'];
        $teacherId = (int)$tpl['teacher_id'];

        $findCandidatesStmt->execute([$teacherId, $weekday, $time]);
        $candidates = $findCandidatesStmt->fetchAll(PDO::FETCH_ASSOC);

        $bookedForTemplate = 0;
        foreach ($candidates as $cand) {
            if ($bookedForTemplate >= $weeks) break;

            $reserveDate = $cand['class_date'];
            $reserveTime = $cand['start_time'];
            $classId = (int)$cand['class_id'];

            $dupStmt->execute([$studentId, $reserveDate, $reserveTime]);
            if ((int)$dupStmt->fetchColumn() > 0) {
                continue;
            }

            $existClassStmt->execute([$studentId, $classId]);
            if ((int)$existClassStmt->fetchColumn() > 0) {
                continue;
            }

            $bookedCount = (int)$cand['current_capacity'];
            $maxStudents = (int)$cand['max_capacity'];
            if ($bookedCount >= $maxStudents) {
                continue;
            }

            $ticketStmt->execute([$studentId]);
            $ticket = $ticketStmt->fetch(PDO::FETCH_ASSOC);
            if (!$ticket) {
                break;
            }

            $ticketId = (int)$ticket['user_ticket_id'];
            $insStmt->execute([$studentId, $classId, $ticketId]);
            $ticketUseStmt->execute([$ticketId]);
            $ticketStatusStmt->execute([$ticketId]);
            $capacityStmt->execute([$classId]);

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
