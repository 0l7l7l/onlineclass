<?php
require_once __DIR__ . '/db.php';
session_start();

function jsonResponse(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$action = strtolower(trim((string)($_GET['action'] ?? 'list')));

function isFreePdfTitle(string $itemTitle): bool
{
    return preg_match('/\s-\s1과$/u', $itemTitle) === 1;
}

function getFullBookTitleFromLesson(string $itemTitle): string
{
    return preg_replace('/\s-\s\d+과$/u', ' - 전체책', $itemTitle) ?? $itemTitle;
}

function getActivePdfAccess(PDO $pdo, int $userId, string $itemTitle): ?array
{
    if ($userId <= 0) {
        return null;
    }

    $fullBookTitle = getFullBookTitleFromLesson($itemTitle);

    $accessStmt = $pdo->prepare(" 
        SELECT MAX(ua.expired_at) AS expired_at
        FROM user_access ua
        JOIN products p ON p.product_id = ua.product_id
        WHERE ua.user_id = ?
          AND p.product_type = 'PDF'
          AND (p.title = ? OR p.title = ?)
          AND p.is_active = 1
    ");
    $accessStmt->execute([$userId, $itemTitle, $fullBookTitle]);
    $access = $accessStmt->fetch(PDO::FETCH_ASSOC);

    if (!$access || empty($access['expired_at']) || strtotime((string)$access['expired_at']) <= time()) {
        return null;
    }

    return $access;
}

try {
    $pdo = DB::getConnection();

    if ($action === 'download') {
        $itemTitle = trim((string)($_GET['item_title'] ?? ''));
        $lang = strtolower(trim((string)($_GET['lang'] ?? '')));
        $allowedLangFolders = ['kr', 'jp', 'ch', 'en'];
        $isFree = isFreePdfTitle($itemTitle);

        if ($itemTitle === '') {
            jsonResponse(400, ['success' => false, 'message' => '교재 정보가 없습니다.']);
        }

        if (!in_array($lang, $allowedLangFolders, true)) {
            jsonResponse(400, ['success' => false, 'message' => '잘못된 언어 폴더입니다.']);
        }

        if (preg_match('/[\\\\\/]/u', $itemTitle)) {
            jsonResponse(400, ['success' => false, 'message' => '잘못된 파일명입니다.']);
        }

        if (!$isFree && $userId <= 0) {
            jsonResponse(401, ['success' => false, 'message' => '로그인이 필요합니다.']);
        }

        $access = null;
        if (!$isFree) {
            $access = getActivePdfAccess($pdo, $userId, $itemTitle);
            if (!$access) {
                jsonResponse(403, ['success' => false, 'message' => '구매 권한이 없거나 이용 기간이 만료되었습니다.']);
            }
        }

        $baseDir = realpath(__DIR__ . '/../files/books');
        if ($baseDir === false) {
            jsonResponse(500, ['success' => false, 'message' => '파일 저장 경로를 찾을 수 없습니다.']);
        }

        $targetDir = $baseDir . DIRECTORY_SEPARATOR . $lang;
        $fileName = $itemTitle . '.pdf';
        $filePath = $targetDir . DIRECTORY_SEPARATOR . $fileName;

        if (!is_file($filePath)) {
            jsonResponse(404, ['success' => false, 'message' => 'PDF 파일이 아직 등록되지 않았습니다.']);
        }

        header('Content-Type: application/pdf');
        header('Content-Description: File Transfer');
        header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode($fileName));
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        readfile($filePath);
        exit;
    }

    if ($action === 'view_html') {
        $itemTitle = trim((string)($_GET['item_title'] ?? ''));
        $lang = strtolower(trim((string)($_GET['lang'] ?? '')));
        $allowedLangFolders = ['kr', 'jp', 'ch', 'en'];
        $isFree = isFreePdfTitle($itemTitle);

        if ($itemTitle === '') {
            jsonResponse(400, ['success' => false, 'message' => '교재 정보가 없습니다.']);
        }

        if (!in_array($lang, $allowedLangFolders, true)) {
            jsonResponse(400, ['success' => false, 'message' => '잘못된 언어 폴더입니다.']);
        }

        if (preg_match('/[\\\/]/u', $itemTitle)) {
            jsonResponse(400, ['success' => false, 'message' => '잘못된 파일명입니다.']);
        }

        if (!$isFree && $userId <= 0) {
            jsonResponse(401, ['success' => false, 'message' => '로그인이 필요합니다.']);
        }

        $access = null;
        if (!$isFree) {
            $access = getActivePdfAccess($pdo, $userId, $itemTitle);
            if (!$access) {
                jsonResponse(403, ['success' => false, 'message' => '구매 권한이 없거나 이용 기간이 만료되었습니다.']);
            }
        }

        $baseDir = realpath(__DIR__ . '/../post/books');
        if ($baseDir === false) {
            jsonResponse(500, ['success' => false, 'message' => '교재 저장 경로를 찾을 수 없습니다.']);
        }

        $safeTitle = preg_replace('/\s*-\s*/u', '_', $itemTitle);
        $safeTitle = preg_replace('/[\\\/\:\"\*\?\<\>\|]/u', '', (string)$safeTitle);
        $bookPath = $baseDir . DIRECTORY_SEPARATOR . $lang . DIRECTORY_SEPARATOR . $safeTitle . '.html';

        if (!is_file($bookPath)) {
            jsonResponse(404, ['success' => false, 'message' => '교재 HTML 파일이 아직 등록되지 않았습니다.']);
        }

        $bookContent = file_get_contents($bookPath);
        if ($bookContent === false) {
            jsonResponse(500, ['success' => false, 'message' => '교재 파일을 읽을 수 없습니다.']);
        }

        $titleEsc = htmlspecialchars($itemTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $expiredEsc = $isFree
            ? '무료 이용'
            : htmlspecialchars((string)$access['expired_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $titleJson = json_encode($itemTitle, JSON_UNESCAPED_UNICODE);

        $inject = <<<HTML
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<style>
#__pdf_toolbar { position: fixed; top: 0; left: 0; right: 0; z-index: 99999; background: #1e293b; color: #fff; padding: 10px 16px; display: flex; gap: 10px; align-items: center; font-family: sans-serif; }
#__pdf_toolbar h2 { font-size: 0.95rem; flex: 1; }
#__pdf_toolbar .expire { font-size: 0.78rem; color: #94a3b8; }
.btn-toolbar { border: none; border-radius: 5px; padding: 7px 12px; font-size: 0.85rem; cursor: pointer; }
.btn-back { background: #334155; color: #fff; }
.btn-pdf { background: #2563eb; color: #fff; }
.btn-pdf:disabled { background: #64748b; cursor: not-allowed; }
#__pdf_progress { display: none; position: fixed; top: 44px; left: 0; right: 0; height: 5px; z-index: 99998; background: #e2e8f0; }
#__pdf_fill { height: 100%; width: 0%; background: #2563eb; transition: width 0.3s; }
body { padding-top: 54px !important; }
@media print { #__pdf_toolbar, #__pdf_progress { display: none !important; } body { padding-top: 0 !important; } }
</style>
<div id="__pdf_toolbar">
  <button class="btn-toolbar btn-back" onclick="history.back()">← 돌아가기</button>
  <h2>{$titleEsc}</h2>
  <span class="expire">이용 만료: {$expiredEsc}</span>
  <button class="btn-toolbar btn-pdf" id="__btn_pdf" onclick="__downloadPDF()">⬇ PDF 저장</button>
</div>
<div id="__pdf_progress"><div id="__pdf_fill"></div></div>
<script>
const __bookTitle = {$titleJson};
const __autoStart = new URLSearchParams(window.location.search).get('autostart') === '1';
async function __downloadPDF() {
    const btn = document.getElementById('__btn_pdf');
    const bar = document.getElementById('__pdf_progress');
    const fill = document.getElementById('__pdf_fill');
    btn.disabled = true;
    btn.textContent = '변환 중...';
    bar.style.display = 'block';
    fill.style.width = '20%';
    try {
        const { jsPDF } = window.jspdf;
        const toolbar = document.getElementById('__pdf_toolbar');
        toolbar.style.display = 'none';
        bar.style.display = 'none';
        document.body.style.paddingTop = '0';

        fill.style.width = '50%';
        const canvas = await html2canvas(document.body, { scale: 2, useCORS: true, backgroundColor: '#ffffff' });

        toolbar.style.display = 'flex';
        bar.style.display = 'block';
        document.body.style.paddingTop = '54px';
        fill.style.width = '80%';

        const pdf = new jsPDF('p', 'mm', 'a4');
        const pageWidth = pdf.internal.pageSize.getWidth();
        const pageHeight = pdf.internal.pageSize.getHeight();
        const imgHeight = (canvas.height * pageWidth) / canvas.width;
        const img = canvas.toDataURL('image/png');

        let y = 0;
        let page = 0;
        while (y < imgHeight) {
            if (page > 0) pdf.addPage();
            pdf.addImage(img, 'PNG', 0, -y, pageWidth, imgHeight);
            y += pageHeight;
            page++;
        }

        fill.style.width = '100%';
        pdf.save(__bookTitle + '.pdf');
    } catch (e) {
        alert('PDF 변환 중 오류가 발생했습니다.');
    } finally {
        btn.disabled = false;
        btn.textContent = '⬇ PDF 저장';
        setTimeout(() => {
            bar.style.display = 'none';
            fill.style.width = '0%';
        }, 800);
    }
}

if (__autoStart) {
    setTimeout(() => {
        __downloadPDF();
    }, 300);
}
</script>
HTML;

        $injected = preg_replace('/(<body[^>]*>)/i', '$1' . $inject, $bookContent, 1);
        if (!is_string($injected) || $injected === $bookContent) {
            $injected = "<!DOCTYPE html><html><head><meta charset=\"UTF-8\"><title>{$titleEsc}</title></head><body>{$inject}{$bookContent}</body></html>";
        }

        header('Content-Type: text/html; charset=utf-8');
        echo $injected;
        exit;
    }

    if ($userId <= 0) {
        jsonResponse(401, ['success' => false, 'message' => '로그인이 필요합니다.']);
    }

    $stmt = $pdo->prepare(" 
        SELECT p.title, MAX(ua.expired_at) AS expired_at
        FROM user_access ua
        JOIN products p ON p.product_id = ua.product_id
        WHERE ua.user_id = ?
          AND p.product_type = 'PDF'
          AND p.is_active = 1
        GROUP BY p.title
        HAVING MAX(ua.expired_at) > NOW()
        ORDER BY MAX(ua.expired_at) DESC
    ");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse(200, [
        'success' => true,
        'data' => [
            'accesses' => $rows,
            'server_now' => date('Y-m-d H:i:s')
        ]
    ]);
} catch (Throwable $e) {
    jsonResponse(500, ['success' => false, 'message' => 'PDF 권한 처리 중 오류가 발생했습니다.']);
}
