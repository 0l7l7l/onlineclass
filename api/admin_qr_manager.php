<?php
header('Content-Type: application/json; charset=UTF-8');

session_start();

require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || strtoupper((string)$_SESSION['user_role']) !== 'ADMIN') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'data' => null,
        'message' => '관리자 권한이 필요합니다.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function respond($success, $data = null, $message = ''): void {
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function getJsonInput(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function generateToken(PDO $pdo): string {
    for ($i = 0; $i < 12; $i++) {
        $token = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        $stmt = $pdo->prepare('SELECT id FROM qr_codes WHERE token = ? LIMIT 1');
        $stmt->execute([$token]);
        if (!$stmt->fetch()) return $token;
    }
    return strtoupper(substr(uniqid('QR', false), -6));
}

function getActiveCampaignId(PDO $pdo): ?int {
    $stmt = $pdo->query("SELECT id FROM campaigns WHERE status = 'active' ORDER BY id ASC LIMIT 1");
    $row = $stmt->fetch();
    return $row ? (int)$row['id'] : null;
}

try {
    $pdo = DB::getConnection();
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET' && $action === 'dashboard') {
        $campaignId = (int)($_GET['campaign_id'] ?? 0);
        if ($campaignId <= 0) {
            $campaignId = getActiveCampaignId($pdo) ?? 0;
        }

        $regionStmt = $pdo->prepare(
            "SELECT r.id, r.name, COUNT(sl.id) AS scan_count
             FROM regions r
             LEFT JOIN scan_logs sl ON sl.region_id = r.id AND sl.campaign_id = ?
             WHERE r.campaign_id = ?
             GROUP BY r.id, r.name
             ORDER BY scan_count DESC, r.id ASC
             LIMIT 3"
        );
        $regionStmt->execute([$campaignId, $campaignId]);
        $regions = $regionStmt->fetchAll();

        $todayStmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM scan_logs WHERE campaign_id = ? AND DATE(scanned_at) = CURDATE()");
        $todayStmt->execute([$campaignId]);
        $todayScans = (int)($todayStmt->fetch()['cnt'] ?? 0);

        $totalStmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM scan_logs WHERE campaign_id = ?");
        $totalStmt->execute([$campaignId]);
        $totalScans = (int)($totalStmt->fetch()['cnt'] ?? 0);

        $popularStmt = $pdo->prepare(
            "SELECT c.id,
                    COALESCE(tja.text, CONCAT('콘텐츠#', c.id)) AS ja_text,
                    COALESCE(tko.text, '-') AS ko_text,
                    COUNT(sl.id) AS scan_count
             FROM contents c
             LEFT JOIN content_translations tja ON tja.content_id = c.id AND tja.language_code = 'ja'
             LEFT JOIN content_translations tko ON tko.content_id = c.id AND tko.language_code = 'ko'
             LEFT JOIN scan_logs sl ON sl.content_id = c.id AND sl.campaign_id = ?
             GROUP BY c.id, tja.text, tko.text
             ORDER BY scan_count DESC, c.id ASC
             LIMIT 5"
        );
        $popularStmt->execute([$campaignId]);
        $popularContents = $popularStmt->fetchAll();

        respond(true, [
            'campaign_id' => $campaignId,
            'regions' => $regions,
            'today_scans' => $todayScans,
            'total_scans' => $totalScans,
            'popular_contents' => $popularContents
        ]);
    }

    if ($method === 'GET' && $action === 'contents') {
        $stmt = $pdo->query(
            "SELECT c.id, c.category, c.level, c.is_active,
                    COALESCE(tja.text, '') AS ja_text,
                    COALESCE(tja.pronunciation, '') AS ja_pronunciation,
                    COALESCE(tko.text, '') AS ko_text,
                    COALESCE(tzh.text, '') AS zh_text,
                    (SELECT COUNT(*) FROM scan_logs sl WHERE sl.content_id = c.id) AS scan_count
             FROM contents c
             LEFT JOIN content_translations tja ON tja.content_id = c.id AND tja.language_code = 'ja'
             LEFT JOIN content_translations tko ON tko.content_id = c.id AND tko.language_code = 'ko'
             LEFT JOIN content_translations tzh ON tzh.content_id = c.id AND tzh.language_code = 'zh'
             ORDER BY c.id DESC"
        );
        respond(true, $stmt->fetchAll());
    }

    if ($method === 'POST' && $action === 'create_content') {
        $input = getJsonInput();
        $jaText = trim((string)($input['ja_text'] ?? ''));
        $koText = trim((string)($input['ko_text'] ?? ''));
        if ($jaText === '' || $koText === '') {
            respond(false, null, '일본어/한국어 텍스트는 필수입니다.');
        }

        $pdo->beginTransaction();

        $insertContent = $pdo->prepare('INSERT INTO contents (category, level, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())');
        $insertContent->execute([
            (string)($input['category'] ?? '일상회화'),
            (string)($input['level'] ?? '초급')
        ]);
        $contentId = (int)$pdo->lastInsertId();

        $upsert = $pdo->prepare(
            "INSERT INTO content_translations (content_id, language_code, text, pronunciation)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE text = VALUES(text), pronunciation = VALUES(pronunciation)"
        );
        $upsert->execute([$contentId, 'ja', $jaText, (string)($input['ja_pronunciation'] ?? '')]);
        $upsert->execute([$contentId, 'ko', $koText, null]);
        $upsert->execute([$contentId, 'zh', (string)($input['zh_text'] ?? ''), null]);

        $pdo->commit();
        respond(true, ['id' => $contentId], '콘텐츠가 등록되었습니다.');
    }

    if (($method === 'POST' || $method === 'PUT') && $action === 'update_content') {
        $input = getJsonInput();
        $contentId = (int)($input['id'] ?? 0);
        if ($contentId <= 0) respond(false, null, '콘텐츠 ID가 필요합니다.');

        $pdo->beginTransaction();

        $updateContent = $pdo->prepare('UPDATE contents SET category = ?, level = ? WHERE id = ?');
        $updateContent->execute([
            (string)($input['category'] ?? '일상회화'),
            (string)($input['level'] ?? '초급'),
            $contentId
        ]);

        $upsert = $pdo->prepare(
            "INSERT INTO content_translations (content_id, language_code, text, pronunciation)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE text = VALUES(text), pronunciation = VALUES(pronunciation)"
        );

        $upsert->execute([$contentId, 'ja', (string)($input['ja_text'] ?? ''), (string)($input['ja_pronunciation'] ?? '')]);
        $upsert->execute([$contentId, 'ko', (string)($input['ko_text'] ?? ''), null]);
        $upsert->execute([$contentId, 'zh', (string)($input['zh_text'] ?? ''), null]);

        $pdo->commit();
        respond(true, null, '콘텐츠가 저장되었습니다.');
    }

    if ($method === 'GET' && $action === 'regions') {
        $campaignId = (int)($_GET['campaign_id'] ?? 0);
        if ($campaignId <= 0) $campaignId = getActiveCampaignId($pdo) ?? 0;

        $stmt = $pdo->prepare(
            "SELECT r.id, r.name,
                    (SELECT COUNT(*) FROM stores s WHERE s.region_id = r.id) AS store_count,
                    (SELECT COALESCE(SUM(d.quantity),0) FROM distributions d WHERE d.region_id = r.id) AS tag_count,
                    (SELECT COUNT(*) FROM scan_logs sl WHERE sl.region_id = r.id AND sl.campaign_id = ?) AS scan_count
             FROM regions r
             WHERE r.campaign_id = ?
             ORDER BY r.id ASC"
        );
        $stmt->execute([$campaignId, $campaignId]);
        respond(true, $stmt->fetchAll());
    }

    if ($method === 'GET' && $action === 'stores') {
        $stmt = $pdo->query(
            "SELECT s.id, s.name, s.store_type, s.region_id, r.name AS region_name,
                    (SELECT COALESCE(SUM(d.quantity),0) FROM distributions d WHERE d.store_id = s.id) AS tag_count,
                    (SELECT COUNT(*) FROM scan_logs sl WHERE sl.store_id = s.id) AS scan_count
             FROM stores s
             JOIN regions r ON r.id = s.region_id
             ORDER BY s.id ASC"
        );
        respond(true, $stmt->fetchAll());
    }

    if ($method === 'GET' && $action === 'store_detail') {
        $storeId = (int)($_GET['store_id'] ?? 0);
        $stmt = $pdo->prepare(
            "SELECT s.id, s.name, s.store_type, s.address, s.phone, s.memo, s.status,
                    r.name AS region_name,
                    (SELECT COALESCE(SUM(d.quantity),0) FROM distributions d WHERE d.store_id = s.id) AS tag_count,
                    (SELECT COUNT(*) FROM scan_logs sl WHERE sl.store_id = s.id) AS scan_count
             FROM stores s
             JOIN regions r ON r.id = s.region_id
             WHERE s.id = ?
             LIMIT 1"
        );
        $stmt->execute([$storeId]);
        $detail = $stmt->fetch();
        if (!$detail) respond(false, null, '매장 정보를 찾을 수 없습니다.');
        respond(true, $detail);
    }

    if ($method === 'GET' && $action === 'generator_options') {
        $campaigns = $pdo->query("SELECT id, name FROM campaigns ORDER BY id ASC")->fetchAll();
        $regions = $pdo->query("SELECT id, campaign_id, name FROM regions ORDER BY id ASC")->fetchAll();
        $stores = $pdo->query("SELECT id, region_id, name FROM stores ORDER BY id ASC")->fetchAll();
        $contents = $pdo->query(
            "SELECT c.id,
                    COALESCE(tja.text, '') AS ja_text,
                    COALESCE(tko.text, '') AS ko_text
             FROM contents c
             LEFT JOIN content_translations tja ON tja.content_id = c.id AND tja.language_code = 'ja'
             LEFT JOIN content_translations tko ON tko.content_id = c.id AND tko.language_code = 'ko'
             ORDER BY c.id ASC"
        )->fetchAll();

        respond(true, [
            'campaigns' => $campaigns,
            'regions' => $regions,
            'stores' => $stores,
            'contents' => $contents
        ]);
    }

    if ($method === 'POST' && $action === 'create_distribution') {
        $input = getJsonInput();
        $campaignId = (int)($input['campaign_id'] ?? 0);
        $regionId = (int)($input['region_id'] ?? 0);
        $contentId = (int)($input['content_id'] ?? 0);
        $quantity = max(1, (int)($input['quantity'] ?? 1));
        $storeId = isset($input['store_id']) && $input['store_id'] !== '' ? (int)$input['store_id'] : null;

        if ($campaignId <= 0 || $regionId <= 0 || $contentId <= 0) {
            respond(false, null, '캠페인/지역/콘텐츠는 필수입니다.');
        }

        $pdo->beginTransaction();

        $findQr = $pdo->prepare('SELECT id, token FROM qr_codes WHERE campaign_id = ? AND content_id = ? ORDER BY id ASC LIMIT 1');
        $findQr->execute([$campaignId, $contentId]);
        $qr = $findQr->fetch();

        if (!$qr) {
            $token = generateToken($pdo);
            $insertQr = $pdo->prepare("INSERT INTO qr_codes (token, content_id, campaign_id, status, created_at, updated_at)
                                       VALUES (?, ?, ?, 'active', NOW(), NOW())");
            $insertQr->execute([$token, $contentId, $campaignId]);
            $qrId = (int)$pdo->lastInsertId();
        } else {
            $qrId = (int)$qr['id'];
            $token = (string)$qr['token'];
        }

        $status = $storeId ? 'allocated' : 'unallocated';
        $distributedAt = $storeId ? date('Y-m-d H:i:s') : null;

        $insertDist = $pdo->prepare(
            "INSERT INTO distributions (qr_id, campaign_id, region_id, store_id, quantity, status, distributed_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
        );
        $insertDist->execute([$qrId, $campaignId, $regionId, $storeId, $quantity, $status, $distributedAt]);
        $distributionId = (int)$pdo->lastInsertId();

        $pdo->commit();

        respond(true, [
            'distribution_id' => $distributionId,
            'token' => $token
        ], 'QR/태그가 생성되었습니다.');
    }

    if ($method === 'GET' && $action === 'distributions') {
        $stmt = $pdo->query(
            "SELECT d.id AS distribution_id, q.token, d.quantity, d.status,
                    d.region_id, d.store_id,
                    r.name AS region_name,
                    s.name AS store_name,
                    c.id AS content_id,
                    COALESCE(tja.text, CONCAT('콘텐츠#', c.id)) AS content_text
             FROM distributions d
             JOIN qr_codes q ON q.id = d.qr_id
             JOIN regions r ON r.id = d.region_id
             LEFT JOIN stores s ON s.id = d.store_id
             JOIN contents c ON c.id = q.content_id
             LEFT JOIN content_translations tja ON tja.content_id = c.id AND tja.language_code = 'ja'
             ORDER BY d.id DESC"
        );
        respond(true, $stmt->fetchAll());
    }

    if ($method === 'POST' && $action === 'update_distribution_store') {
        $input = getJsonInput();
        $distributionId = (int)($input['distribution_id'] ?? 0);
        $storeId = isset($input['store_id']) && $input['store_id'] !== '' ? (int)$input['store_id'] : null;

        if ($distributionId <= 0) respond(false, null, '배포 ID가 필요합니다.');

        $status = $storeId ? 'allocated' : 'unallocated';
        $distributedAt = $storeId ? date('Y-m-d H:i:s') : null;

        $stmt = $pdo->prepare(
            "UPDATE distributions
             SET store_id = ?, status = ?, distributed_at = ?, updated_at = NOW()
             WHERE id = ?"
        );
        $stmt->execute([$storeId, $status, $distributedAt, $distributionId]);

        respond(true, null, '매장 연결이 저장되었습니다.');
    }

    respond(false, null, '지원하지 않는 요청입니다.');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    respond(false, null, '서버 오류: ' . $e->getMessage());
}
