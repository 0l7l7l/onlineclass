<?php
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
            c.class_id AS id,
            c.teacher_id,
            CONCAT(c.class_date, ' ', c.start_time) AS start_time,
            CONCAT(c.class_date, ' ', c.end_time) AS end_time,
            CASE
                WHEN c.class_type IN ('GROUP', 'DUO') THEN 'GROUP_25'
                ELSE 'PRIVATE_25'
            END AS lesson_type,
            c.max_capacity AS max_students,
            c.current_capacity AS booked_count,
            u.name AS teacher_name
        FROM classes c
        LEFT JOIN users u ON u.user_id = c.teacher_id
        WHERE c.class_id = ?
          AND c.status = 'AVAILABLE'
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
        INNER JOIN users u ON u.user_id = r.user_id
        WHERE r.class_id = ?
          AND UPPER(r.status) IN ('CONFIRMED', 'ATTENDED')
        ORDER BY u.name ASC
    ";

    $sstmt = $pdo->prepare($studentSql);
    $sstmt->execute([$slotId]);
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
