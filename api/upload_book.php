
<?php
require_once __DIR__ . '/db.php';
session_start();

function jsonResponse(int $statusCode, array $payload): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, ['success' => false, 'message' => 'POST 요청만 허용됩니다.']);
}

if (!isset($_SESSION['user_id'])) {
    jsonResponse(401, ['success' => false, 'message' => '로그인이 필요합니다.']);
}

$sessionRole = strtoupper(trim((string)($_SESSION['user_role'] ?? '')));
if ($sessionRole !== 'ADMIN') {
    jsonResponse(403, ['success' => false, 'message' => '관리자만 교재를 등록할 수 있습니다.']);
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    jsonResponse(400, ['success' => false, 'message' => '업로드된 파일이 없거나 오류가 발생했습니다.']);
}

$file = $_FILES['file'];
$lang = strtolower(trim((string)($_POST['lang'] ?? 'kr')));
$titleOverride = trim((string)($_POST['title'] ?? ''));
$allowedLangs = ['kr', 'jp', 'ch', 'en'];

if (!in_array($lang, $allowedLangs, true)) {
    jsonResponse(400, ['success' => false, 'message' => '잘못된 언어 선택입니다.']);
}

$fileName = $file['name'];
$lastDot = strrpos($fileName, '.');
if ($lastDot === false) {
    jsonResponse(400, ['success' => false, 'message' => '확장자가 없는 파일입니다.']);
}

$rawTitle = $titleOverride !== '' ? $titleOverride : substr($fileName, 0, $lastDot);
$ext = strtolower(substr($fileName, $lastDot + 1));

if (!in_array($ext, ['html', 'pdf'], true)) {
    jsonResponse(400, ['success' => false, 'message' => 'HTML 또는 PDF 파일만 업로드 가능합니다.']);
}

// 파일명 정제 (언더바만 있고 하이픈이 없으면 '카테고리 - N과' 형태로 치환)
$title = $rawTitle;
if (strpos($title, '_') !== false && strpos($title, '-') === false) {
    $title = str_replace('_', ' - ', $title);
}

$title = trim(preg_replace('/\s+/u', ' ', $title));
if ($title === '') {
    jsonResponse(400, ['success' => false, 'message' => '교재 제목이 비어 있습니다.']);
}

$extractLectureNumber = static function (string $input): ?int {
    if (preg_match('/(\d+)/u', $input, $m)) {
        return (int)$m[1];
    }
    return null;
};

try {
    $pdo = DB::getConnection();

    // 1. 파일 저장 경로 계산 및 폴더 자동 생성
    if ($ext === 'html') {
        $baseDir = __DIR__ . '/../post/books';
        $safeTitle = preg_replace('/\s*-\s*/u', '_', $title);
        $safeTitle = preg_replace('/[\\\\\/:\*\?"<>\|]/u', '', (string)$safeTitle);
        $targetDir = $baseDir . '/' . $lang;
        $targetPath = $targetDir . '/' . $safeTitle . '.html';
    } else {
        $baseDir = __DIR__ . '/../files/books';
        $targetDir = $baseDir . '/' . $lang;
        $targetPath = $targetDir . '/' . $title . '.pdf';
    }

    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    // 2. 서버에 파일 저장
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        jsonResponse(500, ['success' => false, 'message' => '서버에 파일을 저장하지 못했습니다.']);
    }

    // 3. DB 중복 확인 및 자동 INSERT/UPDATE
    $pdo->beginTransaction();

    $checkStmt = $pdo->prepare("SELECT product_id FROM products WHERE title = ? AND product_type = 'PDF' LIMIT 1");
    $checkStmt->execute([$title]);
    $exists = $checkStmt->fetch(PDO::FETCH_ASSOC);

    $price = 3000;
    $expiryDays = 2;
    $lectureNumber = $extractLectureNumber($title);
    $sqlPreview = "INSERT INTO products (product_type, title, price, lecture_number, class_type, total_count, expiry_days, per_week, is_active) VALUES ('PDF', '" . str_replace("'", "''", $title) . "', {$price}, " . ($lectureNumber === null ? 'NULL' : (string)$lectureNumber) . ", NULL, NULL, {$expiryDays}, 0, 1);";

    if (!$exists) {
        $insertStmt = $pdo->prepare("INSERT INTO products (product_type, title, price, lecture_number, class_type, total_count, expiry_days, per_week, is_active) VALUES ('PDF', ?, ?, ?, NULL, NULL, ?, 0, 1)");
        $insertStmt->execute([$title, $price, $lectureNumber, $expiryDays]);
        $productId = (int)$pdo->lastInsertId();
        $msg = "🎉 '{$title}' 파일 업로드 및 DB 등록이 완료되었습니다.";
    } else {
        $productId = (int)$exists['product_id'];
        $updateStmt = $pdo->prepare("UPDATE products SET is_active = 1, price = ?, expiry_days = ?, lecture_number = COALESCE(lecture_number, ?) WHERE product_id = ?");
        $updateStmt->execute([$price, $expiryDays, $lectureNumber, $productId]);
        $msg = "🔄 '{$title}' 기존 교재를 갱신했습니다. 파일 내용이 최신으로 반영되었습니다.";
    }

    $pdo->commit();

    jsonResponse(200, [
        'success' => true,
        'message' => $msg,
        'data' => [
            'title' => $title,
            'product_type' => 'PDF',
            'product_id' => $productId,
            'upload_path' => $publicPath,
            'sql' => $sqlPreview
        ]
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonResponse(500, ['success' => false, 'message' => '오류 발생: ' . $e->getMessage()]);
}