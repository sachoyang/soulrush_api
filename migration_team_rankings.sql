-- =============================================================
--  팀 랭킹(리더보드) 테이블 추가
--
--  - 랭킹 1행 = 한 팀의 클리어 기록. 정렬 기준은 clear_time_seconds 오름차순(빠를수록 1등),
--    동률이면 cleared_at 빠른 순.
--  - 팀원 상세(이름/딜량)는 players_json(TEXT)에 {"members":[{"nickname":..,"damage":..}]}
--    형태로 통째 저장한다(가장 단순). 지금은 빈 배열({"members":[]})로 들어올 수 있음.
--
--  실행 방법 (예):
--    mysql -u root -p soulsusers < migration_team_rankings.sql
-- =============================================================

USE soulsusers;

CREATE TABLE IF NOT EXISTS `team_rankings` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `login_id`           VARCHAR(50)     NULL,                    -- 등록한 방장 계정
  `team_name`          VARCHAR(50)     NOT NULL,                -- 팀 대표 이름(지금은 방장 닉네임)
  `clear_time_seconds` INT UNSIGNED    NOT NULL,               -- 정렬 기준: 팀 전투 소요 시간(초). 작을수록 상위
  `cleared_level`      INT UNSIGNED    NOT NULL DEFAULT 0,     -- 클리어한 최종 층(= maxLevel)
  `party_size`         TINYINT UNSIGNED NOT NULL DEFAULT 3,    -- 파티 인원(랭킹은 3)
  `total_damage`       INT UNSIGNED    NOT NULL DEFAULT 0,     -- 팀 총 딜량(members damage 합, 지금 0)
  `players_json`       TEXT            NULL,                    -- 팀원 상세 JSON
  `cleared_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_time`  (`clear_time_seconds` ASC),
  INDEX `idx_login` (`login_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 결과 확인용
-- DESCRIBE team_rankings;
