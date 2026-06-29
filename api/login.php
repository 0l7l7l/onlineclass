<?php
require_once __DIR__ . '/db.php';
session_start();
header('Content-Type: application/json; charset=utf-8');



// 1. 입력값 받기 (id, password, 로그인 타입 추가)
$id = isset($_POST['id']) ? trim($_POST['id']) : '';
$password = isset($_POST['password']) ? $_POST['password'] : '';
$loginType = isset($_POST['type']) ? strtoupper(trim($_POST['type'])) : 'STUDENT'; // 기본값 STUDENT



if (!$id || !$password) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '아이디와 비밀번호를 입력해 주세요.'], JSON_UNESCAPED_UNICODE);
    exit;
}



// 학생 권한 체크 함수
function isStudentRole(?string $role): bool {
    $role = trim((string)$role);
    return $role === '학생' || strcasecmp($role, 'student') === 0 || stripos($role, 'student') !== false;
}



try {
    $pdo = DB::getConnection();



    // 2. 유저 조회
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);



    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => '아이디 또는 비밀번호가 일치하지 않습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }



    // 3. 비밀번호 검증 (평문 & 암호화 둘 다 지원)
    if (!password_verify($password, $user['password']) && $password !== $user['password']) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => '아이디 또는 비밀번호가 일치하지 않습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }



    $dbRole = strtoupper(trim($user['role'] ?? ''));



    // 4. 요청한 로그인 타입에 따른 권한 검증
    if ($loginType === 'ADMIN') {
        // 관리자 로그인 요청인데 ADMIN이 아닌 경우
        if ($dbRole !== 'ADMIN') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => '관리자 계정으로만 로그인할 수 있습니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }//나중에 서포터, 선생님(강사)추가예정
    } else {
        // 학생 로그인 요청인데 학생 권한이 아닌 경우
        if (!isStudentRole($user['role'] ?? '')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => '학생 계정으로만 로그인할 수 있습니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }



    // 5. 공통 세션 저장
    $_SESSION['user_id'] = (int)$user['user_id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_role'] = $dbRole;



    // 6. 응답 데이터 조립 (학생일 때만 남은 티켓 수 조회)
    $responseData = [
        'success' => true,
        'message' => $loginType === 'ADMIN' ? '관리자 로그인에 성공했습니다.' : '로그인에 성공했습니다.',
        'user' => [
            'user_id' => (int)$user['user_id'],
            'name' => $user['name'],
            'role' => $dbRole
        ]
    ];



    if ($loginType !== 'ADMIN') {
        $tstmt = $pdo->prepare('SELECT COALESCE(SUM(remaining_count), 0) FROM user_tickets WHERE user_id = ?');
        $tstmt->execute([$user['user_id']]);
        $remaining = (int)$tstmt->fetchColumn();
        
        $responseData['user']['tickets'] = $remaining;
    }



    echo json_encode($responseData, JSON_UNESCAPED_UNICODE);
    exit;



} catch (Exception $e) {
    http_response_code(500);
    // 보안을 위해 실제 서비스 시에는 $e->getMessage() 대신 공통 에러 메시지를 추천합니다.
    echo json_encode(['success' => false, 'message' => '서버 오류가 발생했습니다. ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}