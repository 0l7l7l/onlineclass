<?php
// export_settle.php
require_once 'db.php';

$selected_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');

try {
    // 월별 전체 원천 데이터 추출 쿼리
    $stmt = $pdo->prepare("
        SELECT 
            s.lesson_date,
            s.lesson_time,
            u.name AS student_name,
            s.status,
            IFNULL(NULLIF(s.fee_applied, 0), u.lesson_fee) AS fee,
            s.progress
        FROM schedules s
        JOIN Users u ON s.user_id = u.user_id
        WHERE DATE_FORMAT(s.lesson_date, '%Y-%m') = :selected_month
        ORDER BY s.lesson_date ASC, s.lesson_time ASC
    ");
    $stmt->execute(['selected_month' => $selected_month]);
    $results = $stmt->fetchAll();

    $filename = "monthly_settlement_report_" . $selected_month . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF"); // MS Excel 깨짐방지 UTF-8 BOM 삽입

    // 헤더 행 세팅
    fputcsv($output, ['레슨 일자', '레슨 시간', '학생 이름', '레슨 상태', '정산 금액 (엔)', '진도 내역 및 패널티 사유', '정산 유무']);

    foreach ($results as $row) {
        $status_text = '예약 대기';
        $is_settled = '미정산 (대기)';

        if ($row['status'] === 'completed') {
            $status_text = '🟢 정상 완료';
            $is_settled = '정산 포함';
        } else if ($row['status'] === 'canceled') {
            $status_text = '🟡 당일 취소';
            $is_settled = '정산 포함 (수수료 규정반영)';
        } else if ($row['status'] === 'no_show') {
            $status_text = '🔴 무단 노쇼';
            $is_settled = '정산 포함 (100% 청구)';
        }

        fputcsv($output, [
            $row['lesson_date'],
            substr($row['lesson_time'], 0, 5),
            $row['student_name'],
            $status_text,
            $row['fee'],
            $row['progress'],
            $is_settled
        ]);
    }

    fclose($output);
    exit;

} catch (\PDOException $e) {
    die("정산 파일 처리 중 예외 발생: " . $e->getMessage());
}
?>