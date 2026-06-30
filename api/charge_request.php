<?php
require_once __DIR__ . '/db.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '∑Œ±◊¿Œ¿Ã « ø‰«’¥œ¥Ÿ.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$amount = isset($_POST['amount']) ? (int)$_POST['amount'] : 0;
$depositor_name = isset($_POST['depositor_name']) ? trim($_POST['depositor_name']) : '';

if ($amount < 1000) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '√÷º“ 1,000 ºº∏ÅE¿ÃªÅE√Ê¿ÅEœΩ« ºÅE¿÷Ω¿¥œ¥Ÿ.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($depositor_name === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '¿‘±›¿⁄∏˙‹ª ¿‘∑¬«ÿ ¡÷ººøÅE'], JSON_UNESCAPED_UNICODE);
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
        echo json_encode(['success' => false, 'message' => 'ªÁøÅE⁄∏¶ √£¿ª ºÅEæ¯Ω¿¥œ¥Ÿ.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare('INSERT INTO `request` (user_id, amount, payment_method, depositor_name, status) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$user_id, $amount, 'BANK_TRANSFER', $depositor_name, 'PENDING']);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => '√Ê¿ÅEΩ≈√ª¿Ã øœ∑·µ«æ˙Ω¿¥œ¥Ÿ. ¿‘±› »Æ¿Œ »ƒ ¿⁄µø¿∏∑Œ π›øµµÀ¥œ¥Ÿ.'
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '√Ê¿ÅEΩ≈√ª ¡ﬂ ø¿∑˘∞° πﬂª˝«ﬂΩ¿¥œ¥Ÿ.'], JSON_UNESCAPED_UNICODE);
}
?>
