<?php
header('Content-Type: application/json; charset=utf-8');
require 'db_connect.php';

// =============================================================
//  스킬 카탈로그 조회 (7테이블 → 한 스킬=한 오브젝트)
//  - DB는 공통 1 + 타입별(기본/레벨) 6 = 7테이블로 나눠 저장하되,
//    응답은 스킬 1개를 한 오브젝트로 묶고 levels 를 중첩 배열로 내려준다.
//  - 클라 파싱 대상: AbilityDBResponse → List<AbilityDBData>
//  - JSON 키 규칙: DB의 is_basic_skill/is_unlocked → 응답은 basic_skill/unlocked_skill.
//  - 숫자 필드는 JSON 숫자로(JSON_NUMERIC_CHECK), 필드명은 snake_case 그대로.
// =============================================================

// 누락 행 대비용 단건 조회 헬퍼
function fetchOne($pdo, $sql, $args) {
    $s = $pdo->prepare($sql);
    $s->execute($args);
    return $s->fetch(PDO::FETCH_ASSOC);
}

try {
    $abilities = $pdo->query("SELECT * FROM abilities ORDER BY bit_index ASC")->fetchAll(PDO::FETCH_ASSOC);
    $data = [];

    foreach ($abilities as $a) {
        $id   = $a['ability_id'];
        $type = $a['ability_type'];

        $row = [
            'ability_id'     => (string)$id,
            'ability_type'   => (string)$type,
            'bit_index'      => (int)$a['bit_index'],
            'display_name'   => (string)$a['display_name'],
            'description'    => isset($a['description']) ? (string)$a['description'] : '',
            'appear_stage'   => (int)$a['appear_stage'],
            'basic_skill'    => (int)$a['is_basic_skill'],   // JSON 키는 basic_skill
            'unlocked_skill' => (int)$a['is_unlocked'],      // JSON 키는 unlocked_skill
            'max_level'      => (int)$a['max_level'],
            // 타입별 기본값(평탄화). Passive 는 셋 다 0/"" 로 남는다.
            'cooldown_seconds' => 0,
            'stamina_cost'     => 0,
            'special_effect'   => '',
            'levels'           => [],
        ];

        if ($type === 'Active') {
            $b = fetchOne($pdo, "SELECT cooldown_seconds, stamina_cost FROM active_abilities WHERE ability_id = ?", [$id]);
            if ($b) {
                $row['cooldown_seconds'] = (float)$b['cooldown_seconds'];
                $row['stamina_cost']     = (float)$b['stamina_cost'];
            }
            $lv = $pdo->prepare("SELECT level, skill_multiplier FROM active_ability_levels WHERE ability_id = ? ORDER BY level ASC");
            $lv->execute([$id]);
            foreach ($lv->fetchAll(PDO::FETCH_ASSOC) as $l) {
                $row['levels'][] = [
                    'level'            => (int)$l['level'],
                    'skill_multiplier' => (float)$l['skill_multiplier'],
                ];
            }
        } else if ($type === 'Utility') {
            $b = fetchOne($pdo, "SELECT cooldown_seconds, stamina_cost, special_effect FROM utility_abilities WHERE ability_id = ?", [$id]);
            if ($b) {
                $row['cooldown_seconds'] = (float)$b['cooldown_seconds'];
                $row['stamina_cost']     = (float)$b['stamina_cost'];
                $row['special_effect']   = (string)$b['special_effect'];
            }
            $lv = $pdo->prepare("SELECT level, health_restore_amount, stamina_restore_amount FROM utility_ability_levels WHERE ability_id = ? ORDER BY level ASC");
            $lv->execute([$id]);
            foreach ($lv->fetchAll(PDO::FETCH_ASSOC) as $l) {
                $row['levels'][] = [
                    'level'                  => (int)$l['level'],
                    'health_restore_amount'  => (float)$l['health_restore_amount'],
                    'stamina_restore_amount' => (float)$l['stamina_restore_amount'],
                ];
            }
        } else { // Passive
            $lv = $pdo->prepare("SELECT level, max_health_bonus, max_stamina_bonus, defense_bonus_percent, attack_damage_bonus_percent FROM passive_ability_levels WHERE ability_id = ? ORDER BY level ASC");
            $lv->execute([$id]);
            foreach ($lv->fetchAll(PDO::FETCH_ASSOC) as $l) {
                $row['levels'][] = [
                    'level'                       => (int)$l['level'],
                    'max_health_bonus'            => (float)$l['max_health_bonus'],
                    'max_stamina_bonus'           => (float)$l['max_stamina_bonus'],
                    'defense_bonus_percent'       => (float)$l['defense_bonus_percent'],
                    'attack_damage_bonus_percent' => (float)$l['attack_damage_bonus_percent'],
                ];
            }
        }

        $data[] = $row;
    }

    echo json_encode(['status' => 'success', 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'DB 에러: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
