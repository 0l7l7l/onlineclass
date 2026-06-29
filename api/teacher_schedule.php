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
    $user_id = (int)$_SESSION['user_id'];

    // find assigned teacher for this user
    $stmt = $pdo->prepare("SELECT teacher_id FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['teacher_id'])) {
        echo json_encode(['success' => false, 'message' => '담당 선생님이 지정되지 않았습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $teacher_id = (int)$row['teacher_id'];

    $sql = "
        SELECT
            c.class_id AS slot_id,
            c.teacher_id,
            c.class_date,
            c.start_time,
            c.end_time,
            c.class_type AS lesson_type,
            c.max_capacity AS max_students,
            c.current_capacity AS booked_count
        FROM classes c
        WHERE c.teacher_id = ?
          AND c.status = 'AVAILABLE'
          AND CONCAT(c.class_date, ' ', c.start_time) >= NOW()
        ORDER BY c.class_date ASC, c.start_time ASC
        LIMIT 300
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$teacher_id]);
    $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // normalize output
    $out = array_map(function($s){
        $max = (int)$s['max_students'];
        $booked = (int)$s['booked_count'];
        $classDate = $s['class_date'];
        $startTime = $s['start_time'];
        $endTime = $s['end_time'];
        
        return [
            'slot_id' => (int)$s['slot_id'],
            'teacher_id' => (int)$s['teacher_id'],
            'start_time' => $classDate . 'T' . $startTime,
            'end_time' => $classDate . 'T' . $endTime,
            'lesson_type' => $s['lesson_type'],
            'max_students' => $max,
            'booked_count' => $booked,
            'is_full' => $booked >= $max
        ];
    }, $slots);

    echo json_encode(['success'=>true, 'data'=>$out], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success'=>false, 'message'=>'서버 오류: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
