<?php
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = DB::getConnection();

    // ==========================================
    // 1. users
    // - 플랫폼의 모든 사용자 정보를 관리
    // - 선생님/서포터 연결 및 전자머니 잔액 포함
    // ==========================================
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS `users` (
    `user_id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '유저 고유 일련번호',
    `username` VARCHAR(50) NOT NULL UNIQUE COMMENT '로그인용 아이디',
    `password` VARCHAR(255) NOT NULL COMMENT '암호화된 비밀번호',
    `name` VARCHAR(50) NOT NULL COMMENT '사용자 이름',
    `role` ENUM('ADMIN', 'SUPPORTER', 'TEACHER', 'STUDENT') NOT NULL COMMENT '사용자 권한',
    `teacher_id` INT NULL COMMENT '담당 선생님 ID (학생 유저만 가짐)',
    `supporter_id` INT NULL COMMENT '담당 서포터 ID (학생 및 선생님 유저가 가짐)',
    `current_money` INT DEFAULT 0 COMMENT '유저가 보유한 현재 전자머니 잔액',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '가입 일시',
    `deleted_at` TIMESTAMP NULL DEFAULT NULL COMMENT '소프트 삭제용 탈퇴 일시',
    FOREIGN KEY (`teacher_id`) REFERENCES `users`(`user_id`) ON DELETE SET NULL,
    FOREIGN KEY (`supporter_id`) REFERENCES `users`(`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

    // users 조회 최적화 인덱스
    $pdo->exec("CREATE INDEX `idx_users_teacher_id` ON `users`(`teacher_id`);");
    $pdo->exec("CREATE INDEX `idx_users_supporter_id` ON `users`(`supporter_id`);");
    $pdo->exec("CREATE INDEX `idx_users_role_deleted` ON `users`(`role`, `deleted_at`);");

    // ==========================================
    // 2. products
    // - 판매 상품(티켓, PDF, VIDEO) 관리
    // ==========================================
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS `products` (
    `product_id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '상품 고유 일련번호',
    `product_type` ENUM('TICKET', 'PDF', 'VIDEO') NOT NULL COMMENT '상품 대분류',
    `title` VARCHAR(100) NOT NULL COMMENT '화면에 노출될 상품명',
    `price` INT NOT NULL COMMENT '상품 구매에 필요한 전자머니 금액',
    `lecture_number` INT NULL COMMENT 'PDF/영상일 경우 해당 강좌 번호 (1~12)',
    `class_type` ENUM('PRIVATE', 'DUO', 'GROUP') NULL COMMENT '티켓일 경우 수업 유형',
    `total_count` INT NULL COMMENT '티켓일 경우 제공되는 총 수강 횟수',
    `expiry_days` INT NOT NULL COMMENT '유효 기간 (티켓 보유일수 또는 디지털 콘텐츠 제한 일수)',
    `is_active` TINYINT(1) DEFAULT 1 COMMENT '소프트 삭제용 판매 여부 (1: 판매중, 0: 판매중단)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

    // ==========================================
    // 3. wallet_histories
    // - 전자머니 충전/구매 내역 저장
    // ==========================================
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS `wallet_histories` (
    `history_id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '내역 고유 일련번호',
    `user_id` INT NOT NULL COMMENT '자산이 변동된 유저 ID',
    `type` ENUM('CHARGE', 'BUY_PRODUCT') NOT NULL COMMENT '변동 유형',
    `amount` INT NOT NULL COMMENT '변동 금액 (충전 +, 구매 -)',
    `balance_snapshot` INT NOT NULL COMMENT '거래 직후의 전자머니 잔액 스냅샷',
    `target_id` INT NULL COMMENT '연관된 product_id 또는 결제/예약 ID 번호',
    `description` VARCHAR(255) NULL COMMENT '상세 내용 요약',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '거래가 발생한 일시',
    FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

    $pdo->exec("CREATE INDEX `idx_wallet_histories_user_id` ON `wallet_histories`(`user_id`);");

    // ==========================================
    // 4. request
    // - 충전 및 입금 신청 처리
    // ==========================================
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS `request` (
    `request_id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '신청 고유 번호',
    `user_id` INT NOT NULL COMMENT '충전을 신청한 유저 ID',
    `amount` INT NOT NULL COMMENT '충전 요청 금액',
    `payment_method` VARCHAR(30) NOT NULL COMMENT '충전 수단 (예: BANK_TRANSFER, SEMO_PAY 등)',
    `depositor_name` VARCHAR(50) NULL COMMENT '입금자명 (무통장 입금 등 필요시에만 입력)',
    `status` ENUM('PENDING', 'APPROVED', 'REJECTED') DEFAULT 'PENDING' COMMENT '신청 상태 (대기, 승인, 거절)',
    `reject_reason` VARCHAR(255) NULL COMMENT '거절 시 사유',
    `approved_by` INT NULL COMMENT '승인/거절을 처리한 관리자 ID',
    `requested_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '신청 일시',
    `processed_at` TIMESTAMP NULL DEFAULT NULL COMMENT '처리 일시',
    FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`),
    FOREIGN KEY (`approved_by`) REFERENCES `users`(`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

    $pdo->exec("CREATE INDEX `idx_request_status` ON `request`(`status`);");
    $pdo->exec("CREATE INDEX `idx_request_user_id` ON `request`(`user_id`);");

    // ==========================================
    // 5. user_tickets
    // - 유저가 보유한 수업 예약용 티켓 관리
    // ==========================================
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS `user_tickets` (
    `user_ticket_id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '회원 보유 티켓 고유 번호',
    `user_id` INT NOT NULL COMMENT '티켓을 소유한 학생 유저 ID',
    `product_id` INT NOT NULL COMMENT '구매한 원본 티켓 상품 ID',
    `remaining_count` INT NOT NULL COMMENT '현재 남은 수업 예약 가능 횟수',
    `status` ENUM('ACTIVE', 'EXPIRED', 'EXHAUSTED') NOT NULL COMMENT '티켓 상태',
    `expired_at` DATETIME NOT NULL COMMENT '티켓 사용 제한 만료일',
    `purchased_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '티켓 구매 일시',
    FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`),
    FOREIGN KEY (`product_id`) REFERENCES `products`(`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

    $pdo->exec("CREATE INDEX `idx_user_tickets_user_id` ON `user_tickets`(`user_id`);");

    // ==========================================
    // 6. user_access
    // - PDF / VIDEO 콘텐츠 접근 권한 관리
    // ==========================================
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS `user_access` (
    `access_id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '권한 고유 일련번호',
    `user_id` INT NOT NULL COMMENT '콘텐츠를 구매한 학생 유저 ID',
    `product_id` INT NOT NULL COMMENT '구매한 PDF 또는 동영상 상품 ID',
    `purchased_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '콘텐츠 구매 일시',
    `expired_at` DATETIME NOT NULL COMMENT '열람/다운로드/시청 만료 일시',
    FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`),
    FOREIGN KEY (`product_id`) REFERENCES `products`(`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

    $pdo->exec("CREATE INDEX `idx_user_access_user_id` ON `user_access`(`user_id`);");

    // ==========================================
    // 7. classes
    // - 선생님이 개설한 수업 일정 관리
    // ==========================================
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS `classes` (
    `class_id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '수업 고유 일련번호',
    `teacher_id` INT NOT NULL COMMENT '수업을 진행하는 담당 선생님 유저 ID',
    `class_type` ENUM('PRIVATE', 'DUO', 'GROUP') NOT NULL COMMENT '수업 형태',
    `class_date` DATE NOT NULL COMMENT '수업 진행 날짜',
    `start_time` TIME NOT NULL COMMENT '수업 시작 시간',
    `end_time` TIME NOT NULL COMMENT '수업 종료 시간',
    `max_capacity` INT NOT NULL COMMENT '수강 최대 정원',
    `current_capacity` INT DEFAULT 0 COMMENT '현재 이 수업에 예약이 확정된 학생 수',
    `status` ENUM('AVAILABLE', 'CANCELLED', 'CLOSED') DEFAULT 'AVAILABLE' COMMENT '수업 상태',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '수업 최초 생성 일시',
    `deleted_at` TIMESTAMP NULL DEFAULT NULL COMMENT '소프트 삭제용 수업 취소/삭제 일시',
    FOREIGN KEY (`teacher_id`) REFERENCES `users`(`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

    $pdo->exec("CREATE INDEX `idx_classes_teacher_date` ON `classes`(`teacher_id`, `class_date`);");

    // ==========================================
    // 8. class_targets
    // - PRIVATE/DUO 수업의 지정 학생 매핑
    // - PRIVATE: 최대 1명, DUO: 최대 2명(비즈니스 로직에서 제한)
    // ==========================================
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

    $pdo->exec("CREATE INDEX `idx_class_targets_class_id` ON `class_targets`(`class_id`);");
    $pdo->exec("CREATE INDEX `idx_class_targets_user_id` ON `class_targets`(`user_id`);");

    // ==========================================
    // 9. reservations - 학생의 수업 예약 및 매칭 정보
    // ==========================================
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS `reservations` (
    `reservation_id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '예약 및 매칭 고유 일련번호',
    `user_id` INT NOT NULL COMMENT '예약을 신청한 학생 유저 ID',
    `class_id` INT NOT NULL COMMENT '학생이 신청한 수업 ID',
    `user_ticket_id` INT NOT NULL COMMENT '예약 시 소진한 회원 보유 티켓 ID',
    `status` ENUM('CONFIRMED', 'STUDENT_CANCELLED', 'ATTENDED') DEFAULT 'CONFIRMED' COMMENT '예약 상태',
    `reserved_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '예약을 완료한 일시',
    FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`),
    FOREIGN KEY (`class_id`) REFERENCES `classes`(`class_id`),
    FOREIGN KEY (`user_ticket_id`) REFERENCES `user_tickets`(`user_ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

    $pdo->exec("CREATE INDEX `idx_reservations_user_id` ON `reservations`(`user_id`);");
    $pdo->exec("CREATE INDEX `idx_reservations_class_id` ON `reservations`(`class_id`);");

    // ==========================================
    // 10. coupons 아직DB없음 추가예정
    // ==========================================
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS `coupons` (
    `coupon_id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '쿠폰 고유 번호',
    `coupon_code` VARCHAR(50) NOT NULL UNIQUE COMMENT '유저가 입력할 난수 형태의 쿠폰 번호',
    `product_id` INT NOT NULL COMMENT '쿠폰 등록 시 지급할 연관 상품 ID',
    `status` ENUM('READY', 'USED', 'EXPIRED') DEFAULT 'READY' COMMENT '쿠폰 상태 (사용가능, 사용완료, 만료됨)',
    `user_id` INT NULL COMMENT '쿠폰을 등록한 학생 유저 ID (USED 상태일 때만 입력)',
    `generated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '쿠폰 번호 생성 일시',
    `used_at` TIMESTAMP NULL DEFAULT NULL COMMENT '유저가 쿠폰을 실제 등록한 일시',
    `expired_at` DATETIME NOT NULL COMMENT '쿠폰 등록 제한 만료일 (이 기한 전에 등록해야 함)',
    FOREIGN KEY (`product_id`) REFERENCES `products`(`product_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

    $pdo->exec("CREATE INDEX `idx_coupons_code` ON `coupons`(`coupon_code`);");

    echo json_encode(['success' => true, 'message' => 'SQL 기준 테이블 생성/확인 완료.'], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}