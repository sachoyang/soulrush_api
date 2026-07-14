<?php
header('Content-Type: application/json; charset=utf-8');
require 'db_connect.php';

// =============================================================
//  팀 랭킹 조회 (상위 limit 팀)
//  - 정렬: clear_time_seconds ASC, cleared_at ASC. rank 는 1부터 서버가 매김.
//  - members 는 players_json 을 json_decode 해 중첩 배열로 내려준다(문자열 아님).
//  - 숫자 필드는 JSON 숫자로.
// =============================================================

$limit = (int)(isset($_GET['limit']) ? $_GET['limit'] : 10);
if ($limit <= 0 || $limit > 100) $limit = 10;

try {
    $sql = "SELECT team_name, clear_time_seconds, cleared_level, total_damage, players_json,
                   DATE_FORMAT(cleared_at, '%Y-%m-%d %H:%i:%s') AS cleared_at
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
        $pj = json_decode(isset($r['players_json']) ? $r['players_json'] : '', true);
        $members = (is_array($pj) && isset($pj['members']) && is_array($pj['members'])) ? $pj['members'] : [];

        // 숫자/문자 타입 보정
        foreach ($members as &$m) {
            $m['nickname'] = (string)(isset($m['nickname']) ? $m['nickname'] : '');
            $m['damage']   = (int)(isset($m['damage']) ? $m['damage'] : 0);
        }
        unset($m);

        $data[] = [
            'rank'               => $rank++,
            'team_name'          => (string)$r['team_name'],
            'clear_time_seconds' => (int)$r['clear_time_seconds'],
            'cleared_level'      => (int)$r['cleared_level'],
            'total_damage'       => (int)$r['total_damage'],
            'members'            => $members,           // 중첩 배열
            'cleared_at'         => (string)$r['cleared_at'],
        ];
    }

    echo json_encode(['status' => 'success', 'message' => '', 'data' => $data], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode(['status' => 'fail', 'message' => 'DB 에러: ' . $e->getMessage(), 'data' => []], JSON_UNESCAPED_UNICODE);
}
?>
