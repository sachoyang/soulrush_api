<?php
header('Content-Type: application/json; charset=utf-8');
require 'db_connect.php';
require 'ability_write.php'; // 7테이블 UPSERT 공용 로직

// =============================================================
//  스킬 업로드 (Unity 에디터 AbilityUploadWindow → 서버)
//  한 번에 스킬 1개. ability_type 에 따라 알맞은 테이블 3곳에 UPSERT.
//   1) abilities            (공통, JSON 키 basic_skill→is_basic_skill / unlocked_skill→is_unlocked)
//   2) <type>_abilities     (타입별 기본값)
//   3) <type>_ability_levels(levels_json 파싱 → 스킬 기준 DELETE 후 재삽입)
//  검증: bit_index 타입별 범위(Active 1~19 / Passive 20~39 / Utility 40~60), level 1~max_level.
// =============================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST 요청만 허용됩니다."], JSON_UNESCAPED_UNICODE);
    exit;
}

$p = function ($key, $default = null) {
    return isset($_POST[$key]) ? $_POST[$key] : $default;
};

// 공통 필드 (JSON 키 basic_skill/unlocked_skill → is_basic_skill/is_unlocked)
$common = [
    'ability_id'       => trim((string)$p('ability_id', '')),
    'ability_type'     => (string)$p('ability_type', 'Passive'),
    'bit_index'        => (int)$p('bit_index', 0),
    'display_name'     => (string)$p('display_name', ''),
    'description'      => (string)$p('description', ''),
    'appear_stage'     => (int)$p('appear_stage', 1),
    'is_basic_skill'   => (int)$p('basic_skill', 0),
    'is_unlocked'      => (int)$p('unlocked_skill', 0),
    'max_level'        => (int)$p('max_level', 1),
    'cooldown_seconds' => (float)$p('cooldown_seconds', 0),
    'stamina_cost'     => (float)$p('stamina_cost', 0),
    'special_effect'   => (string)$p('special_effect', 'None'),
];

$err = ability_validate($common);
if ($err !== '') {
    echo json_encode(["status" => "fail", "message" => $err], JSON_UNESCAPED_UNICODE);
    exit;
}

// levels_json 파싱: {"levels":[ {...}, ... ]}
$parsed = json_decode((string)$p('levels_json', '{"levels":[]}'), true);
$levels = (is_array($parsed) && isset($parsed['levels']) && is_array($parsed['levels'])) ? $parsed['levels'] : [];

try {
    ability_save($pdo, $common, $levels);
    echo json_encode(["status" => "success", "message" => "업로드 성공: " . $common['ability_id']], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(["status" => "fail", "message" => "DB 에러: " . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
