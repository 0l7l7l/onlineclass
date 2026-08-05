<?php
require_once __DIR__ . '/db.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '???? ?????.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int)$_SESSION['user_id'];

try {
    $pdo = DB::getConnection();

    $stmt = $pdo->prepare(" 
        SELECT p.title, MAX(ua.expired_at) AS expired_at
        FROM user_access ua
        JOIN products p ON p.product_id = ua.product_id
        WHERE ua.user_id = ?
          AND p.product_type = 'PDF'
          AND p.is_active = 1
        GROUP BY p.title
        HAVING MAX(ua.expired_at) > NOW()
        ORDER BY MAX(ua.expired_at) DESC
    ");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => [
            'accesses' => $rows,
            'server_now' => date('Y-m-d H:i:s')
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '?? ??? ???? ?????. ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
