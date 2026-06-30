<?php
require_once __DIR__ . '/db.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

function normalizeClassType(string $classType): string
{
    $type = strtoupper(trim($classType));
    if ($type === 'DUO_12') {
        return 'DUO';
    }
    return in_array($type, ['PRIVATE', 'DUO', 'GROUP'], true) ? $type : 'PRIVATE';
}

function parseTargetUserIds(array $source): array
{
    $ids = [];

    if (isset($source['target_user_ids'])) {
        $raw = $source['target_user_ids'];

        if (is_array($raw)) {
            foreach ($raw as $v) {
                $n = (int)$v;
                if ($n > 0) {
                    $ids[$n] = $n;
                }
            }
        } else {
            $text = trim((string)$raw);
            if ($text !== '') {
                if ($text[0] === '[') {
                    $decoded = json_decode($text, true);
                    if (is_array($decoded)) {
                        foreach ($decoded as $v) {
                            $n = (int)$v;
                            if ($n > 0) {
                                $ids[$n] = $n;
                            }
                        }
                    }
                } else {
                    foreach (explode(',', $text) as $v) {
                        $n = (int)trim($v);
                        if ($n > 0) {
                            $ids[$n] = $n;
                        }
                    }
                }
            }
        }
    }

    // 개별 필드도 허용
    foreach (['target_user_id', 'target_user_id_1', 'target_user_id_2'] as $key) {
        if (isset($source[$key])) {
            $n = (int)$source[$key];
            if ($n > 0) {
                $ids[$n] = $n;
            }
        }
    }

    return array_values($ids);
}

function validateTargetsByClassType(string $classType, array $targetUserIds): ?string
{
    $count = count($targetUserIds);

    if ($classType === 'GROUP' && $count > 0) {
        return 'GROUP 수업은 지정 학생을 설정할 수 없습니다.';
    }

    if ($classType === 'PRIVATE' && $count > 1) {
        return 'PRIVATE 수업은 지정 학생을 최대 1명만 설정할 수 있습니다.';
    }

    if ($classType === 'DUO' && $count > 2) {
        return 'DUO 수업은 지정 학생을 최대 2명만 설정할 수 있습니다.';
    }

    return null;
}

function saveClassTargets(PDO $pdo, int $classId, array $targetUserIds): void
{
    $del = $pdo->prepare("DELETE FROM class_targets WHERE class_id = ?");
    $del->execute([$classId]);

    if (count($targetUserIds) === 0) {
        return;
    }

    $ins = $pdo->prepare("INSERT INTO class_targets (class_id, user_id) VALUES (?, ?)");
    foreach ($targetUserIds as $uid) {
        $ins->execute([$classId, $uid]);
    }
}

try {
    $pdo = DB::getConnection();
    $method = $_SERVER['REQUEST_METHOD'];
    $action = isset($_REQUEST['action']) ? trim((string)$_REQUEST['action']) : '';

    if ($method === 'GET') {
        if ($action === 'detail') {
            $classId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : (isset($_GET['slot_id']) ? (int)$_GET['slot_id'] : 0);
            if ($classId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'class_id가 필요합니다.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT c.*, u.name AS teacher_name
                FROM classes c
                LEFT JOIN users u ON c.teacher_id = u.user_id
                WHERE c.class_id = ? AND c.deleted_at IS NULL
            ");
            $stmt->execute([$classId]);
            $class = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$class) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => '수업을 찾을 수 없습니다.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $targetStmt = $pdo->prepare("
                SELECT ct.user_id, u.name, u.username
                FROM class_targets ct
                JOIN users u ON u.user_id = ct.user_id
                WHERE ct.class_id = ?
                ORDER BY u.name ASC
            ");
            $targetStmt->execute([$classId]);
            $targets = $targetStmt->fetchAll(PDO::FETCH_ASSOC);

            $class['target_user_ids'] = array_map(static fn($t) => (int)$t['user_id'], $targets);

            echo json_encode([
                'success' => true,
                'data' => [
                    'class' => $class,
                    'targets' => $targets,
                ]
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $date = isset($_GET['date']) ? trim((string)$_GET['date']) : '';
        $teacherId = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;

        $sql = "
            SELECT
                c.*,
                u.name AS teacher_name,
                COUNT(ct.class_target_id) AS target_count,
                GROUP_CONCAT(ct.user_id ORDER BY ct.user_id SEPARATOR ',') AS target_user_ids_csv
            FROM classes c
            LEFT JOIN users u ON c.teacher_id = u.user_id
            LEFT JOIN class_targets ct ON ct.class_id = c.class_id
            WHERE c.deleted_at IS NULL
        ";
        $params = [];

        if ($date !== '') {
            $sql .= " AND c.class_date = ?";
            $params[] = $date;
        }
        if ($teacherId > 0) {
            $sql .= " AND c.teacher_id = ?";
            $params[] = $teacherId;
        }

        $sql .= " GROUP BY c.class_id ORDER BY c.class_date ASC, c.start_time ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['target_user_ids'] = [];
            if (!empty($row['target_user_ids_csv'])) {
                foreach (explode(',', (string)$row['target_user_ids_csv']) as $v) {
                    $n = (int)$v;
                    if ($n > 0) {
                        $row['target_user_ids'][] = $n;
                    }
                }
            }
            unset($row['target_user_ids_csv']);
        }
        unset($row);

        echo json_encode(['success' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'POST') {
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $sessionUserId = (int)$_SESSION['user_id'];
        $role = strtoupper(trim((string)($_SESSION['user_role'] ?? '')));
        if (!in_array($role, ['ADMIN', 'SUPPORTER', 'TEACHER'], true)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => '권한이 없습니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'create') {
            $teacherId = isset($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : $sessionUserId;
            $classType = normalizeClassType((string)($_POST['class_type'] ?? 'PRIVATE'));
            $classDate = trim((string)($_POST['class_date'] ?? ($_POST['lesson_date'] ?? '')));
            $startTime = trim((string)($_POST['start_time'] ?? ''));
            $endTime = trim((string)($_POST['end_time'] ?? ''));
            $maxCapacity = isset($_POST['max_capacity']) ? (int)$_POST['max_capacity'] : (isset($_POST['max_students']) ? (int)$_POST['max_students'] : 0);
            $targetUserIds = parseTargetUserIds($_POST);

            if ($role === 'TEACHER' && $teacherId !== $sessionUserId) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => '본인 수업만 생성할 수 있습니다.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if ($classDate === '' || $startTime === '' || $endTime === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => '날짜/시간은 필수입니다.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if ($maxCapacity <= 0) {
                $maxCapacity = $classType === 'PRIVATE' ? 1 : ($classType === 'DUO' ? 2 : 5);
            }

            $targetError = validateTargetsByClassType($classType, $targetUserIds);
            if ($targetError !== null) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => $targetError], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $pdo->beginTransaction();

            $ins = $pdo->prepare("
                INSERT INTO classes (teacher_id, class_type, class_date, start_time, end_time, max_capacity, status)
                VALUES (?, ?, ?, ?, ?, ?, 'AVAILABLE')
            ");
            $ins->execute([$teacherId, $classType, $classDate, $startTime, $endTime, max(1, $maxCapacity)]);

            $classId = (int)$pdo->lastInsertId();
            saveClassTargets($pdo, $classId, $targetUserIds);

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => '수업이 생성되었습니다.',
                'class_id' => $classId,
                'target_user_ids' => $targetUserIds
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'update') {
            $classId = isset($_POST['class_id']) ? (int)$_POST['class_id'] : (isset($_POST['slot_id']) ? (int)$_POST['slot_id'] : 0);
            $teacherId = isset($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : 0;
            $classType = normalizeClassType((string)($_POST['class_type'] ?? 'PRIVATE'));
            $classDate = trim((string)($_POST['class_date'] ?? ($_POST['lesson_date'] ?? '')));
            $startTime = trim((string)($_POST['start_time'] ?? ''));
            $endTime = trim((string)($_POST['end_time'] ?? ''));
            $maxCapacity = isset($_POST['max_capacity']) ? (int)$_POST['max_capacity'] : (isset($_POST['max_students']) ? (int)$_POST['max_students'] : 0);
            $targetUserIds = parseTargetUserIds($_POST);

            if ($classId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'class_id가 필요합니다.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $oldStmt = $pdo->prepare("SELECT class_id, teacher_id FROM classes WHERE class_id = ? AND deleted_at IS NULL");
            $oldStmt->execute([$classId]);
            $old = $oldStmt->fetch(PDO::FETCH_ASSOC);

            if (!$old) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => '수업을 찾을 수 없습니다.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if ($role === 'TEACHER' && (int)$old['teacher_id'] !== $sessionUserId) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => '본인 수업만 수정할 수 있습니다.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if ($teacherId <= 0) {
                $teacherId = (int)$old['teacher_id'];
            }

            if ($maxCapacity <= 0) {
                $maxCapacity = $classType === 'PRIVATE' ? 1 : ($classType === 'DUO' ? 2 : 5);
            }

            $targetError = validateTargetsByClassType($classType, $targetUserIds);
            if ($targetError !== null) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => $targetError], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $pdo->beginTransaction();

            $upd = $pdo->prepare("
                UPDATE classes
                SET teacher_id = ?, class_type = ?, class_date = ?, start_time = ?, end_time = ?, max_capacity = ?
                WHERE class_id = ? AND deleted_at IS NULL
            ");
            $upd->execute([$teacherId, $classType, $classDate, $startTime, $endTime, max(1, $maxCapacity), $classId]);

            saveClassTargets($pdo, $classId, $targetUserIds);

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => '수업이 수정되었습니다.',
                'class_id' => $classId,
                'target_user_ids' => $targetUserIds
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'delete') {
            $classId = isset($_POST['class_id']) ? (int)$_POST['class_id'] : (isset($_POST['slot_id']) ? (int)$_POST['slot_id'] : 0);

            if ($classId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'class_id가 필요합니다.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $stmt = $pdo->prepare("UPDATE classes SET deleted_at = CURRENT_TIMESTAMP, status = 'CANCELLED' WHERE class_id = ?");
            $stmt->execute([$classId]);

            echo json_encode(['success' => true, 'message' => '수업이 취소/삭제되었습니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '잘못된 요청입니다.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
