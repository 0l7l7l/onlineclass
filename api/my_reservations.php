<?php
require_once __DIR__ . '/db.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '???? ?????.']);
    exit;
}

try {
    $pdo = DB::getConnection();
    $studentId = (int)$_SESSION['user_id'];

    $sql = "
        SELECT
            r.reservation_id,
            r.teacher_id,
            r.class_type,
            r.reserve_date,
            r.reserve_time,
            r.status,
            u.name AS teacher_name
        FROM reservations r
        LEFT JOIN users u ON u.user_id = r.teacher_id
        WHERE r.student_id = ?
          AND r.status IN ('CONFIRMED','confirmed','????')
          AND CONCAT(r.reserve_date, ' ', r.reserve_time) >= NOW()
        ORDER BY r.reserve_date ASC, r.reserve_time ASC
        LIMIT 200
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$studentId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $bundlesMap = [];
    $singleReservations = [];

    foreach ($rows as $r) {
        $classType = strtoupper(trim((string)$r['class_type']));
        $dateTime = strtotime($r['reserve_date'] . ' ' . $r['reserve_time']);
        $weekday = (int)date('w', $dateTime);
        $time = date('H:i', $dateTime);

        if ($classType === 'GROUP') {
            $bundleKey = $r['teacher_id'] . '|' . $weekday . '|' . $time;
            if (!isset($bundlesMap[$bundleKey])) {
                $bundlesMap[$bundleKey] = [
                    'teacher_name' => $r['teacher_name'] ?: ('?? #' . $r['teacher_id']),
                    'weekday' => $weekday,
                    'time' => $time,
                    'count' => 0,
                    'dates' => []
                ];
            }
            $bundlesMap[$bundleKey]['count']++;
            $bundlesMap[$bundleKey]['dates'][] = $r['reserve_date'];
        }

        $singleReservations[] = [
            'reservation_id' => (int)$r['reservation_id'],
            'teacher_name' => $r['teacher_name'] ?: ('?? #' . $r['teacher_id']),
            'class_type' => $classType,
            'reserve_date' => $r['reserve_date'],
            'reserve_time' => $r['reserve_time']
        ];
    }

    $weekdays = ['?', '?', '?', '?', '?', '?', '?'];
    $bundles = [];
    foreach ($bundlesMap as $b) {
        $bundles[] = [
            'teacher_name' => $b['teacher_name'],
            'weekday_label' => $weekdays[$b['weekday']] . '??',
            'time' => $b['time'],
            'count' => $b['count'],
            'label' => $b['count'] >= 8 ? '8? ???' : ($b['count'] >= 4 ? '4? ???' : $b['count'] . '? ??'),
            'dates' => $b['dates']
        ];
    }

    usort($bundles, function ($a, $b) {
        if ($a['weekday_label'] === $b['weekday_label']) return strcmp($a['time'], $b['time']);
        return strcmp($a['weekday_label'], $b['weekday_label']);
    });

    echo json_encode([
        'success' => true,
        'data' => [
            'bundles' => $bundles,
            'reservations' => $singleReservations
        ]
    ]);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '??? ??: ' . $e->getMessage()]);
    exit;
}
