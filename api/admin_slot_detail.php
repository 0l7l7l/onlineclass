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

$slotId = isset($_GET['slot_id']) ? (int)$_GET['slot_id'] : 0;
if ($slotId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'slot_id? ?????.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = DB::getConnection();

    $slotSql = "
        SELECT
            ts.id,
            ts.teacher_id,
            ts.start_time,
            ts.end_time,
            ts.lesson_type,
            ts.max_students,
            u.name AS teacher_name,
            COALESCE(r.booked_count, 0) AS booked_count
        FROM time_slots ts
        LEFT JOIN users u ON u.user_id = ts.teacher_id
        LEFT JOIN (
            SELECT
                teacher_id,
                reserve_date,
                reserve_time,
                COUNT(*) AS booked_count
            FROM reservations
            WHERE UPPER(status) = 'CONFIRMED' OR status = '????'
            GROUP BY teacher_id, reserve_date, reserve_time
        ) r
            ON r.teacher_id = ts.teacher_id
           AND r.reserve_date = DATE(ts.start_time)
           AND TIME(r.reserve_time) = TIME(ts.start_time)
        WHERE ts.id = ?
        LIMIT 1
    ";

    $stmt = $pdo->prepare($slotSql);
    $stmt->execute([$slotId]);
    $slot = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$slot) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '?? ??? ?? ? ????.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $studentSql = "
        SELECT u.user_id, u.name, u.username
        FROM reservations r
        INNER JOIN users u ON u.user_id = r.student_id
        WHERE r.teacher_id = ?
          AND r.reserve_date = DATE(?)
          AND TIME(r.reserve_time) = TIME(?)
          AND (UPPER(r.status) = 'CONFIRMED' OR r.status = '????')
        ORDER BY u.name ASC
    ";

    $sstmt = $pdo->prepare($studentSql);
    $sstmt->execute([$slot['teacher_id'], $slot['start_time'], $slot['start_time']]);
    $students = $sstmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => [
            'slot' => $slot,
            'students' => $students
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '??? ??: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
