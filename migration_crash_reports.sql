-- 크래시 리포트 수집 테이블 (CRASH_REPORT_SERVER_SPEC.md)
-- 스택트레이스에 한글/이모지가 섞여 들어오므로 utf8mb4 필수.

CREATE TABLE IF NOT EXISTS `crash_reports` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_report_id` CHAR(32)        NOT NULL,             -- 중복 방지 키 (GUID "N" 포맷)
  `report_type`      VARCHAR(20)     NOT NULL,             -- exception / unhandled / native_crash
  `login_id`         VARCHAR(50)     NULL,                 -- 로그인 전 크래시면 NULL
  `nickname`         VARCHAR(50)     NULL,
  `message`          VARCHAR(1000)   NOT NULL,
  `stack_trace`      TEXT            NULL,
  `log_tail`         TEXT            NULL,                 -- native_crash일 때만 채워짐
  `scene`            VARCHAR(100)    NULL,
  `app_version`      VARCHAR(30)     NULL,
  `unity_version`    VARCHAR(30)     NULL,
  `platform`         VARCHAR(40)     NULL,
  `device_model`     VARCHAR(200)    NULL,
  `gpu`              VARCHAR(200)    NULL,
  `ram_mb`           INT UNSIGNED    NOT NULL DEFAULT 0,
  `client_ip`        VARCHAR(45)     NULL,                 -- 유량 제어용 (IPv6 대응 45자)
  `occurred_at`      DATETIME        NOT NULL,             -- 클라가 보낸 UTC 시각
  `received_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_report` (`client_report_id`),             -- 재전송 중복 차단
  INDEX `idx_type_time` (`report_type`, `occurred_at`),
  INDEX `idx_version`   (`app_version`),
  INDEX `idx_login`     (`login_id`),
  INDEX `idx_ip_time`   (`client_ip`, `received_at`)       -- rate limit 조회용
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 30일 지난 리포트 정리 (크론/이벤트 스케줄러로 주기 실행 권장)
-- DELETE FROM crash_reports WHERE received_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
