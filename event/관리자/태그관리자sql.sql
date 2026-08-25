-- 데이터베이스 및 테이블 생성 (ConoHa WING MySQL / MariaDB)
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `scan_logs`;
DROP TABLE IF EXISTS `distributions`;
DROP TABLE IF EXISTS `qr_codes`;
DROP TABLE IF EXISTS `content_translations`;
DROP TABLE IF EXISTS `contents`;
DROP TABLE IF EXISTS `stores`;
DROP TABLE IF EXISTS `regions`;
DROP TABLE IF EXISTS `campaigns`;

SET FOREIGN_KEY_CHECKS = 1;

-- 1. campaigns (캠페인)
CREATE TABLE `campaigns` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL COMMENT '캠페인명',
  `description` TEXT DEFAULT NULL COMMENT '설명',
  `start_date` DATE DEFAULT NULL COMMENT '시작일',
  `end_date` DATE DEFAULT NULL COMMENT '종료일',
  `status` ENUM('active', 'paused', 'ended') NOT NULL DEFAULT 'active' COMMENT '상태',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='캠페인 관리';

-- 2. regions (지역)
CREATE TABLE `regions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `campaign_id` INT UNSIGNED NOT NULL COMMENT '캠페인 ID',
  `name` VARCHAR(50) NOT NULL COMMENT '지역명 (공주, 향남 등)',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '활성 여부',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_regions_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='지역 관리';

-- 3. stores (매장)
CREATE TABLE `stores` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `region_id` INT UNSIGNED NOT NULL COMMENT '지역 ID',
  `name` VARCHAR(100) NOT NULL COMMENT '매장명',
  `store_type` VARCHAR(50) DEFAULT 'cafe' COMMENT '업종 (cafe, pub 등)',
  `address` VARCHAR(255) DEFAULT NULL COMMENT '주소',
  `phone` VARCHAR(30) DEFAULT NULL COMMENT '전화번호',
  `memo` TEXT DEFAULT NULL COMMENT '메모',
  `status` ENUM('active', 'closed') NOT NULL DEFAULT 'active' COMMENT '매장 상태',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_stores_region` FOREIGN KEY (`region_id`) REFERENCES `regions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='매장 정보';

-- 4. contents (콘텐츠 본체)
CREATE TABLE `contents` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category` VARCHAR(50) NOT NULL DEFAULT '일상회화' COMMENT '카테고리',
  `level` VARCHAR(20) NOT NULL DEFAULT '초급' COMMENT '난이도',
  `image_url` VARCHAR(255) DEFAULT NULL COMMENT '대표 이미지 URL',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '활성 여부',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='콘텐츠 관리';

-- 5. content_translations (콘텐츠 다국어)
CREATE TABLE `content_translations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `content_id` INT UNSIGNED NOT NULL COMMENT '콘텐츠 ID',
  `language_code` VARCHAR(10) NOT NULL COMMENT '언어 코드 (ja, ko, zh, en 등)',
  `text` VARCHAR(255) NOT NULL COMMENT '번역 텍스트',
  `pronunciation` VARCHAR(255) DEFAULT NULL COMMENT '발음/읽는 법',
  `meaning` VARCHAR(255) DEFAULT NULL COMMENT '의미 요약',
  `description` TEXT DEFAULT NULL COMMENT '상세 설명',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_content_lang` (`content_id`, `language_code`),
  CONSTRAINT `fk_translations_content` FOREIGN KEY (`content_id`) REFERENCES `contents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='콘텐츠 다국어 관리';

-- 6. qr_codes (고유 QR 토큰)
CREATE TABLE `qr_codes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token` VARCHAR(32) NOT NULL COMMENT '고유 난수 토큰 (A8K29D 등)',
  `content_id` INT UNSIGNED NOT NULL COMMENT '콘텐츠 ID',
  `campaign_id` INT UNSIGNED NOT NULL COMMENT '캠페인 ID',
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active' COMMENT '토큰 상태',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_qr_token` (`token`),
  CONSTRAINT `fk_qrcodes_content` FOREIGN KEY (`content_id`) REFERENCES `contents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_qrcodes_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='QR 고유 토큰';

-- 7. distributions (QR 배포/매장 연결 정보)
CREATE TABLE `distributions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `qr_id` INT UNSIGNED NOT NULL COMMENT 'QR ID',
  `campaign_id` INT UNSIGNED NOT NULL COMMENT '캠페인 ID',
  `region_id` INT UNSIGNED NOT NULL COMMENT '배포 지역 ID',
  `store_id` INT UNSIGNED DEFAULT NULL COMMENT '배포 매장 ID (NULL = 미배정)',
  `quantity` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '인쇄/배포 수량',
  `status` ENUM('allocated', 'unallocated', 'recalled') NOT NULL DEFAULT 'unallocated' COMMENT '배정 상태',
  `distributed_at` DATETIME DEFAULT NULL COMMENT '실제 매장 배정 일시',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_dist_qr` FOREIGN KEY (`qr_id`) REFERENCES `qr_codes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dist_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dist_region` FOREIGN KEY (`region_id`) REFERENCES `regions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dist_store` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='QR 배포 정보';

-- 8. scan_logs (스캔 로그 및 통계)
CREATE TABLE `scan_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `qr_id` INT UNSIGNED NOT NULL COMMENT 'QR ID',
  `distribution_id` INT UNSIGNED DEFAULT NULL COMMENT '배포 ID',
  `campaign_id` INT UNSIGNED NOT NULL COMMENT '캠페인 ID',
  `region_id` INT UNSIGNED NOT NULL COMMENT '지역 ID',
  `store_id` INT UNSIGNED DEFAULT NULL COMMENT '매장 ID (스캔 당시 매장)',
  `content_id` INT UNSIGNED NOT NULL COMMENT '콘텐츠 ID',
  `language_code` VARCHAR(10) DEFAULT 'ja' COMMENT '손님의 접속 언어',
  `ip_hash` VARCHAR(64) DEFAULT NULL COMMENT '익명화된 IP Hash',
  `user_agent` TEXT DEFAULT NULL COMMENT '브라우저 UA',
  `referer` TEXT DEFAULT NULL COMMENT '유입 경로',
  `scanned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '스캔 일시',
  PRIMARY KEY (`id`),
  KEY `idx_stats_region` (`campaign_id`, `region_id`),
  KEY `idx_stats_store` (`store_id`),
  KEY `idx_stats_content` (`content_id`),
  KEY `idx_scanned_at` (`scanned_at`),
  CONSTRAINT `fk_logs_qr` FOREIGN KEY (`qr_id`) REFERENCES `qr_codes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='QR 스캔 기록';

-- 初期 テスト データ (기본 데이터)
INSERT INTO `campaigns` (`id`, `name`, `description`) VALUES (1, '2026 소상공인 QR 캠페인', '지역 상권 활성화 외국어 태그 이벤트');

INSERT INTO `regions` (`id`, `campaign_id`, `name`) VALUES 
(1, 1, '공주'),
(2, 1, '청주'),
(3, 1, '향남');

INSERT INTO `stores` (`id`, `region_id`, `name`, `store_type`) VALUES 
(1, 1, '○○카페', 'cafe'),
(2, 1, '△△술집', 'pub'),
(3, 2, '××카페', 'cafe');

INSERT INTO `contents` (`id`, `category`, `level`) VALUES 
(1, '일상회화', '초급'),
(2, '일상회화', '초급'),
(3, '일상회화', '초급');

INSERT INTO `content_translations` (`content_id`, `language_code`, `text`, `pronunciation`, `meaning`) VALUES 
(1, 'ja', '何してる？', 'なにしてる？', '뭐 해?'),
(1, 'ko', '뭐 해?', NULL, '뭐 해?'),
(1, 'zh', '你在干什么？', NULL, '뭐 해?'),
(2, 'ja', 'おやすみ', 'おやすみ', '잘 자'),
(2, 'ko', '잘 자', NULL, '잘 자'),
(2, 'zh', '晚安', NULL, '잘 자'),
(3, 'ja', '会おう', 'あおう', '만나자'),
(3, 'ko', '만나자', NULL, '만나자'),
(3, 'zh', '见面吧', NULL, '만나자');

INSERT INTO `qr_codes` (`id`, `token`, `content_id`, `campaign_id`) VALUES 
(1, 'A8K29D', 1, 1),
(2, 'X82KD9', 2, 1),
(3, 'P91LM2', 3, 1);

INSERT INTO `distributions` (`id`, `qr_id`, `campaign_id`, `region_id`, `store_id`, `quantity`, `status`) VALUES 
(1, 1, 1, 1, 1, 50, 'allocated'),
(2, 1, 1, 3, NULL, 50, 'unallocated'),
(3, 2, 1, 1, 2, 30, 'allocated');        