<?php
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = $_POST['password']; // 필요시 password_hash($password, PASSWORD_DEFAULT) 등으로 암호화 권장
    $phone = trim($_POST['phone']);
    $lesson_fee = intval($_POST['lesson_fee']);
    
    // 테이블 정의에 따른 기본값 세팅
    $role = 'student';
    $status = 'active';

    if (!empty($name) && !empty($email) && !empty($password)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO Users (email, password, name, phone, role, status, lesson_fee, created_at) 
                VALUES (:email, :password, :name, :phone, :role, :status, :lesson_fee, NOW())
            ");
            $stmt->execute([
                'email' => $email,
                'password' => $password, // 혹은 암호화된 변수
                'name' => $name,
                'phone' => !empty($phone) ? $phone : null,
                'role' => $role,
                'status' => $status,
                'lesson_fee' => $lesson_fee
            ]);
            
            echo "<script>alert('새로운 학생이 Users 테이블에 등록되었습니다.'); location.href='index.php';</script>";
        } catch (\PDOException $e) {
            echo "<script>alert('등록 실패: " . addslashes($e->getMessage()) . "'); history.back();</script>";
        }
    } else {
        echo "<script>alert('이름, 이메일, 비밀번호는 필수 입력 항목입니다.'); history.back();</script>";
    }
}
?>