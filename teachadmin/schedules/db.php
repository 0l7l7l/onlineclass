<?php
// db.php
$host = "mysql22.conoha.ne.jp";
$db_name = "1vk24_lesson_app";
$username = "1vk24_krlessonuser";
$password = "qweasd123!!";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    die("데이터베이스 연결 실패: " . $e->getMessage());
}
?>