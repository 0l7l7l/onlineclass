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

    // 학생의 담당 선생님 조회
    $stmt = $pdo->prepare("SELECT teacher_id FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $teacher_id = $row ? (int)($row['teacher_id'] ?? 0) : 0;

    $slots = [];

    // ① PRIVATE / DUO: 담당 선생님 스케줄만 표시
    //    - class_targets에 지정된 학생이 없는(오픈) 슬롯 OR 본인이 지정된 슬롯만 허용
    if ($teacher_id > 0) {
        $sqlPrivate = "
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
              AND c.class_type IN ('PRIVATE', 'DUO', 'PRIVATE_11', 'DUO_12')
              AND c.deleted_at IS NULL
              AND CONCAT(c.class_date, ' ', c.start_time) >= NOW()
              AND (
                  NOT EXISTS (SELECT 1 FROM class_targets ct WHERE ct.class_id = c.class_id)
                  OR EXISTS (SELECT 1 FROM class_targets ct WHERE ct.class_id = c.class_id AND ct.user_id = ?)
              )
            ORDER BY c.class_date ASC, c.start_time ASC
            LIMIT 300
        ";
        $stmtPrivate = $pdo->prepare($sqlPrivate);
        $stmtPrivate->execute([$teacher_id, $user_id]);
        $slots = $stmtPrivate->fetchAll(PDO::FETCH_ASSOC);
    }

    // ② GROUP: 담당 선생님 무관하게 모든 학생에게 표시
    $sqlGroup = "
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
        WHERE c.class_type = 'GROUP'
          AND c.deleted_at IS NULL
          AND CONCAT(c.class_date, ' ', c.start_time) >= NOW()
        ORDER BY c.class_date ASC, c.start_time ASC
        LIMIT 300
    ";
    $stmtGroup = $pdo->query($sqlGroup);
    $groupSlots = $stmtGroup->fetchAll(PDO::FETCH_ASSOC);

    // 중복 제거 후 병합 (slot_id 기준)
    $merged = [];
    foreach (array_merge($slots, $groupSlots) as $s) {
        $merged[(int)$s['slot_id']] = $s;
    }

    usort($merged, fn($a, $b) => strcmp(
        $a['class_date'] . $a['start_time'],
        $b['class_date'] . $b['start_time']
    ));

    $out = array_map(function ($s) {
        $max    = (int)$s['max_students'];
        $booked = (int)$s['booked_count'];
        return [
            'slot_id'      => (int)$s['slot_id'],
            'teacher_id'   => (int)$s['teacher_id'],
            'start_time'   => $s['class_date'] . 'T' . $s['start_time'],
            'end_time'     => $s['class_date'] . 'T' . $s['end_time'],
            'lesson_type'  => $s['lesson_type'],
            'max_students' => $max,
            'booked_count' => $booked,
            'is_full'      => $booked >= $max
        ];
    }, array_values($merged));

    echo json_encode(['success' => true, 'data' => $out], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '서버 오류: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
