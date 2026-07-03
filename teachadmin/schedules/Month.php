<?php
// Month.php
require_once 'db.php';

$selected_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');

try {
    // 1. 대시보드 요약 통계 산출용 통계 쿼리
    $stmtSummary = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN s.status IN ('completed', 'canceled', 'no_show') THEN IFNULL(NULLIF(s.fee_applied, 0), u.lesson_fee) ELSE 0 END) as total_revenue,
            SUM(CASE WHEN s.status = 'completed' THEN 1 ELSE 0 END) as complete_count,
            SUM(CASE WHEN s.status IN ('canceled', 'no_show') THEN 1 ELSE 0 END) as penalty_count
        FROM schedules s
        JOIN Users u ON s.user_id = u.user_id
        WHERE DATE_FORMAT(s.lesson_date, '%Y-%m') = :selected_month
    ");
    $stmtSummary->execute(['selected_month' => $selected_month]);
    $summary = $stmtSummary->fetch();

    // 2. 학생별 세부 내역 집계 리스트 구하기
    $stmtList = $pdo->prepare("
        SELECT 
            u.name,
            u.lesson_fee AS base_fee,
            SUM(CASE WHEN s.status = 'completed' THEN 1 ELSE 0 END) AS completed_lessons,
            SUM(CASE WHEN s.status IN ('canceled', 'no_show') THEN 1 ELSE 0 END) AS penalty_lessons,
            SUM(CASE WHEN s.status IN ('completed', 'canceled', 'no_show') THEN IFNULL(NULLIF(s.fee_applied, 0), u.lesson_fee) ELSE 0 END) AS total_fee
        FROM Users u
        JOIN schedules s ON u.user_id = s.user_id
        WHERE DATE_FORMAT(s.lesson_date, '%Y-%m') = :selected_month
        GROUP BY u.user_id
    ");
    $stmtList->execute(['selected_month' => $selected_month]);
    $student_reports = $stmtList->fetchAll();

} catch (\PDOException $e) {
    die("정산 데이터 추출 실패: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <title>월말 정산 및 보고 관리</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root{
            --accent:#4f46e5;
            --muted:#6b7280;
            --card-bg:#ffffff;
            --surface:#f8fafc;
            --border:#e6e9ef;
            --radius:8px;
            --max-width:210mm; /* A4 width for print */
        }
        html,body{height:100%;}
        body{font-family:Inter, system-ui, -apple-system, "Segoe UI", Roboto, "Noto Sans KR", 'Noto Sans', sans-serif; background: #f3f4f6; color:#111827; margin:20px;}

        /* Page container sized for A4 when printing, and centered on screen */
        .page{max-width:var(--max-width); margin:0 auto; background:var(--card-bg); padding:18mm; box-shadow:0 8px 24px rgba(16,24,40,0.08); border-radius:6px;}

        .header-container{display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:18px;}
        .title-zone h1{font-size:20px; margin-bottom:6px;}
        .title-zone p{color:var(--muted); margin:0; font-size:13px}

        .filter-zone{display:flex; gap:10px; align-items:center}
        .month-select{padding:8px 10px; border:1px solid var(--border); border-radius:6px; background:white}
        .btn{padding:8px 12px; border-radius:6px; font-weight:600; border:1px solid transparent; cursor:pointer}
        .btn-outline{background:transparent; color:var(--muted); border-color:var(--border)}
        .btn-primary{background:var(--accent); color:white}
        .btn-print{background:#111827;color:#fff}

        .summary-grid{display:flex; gap:12px; margin-bottom:16px}
        .summary-card{flex:1; background:var(--surface); border:1px solid var(--border); padding:12px; border-radius:var(--radius)}
        .card-label{font-size:12px; color:var(--muted); margin-bottom:6px}
        .card-value{font-size:18px; font-weight:700}
        .card-sub{font-size:12px; color:var(--muted); margin-top:8px}

        .card{background:white; border-radius:6px; padding:12px; border:1px solid var(--border)}
        table{width:100%; border-collapse:collapse; font-size:13px}
        thead th{background:#f8fafc; text-align:left; padding:10px 12px; font-weight:700; color:#374151; border-bottom:1px solid var(--border)}
        tbody td{padding:12px; border-bottom:1px solid #f1f5f9; vertical-align:middle}
        tbody tr:last-child td{border-bottom:none}
        .count-badge{background:#eef2ff;color:var(--accent);padding:4px 8px;border-radius:999px;font-weight:700}
        .price-text{font-weight:800; color:#111827}

        /* Print styles for A4 */
        @page { size: A4; margin: 10mm }
        @media print {
            body{background:white; margin:0}
            .page{box-shadow:none;border-radius:0;padding:12mm; margin:0}
            .filter-zone, .btn{display:none}
            thead{display:table-header-group} /* try to repeat header */
            tbody{display:table-row-group}
            .summary-grid{page-break-inside:avoid}
            .card{box-shadow:none;border:0}
        }

        /* Responsive small screens */
        @media (max-width:800px){
            .header-container{flex-direction:column; align-items:stretch}
            .summary-grid{flex-direction:column}
            .page{padding:12px; margin:12px}
        }
    </style>
</head>
<body>

    <div class="page">
    <div class="header-container">
        <div class="title-zone">
            <h1>월말 정산 내역 및 보고</h1>
            <p>사장님 제출용 월간 수업료 정산 통계 페이지입니다.</p>
        </div>
        
        <div class="filter-zone">
            <?php
            // 최근 6개월(현재 포함)을 선택할 수 있도록 동적 생성
            $months = [];
            $base = new DateTimeImmutable('first day of this month');
            for ($i = 0; $i < 6; $i++) {
                $m = $base->modify("-{$i} months")->format('Y-m');
                $months[] = $m;
            }
            // 선택한 달이 리스트에 없으면 앞에 추가
            if (!in_array($selected_month, $months)) {
                array_unshift($months, $selected_month);
            }
            ?>
            <select class="month-select" onchange="location.href='Month.php?month='+this.value">
                <?php foreach ($months as $m): $label = date('Y년 n월', strtotime($m . '-01')); ?>
                    <option value="<?php echo $m; ?>" <?php if ($selected_month == $m) echo 'selected'; ?>><?php echo $label; ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-outline" onclick="location.href='index.php'">📅 데일리 스케줄러</button>
            <button class="btn btn-primary" onclick="location.href='export_settle.php?month=<?php echo $selected_month; ?>'">💰 이 내역 CSV 다운로드</button>
            <button class="btn btn-print" onclick="window.print()">🖨️ 인쇄 (A4)</button>
        </div>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="card-label">총 정산 금액</div>
            <div class="card-value" style="color: #4f46e5;"><?php echo number_format($summary['total_revenue'] ?? 0); ?> 엔</div>
            <div class="card-sub">이번 달 확정된 순수 강사 정산 총액</div>
        </div>
        <div class="summary-card">
            <div class="card-label">총 완료된 레슨</div>
            <div class="card-value"><?php echo $summary['complete_count'] ?? 0; ?> 회</div>
            <div class="card-sub">실제 완료된 수업 횟수</div>
        </div>
        <div class="summary-card">
            <div class="card-label">당일취소 및 무단변경</div>
            <div class="card-value" style="color: #dc2626;"><?php echo $summary['penalty_count'] ?? 0; ?> 건</div>
            <div class="card-sub">차감 또는 패널티 정산이 반영된 건수</div>
        </div>
    </div>

    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>학생 이름</th>
                    <th>기본 단가</th>
                    <th>총 레슨 횟수</th>
                    <th>취소/노쇼 건수</th>
                    <th>최종 정산 금액</th>
                    <th>비고</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($student_reports as $report): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($report['name']); ?></strong></td>
                    <td><?php echo number_format($report['base_fee']); ?> 엔</td>
                    <td><span class="count-badge"><?php echo $report['completed_lessons']; ?>회</span></td>
                    <td><span style="color:#ca8a04; font-weight:600;"><?php echo $report['penalty_lessons']; ?>건</span></td>
                    <td><span class="price-text"><?php echo number_format($report['total_fee']); ?> 엔</span></td>
                    <td><span style="color: #6b7280; font-size: 13px;">정산 포함</span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    </div>
</body>
</html>