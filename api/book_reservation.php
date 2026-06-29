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
    $reserve_date = isset($_POST['reserve_date']) ? trim($_POST['reserve_date']) : null;
    $reserve_time = isset($_POST['reserve_time']) ? trim($_POST['reserve_time']) : null;
    $class_type = isset($_POST['class_type']) ? strtoupper(trim($_POST['class_type'])) : null;
    $type_param = isset($_POST['type']) ? strtoupper(trim($_POST['type'])) : null;

    $slot = null;
    if ($slot_id) {
        $slotStmt = $pdo->prepare("SELECT id, teacher_id, start_time, lesson_type, max_students FROM time_slots WHERE id = ? LIMIT 1");
        $slotStmt->execute([$slot_id]);
        $slot = $slotStmt->fetch(PDO::FETCH_ASSOC);
        if (!$slot) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => '선택한 수업 슬롯을 찾을 수 없습니다.']);
            exit;
        }

        if (!$reserve_date) {
            $reserve_date = date('Y-m-d', strtotime($slot['start_time']));
        }
        if (!$reserve_time) {
            $reserve_time = date('H:i:s', strtotime($slot['start_time']));
        }
    }

    $isAdminBooking = ($sessionRole === 'ADMIN' || $sessionRole === 'SUPPORTER') && $student_id && $teacher_id;

    if ($isAdminBooking) {
        $studentId = $student_id;
        $teacherId = $teacher_id;
    } else {
        $studentId = $sessionUserId;

        if ($slot) {
            $teacherId = (int)$slot['teacher_id'];
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
    } elseif ($slot && strtoupper((string)$slot['lesson_type']) === 'GROUP_25') {
        $classType = 'GROUP';
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE student_id = ? AND reserve_date = ? AND reserve_time = ? AND status IN ('CONFIRMED','confirmed','예약완료')");
    $stmt->execute([$studentId, $reserve_date, $reserve_time]);
    $cnt = (int)$stmt->fetchColumn();
    if ($cnt > 0) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => '이미 동일 시간에 예약이 존재합니다.']);
        exit;
    }

    if ($slot) {
        $capStmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE teacher_id = ? AND reserve_date = ? AND reserve_time = ? AND status IN ('CONFIRMED','confirmed','예약완료')");
        $capStmt->execute([$teacherId, $reserve_date, $reserve_time]);
        $bookedCount = (int)$capStmt->fetchColumn();
        $maxStudents = (int)$slot['max_students'];

        if ($bookedCount >= $maxStudents) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => '해당 수업은 이미 정원이 마감되었습니다.']);
            exit;
        }
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
