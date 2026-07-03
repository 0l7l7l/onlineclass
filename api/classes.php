<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema_helpers.php';
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

function getClassCapacityLimit(array $class): int
{
    $type = strtoupper((string)($class['class_type'] ?? 'PRIVATE'));
    if ($type === 'PRIVATE') return 1;
    if ($type === 'DUO') return 2;
    return max(1, (int)($class['max_capacity'] ?? 1));
}

function isAdminFreeTicket(PDO $pdo, int $userTicketId): bool
{
    if ($userTicketId <= 0) return false;

    $stmt = $pdo->prepare("\n        SELECT p.title\n        FROM user_tickets ut\n        JOIN products p ON p.product_id = ut.product_id\n        WHERE ut.user_ticket_id = ?\n        LIMIT 1\n    ");
    $stmt->execute([$userTicketId]);
    $title = (string)($stmt->fetchColumn() ?: '');
    return strpos($title, '[ADMIN_FREE]') === 0;
}

function refreshClassCurrentCapacity(PDO $pdo, int $classId): void
{
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE class_id = ? AND UPPER(TRIM(status)) = 'CONFIRMED'");
    $countStmt->execute([$classId]);
    $cnt = (int)$countStmt->fetchColumn();

    $upd = $pdo->prepare("UPDATE classes SET current_capacity = ? WHERE class_id = ?");
    $upd->execute([$cnt, $classId]);
}

function logClassChange(PDO $pdo, int $classId, string $action, int $changedBy, ?array $oldValue = null, ?array $newValue = null, ?string $description = null): void
{
    $stmt = $pdo->prepare("
        INSERT INTO class_change_logs (class_id, action, changed_by, old_value, new_value, description)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $classId,
        $action,
        $changedBy,
        $oldValue ? json_encode($oldValue, JSON_UNESCAPED_UNICODE) : null,
        $newValue ? json_encode($newValue, JSON_UNESCAPED_UNICODE) : null,
        $description
    ]);
}

function findUsableTicketId(PDO $pdo, int $studentId, string $classType): int
{
    $stmt = $pdo->prepare("\n        SELECT ut.user_ticket_id\n        FROM user_tickets ut\n        JOIN products p ON p.product_id = ut.product_id\n        WHERE ut.user_id = ?\n          AND ut.status = 'ACTIVE'\n          AND ut.remaining_count > 0\n          AND ut.expired_at > NOW()\n          AND p.product_type = 'TICKET'\n          AND p.class_type = ?\n          AND p.is_active = 1\n        ORDER BY ut.expired_at ASC, ut.user_ticket_id ASC\n        LIMIT 1\n    ");
    $stmt->execute([$studentId, $classType]);
    return (int)($stmt->fetchColumn() ?: 0);
}

function ensureAdminFreeTicketId(PDO $pdo, int $studentId, string $classType): int
{
    $title = '[ADMIN_FREE] ' . $classType;

    $productStmt = $pdo->prepare("SELECT product_id FROM products WHERE product_type = 'TICKET' AND title = ? LIMIT 1");
    $productStmt->execute([$title]);
    $productId = (int)($productStmt->fetchColumn() ?: 0);

    if ($productId <= 0) {
        $insProduct = $pdo->prepare("\n            INSERT INTO products (product_type, title, price, class_type, total_count, expiry_days, is_active)\n            VALUES ('TICKET', ?, 0, ?, 0, 3650, 0)\n        ");
        $insProduct->execute([$title, $classType]);
        $productId = (int)$pdo->lastInsertId();
    }

    $insTicket = $pdo->prepare("\n        INSERT INTO user_tickets (user_id, product_id, remaining_count, status, expired_at)\n        VALUES (?, ?, 0, 'EXHAUSTED', DATE_ADD(NOW(), INTERVAL 10 YEAR))\n    ");
    $insTicket->execute([$studentId, $productId]);

    return (int)$pdo->lastInsertId();
}

function consumeTicketCount(PDO $pdo, int $userTicketId): void
{
    $upd = $pdo->prepare("\n        UPDATE user_tickets\n        SET remaining_count = CASE WHEN remaining_count > 0 THEN remaining_count - 1 ELSE 0 END\n        WHERE user_ticket_id = ?\n    ");
    $upd->execute([$userTicketId]);

    $mark = $pdo->prepare("\n        UPDATE user_tickets\n        SET status = 'EXHAUSTED'\n        WHERE user_ticket_id = ? AND remaining_count <= 0 AND status = 'ACTIVE'\n    ");
    $mark->execute([$userTicketId]);
}

function restoreTicketCount(PDO $pdo, int $userTicketId): void
{
    if ($userTicketId <= 0) return;
    if (isAdminFreeTicket($pdo, $userTicketId)) return;

    $upd = $pdo->prepare("\n        UPDATE user_tickets\n        SET remaining_count = remaining_count + 1,\n            status = CASE WHEN expired_at > NOW() THEN 'ACTIVE' ELSE status END\n        WHERE user_ticket_id = ?\n    ");
    $upd->execute([$userTicketId]);
}

function assertStudentEligibility(PDO $pdo, array $class, int $studentId): array
{
    $stmt = $pdo->prepare("\n        SELECT user_id, name, username, role, teacher_id, deleted_at\n        FROM users\n        WHERE user_id = ?\n        LIMIT 1\n    ");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student || strtoupper((string)$student['role']) !== 'STUDENT' || $student['deleted_at'] !== null) {
        throw new RuntimeException('유효한 학생을 찾을 수 없습니다.');
    }

    $classType = strtoupper((string)($class['class_type'] ?? 'PRIVATE'));
    if ($classType !== 'GROUP' && (int)$student['teacher_id'] !== (int)$class['teacher_id']) {
        throw new RuntimeException('이 수업에는 담당 학생만 배정할 수 있습니다.');
    }

    return $student;
}

try {
    $pdo = DB::getConnection();
    ensureClassScheduleSupportTables($pdo);
    $method = $_SERVER['REQUEST_METHOD'];
    $action = isset($_REQUEST['action']) ? trim((string)$_REQUEST['action']) : '';

    // ========================================
    // 권한 관련 공통 체크
    // ========================================
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    $role = strtoupper(trim((string)($_SESSION['user_role'] ?? '')));

    // ========================================
    // [선생님/학생 관리 기능] - GET 요청
    // ========================================
    if ($method === 'GET') {
        
        // ------- 전체 선생님 목록 조회 -------
        if ($action === 'list_teachers') {
            if (!isset($_SESSION['user_id'])) {
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if (!in_array($role, ['ADMIN', 'SUPPORTER', 'TEACHER'], true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => '권한이 없습니다.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $sql = "
                SELECT 
                    user_id, 
                    name, 
                    username,
                    supporter_id,
                    created_at
                FROM users
                WHERE role = 'TEACHER'
                  AND deleted_at IS NULL
                ORDER BY name ASC
            ";
            $stmt = $pdo->query($sql);
            $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($teachers as &$teacher) {
                $teacher['user_id'] = (int)$teacher['user_id'];
                $teacher['supporter_id'] = $teacher['supporter_id'] !== null ? (int)$teacher['supporter_id'] : null;
            }
            unset($teacher);

            echo json_encode([
                'success' => true,
                'data' => $teachers,
                'count' => count($teachers)
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ------- 특정 선생님의 담당 학생 목록 조회 -------
        if ($action === 'get_students') {
            if (!isset($_SESSION['user_id'])) {
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if (!in_array($role, ['ADMIN', 'SUPPORTER', 'TEACHER'], true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => '권한이 없습니다.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $teacherId = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;
            if ($teacherId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'teacher_id가 필요합니다.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT 
                    user_id, 
                    name, 
                    username,
                    current_money,
                    created_at
                FROM users
                WHERE teacher_id = ? 
                  AND role = 'STUDENT'
                  AND deleted_at IS NULL
                ORDER BY name ASC
            ");
            $stmt->execute([$teacherId]);
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($students as &$student) {
                $student['user_id'] = (int)$student['user_id'];
                $student['current_money'] = (int)$student['current_money'];
            }
            unset($student);

            echo json_encode([
                'success' => true,
                'data' => $students,
                'count' => count($students)
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ------- 선생님 상세 정보 및 담당 학생 조회 -------
        if ($action === 'teacher_detail') {
            if (!isset($_SESSION['user_id'])) {
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if (!in_array($role, ['ADMIN', 'SUPPORTER', 'TEACHER'], true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => '권한이 없습니다.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $teacherId = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;
            if ($teacherId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'teacher_id가 필요합니다.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // 선생님 정보 조회
            $teacherStmt = $pdo->prepare("
                SELECT 
                    user_id, 
                    name, 
                    username,
                    supporter_id,
                    current_money,
                    created_at
                FROM users
                WHERE user_id = ? AND role = 'TEACHER'
            ");
            $teacherStmt->execute([$teacherId]);
            $teacher = $teacherStmt->fetch(PDO::FETCH_ASSOC);

            if (!$teacher) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => '선생님을 찾을 수 없습니다.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // 담당 학생 목록 조회
            $studentStmt = $pdo->prepare("
                SELECT 
                    user_id, 
                    name, 
                    username,
                    current_money,
                    created_at
                FROM users
                WHERE teacher_id = ? 
                  AND role = 'STUDENT'
                  AND deleted_at IS NULL
                ORDER BY name ASC
            ");
            $studentStmt->execute([$teacherId]);
            $students = $studentStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($students as &$student) {
                $student['user_id'] = (int)$student['user_id'];
                $student['current_money'] = (int)$student['current_money'];
            }
            unset($student);

            echo json_encode([
                'success' => true,
                'data' => [
                    'teacher' => [
                        'user_id' => (int)$teacher['user_id'],
                        'name' => $teacher['name'],
                        'username' => $teacher['username'],
                        'supporter_id' => $teacher['supporter_id'] !== null ? (int)$teacher['supporter_id'] : null,
                        'current_money' => (int)$teacher['current_money'],
                        'created_at' => $teacher['created_at']
                    ],
                    'students' => $students,
                    'student_count' => count($students)
                ]
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'search_students') {
            $classId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
            $q = trim((string)($_GET['q'] ?? ''));

            if ($classId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'class_id가 필요합니다.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $classStmt = $pdo->prepare("\n                SELECT class_id, teacher_id, class_type\n                FROM classes\n                WHERE class_id = ? AND deleted_at IS NULL\n                LIMIT 1\n            ");
            $classStmt->execute([$classId]);
            $class = $classStmt->fetch(PDO::FETCH_ASSOC);
            if (!$class) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => '수업을 찾을 수 없습니다.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $sql = "\n                SELECT u.user_id, u.name, u.username,\n                       EXISTS(\n                           SELECT 1\n                           FROM reservations r\n                           WHERE r.class_id = ?\n                             AND r.user_id = u.user_id\n                             AND UPPER(TRIM(r.status)) = 'CONFIRMED'\n                       ) AS already_reserved\n                FROM users u\n                WHERE u.role = 'STUDENT'\n                  AND u.deleted_at IS NULL\n            ";
            $params = [$classId];

            if (strtoupper((string)$class['class_type']) !== 'GROUP') {
                $sql .= " AND u.teacher_id = ?";
                $params[] = (int)$class['teacher_id'];
            }

            if ($q !== '') {
                $sql .= " AND (u.name LIKE ? OR u.username LIKE ?)";
                $like = '%' . $q . '%';
                $params[] = $like;
                $params[] = $like;
            }

            $sql .= " ORDER BY u.name ASC LIMIT 100";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as &$r) {
                $r['user_id'] = (int)$r['user_id'];
                $r['already_reserved'] = ((int)$r['already_reserved']) > 0;
            }
            unset($r);

            echo json_encode(['success' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ------- 수업 상세 조회 (기존) -------
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

            $reservationStmt = $pdo->prepare("\n                SELECT\n                    r.reservation_id,\n                    r.user_id AS student_id,\n                    u.name AS student_name,\n                    u.username AS student_username,\n                    r.user_ticket_id,\n                    CASE\n                        WHEN p.title LIKE '[ADMIN_FREE]%' THEN 'FREE'\n                        ELSE 'USE'\n                    END AS ticket_mode\n                FROM reservations r\n                JOIN users u ON u.user_id = r.user_id\n                LEFT JOIN user_tickets ut ON ut.user_ticket_id = r.user_ticket_id\n                LEFT JOIN products p ON p.product_id = ut.product_id\n                WHERE r.class_id = ?\n                  AND UPPER(TRIM(r.status)) = 'CONFIRMED'\n                ORDER BY r.reserved_at ASC, r.reservation_id ASC\n            ");
            $reservationStmt->execute([$classId]);
            $reservations = $reservationStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($reservations as &$rv) {
                $rv['reservation_id'] = (int)$rv['reservation_id'];
                $rv['student_id'] = (int)$rv['student_id'];
                $rv['user_ticket_id'] = (int)$rv['user_ticket_id'];
            }
            unset($rv);

            $class['target_user_ids'] = array_map(static fn($t) => (int)$t['user_id'], $targets);
            $class['current_capacity'] = count($reservations);

            echo json_encode([
                'success' => true,
                'data' => [
                    'class' => $class,
                    'targets' => $targets,
                    'reservations' => $reservations,
                ]
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ------- 수업 목록 조회 (기존, but with student visibility filter) -------
        $date = isset($_GET['date']) ? trim((string)$_GET['date']) : '';
        $teacherId = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;

        $sql = "
            SELECT
                c.*,
                u.name AS teacher_name,
                COUNT(DISTINCT ct.class_target_id) AS target_count,
                COUNT(DISTINCT CASE WHEN UPPER(TRIM(r.status)) = 'CONFIRMED' THEN r.reservation_id END) AS booked_count,
                GROUP_CONCAT(ct.user_id ORDER BY ct.user_id SEPARATOR ',') AS target_user_ids_csv
            FROM classes c
            LEFT JOIN users u ON c.teacher_id = u.user_id
            LEFT JOIN class_targets ct ON ct.class_id = c.class_id
            LEFT JOIN reservations r ON r.class_id = c.class_id
            WHERE c.deleted_at IS NULL
        ";
        $params = [];

        // If the requester is a student, restrict visibility:
        // - GROUP: visible to all
        // - or class_targets contains the student
        // - or class.teacher_id equals student's teacher_id (teacher's own students)
        if ($role === 'STUDENT' && $userId > 0) {
            // get student's teacher_id
            $stuStmt = $pdo->prepare("SELECT teacher_id FROM users WHERE user_id = ? LIMIT 1");
            $stuStmt->execute([$userId]);
            $stuRow = $stuStmt->fetch(PDO::FETCH_ASSOC);
            $studentTeacherId = $stuRow ? (int)$stuRow['teacher_id'] : 0;

            $sql .= " AND (UPPER(c.class_type) = 'GROUP' OR EXISTS (SELECT 1 FROM class_targets xct WHERE xct.class_id = c.class_id AND xct.user_id = ?) OR c.teacher_id = ?)";
            $params[] = $userId;
            $params[] = $studentTeacherId;
        }

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

    // ========================================
    // [수업 CRUD 및 선생님 변경] - POST 요청
    // ========================================

    if ($method === 'POST') {
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (!in_array($role, ['ADMIN', 'SUPPORTER', 'TEACHER'], true)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => '권한이 없습니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ------- 학생의 담당 선생님 변경 -------
        if ($action === 'change_teacher') {
            $studentId = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
            $newTeacherId = isset($_POST['new_teacher_id']) ? (int)$_POST['new_teacher_id'] : 0;

            if ($studentId <= 0 || $newTeacherId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => '학생 ID와 새 선생님 ID가 필요합니다.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // 권한 확인: ADMIN만 가능 (SUPPORTER는 본인 선생님의 학생만 변경 가능)
            if ($role === 'SUPPORTER') {
                $supporterStmt = $pdo->prepare("
                    SELECT supporter_id FROM users WHERE user_id = ? AND role = 'STUDENT'
                ");
                $supporterStmt->execute([$studentId]);
                $studentData = $supporterStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$studentData || (int)$studentData['supporter_id'] !== $userId) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'message' => '이 학생을 변경할 권한이 없습니다.'], JSON_UNESCAPED_UNICODE);
                    exit;
                }
            }

            // 학생 존재 여부 확인
            $checkStudent = $pdo->prepare("SELECT user_id FROM users WHERE user_id = ? AND role = 'STUDENT'");
            $checkStudent->execute([$studentId]);
            if (!$checkStudent->fetch()) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => '학생을 찾을 수 없습니다.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // 새 선생님 존재 여부 확인
            $checkTeacher = $pdo->prepare("SELECT user_id FROM users WHERE user_id = ? AND role = 'TEACHER'");
            $checkTeacher->execute([$newTeacherId]);
            if (!$checkTeacher->fetch()) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => '새 선생님을 찾을 수 없습니다.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // 학생의 담당 선생님 변경
            $updateStmt = $pdo->prepare("UPDATE users SET teacher_id = ? WHERE user_id = ?");
            $updateStmt->execute([$newTeacherId, $studentId]);

            echo json_encode([
                'success' => true,
                'message' => '담당 선생님이 변경되었습니다.',
                'student_id' => $studentId,
                'new_teacher_id' => $newTeacherId
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ------- 수업의 담당 선생님 변경 (선생님 부재/대체) -------
        if ($action === 'change_class_teacher') {
            $classId = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
            $newTeacherId = isset($_POST['new_teacher_id']) ? (int)$_POST['new_teacher_id'] : 0;

            if ($classId <= 0 || $newTeacherId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => '수업 ID와 새 선생님 ID가 필요합니다.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // ADMIN만 가능
            if ($role !== 'ADMIN') {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => '관리자만 수업 선생님을 변경할 수 있습니다.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // 수업 존재 여부 확인
            $checkClass = $pdo->prepare("SELECT class_id, teacher_id FROM classes WHERE class_id = ? AND deleted_at IS NULL");
            $checkClass->execute([$classId]);
            $classData = $checkClass->fetch(PDO::FETCH_ASSOC);
            
            if (!$classData) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => '수업을 찾을 수 없습니다.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // 새 선생님 존재 여부 확인
            $checkTeacher = $pdo->prepare("SELECT user_id FROM users WHERE user_id = ? AND role = 'TEACHER'");
            $checkTeacher->execute([$newTeacherId]);
            if (!$checkTeacher->fetch()) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => '새 선생님을 찾을 수 없습니다.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // 수업의 담당 선생님 변경
            $updateStmt = $pdo->prepare("UPDATE classes SET teacher_id = ? WHERE class_id = ?");
            $updateStmt->execute([$newTeacherId, $classId]);

            echo json_encode([
                'success' => true,
                'message' => '수업의 담당 선생님이 변경되었습니다.',
                'class_id' => $classId,
                'old_teacher_id' => (int)$classData['teacher_id'],
                'new_teacher_id' => $newTeacherId
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'assign_student') {
            $classId = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
            $studentId = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
            $ticketMode = strtoupper(trim((string)($_POST['ticket_mode'] ?? 'USE')));

            if ($classId <= 0 || $studentId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'class_id, student_id가 필요합니다.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            if (!in_array($ticketMode, ['USE', 'FREE'], true)) {
                $ticketMode = 'USE';
            }

            $pdo->beginTransaction();

            $classStmt = $pdo->prepare("\n                SELECT class_id, teacher_id, class_type, max_capacity, status\n                FROM classes\n                WHERE class_id = ? AND deleted_at IS NULL\n                LIMIT 1 FOR UPDATE\n            ");
            $classStmt->execute([$classId]);
            $class = $classStmt->fetch(PDO::FETCH_ASSOC);
            if (!$class) {
                throw new RuntimeException('수업을 찾을 수 없습니다.');
            }

            assertStudentEligibility($pdo, $class, $studentId);

            $dupStmt = $pdo->prepare("\n                SELECT reservation_id\n                FROM reservations\n                WHERE class_id = ? AND user_id = ? AND UPPER(TRIM(status)) = 'CONFIRMED'\n                LIMIT 1\n            ");
            $dupStmt->execute([$classId, $studentId]);
            if ($dupStmt->fetch()) {
                throw new RuntimeException('이미 예약된 학생입니다.');
            }

            $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE class_id = ? AND UPPER(TRIM(status)) = 'CONFIRMED'");
            $cntStmt->execute([$classId]);
            $reservedCount = (int)$cntStmt->fetchColumn();
            if ($reservedCount >= getClassCapacityLimit($class)) {
                throw new RuntimeException('정원이 가득 찼습니다. 학생을 추가할 수 없습니다.');
            }

            $classType = strtoupper((string)$class['class_type']);
            if ($ticketMode === 'USE') {
                $userTicketId = findUsableTicketId($pdo, $studentId, $classType);
                if ($userTicketId <= 0) {
                    throw new RuntimeException('사용 가능한 티켓이 없습니다.');
                }
            } else {
                $userTicketId = ensureAdminFreeTicketId($pdo, $studentId, $classType);
            }

            $ins = $pdo->prepare("\n                INSERT INTO reservations (user_id, class_id, user_ticket_id, status)\n                VALUES (?, ?, ?, 'CONFIRMED')\n            ");
            $ins->execute([$studentId, $classId, $userTicketId]);

            if ($ticketMode === 'USE') {
                consumeTicketCount($pdo, $userTicketId);
            }

            refreshClassCurrentCapacity($pdo, $classId);
            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => '학생이 수업에 등록되었습니다.',
                'reservation_id' => (int)$pdo->lastInsertId()
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'change_student') {
            $classId = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
            $reservationId = isset($_POST['reservation_id']) ? (int)$_POST['reservation_id'] : 0;
            $newStudentId = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
            $ticketMode = strtoupper(trim((string)($_POST['ticket_mode'] ?? 'USE')));

            if ($classId <= 0 || $reservationId <= 0 || $newStudentId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'class_id, reservation_id, student_id가 필요합니다.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            if (!in_array($ticketMode, ['USE', 'FREE'], true)) {
                $ticketMode = 'USE';
            }

            $pdo->beginTransaction();

            $classStmt = $pdo->prepare("\n                SELECT class_id, teacher_id, class_type, max_capacity\n                FROM classes\n                WHERE class_id = ? AND deleted_at IS NULL\n                LIMIT 1 FOR UPDATE\n            ");
            $classStmt->execute([$classId]);
            $class = $classStmt->fetch(PDO::FETCH_ASSOC);
            if (!$class) {
                throw new RuntimeException('수업을 찾을 수 없습니다.');
            }

            $resStmt = $pdo->prepare("\n                SELECT reservation_id, user_id, user_ticket_id\n                FROM reservations\n                WHERE reservation_id = ? AND class_id = ? AND UPPER(TRIM(status)) = 'CONFIRMED'\n                LIMIT 1 FOR UPDATE\n            ");
            $resStmt->execute([$reservationId, $classId]);
            $oldReservation = $resStmt->fetch(PDO::FETCH_ASSOC);
            if (!$oldReservation) {
                throw new RuntimeException('변경할 예약을 찾을 수 없습니다.');
            }

            assertStudentEligibility($pdo, $class, $newStudentId);

            $dupStmt = $pdo->prepare("\n                SELECT reservation_id\n                FROM reservations\n                WHERE class_id = ? AND user_id = ? AND UPPER(TRIM(status)) = 'CONFIRMED' AND reservation_id <> ?\n                LIMIT 1\n            ");
            $dupStmt->execute([$classId, $newStudentId, $reservationId]);
            if ($dupStmt->fetch()) {
                throw new RuntimeException('이미 예약된 학생입니다.');
            }

            $classType = strtoupper((string)$class['class_type']);
            if ($ticketMode === 'USE') {
                $newUserTicketId = findUsableTicketId($pdo, $newStudentId, $classType);
                if ($newUserTicketId <= 0) {
                    throw new RuntimeException('새 학생의 사용 가능한 티켓이 없습니다.');
                }
            } else {
                $newUserTicketId = ensureAdminFreeTicketId($pdo, $newStudentId, $classType);
            }

            $upd = $pdo->prepare("\n                UPDATE reservations\n                SET user_id = ?, user_ticket_id = ?, reserved_at = CURRENT_TIMESTAMP\n                WHERE reservation_id = ?\n            ");
            $upd->execute([$newStudentId, $newUserTicketId, $reservationId]);

            if ($ticketMode === 'USE') {
                consumeTicketCount($pdo, $newUserTicketId);
            }

            restoreTicketCount($pdo, (int)$oldReservation['user_ticket_id']);
            refreshClassCurrentCapacity($pdo, $classId);
            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => '예약 학생이 변경되었습니다. (기존 학생 티켓은 자동 복구)'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'remove_student') {
            $classId = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
            $reservationId = isset($_POST['reservation_id']) ? (int)$_POST['reservation_id'] : 0;
            $restoreTicket = strtoupper(trim((string)($_POST['restore_ticket'] ?? 'Y'))) === 'Y';

            if ($classId <= 0 || $reservationId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'class_id, reservation_id가 필요합니다.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $pdo->beginTransaction();

            $resStmt = $pdo->prepare("\n                SELECT reservation_id, class_id, user_ticket_id\n                FROM reservations\n                WHERE reservation_id = ? AND class_id = ? AND UPPER(TRIM(status)) = 'CONFIRMED'\n                LIMIT 1 FOR UPDATE\n            ");
            $resStmt->execute([$reservationId, $classId]);
            $reservation = $resStmt->fetch(PDO::FETCH_ASSOC);
            if (!$reservation) {
                throw new RuntimeException('삭제할 예약을 찾을 수 없습니다.');
            }

            $upd = $pdo->prepare("UPDATE reservations SET status = 'STUDENT_CANCELLED' WHERE reservation_id = ?");
            $upd->execute([$reservationId]);

            if ($restoreTicket) {
                restoreTicketCount($pdo, (int)$reservation['user_ticket_id']);
            }

            refreshClassCurrentCapacity($pdo, $classId);
            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => $restoreTicket ? '학생 예약이 취소되고 티켓이 복구되었습니다.' : '학생 예약이 취소되었습니다.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ========================================
        // [수업 생성 시 변경 이력 기록]
        // ========================================
        if ($action === 'create') {
            $teacherId = isset($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : $userId;
            $classType = normalizeClassType((string)($_POST['class_type'] ?? 'PRIVATE'));
            $classDate = trim((string)($_POST['class_date'] ?? ($_POST['lesson_date'] ?? '')));
            $startTime = trim((string)($_POST['start_time'] ?? ''));
            $endTime = trim((string)($_POST['end_time'] ?? ''));
            $maxCapacity = isset($_POST['max_capacity']) ? (int)$_POST['max_capacity'] : (isset($_POST['max_students']) ? (int)$_POST['max_students'] : 0);
            $targetUserIds = parseTargetUserIds($_POST);

            if ($role === 'TEACHER' && $teacherId !== $userId) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => '본인 수업만 생성할 수 있습니다.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if ($classDate === '' || $startTime === '' || $endTime === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => '날짜/시간은 필수입니다.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            if ($startTime >= $endTime) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => '종료 시간은 시작 시간보다 늦어야 합니다.'], JSON_UNESCAPED_UNICODE);
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

    // ✅ 변경 이력 기록
    $newValue = [
        'teacher_id' => $teacherId,
        'class_type' => $classType,
        'class_date' => $classDate,
        'start_time' => $startTime,
        'end_time' => $endTime,
        'max_capacity' => max(1, $maxCapacity),
        'target_user_ids' => $targetUserIds
    ];
    logClassChange($pdo, $classId, 'CREATE', $userId, null, $newValue, '수업이 생성되었습니다.');

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => '수업이 생성되었습니다.',
        'class_id' => $classId,
        'target_user_ids' => $targetUserIds
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

        // ========================================
        // [수업 수정 시 변경 이력 기록]
        // ========================================
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

            $oldStmt = $pdo->prepare("SELECT * FROM classes WHERE class_id = ? AND deleted_at IS NULL");
    $oldStmt->execute([$classId]);
    $old = $oldStmt->fetch(PDO::FETCH_ASSOC);

    if (!$old) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '수업을 찾을 수 없습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

            if ($role === 'TEACHER' && (int)$old['teacher_id'] !== $userId) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => '본인 수업만 수정할 수 있습니다.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if ($teacherId <= 0) {
        $teacherId = (int)$old['teacher_id'];
    }
    if ($classDate === '') {
        $classDate = (string)$old['class_date'];
    }
    if ($startTime === '') {
        $startTime = (string)$old['start_time'];
    }
    if ($endTime === '') {
        $endTime = (string)$old['end_time'];
    }
    if ($startTime >= $endTime) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '종료 시간은 시작 시간보다 늦어야 합니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($maxCapacity <= 0) {
        $maxCapacity = $classType === 'PRIVATE' ? 1 : ($classType === 'DUO' ? 2 : 5);
    }
    if ($classType === 'PRIVATE') {
        $maxCapacity = 1;
    } elseif ($classType === 'DUO') {
        $maxCapacity = 2;
    }

    $targetError = validateTargetsByClassType($classType, $targetUserIds);
    if ($targetError !== null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $targetError], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $reservedStmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE class_id = ? AND UPPER(TRIM(status)) = 'CONFIRMED'");
    $reservedStmt->execute([$classId]);
    $reservedCount = (int)$reservedStmt->fetchColumn();
    if ($reservedCount > max(1, $maxCapacity)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '현재 예약 인원보다 작은 정원/수업 유형으로 변경할 수 없습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

            $pdo->beginTransaction();

            // ✅ 변경 전 값 저장
    $oldValue = [
        'teacher_id' => (int)$old['teacher_id'],
        'class_type' => $old['class_type'],
        'class_date' => $old['class_date'],
        'start_time' => $old['start_time'],
        'end_time' => $old['end_time'],
        'max_capacity' => (int)$old['max_capacity']
    ];

            $upd = $pdo->prepare("
                UPDATE classes
                SET teacher_id = ?, class_type = ?, class_date = ?, start_time = ?, end_time = ?, max_capacity = ?
                WHERE class_id = ? AND deleted_at IS NULL
            ");
            $upd->execute([$teacherId, $classType, $classDate, $startTime, $endTime, max(1, $maxCapacity), $classId]);

            saveClassTargets($pdo, $classId, $targetUserIds);


            // ✅ 변경 이력 기록
    $newValue = [
        'teacher_id' => $teacherId,
        'class_type' => $classType,
        'class_date' => $classDate,
        'start_time' => $startTime,
        'end_time' => $endTime,
        'max_capacity' => max(1, $maxCapacity),
        'target_user_ids' => $targetUserIds
    ];
    
    $changes = [];
    if ($oldValue['teacher_id'] !== $newValue['teacher_id']) $changes[] = '선생님 변경';
    if ($oldValue['class_date'] !== $newValue['class_date']) $changes[] = '날짜 변경';
    if ($oldValue['start_time'] !== $newValue['start_time']) $changes[] = '시작시간 변경';
    if ($oldValue['end_time'] !== $newValue['end_time']) $changes[] = '종료시간 변경';
    if ($oldValue['max_capacity'] !== $newValue['max_capacity']) $changes[] = '정원 변경';
    if ($oldValue['class_type'] !== $newValue['class_type']) $changes[] = '수업유형 변경';
    
    logClassChange($pdo, $classId, 'UPDATE', $userId, $oldValue, $newValue, implode(', ', $changes));


            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => '수업이 수정되었습니다.',
                'class_id' => $classId,
                'target_user_ids' => $targetUserIds
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ========================================
        // [수업 삭제 시 변경 이력 기록]
        // ========================================
        if ($action === 'delete') {
            $classId = isset($_POST['class_id']) ? (int)$_POST['class_id'] : (isset($_POST['slot_id']) ? (int)$_POST['slot_id'] : 0);

            if ($classId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'class_id가 필요합니다.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $oldStmt = $pdo->prepare("SELECT * FROM classes WHERE class_id = ? AND deleted_at IS NULL");
            $oldStmt->execute([$classId]);
            $oldData = $oldStmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("UPDATE classes SET deleted_at = CURRENT_TIMESTAMP, status = 'CANCELLED' WHERE class_id = ?");
            $stmt->execute([$classId]);

            if ($oldData) {
                $oldValue = [
                    'teacher_id' => (int)$oldData['teacher_id'],
                    'class_type' => $oldData['class_type'],
                    'class_date' => $oldData['class_date'],
                    'start_time' => $oldData['start_time'],
                    'end_time' => $oldData['end_time'],
                    'status' => 'AVAILABLE'
                ];
                logClassChange($pdo, $classId, 'DELETE', $userId, $oldValue, null, '수업이 취소/삭제되었습니다.');
            }

            echo json_encode(['success' => true, 'message' => '수업이 취소/삭제되었습니다.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '잘못된 요청입니다.'], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}


