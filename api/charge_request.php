<?php
require_once __DIR__ . '/db.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '???? ?????.']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$amount = isset($_POST['amount']) ? (int)$_POST['amount'] : 0;
$depositor_name = isset($_POST['depositor_name']) ? trim($_POST['depositor_name']) : '';

if ($amount < 1000) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '?? 1,000 ?? ?? ?? ?????.']);
    exit;
}

if ($depositor_name === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '????? ??? ???.']);
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
        echo json_encode(['success' => false, 'message' => '??? ??? ?? ? ????.']);
        exit;
    }

    $stmt = $pdo->prepare('INSERT INTO `request` (user_id, amount, payment_method, depositor_name, status) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$user_id, $amount, 'BANK_TRANSFER', $depositor_name, 'PENDING']);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => '?? ??? ????? ???????. ?? ?? ? ??? ?????.'
    ]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '?? ??? ??????.']);
}
