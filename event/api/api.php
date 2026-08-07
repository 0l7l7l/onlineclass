<?php
<?php
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

function getDefaultRegionId(PDO $pdo): ?int {
    $stmt = $pdo->query("SELECT id FROM regions WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
    $row = $stmt->fetch();
    return $row ? (int)$row['id'] : null;
}

function generateQrCodeKey(PDO $pdo, string $prefix = 'EV'): string {
    $normalizedPrefix = strtoupper(preg_replace('/[^A-Z0-9]/', '', $prefix));
    if ($normalizedPrefix === '') {
        $normalizedPrefix = 'EV';
    }

    for ($i = 0; $i < 10; $i++) {
        $candidate = $normalizedPrefix . date('ymdHis') . strtoupper(bin2hex(random_bytes(2)));
        $checkStmt = $pdo->prepare("SELECT id FROM event_qr WHERE qr_code_key = ? LIMIT 1");
        $checkStmt->execute([$candidate]);
        if (!$checkStmt->fetch()) {
            return $candidate;
        }
    }

    return $normalizedPrefix . strtoupper(uniqid(date('ymdHis'), false));
}

function buildEventQrUrl(string $qrCodeKey): array {
    $relativeUrl = '/event/event.html?qr=' . rawurlencode($qrCodeKey);
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $absoluteUrl = $host ? ($scheme . '://' . $host . $relativeUrl) : $relativeUrl;

    return [
        'relative' => $relativeUrl,
        'absolute' => $absoluteUrl
    ];
}

// 1. 언어 목록 조회 (드롭다운용)
if ($method === 'GET' && $action === 'languages') {
    $stmt = $pdo->query("SELECT id, name, code FROM languages WHERE is_active = 1");
    echo json_encode(["status" => "success", "data" => $stmt->fetchAll()]);
    exit();
}

// 2. 콘텐츠 목록 조회 (검색/필터 포함)
if ($method === 'GET' && $action === 'contents') {
    $lang_id = $_GET['language_id'] ?? null;
    $sql = "SELECT c.*, l.name as language_name, l.code as language_code,
                   (SELECT q.qr_code_key
                    FROM event_qr q
                    WHERE q.content_id = c.id AND q.is_active = 1
                    ORDER BY q.id DESC
                    LIMIT 1) AS qr_code_key
            FROM language_contents c 
            JOIN languages l ON c.language_id = l.id 
            WHERE 1=1";
    $params = [];

    if ($lang_id) {
        $sql .= " AND c.language_id = ?";
        $params[] = $lang_id;
    }
    $sql .= " ORDER BY c.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(["status" => "success", "data" => $stmt->fetchAll()]);
    exit();
}

// 3. 단일 콘텐츠 상세 조회
if ($method === 'GET' && $action === 'content_detail') {
    $id = $_GET['id'] ?? 0;
    $stmt = $pdo->prepare("SELECT c.*,
                                  (SELECT q.qr_code_key
                                   FROM event_qr q
                                   WHERE q.content_id = c.id AND q.is_active = 1
                                   ORDER BY q.id DESC
                                   LIMIT 1) AS qr_code_key
                           FROM language_contents c
                           WHERE c.id = ?");
    $stmt->execute([$id]);
    $data = $stmt->fetch();
    echo json_encode(["status" => "success", "data" => $data]);
    exit();
}

// JSON 입력을 위한 Body 데이터 수신
$input = json_decode(file_get_contents('php://input'), true);

// 4. 콘텐츠 등록 (POST)
if ($method === 'POST' && $action === 'create') {
    try {
        $pdo->beginTransaction();

        $sql = "INSERT INTO language_contents 
                (language_id, text, reading, meaning, explanation, example_text, example_meaning, image_url, level, is_active) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $success = $stmt->execute([
            $input['language_id'],
            $input['text'],
            $input['reading'] ?? '',
            $input['meaning'],
            $input['explanation'] ?? '',
            $input['example_text'] ?? '',
            $input['example_meaning'] ?? '',
            $input['image_url'] ?? '',
            $input['level'] ?? 1,
            $input['is_active'] ?? 1
        ]);

        if (!$success) {
            $pdo->rollBack();
            echo json_encode(["status" => "error", "message" => "등록 실패"]);
            exit();
        }

        $contentId = (int)$pdo->lastInsertId();
        $regionId = getDefaultRegionId($pdo);
        if (!$regionId) {
            $pdo->rollBack();
            echo json_encode(["status" => "error", "message" => "활성화된 regions 데이터가 없어 QR을 생성할 수 없습니다."]);
            exit();
        }

        $qrCodeKey = generateQrCodeKey($pdo);
        $qrInsertStmt = $pdo->prepare("INSERT INTO event_qr (qr_code_key, content_id, region_id, store_id, scan_count, is_active, created_at)
                                       VALUES (?, ?, ?, NULL, 0, 1, NOW())");
        $qrInsertStmt->execute([$qrCodeKey, $contentId, $regionId]);

        $pdo->commit();

        $qrUrls = buildEventQrUrl($qrCodeKey);
        echo json_encode([
            "status" => "success",
            "message" => "등록되었습니다. QR이 자동 생성되었습니다.",
            "data" => [
                "content_id" => $contentId,
                "qr_code_key" => $qrCodeKey,
                "qr_url" => $qrUrls['absolute'],
                "qr_relative_url" => $qrUrls['relative']
            ]
        ]);
        exit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(["status" => "error", "message" => "등록 중 오류: " . $e->getMessage()]);
        exit();
    }
}

// 5. 콘텐츠 수정 (POST/PUT)
if (($method === 'POST' || $method === 'PUT') && $action === 'update') {
    $sql = "UPDATE language_contents SET 
            language_id = ?, text = ?, reading = ?, meaning = ?, explanation = ?, 
            example_text = ?, example_meaning = ?, image_url = ?, level = ?, is_active = ? 
            WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $success = $stmt->execute([
        $input['language_id'],
        $input['text'],
        $input['reading'],
        $input['meaning'],
        $input['explanation'],
        $input['example_text'],
        $input['example_meaning'],
        $input['image_url'],
        $input['level'],
        $input['is_active'],
        $input['id']
    ]);

    echo json_encode(["status" => $success ? "success" : "error", "message" => $success ? "수정되었습니다." : "수정 실패"]);
    exit();
}

// 6. 콘텐츠 삭제 (DELETE)
if (($method === 'POST' || $method === 'DELETE') && $action === 'delete') {
    $id = $input['id'] ?? $_GET['id'] ?? 0;
    $stmt = $pdo->prepare("DELETE FROM language_contents WHERE id = ?");
    $success = $stmt->execute([$id]);

    echo json_encode(["status" => $success ? "success" : "error", "message" => $success ? "삭제되었습니다." : "삭제 실패"]);
    exit();
}

// =========================================================
// 7. QR 이벤트 접속 API (event.html 전용)
// 주소 예시: api.php?action=event_scan&qr_key=CA001
// =========================================================
if ($method === 'GET' && $action === 'event_scan') {
    $qr_key = $_GET['qr_key'] ?? 'CA001'; // QR 키값이 없으면 기본 CA001 사용

    // ① QR 정보 및 연결된 일본어 콘텐츠/지역/가게 정보 불러오기
    $sql = "SELECT q.id as qr_id, q.scan_count, 
                   c.id as content_id, c.text, c.reading, c.meaning, c.explanation, 
                   r.id as region_id, r.name as region_name, 
                   s.id as store_id, s.store_name
            FROM event_qr q
            JOIN language_contents c ON q.content_id = c.id
            JOIN regions r ON q.region_id = r.id
            LEFT JOIN stores s ON q.store_id = s.id
            WHERE q.qr_code_key = ? AND q.is_active = 1";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$qr_key]);
    $eventInfo = $stmt->fetch();

    if (!$eventInfo) {
        echo json_encode(["status" => "error", "message" => "유효하지 않은 QR 코드입니다."]);
        exit();
    }

    // ② 랜덤 경품/혜택 당첨 (event_prizes 테이블에서 활성화된 경품 중 하나 무작위 추출)
    $prizeStmt = $pdo->query("SELECT id, name, semo_point FROM event_prizes WHERE is_active = 1 ORDER BY RAND() LIMIT 1");
    $prizeInfo = $prizeStmt->fetch();

    // ③ 스캔 횟수 +1 증가 (event_qr 테이블)
    $updateStmt = $pdo->prepare("UPDATE event_qr SET scan_count = scan_count + 1 WHERE id = ?");
    $updateStmt->execute([$eventInfo['qr_id']]);

    // ④ 스캔 및 참여 로그 기록 (event_scan_history 테이블)
    $logSql = "INSERT INTO event_scan_history (qr_id, content_id, region_id, store_id, prize_id, created_at) 
               VALUES (?, ?, ?, ?, ?, NOW())";
    $logStmt = $pdo->prepare($logSql);
    $logStmt->execute([
        $eventInfo['qr_id'],
        $eventInfo['content_id'],
        $eventInfo['region_id'],
        $eventInfo['store_id'],
        $prizeInfo['id'] ?? null
    ]);

    // ⑤ event.html 로 전달할 최종 데이터 응답
    echo json_encode([
        "status" => "success",
        "data" => [
            "content" => [
                "text" => $eventInfo['text'],
                "reading" => $eventInfo['reading'],
                "meaning" => $eventInfo['meaning'],
                "explanation" => $eventInfo['explanation']
            ],
            "prize" => [
                "id" => $prizeInfo['id'] ?? 0,
                "name" => $prizeInfo['name'] ?? '오늘의 일본어 카드 당첨!',
                "point" => $prizeInfo['semo_point'] ?? 0
            ]
        ]
    ]);
    exit();
}
?>

