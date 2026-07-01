<?php
require_once __DIR__ . '/db.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$amount = isset($_POST['amount']) ? (int)$_POST['amount'] : 0;
$depositorName = isset($_POST['depositor_name']) ? trim((string)$_POST['depositor_name']) : '';

if ($amount < 1000) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '최소 1,000 세모 이상 충전하실 수 있습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($depositorName === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '입금자명을 입력해 주세요.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = DB::getConnection();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT user_id FROM users WHERE user_id = ? FOR UPDATE');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '사용자를 찾을 수 없습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare('INSERT INTO `request` (user_id, amount, payment_method, depositor_name, status) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$userId, $amount, 'BANK_TRANSFER', $depositorName, 'PENDING']);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => '충전 신청이 완료되었습니다. 입금 확인 후 세모가 반영됩니다.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '충전 신청 중 오류가 발생했습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}
