<?php
require_once __DIR__ . '/../../service/db.php';

try {
    $pdo = DB::getConnection();

    // 1) time_slots
    $pdo->exec("CREATE TABLE IF NOT EXISTS time_slots (
        id INT AUTO_INCREMENT PRIMARY KEY,
        teacher_id INT NOT NULL,
        start_time DATETIME NOT NULL,
        end_time DATETIME NOT NULL,
        lesson_type ENUM('GROUP_25','PRIVATE_25') NOT NULL DEFAULT 'PRIVATE_25',
        theme VARCHAR(191) DEFAULT NULL,
        max_students INT NOT NULL DEFAULT 1,
        google_event_id VARCHAR(255) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 2) tickets
    $pdo->exec("CREATE TABLE IF NOT EXISTS Tickets (
        ticket_id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        category VARCHAR(191) DEFAULT NULL,
        total_count INT NOT NULL DEFAULT 0,
        remaining_count INT NOT NULL DEFAULT 0,
        status VARCHAR(50) DEFAULT '정상'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 3) schedules (lesson logs / confirmed lessons)
    $pdo->exec("CREATE TABLE IF NOT EXISTS schedules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        lesson_date DATE NOT NULL,
        lesson_time TIME NOT NULL,
        status ENUM('scheduled','completed','canceled','no_show') NOT NULL DEFAULT 'scheduled',
        progress TEXT DEFAULT NULL,
        fee_applied INT NOT NULL DEFAULT 0,
        next_schedule_id INT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 4) reservations
    $pdo->exec("CREATE TABLE IF NOT EXISTS Reservations (
        reservation_id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        teacher_id INT NOT NULL,
        class_type VARCHAR(50) DEFAULT '����',
        reserve_date DATE NOT NULL,
        reserve_time TIME NOT NULL,
        status VARCHAR(50) NOT NULL DEFAULT '����Ϸ�',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Optional: seed small sample if empty
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM time_slots")->fetchColumn();
    if ($cnt === 0) {
        $ins = $pdo->prepare("INSERT INTO time_slots (teacher_id, start_time, end_time, lesson_type, theme, max_students) VALUES (?,?,?,?,?,?)");
        $ins->execute([1,'2026-06-15 16:00:00','2026-06-15 16:25:00','GROUP_25','�ʱ� ȸȭ ���',2]);
        $ins->execute([1,'2026-06-15 19:00:00','2026-06-15 19:25:00','GROUP_25','���� �׷� ���͵�',4]);
        $ins->execute([2,'2026-06-16 10:00:00','2026-06-16 10:25:00','PRIVATE_25','���� 1:1 ����',1]);
    }

    // 5) users table
    $pdo->exec("CREATE TABLE IF NOT EXISTS Users (
        user_id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(100) NOT NULL,
        username VARCHAR(50) NOT NULL,
        nickname VARCHAR(50) NOT NULL,
        password VARCHAR(255) NOT NULL,
        name VARCHAR(50) NOT NULL,
        phone VARCHAR(20) DEFAULT NULL,
        birthday DATE DEFAULT NULL,
        role VARCHAR(20) NOT NULL DEFAULT '�л�',
        country_code VARCHAR(2) NOT NULL DEFAULT 'KO',
        status VARCHAR(20) DEFAULT 'active',
        teacher_id INT DEFAULT NULL,
        support_id INT DEFAULT NULL,
        lesson_fee INT NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_email (email),
        UNIQUE KEY uq_username (username),
        UNIQUE KEY uq_nickname (nickname)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    echo json_encode(['success'=>true, 'message'=>'Tables created/checked.']);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    exit;
}
