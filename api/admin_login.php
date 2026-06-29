


<?php
require_once __DIR__ . '/db.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

$id = isset($_POST['id']) ? trim($_POST['id']) : '';
$password = isset($_POST['password']) ? $_POST['password'] : '';

if (!$id || !$password) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '아이디와 비밀번호를 입력해 주세요.']);
    exit;
}

try {
    $pdo = DB::getConnection();

    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => '아이디 또는 비밀번호가 일치하지 않습니다.']);
        exit;
    }

    if (!password_verify($password, $user['password']) && $password !== $user['password']) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => '아이디 또는 비밀번호가 일치하지 않습니다.']);
        exit;
    }

    if (strtoupper(trim($user['role'] ?? '')) !== 'ADMIN') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '관리자 계정으로만 로그인할 수 있습니다.']);
        exit;
    }

    $_SESSION['user_id'] = (int)$user['user_id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_role'] = strtoupper(trim($user['role']));

    echo json_encode([
        'success' => true,
        'message' => '관리자 로그인에 성공했습니다.',
        'user' => [
            'user_id' => (int)$user['user_id'],
            'name' => $user['name'],
            'role' => strtoupper(trim($user['role']))
        ]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '서버 오류가 발생했습니다.']);
}
//<!---파일곧폐기예정--->
// LOHIN.PHP통합예정LOHIN.PHP통합예정LOHIN.PHP통합예정LOHIN.PHP통합예정LOHIN.PHP통합예정