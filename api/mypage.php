<?php
require_once __DIR__ . '/db.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = DB::getConnection();
    $userId = (int)$_SESSION['user_id'];

    $stmt = $pdo->prepare("
        SELECT name, role, current_money
        FROM users
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '사용자를 찾을 수 없습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(remaining_count), 0)
        FROM user_tickets
        WHERE user_id = ?
          AND status = 'ACTIVE'
          AND remaining_count > 0
          AND expired_at > NOW()
    ");
    $stmt->execute([$userId]);
    $tickets = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT DATEDIFF(MAX(expired_at), NOW())
        FROM user_access
        WHERE user_id = ?
          AND expired_at > NOW()
    ");
    $stmt->execute([$userId]);
    $pdfDays = $stmt->fetchColumn();
    $pdfDays = $pdfDays !== null ? max(0, (int)$pdfDays) : 0;

    $nextClassText = '없음';
    $stmt = $pdo->prepare("
        SELECT c.class_date, c.start_time
        FROM reservations r
        JOIN classes c ON r.class_id = c.class_id
        WHERE r.user_id = ?
          AND r.status = 'CONFIRMED'
          AND c.deleted_at IS NULL
          AND CONCAT(c.class_date, ' ', c.start_time) >= NOW()
        ORDER BY c.class_date ASC, c.start_time ASC
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $nextClass = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($nextClass) {
        $weekDays = ['일', '월', '화', '수', '목', '금', '토'];
        $dayOfWeek = $weekDays[(int)date('w', strtotime($nextClass['class_date']))];
        $time = date('H:i', strtotime($nextClass['start_time']));
        $nextClassText = "{$nextClass['class_date']} ({$dayOfWeek}) {$time}";
    }

    $stmt = $pdo->prepare("
        SELECT requested_at AS created_at, amount, depositor_name, status, reject_reason
        FROM `request`
        WHERE user_id = ?
        ORDER BY requested_at DESC
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    $histories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => [
            'name' => $user['name'] ?? '이름없음',
            'role' => $user['role'] ?? 'STUDENT',
            'money' => (int)($user['current_money'] ?? 0),
            'tickets' => $tickets,
            'pdf_days' => $pdfDays,
            'next_class' => $nextClassText,
            'histories' => $histories
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '마이페이지 정보를 불러오지 못했습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}
