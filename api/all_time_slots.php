<?php
require_once __DIR__ . '/db.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = DB::getConnection();
    $date = isset($_GET['date']) ? trim($_GET['date']) : date('Y-m-d');

    $sql = "
        SELECT
            ts.id,
            ts.teacher_id,
            ts.start_time,
            ts.end_time,
            ts.lesson_type,
            ts.theme,
            ts.max_students,
            u.name AS teacher_name,
            COALESCE(r.booked_count, 0) AS booked_count
        FROM time_slots ts
        LEFT JOIN users u ON ts.teacher_id = u.user_id
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
        WHERE DATE(ts.start_time) = ?
        ORDER BY u.user_id, ts.start_time ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$date]);
    $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $slots], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
