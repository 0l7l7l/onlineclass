<?php
require_once __DIR__ . '/db.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = DB::getConnection();
    $method = $_SERVER['REQUEST_METHOD'];
    $action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

    if ($method === 'GET') {
        if ($action === 'detail') {
            $class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
            if ($class_id <= 0) {
                echo json_encode(['success'=>false, 'message'=>'class_id? ?????.']);
                exit;
            }
            $stmt = $pdo->prepare("
                SELECT c.*, u.name AS teacher_name 
                FROM classes c 
                LEFT JOIN users u ON c.teacher_id = u.user_id 
                WHERE c.class_id = ? AND c.deleted_at IS NULL
            ");
            $stmt->execute([$class_id]);
            $class = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['success'=>true, 'data'=>$class]);
            exit;
        }

        // List classes
        $date = isset($_GET['date']) ? trim($_GET['date']) : date('Y-m-d');
        $teacher_id = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;

        $sql = "
            SELECT c.*, u.name AS teacher_name 
            FROM classes c 
            LEFT JOIN users u ON c.teacher_id = u.user_id 
            WHERE c.deleted_at IS NULL
        ";
        $params = [];

        if ($date) {
            $sql .= " AND c.class_date = ?";
            $params[] = $date;
        }
        if ($teacher_id > 0) {
            $sql .= " AND c.teacher_id = ?";
            $params[] = $teacher_id;
        }

        $sql .= " ORDER BY c.class_date ASC, c.start_time ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success'=>true, 'data'=>$classes]);
        exit;
    }

    if ($method === 'POST') {
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => '???? ?????.']);
            exit;
        }
        $role = strtoupper(trim($_SESSION['user_role'] ?? ''));
        if (!in_array($role, ['ADMIN', 'SUPPORTER', 'TEACHER'], true)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => '??? ????.']);
            exit;
        }

        if ($action === 'create') {
            $teacher_id = isset($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : (int)$_SESSION['user_id'];
            $class_type = $_POST['class_type'] ?? 'PRIVATE';
            $class_date = $_POST['class_date'] ?? '';
            $start_time = $_POST['start_time'] ?? '';
            $end_time = $_POST['end_time'] ?? '';
            $max_capacity = isset($_POST['max_capacity']) ? (int)$_POST['max_capacity'] : 1;

            $stmt = $pdo->prepare("INSERT INTO classes (teacher_id, class_type, class_date, start_time, end_time, max_capacity, status) VALUES (?, ?, ?, ?, ?, ?, 'AVAILABLE')");
            $stmt->execute([$teacher_id, $class_type, $class_date, $start_time, $end_time, $max_capacity]);
            echo json_encode(['success'=>true, 'message'=>'???? ???????.', 'class_id'=>$pdo->lastInsertId()]);
            exit;
        }
        else if ($action === 'update') {
            $class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
            $class_type = $_POST['class_type'] ?? 'PRIVATE';
            $class_date = $_POST['class_date'] ?? '';
            $start_time = $_POST['start_time'] ?? '';
            $end_time = $_POST['end_time'] ?? '';
            $max_capacity = isset($_POST['max_capacity']) ? (int)$_POST['max_capacity'] : 1;

            $stmt = $pdo->prepare("UPDATE classes SET class_type=?, class_date=?, start_time=?, end_time=?, max_capacity=? WHERE class_id=?");
            $stmt->execute([$class_type, $class_date, $start_time, $end_time, $max_capacity, $class_id]);
            echo json_encode(['success'=>true, 'message'=>'???? ???????.']);
            exit;
        }
        else if ($action === 'delete') {
            $class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
            $stmt = $pdo->prepare("UPDATE classes SET deleted_at=CURRENT_TIMESTAMP, status='CANCELLED' WHERE class_id=?");
            $stmt->execute([$class_id]);
            echo json_encode(['success'=>true, 'message'=>'???? ??(??)?????.']);
            exit;
        }
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success'=>false, 'message'=>$e->getMessage()]);
}
