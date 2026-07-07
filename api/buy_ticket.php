<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema_helpers.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

function ensureTicketProduct(PDO $pdo, int $productId): array
{
    $catalog = [
        101 => ['title' => '1회 체험권', 'price' => 4500, 'class_type' => 'PRIVATE', 'total_count' => 1, 'expiry_days' => 30, 'per_week' => 0],
        102 => ['title' => '4회 수강권', 'price' => 20000, 'class_type' => 'PRIVATE', 'total_count' => 4, 'expiry_days' => 90, 'per_week' => 0],
        103 => ['title' => '8회 수강권', 'price' => 38000, 'class_type' => 'PRIVATE', 'total_count' => 8, 'expiry_days' => 180, 'per_week' => 0],
        201 => ['title' => '듀오 1회 체험권', 'price' => 2500, 'class_type' => 'DUO', 'total_count' => 1, 'expiry_days' => 30, 'per_week' => 0],
        202 => ['title' => '듀오 4회 수강권', 'price' => 11500, 'class_type' => 'DUO', 'total_count' => 4, 'expiry_days' => 90, 'per_week' => 0],
        203 => ['title' => '듀오 8회 수강권', 'price' => 21000, 'class_type' => 'DUO', 'total_count' => 8, 'expiry_days' => 180, 'per_week' => 0],
        301 => ['title' => '그룹 이벤트', 'price' => 15800, 'class_type' => 'GROUP', 'total_count' => 5, 'expiry_days' => 90, 'per_week' => 1],
        302 => ['title' => '그룹 4회 수강권', 'price' => 9900, 'class_type' => 'GROUP', 'total_count' => 4, 'expiry_days' => 90, 'per_week' => 1],
        303 => ['title' => '그룹 8회 수강권', 'price' => 15800, 'class_type' => 'GROUP', 'total_count' => 8, 'expiry_days' => 180, 'per_week' => 2],
        304 => ['title' => '그룹 1회 체험권(무료)', 'price' => 0, 'class_type' => 'GROUP', 'total_count' => 1, 'expiry_days' => 7, 'per_week' => 0],

        // 패키지 상품(결제 전용): 실제 사용 티켓은 구매 시 101 + 302로 분리 발급
        401 => ['title' => '특별할인 패키지(개인1+그룹4)', 'price' => 15800, 'class_type' => 'PRIVATE', 'total_count' => 1, 'expiry_days' => 30, 'per_week' => 0],
        402 => ['title' => '패키지 4회 수강권', 'price' => 14900, 'class_type' => 'PRIVATE', 'total_count' => 4, 'expiry_days' => 90, 'per_week' => 0],
        //403 => ['title' => '패키지 8회 수강권', 'price' => 49000, 'class_type' => 'PRIVATE', 'total_count' => 8, 'expiry_days' => 180, 'per_week' => 0],
    ];

    if (!isset($catalog[$productId])) {
        throw new InvalidArgumentException('지원하지 않는 티켓입니다.');
    }

    $meta = $catalog[$productId];

    $stmt = $pdo->prepare("SELECT * FROM products WHERE product_id = ? AND is_active = 1");
    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($product) {
        $needUpdate = ((int)$product['price'] !== (int)$meta['price'])
            || ((int)$product['total_count'] !== (int)$meta['total_count'])
            || ((int)$product['expiry_days'] !== (int)$meta['expiry_days'])
            || ((string)$product['class_type'] !== (string)$meta['class_type'])
            || ((string)$product['title'] !== (string)$meta['title'])
            || ((int)($product['per_week'] ?? 0) !== (int)($meta['per_week'] ?? 0));

        if ($needUpdate) {
            $u = $pdo->prepare("UPDATE products SET title = ?, price = ?, class_type = ?, total_count = ?, expiry_days = ?, per_week = ? WHERE product_id = ?");
            $u->execute([$meta['title'], $meta['price'], $meta['class_type'], $meta['total_count'], $meta['expiry_days'], $meta['per_week'], $productId]);

            $stmt = $pdo->prepare("SELECT * FROM products WHERE product_id = ? AND is_active = 1");
            $stmt->execute([$productId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        return $product;
    }

    $stmt = $pdo->prepare("INSERT INTO products (product_id, product_type, title, price, class_type, total_count, expiry_days, per_week, is_active) VALUES (?, 'TICKET', ?, ?, ?, ?, ?, ?, 1)");
    $stmt->execute([$productId, $meta['title'], $meta['price'], $meta['class_type'], $meta['total_count'], $meta['expiry_days'], $meta['per_week']]);

    $stmt = $pdo->prepare("SELECT * FROM products WHERE product_id = ? AND is_active = 1");
    $stmt->execute([$productId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;

if ($product_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '티켓 정보를 확인해 주세요.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = DB::getConnection();
    ensureTicketPerWeekColumns($pdo);
    $pdo->beginTransaction();

    $product = ensureTicketProduct($pdo, $product_id);

    if ($product['product_type'] !== 'TICKET') {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => '수강권 티켓이 아닙니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $price = (int)$product['price'];

    // 무료 상품(가격 0)인 경우, 한 계정당 1회 제한 검사 (서버 최종 검증)
    if ($price === 0) {
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM user_tickets WHERE user_id = ? AND product_id = ?");
        $checkStmt->execute([$user_id, $product_id]);
        $already = (int)$checkStmt->fetchColumn();
        if ($already > 0) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => '이 무료 체험티켓은 계정당 1회만 사용할 수 있습니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // 사용자 row 잠금 (동시성 대비)
    $stmt = $pdo->prepare("SELECT current_money FROM users WHERE user_id = ? FOR UPDATE");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => '사용자를 찾을 수 없습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $current_money = (int)$user['current_money'];

    if ($price > 0) {
        if ($current_money < $price) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => '보유 세모가 부족합니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $new_balance = $current_money - $price;
        $stmt = $pdo->prepare("UPDATE users SET current_money = ? WHERE user_id = ?");
        $stmt->execute([$new_balance, $user_id]);
    } else {
        $new_balance = $current_money;
    }

    // 지출/구매 이력 로깅
    $amountRecord = $price > 0 ? -$price : 0;
    $description = $price > 0 ? ($product['title'] . ' 구매') : ($product['title'] . ' 무료 체험티켓 발급');
    $stmt = $pdo->prepare("
        INSERT INTO wallet_histories (user_id, type, amount, balance_snapshot, target_id, description) 
        VALUES (?, 'BUY_PRODUCT', ?, ?, ?, ?)
    ");
    $stmt->execute([$user_id, $amountRecord, $new_balance, $product_id, $description]);

    $ticketInsert = $pdo->prepare("
        INSERT INTO user_tickets (user_id, product_id, remaining_count, status, expired_at, per_week) 
        VALUES (?, ?, ?, 'ACTIVE', DATE_ADD(NOW(), INTERVAL ? DAY), ?)
    ");

    //  특별할인패키지(401) => 개인1(101) + 그룹4(302) 분리 발급
    if ($product_id === 401) {
        $privateProduct = ensureTicketProduct($pdo, 101);
        $groupProduct = ensureTicketProduct($pdo, 302);

        $ticketInsert->execute([
            $user_id,
            101,
            (int)$privateProduct['total_count'],
            (int)$privateProduct['expiry_days'],
            (int)($privateProduct['per_week'] ?? 0)
        ]);

        $ticketInsert->execute([
            $user_id,
            302,
            (int)$groupProduct['total_count'],
            (int)$groupProduct['expiry_days'],
            (int)($groupProduct['per_week'] ?? 0)
        ]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => '특별할인 패키지 구매 완료: 개인 1회 + 그룹 4회 티켓이 발급되었습니다.',
            'data' => [
                'balance' => $new_balance
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 기본 단일 티켓 발급
    $ticketInsert->execute([
        $user_id,
        $product_id,
        (int)$product['total_count'],
        (int)$product['expiry_days'],
        (int)($product['per_week'] ?? 0)
    ]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => ($price === 0 ? '무료 체험티켓이 발급되었습니다.' : '수강권티켓 구매가 완료되었습니다.'),
        'data' => [
            'balance' => $new_balance
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '시스템 오류가 발생했습니다: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
