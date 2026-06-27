<?php
require_once __DIR__ . '/db.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '�α����� �ʿ��մϴ�.']);
    exit;
}

try {
    $pdo = DB::getConnection();
    $user_id = (int)$_SESSION['user_id'];

    // 1. ���� ���� ��ȸ (�ܾ�, �̸�, ����)
    $stmt = $pdo->prepare("SELECT name, role, current_money FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // 2. ��� ������ (����) Ƽ�ϼ� �հ�
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(remaining_count), 0) FROM user_tickets WHERE user_id = ? AND status = 'ACTIVE' AND expired_at > NOW()");
    $stmt->execute([$user_id]);
    $tickets = (int)$stmt->fetchColumn();

    // 3. ���� ���� �Ⱓ ��� (���� ���� ���� ������ ����)
    $stmt = $pdo->prepare("SELECT DATEDIFF(MAX(expired_at), NOW()) FROM user_access WHERE user_id = ? AND expired_at > NOW()");
    $stmt->execute([$user_id]);
    $pdf_days = $stmt->fetchColumn();
    $pdf_days = $pdf_days !== null ? max(0, (int)$pdf_days) : 0;

    // 4. ���� ���� (��������: Ȯ���� ���� �� ���� �ð� ���� ���� ���� ��)
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

    $next_class_text = "����";
    if ($next_class_data) {
        $weekDays = ['��', '��', 'ȭ', '��', '��', '��', '��'];
        $dayOfWeek = $weekDays[date('w', strtotime($next_class_data['class_date']))];
        $time = date('H:i', strtotime($next_class_data['start_time']));
        $next_class_text = "{$dayOfWeek} {$time}";
    }

    // 5. 최근 충전 요청 내역
    $stmt = $pdo->prepare("
        SELECT requested_at AS created_at, amount, depositor_name, status, reject_reason 
        FROM `request` 
        WHERE user_id = ? 
        ORDER BY requested_at DESC 
        LIMIT 5
    ");
    $stmt->execute([$user_id]);
    $histories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => [
            'name' => $user['name'] ?? '�̸�����',
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
    echo json_encode(['success' => false, 'message' => '�ý��� ������ �߻��߽��ϴ�.']);
}
