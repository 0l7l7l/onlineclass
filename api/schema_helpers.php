<?php

function ensureClassScheduleSupportTables(PDO $pdo): void
{
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS `class_targets` (
    `class_target_id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '수업 대상 매핑 고유 번호',
    `class_id` INT NOT NULL COMMENT '대상 지정할 수업 ID',
    `user_id` INT NOT NULL COMMENT '지정 학생 유저 ID',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '지정 일시',
    UNIQUE KEY `uq_class_targets_class_user` (`class_id`, `user_id`),
    FOREIGN KEY (`class_id`) REFERENCES `classes`(`class_id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS `class_change_logs` (
    `log_id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '변경 이력 고유 번호',
    `class_id` INT NOT NULL COMMENT '변경된 수업 ID',
    `action` ENUM('CREATE', 'UPDATE', 'DELETE', 'TEACHER_CHANGE') NOT NULL COMMENT '변경 액션',
    `changed_by` INT NULL COMMENT '변경한 사용자 ID (관리자/선생님)',
    `old_value` JSON NULL COMMENT '변경 전 수업 정보 (JSON)',
    `new_value` JSON NULL COMMENT '변경 후 수업 정보 (JSON)',
    `description` VARCHAR(255) NULL COMMENT '변경 내용 요약',
    `changed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '변경 일시',
    FOREIGN KEY (`class_id`) REFERENCES `classes`(`class_id`) ON DELETE CASCADE,
    FOREIGN KEY (`changed_by`) REFERENCES `users`(`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
}

function tableColumnExists(PDO $pdo, string $tableName, string $columnName): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$tableName, $columnName]);
    return (int)$stmt->fetchColumn() > 0;
}

function ensureTicketPerWeekColumns(PDO $pdo): void
{
    if (!tableColumnExists($pdo, 'products', 'per_week')) {
        $pdo->exec("ALTER TABLE `products` ADD COLUMN `per_week` INT NOT NULL DEFAULT 0 COMMENT '그룹 티켓 주당 예약 가능 횟수' AFTER `expiry_days`");
    }

    if (!tableColumnExists($pdo, 'user_tickets', 'per_week')) {
        $pdo->exec("ALTER TABLE `user_tickets` ADD COLUMN `per_week` INT NOT NULL DEFAULT 0 COMMENT '구매 시점의 주당 예약 가능 횟수' AFTER `expired_at`");
    }
}

function ensureUserSignupColumns(PDO $pdo): void
{
    if (!tableColumnExists($pdo, 'users', 'phone_number')) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `phone_number` VARCHAR(20) NULL COMMENT '휴대폰 번호' AFTER `current_money`");
    }

    if (!tableColumnExists($pdo, 'users', 'email')) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `email` VARCHAR(100) NULL COMMENT '이메일 주소' AFTER `phone_number`");
    }

    if (!tableColumnExists($pdo, 'users', 'consent_version')) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `consent_version` VARCHAR(32) NULL COMMENT '약관·개인정보 동의 버전' AFTER `email`");
    }

    if (!tableColumnExists($pdo, 'users', 'consent_at')) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `consent_at` DATETIME NULL COMMENT '동의 일시' AFTER `consent_version`");
    }

    if (!tableColumnExists($pdo, 'users', 'consent_ip')) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `consent_ip` VARCHAR(45) NULL COMMENT '동의 당시 요청자 IP' AFTER `consent_at`");
    }
}
