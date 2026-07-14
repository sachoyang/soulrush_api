-- =============================================================
--  user_data 테이블에 is_admin(디버그 권한) 컬럼 추가
--
--  - is_admin = 1 인 계정만 릴리즈 빌드의 디버그 기능(F5 보스 강제 킬 등)을 쓸 수 있다.
--  - 기본값 0(일반 유저). 아무 것도 안 하면 아무도 디버그 권한이 없다(안전).
--  - admin 지정은 수동 DB 업데이트 또는 관리자 사이트(log_viewer.php)에서:
--      UPDATE user_data SET is_admin = 1 WHERE login_id = '...';
--
--  ⚠️ 스펙 문서는 users 테이블로 표기했지만, 이 서버의 실제 유저 테이블명은 user_data 이다.
--
--  실행 방법 (예):
--    mysql -u root -p soulsusers < migration_add_is_admin.sql
-- =============================================================

USE soulsusers;

ALTER TABLE `user_data`
  ADD COLUMN `is_admin` TINYINT(1) NOT NULL DEFAULT 0;  -- 1 = admin(디버그 권한)

-- 결과 확인용
-- DESCRIBE user_data;
