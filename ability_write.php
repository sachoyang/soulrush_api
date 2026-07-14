<?php
// =============================================================
//  ability_write.php — 스킬 UPSERT 공용 로직 (7테이블)
//  upload_ability.php(에디터 업로드)와 ability_manager.php(관리자 사이트)가 공유한다.
//  타입별 기본 테이블 + 레벨 테이블을 한 트랜잭션으로 재작성한다.
// =============================================================

// 배열 안전 접근 헬퍼 (PHP 5.6 에는 ?? 연산자가 없다)
function av($a, $k, $d = 0) {
    return (is_array($a) && isset($a[$k])) ? $a[$k] : $d;
}

// bit_index 타입별 범위. [lo, hi] (양끝 포함)
function ability_bit_range($type) {
    $ranges = ['Active' => [1, 19], 'Passive' => [20, 39], 'Utility' => [40, 60]];
    return isset($ranges[$type]) ? $ranges[$type] : [1, 60];
}

// 검증. 통과하면 '' , 실패하면 사람이 읽을 오류 문자열 반환.
function ability_validate($c) {
    if (trim((string)$c['ability_id']) === '') return 'ability_id 가 비어있습니다.';
    if (!in_array($c['ability_type'], ['Active', 'Passive', 'Utility'], true)) {
        return 'ability_type 이 올바르지 않습니다: ' . $c['ability_type'];
    }
    list($lo, $hi) = ability_bit_range($c['ability_type']);
    $bit = (int)$c['bit_index'];
    if ($bit < $lo || $bit > $hi) {
        return "bit_index($bit) 가 {$c['ability_type']} 범위($lo~$hi)를 벗어났습니다.";
    }
    if ((int)$c['max_level'] < 1) return 'max_level 은 1 이상이어야 합니다.';
    return '';
}

// 실제 저장. $c: 공통 필드 배열, $levels: 레벨 배열(타입별 키만 채워짐).
// 예외는 호출측에서 잡는다(트랜잭션 롤백 포함).
function ability_save($pdo, $c, $levels) {
    $ability_id   = (string)$c['ability_id'];
    $ability_type = (string)$c['ability_type'];
    $max_level    = max(1, (int)$c['max_level']);

    $pdo->beginTransaction();

    // 1) 공통 abilities UPSERT (PK ability_id, bit_index UNIQUE)
    $stmt = $pdo->prepare(
        "INSERT INTO abilities
            (ability_id, ability_type, bit_index, display_name, description, appear_stage, is_basic_skill, is_unlocked, max_level)
         VALUES (:id, :type, :bit, :name, :desc, :stage, :basic, :unlock, :maxlv)
         ON DUPLICATE KEY UPDATE
            ability_type = VALUES(ability_type),
            bit_index    = VALUES(bit_index),
            display_name = VALUES(display_name),
            description  = VALUES(description),
            appear_stage = VALUES(appear_stage),
            is_basic_skill = VALUES(is_basic_skill),
            is_unlocked  = VALUES(is_unlocked),
            max_level    = VALUES(max_level)"
    );
    $stmt->execute([
        ':id'     => $ability_id,
        ':type'   => $ability_type,
        ':bit'    => (int)$c['bit_index'],
        ':name'   => (string)$c['display_name'],
        ':desc'   => (string)$c['description'],
        ':stage'  => (int)$c['appear_stage'],
        ':basic'  => ((int)$c['is_basic_skill']) ? 1 : 0,
        ':unlock' => ((int)$c['is_unlocked']) ? 1 : 0,
        ':maxlv'  => $max_level,
    ]);

    // 타입이 바뀌었을 수 있으므로 다른 타입 테이블의 잔여 행을 정리한다.
    ability_cleanup_other_types($pdo, $ability_id, $ability_type);

    $cooldown = (float)$c['cooldown_seconds'];
    $stamina  = (float)$c['stamina_cost'];

    if ($ability_type === 'Active') {
        $s = $pdo->prepare(
            "INSERT INTO active_abilities (ability_id, cooldown_seconds, stamina_cost)
             VALUES (:id, :cd, :st)
             ON DUPLICATE KEY UPDATE cooldown_seconds = VALUES(cooldown_seconds), stamina_cost = VALUES(stamina_cost)"
        );
        $s->execute([':id' => $ability_id, ':cd' => $cooldown, ':st' => $stamina]);

        $pdo->prepare("DELETE FROM active_ability_levels WHERE ability_id = ?")->execute([$ability_id]);
        $ins = $pdo->prepare("INSERT INTO active_ability_levels (ability_id, level, skill_multiplier) VALUES (?, ?, ?)");
        foreach ($levels as $l) {
            $lvl = (int)av($l,'level',0);
            if ($lvl < 1 || $lvl > $max_level) continue;
            $ins->execute([$ability_id, $lvl, (float)av($l,'skill_multiplier',1)]);
        }
    } else if ($ability_type === 'Utility') {
        $special = (string)av($c,'special_effect','None');
        if ($special === '') $special = 'None';
        $s = $pdo->prepare(
            "INSERT INTO utility_abilities (ability_id, cooldown_seconds, stamina_cost, special_effect)
             VALUES (:id, :cd, :st, :se)
             ON DUPLICATE KEY UPDATE cooldown_seconds = VALUES(cooldown_seconds), stamina_cost = VALUES(stamina_cost), special_effect = VALUES(special_effect)"
        );
        $s->execute([':id' => $ability_id, ':cd' => $cooldown, ':st' => $stamina, ':se' => $special]);

        $pdo->prepare("DELETE FROM utility_ability_levels WHERE ability_id = ?")->execute([$ability_id]);
        $ins = $pdo->prepare("INSERT INTO utility_ability_levels (ability_id, level, health_restore_amount, stamina_restore_amount) VALUES (?, ?, ?, ?)");
        foreach ($levels as $l) {
            $lvl = (int)av($l,'level',0);
            if ($lvl < 1 || $lvl > $max_level) continue;
            $ins->execute([$ability_id, $lvl, (float)av($l,'health_restore_amount',0), (float)av($l,'stamina_restore_amount',0)]);
        }
    } else { // Passive
        $pdo->prepare("INSERT IGNORE INTO passive_abilities (ability_id) VALUES (?)")->execute([$ability_id]);

        $pdo->prepare("DELETE FROM passive_ability_levels WHERE ability_id = ?")->execute([$ability_id]);
        $ins = $pdo->prepare(
            "INSERT INTO passive_ability_levels
                (ability_id, level, max_health_bonus, max_stamina_bonus, defense_bonus_percent, attack_damage_bonus_percent)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        foreach ($levels as $l) {
            $lvl = (int)av($l,'level',0);
            if ($lvl < 1 || $lvl > $max_level) continue;
            $ins->execute([
                $ability_id, $lvl,
                (float)av($l,'max_health_bonus',0),
                (float)av($l,'max_stamina_bonus',0),
                (float)av($l,'defense_bonus_percent',0),
                (float)av($l,'attack_damage_bonus_percent',0),
            ]);
        }
    }

    $pdo->commit();
}

// 스킬 타입이 바뀌었을 때 이전 타입 테이블의 잔여 행 삭제.
function ability_cleanup_other_types($pdo, $ability_id, $keep_type) {
    $map = [
        'Active'  => ['active_abilities', 'active_ability_levels'],
        'Passive' => ['passive_abilities', 'passive_ability_levels'],
        'Utility' => ['utility_abilities', 'utility_ability_levels'],
    ];
    foreach ($map as $type => $tables) {
        if ($type === $keep_type) continue;
        foreach ($tables as $t) {
            $pdo->prepare("DELETE FROM `$t` WHERE ability_id = ?")->execute([$ability_id]);
        }
    }
}

// 스킬 완전 삭제(공통 + 모든 타입 테이블).
function ability_delete($pdo, $ability_id) {
    $pdo->beginTransaction();
    $tables = [
        'active_ability_levels', 'active_abilities',
        'passive_ability_levels', 'passive_abilities',
        'utility_ability_levels', 'utility_abilities',
        'abilities',
    ];
    foreach ($tables as $t) {
        $pdo->prepare("DELETE FROM `$t` WHERE ability_id = ?")->execute([$ability_id]);
    }
    $pdo->commit();
}
?>
