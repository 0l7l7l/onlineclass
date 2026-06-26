<?php
require_once __DIR__ . '/db.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

function isStudentRole(?string $role): bool {
    $role = trim((string)$role);
    // DB 스키마가 대문자 'STUDENT'이므로 안전하게 체크하도록 유지합니다.
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

    // 📌 1. 테이블명을 실제 DB에 맞게 소문자 'users'로 조회합니다.
    $q = $pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $q->execute([$id]); // 💡 [수정 완료] 오타였던 대괄호 닫기(])를 정상 수정했습니다.
    $user = $q->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        http_response_code(401);
        echo json_encode(['success'=>false,'message'=>'Invalid credentials']);
        exit;
    }

    // 💡 평문 비밀번호와 기존 암호화 비밀번호를 둘 다 허용합니다.
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

    // 📌 2. 실제 DB 스키마명인 'user_tickets' 테이블과 'user_id' 컬럼으로 조회합니다.
    // 만료된 티켓을 제외하고 싶다면 끝에 AND status = 'ACTIVE' 를 붙이셔도 좋습니다.
    $tstmt = $pdo->prepare('SELECT COALESCE(SUM(remaining_count), 0) FROM user_tickets WHERE user_id = ?');
    $tstmt->execute([$user['user_id']]);
    $remaining = (int)$tstmt->fetchColumn();

    // 세션에 로그인 정보 저장
    $_SESSION['user_id'] = (int)$user['user_id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_role'] = $user['role'];

    // ✨ booking.html이 기다리는 구조로 맞춰서 응답합니다.
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