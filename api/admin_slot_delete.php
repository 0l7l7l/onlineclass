<?php
require_once __DIR__ . '/db.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '???? ?????.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$role = strtoupper(trim($_SESSION['user_role'] ?? ''));
if (!in_array($role, ['ADMIN', 'SUPPORTER'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '??? ????.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$slotId = isset($_POST['slot_id']) ? (int)$_POST['slot_id'] : 0;
if ($slotId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'slot_id? ?????.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = DB::getConnection();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT id, teacher_id, start_time FROM time_slots WHERE id = ? LIMIT 1");
    $stmt->execute([$slotId]);
    $slot = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$slot) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '?? ??? ?? ? ????.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $teacherId = (int)$slot['teacher_id'];
    $reserveDate = date('Y-m-d', strtotime($slot['start_time']));
    $reserveTime = date('H:i:s', strtotime($slot['start_time']));

    $dres = $pdo->prepare("DELETE FROM reservations WHERE teacher_id = ? AND reserve_date = ? AND reserve_time = ?");
    $dres->execute([$teacherId, $reserveDate, $reserveTime]);
    $deletedReservations = $dres->rowCount();

    $dslot = $pdo->prepare("DELETE FROM time_slots WHERE id = ?");
    $dslot->execute([$slotId]);

    $pdo->commit();
    echo json_encode([
        'success' => true,
        'message' => '??? ???????.',
        'deleted_reservations' => $deletedReservations
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '??? ??: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
