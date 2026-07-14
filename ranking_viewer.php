<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_hub.php");
    exit;
}
require 'db_connect.php';

// =============================================================
//  팀 랭킹 뷰어/관리 (team_rankings)
//  - 상위 기록을 정렬해 보여주고, 부정 기록은 개별 삭제 가능.
// =============================================================

$msg = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'delete' && isset($_POST['del_id'])) {
        try {
            $stmt = $pdo->prepare("DELETE FROM team_rankings WHERE id = ?");
            $stmt->execute([(int)$_POST['del_id']]);
            $msg = "<span style='color:#ff4444;'>[알림] 랭킹 기록(ID: " . (int)$_POST['del_id'] . ")이 삭제되었습니다.</span>";
        } catch (PDOException $e) {
            $msg = "<span style='color:red;'>삭제 에러: " . htmlspecialchars($e->getMessage()) . "</span>";
        }
    }
}

$rows = [];
try {
    $rows = $pdo->query(
        "SELECT id, login_id, team_name, clear_time_seconds, cleared_level, party_size, total_damage, players_json,
                DATE_FORMAT(cleared_at,'%Y-%m-%d %H:%i:%s') AS cleared_at
         FROM team_rankings
         ORDER BY clear_time_seconds ASC, cleared_at ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $msg .= "<span style='color:red;'>[오류] 목록 로드 실패: " . htmlspecialchars($e->getMessage()) . " (migration_team_rankings.sql 실행 여부 확인)</span>";
}

function fmt_time($sec) {
    $sec = (int)$sec;
    return sprintf('%d:%02d (%ds)', (int)($sec / 60), $sec % 60, $sec);
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>팀 랭킹 관리</title>
    <style>
        body { background-color: #1e1e1e; color: #ddd; font-family: sans-serif; padding: 20px; }
        .header { display: flex; justify-content: space-between; border-bottom: 1px solid #444; padding-bottom: 10px; margin-bottom: 20px; }
        a { color: #ffcc00; text-decoration: none; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 13px; }
        th, td { border: 1px solid #555; padding: 8px; text-align: center; vertical-align: middle; }
        th { background-color: #444; }
        tr:nth-child(even) { background-color: #2a2a2a; }
        .rank1 { color:#ffd700; font-weight:bold; } .rank2 { color:#c0c0c0; font-weight:bold; } .rank3 { color:#cd7f32; font-weight:bold; }
        .members { text-align:left; font-size:12px; color:#aad; }
        .member-chip { display:inline-block; background:#334; color:#cdf; padding:2px 8px; margin:2px; border-radius:10px; }
        button.del-btn { background-color: #ff4444; color: white; padding: 4px 8px; border:none; border-radius:4px; cursor:pointer; }
    </style>
</head>
<body>
    <div class="header">
        <h2>🏆 팀 랭킹 관리 (Team Rankings)</h2>
        <div><?php echo $msg; ?> <a href="admin_hub.php" style="margin-left:20px;">[허브로 돌아가기]</a></div>
    </div>

    <p style="color:#888; font-size:12px;">정렬: 소요 시간 오름차순(빠를수록 상위), 동률이면 등록 시각 순. 3인 협동 클리어 기록만 등록됩니다.</p>

    <table>
        <tr>
            <th>순위</th><th>팀 이름</th><th>소요 시간</th><th>클리어 층</th><th>인원</th>
            <th>총 딜량</th><th>팀원 (이름 · 딜량)</th><th>등록 시각</th><th>관리</th>
        </tr>
        <?php if (count($rows) === 0): ?>
            <tr><td colspan="9" style="color:#888;">등록된 랭킹 기록이 없습니다.</td></tr>
        <?php else: $rank = 1; foreach ($rows as $r):
            $pj = json_decode(isset($r['players_json']) ? $r['players_json'] : '', true);
            $members = (is_array($pj) && isset($pj['members']) && is_array($pj['members'])) ? $pj['members'] : [];
            $rankClass = $rank <= 3 ? "rank$rank" : "";
        ?>
        <tr>
            <td class="<?php echo $rankClass; ?>"><?php echo $rank; ?></td>
            <td><?php echo htmlspecialchars($r['team_name']); ?><?php echo $r['login_id'] ? "<br><span style='color:#777;font-size:11px;'>@" . htmlspecialchars($r['login_id']) . "</span>" : ""; ?></td>
            <td><?php echo fmt_time($r['clear_time_seconds']); ?></td>
            <td><?php echo (int)$r['cleared_level']; ?></td>
            <td><?php echo (int)$r['party_size']; ?></td>
            <td><?php echo number_format((int)$r['total_damage']); ?></td>
            <td class="members">
                <?php if (count($members) === 0): ?>
                    <span style="color:#777;">(팀원 정보 없음 — 클라가 나중에 채움)</span>
                <?php else: foreach ($members as $m): ?>
                    <span class="member-chip"><?php echo htmlspecialchars((string)(isset($m['nickname']) ? $m['nickname'] : '')); ?> · <?php echo number_format((int)(isset($m['damage']) ? $m['damage'] : 0)); ?></span>
                <?php endforeach; endif; ?>
            </td>
            <td style="color:#aaa; font-size:12px;"><?php echo htmlspecialchars($r['cleared_at']); ?></td>
            <td>
                <form method="post" onsubmit="return confirm('이 랭킹 기록을 삭제하시겠습니까?');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="del_id" value="<?php echo (int)$r['id']; ?>">
                    <button type="submit" class="del-btn">삭제</button>
                </form>
            </td>
        </tr>
        <?php $rank++; endforeach; endif; ?>
    </table>
</body>
</html>
