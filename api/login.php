<?php
require_once __DIR__ . '/db.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

// 공통 JSON 응답 헬퍼
// - HTTP 상태코드 설정
// - JSON 출력
// - 즉시 종료
function jsonResponse(int $statusCode, array $payload): void {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// 학생 권한 판별 헬퍼
// 현재 학생 페이지는 STUDENT 권한만 허용합니다.
function isStudentRole(?string $role): bool {
    $role = trim((string)$role);
    return strcasecmp($role, 'STUDENT') === 0;
}

// 현재 요청의 메서드 / 액션 확인
// - GET + action=session : 로그인된 세션 조회
// - POST                : 실제 로그인 처리
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = strtoupper(trim((string)($_GET['action'] ?? '')));

// ============================================================
// 1. 세션 조회 분기
// ------------------------------------------------------------
// 용도:
// - admin/dashboard.html 에서 새로고침 시 관리자 로그인 유지 확인
// - onlineclass.html 에서 새로고침 시 학생 로그인 유지 확인
//
// 사용 예:
// - /api/login.php?action=session&type=ADMIN
// - /api/login.php?action=session&type=STUDENT
// ============================================================
if ($method === 'GET' && $action === 'SESSION') {
    // 어떤 역할의 세션을 확인할지 받습니다.
    $requestedType = strtoupper(trim((string)($_GET['type'] ?? '')));

    // 현재 PHP 세션에 저장된 사용자 정보 읽기
    $sessionUserId = (int)($_SESSION['user_id'] ?? 0);
    $sessionUserName = trim((string)($_SESSION['user_name'] ?? ''));
    $sessionUserRole = strtoupper(trim((string)($_SESSION['user_role'] ?? '')));

    // 세션 자체가 없으면 로그인 안 된 상태로 반환
    if ($sessionUserId <= 0 || $sessionUserName === '' || $sessionUserRole === '') {
        jsonResponse(200, ['success' => false, 'user' => null]);
    }

    // 관리자 세션 확인 요청인데 실제 세션 역할이 ADMIN이 아니면 실패
    if ($requestedType === 'ADMIN' && $sessionUserRole !== 'ADMIN') {
        jsonResponse(200, ['success' => false, 'user' => null]);
    }

    // 학생 세션 확인 요청인데 실제 세션 역할이 STUDENT가 아니면 실패
    if ($requestedType === 'STUDENT' && !isStudentRole($sessionUserRole)) {
        jsonResponse(200, ['success' => false, 'user' => null]);
    }

    // 세션이 유효하면 최소 사용자 정보만 반환
    jsonResponse(200, [
        'success' => true,
        'user' => [
            'user_id' => $sessionUserId,
            'name' => $sessionUserName,
            'role' => $sessionUserRole
        ]
    ]);
}

// ============================================================
// 2. 실제 로그인 처리 분기
// ------------------------------------------------------------
// dashboard.html  -> type=ADMIN
// onlineclass.html -> type=STUDENT
// ============================================================
$id = isset($_POST['id']) ? trim($_POST['id']) : '';
$password = isset($_POST['password']) ? $_POST['password'] : '';
$loginType = isset($_POST['type']) ? strtoupper(trim($_POST['type'])) : 'STUDENT';

// 아이디/비밀번호는 로그인 시 필수입니다.
if (!$id || !$password) {
    jsonResponse(400, ['success' => false, 'message' => '아이디와 비밀번호를 입력해 주세요.']);
}

try {
    $pdo = DB::getConnection();

    // username 기준으로 사용자 1건 조회
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // 계정이 없으면 로그인 실패
    if (!$user) {
        jsonResponse(401, ['success' => false, 'message' => '아이디 또는 비밀번호가 일치하지 않습니다.']);
    }

    // 비밀번호 검증
    // - password_hash 로 저장된 계정 지원
    // - 예전 평문 데이터가 있을 가능성도 같이 대응
    if (!password_verify($password, $user['password']) && $password !== $user['password']) {
        jsonResponse(401, ['success' => false, 'message' => '아이디 또는 비밀번호가 일치하지 않습니다.']);
    }

    // DB의 역할값을 대문자로 정규화
    $dbRole = strtoupper(trim($user['role'] ?? ''));

    // 로그인 요청 타입별 권한 검증
    if ($loginType === 'ADMIN') {
        // 관리자 페이지 로그인은 ADMIN만 허용
        if ($dbRole !== 'ADMIN') {
            jsonResponse(403, ['success' => false, 'message' => '관리자 계정으로만 로그인할 수 있습니다.']);
        }
    } else {
        // 학생 페이지 로그인은 STUDENT만 허용
        if (!isStudentRole($user['role'] ?? '')) {
            jsonResponse(403, ['success' => false, 'message' => '학생 계정으로만 로그인할 수 있습니다.']);
        }
    }

    // 로그인 성공 시 공통 세션 저장
    // 같은 브라우저에서는 이 세션값을 기준으로 로그인 상태를 복원합니다.
    $_SESSION['user_id'] = (int)$user['user_id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_role'] = $dbRole;

    // 기본 응답 데이터 작성
    $responseData = [
        'success' => true,
        'message' => $loginType === 'ADMIN' ? '관리자 로그인에 성공했습니다.' : '로그인에 성공했습니다.',
        'user' => [
            'user_id' => (int)$user['user_id'],
            'name' => $user['name'],
            'role' => $dbRole
        ]
    ];

    // 학생 로그인일 때만 남은 티켓 수를 함께 응답
    // onlineclass.html 에서 마이페이지/구매 흐름에 활용 가능
    if ($loginType !== 'ADMIN') {
        $tstmt = $pdo->prepare('SELECT COALESCE(SUM(remaining_count), 0) FROM user_tickets WHERE user_id = ?');
        $tstmt->execute([$user['user_id']]);
        $remaining = (int)$tstmt->fetchColumn();
        $responseData['user']['tickets'] = $remaining;
    }

    jsonResponse(200, $responseData);
} catch (Throwable $e) {
    // 실제 서비스에서는 상세 에러를 숨기는 편이 안전하지만,
    // 현재는 디버깅 편의를 위해 메시지를 같이 반환합니다.
    jsonResponse(500, ['success' => false, 'message' => '서버 오류가 발생했습니다. ' . $e->getMessage()]);
}