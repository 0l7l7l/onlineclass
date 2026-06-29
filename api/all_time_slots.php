<?php
<?php
require_once __DIR__ . '/db.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = DB::getConnection();
    $date = isset($_GET['date']) ? trim($_GET['date']) : date('Y-m-d');

    $sql = "
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
            u.name AS teacher_name,
            c.current_capacity AS booked_count
        FROM classes c
        LEFT JOIN users u ON c.teacher_id = u.user_id
        WHERE c.class_date = ?
          AND c.status = 'AVAILABLE'
        ORDER BY u.user_id, c.start_time ASC
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
