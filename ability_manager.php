<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_hub.php");
    exit;
}
require 'db_connect.php';
require 'ability_write.php'; // 7테이블 UPSERT 공용 로직

$msg = "";

// =============================================================
//  스킬 모듈 관리 (7테이블: 공통 + 타입별 기본/레벨)
//  - 타입(Active/Passive/Utility)에 따라 기본값·레벨 필드가 달라진다.
//  - 저장/삭제는 ability_write.php 의 공용 로직을 그대로 사용(에디터 업로드와 동일 규칙).
// =============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'save') {
        try {
            $type = isset($_POST['ability_type']) ? $_POST['ability_type'] : 'Passive';

            // av() 는 ability_write.php 에 정의된 배열 안전 접근 헬퍼(PHP 5.6 엔 ?? 없음)
            $common = [
                'ability_id'       => isset($_POST['ability_id']) ? trim($_POST['ability_id']) : '',
                'ability_type'     => $type,
                'bit_index'        => (int)av($_POST, 'bit_index', 0),
                'display_name'     => av($_POST, 'display_name', ''),
                'description'      => av($_POST, 'description', ''),
                'appear_stage'     => (int)av($_POST, 'appear_stage', 1),
                'is_basic_skill'   => (isset($_POST['is_basic_skill']) && (int)$_POST['is_basic_skill']) ? 1 : 0,
                'is_unlocked'      => (isset($_POST['is_unlocked']) && (int)$_POST['is_unlocked']) ? 1 : 0,
                'max_level'        => (int)av($_POST, 'max_level', 1),
                'cooldown_seconds' => (float)av($_POST, 'cooldown_seconds', 0),
                'stamina_cost'     => (float)av($_POST, 'stamina_cost', 0),
                'special_effect'   => av($_POST, 'special_effect', 'None'),
            ];

            // 레벨 배열 조립: level[] 과 타입별 값 배열이 인덱스로 정렬돼 들어온다.
            $levelNums = (isset($_POST['level']) && is_array($_POST['level'])) ? $_POST['level'] : [];
            $mul = av($_POST, 'skill_multiplier', []);
            $hr  = av($_POST, 'health_restore_amount', []);
            $sr  = av($_POST, 'stamina_restore_amount', []);
            $mhp = av($_POST, 'max_health_bonus', []);
            $mst = av($_POST, 'max_stamina_bonus', []);
            $dfp = av($_POST, 'defense_bonus_percent', []);
            $atp = av($_POST, 'attack_damage_bonus_percent', []);
            $levels = [];
            foreach ($levelNums as $i => $lv) {
                $row = ['level' => (int)$lv];
                if ($type === 'Active') {
                    $row['skill_multiplier'] = (float)av($mul, $i, 1);
                } else if ($type === 'Utility') {
                    $row['health_restore_amount']  = (float)av($hr, $i, 0);
                    $row['stamina_restore_amount'] = (float)av($sr, $i, 0);
                } else { // Passive
                    $row['max_health_bonus']            = (float)av($mhp, $i, 0);
                    $row['max_stamina_bonus']           = (float)av($mst, $i, 0);
                    $row['defense_bonus_percent']       = (float)av($dfp, $i, 0);
                    $row['attack_damage_bonus_percent'] = (float)av($atp, $i, 0);
                }
                $levels[] = $row;
            }

            $err = ability_validate($common);
            if ($err !== '') {
                $msg = "<span style='color:red;'>[오류] $err</span>";
            } else {
                ability_save($pdo, $common, $levels);
                $msg = "<span style='color:green;'>[알림] 스킬이 성공적으로 저장/수정되었습니다.</span>";
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $msg = "<span style='color:red;'>[오류] 저장 실패: " . htmlspecialchars($e->getMessage()) . "</span>";
        }
    } elseif ($_POST['action'] === 'delete') {
        try {
            ability_delete($pdo, av($_POST, 'del_id', ''));
            $msg = "<span style='color:red;'>[알림] 스킬이 삭제되었습니다.</span>";
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $msg = "<span style='color:red;'>[오류] 삭제 실패: " . htmlspecialchars($e->getMessage()) . "</span>";
        }
    }
}

// ---- 전체 스킬 로드 (공통 + 타입 기본값 + 레벨) → 표 + JS 편집용 ----
function fetchOneRow($pdo, $sql, $args) {
    $s = $pdo->prepare($sql);
    $s->execute($args);
    return $s->fetch(PDO::FETCH_ASSOC);
}

// 수치 표시: 불필요한 소수점 0 을 다듬어 한눈에 보기 좋게 (예: 8.00 → 8, 1.20 → 1.2)
function fmtNum($v) {
    if (!is_numeric($v)) return htmlspecialchars((string)$v);
    $s = rtrim(rtrim(sprintf('%.4f', (float)$v), '0'), '.');
    return $s === '' ? '0' : $s;
}

$abilities = [];
try {
    $rows = $pdo->query("SELECT * FROM abilities ORDER BY bit_index ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $a) {
        $id = $a['ability_id'];
        $type = $a['ability_type'];
        $ab = [
            'ability_id'       => (string)$id,
            'ability_type'     => (string)$type,
            'bit_index'        => (int)$a['bit_index'],
            'display_name'     => (string)$a['display_name'],
            'description'      => isset($a['description']) ? (string)$a['description'] : '',
            'appear_stage'     => (int)$a['appear_stage'],
            'is_basic_skill'   => (int)$a['is_basic_skill'],
            'is_unlocked'      => (int)$a['is_unlocked'],
            'max_level'        => (int)$a['max_level'],
            'cooldown_seconds' => 0.0,
            'stamina_cost'     => 0.0,
            'special_effect'   => 'None',
            'levels'           => [],
        ];
        if ($type === 'Active') {
            $b = fetchOneRow($pdo, "SELECT cooldown_seconds, stamina_cost FROM active_abilities WHERE ability_id=?", [$id]);
            if ($b) { $ab['cooldown_seconds'] = (float)$b['cooldown_seconds']; $ab['stamina_cost'] = (float)$b['stamina_cost']; }
            $lv = $pdo->prepare("SELECT level, skill_multiplier FROM active_ability_levels WHERE ability_id=? ORDER BY level");
            $lv->execute([$id]);
            foreach ($lv->fetchAll(PDO::FETCH_ASSOC) as $l) {
                $ab['levels'][] = ['level' => (int)$l['level'], 'skill_multiplier' => (float)$l['skill_multiplier']];
            }
        } else if ($type === 'Utility') {
            $b = fetchOneRow($pdo, "SELECT cooldown_seconds, stamina_cost, special_effect FROM utility_abilities WHERE ability_id=?", [$id]);
            if ($b) { $ab['cooldown_seconds'] = (float)$b['cooldown_seconds']; $ab['stamina_cost'] = (float)$b['stamina_cost']; $ab['special_effect'] = (string)$b['special_effect']; }
            $lv = $pdo->prepare("SELECT level, health_restore_amount, stamina_restore_amount FROM utility_ability_levels WHERE ability_id=? ORDER BY level");
            $lv->execute([$id]);
            foreach ($lv->fetchAll(PDO::FETCH_ASSOC) as $l) {
                $ab['levels'][] = ['level' => (int)$l['level'], 'health_restore_amount' => (float)$l['health_restore_amount'], 'stamina_restore_amount' => (float)$l['stamina_restore_amount']];
            }
        } else { // Passive
            $lv = $pdo->prepare("SELECT level, max_health_bonus, max_stamina_bonus, defense_bonus_percent, attack_damage_bonus_percent FROM passive_ability_levels WHERE ability_id=? ORDER BY level");
            $lv->execute([$id]);
            foreach ($lv->fetchAll(PDO::FETCH_ASSOC) as $l) {
                $ab['levels'][] = [
                    'level' => (int)$l['level'],
                    'max_health_bonus' => (float)$l['max_health_bonus'],
                    'max_stamina_bonus' => (float)$l['max_stamina_bonus'],
                    'defense_bonus_percent' => (float)$l['defense_bonus_percent'],
                    'attack_damage_bonus_percent' => (float)$l['attack_damage_bonus_percent'],
                ];
            }
        }
        $abilities[] = $ab;
    }
} catch (PDOException $e) {
    $msg .= "<span style='color:red;'>[오류] 목록 로드 실패: " . htmlspecialchars($e->getMessage()) . " (마이그레이션 migration_skill_tables_v2.sql 실행 여부 확인)</span>";
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>스킬 모듈 관리</title>
    <style>
        body { background-color: #1e1e1e; color: #ddd; font-family: sans-serif; padding: 20px; }
        .header { display: flex; justify-content: space-between; border-bottom: 1px solid #444; padding-bottom: 10px; margin-bottom: 20px;}
        a { color: #ffcc00; text-decoration: none; }
        .form-panel { background-color: #2d2d2d; padding: 20px; border-radius: 8px; border: 1px solid #555; margin-bottom: 20px; }
        .form-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
        .form-grid label { font-size: 12px; color: #aaa; }
        .form-grid input, .form-grid select { width: 100%; padding: 6px; box-sizing: border-box; background-color: #111; color:#fff; border: 1px solid #444; }
        .form-grid .full-width { grid-column: span 4; }
        .hint { color:#888; font-size:11px; }
        button { padding: 8px 16px; background-color: #00ff00; border: none; font-weight: bold; cursor: pointer; border-radius: 4px; margin-top:10px; color: black; }
        button.del-btn { background-color: #ff4444; color: white; padding: 4px 8px; margin-top: 0; }
        button.edit-btn { background-color: #0088ff; color: white; padding: 4px 8px; margin-top: 0; }
        button.reset-btn { background-color: #777; color: white; margin-left: 10px; }
        .type-Active  { color:#ff8866; } .type-Passive { color:#66ccff; } .type-Utility { color:#aaff66; }

        /* 편집 폼 안의 레벨 입력 */
        #levelsPanel { margin-top: 15px; background:#222; border:1px solid #444; border-radius:6px; padding:12px; }
        #levelsPanel h4 { margin: 0 0 10px 0; color:#ffcc00; }
        .lvl-row { display:flex; gap:8px; align-items:center; margin-bottom:6px; flex-wrap:wrap; }
        .lvl-row .lvl-tag { width:60px; color:#00ff88; font-weight:bold; }
        .lvl-row label { font-size:11px; color:#aaa; margin-right:2px; }
        .lvl-row input { width:90px; padding:4px; background:#111; color:#fff; border:1px solid #444; }
        .base-field { display:none; }

        /* 목록: 타입별 카드 + 표 분리 */
        .list-controls { display:flex; justify-content:space-between; align-items:center; margin:10px 0; }
        .mini-btn { padding:5px 12px; background:#444; color:#eee; font-weight:normal; font-size:12px; margin:0 0 0 6px; }
        .mini-btn:hover { background:#666; }
        .add-btn { padding:8px 16px; background:#00cc66; color:#000; font-weight:bold; font-size:13px; margin:0; }
        .add-btn:hover { background:#00ff88; }
        button.close-btn { background:#777; color:#fff; margin-left:10px; }
        button.close-btn:hover { background:#999; }
        /* 인라인 편집 행: 폼 패널을 표 안에 끼워 넣을 때 셀 여백/배경 정리 */
        tr.editor-row > td { background:#151515 !important; padding:0 !important; border-bottom:2px solid #555; }
        tr.editor-row .form-panel { margin:0; border-radius:0; border:none; border-left:4px solid #0088ff; }
        #topEditorHost .form-panel { border-left:4px solid #00cc66; }
        .type-section { background:#242424; border:1px solid; border-left-width:5px; border-radius:8px; padding:14px 16px 18px; margin-bottom:22px; }
        .type-section h3 { margin:0 0 12px 0; font-size:18px; }
        .type-section h3 .count { font-size:13px; color:#fff; background:#444; border-radius:10px; padding:2px 10px; margin-left:8px; }
        .type-section h3 .range { font-size:12px; color:#888; margin-left:10px; font-weight:normal; }

        table.skill-table { width:100%; border-collapse:collapse; font-size:13px; }
        table.skill-table thead th { background:#3a3a3a; color:#fff; padding:8px 6px; border-bottom:2px solid #555; text-align:center; white-space:nowrap; }
        table.skill-table tbody td { padding:7px 6px; border-bottom:1px solid #3a3a3a; text-align:center; }
        table.skill-table tbody tr:not(.detail-row):hover { background:#2e2e2e; }
        table.skill-table td.mono { font-family:'Consolas',monospace; color:#9cf; text-align:left; }
        table.skill-table td.name { text-align:left; font-weight:bold; color:#fff; }
        table.skill-table td.bit { color:#ffcc00; font-weight:bold; }
        table.skill-table td.actions { white-space:nowrap; }
        .empty { color:#777; font-style:italic; }

        .lvl-toggle { margin:0; padding:4px 10px; background:#2b3a2b; color:#8fdca0; border:1px solid #3d5a3d; font-size:12px; font-weight:bold; }
        .lvl-toggle:hover { background:#365036; }
        .lvl-toggle.open { background:#4a7a4a; color:#fff; }
        .lvl-toggle .arrow { font-size:10px; }

        .detail-row td { background:#1a1a1a !important; padding:4px 10px 10px !important; }
        table.lvl-table { width:auto; min-width:340px; margin:6px auto; border-collapse:collapse; font-size:12px; background:#222; border-radius:6px; overflow:hidden; }
        table.lvl-table th { background:#333; color:#ffcc00; padding:6px 14px; border:1px solid #3a3a3a; }
        table.lvl-table td { padding:5px 14px; border:1px solid #3a3a3a; text-align:center; color:#ddd; }
        table.lvl-table td.lv { color:#00ff88; font-weight:bold; background:#262626; }
    </style>
</head>
<body>
    <div class="header">
        <h2>⚔️ 스킬 모듈 관리 (Ability Manager · 7테이블)</h2>
        <div><?php echo $msg; ?> <a href="admin_hub.php" style="margin-left:20px;">[허브로 돌아가기]</a></div>
    </div>

    <div class="form-panel" id="editorPanel" style="display:none;">
        <h3 id="form-title" style="margin-top:0; color:#00ff00;">새 스킬 추가</h3>
        <p class="hint" style="margin:0 0 10px 0;">
            ※ 연출값(아이콘/애니메이션/VFX/사운드/히트박스)은 유니티 SO 모듈이 전담합니다.
            서버는 기획/밸런스 수치만 관리하며, 클라 연결점은 <b>ability_id</b> 와 <b>bit_index</b> 입니다.<br>
            ※ bit_index 범위 — <span class="type-Active">Active 1~19</span> ·
            <span class="type-Passive">Passive 20~39</span> ·
            <span class="type-Utility">Utility 40~60</span>
        </p>
        <form method="post" id="abilityForm">
            <input type="hidden" name="action" value="save">
            <div class="form-grid">
                <div><label>Ability ID (PK)</label><input type="text" name="ability_id" id="ability_id" required></div>
                <div><label>타입</label>
                    <select name="ability_type" id="ability_type" onchange="onTypeOrLevelChange()">
                        <option value="Active">Active</option>
                        <option value="Passive">Passive</option>
                        <option value="Utility">Utility</option>
                    </select>
                </div>
                <div><label>비트 인덱스 <span class="hint" id="bitHint">(1~19)</span></label><input type="number" name="bit_index" id="bit_index" min="1" max="60" required></div>
                <div><label>표시 이름</label><input type="text" name="display_name" id="display_name" required></div>

                <div><label>등장 스테이지</label><input type="number" name="appear_stage" id="appear_stage" min="1" value="1"></div>
                <div><label>최대 레벨 (max_level)</label><input type="number" name="max_level" id="max_level" min="1" value="1" onchange="onTypeOrLevelChange()"></div>
                <div><label>기본 스킬(is_basic_skill)</label>
                    <select name="is_basic_skill" id="is_basic_skill">
                        <option value="0">0 · 비트로만 해금</option>
                        <option value="1">1 · 모든 유저 기본 지급</option>
                    </select>
                </div>
                <div><label>기본 해금(is_unlocked)</label>
                    <select name="is_unlocked" id="is_unlocked">
                        <option value="0">0 · 잠김</option>
                        <option value="1">1 · 기본 해금</option>
                    </select>
                </div>

                <!-- 타입별 기본값 (Active/Utility 만 노출) -->
                <div class="base-field" data-base="cooldown"><label>쿨타임 (초)</label><input type="number" step="0.1" name="cooldown_seconds" id="cooldown_seconds" value="0"></div>
                <div class="base-field" data-base="stamina"><label>소모 스태미나</label><input type="number" step="0.1" name="stamina_cost" id="stamina_cost" value="0"></div>
                <div class="base-field" data-base="special"><label>특수효과 (Utility)</label><input type="text" name="special_effect" id="special_effect" value="None"></div>

                <div class="full-width"><label>스킬 설명 (토큰 포함 가능, 예: {hit1})</label><input type="text" name="description" id="description"></div>
            </div>

            <div id="levelsPanel">
                <h4>레벨별 수치 (1 ~ max_level)</h4>
                <div id="levelsContainer"></div>
            </div>

            <button type="submit" id="submit-btn">DB에 저장 (Save)</button>
            <button type="button" class="reset-btn" onclick="resetForm()">입력창 초기화</button>
            <button type="button" class="close-btn" onclick="closeEditor()">✕ 닫기</button>
        </form>
    </div>

    <div class="list-controls">
        <div>
            <button type="button" class="add-btn" onclick="openNew()">➕ 새 스킬 추가</button>
            <span style="color:#888; font-size:12px; margin-left:12px;">💡 각 스킬의 <b>수정</b> 버튼을 누르면 편집창이 그 밑에 펼쳐집니다.</span>
        </div>
        <div>
            <button type="button" class="mini-btn" onclick="toggleAll(true)">전체 펼치기</button>
            <button type="button" class="mini-btn" onclick="toggleAll(false)">전체 접기</button>
        </div>
    </div>

    <!-- 새 스킬 추가 시 편집 폼이 여기(목록 맨 위)로 이동해서 열린다 -->
    <div id="topEditorHost"></div>

    <?php
    // 타입별 메타: 색/아이콘/기본값 컬럼/레벨 컬럼. 표를 3개로 분리해 렌더링한다.
    $typeMeta = [
        'Active' => [
            'color' => '#ff8866', 'icon' => '⚡', 'label' => '액티브 (Active)', 'range' => '1~19',
            'baseCols' => [['쿨타임(초)', 'cooldown_seconds'], ['스태미나', 'stamina_cost']],
            'lvlCols'  => [['배율 (skill_multiplier)', 'skill_multiplier']],
        ],
        'Passive' => [
            'color' => '#66ccff', 'icon' => '🛡️', 'label' => '패시브 (Passive)', 'range' => '20~39',
            'baseCols' => [],
            'lvlCols'  => [
                ['최대체력', 'max_health_bonus'], ['최대스태미나', 'max_stamina_bonus'],
                ['방어 %', 'defense_bonus_percent'], ['공격 %', 'attack_damage_bonus_percent'],
            ],
        ],
        'Utility' => [
            'color' => '#aaff66', 'icon' => '🔧', 'label' => '유틸리티 (Utility)', 'range' => '40~60',
            'baseCols' => [['쿨타임(초)', 'cooldown_seconds'], ['스태미나', 'stamina_cost'], ['특수효과', 'special_effect']],
            'lvlCols'  => [['체력회복', 'health_restore_amount'], ['스태미나회복', 'stamina_restore_amount']],
        ],
    ];
    $byType = ['Active' => [], 'Passive' => [], 'Utility' => []];
    foreach ($abilities as $ab) { $byType[$ab['ability_type']][] = $ab; }
    ?>

    <?php foreach ($typeMeta as $type => $meta):
        $list = $byType[$type];
        // 상세(레벨) 행 병합 컬럼 수 = 공통5 + 기본값 + basic/unlock/레벨/관리(4)
        $colCount = 5 + count($meta['baseCols']) + 4;
    ?>
    <div class="type-section" style="border-color:<?php echo $meta['color']; ?>">
        <h3 style="color:<?php echo $meta['color']; ?>;">
            <?php echo $meta['icon'] . ' ' . $meta['label']; ?>
            <span class="count"><?php echo count($list); ?>개</span>
            <span class="range">bit_index <?php echo $meta['range']; ?></span>
        </h3>
        <table class="skill-table">
            <thead>
                <tr>
                    <th>비트</th><th>ID</th><th>이름</th><th>등장</th><th>maxLv</th>
                    <?php foreach ($meta['baseCols'] as $bc): ?><th><?php echo $bc[0]; ?></th><?php endforeach; ?>
                    <th>basic</th><th>unlock</th><th>레벨 수치</th><th>관리</th>
                </tr>
            </thead>
            <tbody>
            <?php if (count($list) === 0): ?>
                <tr><td colspan="<?php echo $colCount; ?>" class="empty">등록된 <?php echo $type; ?> 스킬이 없습니다.</td></tr>
            <?php else: foreach ($list as $ab): ?>
                <tr>
                    <td class="bit"><?php echo $ab['bit_index']; ?></td>
                    <td class="mono"><?php echo htmlspecialchars($ab['ability_id']); ?></td>
                    <td class="name"><?php echo htmlspecialchars($ab['display_name']); ?></td>
                    <td><?php echo $ab['appear_stage']; ?></td>
                    <td><?php echo $ab['max_level']; ?></td>
                    <?php foreach ($meta['baseCols'] as $bc): ?>
                        <td><?php echo ($bc[1] === 'special_effect') ? htmlspecialchars($ab[$bc[1]]) : fmtNum($ab[$bc[1]]); ?></td>
                    <?php endforeach; ?>
                    <td><?php echo $ab['is_basic_skill'] ? '<b style="color:#00ff88;">✔</b>' : '<span style="color:#666;">–</span>'; ?></td>
                    <td><?php echo $ab['is_unlocked'] ? '<b style="color:#00ff88;">✔</b>' : '<span style="color:#666;">–</span>'; ?></td>
                    <td>
                        <button type="button" class="lvl-toggle" onclick="toggleDetail(this)">
                            Lv 1~<?php echo $ab['max_level']; ?> <span class="arrow">▾</span>
                        </button>
                    </td>
                    <td class="actions">
                        <button type="button" class="edit-btn" onclick='editAbility(this, <?php echo json_encode($ab, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>)'>수정</button>
                        <form method="post" style="display:inline;" onsubmit="return confirm('정말 삭제하시겠습니까? (모든 타입/레벨 테이블에서 제거)');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="del_id" value="<?php echo htmlspecialchars($ab['ability_id']); ?>">
                            <button type="submit" class="del-btn">삭제</button>
                        </form>
                    </td>
                </tr>
                <tr class="detail-row" style="display:none;">
                    <td colspan="<?php echo $colCount; ?>">
                        <?php if (count($ab['levels']) === 0): ?>
                            <span class="empty">레벨 데이터가 없습니다.</span>
                        <?php else: ?>
                        <table class="lvl-table">
                            <thead>
                                <tr>
                                    <th>레벨</th>
                                    <?php foreach ($meta['lvlCols'] as $lc): ?><th><?php echo $lc[0]; ?></th><?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ab['levels'] as $l): ?>
                                <tr>
                                    <td class="lv"><?php echo 'Lv ' . $l['level']; ?></td>
                                    <?php foreach ($meta['lvlCols'] as $lc): ?>
                                        <td><?php echo isset($l[$lc[1]]) ? fmtNum($l[$lc[1]]) : '-'; ?></td>
                                    <?php endforeach; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>

    <!-- 편집 폼의 기본 보관 위치(닫으면 여기로 되돌아와 숨겨진다) -->
    <div id="editorHome" style="display:none;"></div>

    <script>
        // 타입별 레벨 필드 정의: {name, label} 목록
        const LEVEL_FIELDS = {
            Active:  [ {name:'skill_multiplier', label:'배율', def:1} ],
            Passive: [
                {name:'max_health_bonus', label:'최대체력', def:0},
                {name:'max_stamina_bonus', label:'최대스태', def:0},
                {name:'defense_bonus_percent', label:'방어%', def:0},
                {name:'attack_damage_bonus_percent', label:'공격%', def:0},
            ],
            Utility: [
                {name:'health_restore_amount', label:'체력회복', def:0},
                {name:'stamina_restore_amount', label:'스태회복', def:0},
            ],
        };

        // 타입에 따라 base 필드(쿨타임/스태미나/특수효과) 표시 토글
        function updateBaseFields(type) {
            const show = { cooldown:false, stamina:false, special:false };
            if (type === 'Active')  { show.cooldown = true; show.stamina = true; }
            if (type === 'Utility') { show.cooldown = true; show.stamina = true; show.special = true; }
            document.querySelectorAll('.base-field').forEach(el => {
                el.style.display = show[el.dataset.base] ? 'block' : 'none';
            });
            // bit_index 범위 힌트
            const ranges = { Active:'(1~19)', Passive:'(20~39)', Utility:'(40~60)' };
            document.getElementById('bitHint').innerText = ranges[type] || '';
        }

        // 레벨 행 렌더링. existing: {level: {field:value}} 맵(수정 시 값 채우기)
        function renderLevels(type, maxLevel, existing) {
            const fields = LEVEL_FIELDS[type] || [];
            const c = document.getElementById('levelsContainer');
            c.innerHTML = '';
            for (let lv = 1; lv <= maxLevel; lv++) {
                const row = document.createElement('div');
                row.className = 'lvl-row';
                let html = '<span class="lvl-tag">Lv ' + lv + '</span>';
                html += '<input type="hidden" name="level[]" value="' + lv + '">';
                fields.forEach(f => {
                    let val = f.def;
                    if (existing && existing[lv] && existing[lv][f.name] !== undefined) val = existing[lv][f.name];
                    html += '<label>' + f.label + '</label>';
                    html += '<input type="number" step="0.01" name="' + f.name + '[]" value="' + val + '">';
                });
                row.innerHTML = html;
                c.appendChild(row);
            }
        }

        let currentExisting = null; // 수정 중인 스킬의 레벨값 보존

        function onTypeOrLevelChange() {
            const type = document.getElementById('ability_type').value;
            let maxLevel = parseInt(document.getElementById('max_level').value, 10);
            if (isNaN(maxLevel) || maxLevel < 1) maxLevel = 1;
            updateBaseFields(type);
            renderLevels(type, maxLevel, currentExisting);
        }

        // 편집 폼(#editorPanel)을 지정한 위치로 이동시켜 연다.
        //  - mode 'edit'  : referenceTr 바로 아래(해당 스킬 밑)에 인라인으로 끼워 넣는다.
        //  - mode 'new'   : 목록 맨 위(#topEditorHost)에 연다.
        function showEditorAt(mode, referenceTr) {
            const panel = document.getElementById('editorPanel');
            // 이전에 열려있던 인라인 편집 행 정리
            document.querySelectorAll('tr.editor-row').forEach(tr => tr.remove());

            if (mode === 'edit' && referenceTr) {
                const tr = document.createElement('tr');
                tr.className = 'editor-row';
                const td = document.createElement('td');
                td.colSpan = 30; // 표 너비보다 크게 잡아 전체 폭 병합
                td.appendChild(panel);
                tr.appendChild(td);
                referenceTr.parentNode.insertBefore(tr, referenceTr.nextSibling);
            } else { // new
                document.getElementById('topEditorHost').appendChild(panel);
            }
            panel.style.display = 'block';
        }

        // 편집 폼을 닫고 기본 보관 위치로 되돌린다.
        function closeEditor() {
            const panel = document.getElementById('editorPanel');
            panel.style.display = 'none';
            document.getElementById('editorHome').appendChild(panel);
            document.querySelectorAll('tr.editor-row').forEach(tr => tr.remove());
        }

        // 새 스킬 추가: 입력창 비우고 목록 맨 위에서 연다.
        function openNew() {
            resetFields();
            document.getElementById('form-title').innerText = "새 스킬 추가";
            document.getElementById('form-title').style.color = "#00ff00";
            document.getElementById('submit-btn').innerText = "DB에 저장 (Save)";
            showEditorAt('new');
            onTypeOrLevelChange();
            document.getElementById('editorPanel').scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        function editAbility(btn, data) {
            document.getElementById('form-title').innerText = "스킬 수정 중: " + data.display_name;
            document.getElementById('form-title').style.color = "#0088ff";
            document.getElementById('submit-btn').innerText = "수정사항 덮어쓰기 (Update)";

            document.getElementById('ability_id').value = data.ability_id;
            document.getElementById('ability_type').value = data.ability_type;
            document.getElementById('bit_index').value = data.bit_index;
            document.getElementById('display_name').value = data.display_name;
            document.getElementById('appear_stage').value = data.appear_stage;
            document.getElementById('max_level').value = data.max_level;
            document.getElementById('is_basic_skill').value = data.is_basic_skill ? "1" : "0";
            document.getElementById('is_unlocked').value = data.is_unlocked ? "1" : "0";
            document.getElementById('cooldown_seconds').value = data.cooldown_seconds;
            document.getElementById('stamina_cost').value = data.stamina_cost;
            document.getElementById('special_effect').value = data.special_effect;
            document.getElementById('description').value = data.description;

            // 레벨 값을 {level: {...}} 맵으로 변환해 보존
            currentExisting = {};
            (data.levels || []).forEach(l => { currentExisting[l.level] = l; });

            // 해당 스킬 행 바로 아래(레벨 상세행이 있으면 그 아래)에 편집창을 끼워 넣는다.
            const mainTr = btn.closest('tr');
            let ref = mainTr;
            const nx = mainTr.nextElementSibling;
            if (nx && nx.classList.contains('detail-row')) ref = nx;
            showEditorAt('edit', ref);
            onTypeOrLevelChange();

            document.getElementById('editorPanel').scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        // 폼 입력값만 초기화(위치/열림 상태는 건드리지 않음)
        function resetFields() {
            document.getElementById('abilityForm').reset();
            currentExisting = null;
        }

        // 폼 안의 '입력창 초기화' 버튼: 값만 비운다.
        function resetForm() {
            resetFields();
            onTypeOrLevelChange();
        }

        // 레벨 상세 행 토글: 버튼이 속한 행 바로 다음의 detail-row 를 펼치고 접는다.
        function toggleDetail(btn) {
            const tr = btn.closest('tr');
            const detail = tr.nextElementSibling;
            if (!detail || !detail.classList.contains('detail-row')) return;
            const open = detail.style.display !== 'none';
            detail.style.display = open ? 'none' : 'table-row';
            btn.classList.toggle('open', !open);
            const arrow = btn.querySelector('.arrow');
            if (arrow) arrow.textContent = open ? '▾' : '▴';
        }

        // 전체 펼치기/접기
        function toggleAll(open) {
            document.querySelectorAll('.detail-row').forEach(d => {
                d.style.display = open ? 'table-row' : 'none';
                const btn = d.previousElementSibling ? d.previousElementSibling.querySelector('.lvl-toggle') : null;
                if (btn) {
                    btn.classList.toggle('open', open);
                    const arrow = btn.querySelector('.arrow');
                    if (arrow) arrow.textContent = open ? '▴' : '▾';
                }
            });
        }

        // 초기 렌더: 편집 폼을 기본 보관 위치로 옮겨 숨겨둔다(목록이 상단에 먼저 보이도록).
        document.getElementById('editorHome').appendChild(document.getElementById('editorPanel'));
        onTypeOrLevelChange();
    </script>
</body>
</html>
