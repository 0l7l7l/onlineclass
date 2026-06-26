<?php
require_once __DIR__ . '/db.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

function isStudentRole(?string $role): bool {
    $role = trim((string)$role);
    return $role === '학생' || strcasecmp($role, 'student') === 0 || stripos($role, 'student') !== false;
}

try {
    $pdo = DB::getConnection();

    $id = isset($_POST['id']) ? trim($_POST['id']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    if (!$id || !$password) {
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>'Required fields missing']);
        exit;
    }

    // 📌 대문자 'Users' 테이블에서 소문자 username 또는 email로 사용자를 찾습니다.
    $q = $pdo->prepare('SELECT * FROM Users WHERE username = ? OR email = ? LIMIT 1');
    $q->execute([$id, $id]);
    $user = $q->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        http_response_code(401);
        echo json_encode(['success'=>false,'message'=>'Invalid credentials']);
        exit;
    }

    // 💡 'jieun' 계정의 '0000' 평문 비밀번호와 기존 암호화 비밀번호를 둘 다 허용합니다.
    if (!password_verify($password, $user['password']) && $password !== $user['password']) {
        http_response_code(401);
        echo json_encode(['success'=>false,'message'=>'Invalid credentials']);
        exit;
    }

    if (!isStudentRole($user['role'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success'=>false,'message'=>'Only student accounts can log in'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Remaining ticket count.
    $tstmt = $pdo->prepare('SELECT COALESCE(SUM(remaining_count), 0) FROM Tickets WHERE student_id = ?');
    $tstmt->execute([$user['user_id']]);
    $remaining = (int)$tstmt->fetchColumn();

    // 세션에 로그인 정보 저장
    $_SESSION['user_id'] = (int)$user['user_id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_role'] = $user['role'];

    // ✨ [수정 완료] booking.html이 기다리는 소문자 'user' 키값으로 맞춰서 응답합니다.
    echo json_encode([
        'success' => true,
        'message' => 'Login successful',
        'user' => [
            'user_id' => (int)$user['user_id'],
            'name' => $user['name'],
            'role' => $user['role'],
            'tickets' => $remaining
        ]
    ]);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    exit;
}
