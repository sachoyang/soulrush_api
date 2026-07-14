-- =============================================================
--  abilities 테이블에 basic_skill(기본 지급 여부) 전역 플래그 추가
--
--  [개념 정리 - 절대 혼동 금지]
--   - unlocked_skills : 유저 테이블의 유저별 64비트 비트마스크
--                       ("이 유저 계정이 해금한 스킬")
--   - basic_skill     : abilities 테이블의 스킬별 전역 int 플래그
--                       ("이 스킬은 비트 없이도 모든 유저에게 기본 지급되는가")
--   두 값은 완전히 독립적으로 저장/반환하며, 최종 보상 풀은 클라/게임 로직에서
--   (basic_skill == 1) OR (유저 비트마스크에 bit_index 켜짐) 으로 계산한다.
--
--  실행 방법 (예):
--    mysql -u root -p soulsusers < migration_add_basic_skill.sql
--
--  ⚠️ 실행 전 백업 권장:
--    mysqldump -u root -p soulsusers abilities > abilities_backup.sql
-- =============================================================

USE soulsusers;

-- 기본값 0 (미지급). ability_type 뒤에 배치해 클라 규격 순서와 맞춘다.
ALTER TABLE abilities
    ADD COLUMN basic_skill TINYINT(1) NOT NULL DEFAULT 0 AFTER ability_type;

-- 결과 확인용
-- DESCRIBE abilities;
