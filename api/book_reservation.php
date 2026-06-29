<?php
require_once __DIR__ . '/db.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = DB::getConnection();
    $pdo->beginTransaction();

    $sessionUserId = (int)$_SESSION['user_id'];
    $sessionRole = isset($_SESSION['user_role']) ? strtoupper(trim($_SESSION['user_role'])) : 'STUDENT';

    // API 파라미터 받기
    $class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : null;
    $slot_id = isset($_POST['slot_id']) ? (int)$_POST['slot_id'] : null;

    // 클래스 정보 조회 (class_id 또는 slot_id로부터)
    $class = null;
    if ($class_id) {
        $classStmt = $pdo->prepare("
            SELECT class_id, teacher_id, class_date, start_time, end_time, class_type, max_capacity, current_capacity
            FROM classes 
            WHERE class_id = ? AND status = 'AVAILABLE'
            LIMIT 1
        ");
        $classStmt->execute([$class_id]);
        $class = $classStmt->fetch(PDO::FETCH_ASSOC);
    } elseif ($slot_id) {
        // slot_id는 class_id로 취급
        $classStmt = $pdo->prepare("
            SELECT class_id, teacher_id, class_date, start_time, end_time, class_type, max_capacity, current_capacity
            FROM classes 
            WHERE class_id = ? AND status = 'AVAILABLE'
            LIMIT 1
        ");
        $classStmt->execute([$slot_id]);
        $class = $classStmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$class) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '선택한 수업을 찾을 수 없습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $studentId = $sessionUserId;
    $teacherId = (int)$class['teacher_id'];
    $classId = (int)$class['class_id'];

    // 중복 예약 확인
    $checkStmt = $pdo->prepare("
        SELECT COUNT(*) FROM reservations 
        WHERE user_id = ? AND class_id = ? AND status = 'CONFIRMED'
    ");
    $checkStmt->execute([$studentId, $classId]);
    $cnt = (int)$checkStmt->fetchColumn();
    if ($cnt > 0) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => '이 수업은 이미 예약했습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 정원 확인
    $maxCapacity = (int)$class['max_capacity'];
    $currentCapacity = (int)$class['current_capacity'];
    if ($currentCapacity >= $maxCapacity) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => '해당 수업은 이미 정원이 마감되었습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 사용 가능한 티켓 확인
    $ticketStmt = $pdo->prepare("
        SELECT user_ticket_id, remaining_count, expired_at 
        FROM user_tickets 
        WHERE user_id = ? AND status = 'ACTIVE' AND expired_at > NOW()
        LIMIT 1
    ");
    $ticketStmt->execute([$studentId]);
    $ticket = $ticketStmt->fetch(PDO::FETCH_ASSOC);

    if (!$ticket || $ticket['remaining_count'] <= 0) {
        $pdo->rollBack();
        http_response_code(402);
        echo json_encode(['success' => false, 'message' => '사용 가능한 수강권이 없습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $userTicketId = (int)$ticket['user_ticket_id'];

    // 예약 생성
    $insertStmt = $pdo->prepare("
        INSERT INTO reservations (user_id, class_id, user_ticket_id, status, reserved_at)
        VALUES (?, ?, ?, 'CONFIRMED', NOW())
    ");
    $insertStmt->execute([$studentId, $classId, $userTicketId]);
    $reservationId = $pdo->lastInsertId();

    // 클래스의 현재 정원 업데이트
    $updateClassStmt = $pdo->prepare("
        UPDATE classes 
        SET current_capacity = current_capacity + 1
        WHERE class_id = ?
    ");
    $updateClassStmt->execute([$classId]);

    // 티켓의 남은 횟수 업데이트
    $updateTicketStmt = $pdo->prepare("
        UPDATE user_tickets 
        SET remaining_count = remaining_count - 1
        WHERE user_ticket_id = ?
    ");
    $updateTicketStmt->execute([$userTicketId]);

    $pdo->commit();
    echo json_encode([
        'success' => true,
        'message' => '예약이 확정되었습니다. 마이페이지에서 확인하세요.',
        'reservation_id' => (int)$reservationId
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
