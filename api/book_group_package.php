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
    // 기존 로직 유지 (FOR UPDATE 포함)
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
        SELECT class_id, teacher_id, class_type, class_date, start_time, end_time, max_capacity, current_capacity, status
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

    $selectedTicketId = isset($_POST['ticket_id']) ? (int)$_POST['ticket_id'] : null;
    $distinctDaysSelected = count($slotIds); // 클라이언트에서 요일별로 전달된 base slotId 개수로 간주

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

    // slots 집합 획득 (중복 제거)
    $allSlots = [];
    foreach ($slotIds as $slotId) {
        foreach (fetchGroupPackageSlots($pdo, $slotId, $weeks) as $slot) {
            $allSlots[(int)$slot['class_id']] = $slot;
        }
    }
    $allSlots = array_values($allSlots);
    $neededCount = count($allSlots);

    // 과거 시각/중복/정원 체크 (기존 로직)
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

    // 특정 티켓으로 예약하려는 경우: 해당 user_ticket 을 잠그고 per_week/remaining 검증
    if ($selectedTicketId) {
        $ticketLock = $pdo->prepare("
            SELECT ut.user_ticket_id, ut.remaining_count, ut.per_week, p.class_type
            FROM user_tickets ut
            LEFT JOIN products p ON p.product_id = ut.product_id
            WHERE ut.user_ticket_id = ? AND ut.user_id = ? AND ut.status = 'ACTIVE'
            FOR UPDATE
        ");
        $ticketLock->execute([$selectedTicketId, $userId]);
        $ut = $ticketLock->fetch(PDO::FETCH_ASSOC);
        if (!$ut) {
            throw new RuntimeException('해당 티켓을 찾을 수 없거나 사용 불가합니다.');
        }
        if (strtoupper((string)$ut['class_type'] ?? 'GROUP') !== 'GROUP' && intval($ut['class_type'] ?? 0) !== 0) {
            // class_type 체크는 products 에 의존하므로 필요시 확장
        }
        
        // products.class_type 이 GROUP 인지 검사 (클래스 타입이 GROUP 이 아니면 거부)
        if (strtoupper(trim($ut['class_type'] ?? '')) !== 'GROUP') {
            throw new RuntimeException('선택한 티켓은 그룹 수업용 티켓이 아닙니다. 그룹 패키지 전용 티켓을 사용해 주세요.');
        }

        // 주당 허용 요일(per_week) 검사: 선택한 요일(기초 slotIds 개수) 이 티켓의 per_week 보다 크면 거부
        $ticketPerWeek = (int)($ut['per_week'] ?? 0);
        if ($ticketPerWeek > 0 && $distinctDaysSelected > $ticketPerWeek) {
            throw new RuntimeException("선택하신 티켓은 주 {$ticketPerWeek}회까지만 예약 가능합니다. 선택한 요일 수: {$distinctDaysSelected}");
        }

        // 남은 회차가 충분한지 검사
        if ((int)$ut['remaining_count'] < $neededCount) {
            throw new RuntimeException("선택한 티켓의 남은 회차가 부족합니다. 필요: {$neededCount}, 보유: " . intval($ut['remaining_count']));
        }

        // 이제 해당 티켓만 소진하면서 reservations 생성
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

        $created = 0;
        foreach ($allSlots as $slot) {
            $insert->execute([$userId, (int)$slot['class_id'], $selectedTicketId]);
            $consume->execute([$selectedTicketId]);
            $mark->execute([$selectedTicketId]);
            $capacity->execute([(int)$slot['class_id'], (int)$slot['class_id']]);
            $created++;
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => "{$created}건의 그룹 수업 예약이 완료되었습니다.", 'reserved_count' => $created], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 기존 로직: 여러 티켓을 섞어 필요한 만큼 소진
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

