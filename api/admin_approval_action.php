<?php
require_once __DIR__ . '/db.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || strtoupper($_SESSION['user_role']) !== 'ADMIN') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '관리자 권한이 필요합니다.']);
    exit;
}

$request_id = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
$action = isset($_POST['action']) ? trim($_POST['action']) : '';
$reject_reason = isset($_POST['reject_reason']) ? trim($_POST['reject_reason']) : null;

if (!$request_id || !in_array($action, ['APPROVE', 'REJECT'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '잘못된 요청입니다.']);
    exit;
}

try {
    $pdo = DB::getConnection();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT * FROM `request` WHERE request_id = ? FOR UPDATE');
    $stmt->execute([$request_id]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '승인 요청을 찾을 수 없습니다.']);
        exit;
    }

    if ($request['status'] !== 'PENDING') {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '이미 처리된 요청입니다.']);
        exit;
    }

    if ($action === 'APPROVE') {
        $stmt = $pdo->prepare('UPDATE users SET current_money = current_money + ? WHERE user_id = ?');
        $stmt->execute([(int)$request['amount'], (int)$request['user_id']]);

        $stmt = $pdo->prepare('INSERT INTO wallet_histories (user_id, type, amount, balance_snapshot, target_id, description) VALUES (?, "CHARGE", ?, (SELECT current_money FROM users WHERE user_id = ?), NULL, ?)');
        $description = sprintf('관리자 승인 충전 요청 #%d', $request_id);
        $stmt->execute([(int)$request['user_id'], (int)$request['amount'], (int)$request['user_id'], $description]);

        $stmt = $pdo->prepare('UPDATE `request` SET status = ?, approved_by = ?, processed_at = NOW() WHERE request_id = ?');
        $stmt->execute(['APPROVED', (int)$_SESSION['user_id'], $request_id]);

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => '충전 요청이 승인되었습니다.']);
        exit;
    }

    if ($action === 'REJECT') {
        $stmt = $pdo->prepare('UPDATE `request` SET status = ?, reject_reason = ?, approved_by = ?, processed_at = NOW() WHERE request_id = ?');
        $stmt->execute(['REJECTED', $reject_reason, (int)$_SESSION['user_id'], $request_id]);
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => '충전 요청이 거절되었습니다.']);
        exit;
    }
} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '서버 오류가 발생했습니다.']);
}
