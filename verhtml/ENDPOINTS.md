# Soul Rush API — 엔드포인트 명세 (Node 버전)

게임 클라이언트(Unity)가 이 서버를 쓸 때 참고할 문서.

> **핵심: 코드 수정은 서버 주소 한 줄뿐이다.**
> 경로(`login.php`, `get_abilities.php` …)·파라미터 이름·응답 JSON 구조를 원본 PHP와
> 동일하게 맞춰 두었으므로, 요청을 만드는 코드는 그대로 두고 base URL만 바꾸면 된다.
>
> ```csharp
> // 예전
> const string SERVER = "http://192.168.0.10/soulrush_api/";
> // 지금
> const string SERVER = "http://192.168.0.10:3000/";
> ```

---

## 목차

- [공통 규칙](#공통-규칙)
- [게임용 엔드포인트](#게임용-엔드포인트)
  - [register.php — 회원가입](#registerphp--회원가입)
  - [login.php — 로그인](#loginphp--로그인)
  - [check_session.php — 세션 하트비트](#check_sessionphp--세션-하트비트)
  - [check_admin.php — admin 권한 검증](#check_adminphp--admin-권한-검증)
  - [update_skills.php — 해금 스킬 저장](#update_skillsphp--해금-스킬-저장)
  - [get_abilities.php — 스킬 카탈로그](#get_abilitiesphp--스킬-카탈로그)
  - [get_rankings.php — 랭킹 조회](#get_rankingsphp--랭킹-조회)
  - [submit_ranking.php — 랭킹 등록](#submit_rankingphp--랭킹-등록)
  - [upload_ability.php — 스킬 업로드(에디터)](#upload_abilityphp--스킬-업로드에디터)
  - [crash_report.php — 크래시 수집](#crash_reportphp--크래시-수집)
  - [crash_report_list.php — 크래시 목록](#crash_report_listphp--크래시-목록)
- [관리자용 엔드포인트](#관리자용-엔드포인트)
- [원본 PHP와 다른 점](#원본-php와-다른-점)

---

## 공통 규칙

| 항목 | 내용 |
|---|---|
| Base URL | `http://<host>:3000/` (기본 포트 3000, `SR_PORT` 로 변경) |
| 요청 형식 | `multipart/form-data`(Unity `WWWForm`), `application/x-www-form-urlencoded`, `application/json` **모두 허용** |
| 응답 형식 | 항상 JSON (`Content-Type: application/json; charset=utf-8`) |
| HTTP 상태 | 게임용 엔드포인트는 실패해도 **200**. 성패는 반드시 `status` 필드로 판단한다 |
| 인코딩 | UTF-8 |
| CORS | 모든 오리진 허용 |

**확장자 없는 별칭**도 함께 열려 있다 — `/login` == `/login.php`.
새로 짜는 코드는 확장자 없는 쪽을 써도 되고, 기존 코드는 `.php` 그대로 두면 된다.

### `status` 값 정리

| 값 | 의미 |
|---|---|
| `success` | 정상 처리 |
| `error` / `fail` | 실패 (사유는 `message`) |
| `valid` / `invalid` | 세션 유효/무효 (`check_session` 전용) |
| `duplicate` | 이미 수집된 리포트 (`crash_report` 전용) |

> ⚠️ `invalid` 문자열은 클라 하트비트가 강제 로그아웃을 판단하는 신호다. 바꾸지 말 것.

---

## 게임용 엔드포인트

### register.php — 회원가입

`POST /register.php`

| 파라미터 | 타입 | 필수 | 설명 |
|---|---|:--:|---|
| `login_id` | string | ✔ | 로그인 ID (UNIQUE) |
| `password` | string | ✔ | 평문. 서버가 bcrypt 해시로 저장 |
| `nickname` | string | ✔ | 닉네임 (UNIQUE) |

```jsonc
// 성공
{ "status": "success", "message": "회원가입이 완료되었습니다." }
// 중복
{ "status": "error", "message": "이미 존재하는 아이디 또는 닉네임입니다." }
```

신규 유저의 `unlocked_skills` 는 항상 `0` 으로 시작한다.
"모든 유저 기본 지급" 은 `abilities.basic_skill` 전역 플래그가 전담한다.

---

### login.php — 로그인

`POST /login.php`

| 파라미터 | 타입 | 필수 |
|---|---|:--:|
| `login_id` | string | ✔ |
| `password` | string | ✔ |

```jsonc
{
  "status": "success",
  "message": "로그인 성공",
  "nickname": "용사",
  "unlocked_skills": "9223372036854775807",  // ★ 문자열! long 으로 파싱할 것
  "session_token": "6c2f3a640834fe92c8b7ea1fb834f09d",
  "is_admin": 0                              // ★ JSON 숫자 0/1
}
```

> **`unlocked_skills` 가 문자열인 이유** — 64비트 값을 JSON 숫자로 내리면 파서가
> 정밀도를 잃는다(`9223372036854775807` → `9.2233720368548e+18`).
> C# 에서는 `long.Parse(res.unlocked_skills)` 로 받을 것.

로그인 성공 시 새 `session_token` 이 발급되어 DB에 저장된다(= 기존 접속 무효화).

---

### check_session.php — 세션 하트비트

`POST /check_session.php` · 중복 접속 감지용. 주기적으로 호출한다.

| 파라미터 | 필수 |
|---|:--:|
| `login_id` | ✔ |
| `session_token` | ✔ |

```jsonc
{ "status": "valid",   "is_admin": 1 }  // 정상
{ "status": "invalid", "is_admin": 0 }  // → 강제 로그아웃 처리
{ "status": "error", "message": "데이터 부족" }
```

---

### check_admin.php — admin 권한 검증

`POST /check_admin.php`

F5 보스 강제 킬처럼 **네트워크로 남을 죽이는 조작**은, 호스트가 요청자가 진짜 admin 인지
서버에 되물어 확정한다(클라 하드코딩 목록 금지).

| 파라미터 | 필수 |
|---|:--:|
| `login_id` | ✔ |
| `session_token` | ✔ |

```jsonc
{ "status": "success", "is_admin": 1 }  // 세션 유효 + admin
{ "status": "success", "is_admin": 0 }  // 세션 유효 + 일반
{ "status": "invalid", "is_admin": 0 }  // 세션 불일치
```

---

### update_skills.php — 해금 스킬 저장

`POST /update_skills.php`

| 파라미터 | 타입 | 필수 | 설명 |
|---|---|:--:|---|
| `login_id` | string | ✔ | |
| `session_token` | string | ✔ | 불일치 시 저장 거부 |
| `unlocked_skills` | string | ✔ | 64비트 마스크의 **10진 문자열**. `"0"` 도 정상 값 |

```jsonc
{
  "status": "success",
  "message": "스킬 데이터가 성공적으로 저장되었습니다.",
  "updated_skills": "9223372036854775807"   // 무부호 64비트 문자열
}
// 세션 불일치
{ "status": "error", "message": "다른 기기에서 접속하여 연결이 끊어졌습니다." }
```

> 보내는 쪽도 `long.ToString()` 으로 문자열화해서 보낼 것. 클라는 bit 0~62 만 사용한다.

---

### get_abilities.php — 스킬 카탈로그

`GET /get_abilities.php` · 파라미터 없음. 인증 불필요.

DB는 7테이블로 쪼개 저장하지만, 응답은 **스킬 1개 = 오브젝트 1개**로 묶여 나온다.

```jsonc
{
  "status": "success",
  "data": [
    {
      "ability_id": "high_spin_attack",
      "ability_type": "Active",          // Active | Passive | Utility
      "bit_index": 1,                    // unlocked_skills 의 비트 위치
      "display_name": "풍차 돌리기",
      "description": "빠르게 {hitCount}번 돌려베어 {hit1}배의 데미지를 준다",
      "appear_stage": 1,
      "basic_skill": 1,                  // ★ DB is_basic_skill → 응답 basic_skill
      "unlocked_skill": 1,               // ★ DB is_unlocked    → 응답 unlocked_skill
      "max_level": 4,
      "cooldown_seconds": 8,             // Active/Utility 만 유효 (Passive 는 0)
      "stamina_cost": 400,
      "special_effect": "",              // Utility 만 유효
      "levels": [
        { "level": 1, "skill_multiplier": 1 },
        { "level": 3, "skill_multiplier": 2.3 }
      ]
    }
  ]
}
```

`levels[]` 의 키는 타입마다 다르다:

| 타입 | `levels[]` 키 | `bit_index` 범위 |
|---|---|---|
| `Active` | `skill_multiplier` | 1 ~ 19 |
| `Passive` | `max_health_bonus`, `max_stamina_bonus`, `defense_bonus_percent`, `attack_damage_bonus_percent` | 20 ~ 39 |
| `Utility` | `health_restore_amount`, `stamina_restore_amount` | 40 ~ 60 |

> 최종 보유 스킬 = `(basic_skill == 1) OR (유저 unlocked_skills 의 bit_index 비트)`

---

### get_rankings.php — 랭킹 조회

`GET /get_rankings.php?limit=10`

| 파라미터 | 기본값 | 설명 |
|---|---|---|
| `limit` | 10 | 1~100 밖이면 10 으로 보정 |

```jsonc
{
  "status": "success",
  "message": "",
  "data": [
    {
      "rank": 1,                       // 서버가 1부터 매김
      "team_name": "harry",
      "clear_time_seconds": 233,
      "cleared_level": 3,
      "total_damage": 152300,
      "members": [                     // 중첩 배열 (문자열 아님)
        { "nickname": "A", "damage": 50000 }
      ],
      "cleared_at": "2026-08-04 10:04:08"
    }
  ]
}
```

정렬: `clear_time_seconds` 오름차순 → 동률이면 `cleared_at` 순.

---

### submit_ranking.php — 랭킹 등록

`POST /submit_ranking.php` · **3인 협동 클리어 시 방장이 1번만** 호출.

| 파라미터 | 타입 | 기본값 | 설명 |
|---|---|---|---|
| `login_id` | string | `''` | 있으면 세션 검증 |
| `session_token` | string | `''` | 불일치 시 거부 |
| `team_name` | string | `Unknown` | |
| `clear_time_seconds` | int | 0 | **1 ~ 86400** 만 허용 |
| `cleared_level` | int | 0 | |
| `party_size` | int | 3 | **3 만 허용** |
| `total_damage` | int | 0 | |
| `players_json` | string | `{"members":[]}` | JSON 문자열. 깨지면 빈 배열로 대체 |

```jsonc
{ "status": "success", "message": "기록이 등록되었습니다.", "rank": 1 }
{ "status": "fail",    "message": "invalid record", "rank": 0 }        // 3인 아님/시간 이상
{ "status": "invalid", "message": "세션이 유효하지 않습니다.", "rank": 0 }
```

`players_json` 형식:
```json
{ "members": [ { "nickname": "A", "damage": 50000 } ] }
```

---

### upload_ability.php — 스킬 업로드(에디터)

`POST /upload_ability.php` · Unity 에디터 `AbilityUploadWindow` 전용. 한 번에 스킬 1개.

| 파라미터 | 타입 | 기본값 |
|---|---|---|
| `ability_id` | string | — (필수) |
| `ability_type` | string | `Passive` |
| `bit_index` | int | 0 |
| `display_name` | string | `''` |
| `description` | string | `''` |
| `appear_stage` | int | 1 |
| `basic_skill` | 0/1 | 0 |
| `unlocked_skill` | 0/1 | 0 |
| `max_level` | int | 1 |
| `cooldown_seconds` | float | 0 |
| `stamina_cost` | float | 0 |
| `special_effect` | string | `None` |
| `levels_json` | string | `{"levels":[]}` |

```jsonc
{ "status": "success", "message": "업로드 성공: high_spin_attack" }
{ "status": "fail", "message": "bit_index(5) 가 Utility 범위(40~60)를 벗어났습니다." }
```

`levels_json` 형식 (키는 타입별로 다름, 위 표 참고):
```json
{ "levels": [ { "level": 1, "skill_multiplier": 1.0 } ] }
```

---

### crash_report.php — 크래시 수집

`POST /crash_report.php` · **인증 불필요** (로그인 전 크래시도 익명 수집)

| 파라미터 | 타입 | 필수 | 길이 제한 |
|---|---|:--:|---|
| `client_report_id` | string | ✔ | UNIQUE — 재전송 중복 차단 키 |
| `report_type` | string | ✔ | `exception` / `unhandled` / `native_crash` |
| `message` | string | ✔ | 1000자 |
| `occurred_at` | string | ✔ | UTC `yyyy-MM-dd HH:mm:ss` 고정 |
| `login_id` | string | | 50자 |
| `nickname` | string | | 50자 |
| `session_token` | string | | 불일치해도 버리지 않고 **익명으로 강등** |
| `stack_trace` | string | | 60000 바이트 |
| `log_tail` | string | | 60000 바이트 |
| `scene` | string | | 100자 |
| `app_version` | string | | 30자 |
| `unity_version` | string | | 30자 |
| `platform` | string | | 40자 |
| `device_model` | string | | 200자 |
| `gpu` | string | | 200자 |
| `ram_mb` | int | | 음수는 0 |

```jsonc
{ "status": "success",   "message": "리포트 저장됨" }
{ "status": "duplicate", "message": "이미 수집된 리포트" }   // 재전송 중단할 것
{ "status": "fail",      "message": "필수 필드 누락" }
{ "status": "fail",      "message": "알 수 없는 report_type" }
{ "status": "fail",      "message": "occurred_at 형식 오류" }
{ "status": "fail",      "message": "rate limited" }         // 같은 IP 1분 20건 초과
```

> ⚠️ `duplicate` 는 **성공으로 취급**하고 큐에서 제거할 것.
> 실패로 보고 재시도하면 그 리포트를 영원히 재전송하게 된다.

---

### crash_report_list.php — 크래시 목록

`GET /crash_report_list.php?limit=50&type=native_crash`

| 파라미터 | 기본값 | 설명 |
|---|---|---|
| `limit` | 50 | 1~200 으로 클램프 |
| `type` | (전체) | `exception` / `unhandled` / `native_crash` |

```jsonc
{
  "status": "success",
  "message": "",
  "reports": [
    {
      "id": 22, "report_type": "exception", "nickname": null,
      "message": "NullReferenceException", "scene": "Stage3",
      "app_version": "1.0.0", "gpu": null,
      "occurred_at": "2026-08-04 01:03:30"
    }
  ]
}
```

---

## 관리자용 엔드포인트

브라우저 대시보드 전용. 게임 클라이언트는 쓸 일이 없다.

인증: `POST /api/admin/login` 으로 토큰을 받아 이후 요청에 `X-Admin-Token` 헤더로 첨부.
토큰이 없거나 만료면 **HTTP 401** + `{"status":"unauthorized"}`.

| 메서드 | 경로 | 설명 |
|---|---|---|
| POST | `/api/admin/login` | `{ pw }` → `{ token }` |
| POST | `/api/admin/logout` | 토큰 폐기 |
| GET | `/api/admin/check` | 토큰 유효성 확인 |
| GET | `/api/server-log` | `server_log.txt` 내용 |
| GET | `/api/users` | 유저 목록 + `bit_index→스킬명` 매핑 |
| POST | `/api/users/action` | `{ action: delete\|reset\|toggle_admin, target_idx, new_pw? }` |
| GET | `/api/abilities` | 전체 스킬 (관리용 키: `is_basic_skill`/`is_unlocked`) |
| POST | `/api/abilities/save` | 스킬 저장/수정 |
| POST | `/api/abilities/delete` | `{ del_id }` |
| GET | `/api/rankings` | 전체 랭킹 (관리용 전체 필드) |
| POST | `/api/rankings/delete` | `{ del_id }` |
| GET | `/api/crashes?type=` | 7일 집계 + 최근 200건 |
| POST | `/api/crashes/action` | `{ action: delete\|purge, del_id? }` |

---

## 원본 PHP와 다른 점

동작은 같지만 알아 두면 좋은 차이들.

| 항목 | 원본 PHP | Node 버전 |
|---|---|---|
| 실수 정밀도 | MySQL 텍스트 표현 그대로 (`2.3`) | 동일 (`2.3`) — FLOAT 확장 오차를 보정함 |
| 비밀번호 해시 | `$2y$` bcrypt | `$2y$` bcrypt — **양방향 호환 확인 완료** |
| 세션 토큰 | `md5(uniqid())` 32자 hex | 암호학적 난수 32자 hex (길이 동일) |
| 서버 로그 | `server_log.txt` | **같은 파일**에 이어서 기록 |
| 에러 응답 | PHP 경고가 JSON에 섞일 수 있음 | 항상 순수 JSON |
| 관리자 인증 | PHP 세션 쿠키 | 토큰 헤더 (`X-Admin-Token`) |
| `ability_id` 가 숫자 문자열일 때 | `JSON_NUMERIC_CHECK` 탓에 **숫자**로 나감 | 문자열 유지 (더 정확) |

두 서버가 **같은 MySQL DB를 공유**하므로 원본 PHP와 Node를 동시에 띄워 두고
클라이언트만 옮겨 가며 비교해도 된다.
