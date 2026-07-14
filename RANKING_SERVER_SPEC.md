# 🏆 팀 랭킹(리더보드) 서버 구현 요청서 — for 서버팀 Claude

Unity 클라이언트에 **게임 클리어 팀 랭킹** 기능을 붙였습니다. 서버(PHP + MySQL)에
**기록 등록 / 조회** 엔드포인트 2개와 테이블을 추가해 주세요.

> 이 문서는 클라이언트가 **실제로 보내고/파싱하는 필드 이름과 타입을 그대로** 적은 것입니다.
> **필드 이름·타입이 1글자라도 다르면 클라가 파싱을 실패**하니 그대로 맞춰 주세요.

## 핵심 컨셉 — "팀 단위" 랭킹
- 랭킹은 **3인 협동 클리어에서만** 등록/조회합니다. (`party_size == 3`일 때만 방장이 등록)
- 랭킹 **1줄 = 한 팀의 클리어 기록**. 정렬 기준은 **팀의 전투 소요 시간(작을수록 상위)**.
- 팀 기록 안에는 **팀원 각각의 (이름, 딜량)** 이 `members` 배열로 들어갑니다.
  → 클라 표에서 팀 줄 아래에 팀원별 소줄(이름 · 딜량)이 붙습니다.
- **지금은 "소요 시간"만 실제 값**입니다. 팀원 딜량/이름은 클라 담당자가 나중에 채웁니다.
  서버는 **지금부터 members 구조를 받고/돌려줄 수 있게** 만들어 두기만 하면, 클라가 값을 채우는 순간 자동 반영됩니다.
  (즉, 지금 등록 요청의 `players_json` 은 `{"members":[]}` 빈 배열로 들어올 수 있음 — 그래도 정상 처리)

---

## 0. 기존 규격과 동일하게 (중요)

기존 PHP API(`login.php`, `update_skills.php`, `get_abilities.php`, `check_session.php`)와 **동일한 관례**를 따릅니다.

- 위치: `soulrush_api/` 폴더 (예: `http://<서버IP>:8080/soulrush_api/submit_ranking.php`)
- 등록(POST)은 `application/x-www-form-urlencoded` (Unity `WWWForm`)로 들어옵니다.
- 조회(GET)는 쿼리스트링(`?limit=10`)으로 들어옵니다.
- 응답은 **항상 JSON**, 최상위에 `status`(`"success"`/`"fail"`)와 `message` 포함.
- 인증은 기존과 동일하게 `login_id` + `session_token`을 함께 받습니다(`users.session_token` 대조).

### ⚠️ JsonUtility 필수 주의사항
Unity `JsonUtility`는 엄격합니다. 서버 JSON 만들 때:
1. **숫자 필드는 반드시 JSON 숫자로** 출력. 문자열(`"123"`)로 주면 int 파싱 실패.
   → PHP에서 `(int)` 캐스팅 후 `json_encode(..., JSON_NUMERIC_CHECK)` 권장.
2. 필드 이름은 아래 표의 **snake_case 그대로**.
3. 조회 응답의 팀원 목록은 **중첩 배열 `members`** 로 내려주세요(문자열 아님). 아래 3번 예시 참고.

---

## 1. DB 테이블

팀 기록 1행 + 팀원 상세는 **JSON 컬럼에 통째로 저장**하는 방식을 권장합니다(가장 단순).
정규화(팀원 별도 테이블)를 원하면 5번 참고.

```sql
CREATE TABLE IF NOT EXISTS `team_rankings` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `login_id`           VARCHAR(50)     NULL,                    -- 등록한 방장 계정
  `team_name`          VARCHAR(50)     NOT NULL,                -- 팀 대표 이름(지금은 방장 닉네임)
  `clear_time_seconds` INT UNSIGNED    NOT NULL,               -- ★ 정렬 기준: 팀 전투 소요 시간(초). 작을수록 상위
  `cleared_level`      INT UNSIGNED    NOT NULL DEFAULT 0,     -- 클리어한 최종 층(= maxLevel)
  `party_size`         TINYINT UNSIGNED NOT NULL DEFAULT 3,    -- 파티 인원(랭킹은 3)
  `total_damage`       INT UNSIGNED    NOT NULL DEFAULT 0,     -- 팀 총 딜량(members damage 합, 지금 0)
  `players_json`       TEXT            NULL,                    -- 팀원 상세 JSON: {"members":[{"nickname":..,"damage":..}]}
  `cleared_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_time`  (`clear_time_seconds` ASC),
  INDEX `idx_login` (`login_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

정렬 규칙: **`clear_time_seconds` 오름차순(빠를수록 1등)**, 동률이면 `cleared_at` 빠른 순.

---

## 2. 엔드포인트 A — 팀 기록 등록: `submit_ranking.php` (POST)

3인 클리어 시 **방장(호스트)이 로그인 상태일 때 1번만** 호출합니다.
(게스트/게스트클라/솔로/2인 이하는 호출하지 않음 → 중복·오염 방지. 서버는 들어온 것만 저장하면 됩니다.)

### 클라이언트가 보내는 필드 (POST form)
| 필드 | 타입 | 지금 값 | 설명 |
|---|---|---|---|
| `login_id`           | string | 로그인 ID   | 인증용(세션 검증) |
| `session_token`      | string | 세션 토큰   | 인증용(`users.session_token` 대조) |
| `team_name`          | string | 방장 닉네임  | 팀 대표 이름 |
| `clear_time_seconds` | int    | 예: `183`  | 팀 전투 소요 시간(초) |
| `cleared_level`      | int    | 예: `3`    | 클리어 최종 층 |
| `party_size`         | int    | `3`        | 파티 인원(항상 3) |
| `total_damage`       | int    | `0`        | 팀 총 딜량(지금 0, 나중에 채움) |
| `players_json`       | string | `{"members":[]}` | 팀원 상세 JSON 문자열(아래 4번). 지금은 빈 배열일 수 있음 |

`players_json` 예시(팀원 채워졌을 때):
```json
{"members":[{"nickname":"peace","damage":152000},{"nickname":"hyunbin","damage":98800},{"nickname":"sacho","damage":120400}]}
```

### 서버가 돌려줄 JSON (클라 `RankingSubmitResponse`가 파싱)
```json
{ "status": "success", "message": "기록이 등록되었습니다.", "rank": 4 }
```
- `rank`(int): 방금 등록한 팀이 **몇 등인지**. 계산 부담되면 `0`으로 줘도 됩니다(클라는 team_name으로 내 줄 하이라이트).
  - 계산법 예: `SELECT COUNT(*)+1 FROM team_rankings WHERE clear_time_seconds < :myTime`

### PHP 스켈레톤 (참고)
```php
<?php
header('Content-Type: application/json; charset=utf-8');
require 'db.php';

$login_id      = $_POST['login_id']      ?? '';
$session_token = $_POST['session_token'] ?? '';
$team_name     = $_POST['team_name']     ?? 'Unknown';
$clear_time    = (int)($_POST['clear_time_seconds'] ?? 0);
$cleared_level = (int)($_POST['cleared_level'] ?? 0);
$party_size    = (int)($_POST['party_size']    ?? 3);
$total_damage  = (int)($_POST['total_damage']  ?? 0);
$players_json  = $_POST['players_json']  ?? '{"members":[]}';

// (선택) 세션 검증 — check_session.php와 동일 로직.
// 기본 방어: 3인 기록만 받고, 비정상 시간 컷
if ($party_size !== 3 || $clear_time <= 0 || $clear_time > 86400) {
    echo json_encode(['status'=>'fail','message'=>'invalid record','rank'=>0]); exit;
}
// players_json 이 올바른 JSON인지 가볍게 검증(깨졌으면 빈 배열로 대체)
if (json_decode($players_json) === null) $players_json = '{"members":[]}';

$stmt = $pdo->prepare(
  "INSERT INTO team_rankings
     (login_id, team_name, clear_time_seconds, cleared_level, party_size, total_damage, players_json)
   VALUES (:lid,:tn,:t,:lv,:ps,:dmg,:pj)");
$stmt->execute([
  ':lid'=>($login_id!==''?$login_id:null), ':tn'=>$team_name, ':t'=>$clear_time,
  ':lv'=>$cleared_level, ':ps'=>$party_size, ':dmg'=>$total_damage, ':pj'=>$players_json,
]);

$r = $pdo->prepare("SELECT COUNT(*)+1 AS r FROM team_rankings WHERE clear_time_seconds < :t");
$r->execute([':t'=>$clear_time]);
$rank = (int)$r->fetch(PDO::FETCH_ASSOC)['r'];

echo json_encode(['status'=>'success','message'=>'기록이 등록되었습니다.','rank'=>$rank],
                 JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
```

---

## 3. 엔드포인트 B — 팀 랭킹 조회: `get_rankings.php` (GET)

### 클라이언트가 보내는 쿼리스트링
| 파라미터 | 타입 | 예 | 설명 |
|---|---|---|---|
| `limit` | int | `?limit=10` | 상위 몇 팀을 받을지. 없으면 10 |

### 서버가 돌려줄 JSON (클라 `RankingListResponse` → `RankingEntry[]` 파싱)
```json
{
  "status": "success",
  "message": "",
  "data": [
    {
      "rank": 1,
      "team_name": "peace",
      "clear_time_seconds": 152,
      "cleared_level": 3,
      "total_damage": 371200,
      "members": [
        { "nickname": "peace",   "damage": 152000 },
        { "nickname": "hyunbin", "damage": 98800  },
        { "nickname": "sacho",   "damage": 120400 }
      ],
      "cleared_at": "2026-07-08 15:03:21"
    }
  ]
}
```
- `data`는 **순위 오름차순**으로 정렬해서 주세요. `rank`는 1부터 서버가 매김.
- **`members`는 중첩 JSON 배열**로 주세요(문자열 아님!). `players_json` 컬럼(TEXT)에 저장한 걸
  `json_decode` 해서 `members` 배열만 꺼내 그대로 넣으면 됩니다.
- 지금은 `members`가 빈 배열(`[]`)이어도 됩니다 → 클라는 팀 줄만 표시.
- `cleared_at`은 문자열(표시용).

### PHP 스켈레톤 (참고)
```php
<?php
header('Content-Type: application/json; charset=utf-8');
require 'db.php';

$limit = (int)($_GET['limit'] ?? 10);
if ($limit <= 0 || $limit > 100) $limit = 10;

$sql = "SELECT team_name, clear_time_seconds, cleared_level, total_damage, players_json,
               DATE_FORMAT(cleared_at,'%Y-%m-%d %H:%i:%s') AS cleared_at
        FROM team_rankings
        ORDER BY clear_time_seconds ASC, cleared_at ASC
        LIMIT :lim";
$stmt = $pdo->prepare($sql);
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$data = [];
$rank = 1;
foreach ($rows as $r) {
    // players_json({"members":[...]}) 에서 members 배열만 꺼내 중첩으로 그대로 내보낸다
    $pj = json_decode($r['players_json'] ?? '', true);
    $members = (is_array($pj) && isset($pj['members'])) ? $pj['members'] : [];
    // 숫자 타입 보정
    foreach ($members as &$m) {
        $m['nickname'] = (string)($m['nickname'] ?? '');
        $m['damage']   = (int)($m['damage'] ?? 0);
    }
    unset($m);

    $data[] = [
        'rank'               => $rank++,
        'team_name'          => $r['team_name'],
        'clear_time_seconds' => (int)$r['clear_time_seconds'],
        'cleared_level'      => (int)$r['cleared_level'],
        'total_damage'       => (int)$r['total_damage'],
        'members'            => $members,           // 중첩 배열!
        'cleared_at'         => $r['cleared_at'],
    ];
}

echo json_encode(['status'=>'success','message'=>'','data'=>$data], JSON_UNESCAPED_UNICODE);
```

---

## 4. `players_json` 규격 (팀원 상세)

클라이언트(Unity `PartyMemberList` / `PartyMember`)와 1:1 대응하는 형태입니다.

```json
{ "members": [ { "nickname": "이름", "damage": 12345 } ] }
```
- 등록 시: 클라가 이 문자열을 `players_json` 폼필드로 보냄 → **그대로 TEXT 저장**.
- 조회 시: 저장한 걸 `json_decode` 해서 **`members` 배열만** 응답의 `members`에 중첩으로 넣음.
- 지금은 `members`가 `[]`(빈 배열)로 올 수 있음 → 저장/응답 모두 그대로 두면 됨.

---

## 5. (선택) 정규화 방식 — 팀원을 별도 테이블로

JSON 컬럼 대신 조회/집계를 SQL로 하고 싶으면:
```sql
CREATE TABLE team_ranking_members (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ranking_id BIGINT UNSIGNED NOT NULL,   -- team_rankings.id FK
  nickname   VARCHAR(50) NOT NULL,
  damage     INT UNSIGNED NOT NULL DEFAULT 0,
  INDEX idx_rid (ranking_id)
);
```
등록 시 `players_json.members`를 파싱해 이 테이블에 3행 INSERT,
조회 시 `ranking_id`로 묶어 `members` 배열을 재구성하면 됩니다(응답 형태는 3번과 동일).

향후 확장 후보 컬럼: `boss_name`, `game_mode`(솔로/협동 분리 랭킹), 계정별 최고기록만 노출 등.

---

## 6. 요약 체크리스트 (서버팀)

- [ ] `team_rankings` 테이블 생성 (1번)
- [ ] `submit_ranking.php` : POST 저장 + `rank` 반환 (2번). `party_size==3`만 수용
- [ ] `get_rankings.php` : GET 상위 `limit`팀 정렬 반환, **`members`는 중첩 배열** (3번)
- [ ] 숫자 필드는 JSON **숫자**로 출력 (`JSON_NUMERIC_CHECK` 등)
- [ ] 응답 최상위 `status` / `message` 포함
- [ ] `players_json` 은 지금 빈 배열(`{"members":[]}`)로 와도 정상 처리
- [ ] 두 파일을 기존 `soulrush_api/` 폴더에 배치

문의 대응 클라 파일:
`Assets/02. Scripts/DB/RankingManager.cs`, `RankingModels.cs`,
`Assets/02. Scripts/Ending/EndingSceneController.cs`
(딜량/이름 주입 지점: `EndingSceneController.PartyMembersProvider`)
