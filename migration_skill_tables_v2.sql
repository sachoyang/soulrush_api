-- =============================================================
--  스킬(Ability) DB 재구성 마이그레이션 (v2)
--  단일 abilities 테이블 → 공통 1 + 타입별(기본/레벨) 6 = 총 7 테이블
--
--  변경 요약
--   - 스킬을 타입 3종(Active/Passive/Utility)으로 분리하고, 각 스킬은
--     레벨별 수치(1..max_level)를 별도 레벨 테이블에 보관한다.
--   - 연출값(애니메이션/VFX/사운드/히트박스)은 DB에 저장하지 않는다(Unity SO 전담).
--   - 공통 abilities 컬럼 개편:
--       basic_skill → is_basic_skill(이름 변경),  is_unlocked / appear_stage / max_level 신설,
--       stamina_cost / cooldown_seconds / damage_multiplier / duration / special_effect 는
--       타입별 테이블로 이동(공통에서 제거).
--   - bit_index 는 타입별 범위 규칙을 가진다: Active 1~19 / Passive 20~39 / Utility 40~60.
--
--  ⚠️ 계정별 개인 해금 비트마스크(user_data.unlocked_skills)는 이 스펙과 무관(카탈로그 전용).
--     단, bit_index 재배치는 기존 저장값과 호환이 깨질 수 있으니 운영 데이터가 있으면
--     아래 백업 후 bit_index 매핑을 먼저 확정하라.
--
--  실행 방법 (예):
--    mysql -u root -p soulsusers < migration_skill_tables_v2.sql
--
--  ⚠️ 기존 abilities 테이블을 백업 테이블로 보존한 뒤 새 구조를 만든다.
--    mysqldump -u root -p soulsusers abilities > abilities_backup.sql
-- =============================================================

USE soulsusers;

-- 0) 기존 단일 abilities 테이블을 백업 테이블로 보존(있을 때만).
--    새 abilities 는 컬럼 구조가 완전히 달라 그대로 ALTER 하기보다 보존 후 신규 생성이 안전하다.
DROP TABLE IF EXISTS `abilities_legacy_backup`;
CREATE TABLE IF NOT EXISTS `abilities_legacy_backup` LIKE `abilities`;
INSERT INTO `abilities_legacy_backup` SELECT * FROM `abilities`;

-- 1) 새 구조로 재생성하기 위해 기존 abilities 제거.
DROP TABLE IF EXISTS `abilities`;

-- 1-1) 공통 카탈로그
CREATE TABLE IF NOT EXISTS `abilities` (
  `ability_id`     VARCHAR(64)  NOT NULL,                    -- PK, 스킬 고유 ID
  `ability_type`   ENUM('Active','Passive','Utility') NOT NULL,
  `bit_index`      TINYINT UNSIGNED NOT NULL,               -- 해금 비트마스크 인덱스(타입별 범위 규칙)
  `display_name`   VARCHAR(64)  NOT NULL,
  `description`    TEXT         NULL,                        -- 토큰 포함 설명문 (예: "...{hit1}배...")
  `appear_stage`   INT UNSIGNED NOT NULL DEFAULT 1,          -- 몇 스테이지부터 등장
  `is_basic_skill` TINYINT(1)   NOT NULL DEFAULT 0,          -- 기본 스킬 여부(전역 기본 지급)
  `is_unlocked`    TINYINT(1)   NOT NULL DEFAULT 0,          -- 기본 해금 여부
  `max_level`      TINYINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (`ability_id`),
  UNIQUE KEY `uq_bit_index` (`bit_index`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 1-2) Active
CREATE TABLE IF NOT EXISTS `active_abilities` (
  `ability_id`       VARCHAR(64) NOT NULL,   -- FK → abilities.ability_id
  `cooldown_seconds` FLOAT NOT NULL DEFAULT 0,
  `stamina_cost`     FLOAT NOT NULL DEFAULT 0,
  PRIMARY KEY (`ability_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `active_ability_levels` (
  `ability_id`       VARCHAR(64) NOT NULL,
  `level`            TINYINT UNSIGNED NOT NULL,
  `skill_multiplier` FLOAT NOT NULL DEFAULT 1,   -- Hit Event damageRate에 곱할 배율
  PRIMARY KEY (`ability_id`,`level`)             -- 스킬×레벨 분리
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 1-3) Passive
CREATE TABLE IF NOT EXISTS `passive_abilities` (
  `ability_id` VARCHAR(64) NOT NULL,
  PRIMARY KEY (`ability_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `passive_ability_levels` (
  `ability_id`                  VARCHAR(64) NOT NULL,
  `level`                       TINYINT UNSIGNED NOT NULL,
  `max_health_bonus`            FLOAT NOT NULL DEFAULT 0,
  `max_stamina_bonus`           FLOAT NOT NULL DEFAULT 0,
  `defense_bonus_percent`       FLOAT NOT NULL DEFAULT 0,   -- 퍼센트값(10 = 10%)
  `attack_damage_bonus_percent` FLOAT NOT NULL DEFAULT 0,   -- 퍼센트값(10 = 10%)
  PRIMARY KEY (`ability_id`,`level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 1-4) Utility
CREATE TABLE IF NOT EXISTS `utility_abilities` (
  `ability_id`       VARCHAR(64) NOT NULL,
  `cooldown_seconds` FLOAT NOT NULL DEFAULT 0,
  `stamina_cost`     FLOAT NOT NULL DEFAULT 0,
  `special_effect`   VARCHAR(64) NOT NULL DEFAULT 'None',  -- enum 이름(예: None / UnlockBasicAttackCombo)
  PRIMARY KEY (`ability_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `utility_ability_levels` (
  `ability_id`            VARCHAR(64) NOT NULL,
  `level`                 TINYINT UNSIGNED NOT NULL,
  `health_restore_amount` FLOAT NOT NULL DEFAULT 0,
  `stamina_restore_amount`FLOAT NOT NULL DEFAULT 0,
  PRIMARY KEY (`ability_id`,`level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 결과 확인용
-- SHOW TABLES LIKE '%abilit%';
-- DESCRIBE abilities;
