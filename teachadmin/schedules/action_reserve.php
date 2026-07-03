<?php
// action_reserve.php
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = intval($_POST['user_id']);
    $lesson_date = $_POST['lesson_date'];
    $lesson_time = $_POST['lesson_time'];
    $fee_applied = isset($_POST['fee_applied']) ? intval($_POST['fee_applied']) : 0;

    if ($user_id > 0 && !empty($lesson_date) && !empty($lesson_time)) {
        try {
            // 만약 적용 수업료를 입력하지 않았다면, 해당 학생의 기본 단가를 가져와서 세팅
            if ($fee_applied <= 0) {
                $stmtFee = $pdo->prepare("SELECT lesson_fee FROM Users WHERE user_id = :user_id");
                $stmtFee->execute(['user_id' => $user_id]);
                $user = $stmtFee->fetch();
                $fee_applied = $user ? $user['lesson_fee'] : 0;
            }

            // schedules 테이블에 새 예약 인서트
            $stmt = $pdo->prepare("
                INSERT INTO `schedules` (`user_id`, `lesson_date`, `lesson_time`, `status`, `fee_applied`, `progress`) 
                VALUES (:user_id, :lesson_date, :lesson_time, 'scheduled', :fee, '')
            ");
            
            $stmt->execute([
                'user_id' => $user_id,
                'lesson_date' => $lesson_date,
                'lesson_time' => $lesson_time,
                'fee' => $fee_applied
            ]);
            
            echo "<script>alert('새로운 레슨 예약이 등록되었습니다.'); location.href='index.php?date=" . $lesson_date . "';</script>";
        } catch (\PDOException $e) {
            echo "<script>alert('예약 실패: " . addslashes($e->getMessage()) . "'); history.back();</script>";
        }
    } else {
        echo "<script>alert('학생, 날짜, 시간을 모두 정확히 입력해주세요.'); history.back();</script>";
    }
}
?>