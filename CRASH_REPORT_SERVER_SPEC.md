# 💥 크래시 리포트 수집 서버 구현 요청서 — for 서버팀 Claude

Unity 클라이언트에 **크래시/예외 자동 보고** 기능을 붙였습니다. 서버(PHP + MySQL)에
**리포트 수집 엔드포인트 1개**와 테이블을 추가해 주세요.

> 이 문서는 클라이언트가 **실제로 보내고/파싱하는 필드 이름과 타입을 그대로** 적은 것입니다.
> **필드 이름·타입이 1글자라도 다르면 클라가 파싱을 실패**하니 그대로 맞춰 주세요.

클라 구현체: `Assets/02. Scripts/System/CrashReporter.cs`

---

## 핵심 컨셉

- 크래시는 **3종류**로 들어옵니다. `report_type` 필드로 구분합니다.
  - `exception` — C# 예외 (NullReference 등). 게임은 살아있을 수 있음.
  - `unhandled` — Assert 실패 / 처리되지 않은 예외.
  - `native_crash` — **프로세스가 즉사한 케이스.** 클라가 다음 실행 때 "지난 세션이 정상 종료 안 됨"을
    감지해서 보고합니다. 따라서 **occurred_at은 크래시 시각이 아니라 "다음 실행 시각"** 입니다.
    실제 죽은 시각은 `log_tail`(직전 세션 로그 꼬리)에서 확인해야 합니다.

- 클라는 리포트를 **먼저 디스크에 저장**하고, 전송에 성공해야 지웁니다.
  → 서버가 죽어 있어도 유실되지 않고, **다음 실행 때 재전송**됩니다.
  → 그래서 **같은 리포트가 두 번 도착할 수 있습니다.** `client_report_id`로 막아 주세요. (아래 2번)

- 로그인 **전에도** 크래시가 날 수 있습니다.
  → `login_id` / `nickname` / `session_token`이 **빈 문자열로 올 수 있습니다.**
  → ⚠️ **세션 토큰이 비었다고 리포트를 거부하지 마세요.** 크래시 수집은 인증이 필수가 아닙니다.
     (토큰이 있으면 검증해서 `login_id`를 신뢰, 없으면 익명 리포트로 그냥 저장)

---

## 0. 기존 규격과 동일하게 (중요)

기존 PHP API(`login.php`, `update_skills.php`, `submit_ranking.php`)와 **동일한 관례**를 따릅니다.

- 위치: `soulrush_api/` 폴더 → `http://<서버IP>:8080/soulrush_api/crash_report.php`
- POST는 `application/x-www-form-urlencoded` (Unity `WWWForm`)로 들어옵니다.
- 응답은 **항상 JSON**, 최상위에 `status`와 `message` 포함.

### ⚠️ 주의사항
1. `ram_mb`는 폼 필드라 **문자열로 도착**합니다. PHP에서 `(int)` 캐스팅해서 저장하세요.
2. `stack_trace`와 `log_tail`은 **최대 30KB까지** 올 수 있습니다.
   `TEXT`(64KB)면 충분하지만, PHP `post_max_size`가 기본 8M이면 문제없습니다.
3. `message`, `stack_trace`에는 **줄바꿈과 따옴표가 그대로** 들어있습니다. Prepared statement 필수.

---

## 1. DB 테이블

```sql
CREATE TABLE IF NOT EXISTS `crash_reports` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_report_id` CHAR(32)        NOT NULL,             -- ★ 중복 방지 키 (GUID "N" 포맷, 하이픈 없음)
  `report_type`      VARCHAR(20)     NOT NULL,             -- exception / unhandled / native_crash
  `login_id`         VARCHAR(50)     NULL,                 -- 비어서 올 수 있음 (로그인 전 크래시)
  `nickname`         VARCHAR(50)     NULL,
  `message`          VARCHAR(1000)   NOT NULL,
  `stack_trace`      TEXT            NULL,
  `log_tail`         TEXT            NULL,                 -- native_crash일 때만 채워짐
  `scene`            VARCHAR(100)    NULL,                 -- 크래시 난 씬 이름
  `app_version`      VARCHAR(30)     NULL,
  `unity_version`    VARCHAR(30)     NULL,
  `platform`         VARCHAR(40)     NULL,                 -- 예: WindowsPlayer
  `device_model`     VARCHAR(200)    NULL,
  `gpu`              VARCHAR(200)    NULL,                 -- GPU 드라이버 크래시 추적용
  `ram_mb`           INT UNSIGNED    NOT NULL DEFAULT 0,
  `occurred_at`      DATETIME        NOT NULL,             -- 클라가 보낸 UTC 시각
  `received_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_report` (`client_report_id`),             -- ★ 재전송 중복 차단
  INDEX `idx_type_time` (`report_type`, `occurred_at`),
  INDEX `idx_version`   (`app_version`),
  INDEX `idx_login`     (`login_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

`utf8mb4` 필수입니다. 스택트레이스에 한글 로그와 이모지(`Debug.Log("🟢 ...")`)가 섞여 들어옵니다.

---

## 2. 엔드포인트: `crash_report.php` (POST)

### 클라가 보내는 필드 (전부 문자열로 도착)

| 필드 | 예시 | 비고 |
|---|---|---|
| `client_report_id` | `a3f1c8...` (32자) | **중복 판정 키** |
| `report_type` | `exception` | 3종 중 하나 |
| `message` | `Object reference not set...` | 최대 1000자 |
| `stack_trace` | `at PlayerCtrl.Update()...` | 최대 8KB |
| `log_tail` | `...` | native_crash만. 최대 30KB |
| `scene` | `Gothic_Stage` | |
| `app_version` | `1.0.3` | |
| `unity_version` | `2022.3.62f3` | |
| `platform` | `WindowsPlayer` | |
| `device_model` | `MS-7C95 (MSI)` | |
| `gpu` | `NVIDIA GeForce RTX 3060` | |
| `ram_mb` | `16384` | **`(int)` 캐스팅 필요** |
| `occurred_at` | `2026-07-09 14:33:02` | **UTC**, `yyyy-MM-dd HH:mm:ss` |
| `login_id` | `peace` 또는 `""` | 빈 값 허용 |
| `nickname` | `피스` 또는 `""` | 빈 값 허용 |
| `session_token` | `abc...` 또는 `""` | **빈 값이어도 거부 금지** |

### 서버가 돌려줄 응답

성공:
```json
{ "status": "success", "message": "리포트 저장됨" }
```

이미 받은 리포트(`client_report_id` 중복):
```json
{ "status": "duplicate", "message": "이미 수집된 리포트" }
```

실패:
```json
{ "status": "fail", "message": "사유" }
```

> 📌 **`duplicate`도 클라는 성공으로 처리**해서 로컬 파일을 지웁니다.
> 그러니 UNIQUE 제약 위반 시 **500을 던지지 말고 반드시 `duplicate`를 JSON으로** 돌려주세요.
> 여기서 500을 던지면 클라가 그 리포트를 **영원히 재전송**합니다.

### 처리 로직

```
1. 필수 필드(client_report_id, report_type, message, occurred_at) 확인 → 없으면 fail
2. report_type이 3종 중 하나인지 확인 → 아니면 fail
3. session_token이 비어있지 않으면 users 테이블과 대조.
   - 일치하면 그대로 저장
   - 불일치하면 login_id/nickname을 NULL로 지우고 저장 (리포트 자체는 버리지 않음)
4. INSERT. UNIQUE 위반이면 status="duplicate" 반환
5. status="success" 반환
```

---

## 3. 유량 제어 (권장)

크래시는 **한 유저가 짧은 시간에 수십 건**을 보낼 수 있습니다.
클라도 세션당 10건으로 제한하고 있지만, 서버에서도 방어해 주세요.

- 같은 IP에서 **1분에 20건 초과**면 429 대신 `{"status":"fail","message":"rate limited"}` 반환
- `crash_reports` 테이블이 무한정 커지지 않게 **30일 지난 행은 정리**하는 크론 권장

---

## 4. 조회 (선택 — 있으면 편함)

`ADMIN_SERVER_SPEC.md`의 관리자 페이지에 붙일 용도입니다. 급하지 않으면 나중에 해도 됩니다.

`GET crash_report_list.php?limit=50&type=native_crash`

```json
{
  "status": "success",
  "message": "",
  "reports": [
    {
      "id": 12,
      "report_type": "native_crash",
      "nickname": "피스",
      "message": "지난 세션이 정상 종료되지 않음 (네이티브 크래시 추정)",
      "scene": "Gothic_Stage",
      "app_version": "1.0.3",
      "gpu": "NVIDIA GeForce RTX 3060",
      "occurred_at": "2026-07-09 14:33:02"
    }
  ]
}
```

**가장 유용한 집계**는 이겁니다 — 같은 크래시가 몇 명한테 터졌는지:

```sql
SELECT report_type, scene, LEFT(message, 80) AS msg,
       COUNT(*) AS hits, COUNT(DISTINCT login_id) AS users
FROM crash_reports
WHERE occurred_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY report_type, scene, msg
ORDER BY users DESC, hits DESC;
```
