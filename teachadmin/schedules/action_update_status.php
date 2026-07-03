<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method']);
    exit;
}

$schedule_id = isset($_POST['schedule_id']) ? intval($_POST['schedule_id']) : 0;
$status = isset($_POST['status']) ? $_POST['status'] : null;

if ($schedule_id <= 0 || !$status) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit;
}

try {
    $stmt = $pdo->prepare("UPDATE schedules SET status = :status WHERE id = :id");
    $stmt->execute(['status' => $status, 'id' => $schedule_id]);
    echo json_encode(['success' => true, 'message' => 'Status updated']);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
