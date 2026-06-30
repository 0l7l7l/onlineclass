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
    $method = $_SERVER['REQUEST_METHOD'];
    $action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';
    $user_id = (int)$_SESSION['user_id'];

    if ($method === 'GET') {
        // ? ?? ??
        $sql = "
            SELECT r.*, c.class_date, c.start_time, c.end_time, c.class_type, u.name AS teacher_name
            FROM reservations r
            JOIN classes c ON r.class_id = c.class_id
            LEFT JOIN users u ON c.teacher_id = u.user_id
            WHERE r.user_id = ?
            ORDER BY c.class_date ASC, c.start_time ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id]);
        $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success'=>true, 'data'=>$reservations]);
        exit;
    }

    if ($method === 'POST') {
        if ($action === 'book') {
            $class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
            $user_ticket_id = isset($_POST['user_ticket_id']) ? (int)$_POST['user_ticket_id'] : 0;

            if ($class_id <= 0 || $user_ticket_id <= 0) {
                echo json_encode(['success'=>false, 'message'=>'class_id? user_ticket_id? ?????.']);
                exit;
            }

            $pdo->beginTransaction();

            // 1. ?? ?? ?? ? ?? ??
            // (??? ?? ?? ?? ?? ? ???? ?? ?? ??)
            $stmt = $pdo->prepare("SELECT remaining_count, expired_at FROM user_tickets WHERE user_ticket_id = ? AND user_id = ? FOR UPDATE");
            $stmt->execute([$user_ticket_id, $user_id]);
            $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$ticket || $ticket['remaining_count'] <= 0 || strtotime($ticket['expired_at']) < time()) {
                $pdo->rollBack();
                echo json_encode(['success'=>false, 'message'=>'?? ??? ??? ????.']);
                exit;
            }

            // 2. ?? ??
            $stmt = $pdo->prepare("INSERT INTO reservations (user_id, class_id, user_ticket_id, status) VALUES (?, ?, ?, 'CONFIRMED')");
            $stmt->execute([$user_id, $class_id, $user_ticket_id]);
            $reservation_id = $pdo->lastInsertId();

            // 3. ??? current_capacity ?? ?? ? ?? remaining ??
            $pdo->exec("UPDATE classes SET current_capacity = current_capacity + 1 WHERE class_id = $class_id");
            $pdo->exec("UPDATE user_tickets SET remaining_count = remaining_count - 1 WHERE user_ticket_id = $user_ticket_id");
            if ($ticket['remaining_count'] - 1 == 0) {
                $pdo->exec("UPDATE user_tickets SET status = 'EXHAUSTED' WHERE user_ticket_id = $user_ticket_id");
            }

            $pdo->commit();
            echo json_encode(['success'=>true, 'message'=>'??? ???????.', 'reservation_id'=>$reservation_id]);
            exit;
        }
        else if ($action === 'cancel') {
            $reservation_id = isset($_POST['reservation_id']) ? (int)$_POST['reservation_id'] : 0;

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT * FROM reservations WHERE reservation_id = ? AND user_id = ? FOR UPDATE");
            $stmt->execute([$reservation_id, $user_id]);
            $reservation = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$reservation || $reservation['status'] !== 'CONFIRMED') {
                $pdo->rollBack();
                echo json_encode(['success'=>false, 'message'=>'??? ??? ????.']);
                exit;
            }

            // ?? ??
            $stmt = $pdo->prepare("UPDATE reservations SET status = 'STUDENT_CANCELLED' WHERE reservation_id = ?");
            $stmt->execute([$reservation_id]);

            // ?? ??
            $pdo->exec("UPDATE user_tickets SET remaining_count = remaining_count + 1, status = 'ACTIVE' WHERE user_ticket_id = " . $reservation['user_ticket_id']);

            // ??? ?? ??
            $pdo->exec("UPDATE classes SET current_capacity = current_capacity - 1 WHERE class_id = " . $reservation['class_id']);

            $pdo->commit();
            echo json_encode(['success'=>true, 'message'=>'??? ???????.']);
            exit;
        }
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success'=>false, 'message'=>$e->getMessage()]);
}
