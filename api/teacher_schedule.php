<?php
require_once __DIR__ . '/db.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '???? ?????.']);
    exit;
}

try {
    $pdo = DB::getConnection();
    $user_id = (int)$_SESSION['user_id'];

    // find assigned teacher for this user
    $stmt = $pdo->prepare("SELECT teacher_id FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['teacher_id'])) {
        echo json_encode(['success' => false, 'message' => '?? ???? ???? ?????.']);
        exit;
    }

    $teacher_id = (int)$row['teacher_id'];

    $sql = "
        SELECT
            ts.id AS slot_id,
            ts.teacher_id,
            ts.start_time,
            ts.end_time,
            ts.lesson_type,
            ts.theme,
            ts.max_students,
            COALESCE(r.booked_count, 0) AS booked_count
        FROM time_slots ts
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
        WHERE ts.teacher_id = ?
          AND ts.start_time >= NOW()
        ORDER BY ts.start_time ASC
        LIMIT 300
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$teacher_id]);
    $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // normalize output
    $out = array_map(function($s){
        $max = (int)$s['max_students'];
        $booked = (int)$s['booked_count'];
        return [
            'slot_id' => (int)$s['slot_id'],
            'teacher_id' => (int)$s['teacher_id'],
            'start_time' => $s['start_time'],
            'end_time' => $s['end_time'],
            'lesson_type' => $s['lesson_type'],
            'theme' => $s['theme'],
            'max_students' => $max,
            'booked_count' => $booked,
            'is_full' => $booked >= $max
        ];
    }, $slots);

    echo json_encode(['success'=>true, 'data'=>$out]);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success'=>false, 'message'=>'??? ??: '.$e->getMessage()]);
    exit;
}
