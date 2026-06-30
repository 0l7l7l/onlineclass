<?php
require_once __DIR__ . '/db.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

// [권한 게이트]
// ADMIN 세션이 아닐 경우 접근 차단
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || strtoupper($_SESSION['user_role']) !== 'ADMIN') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '관리자 권한이 필요합니다.']);
    exit;
}

try {
    $pdo = DB::getConnection();

    // [기능 1] 승인/거절 요청 목록 조회 (승인 탭 테이블에 표시)
    $stmt = $pdo->prepare("
        SELECT r.request_id, r.user_id, u.name AS user_name, u.username,
               r.amount, r.payment_method, r.depositor_name, r.status,
               r.reject_reason, r.requested_at, r.processed_at, r.approved_by
        FROM `request` r
        JOIN users u ON r.user_id = u.user_id
        ORDER BY r.requested_at DESC
        LIMIT 100
    ");
    $stmt->execute();
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // [기능 2] 홈 카드 - 당월 승인 금액 집계
    $monthApproved = (int)$pdo->query("
        SELECT COALESCE(SUM(amount), 0)
        FROM request
        WHERE status = 'APPROVED'
          AND YEAR(requested_at) = YEAR(CURRENT_DATE())
          AND MONTH(requested_at) = MONTH(CURRENT_DATE())
    ")->fetchColumn();

    // [기능 3] 홈 카드 - 올해 누적 승인 금액 집계
    $yearApproved = (int)$pdo->query("
        SELECT COALESCE(SUM(amount), 0)
        FROM request
        WHERE status = 'APPROVED'
          AND YEAR(requested_at) = YEAR(CURRENT_DATE())
    ")->fetchColumn();

    // [기능 4] 홈 카드 - 전체 활성 학생 수 집계
    $studentCount = (int)$pdo->query("
        SELECT COUNT(*)
        FROM users
        WHERE UPPER(role) = 'STUDENT'
          AND deleted_at IS NULL
    ")->fetchColumn();

    // [기능 5] 홈 카드 - 승인 대기 건수 집계
    $pendingCount = (int)$pdo->query("
        SELECT COUNT(*)
        FROM request
        WHERE status = 'PENDING'
    ")->fetchColumn();

    // [응답 구조]
    // data    : 승인 탭 테이블 데이터
    // summary : 홈 카드(대시보드 요약) 데이터
    echo json_encode([
        'success' => true,
        'data' => $requests,
        'summary' => [
            'month_approved_amount' => $monthApproved,
            'year_approved_amount' => $yearApproved,
            'student_count' => $studentCount,
            'pending_count' => $pendingCount
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
