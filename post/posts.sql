-- ========================================================
-- 1. 지원 언어 테이블 (languages)
-- ========================================================
CREATE TABLE IF NOT EXISTS `languages` (
  `lang_code` VARCHAR(5) NOT NULL COMMENT '언어 코드 (ko, ja, zh 등)',
  `lang_name` VARCHAR(20) NOT NULL COMMENT '표시 이름 (한국어, 日本語 등)',
  `is_active` TINYINT(1) DEFAULT 1 COMMENT '활성화 여부 (1:활성, 0:비활성)',
  PRIMARY KEY (`lang_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. 관리자/선생님 테이블 (users)
-- ========================================================
CREATE TABLE IF NOT EXISTS `users` (
  `user_id` INT NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(30) NOT NULL COMMENT '로그인 고유 회원번호 (사번/학번 등)',
  `password` VARCHAR(255) NOT NULL COMMENT '암호화된 비밀번호',
  `nickname` VARCHAR(30) NOT NULL COMMENT '선생님 활동명',
  `role` VARCHAR(10) DEFAULT 'teacher' COMMENT '권한 (admin, teacher)',
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `idx_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. 무한 확장 카테고리 테이블 (categories)
-- ========================================================
CREATE TABLE IF NOT EXISTS `categories` (
  `category_id` INT NOT NULL AUTO_INCREMENT,
  `parent_id` INT DEFAULT NULL COMMENT '상위 카테고리 ID (최상위는 NULL)',
  `lang_code` VARCHAR(5) NOT NULL COMMENT '어떤 언어 환경에서 노출할 것인가',
  `type` VARCHAR(20) NOT NULL COMMENT '분류 대유형 (study, travel, cooking 등)',
  `category_name` VARCHAR(50) NOT NULL COMMENT '카테고리명 (초급문법, 도쿄맛집 등)',
  `sort_order` INT DEFAULT 0 COMMENT '메뉴 순서정렬용',
  PRIMARY KEY (`category_id`),
  CONSTRAINT `fk_categories_parent` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`category_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_categories_lang` FOREIGN KEY (`lang_code`) REFERENCES `languages` (`lang_code`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. 통합 게시글 테이블 (posts)
-- ========================================================
CREATE TABLE IF NOT EXISTS `posts` (
  `post_id` INT NOT NULL AUTO_INCREMENT,
  `category_id` INT NOT NULL COMMENT '최종 소분류 카테고리 ID 참조',
  `user_id` INT DEFAULT NULL COMMENT '작성자 선생님 ID 참조',
  `title` VARCHAR(200) NOT NULL COMMENT '글 제목 또는 문법/단어 명칭',
  `summary` VARCHAR(500) DEFAULT NULL COMMENT '목록에 보일 한 줄 요약',
  `content` TEXT NOT NULL COMMENT '💡 상세 설명 본문',
  `extra_data` TEXT DEFAULT NULL COMMENT '📖 예문 및 활용팁 (필요시 JSON 포맷 저장)',
  `view_count` INT DEFAULT 0 COMMENT '조회수',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`post_id`),
  CONSTRAINT `fk_posts_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`category_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_posts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ========================================================
-- 초기 필수/샘플 데이터 삽입 (테스트용)
-- ========================================================

-- [언어 설정 추가]
INSERT INTO `languages` (`lang_code`, `lang_name`) VALUES 
('ko', '한국어'),
('ja', '日本語'),
('zh', '中文');

-- [테스트 선생님 계정 추가] 
-- 실제 서비스 시 비밀번호는 password_hash()로 암호화해야 하지만, 테스트용으로 임시 삽입
INSERT INTO `users` (`username`, `password`, `nickname`, `role`) VALUES 
('admin', '1234', '관리자선생님', 'admin');

-- [기본 카테고리 트리 구조 짜기]
-- 1) 일본어 학습 관련 구조
INSERT INTO `categories` (`category_id`, `parent_id`, `lang_code`, `type`, `category_name`, `sort_order`) VALUES 
(1, NULL, 'ko', 'study', '일본어 문법', 1), -- 중분류 격
(2, NULL, 'ko', 'study', '일본어 단어', 2);

-- 2) 일본어의 소분류 테마들 (parent_id를 위에서 만든 1, 2번으로 지정)
INSERT INTO `categories` (`parent_id`, `lang_code`, `type`, `category_name`, `sort_order`) VALUES 
(1, 'ko', 'study', '초급문법', 1), -- 문법의 소분류 탭 버튼이 됨
(1, 'ko', 'study', '반말표현', 2),
(1, 'ko', 'study', '일상생활', 3),
(2, 'ko', 'study', '가족', 1),    -- 단어의 소분류 탭 버튼이 됨
(2, 'ko', 'study', '숫자/날짜', 2);

-- 3) 🚀 대박 포인트: 확장 카테고리 (여행, 요리 게시판도 코드 변경 없이 데이터만으로 추가!)
INSERT INTO `categories` (`category_id`, `parent_id`, `lang_code`, `type`, `category_name`, `sort_order`) VALUES 
(10, NULL, 'ko', 'travel', '일본 여행 정보', 3),
(11, NULL, 'ko', 'cooking', '세계 요리 레시피', 4);

-- 여행/요리의 소분류 설정
INSERT INTO `categories` (`parent_id`, `lang_code`, `type`, `category_name`, `sort_order`) VALUES 
(10, 'ko', 'travel', '도쿄 맛집', 1),
(10, 'ko', 'travel', '교통 패스 팁', 2),
(11, 'ko', 'cooking', '일식 홈쿠킹', 1);