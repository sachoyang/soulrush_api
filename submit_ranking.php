<?php
header('Content-Type: application/json; charset=utf-8');
require 'db_connect.php';

// =============================================================
//  팀 랭킹 등록 (3인 협동 클리어 시 방장이 1번만 호출)
//  - party_size==3 만 수용, 비정상 시간 컷.
//  - players_json 은 {"members":[...]} 문자열 그대로 TEXT 저장(빈 배열 허용).
//  - 응답: { status, message, rank }.  rank = 방금 등록 팀 순위.
// =============================================================

$login_id      = isset($_POST['login_id'])      ? $_POST['login_id']      : '';
$session_token = isset($_POST['session_token']) ? $_POST['session_token'] : '';
$team_name     = isset($_POST['team_name'])     ? $_POST['team_name']     : 'Unknown';
$clear_time    = (int)(isset($_POST['clear_time_seconds']) ? $_POST['clear_time_seconds'] : 0);
$cleared_level = (int)(isset($_POST['cleared_level'])      ? $_POST['cleared_level']      : 0);
$party_size    = (int)(isset($_POST['party_size'])         ? $_POST['party_size']         : 3);
$total_damage  = (int)(isset($_POST['total_damage'])       ? $_POST['total_damage']       : 0);
$players_json  = isset($_POST['players_json']) ? $_POST['players_json'] : '{"members":[]}';

// 기본 방어: 3인 기록만, 비정상 시간 컷(0 이하 / 24h 초과)
if ($party_size !== 3 || $clear_time <= 0 || $clear_time > 86400) {
    echo json_encode(['status' => 'fail', 'message' => 'invalid record', 'rank' => 0], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 세션 검증(기존 check_session.php 와 동일 로직). 유효하지 않으면 등록 거부.
    if ($login_id !== '' && $session_token !== '') {
        $chk = $pdo->prepare("SELECT session_token FROM user_data WHERE login_id = ?");
        $chk->execute([$login_id]);
        $u = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$u || $u['session_token'] !== $session_token) {
            echo json_encode(['status' => 'invalid', 'message' => '세션이 유효하지 않습니다.', 'rank' => 0], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // players_json 이 올바른 JSON 인지 가볍게 검증(깨졌으면 빈 배열로 대체)
    if (json_decode($players_json) === null) {
        $players_json = '{"members":[]}';
    }

    $stmt = $pdo->prepare(
        "INSERT INTO team_rankings
            (login_id, team_name, clear_time_seconds, cleared_level, party_size, total_damage, players_json)
         VALUES (:lid, :tn, :t, :lv, :ps, :dmg, :pj)"
    );
    $stmt->execute([
        ':lid' => ($login_id !== '' ? $login_id : null),
        ':tn'  => $team_name,
        ':t'   => $clear_time,
        ':lv'  => $cleared_level,
        ':ps'  => $party_size,
        ':dmg' => $total_damage,
        ':pj'  => $players_json,
    ]);

    // 방금 등록한 팀 순위: 나보다 빠른(작은) 기록 수 + 1
    $r = $pdo->prepare("SELECT COUNT(*) + 1 AS r FROM team_rankings WHERE clear_time_seconds < :t");
    $r->execute([':t' => $clear_time]);
    $rank = (int)$r->fetch(PDO::FETCH_ASSOC)['r'];

    echo json_encode(
        ['status' => 'success', 'message' => '기록이 등록되었습니다.', 'rank' => $rank],
        JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK
    );

} catch (PDOException $e) {
    echo json_encode(['status' => 'fail', 'message' => 'DB 에러: ' . $e->getMessage(), 'rank' => 0], JSON_UNESCAPED_UNICODE);
}
?>
