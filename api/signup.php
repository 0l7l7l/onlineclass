<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema_helpers.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

function bad($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') bad('POST 요청 필요', 405);

$username = trim((string)($_POST['username'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$name = trim((string)($_POST['name'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$phone = trim((string)($_POST['phone'] ?? ''));
$agree = isset($_POST['agree']) && ($_POST['agree'] === '1' || $_POST['agree'] === 'true');

if (!$username || !$email || !$name || !$password) bad('필수 항목을 모두 입력해 주세요.');

if (!$agree) bad('약관 및 개인정보 처리방침에 동의해야 회원가입이 가능합니다.');

if (!preg_match('/^[A-Za-z0-9_]{4,50}$/', $username)) {
    bad('아이디는 영문, 숫자, 밑줄(_) 4~50자로 입력해 주세요.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    bad('이메일 형식이 올바르지 않습니다.');
}

if (strlen($password) < 8) bad('비밀번호는 최소 8자 이상이어야 합니다.');

try {
    $pdo = DB::getConnection();
    ensureUserSignupColumns($pdo);

    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    if ($stmt->fetch()) bad('이미 사용중인 아이디입니다.');

    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    if ($stmt->fetch()) bad('이미 가입된 이메일입니다.');

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $consent_version = 'v1.0';
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    $ins = $pdo->prepare("
        INSERT INTO users (
            username,
            password,
            name,
            role,
            teacher_id,
            current_money,
            phone_number,
            email,
            created_at,
            consent_version,
            consent_at,
            consent_ip
        )
        VALUES (?, ?, ?, 'STUDENT', NULL, 0, ?, ?, NOW(), ?, NOW(), ?)
    ");
    $ins->execute([$username, $hash, $name, ($phone !== '' ? $phone : null), $email, $consent_version, $ip]);

    echo json_encode(['success' => true, 'message' => '회원가입이 완료되었습니다.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '서버 오류: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
