-- =============================================================
--  스킬 데이터 관리 구조 개편 마이그레이션
--  (클라이언트 SO 모듈이 비주얼/사운드 에셋 전담 → 서버는 기획/밸런스 수치만 보유)
--
--  실행 방법 (예):
--    mysql -u root -p soulsusers < migration_drop_asset_columns.sql
--
--  ⚠️ 컬럼을 영구 삭제하므로 실행 전 백업 권장:
--    mysqldump -u root -p soulsusers abilities > abilities_backup.sql
-- =============================================================

USE soulsusers;

-- 🔴 시각/청각 에셋 관련 컬럼 삭제 (이제 유니티 SO 모듈이 전담)
ALTER TABLE abilities
    DROP COLUMN icon_key,
    DROP COLUMN animation_key,
    DROP COLUMN vfx_key,
    DROP COLUMN hitbox_key,
    DROP COLUMN sound_key,
    DROP COLUMN sound_volume,
    DROP COLUMN sound_delay;

-- 결과 확인용 (남은 컬럼: bit_index, ability_id, display_name, description,
--   ability_type, stamina_cost, cooldown_seconds, damage_multiplier, duration, special_effect)
-- DESCRIBE abilities;
