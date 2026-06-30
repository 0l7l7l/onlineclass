api\admin_teachers.php
````````php
<?php
require_once __DIR__ . '/db.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

// ========================================
// 권한 체크: ADMIN, SUPPORTER, TEACHER만 접근 가능
// ========================================
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$role = strtoupper(trim((string)($_SESSION['user_role'] ?? '')));
if (!in_array($role, ['ADMIN', 'SUPPORTER', 'TEACHER'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '권한이 없습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = DB::getConnection();
    $action = isset($_GET['action']) ? trim((string)$_GET['action']) : '';
    $teacherId = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;

    // ========================================
    // [1] 특정 선생님의 담당 학생 목록 조회
    // ========================================
    if ($action === '' && $teacherId > 0) {
        // 담당선생님이 $teacherId인 모든 STUDENT 역할 사용자 조회
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

        // 숫자 필드 타입 변환
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

    // ========================================
    // [2] 전체 선생님 목록 조회 (기본값)
    // ========================================
    if ($action === '' && $teacherId === 0) {
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

        // 숫자 필드 타입 변환
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

    // ========================================
    // [3] 특정 선생님의 상세 정보 및 담당 학생 조회
    // ========================================
    if ($action === 'detail' && $teacherId > 0) {
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

        // 숫자 필드 타입 변환
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

    // ========================================
    // [4] 선생님 변경: 학생의 담당 선생님 변경
    // ========================================
    if ($action === 'change_teacher' && $_SERVER['REQUEST_METHOD'] === 'POST') {
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
            
            if (!$studentData || (int)$studentData['supporter_id'] !== (int)$_SESSION['user_id']) {
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

    // ========================================
    // [5] 선생님 변경: 수업의 담당 선생님 변경
    // ========================================
    if ($action === 'change_class_teacher' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $classId = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
        $newTeacherId = isset($_POST['new_teacher_id']) ? (int)$_POST['new_teacher_id'] : 0;

        if ($classId <= 0 || $newTeacherId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '수업 ID와 새 선생님 ID가 필요합니다.'], JSON_UNESCAPED_UNICODE);
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

    // 기본값: 전체 선생님 목록 반환
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

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'DB 오류: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
