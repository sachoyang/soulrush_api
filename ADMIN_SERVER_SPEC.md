# 🛡️ Admin(디버그 권한) 판정 서버 구현 요청서 — for 서버팀 Claude

특정 계정(admin)만 **릴리즈 빌드에서 디버그 기능(F5 보스 강제 킬 등)** 을 쓸 수 있게 하는
**admin 판정**을 서버에서 권위 있게 처리해 주세요. 지금은 클라이언트 캐시 + 클라 하드코딩 목록에
의존하고 있어, 서버가 진짜 기준(single source of truth)이 되어야 합니다.

> 관련 클라 파일: `Assets/02. Scripts/DB/BackendManager.cs`(AuthResponse.is_admin / IsAdminAccount),
> `Assets/02. Scripts/Server/DebugHotkey.cs`(게이트), `Assets/02. Scripts/Boss/BossModule/NetworkBossCore.cs`(RPC_DebugKillBoss 최종 검증)

---

## 현재 상태 (클라이언트가 기대하는 것)
- 로그인 성공 시 클라(`BackendManager`)는 응답의 **`is_admin`(0/1)** 을 읽어 `IsAdminAccount`로 캐싱합니다.
  → **그런데 login.php가 이 필드를 아직 안 내려주고 있을 수 있습니다**(안 오면 0으로 파싱되어 전원 일반 유저 취급).
- 릴리즈에서 F5 보스 킬의 최종 발동은 호스트가 `check_session.php`로 **세션 토큰이 살아있는지**만 확인하고,
  admin 여부는 **보스 프리팹에 하드코딩된 login_id 목록(`debugKillAdminIds`)** 으로 판단합니다(임시방편).

## 목표
1. **`is_admin`을 DB/서버가 관리**하고 login.php가 응답에 포함 → 클라 캐시가 정확해짐.
2. (권장) **admin까지 확인해주는 검증 엔드포인트**를 제공 → 클라의 하드코딩 목록 제거, 서버가 최종 판정.

---

## 1. DB — `users` 테이블에 admin 컬럼

이미 있으면 스킵. 없으면 추가:
```sql
ALTER TABLE `users`
  ADD COLUMN `is_admin` TINYINT(1) NOT NULL DEFAULT 0;  -- 1 = admin(디버그 권한)
```
- admin 지정은 수동 DB 업데이트(또는 관리자 사이트)로: `UPDATE users SET is_admin=1 WHERE login_id='...';`
- 기본 0(일반 유저)이라, 아무 것도 안 하면 아무도 디버그 권한이 없습니다(안전).

---

## 2. `login.php` 응답에 `is_admin` 포함 (필수)

클라 `AuthResponse`가 이미 파싱하는 필드입니다. **로그인 성공 응답에 `is_admin`(숫자 0/1)** 을 넣어주세요.

### 클라가 파싱하는 로그인 응답 (기존 + is_admin)
```json
{
  "status": "success",
  "message": "로그인 완료",
  "nickname": "peace",
  "unlocked_skills": 12345,
  "session_token": "xxxxxxxx",
  "is_admin": 1
}
```
- `is_admin`은 **JSON 숫자**로(0 또는 1). 문자열 `"1"`로 주면 JsonUtility(int) 파싱이 어긋납니다.
  → PHP에서 `(int)$row['is_admin']` 후 `json_encode(..., JSON_NUMERIC_CHECK)`.
- 이 필드만 추가하면, 릴리즈 빌드의 F5~F9 디버그 게이트가 서버 기준으로 정확히 열리고 닫힙니다.

---

## 3. ⚠️ `check_admin.php` — admin 서버 권위 검증 (**이제 필수**)

> 🔴 **클라이언트는 이미 이 엔드포인트를 호출하도록 수정되었습니다.**
> 하드코딩 목록(`debugKillAdminIds`)을 제거했기 때문에, 이 파일이 없으면
> **F5 보스 강제 킬은 릴리즈 빌드에서 영영 동작하지 않습니다** (fail-closed).
> `login.php`의 `is_admin`(2번)만으로는 F6/F7/F8만 열립니다.

F5 보스 강제 킬처럼 **네트워크로 남을 죽이는 조작**은, 호스트가 "요청자가 진짜 admin인지"를
서버에 되물어 확정하는 게 안전합니다. 지금은 세션만 확인(`check_session.php`)하고 admin은 클라 하드코딩
목록으로 보므로, 아래 엔드포인트가 있으면 그 목록을 없애고 서버가 최종 판정하게 할 수 있습니다.

### 요청 (POST form)
| 필드 | 타입 | 설명 |
|---|---|---|
| `login_id`      | string | 요청 계정 |
| `session_token` | string | 살아있는 세션 토큰 |

### 응답 (JSON)
```json
{ "status": "success", "is_admin": 1 }
```
판정 로직:
- 세션 토큰이 그 계정의 **현재 유효 세션과 일치하지 않으면** → `{"status":"invalid","is_admin":0}`
  (위조/만료/중복로그인 방지 — `check_session.php`와 동일한 세션 검사 재사용)
- 세션은 유효하지만 `is_admin=0` → `{"status":"success","is_admin":0}`
- 세션 유효 + `is_admin=1` → `{"status":"success","is_admin":1}`

### PHP 스켈레톤 (참고)
```php
<?php
header('Content-Type: application/json; charset=utf-8');
require 'db.php';

$login_id      = $_POST['login_id'] ?? '';
$session_token = $_POST['session_token'] ?? '';

$stmt = $pdo->prepare("SELECT session_token, is_admin FROM users WHERE login_id = ?");
$stmt->execute([$login_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || $row['session_token'] !== $session_token) {
    echo json_encode(['status'=>'invalid','is_admin'=>0], JSON_NUMERIC_CHECK); exit;
}
echo json_encode(['status'=>'success','is_admin'=>(int)$row['is_admin']], JSON_NUMERIC_CHECK);
```

> ⚠️ **대안**: 새 파일을 만들기 싫으면 기존 `check_session.php` 응답에 `is_admin`을 추가로 실어줘도 됩니다.
> 그 경우 클라의 세션 검사(하트비트)와 admin 검증을 한 번에 처리할 수 있습니다.
> (어느 쪽이든, 응답에서 **세션 무효는 `"invalid"` 문자열 포함** 규칙은 기존과 동일하게 유지해 주세요 —
>  하트비트 로직이 `"invalid"` 포함 여부로 강제 로그아웃을 판단합니다.)

---

## 4. 클라이언트 후속 — ✅ 완료됨

- `login.php`의 `is_admin` → `IsAdminAccount`로 반영됨. F5~F8 키 게이트가 이 값을 본다.
- `NetworkBossCore.RPC_DebugKillBoss`의 릴리즈 검증을 **`check_admin.php` 응답**으로 교체 완료.
  하드코딩 `debugKillAdminIds` 목록과 보스 프리팹의 해당 필드는 제거됨.
- 호스트는 `{"status":"success","is_admin":1}` 을 받은 경우에만 보스를 죽인다.
  응답이 없거나/`invalid`거나/`is_admin=0`이면 조용히 거부한다.

---

## 5. 요약 체크리스트 (서버팀)
- [ ] `users.is_admin` 컬럼 (없으면 추가, 기본 0)
- [ ] `login.php` 응답에 `is_admin`(숫자 0/1) 포함  ← **최소 필수**
- [ ] (권장) `check_admin.php` 또는 `check_session.php` 확장으로 세션+admin 동시 검증
- [ ] 숫자 필드는 JSON 숫자로, 세션 무효는 응답에 `"invalid"` 유지
- [ ] admin 지정은 수동/관리자툴로 `is_admin=1`
