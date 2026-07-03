<?php
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $schedule_id = isset($_POST['schedule_id']) ? intval($_POST['schedule_id']) : 0;
    $status = isset($_POST['status']) ? $_POST['status'] : null;
    $redirect_date = isset($_POST['redirect_date']) ? $_POST['redirect_date'] : date('Y-m-d'); // 보던 날짜 그대로 유지하기 위함
    $fee_applied = isset($_POST['fee_applied']) && $_POST['fee_applied'] !== '' ? intval($_POST['fee_applied']) : null;
    $progress = isset($_POST['progress']) ? trim($_POST['progress']) : null;
    $lesson_date = isset($_POST['lesson_date']) ? trim($_POST['lesson_date']) : null;
    $lesson_time = isset($_POST['lesson_time']) ? trim($_POST['lesson_time']) : null;
    $next_lesson_date = isset($_POST['next_lesson_date']) ? trim($_POST['next_lesson_date']) : null;
    $next_lesson_time = isset($_POST['next_lesson_time']) ? trim($_POST['next_lesson_time']) : null;
    $next_schedule_id = isset($_POST['next_schedule_id']) && $_POST['next_schedule_id'] !== '' ? intval($_POST['next_schedule_id']) : null;

    if ($schedule_id > 0 && $status !== null) {
        try {
            $stmtCurrent = $pdo->prepare("SELECT fee_applied, progress, lesson_date, lesson_time, user_id FROM schedules WHERE id = :id");
            $stmtCurrent->execute(['id' => $schedule_id]);
            $current = $stmtCurrent->fetch();

            if ($current) {
                if ($fee_applied === null) {
                    $fee_applied = $current['fee_applied'];
                }
                if ($progress === null) {
                    $progress = $current['progress'];
                }

                if ($lesson_date === null || $lesson_date === '') {
                    $lesson_date = $current['lesson_date'];
                }
                if ($lesson_time === null || $lesson_time === '') {
                    $lesson_time = $current['lesson_time'];
                }

                // If a next_schedule_id wasn't provided but a date/time was, find or create the referenced schedule
                if (empty($next_schedule_id) && $next_lesson_date) {
                    $foundId = null;
                    try {
                        $findStmt = $pdo->prepare("SELECT id FROM schedules WHERE user_id = :user_id AND lesson_date = :ld AND lesson_time = :lt LIMIT 1");
                        $findStmt->execute([
                            'user_id' => $current['user_id'],
                            'ld' => $next_lesson_date,
                            'lt' => ($next_lesson_time ?: '00:00')
                        ]);
                        $foundId = $findStmt->fetchColumn();
                    } catch (\Exception $e) { $foundId = null; }

                    if ($foundId) {
                        $next_schedule_id = intval($foundId);
                    } else {
                        // insert a new placeholder schedule for the next lesson and use its id
                        $ins = $pdo->prepare("INSERT INTO schedules (user_id, lesson_date, lesson_time, status, fee_applied, progress) VALUES (:user_id, :ld, :lt, 'scheduled', :fee, '')");
                        $ins->execute([
                            'user_id' => $current['user_id'],
                            'ld' => $next_lesson_date,
                            'lt' => ($next_lesson_time ?: '00:00'),
                            'fee' => ($current['fee_applied'] ?? 0)
                        ]);
                        $next_schedule_id = $pdo->lastInsertId();
                    }
                }

                // Build update to set next_schedule_id
                $sql = "UPDATE schedules SET status = :status, fee_applied = :fee_applied, progress = :progress, lesson_date = :lesson_date, lesson_time = :lesson_time, next_schedule_id = :next_schedule_id WHERE id = :id";

                $params = [
                    'status' => $status,
                    'fee_applied' => $fee_applied,
                    'progress' => $progress,
                    'lesson_date' => $lesson_date,
                    'lesson_time' => $lesson_time,
                    'next_schedule_id' => ($next_schedule_id ?: null),
                    'id' => $schedule_id
                ];

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            }

            // 리다이렉트: 변경된 레슨 날짜로 이동하고 싶으면 lesson_date 사용, 아니면 원래 보던 날짜 유지
            $targetDate = $lesson_date ?: $redirect_date;
            echo "<script>alert('레슨 내역이 반영되었습니다.'); location.href='index.php?date=" . $targetDate . "';</script>";
        } catch (\PDOException $e) {
            echo "<script>alert('저장 실패: " . addslashes($e->getMessage()) . "'); history.back();</script>";
        }
    } else {
        echo "<script>alert('잘못된 요청입니다.'); history.back();</script>";
    }
}
