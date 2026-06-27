<?php
require_once __DIR__ . '/db.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$amount = isset($_POST['amount']) ? (int)$_POST['amount'] : 0;
$depositor_name = isset($_POST['depositor_name']) ? trim($_POST['depositor_name']) : '';

if ($amount < 10000) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '최소 10,000 세모 이상 신청 가능합니다.']);
    exit;
}

if ($depositor_name === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '입금자명을 입력해 주세요.']);
    exit;
}

try {
    $pdo = DB::getConnection();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT current_money FROM users WHERE user_id = ? FOR UPDATE');
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '사용자 정보를 찾을 수 없습니다.']);
        exit;
    }

    $balance_snapshot = (int)$user['current_money'];

    $stmt = $pdo->prepare(
        'INSERT INTO `request` (user_id, amount, payment_method, depositor_name, status) VALUES (?, ?, ?, ?, ?)' 
    );
    $stmt->execute([$user_id, $amount, 'BANK_TRANSFER', $depositor_name, 'PENDING']);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => '충전 신청이 정상적으로 접수되었습니다. 입금 확인 후 세모가 반영됩니다.'
    ]);
} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '서버 오류가 발생했습니다.']);
}
