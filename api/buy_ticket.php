<?php
require_once __DIR__ . '/db.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

function ensureTicketProduct(PDO $pdo, int $productId): array {
    $catalog = [
        101 => ['title' => '1회 체험권', 'price' => 40000, 'class_type' => 'PRIVATE', 'total_count' => 1, 'expiry_days' => 30],
        102 => ['title' => '4회 수강권', 'price' => 200000, 'class_type' => 'PRIVATE', 'total_count' => 4, 'expiry_days' => 90],
        103 => ['title' => '8회 수강권', 'price' => 380000, 'class_type' => 'PRIVATE', 'total_count' => 8, 'expiry_days' => 180],
        201 => ['title' => '듀오 1회 체험권', 'price' => 25000, 'class_type' => 'DUO', 'total_count' => 1, 'expiry_days' => 30],
        202 => ['title' => '듀오 4회 수강권', 'price' => 115000, 'class_type' => 'DUO', 'total_count' => 4, 'expiry_days' => 90],
        203 => ['title' => '듀오 8회 수강권', 'price' => 210000, 'class_type' => 'DUO', 'total_count' => 8, 'expiry_days' => 180],
        301 => ['title' => '그룹 1회 체험권', 'price' => 18000, 'class_type' => 'GROUP', 'total_count' => 1, 'expiry_days' => 30],
        302 => ['title' => '그룹 4회 수강권', 'price' => 85000, 'class_type' => 'GROUP', 'total_count' => 4, 'expiry_days' => 90],
        303 => ['title' => '그룹 8회 수강권', 'price' => 150000, 'class_type' => 'GROUP', 'total_count' => 8, 'expiry_days' => 180],
        401 => ['title' => '패키지 1회 체험권', 'price' => 60000, 'class_type' => 'PRIVATE', 'total_count' => 1, 'expiry_days' => 30],
        402 => ['title' => '패키지 4회 수강권', 'price' => 270000, 'class_type' => 'PRIVATE', 'total_count' => 4, 'expiry_days' => 90],
        403 => ['title' => '패키지 8회 수강권', 'price' => 490000, 'class_type' => 'PRIVATE', 'total_count' => 8, 'expiry_days' => 180],
    ];

    if (!isset($catalog[$productId])) {
        throw new InvalidArgumentException('지원하지 않는 상품입니다.');
    }

    $stmt = $pdo->prepare("SELECT * FROM products WHERE product_id = ? AND is_active = 1");
    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($product) {
        return $product;
    }

    $meta = $catalog[$productId];
    $stmt = $pdo->prepare("INSERT INTO products (product_id, product_type, title, price, class_type, total_count, expiry_days, is_active) VALUES (?, 'TICKET', ?, ?, ?, ?, ?, 1)");
    $stmt->execute([$productId, $meta['title'], $meta['price'], $meta['class_type'], $meta['total_count'], $meta['expiry_days']]);

    $stmt = $pdo->prepare("SELECT * FROM products WHERE product_id = ? AND is_active = 1");
    $stmt->execute([$productId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;

if ($product_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '상품 정보를 확인해 주세요.']);
    exit;
}

try {
    $pdo = DB::getConnection();
    $pdo->beginTransaction();

    $product = ensureTicketProduct($pdo, $product_id);

    if ($product['product_type'] !== 'TICKET') {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => '수강권 상품이 아닙니다.']);
        exit;
    }

    $price = (int)$product['price'];
    $total_count = (int)$product['total_count'];
    $expiry_days = (int)$product['expiry_days'];

    $stmt = $pdo->prepare("SELECT current_money FROM users WHERE user_id = ? FOR UPDATE");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => '사용자를 찾을 수 없습니다.']);
        exit;
    }

    $current_money = (int)$user['current_money'];

    if ($current_money < $price) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => '보유 세모가 부족합니다.']);
        exit;
    }

    $new_balance = $current_money - $price;
    $stmt = $pdo->prepare("UPDATE users SET current_money = ? WHERE user_id = ?");
    $stmt->execute([$new_balance, $user_id]);

    $stmt = $pdo->prepare("
        INSERT INTO wallet_histories (user_id, type, amount, balance_snapshot, target_id, description) 
        VALUES (?, 'BUY_PRODUCT', ?, ?, ?, ?)
    ");
    $description = $product['title'] . ' 구매';
    $stmt->execute([$user_id, -$price, $new_balance, $product_id, $description]);

    $stmt = $pdo->prepare("
        INSERT INTO user_tickets (user_id, product_id, remaining_count, status, expired_at) 
        VALUES (?, ?, ?, 'ACTIVE', DATE_ADD(NOW(), INTERVAL ? DAY))
    ");
    $stmt->execute([$user_id, $product_id, $total_count, $expiry_days]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => '수강권 구매가 완료되었습니다.',
        'data' => [
            'balance' => $new_balance
        ]
    ]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '시스템 오류가 발생했습니다: ' . $e->getMessage()]);
}
