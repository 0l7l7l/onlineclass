-- ==========================================
-- [전체 통합 DB 스키마]
-- 온라인 클래스 및 디지털 콘텐츠 플랫폼 데이터베이스
-- 주요 기능: 회원관리, 상품(티켓/VOD/PDF), 전자머니 결제, 수강권 관리, 수업 일정 및 예약
-- ==========================================

-- 1. 유저 테이블 (users)
-- 기능: 플랫폼을 이용하는 모든 사용자(관리자, 서포터, 선생님, 학생)의 기본 정보 및 권한을 관리합니다.
-- 특징: 선생님-학생, 서포터-사용자 간의 멘토링/관리 관계를 연결할 수 있으며, 전자머니 잔액(current_money)을 저장합니다.
CREATE TABLE `users` (
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

-- users 테이블 성능 최적화를 위한 인덱스 추가
CREATE INDEX idx_users_teacher_id ON `users`(`teacher_id`);
CREATE INDEX idx_users_supporter_id ON `users`(`supporter_id`);
CREATE INDEX idx_users_role_deleted ON `users`(`role`, `deleted_at`);


-- 2. 상품 테이블 (products)
-- 기능: 플랫폼에서 판매되는 모든 유형의 상품 정보를 등록하고 관리합니다.
-- 특징: 티켓(수업 수강용), PDF(교재), VIDEO(동영상 강의) 등 상품의 대분류에 따라 필요한 속성(수강 횟수, 유효 기간 등)을 한 곳에서 관리합니다.
CREATE TABLE `products` (
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


-- 3. 전자머니 이용 내역 테이블 (wallet_histories)
-- 기능: 사용자의 전자머니 충전 및 상품 구매 시 발생하는 자산의 변동 내역을 영구적으로 기록합니다.
-- 특징: 거래 직후의 잔액(balance_snapshot)을 저장하여 나중에 데이터 검증이나 정산 시 무결성을 보장합니다.
CREATE TABLE `wallet_histories` (
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

CREATE INDEX idx_wallet_histories_user_id ON `wallet_histories`(`user_id`);


-- 8. 충전 및 입금 신청 관리 테이블 (request)
-- 기능: 무통장 입금, 세모 충전 등 유저의 모든 충전 요청을 통합 관리하고 승인 프로세스를 처리합니다.
CREATE TABLE `request` (
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

CREATE INDEX idx_request_status ON `request`(`status`);
CREATE INDEX idx_request_user_id ON `request`(`user_id`);


-- 4. 회원 보유 티켓 테이블 (user_tickets)
-- 기능: 유저가 구매한 '수업 예약용 티켓'의 실시간 상태 및 남은 예약 가능 횟수를 관리합니다.
-- 특징: 예약 시마다 remaining_count가 차감되며, 기한 만료(expired_at) 여부와 티켓 소진 상태를 추적합니다.
CREATE TABLE `user_tickets` (
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

CREATE INDEX idx_user_tickets_user_id ON `user_tickets`(`user_id`);


-- 5. 디지털 콘텐츠 권한 테이블 (user_access)
-- 기능: 유저가 구매한 '비물리적 콘텐츠(동영상/PDF)'에 대한 열람 및 다운로드 권한을 관리합니다.
-- 특징: 단순 다운로드뿐만 아니라, 지정된 만료 시간(expired_at) 내에만 자원에 접근할 수 있도록 보안 체크 용도로 활용됩니다.
CREATE TABLE `user_access` (
    `access_id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '권한 고유 일련번호',
    `user_id` INT NOT NULL COMMENT '콘텐츠를 구매한 학생 유저 ID',
    `product_id` INT NOT NULL COMMENT '구매한 PDF 또는 동영상 상품 ID',
    `purchased_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '콘텐츠 구매 일시',
    `expired_at` DATETIME NOT NULL COMMENT '열람/다운로드/시청 만료 일시',
    FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`),
    FOREIGN KEY (`product_id`) REFERENCES `products`(`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_user_access_user_id ON `user_access`(`user_id`);


-- 6. 수업/클래스 테이블 (classes)
-- 기능: 선생님이 개설한 화상회의나 오프라인 수업의 일정, 타입, 최대 정원 등의 스케줄 데이터를 관리합니다.
-- 특징: 중복 예약 방지를 위해 현재 예약된 인원 수(current_capacity)를 추적하며, 시간표 기반의 캘린더 기능을 구현할 때 주축이 됩니다.
CREATE TABLE `classes` (
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

CREATE INDEX idx_classes_teacher_date ON `classes`(`teacher_id`, `class_date`);


-- 7. 수업 대상 지정 테이블 (class_targets)
-- 기능: PRIVATE/DUO 수업을 특정 담당 학생에게만 노출해야 할 때 대상 학생을 지정합니다.
-- 대상이 없는 PRIVATE/DUO 수업은 해당 선생님의 담당 학생 전체에게 노출됩니다.
CREATE TABLE `class_targets` (
    `class_target_id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '수업 대상 매핑 고유 번호',
    `class_id` INT NOT NULL COMMENT '대상 지정할 수업 ID',
    `user_id` INT NOT NULL COMMENT '지정 학생 유저 ID',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '지정 일시',
    UNIQUE KEY `uq_class_targets_class_user` (`class_id`, `user_id`),
    FOREIGN KEY (`class_id`) REFERENCES `classes`(`class_id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_class_targets_class_id ON `class_targets`(`class_id`);
CREATE INDEX idx_class_targets_user_id ON `class_targets`(`user_id`);


-- 8. 예약 및 매칭 테이블 (reservations)
-- 기능: 학생이 특정 수업(class_id)을 듣기 위해 보유 중인 티켓(user_ticket_id)을 사용하여 매칭된 예약 내역입니다.
-- 특징: 수업 참석, 취소 등의 상태 정보(status)를 관리하며, 출석 확인 및 정산 데이터로 사용됩니다.
CREATE TABLE `reservations` (
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

CREATE INDEX idx_reservations_user_id ON `reservations`(`user_id`);
CREATE INDEX idx_reservations_class_id ON `reservations`(`class_id`);


-- 9. 수업 변경 이력 테이블 (class_change_logs)
-- 기능: 관리자/선생님이 수업 스케줄을 생성, 수정, 삭제한 기록을 저장합니다.
CREATE TABLE `class_change_logs` (
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

CREATE INDEX idx_class_change_logs_class_id ON `class_change_logs`(`class_id`);
CREATE INDEX idx_class_change_logs_changed_at ON `class_change_logs`(`changed_at`);


-- 10. 쿠폰 관리 테이블 (coupons)<나중에추가예정/아직추가안함>
-- 기능: 외부 판매(선물하기 등)로 생성된 쿠폰 번호를 관리하고, 유저가 마이페이지에서 등록할 때 사용 처리합니다.
CREATE TABLE `coupons` (
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
 
CREATE INDEX idx_coupons_code ON `coupons`(`coupon_code`);
