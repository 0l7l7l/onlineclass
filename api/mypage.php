<?php
require_once __DIR__ . '/db.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
    exit;
}

try {
    $pdo = DB::getConnection();
    $user_id = (int)$_SESSION['user_id'];

    // 1. 유저 정보 조회 (잔액, 이름, 역할)
    $stmt = $pdo->prepare("SELECT name, role, current_money FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // 2. 사용 가능한 (보유) 티켓수 합계
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(remaining_count), 0) FROM user_tickets WHERE user_id = ? AND status = 'ACTIVE' AND expired_at > NOW()");
    $stmt->execute([$user_id]);
    $tickets = (int)$stmt->fetchColumn();

    // 3. 교재 열람 기간 계산 (가장 많이 남은 만료일 기준)
    $stmt = $pdo->prepare("SELECT DATEDIFF(MAX(expired_at), NOW()) FROM user_access WHERE user_id = ? AND expired_at > NOW()");
    $stmt->execute([$user_id]);
    $pdf_days = $stmt->fetchColumn();
    $pdf_days = $pdf_days !== null ? max(0, (int)$pdf_days) : 0;

    // 4. 다음 수업 (가져오기: 확정된 예약 중 현재 시간 이후 가장 빠른 것)
    $stmt = $pdo->prepare("
        SELECT c.class_date, c.start_time 
        FROM reservations r
        JOIN classes c ON r.class_id = c.class_id
        WHERE r.user_id = ? AND r.status = 'CONFIRMED' AND CONCAT(c.class_date, ' ', c.start_time) >= NOW()
        ORDER BY c.class_date ASC, c.start_time ASC
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $next_class_data = $stmt->fetch(PDO::FETCH_ASSOC);

    $next_class_text = "없음";
    if ($next_class_data) {
        $weekDays = ['일', '월', '화', '수', '목', '금', '토'];
        $dayOfWeek = $weekDays[date('w', strtotime($next_class_data['class_date']))];
        $time = date('H:i', strtotime($next_class_data['start_time']));
        $next_class_text = "{$dayOfWeek} {$time}";
    }

    // 5. 최근 세모 충전 신청 내역 
    // 충전 내역(CHARGE)은 wallet_histories 테이블에서 최신 내역 5장을 불러옵니다.
    $stmt = $pdo->prepare("
        SELECT created_at, amount, description 
        FROM wallet_histories 
        WHERE user_id = ? AND type = 'CHARGE'
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $stmt->execute([$user_id]);
    $histories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => [
            'name' => $user['name'] ?? '이름없음',
            'role' => $user['role'] ?? 'STUDENT',
            'money' => $user['current_money'] ?? 0,
            'tickets' => $tickets,
            'pdf_days' => $pdf_days,
            'next_class' => $next_class_text,
            'histories' => $histories
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '시스템 오류가 발생했습니다.']);
}
