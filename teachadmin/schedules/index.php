<?php
// ==================== 1. 데이터베이스 연결 및 데이터 로드 ====================
require_once 'db.php';

// 선택된 날짜 가져오기 (기본값: 오늘)
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

try {
    $stmt = $pdo->prepare(" 
        SELECT 
            s.id AS schedule_id,
            s.lesson_date,
            s.lesson_time,
            s.status,
            s.progress,
            s.fee_applied,
            s.next_schedule_id,
            s.user_id,
                u.user_id AS student_id,
                u.name AS student_name,
                u.phone AS student_phone,
                u.email AS student_email,
                u.lesson_fee AS base_fee,
            sn.lesson_date AS next_lesson_date,
            sn.lesson_time AS next_lesson_time
        FROM schedules s
        LEFT JOIN schedules sn ON s.next_schedule_id = sn.id
        JOIN Users u ON s.user_id = u.user_id
        WHERE s.lesson_date = :selected_date
        ORDER BY s.lesson_time ASC
    ");
    $stmt->execute(['selected_date' => $selected_date]);
    $schedules = $stmt->fetchAll();
    
    // 각 학생의 미래 예약 정보 미리 로드
    $nextLessons = [];
    foreach ($schedules as $row) {
        $nextStmt = $pdo->prepare("
            SELECT lesson_date, lesson_time
            FROM schedules
            WHERE user_id = :user_id AND lesson_date > :current_date
            ORDER BY lesson_date ASC, lesson_time ASC
            LIMIT 1
        ");
        $nextStmt->execute([
            'user_id' => $row['user_id'],
            'current_date' => $selected_date
        ]);
        $nextLesson = $nextStmt->fetch();
        $nextLessons[$row['schedule_id']] = $nextLesson;
    }

    $calendarYear = date('Y', strtotime($selected_date));
    $calendarMonth = date('m', strtotime($selected_date));
    $currentMonthStart = strtotime($calendarYear . '-' . $calendarMonth . '-01');
    $calendarMonthName = date('Y년 n월', $currentMonthStart);
    $calendarFirstWeekday = intval(date('w', $currentMonthStart));
    $calendarDaysInMonth = intval(date('t', $currentMonthStart));
    $calendarMonthStartDate = date('Y-m-01', $currentMonthStart);
    $calendarMonthEndDate = date('Y-m-t', $currentMonthStart);

    $stmtDays = $pdo->prepare("SELECT lesson_date, COUNT(*) AS count FROM schedules WHERE lesson_date BETWEEN :start AND :end GROUP BY lesson_date");
    $stmtDays->execute(['start' => $calendarMonthStartDate, 'end' => $calendarMonthEndDate]);
    $lessonDays = [];
    foreach ($stmtDays->fetchAll() as $row) {
        $lessonDays[$row['lesson_date']] = $row['count'];
    }

    // 학생 목록: 일정 등록용
    $stmtStudents = $pdo->query("
        SELECT user_id AS id, name, lesson_fee, phone, email 
        FROM Users 
        WHERE status = 'active'
        ORDER BY name ASC
    ");
    $all_students = $stmtStudents->fetchAll();

} catch (\PDOException $e) {
    die("데이터 로딩 실패: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>통합 레슨 스케줄러 대시보드</title>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Noto Sans KR', sans-serif; }
        body { background-color: #fcfcfc; color: #444; padding: 20px; display: flex; flex-direction: column; height: 100vh; font-size: 14px;}
        
        /* 상단 대시보드 바 */
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 12px; border-bottom: 2px solid #eee; }
        .header h1 { font-size: 20px; font-weight: 700; color: #222; }
        
        .btn-group { display: flex; gap: 8px; }
        .btn { padding: 8px 14px; border-radius: 4px; font-size: 13px; font-weight: 500; border: none; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; justify-content: center; gap: 5px; }
        .btn-outline { background-color: white; color: #666; border: 1px solid #ddd; }
        .btn-outline:hover { background-color: #f5f5f5; border-color: #ccc;}
        .btn-primary { background-color: #ff8c00; color: white; }
        .btn-primary:hover { background-color: #e67e00; }
        .btn-success { background-color: #fcfcfc; color: #ff8c00; border: 1px solid #ff8c00;}
        .btn-success:hover { background-color: #fff3e0;}
        .btn-danger { background-color: #f44336; color: white; }
        .btn-danger:hover { background-color: #d32f2f; }

        /* 메인 레이아웃 */
        .main-container { display: flex; align-items: flex-start; gap: 20px; flex: 1; min-height: 0; margin-bottom: 15px; }
        
        /* 좌측 달력 구역 */
        .sidebar-calendar { width: 300px; background: #ffffff; border-radius: 8px; border: 1px solid #eee; padding: 12px; display: flex; flex-direction: column; gap: 10px; }
        .calendar-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; font-weight: 700; color: #333; font-size: 15px;}
        .calendar-navigation button { border: none; background: transparent; color: #ff8c00; font-weight: 700; cursor: pointer; font-size: 16px; padding: 0 5px;}
        .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; text-align: center; font-size: 12px; }
        .day-name { font-weight: 500; color: #999; padding-bottom: 1px; }
        .day, .day-link { min-height: 28px; padding: 4px 0; border-radius: 4px; cursor: pointer; font-weight: 400; color: #555; display: inline-flex; justify-content: center; align-items: center; text-decoration: none; }
        .day-link:hover, .day:hover { background-color: #fff3e0; color: #ff8c00;}
        .day.selected, .day-link.selected { background-color: #ff8c00; color: white; font-weight: 500; }
        .day.has-lesson::after, .day-link.has-lesson::after { content: ''; display: block; width: 3px; height: 3px; background-color: #ff8c00; border-radius: 50%; margin: 1px auto 0; position: absolute; bottom: 4px;}
        .day-link.selected.has-lesson::after { background-color: white;}
        .day-link { position: relative; }

        .sidebar-actions { margin-top: auto; }

        /* 우측 당일 스케줄 구역 */
        .content-schedule { width: min(100%, 860px); flex: 0 1 860px; align-self: stretch; background: white; border-radius: 8px; border: 1px solid #eee; padding: 18px; display: flex; flex-direction: column; }
        .schedule-list-zone { width: 100%; overflow-y: auto; flex: 1; margin-bottom: 15px; padding-right: 5px;}
        
        /* 개선된 스케줄 타이틀 및 가이드 영역 */
        .schedule-title { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid #eee; }
        .schedule-title .date-info { font-size: 16px; font-weight: 700; color: #222; }
        .schedule-title .guide-info { font-size: 11px; color: #999; font-weight: normal; background-color: #f9f9f9; padding: 3px 8px; border-radius: 15px; border: 1px solid #eee;}
        
        /* 스케줄 리스트 아이템 */
        .schedule-item { width: 100%; display: grid; grid-template-columns: 220px minmax(260px, 1fr) 120px; align-items: center; gap: 12px; padding: 9px 12px; border: 1px solid #eee; border-radius: 6px; margin-bottom: 8px; cursor: pointer; transition: all 0.2s; }
        .schedule-item:hover { border-color: #ffcc80; background-color: #fffcf9; }
        .schedule-person { display: grid; grid-template-columns: 48px minmax(0, 1fr); align-items: center; gap: 10px; min-width: 0; }
        .schedule-detail { display: grid; grid-template-columns: 70px minmax(0, 1fr); align-items: center; gap: 10px; min-width: 0; }
        .schedule-fee { font-size: 13px; color: #555; font-weight: 600; text-align: right; white-space: nowrap; }
        .schedule-progress { min-width: 0; display: flex; align-items: center; gap: 7px; color: #888; }
        .schedule-progress-text { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 12px; }
        .schedule-history-btn { padding: 3px 6px; font-size: 11px; background: #fff; border: 1px solid #eee; color: #777; flex: 0 0 auto; }
        .schedule-status { text-align: left; }
        .time-box { font-weight: 500; color: #ff8c00; background-color: #fff3e0; padding: 3px 6px; border-radius: 4px; font-size: 12px; width: 48px; text-align: center; }
        .student-name { font-weight: 500; font-size: 14px; color: #222; }
        
        .status-badge { padding: 3px 8px; border-radius: 15px; font-size: 11px; font-weight: 500; display: inline-block; text-align: center; }

        /* 인라인 인체인지용 셀렉트 박스 스타일 */
        .inline-status-select { width: 92px; padding: 4px 8px; border-radius: 15px; font-size: 11px; font-weight: 500; border: 1px solid #ddd; outline: none; cursor: pointer; background-color: white; color: #777; appearance: none; -webkit-appearance: none; text-align: center; text-align-last: center;}
        .inline-status-select.completed { background-color: #e8f5e9; color: #2e7d32; border-color: #c8e6c9; }
        .inline-status-select.canceled { background-color: #fff3e0; color: #ef6c00; border-color: #ffe0b2; }
        .inline-status-select.no_show { background-color: #ffebee; color: #c62828; border-color: #ffcdd2; }

        /* 신규 추가: 보고서 텍스트 프리뷰 구역 스타일 */
        .report-preview-box { background-color: #fafafa; border: 1px solid #e0e0e0; border-radius: 6px; padding: 12px 15px; margin-bottom: 15px; max-height: 160px; overflow-y: auto; }
        .report-preview-box h3 { font-size: 12px; color: #ff8c00; font-weight: 700; margin-bottom: 6px; display: flex; justify-content: space-between; align-items: center; }
        .report-preview-content { font-family: 'Courier New', Courier, monospace, 'Noto Sans KR'; font-size: 12px; line-height: 1.6; color: #333; white-space: pre-wrap; }

        /* 하단 보고서 구역 구조 개선 */
        .report-zone { border-top: 1px solid #eee; padding-top: 15px; display: flex; gap: 10px; width: 100%; }

        /* 📌 공통 모달 스타일 */
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.3); display: none; justify-content: center; align-items: center; z-index: 1000; backdrop-filter: blur(1px);}
        .modal-box { background: white; padding: 25px; border-radius: 8px; width: 400px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); border: 1px solid #eee;}
        .modal-header { font-size: 16px; font-weight: 700; margin-bottom: 18px; display: flex; justify-content: space-between; align-items: center; color: #222; border-bottom: 1px solid #eee; padding-bottom: 10px;}
        .modal-close { cursor: pointer; color: #aaa; font-size: 20px; transition: color 0.2s;}
        .modal-close:hover { color: #ff8c00; }
        .form-group { margin-bottom: 12px; }
        .form-group label { display: block; font-size: 11px; font-weight: 500; color: #888; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px;}
        .input-text, .select-box, .textarea-box { width: 100%; padding: 8px 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 13px; outline: none; transition: border-color 0.2s; color: #444;}
        .input-text:focus, .select-box:focus, .textarea-box:focus { border-color: #ff8c00; }
        .textarea-box { resize: vertical; height: 60px;}

        /* 학생 목록 모달 전용 */
        .student-list-item { width: 100%; background: transparent; border-radius: 6px; padding: 10px; margin-bottom: 6px; text-align: left; cursor: pointer; transition: all 0.15s; border: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; gap: 10px; }
        .student-list-item:hover { background: #fffcf9; border-color: #ffcc80; }
        .student-item-content { display: flex; flex-direction: column; gap: 2px; flex: 1; }
        .student-item-title { font-weight: 500; color: #222; font-size: 13px;}
        .student-item-meta { font-size: 11px; color: #999; }
        .student-delete-btn { padding: 4px 8px; border-radius: 4px; background: #fff; color: #f44336; border: 1px solid #f44336; cursor: pointer; font-size: 11px; transition: all 0.2s;}
        .student-delete-btn:hover { background: #ffebee; }
        

        /* 스케줄 리스트 헤더 */
        .schedule-list-header { width: 100%; display: grid; grid-template-columns: 220px minmax(260px, 1fr) 120px; align-items: center; gap: 12px; padding: 4px 12px 6px; margin-bottom: 5px; font-size: 11px; font-weight: 500; color: #aaa; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #f5f5f5;}

        @media (max-width: 900px) {
            .main-container { flex-direction: column; }
            .sidebar-calendar { width: 100%; }
            .content-schedule { width: 100%; flex-basis: auto; }
            .schedule-item, .schedule-list-header { width: 100%; grid-template-columns: minmax(150px, 1fr) minmax(220px, 1.2fr) 94px; gap: 12px; }
        }

        /* 스크롤바 스타일 */
        ::-webkit-scrollbar { width: 5px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        ::-webkit-scrollbar-thumb { background: #ddd; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #ffcc80; }

        /* 히스토리 모달 전용 스타일 */
        .history-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 10px; }
        .history-item { display: flex; gap: 12px; align-items: flex-start; padding: 10px; border-radius: 8px; border: 1px solid #f1f5f9; background: #ffffff; }
        .history-date { min-width: 110px; font-size: 12px; color: #6b7280; }
        .history-meta { flex: 1; }
        .history-progress { font-size: 13px; color: #111827; line-height: 1.4; white-space: pre-wrap; }
        .history-status { font-size: 12px; padding: 4px 8px; border-radius: 999px; font-weight:700; }
        .history-status.completed { background:#e6ffed; color:#047857; border:1px solid #c6f6d5 }
        .history-status.canceled { background:#fff7ed; color:#994d00; border:1px solid #ffe8c2 }
        .history-status.no_show { background:#fff1f2; color:#9b1c1c; border:1px solid #ffd6d9 }

    </style>
</head>
<body>

    <div class="header">
        <h1>학생관리</h1>
        <div class="btn-group">
            <button class="btn btn-outline" onclick="location.href='Month.php'">반갑습니다. 👤 사용자님</button>
        </div>
    </div>

    <div class="main-container">
        
        <div class="sidebar-calendar">
            <div class="calendar-header">
                <span><?php echo $calendarMonthName; ?></span>
                <div class="calendar-navigation">
                    <button type="button" onclick="location.href='index.php?date=<?php echo htmlspecialchars(date('Y-m-d', strtotime('-1 month', $currentMonthStart)), ENT_QUOTES, 'UTF-8'); ?>'">◀</button>
                    <button type="button" onclick="location.href='index.php?date=<?php echo htmlspecialchars(date('Y-m-d', strtotime('+1 month', $currentMonthStart)), ENT_QUOTES, 'UTF-8'); ?>'">▶</button>
                </div>
            </div>
            <div class="calendar-grid">
                <div class="day-name">일</div><div class="day-name">월</div><div class="day-name">화</div><div class="day-name">수</div><div class="day-name">목</div><div class="day-name">금</div><div class="day-name">토</div>
                <?php for ($empty = 0; $empty < $calendarFirstWeekday; $empty++): ?>
                    <div></div>
                <?php endfor; ?>
                <?php for ($day = 1; $day <= $calendarDaysInMonth; $day++):
                    $dateKey = sprintf('%04d-%02d-%02d', $calendarYear, $calendarMonth, $day);
                    $dayClasses = 'day-link';
                    if ($dateKey === $selected_date) {
                        $dayClasses .= ' selected';
                    }
                    if (isset($lessonDays[$dateKey])) {
                        $dayClasses .= ' has-lesson';
                    }
                ?>
                    <a class="<?php echo $dayClasses; ?>" href="index.php?date=<?php echo $dateKey; ?>"><?php echo $day; ?></a>
                <?php endfor; ?>
            </div>

            <div class="sidebar-actions">
                <button class="btn btn-outline" style="width:100%; margin-bottom: 8px;" data-open-modal="studentListModal">👥 학생 목록 보기</button>
                <button class="btn btn-primary" style="width: 100%;" onclick="location.href='Month.php'">💰 월말 정산 보기</button>
            </div>
        </div>

        <div class="content-schedule">
            <div class="schedule-list-zone">
                
                <div class="schedule-title">
                    <span class="date-info">📍 <?php echo $selected_date; ?> 일정</span>
                    <span class="guide-info">💡 항목 클릭 시 상세 수정</span>
                </div>

                <div class="schedule-list-header">
                    <span>시간 / 학생</span>
                    <span>수업료 / 진도 및 메모</span>
                    <span>상태</span>
                </div>

                <?php if (empty($schedules)): ?>
                    <p style="text-align:center; padding:30px; color:#bbb; font-size: 13px;">등록된 수업 일정이 없습니다.</p>
                <?php else: ?>
                    <?php foreach ($schedules as $row): 
                        $status_class = ($row['status'] == 'completed') ? 'completed' : (($row['status'] == 'canceled') ? 'canceled' : (($row['status'] == 'no_show') ? 'no_show' : ''));
                        $display_fee = ($row['fee_applied'] > 0) ? $row['fee_applied'] : $row['base_fee'];
                        $lesson_time_short = substr($row['lesson_time'], 0, 5);
                        
                        // 다음 레슨 정보
                        $nextLesson = $nextLessons[$row['schedule_id']] ?? null;
                        $nextLessonDate = $nextLesson ? $nextLesson['lesson_date'] : '';
                        $nextLessonTime = $nextLesson ? substr($nextLesson['lesson_time'], 0, 5) : '';
                    ?>
                                <div class="schedule-item" 
                                      data-lesson-id="<?php echo htmlspecialchars($row['schedule_id'], ENT_QUOTES, 'UTF-8'); ?>"
                                          data-user-id="<?php echo htmlspecialchars($row['user_id'], ENT_QUOTES, 'UTF-8'); ?>"
                                      data-lesson-date="<?php echo htmlspecialchars($selected_date, ENT_QUOTES, 'UTF-8'); ?>"
                             data-student-name="<?php echo htmlspecialchars($row['student_name'], ENT_QUOTES, 'UTF-8'); ?>"
                             data-student-phone="<?php echo htmlspecialchars($row['student_phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                             data-student-email="<?php echo htmlspecialchars($row['student_email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                             data-student-fee="<?php echo htmlspecialchars($row['base_fee'], ENT_QUOTES, 'UTF-8'); ?>"
                             data-lesson-time="<?php echo htmlspecialchars($lesson_time_short, ENT_QUOTES, 'UTF-8'); ?>"
                             data-lesson-status="<?php echo htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8'); ?>"
                             data-lesson-fee="<?php echo htmlspecialchars($display_fee, ENT_QUOTES, 'UTF-8'); ?>"
                             data-lesson-progress="<?php echo htmlspecialchars($row['progress'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                             data-lesson-nextid="<?php echo htmlspecialchars($row['next_schedule_id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                             data-lesson-nextdate="<?php echo htmlspecialchars($row['next_lesson_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                             data-lesson-nexttime="<?php echo htmlspecialchars($row['next_lesson_time'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                             data-next-lesson-date="<?php echo htmlspecialchars($nextLessonDate, ENT_QUOTES, 'UTF-8'); ?>"
                             data-next-lesson-time="<?php echo htmlspecialchars($nextLessonTime, ENT_QUOTES, 'UTF-8'); ?>">
                            
                            <div class="schedule-person">
                                <span class="time-box"><?php echo $lesson_time_short; ?></span>
                                <div>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <span class="student-name"><?php echo htmlspecialchars($row['student_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <button type="button" class="btn-student-info" title="정보" style="background: none; border: none; cursor: pointer; color: #aaa; font-size: 12px; padding: 0;">ℹ️</button>
                                    </div>
                                </div>
                            </div>

                            <div class="schedule-detail">
                                <span class="schedule-fee"><?php echo number_format($display_fee); ?>엔</span>
                                <span class="schedule-progress">
                                    <span class="schedule-progress-text"><?php echo htmlspecialchars($row['progress'] ? $row['progress'] : '-', ENT_QUOTES, 'UTF-8'); ?></span>
                                    <button type="button" class="btn btn-outline schedule-history-btn" onclick="event.stopPropagation(); openHistoryModal('<?php echo htmlspecialchars($row['user_id'], ENT_QUOTES, 'UTF-8'); ?>','<?php echo htmlspecialchars($row['student_name'], ENT_QUOTES, 'UTF-8'); ?>')">기록</button>
                                </span>
                            </div>

                            <div class="schedule-status">
                                    <select class="card-status-select inline-status-select <?php echo $status_class; ?>">
                                        <option value="scheduled" <?php if($row['status']=='scheduled') echo 'selected'; ?>>📅예약됨</option>
                                        <option value="completed" <?php if($row['status']=='completed') echo 'selected'; ?>>🟢완료</option>
                                        <option value="canceled" <?php if($row['status']=='canceled') echo 'selected'; ?>>🟡당일취소</option>
                                        <option value="no_show" <?php if($row['status']=='no_show') echo 'selected'; ?>>🔴무단변경</option>
                                    </select>
                            </div>

                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="report-preview-box">
                <h3>
                    <span>📋 오늘 레슨 및 변경 보고 텍스트</span>
                    <button class="btn btn-outline" style="padding: 2px 8px; font-size: 11px;" onclick="copyReportText()">텍스트 복사</button>
                </h3>
                <div class="report-preview-content" id="reportPreviewText"><?php
                    $month_day = date('n月 j日', strtotime($selected_date));
                    echo "お疲れ様です。\n今日の\n";
                    
                    // 오늘 레슨 목록 출력
                    if (!empty($schedules)) {
                        foreach ($schedules as $row) {
                            $time_short = substr($row['lesson_time'], 0, 5);
                            $status_text = '予約済み';
                            if ($row['status'] == 'completed') $status_text = '完了';
                            elseif ($row['status'] == 'canceled') $status_text = 'キャンセル : 理由: 日付変更';
                            elseif ($row['status'] == 'no_show') $status_text = 'キャンセル : 理由: 無断';
                            
                            echo " {$row['student_name']}さん: {$month_day} の{$time_short} \n";
                        }
                    } else {
                        echo "오늘 등록된 레슨이 없습니다.\n";
                    }
                    echo "レッスン完了です。\n\n\n次のレッスンは\n\n";

                    // 다음 레슨 목록 출력 (실제 미래 예약 정보 사용)
                    $has_next = false;
                    $displayed_students = [];
                    if (!empty($schedules)) {
                        foreach ($schedules as $row) {
                            $nextLesson = $nextLessons[$row['schedule_id']] ?? null;
                            if ($nextLesson && !in_array($row['user_id'], $displayed_students)) {
                                $next_month_day = date('n月 j日', strtotime($nextLesson['lesson_date']));
                                $next_time = substr($nextLesson['lesson_time'], 0, 5);
                                echo " {$row['student_name']}さん,{$next_month_day}の{$next_time} \n";
                                $displayed_students[] = $row['user_id'];
                                $has_next = true;
                            }
                        }
                    }
                    if (!$has_next) {
                        echo "(지정된 다음 레슨 일정이 없습니다)\n";
                    }
                    echo "です。 よろしくお願いいたします。";
                ?></div>
            </div>

            <div class="report-zone">
                <button class="btn btn-success" data-open-modal="studentModal">
                    👤 학생 추가
                </button>
                <button class="btn btn-primary" onclick="openReservationModal()">
                    📅 일정 등록
                </button>
                <button class="btn btn-outline" style="flex: 1;" onclick="location.href='export_today.php?date=<?php echo $selected_date; ?>'">
                    📄 오늘 내역 CSV 다운로드
                </button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="studentListModal">
        <div class="modal-box">
            <div class="modal-header">
                <span>👥 학생 목록</span>
                <span class="modal-close" onclick="closeModal('studentListModal')">&times;</span>
            </div>
            <div style="max-height:250px; overflow-y:auto; padding-right: 5px;">
                <?php foreach ($all_students as $st): ?>
                    <div class="student-list-item" data-id="<?php echo htmlspecialchars($st['id']); ?>" data-name="<?php echo htmlspecialchars($st['name']); ?>" data-phone="<?php echo htmlspecialchars($st['phone'] ?? ''); ?>" data-email="<?php echo htmlspecialchars($st['email'] ?? ''); ?>" data-fee="<?php echo htmlspecialchars($st['lesson_fee']); ?>" onclick="handleStudentListClick(this)">
                        <div class="student-item-content">
                            <div class="student-item-title"><?php echo htmlspecialchars($st['name']); ?></div>
                            <div class="student-item-meta"><?php echo htmlspecialchars($st['phone']); ?> / <?php echo number_format($st['lesson_fee']); ?>엔</div>
                        </div>
                        <button type="button" class="student-delete-btn" onclick="event.stopPropagation(); deleteStudent(<?php echo json_encode($st['id'], JSON_HEX_APOS|JSON_HEX_QUOT); ?>)">삭제</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <div style="margin-top:15px;">
                <button class="btn btn-outline" style="width:100%;" onclick="closeModal('studentListModal')">닫기</button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="studentDetailModal">
        <div class="modal-box">
            <div class="modal-header">
                <span id="studentDetailName">학생 정보</span>
                <span class="modal-close" onclick="closeModal('studentDetailModal')">&times;</span>
            </div>
            <div class="form-group">
                <label>이메일</label>
                <div id="studentDetailEmail" style="font-weight:500; color:#444; font-size: 13px;"></div>
            </div>
            <div class="form-group">
                <label>전화번호</label>
                <div id="studentDetailPhone" style="color:#444; font-size: 13px;"></div>
            </div>
            <div class="form-group">
                <label>기본 레슨비</label>
                <div id="studentDetailFee" style="color:#ff8c00; font-weight: 500; font-size: 13px;"></div>
            </div>
            <div style="margin-top:18px;">
                <button class="btn btn-outline" style="width:100%;" onclick="closeModal('studentDetailModal')">닫기</button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="lessonModal">
        <div class="modal-box">
            <form action="action_lesson.php" method="POST">
                <input type="hidden" name="schedule_id" id="modalScheduleId">
                <input type="hidden" name="user_id" id="modalUserId">
                <input type="hidden" name="next_schedule_id" id="modalNextScheduleId">
                <input type="hidden" name="redirect_date" value="<?php echo $selected_date; ?>">
                
                <div class="modal-header">
                    <span id="lessonModalTitle">레슨 상세 수정</span>
                    <span class="modal-close" onclick="closeModal('lessonModal')">&times;</span>
                </div>
                <div class="form-group" style="display:flex; gap:10px; align-items:center;">
                    <div style="flex: 0 0 48%;">
                        <label>레슨 상태</label>
                        <select class="select-box" name="status" id="modalStatus">
                            <option value="scheduled">📅예약됨</option>
                            <option value="completed">🟢완료</option>
                            <option value="canceled">🟡당일취소</option>
                            <option value="no_show">🔴무단변경</option>
                        </select>
                    </div>
                    <div style="flex: 1;">
                        <label>확정 수업료 (엔)</label>
                        <input type="number" class="input-text" name="fee_applied" id="modalPrice" style="max-width:160px;">
                    </div>
                </div>
                <div class="form-group">
                    <label>수업 날짜 및 시간</label>
                    <div style="display:flex; gap:10px;">
                        <input type="date" class="input-text" name="lesson_date" id="modalLessonDate">
                        <input type="time" class="input-text" name="lesson_time" id="modalLessonTime">
                    </div>
                </div>
                <div class="form-group">
                    <label>진도 및 메모</label>
                    <textarea class="textarea-box" name="progress" id="modalProgress"></textarea>
                </div>
                <div class="form-group">
                 <label>📅 다음 수업 예정일</label>
                 <div style="display: flex; gap: 10px;">
                     <!-- 날짜 입력 칸 -->
                       <input type="date" class="input-text" name="next_lesson_date" id="modalNextDate">
                      <!-- 시간 입력 칸 -->
                       <input type="time" class="input-text" name="next_lesson_time" id="modalNextTime">
                    </div>
                </div>

                <div style="display: flex; gap: 8px; margin-top: 20px;">
                    <button type="button" class="btn btn-outline" style="flex:1;" onclick="closeModal('lessonModal')">취소</button>
                    <button type="button" class="btn btn-danger" style="flex:1;" onclick="deleteReservation(document.getElementById('modalScheduleId').value)">삭제</button>
                    <button type="submit" class="btn btn-primary" style="flex:1.5;">저장</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="studentModal">
        <div class="modal-box">
            <form action="action_student.php" method="POST">
                <div class="modal-header">
                    <span>👤 신규 학생 등록</span>
                    <span class="modal-close" onclick="closeModal('studentModal')">&times;</span>
                </div>
                
                <div class="form-group">
                    <label>이름</label>
                    <input type="text" class="input-text" name="name" required placeholder="홍길동">
                </div>
                <div class="form-group">
                    <label>이메일</label>
                    <input type="email" class="input-text" name="email" required placeholder="example@email.com">
                </div>
                <div class="form-group">
                    <label>초기 비밀번호</label>
                    <input type="password" class="input-text" name="password" required>
                </div>
                <div class="form-group">
                    <label>연락처</label>
                    <input type="text" class="input-text" name="phone" placeholder="010-0000-0000">
                </div>
                <div class="form-group">
                    <label>기본 레슨비</label>
                    <select class="select-box" name="lesson_fee">
                        <option value="1500">1,500 엔</option>
                        <option value="2000" selected>2,000 엔</option>
                        <option value="2500">2,500 엔</option>
                        <option value="3000">3,000 엔</option>
                    </select>
                </div>
                
                <div style="display: flex; gap: 8px; margin-top: 20px;">
                    <button type="button" class="btn btn-outline" style="flex:1;" onclick="closeModal('studentModal')">취소</button>
                    <button type="submit" class="btn btn-primary" style="flex:2;">등록</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="reservationModal">
        <div class="modal-box">
            <form action="action_reserve.php" method="POST">
                <div class="modal-header">
                    <span>📅 새 레슨 예약</span>
                    <span class="modal-close" onclick="closeModal('reservationModal')">&times;</span>
                </div>
                
                <div class="form-group">
                    <label>학생 선택</label>
                    <select class="select-box" name="user_id" required>
                        <option value="">학생을 선택하세요</option>
                        <?php foreach ($all_students as $st): ?>
                            <option value="<?php echo $st['id']; ?>">
                                <?php echo htmlspecialchars($st['name']); ?> (<?php echo number_format($st['lesson_fee']); ?>엔)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>날짜</label>
                    <input type="date" class="input-text" name="lesson_date" value="<?php echo $selected_date; ?>" required>
                </div>
                <div class="form-group">
                    <label>시간</label>
                    <input type="time" class="input-text" name="lesson_time" value="14:00" required>
                </div>
                <div class="form-group">
                    <label>수업료 (미입력 시 기본 단가 적용)</label>
                    <input type="number" class="input-text" name="fee_applied" placeholder="예: 2000">
                </div>

                <div class="form-group" id="reservationHistoryContainer" style="display:none;">
                    <label>이전 진도 및 메모 (최근 기록)</label>
                    <div id="reservationHistory" style="max-height:120px; overflow-y:auto; background:#fafafa; border:1px solid #eee; padding:8px; border-radius:6px; font-size:13px; white-space:pre-wrap; color:#333;"></div>
                </div>

                <div style="display: flex; gap: 8px; margin-top: 20px;">
                    <button type="button" class="btn btn-outline" style="flex:1;" onclick="closeModal('reservationModal')">취소</button>
                    <button type="submit" class="btn btn-primary" style="flex:2;">예약 등록</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 히스토리 모달 -->
    <div class="modal-overlay" id="historyModal">
        <div class="modal-box">
            <div class="modal-header">
                <span id="historyModalTitle">이전 수업 기록</span>
                <span class="modal-close" onclick="closeModal('historyModal')">&times;</span>
            </div>
            <div style="max-height:360px; overflow-y:auto; padding-right:6px;">
                <div id="historyContent" style="font-size:13px; white-space:pre-wrap; color:#333;"></div>
            </div>
            <div style="margin-top:12px; text-align:right;">
                <button class="btn btn-outline" onclick="closeModal('historyModal')">닫기</button>
            </div>
        </div>
    </div>

    <script>
        function decodeHtml(html) {
            var txt = document.createElement("textarea");
            txt.innerHTML = html;
            return txt.value;
        }

        // 📌 보고서 텍스트 복사 함수
        function copyReportText() {
            const text = document.getElementById('reportPreviewText').innerText;
            navigator.clipboard.writeText(text).then(() => {
                alert('보고용 텍스트가 클립보드에 복사되었습니다.');
            }).catch(err => {
                alert('복사에 실패했습니다. 직접 선택하여 복사해주세요.');
            });
        }

        function handleStudentListClick(btn) {
            const name = btn.getAttribute('data-name');
            const phone = btn.getAttribute('data-phone');
            const email = btn.getAttribute('data-email');
            const fee = btn.getAttribute('data-fee');
            
            document.getElementById('studentDetailName').innerText = name || '학생 정보';
            document.getElementById('studentDetailEmail').innerText = email || '-';
            document.getElementById('studentDetailPhone').innerText = phone || '-';
            try {
                var feeText = fee ? new Intl.NumberFormat().format(fee) + '엔' : '-';
            } catch(e) {
                var feeText = fee || '-';
            }
            document.getElementById('studentDetailFee').innerText = feeText;
            
            closeModal('studentListModal');
            document.getElementById('studentDetailModal').style.display = 'flex';
        }

        async function deleteStudent(id) {
            if (!confirm('이 학생을 삭제하시겠습니까?\n관련된 수업 일정도 함께 삭제됩니다.')) {
                return;
            }
            const formData = new FormData();
            formData.append('user_id', id);
            try {
                const res = await fetch('action_delete_student.php', { method: 'POST', body: formData });
                const text = await res.text();
                let data;
                try { data = JSON.parse(text); } catch (parseErr) { throw new Error('서버 응답 오류'); }
                if (data.success) { alert('학생이 삭제되었습니다.'); location.reload(); } 
                else { alert('삭제 실패: ' + (data.message || '서버 오류')); }
            } catch (err) { alert('오류가 발생했습니다: ' + err.message); }
        }

        async function deleteReservation(scheduleId) {
            if (!scheduleId || !confirm('이 일정을 삭제하시겠습니까?')) {
                return;
            }
            const formData = new FormData();
            formData.append('schedule_id', scheduleId);
            try {
                const res = await fetch('action_delete_reservation.php', { method: 'POST', body: formData });
                const text = await res.text();
                let data;
                try { data = JSON.parse(text); } catch (parseErr) { throw new Error('서버 응답 오류'); }
                if (data.success) { alert('예약이 삭제되었습니다.'); location.reload(); } 
                else { alert('삭제 실패: ' + (data.message || '서버 오류')); }
            } catch (err) { alert('오류가 발생했습니다: ' + err.message); }
        }

        function openLessonModal(card) {
            const id = card.getAttribute('data-lesson-id');
            const name = card.getAttribute('data-student-name');
            const time = card.getAttribute('data-lesson-time');
            const date = card.getAttribute('data-lesson-date') || '';
            const status = card.getAttribute('data-lesson-status');
            const price = card.getAttribute('data-lesson-fee');
            const progress = card.getAttribute('data-lesson-progress');
            const userId = card.getAttribute('data-user-id');
            const nextId = card.getAttribute('data-lesson-nextid') || '';
            const nextDate = card.getAttribute('data-lesson-nextdate') || card.getAttribute('data-next-lesson-date');
            const nextTime = card.getAttribute('data-lesson-nexttime') || card.getAttribute('data-next-lesson-time');
            
            document.getElementById('modalScheduleId').value = id;
            document.getElementById('lessonModalTitle').innerText = name + ' (' + time + ')';
            document.getElementById('modalStatus').value = status;
            document.getElementById('modalPrice').value = price;
            document.getElementById('modalProgress').value = decodeHtml(progress);
            document.getElementById('modalUserId').value = userId || '';
            document.getElementById('modalNextScheduleId').value = nextId || '';
            document.getElementById('modalNextDate').value = nextDate || '';
            document.getElementById('modalNextTime').value = nextTime || '';
            // populate editable lesson date/time
            document.getElementById('modalLessonDate').value = date || '';
            document.getElementById('modalLessonTime').value = time || '';
            document.getElementById('lessonModal').style.display = 'flex';
        }
        
        function openStudentDetailModal(card) {
            const name = card.getAttribute('data-student-name');
            const email = card.getAttribute('data-student-email');
            const phone = card.getAttribute('data-student-phone');
            const fee = card.getAttribute('data-student-fee');
            
            document.getElementById('studentDetailName').innerText = name || '학생 정보';
            document.getElementById('studentDetailEmail').innerText = email || '-';
            document.getElementById('studentDetailPhone').innerText = phone || '-';
            try {
                var feeText = fee ? new Intl.NumberFormat().format(fee) + '엔' : '-';
            } catch(e) {
                var feeText = fee || '-';
            }
            document.getElementById('studentDetailFee').innerText = feeText;
            document.getElementById('studentDetailModal').style.display = 'flex';
        }
        
        function openReservationModal() { document.getElementById('reservationModal').style.display = 'flex'; }
        
        function closeModal(modalId) { document.getElementById(modalId).style.display = 'none'; }

        async function quickStatusChangeAjax(id, selectElement) {
            if (!id) return;
            const status = selectElement.value;
            selectElement.disabled = true;
            try {
                const formData = new FormData();
                formData.append('schedule_id', id);
                formData.append('status', status);
                const res = await fetch('action_update_status.php', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    selectElement.classList.remove('completed','canceled','no_show');
                    if (status === 'completed') selectElement.classList.add('completed');
                    else if (status === 'canceled') selectElement.classList.add('canceled');
                    else if (status === 'no_show') selectElement.classList.add('no_show');
                    
                    // 상태가 바뀌면 프리뷰 텍스트도 실시간 동기화를 위해 새로고침 유도
                    location.reload();
                } else {
                    alert('변경 실패: ' + (data.message || '서버 오류'));
                }
            } catch (err) { alert('네트워크 오류'); } 
            finally { selectElement.disabled = false; }
        }

        // 히스토리 조회 및 렌더링 (예약 + 모달용)
        async function fetchHistory(userId) {
            if (!userId) return { records: [] , student_memo: '' };
            try {
                const res = await fetch('schedules.php?user_id=' + encodeURIComponent(userId));
                if (!res.ok) throw new Error('서버 응답 실패');
                const data = await res.json();
                return { records: data.records || [], student_memo: data.student_memo || '' };
            } catch (err) {
                console.error('히스토리 로드 실패', err);
                return { records: [], student_memo: '' };
            }
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/[&<>"'`]/g, function (s) {
                return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;', '`':'&#x60;'})[s];
            });
        }

        function renderHistoryList(records) {
            if (!records || records.length === 0) return '<div style="color:#9ca3af;">(이전 기록이 없습니다)</div>';
            const items = records.map(r => {
                const date = escapeHtml(r.lesson_date || '');
                const time = escapeHtml(r.lesson_time ? r.lesson_time.substring(0,5) : '');
                const progress = escapeHtml(r.progress || '-');
                const status = (r.status || '').toString();
                const statusClass = status === 'completed' ? 'completed' : (status === 'canceled' ? 'canceled' : (status === 'no_show' ? 'no_show' : ''));
                const badge = status ? `<div class="history-status ${statusClass}">${escapeHtml(status)}</div>` : '';
                return `<li class="history-item"><div class="history-date">${date}<br><span style="font-weight:700;">${time}</span></div><div class="history-meta"><div class="history-progress">${progress}</div></div>${badge}</li>`;
            });
            return `<ul class="history-list">${items.join('')}</ul>`;
        }

        async function openHistoryModal(userId, name) {
            document.getElementById('historyModalTitle').innerText = (name || '학생') + '님 이전 수업 기록';
            document.getElementById('historyContent').innerText = '로딩 중...';
            document.getElementById('historyModal').style.display = 'flex';
            const data = await fetchHistory(userId);
            // API는 schedules.progress에서 이전 진도들을 반환합니다.
            document.getElementById('historyContent').innerHTML = renderHistoryList(data.records);
        }

        // 예약 모달에서 학생 선택 시 이전 진도 표시
        document.addEventListener('DOMContentLoaded', function() {
            // 기존 DOMContentLoaded listener exists; add handler for reservation select
            const reservationSelect = document.querySelector('#reservationModal .select-box[name="user_id"], #reservationModal select[name="user_id"]');
            if (reservationSelect) {
                reservationSelect.addEventListener('change', async function() {
                    const uid = this.value;
                    const container = document.getElementById('reservationHistoryContainer');
                    const display = document.getElementById('reservationHistory');
                    if (!uid) { container.style.display = 'none'; display.innerText = ''; return; }
                    container.style.display = 'block'; display.innerText = '로딩 중...';
                    const data = await fetchHistory(uid);
                    display.innerHTML = renderHistoryList(data.records);
                });
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('[data-open-modal]').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var modal = document.getElementById(btn.getAttribute('data-open-modal'));
                    if (modal) modal.style.display = 'flex';
                });
            });

            document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
                overlay.addEventListener('click', function(e) {
                    if (e.target === overlay) overlay.style.display = 'none';
                });
            });

            document.querySelectorAll('.schedule-item').forEach(function(card) {
                card.addEventListener('click', function(e) {
                    if (e.target.closest('.btn-student-info') || e.target.closest('.card-status-select')) return;
                    openLessonModal(card);
                });

                var studentInfoBtn = card.querySelector('.btn-student-info');
                if (studentInfoBtn) {
                    studentInfoBtn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        openStudentDetailModal(card);
                    });
                }

                var statusSelect = card.querySelector('.card-status-select');
                if (statusSelect) {
                    statusSelect.addEventListener('click', function(e) { e.stopPropagation(); });
                    statusSelect.addEventListener('change', function(e) {
                        e.stopPropagation();
                        quickStatusChangeAjax(card.getAttribute('data-lesson-id'), statusSelect);
                    });
                }
            });
        });
    </script>
</body>
</html>
