<?php
require_once __DIR__ . '/db.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
    exit;
}

try {
    $pdo = DB::getConnection();
    $pdo->beginTransaction();

    $sessionUserId = (int)$_SESSION['user_id'];
    $sessionRole = isset($_SESSION['user_role']) ? strtoupper(trim($_SESSION['user_role'])) : 'STUDENT';

    $student_id = isset($_POST['student_id']) ? (int)$_POST['student_id'] : null;
    $teacher_id = isset($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : null;
    $slot_id = isset($_POST['slot_id']) ? (int)$_POST['slot_id'] : null;
    $reserve_date = isset($_POST['reserve_date']) ? $_POST['reserve_date'] : null;
    $reserve_time = isset($_POST['reserve_time']) ? $_POST['reserve_time'] : null;
    $class_type = isset($_POST['class_type']) ? strtoupper($_POST['class_type']) : null;
    $type_param = isset($_POST['type']) ? strtoupper($_POST['type']) : null;

    $isAdminBooking = ($sessionRole === 'ADMIN' || $sessionRole === 'SUPPORTER') && $student_id && $teacher_id;

    if ($isAdminBooking) {
        $studentId = $student_id;
        $teacherId = $teacher_id;
    } else {
        $studentId = $sessionUserId;

        if ($slot_id) {
            $stmt = $pdo->prepare("SELECT teacher_id FROM time_slots WHERE id = ?");
            $stmt->execute([$slot_id]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($r) {
                $teacherId = (int)$r['teacher_id'];
            }
        }

        if (empty($teacherId)) {
            $stmt = $pdo->prepare("SELECT teacher_id FROM users WHERE user_id = ?");
            $stmt->execute([$studentId]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($r && !empty($r['teacher_id'])) {
                $teacherId = (int)$r['teacher_id'];
            }
        }
    }

    if (!$studentId || !$teacherId || !$reserve_date || !$reserve_time) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '필수 파라미터가 누락되었습니다. (student_id, teacher_id, reserve_date, reserve_time 확인)']);
        exit;
    }

    $classType = 'PRIVATE';
    if ($class_type === 'GROUP' || $type_param === 'GROUP') {
        $classType = 'GROUP';
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE student_id = ? AND reserve_date = ? AND reserve_time = ? AND status IN ('CONFIRMED','confirmed')");
    $stmt->execute([$studentId, $reserve_date, $reserve_time]);
    $cnt = (int)$stmt->fetchColumn();
    if ($cnt > 0) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => '이미 동일 시간에 예약이 존재합니다.']);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO reservations (student_id, teacher_id, class_type, reserve_date, reserve_time, status, created_at) VALUES (?, ?, ?, ?, ?, 'CONFIRMED', NOW())");
    $stmt->execute([$studentId, $teacherId, $classType, $reserve_date, $reserve_time]);

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => ($isAdminBooking ? '관리자로 예약을 생성했습니다.' : '예약이 확정되었습니다. 마이페이지에서 확인하세요.')]);
    exit;
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '시스템 오류: ' . $e->getMessage()]);
    exit;
}
