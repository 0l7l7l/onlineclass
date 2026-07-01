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

function bookingClassType(string $value): string
{
    $type = strtoupper(trim($value));
    if ($type === 'PRIVATE_11') return 'PRIVATE';
    if ($type === 'DUO_12') return 'DUO';
    return in_array($type, ['PRIVATE', 'DUO', 'GROUP'], true) ? $type : 'PRIVATE';
}

function bookingCapacityLimit(array $class): int
{
    $type = strtoupper((string)($class['class_type'] ?? 'PRIVATE'));
    if ($type === 'PRIVATE') return 1;
    if ($type === 'DUO') return 2;
    return max(1, (int)($class['max_capacity'] ?? 1));
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
    $classId = isset($_POST['class_id']) ? (int)$_POST['class_id'] : (isset($_POST['slot_id']) ? (int)$_POST['slot_id'] : 0);
    $requestedType = bookingClassType((string)($_POST['class_type'] ?? 'PRIVATE'));

    if ($classId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '예약할 수업을 선택해 주세요.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->beginTransaction();

    $classStmt = $pdo->prepare("
        SELECT class_id, teacher_id, class_type, class_date, start_time, end_time, max_capacity, current_capacity, status
        FROM classes
        WHERE class_id = ? AND deleted_at IS NULL
        LIMIT 1 FOR UPDATE
    ");
    $classStmt->execute([$classId]);
    $class = $classStmt->fetch(PDO::FETCH_ASSOC);
    if (!$class) {
        throw new RuntimeException('예약 가능한 수업을 찾을 수 없습니다.');
    }

    $classType = bookingClassType((string)$class['class_type']);
    if ($classType !== $requestedType) {
        throw new RuntimeException('선택한 수업 유형이 변경되었습니다. 다시 선택해 주세요.');
    }
    if ((string)$class['status'] !== 'AVAILABLE') {
        throw new RuntimeException('현재 예약할 수 없는 수업입니다.');
    }
    if (strtotime($class['class_date'] . ' ' . $class['start_time']) < time()) {
        throw new RuntimeException('이미 지난 수업은 예약할 수 없습니다.');
    }

    $studentStmt = $pdo->prepare("
        SELECT user_id, role, teacher_id, deleted_at
        FROM users
        WHERE user_id = ?
        LIMIT 1
    ");
    $studentStmt->execute([$userId]);
    $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
    if (!$student || strtoupper((string)$student['role']) !== 'STUDENT' || $student['deleted_at'] !== null) {
        throw new RuntimeException('학생 계정으로 로그인해야 예약할 수 있습니다.');
    }

    if ($classType !== 'GROUP') {
        if ((int)$student['teacher_id'] !== (int)$class['teacher_id']) {
            throw new RuntimeException('담당 선생님의 수업만 예약할 수 있습니다.');
        }

        $targetStmt = $pdo->prepare("
            SELECT COUNT(*) FROM class_targets WHERE class_id = ?
        ");
        $targetStmt->execute([$classId]);
        $hasTargets = (int)$targetStmt->fetchColumn() > 0;
        if ($hasTargets) {
            $allowedStmt = $pdo->prepare("
                SELECT COUNT(*) FROM class_targets WHERE class_id = ? AND user_id = ?
            ");
            $allowedStmt->execute([$classId, $userId]);
            if ((int)$allowedStmt->fetchColumn() <= 0) {
                throw new RuntimeException('이 수업에 지정된 학생만 예약할 수 있습니다.');
            }
        }
    }

    $dupStmt = $pdo->prepare("
        SELECT reservation_id
        FROM reservations
        WHERE user_id = ? AND class_id = ? AND status = 'CONFIRMED'
        LIMIT 1
    ");
    $dupStmt->execute([$userId, $classId]);
    if ($dupStmt->fetch()) {
        throw new RuntimeException('이미 예약한 수업입니다.');
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE class_id = ? AND status = 'CONFIRMED'");
    $countStmt->execute([$classId]);
    $reservedCount = (int)$countStmt->fetchColumn();
    if ($reservedCount >= bookingCapacityLimit($class)) {
        throw new RuntimeException('정원이 마감된 수업입니다.');
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
          AND p.class_type = ?
          AND p.is_active = 1
        ORDER BY ut.expired_at ASC, ut.user_ticket_id ASC
        LIMIT 1
        FOR UPDATE
    ");
    $ticketStmt->execute([$userId, $classType]);
    $ticket = $ticketStmt->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) {
        throw new RuntimeException($classType === 'PRIVATE' ? '사용 가능한 1:1 수업 티켓이 없습니다.' : '사용 가능한 수업 티켓이 없습니다.');
    }

    $ticketId = (int)$ticket['user_ticket_id'];
    $insert = $pdo->prepare("
        INSERT INTO reservations (user_id, class_id, user_ticket_id, status)
        VALUES (?, ?, ?, 'CONFIRMED')
    ");
    $insert->execute([$userId, $classId, $ticketId]);
    $reservationId = (int)$pdo->lastInsertId();

    $consume = $pdo->prepare("
        UPDATE user_tickets
        SET remaining_count = remaining_count - 1
        WHERE user_ticket_id = ? AND remaining_count > 0
    ");
    $consume->execute([$ticketId]);

    $mark = $pdo->prepare("
        UPDATE user_tickets
        SET status = 'EXHAUSTED'
        WHERE user_ticket_id = ? AND remaining_count <= 0
    ");
    $mark->execute([$ticketId]);

    $capacity = $pdo->prepare("
        UPDATE classes
        SET current_capacity = (
            SELECT COUNT(*) FROM reservations WHERE class_id = ? AND UPPER(TRIM(status)) = 'CONFIRMED'
        )
        WHERE class_id = ?
    ");
    $capacity->execute([$classId, $classId]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => '예약이 완료되었습니다.',
        'reservation_id' => $reservationId,
        'class_id' => $classId
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
