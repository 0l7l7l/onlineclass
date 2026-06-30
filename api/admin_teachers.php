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

try {
    $pdo = DB::getConnection();

    $teacherId = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;

    if ($teacherId > 0) {
        $stmt = $pdo->prepare("SELECT user_id, name, username FROM users WHERE teacher_id = ? AND UPPER(role) = 'STUDENT' ORDER BY name ASC");
        $stmt->execute([$teacherId]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $students], JSON_UNESCAPED_UNICODE);
    } else {
        $sql = "
            SELECT user_id, name, username
            FROM users
            WHERE UPPER(role) = 'TEACHER'
            ORDER BY name ASC
        ";
        $stmt = $pdo->query($sql);
        $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'data' => $teachers], JSON_UNESCAPED_UNICODE);
    }
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB ??: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
