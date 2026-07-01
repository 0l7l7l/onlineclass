<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema_helpers.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

function parseSlotIds(string $raw): array
{
    $ids = [];
    foreach (explode(',', $raw) as $value) {
        $id = (int)trim($value);
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    return array_values($ids);
}

function fetchGroupPackageSlots(PDO $pdo, int $baseSlotId, int $weeks): array
{
    $baseStmt = $pdo->prepare("
        SELECT class_id, class_date, start_time
        FROM classes
        WHERE class_id = ?
          AND class_type = 'GROUP'
          AND status = 'AVAILABLE'
          AND deleted_at IS NULL
        LIMIT 1
    ");
    $baseStmt->execute([$baseSlotId]);
    $base = $baseStmt->fetch(PDO::FETCH_ASSOC);
    if (!$base) {
        throw new RuntimeException('선택한 그룹 수업을 찾을 수 없습니다.');
    }

    $slotStmt = $pdo->prepare("
        SELECT class_id, teacher_id, class_type, class_date, start_time, end_time, max_capacity, status
        FROM classes
        WHERE class_type = 'GROUP'
          AND status = 'AVAILABLE'
          AND deleted_at IS NULL
          AND CONCAT(class_date, ' ', start_time) >= CONCAT(?, ' ', ?)
          AND DAYOFWEEK(class_date) = DAYOFWEEK(?)
          AND start_time = ?
        ORDER BY class_date ASC, start_time ASC
        LIMIT {$weeks}
        FOR UPDATE
    ");
    $slotStmt->execute([
        $base['class_date'],
        $base['start_time'],
        $base['class_date'],
        $base['start_time']
    ]);

    $slots = $slotStmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($slots) < $weeks) {
        throw new RuntimeException("선택한 요일/시간의 {$weeks}주 그룹 스케줄이 부족합니다. 관리자에게 스케줄 추가를 요청해 주세요.");
    }

    return $slots;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'POST 요청만 가능합니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo = DB::getConnection();
    ensureClassScheduleSupportTables($pdo);

    $userId = (int)$_SESSION['user_id'];
    $slotIds = parseSlotIds((string)($_POST['slot_ids'] ?? ''));
    $weeks = isset($_POST['weeks']) ? (int)$_POST['weeks'] : 4;
    if ($weeks <= 0 || $weeks > 8) {
        $weeks = 4;
    }

    if (count($slotIds) === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '예약할 그룹 수업을 선택해 주세요.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->beginTransaction();

    $studentStmt = $pdo->prepare("
        SELECT user_id, role, deleted_at
        FROM users
        WHERE user_id = ?
        LIMIT 1
    ");
    $studentStmt->execute([$userId]);
    $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
    if (!$student || strtoupper((string)$student['role']) !== 'STUDENT' || $student['deleted_at'] !== null) {
        throw new RuntimeException('학생 계정으로 로그인해야 예약할 수 있습니다.');
    }

    $allSlots = [];
    foreach ($slotIds as $slotId) {
        foreach (fetchGroupPackageSlots($pdo, $slotId, $weeks) as $slot) {
            $allSlots[(int)$slot['class_id']] = $slot;
        }
    }
    $allSlots = array_values($allSlots);
    $neededCount = count($allSlots);

    foreach ($allSlots as $slot) {
        if (strtotime($slot['class_date'] . ' ' . $slot['start_time']) < time()) {
            throw new RuntimeException('이미 지난 그룹 수업은 예약할 수 없습니다.');
        }

        $dupStmt = $pdo->prepare("
            SELECT reservation_id
            FROM reservations
            WHERE user_id = ?
              AND class_id = ?
              AND UPPER(TRIM(status)) = 'CONFIRMED'
            LIMIT 1
        ");
        $dupStmt->execute([$userId, (int)$slot['class_id']]);
        if ($dupStmt->fetch()) {
            throw new RuntimeException('이미 예약한 그룹 수업이 포함되어 있습니다.');
        }

        $countStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM reservations
            WHERE class_id = ?
              AND UPPER(TRIM(status)) = 'CONFIRMED'
        ");
        $countStmt->execute([(int)$slot['class_id']]);
        $reservedCount = (int)$countStmt->fetchColumn();
        if ($reservedCount >= (int)$slot['max_capacity']) {
            throw new RuntimeException($slot['class_date'] . ' ' . substr((string)$slot['start_time'], 0, 5) . ' 그룹 수업은 정원이 마감되었습니다.');
        }
    }

    $ticketStmt = $pdo->prepare("
        SELECT ut.user_ticket_id, ut.remaining_count
        FROM user_tickets ut
        JOIN products p ON p.product_id = ut.product_id
        WHERE ut.user_id = ?
          AND ut.status = 'ACTIVE'
          AND ut.remaining_count > 0
          AND ut.expired_at > NOW()
          AND p.product_type = 'TICKET'
          AND p.class_type = 'GROUP'
          AND p.is_active = 1
        ORDER BY ut.expired_at ASC, ut.user_ticket_id ASC
        FOR UPDATE
    ");
    $ticketStmt->execute([$userId]);
    $tickets = $ticketStmt->fetchAll(PDO::FETCH_ASSOC);
    $remainingTotal = 0;
    foreach ($tickets as $ticket) {
        $remainingTotal += (int)$ticket['remaining_count'];
    }
    if ($remainingTotal < $neededCount) {
        throw new RuntimeException("그룹 수업 티켓이 부족합니다. 필요한 티켓: {$neededCount}회, 보유: {$remainingTotal}회");
    }

    $ticketQueue = [];
    foreach ($tickets as $ticket) {
        $ticketQueue[] = [
            'id' => (int)$ticket['user_ticket_id'],
            'remaining' => (int)$ticket['remaining_count']
        ];
    }

    $insert = $pdo->prepare("
        INSERT INTO reservations (user_id, class_id, user_ticket_id, status)
        VALUES (?, ?, ?, 'CONFIRMED')
    ");
    $consume = $pdo->prepare("
        UPDATE user_tickets
        SET remaining_count = remaining_count - 1
        WHERE user_ticket_id = ? AND remaining_count > 0
    ");
    $mark = $pdo->prepare("
        UPDATE user_tickets
        SET status = 'EXHAUSTED'
        WHERE user_ticket_id = ? AND remaining_count <= 0
    ");
    $capacity = $pdo->prepare("
        UPDATE classes
        SET current_capacity = (
            SELECT COUNT(*) FROM reservations WHERE class_id = ? AND UPPER(TRIM(status)) = 'CONFIRMED'
        )
        WHERE class_id = ?
    ");

    $createdCount = 0;
    foreach ($allSlots as $slot) {
        while (count($ticketQueue) > 0 && $ticketQueue[0]['remaining'] <= 0) {
            array_shift($ticketQueue);
        }
        if (count($ticketQueue) === 0) {
            throw new RuntimeException('예약 처리 중 사용할 그룹 티켓을 찾지 못했습니다.');
        }

        $ticketId = $ticketQueue[0]['id'];
        $insert->execute([$userId, (int)$slot['class_id'], $ticketId]);
        $consume->execute([$ticketId]);
        $mark->execute([$ticketId]);
        $ticketQueue[0]['remaining']--;

        $capacity->execute([(int)$slot['class_id'], (int)$slot['class_id']]);
        $createdCount++;
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "{$createdCount}건의 그룹 수업 예약이 완료되었습니다.",
        'reserved_count' => $createdCount
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
