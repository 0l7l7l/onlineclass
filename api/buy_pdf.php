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
$itemTitle = trim((string)($_POST['item_title'] ?? ''));

if ($itemTitle === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '??? ?? ??? ??? ???.'], JSON_UNESCAPED_UNICODE);
    exit;
}

function extractLectureNumber(string $title): ?int
{
    if (preg_match('/(\d+)/u', $title, $m)) {
        return (int)$m[1];
    }
    return null;
}

function resolvePdfPriceByTitle(string $title): int
{
    if (preg_match('/\s-\s???$/u', $title) === 1) {
        return 2880;
    }

    if (preg_match('/\s-\s1?$/u', $title) === 1) {
        return 0;
    }

    return 300;
}

try {
    $pdo = DB::getConnection();
    $pdo->beginTransaction();

    $price = resolvePdfPriceByTitle($itemTitle);
    $expiryDays = 2; // 48??
    $lectureNumber = extractLectureNumber($itemTitle);

    if ($price <= 0) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '?? ??? ?? ?? ??? ???.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $productStmt = $pdo->prepare("SELECT * FROM products WHERE product_type = 'PDF' AND title = ? AND is_active = 1 LIMIT 1");
    $productStmt->execute([$itemTitle]);
    $product = $productStmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        $insertProduct = $pdo->prepare("INSERT INTO products (product_type, title, price, lecture_number, class_type, total_count, expiry_days, per_week, is_active) VALUES ('PDF', ?, ?, ?, NULL, NULL, ?, 0, 1)");
        $insertProduct->execute([$itemTitle, $price, $lectureNumber, $expiryDays]);
        $productId = (int)$pdo->lastInsertId();
    } else {
        $productId = (int)$product['product_id'];
        $currentPrice = (int)$product['price'];
        $currentExpiry = (int)$product['expiry_days'];
        $currentLecture = isset($product['lecture_number']) ? (int)$product['lecture_number'] : null;

        if ($currentPrice !== $price || $currentExpiry !== $expiryDays || $currentLecture !== $lectureNumber) {
            $updateProduct = $pdo->prepare('UPDATE products SET price = ?, expiry_days = ?, lecture_number = ? WHERE product_id = ?');
            $updateProduct->execute([$price, $expiryDays, $lectureNumber, $productId]);
        }
    }

    $userStmt = $pdo->prepare('SELECT current_money FROM users WHERE user_id = ? FOR UPDATE');
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '???? ?? ? ????.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $currentMoney = (int)$user['current_money'];
    if ($currentMoney < $price) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '?? ??? ?????.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $newBalance = $currentMoney - $price;
    $updateMoney = $pdo->prepare('UPDATE users SET current_money = ? WHERE user_id = ?');
    $updateMoney->execute([$newBalance, $userId]);

    $historyStmt = $pdo->prepare("INSERT INTO wallet_histories (user_id, type, amount, balance_snapshot, target_id, description) VALUES (?, 'BUY_PRODUCT', ?, ?, ?, ?)");
    $historyStmt->execute([$userId, -$price, $newBalance, $productId, $itemTitle . ' PDF ??']);

    $accessStmt = $pdo->prepare('SELECT MAX(expired_at) FROM user_access WHERE user_id = ? AND product_id = ?');
    $accessStmt->execute([$userId, $productId]);
    $latestExpiryRaw = $accessStmt->fetchColumn();

    $baseTime = new DateTime();
    if ($latestExpiryRaw) {
        $latestExpiry = new DateTime((string)$latestExpiryRaw);
        if ($latestExpiry > $baseTime) {
            $baseTime = $latestExpiry;
        }
    }

    $newExpiry = (clone $baseTime)->modify('+48 hours')->format('Y-m-d H:i:s');

    $insertAccess = $pdo->prepare('INSERT INTO user_access (user_id, product_id, purchased_at, expired_at) VALUES (?, ?, NOW(), ?)');
    $insertAccess->execute([$userId, $productId, $newExpiry]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "[{$itemTitle}] ??? ???????! 48?? ?? ???? ?????.",
        'data' => [
            'balance' => $newBalance,
            'expired_at' => $newExpiry,
            'product_id' => $productId
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '??? ??? ??????. ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
