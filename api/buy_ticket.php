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
$product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;

if ($product_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '상품 정보를 확인할 수 없습니다.']);
    exit;
}

try {
    $pdo = DB::getConnection();

    // 트랜잭션 시작
    $pdo->beginTransaction();

    // 1. 상품 정보 조회 (비관적 락 제외, 읽기 전용)
    $stmt = $pdo->prepare("SELECT * FROM products WHERE product_id = ? AND is_active = 1");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => '존재하지 않거나 판매 중단된 상품입니다.']);
        exit;
    }

    if ($product['product_type'] !== 'TICKET') {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => '수강 티켓 상품이 아닙니다.']);
        exit;
    }

    $price = (int)$product['price'];
    $total_count = (int)$product['total_count'];
    $expiry_days = (int)$product['expiry_days'];

    // 2. 유저 정보 조회 및 잔액 확인 (동시성 방지를 위해 행 락 적용)
    $stmt = $pdo->prepare("SELECT current_money FROM users WHERE user_id = ? FOR UPDATE");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => '유저 정보를 찾을 수 없습니다.']);
        exit;
    }

    $current_money = (int)$user['current_money'];

    if ($current_money < $price) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => '전자머니 잔액이 부족합니다.']);
        exit;
    }

    // 3. 잔액 차감
    $new_balance = $current_money - $price;
    $stmt = $pdo->prepare("UPDATE users SET current_money = ? WHERE user_id = ?");
    $stmt->execute([$new_balance, $user_id]);

    // 4. 전자머니 이력 기록 (balance_snapshot, target_id 포함)
    $stmt = $pdo->prepare("
        INSERT INTO wallet_histories (user_id, type, amount, balance_snapshot, target_id, description) 
        VALUES (?, 'BUY_PRODUCT', ?, ?, ?, ?)
    ");
    $description = $product['title'] . ' 구매';
    $stmt->execute([$user_id, -$price, $new_balance, $product_id, $description]);

    // 5. 티켓 발급 (만료일 계산 적용)
    $stmt = $pdo->prepare("
        INSERT INTO user_tickets (user_id, product_id, remaining_count, status, expired_at) 
        VALUES (?, ?, ?, 'ACTIVE', DATE_ADD(NOW(), INTERVAL ? DAY))
    ");
    $stmt->execute([$user_id, $product_id, $total_count, $expiry_days]);

    // 트랜잭션 커밋
    $pdo->commit();

    echo json_encode([
        'success' => true, 
        'message' => '티켓 구매가 성공적으로 완료되었습니다.',
        'data' => [
            'balance' => $new_balance
        ]
    ]);

} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '시스템 오류가 발생했습니다: ' . $e->getMessage()]);
}
