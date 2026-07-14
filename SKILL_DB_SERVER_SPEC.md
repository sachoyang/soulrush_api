# ⚔️ 스킬(Ability) DB 재구성 요청서 — for 서버팀 Claude

Unity 스킬 모듈이 **타입별(Active / Passive / Utility) 분리 + 레벨별 데이터** 구조로 바뀌었습니다.
그에 맞춰 서버(PHP + MySQL)의 스킬 테이블과 `get_abilities.php` / `upload_ability.php` 를
아래 규격으로 **재구성**해 주세요.

> 이 문서는 Unity 클라이언트가 **실제로 파싱/전송하는 필드 이름·타입 그대로**입니다.
> **필드명·타입이 다르면 클라가 파싱 실패**하니 그대로 맞춰 주세요.
> (모듈 개발자가 쓴 설계 배경: `Assets/02. Scripts/DB/DBability_README.md` — 이 문서는 그걸 서버 구현 관점으로 확정한 것)

## 무엇이 바뀌나 (요약)
- 스킬이 **타입 3종**으로 나뉘고, 각 스킬은 **레벨별 수치(1..max_level)** 를 가진다.
- DB도 **공통 1 + 타입별(기본/레벨) 6 = 총 7 테이블**로 분리한다.
- **연출값(애니메이션/VFX/사운드/히트박스)은 DB에 저장하지 않는다.** Unity 로컬 에셋이 보관.
- DB가 저장하는 것: **id / 이름 / 설명 / 해금여부 / 기본스킬여부 / 등장 스테이지 / 최대레벨 / (타입별)쿨타임·스태미나·특수효과 / 레벨별 수치.**
- ⚠️ 이건 **스킬 카탈로그(모두 공통)** 다. 계정별 개인 해금 비트마스크(`users.unlocked_skills`)는
  기존 `login.php`/`update_skills.php` 그대로 유지된다(변경 없음). bit_index가 최대 60이라 기존 64비트에 그대로 들어간다.

---

## 0. 기존 규격과 동일하게
- 위치: `soulrush_api/` (예: `http://<서버IP>:8080/soulrush_api/get_abilities.php`)
- 조회(GET) / 업로드(POST `WWWForm`).
- 응답 JSON 최상위에 `status`(`"success"`/`"fail"`).
- **JsonUtility 주의**: 숫자 필드는 반드시 JSON **숫자**로 출력(`json_encode(..., JSON_NUMERIC_CHECK)`), 필드명은 아래 snake_case 그대로.

---

## 1. DB 테이블 (총 7개)

### 1-1. `abilities` (공통)
```sql
CREATE TABLE IF NOT EXISTS `abilities` (
  `ability_id`     VARCHAR(64)  NOT NULL,                    -- PK, 스킬 고유 ID
  `ability_type`   ENUM('Active','Passive','Utility') NOT NULL,
  `bit_index`      TINYINT UNSIGNED NOT NULL,               -- 해금 비트마스크 인덱스(타입별 범위 규칙)
  `display_name`   VARCHAR(64)  NOT NULL,
  `description`    TEXT         NULL,                        -- 토큰 포함 설명문 (예: "...{hit1}배...")
  `appear_stage`   INT UNSIGNED NOT NULL DEFAULT 1,          -- 몇 스테이지부터 등장
  `is_basic_skill` TINYINT(1)   NOT NULL DEFAULT 0,          -- 기본 스킬 여부
  `is_unlocked`    TINYINT(1)   NOT NULL DEFAULT 0,          -- 기본 해금 여부
  `max_level`      TINYINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (`ability_id`),
  UNIQUE KEY `uq_bit_index` (`bit_index`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**bit_index 타입별 범위** (검증 필수):

| 타입 | bit_index 범위 |
| --- | --- |
| Active | 1 ~ 19 |
| Passive | 20 ~ 39 |
| Utility | 40 ~ 60 |

> ⚠️ 이미 운영 중인 계정 `unlocked_skills` 비트마스크가 있으면, bit_index 재배치는 기존 저장값과
> 호환이 깨집니다. 마이그레이션/초기화 기준을 먼저 정하세요.

### 1-2. Active
```sql
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
  PRIMARY KEY (`ability_id`,`level`)             -- ★ PK로 스킬×레벨 분리
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 1-3. Passive
```sql
-- Passive는 레벨무관 기본값이 없지만 구조 일관성 위해 유지(없으면 생략 가능)
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
```
> Passive 레벨값은 “이번 레벨에서 더할 차이값”이 아니라 **“해당 레벨의 최종값”** 을 저장합니다.
> (Unity가 이전 레벨과의 차이를 계산해 적용)

### 1-4. Utility
```sql
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
```

---

## 2. 조회: `get_abilities.php` (GET) — 서버 → Unity

DB는 7테이블로 나눠 저장하되, **응답은 한 스킬 = 한 오브젝트로 묶어서** 내려줍니다.
클라 파싱 대상: `AbilityDBResponse → List<AbilityDBData>` (필드명 정확히 일치해야 함).

### 응답 JSON (정확한 계약)
```json
{
  "status": "success",
  "data": [
    {
      "ability_id": "jump_attack",
      "ability_type": "Active",
      "bit_index": 6,
      "display_name": "리프 어택",
      "description": "전방으로 도약하여 {hit1}배의 데미지를 준다",
      "appear_stage": 1,
      "basic_skill": 1,
      "unlocked_skill": 1,
      "max_level": 4,

      "cooldown_seconds": 8,
      "stamina_cost": 400,
      "special_effect": "",

      "levels": [
        { "level": 1, "skill_multiplier": 1.0 },
        { "level": 2, "skill_multiplier": 1.2 },
        { "level": 3, "skill_multiplier": 1.4 },
        { "level": 4, "skill_multiplier": 1.6 }
      ]
    }
  ]
}
```

### 필드 규칙
- **공통(항상 포함)**: `ability_id`, `ability_type`("Active"/"Passive"/"Utility"), `bit_index`,
  `display_name`, `description`, `appear_stage`, `basic_skill`(0/1), `unlocked_skill`(0/1), `max_level`.
  - 주의: DB 컬럼명은 `is_basic_skill`/`is_unlocked` 지만 **JSON 키는 `basic_skill`/`unlocked_skill`** 로 내보내야 합니다.
- **타입별 기본값(평탄화해서 top-level)**:
  - Active: `cooldown_seconds`, `stamina_cost` (그 외는 0/"" )
  - Utility: `cooldown_seconds`, `stamina_cost`, `special_effect`
  - Passive: 셋 다 0/"" 로 (또는 생략해도 됨 — JsonUtility가 0/빈값 처리)
- **`levels` 배열**: `active_ability_levels` / `passive_ability_levels` / `utility_ability_levels` 에서
  해당 스킬 행을 `level` 오름차순으로 넣습니다. 각 원소는 **그 타입이 쓰는 필드만** 넣으면 됩니다
  (안 넣은 필드는 클라에서 0으로 파싱됨 — 안전).
  - Active 원소: `{ "level":n, "skill_multiplier":.. }`
  - Passive 원소: `{ "level":n, "max_health_bonus":.., "max_stamina_bonus":.., "defense_bonus_percent":.., "attack_damage_bonus_percent":.. }`
  - Utility 원소: `{ "level":n, "health_restore_amount":.., "stamina_restore_amount":.. }`

### PHP 스켈레톤 (참고)
```php
<?php
header('Content-Type: application/json; charset=utf-8');
require 'db.php';

$abilities = $pdo->query("SELECT * FROM abilities")->fetchAll(PDO::FETCH_ASSOC);
$data = [];

foreach ($abilities as $a) {
    $id = $a['ability_id'];
    $type = $a['ability_type'];

    $row = [
        'ability_id'     => $id,
        'ability_type'   => $type,
        'bit_index'      => (int)$a['bit_index'],
        'display_name'   => $a['display_name'],
        'description'    => $a['description'] ?? '',
        'appear_stage'   => (int)$a['appear_stage'],
        'basic_skill'    => (int)$a['is_basic_skill'],   // JSON 키는 basic_skill
        'unlocked_skill' => (int)$a['is_unlocked'],      // JSON 키는 unlocked_skill
        'max_level'      => (int)$a['max_level'],
        'cooldown_seconds' => 0, 'stamina_cost' => 0, 'special_effect' => '',
        'levels'         => [],
    ];

    if ($type === 'Active') {
        $b = fetchOne($pdo, "SELECT cooldown_seconds,stamina_cost FROM active_abilities WHERE ability_id=?", [$id]);
        if ($b) { $row['cooldown_seconds']=(float)$b['cooldown_seconds']; $row['stamina_cost']=(float)$b['stamina_cost']; }
        $lv = $pdo->prepare("SELECT level,skill_multiplier FROM active_ability_levels WHERE ability_id=? ORDER BY level");
        $lv->execute([$id]);
        foreach ($lv as $l) $row['levels'][] = ['level'=>(int)$l['level'],'skill_multiplier'=>(float)$l['skill_multiplier']];
    }
    else if ($type === 'Utility') {
        $b = fetchOne($pdo, "SELECT cooldown_seconds,stamina_cost,special_effect FROM utility_abilities WHERE ability_id=?", [$id]);
        if ($b) { $row['cooldown_seconds']=(float)$b['cooldown_seconds']; $row['stamina_cost']=(float)$b['stamina_cost']; $row['special_effect']=$b['special_effect']; }
        $lv = $pdo->prepare("SELECT level,health_restore_amount,stamina_restore_amount FROM utility_ability_levels WHERE ability_id=? ORDER BY level");
        $lv->execute([$id]);
        foreach ($lv as $l) $row['levels'][] = ['level'=>(int)$l['level'],'health_restore_amount'=>(float)$l['health_restore_amount'],'stamina_restore_amount'=>(float)$l['stamina_restore_amount']];
    }
    else { // Passive
        $lv = $pdo->prepare("SELECT level,max_health_bonus,max_stamina_bonus,defense_bonus_percent,attack_damage_bonus_percent FROM passive_ability_levels WHERE ability_id=? ORDER BY level");
        $lv->execute([$id]);
        foreach ($lv as $l) $row['levels'][] = [
            'level'=>(int)$l['level'],
            'max_health_bonus'=>(float)$l['max_health_bonus'],
            'max_stamina_bonus'=>(float)$l['max_stamina_bonus'],
            'defense_bonus_percent'=>(float)$l['defense_bonus_percent'],
            'attack_damage_bonus_percent'=>(float)$l['attack_damage_bonus_percent'],
        ];
    }
    $data[] = $row;
}
echo json_encode(['status'=>'success','data'=>$data], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

function fetchOne($pdo,$sql,$args){ $s=$pdo->prepare($sql); $s->execute($args); return $s->fetch(PDO::FETCH_ASSOC); }
```

---

## 3. 업로드: `upload_ability.php` (POST) — Unity(에디터) → 서버

Unity `AbilityUploadWindow`(에디터 툴)가 선택한 스킬 SO를 DB로 올릴 때 보내는 폼입니다.
한 번에 스킬 1개씩 여러 번 호출합니다. **`ability_type` 에 따라 알맞은 테이블 3곳에 UPSERT** 하세요.

### 클라이언트가 보내는 필드 (POST form)
| 필드 | 타입 | 설명 |
|---|---|---|
| `bit_index`        | int    | 해금 비트 인덱스 |
| `ability_id`       | string | PK |
| `ability_type`     | string | `Active`/`Passive`/`Utility` |
| `display_name`     | string | |
| `description`      | string | 토큰 설명문 |
| `appear_stage`     | int    | 등장 스테이지 |
| `basic_skill`      | int    | 0/1 |
| `unlocked_skill`   | int    | 0/1 |
| `max_level`        | int    | |
| `cooldown_seconds` | float(문자열) | Active/Utility만 유효(그 외 0) |
| `stamina_cost`     | float(문자열) | Active/Utility만 유효(그 외 0) |
| `special_effect`   | string | Utility만 유효(그 외 "None") |
| `levels_json`      | string | 레벨 배열 JSON. 아래 형식 |

`levels_json` 형식 (타입별로 채워지는 필드만 값이 있고 나머지는 0):
```json
{"levels":[
  {"level":1,"skill_multiplier":1.0,"max_health_bonus":0,"max_stamina_bonus":0,"defense_bonus_percent":0,"attack_damage_bonus_percent":0,"health_restore_amount":0,"stamina_restore_amount":0},
  {"level":2,"skill_multiplier":1.2, ...}
]}
```

### 서버 처리 (UPSERT 순서)
1. `abilities` UPSERT (공통 9개 필드 — JSON 키 `basic_skill`→`is_basic_skill`, `unlocked_skill`→`is_unlocked` 매핑).
2. `ability_type` 에 따라:
   - Active → `active_abilities`(cooldown,stamina) UPSERT + `levels_json.levels`를 `active_ability_levels`(skill_multiplier)에 스킬 기준 재작성(기존 삭제 후 삽입 권장).
   - Passive → `passive_abilities` UPSERT + `passive_ability_levels`(max_health_bonus/max_stamina_bonus/defense_bonus_percent/attack_damage_bonus_percent).
   - Utility → `utility_abilities`(cooldown,stamina,special_effect) UPSERT + `utility_ability_levels`(health_restore_amount/stamina_restore_amount).
3. 응답: `{ "status":"success", "message":"..." }` (실패 시 `"fail"`).

> 레벨 테이블은 `ability_id` 기준으로 **DELETE 후 재삽입**하면 레벨 수가 줄었을 때도 깔끔합니다.

---

## 4. 검증 규칙 (권장)
- `ability_id` 중복 금지(PK).
- `bit_index` 중복 금지, **타입별 범위 준수**(Active 1–19 / Passive 20–39 / Utility 40–60).
- `(ability_id, level)` 중복 금지(레벨 테이블 PK).
- `level`은 1 ~ `max_level`.
- `special_effect`는 Utility에서만 사용.
- 타입에 맞는 기본/레벨 테이블 행이 존재해야 함.

---

## 5. 요약 체크리스트 (서버팀)
- [ ] 7개 테이블 생성 (1번)
- [ ] `get_abilities.php` : 스킬 1개=1오브젝트로 묶어 응답, `levels` 중첩 배열, JSON 키 `basic_skill`/`unlocked_skill` (2번)
- [ ] `upload_ability.php` : `ability_type`별 3테이블 UPSERT + `levels_json` 파싱 (3번)
- [ ] 숫자 필드 JSON **숫자** 출력, snake_case 필드명 정확히
- [ ] bit_index 타입 범위/중복 검증 (4번)
- [ ] 계정 비트마스크(`users.unlocked_skills`)는 그대로 — 이 스펙과 무관(카탈로그 전용)

문의 대응 클라 파일:
`Assets/02. Scripts/DB/AbilityManager.cs`(DTO: `AbilityDBData`/`AbilityLevelDBData`/`AbilityDBResponse`),
`Assets/02. Scripts/Player/Abilities/PlayerAbilityModule.cs`(+ ActiveAbilityModule/PassiveAbilityModule/UtilityAbilityModule `InitializeFromDB`),
`Assets/02. Scripts/DB/Editor/AbilityUploadWindow.cs`(업로드 폼/`levels_json`).
설계 배경: `Assets/02. Scripts/DB/DBability_README.md`.
