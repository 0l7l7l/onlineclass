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

        // ------- 수업 목록 조회 (기존) -------
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

        // ========================================
// 변경 이력 기록 헬퍼 함수 (상단에 추가)
// ========================================
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

             // ✅ 변경 전 정보 조회
    $oldStmt = $pdo->prepare("SELECT * FROM classes WHERE class_id = ? AND deleted_at IS NULL");
    $oldStmt->execute([$classId]);
    $oldData = $oldStmt->fetch(PDO::FETCH_ASSOC);


            $stmt = $pdo->prepare("UPDATE classes SET deleted_at = CURRENT_TIMESTAMP, status = 'CANCELLED' WHERE class_id = ?");
            $stmt->execute([$classId]);

             // ✅ 변경 이력 기록
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

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '잘못된 요청입니다.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}


