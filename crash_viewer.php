<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_hub.php");
    exit;
}
require 'db_connect.php';

// =============================================================
//  크래시 리포트 뷰어 (crash_reports)
//  - 상단: 최근 7일 집계(같은 크래시가 몇 명한테 터졌는지)
//  - 하단: 개별 리포트 목록. 스택트레이스/로그 꼬리는 접었다 펼침.
// =============================================================

$msg = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'delete' && isset($_POST['del_id'])) {
            $stmt = $pdo->prepare("DELETE FROM crash_reports WHERE id = ?");
            $stmt->execute([(int)$_POST['del_id']]);
            $msg = "<span style='color:#ff4444;'>[알림] 리포트(ID: " . (int)$_POST['del_id'] . ")가 삭제되었습니다.</span>";
        } elseif ($_POST['action'] === 'purge') {
            $n = $pdo->exec("DELETE FROM crash_reports WHERE received_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
            $msg = "<span style='color:#ffcc00;'>[알림] 30일 지난 리포트 " . (int)$n . "건을 정리했습니다.</span>";
        }
    } catch (PDOException $e) {
        $msg = "<span style='color:red;'>에러: " . htmlspecialchars($e->getMessage()) . "</span>";
    }
}

$type = isset($_GET['type']) ? $_GET['type'] : '';
if (!in_array($type, ['exception', 'unhandled', 'native_crash'], true)) {
    $type = '';
}

$rows = [];
$agg  = [];
$loadError = false;
try {
    // 최근 7일, 같은 크래시가 몇 명한테 터졌는지
    $agg = $pdo->query(
        "SELECT report_type, scene, LEFT(message, 80) AS msg,
                COUNT(*) AS hits, COUNT(DISTINCT login_id) AS users
         FROM crash_reports
         WHERE occurred_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
         GROUP BY report_type, scene, msg
         ORDER BY users DESC, hits DESC
         LIMIT 20"
    )->fetchAll(PDO::FETCH_ASSOC);

    $sql = "SELECT id, client_report_id, report_type, login_id, nickname, message, stack_trace, log_tail,
                   scene, app_version, unity_version, platform, device_model, gpu, ram_mb,
                   DATE_FORMAT(occurred_at, '%Y-%m-%d %H:%i:%s') AS occurred_at,
                   DATE_FORMAT(received_at, '%Y-%m-%d %H:%i:%s') AS received_at
            FROM crash_reports";
    $params = [];
    if ($type !== '') {
        $sql .= " WHERE report_type = ?";
        $params[] = $type;
    }
    $sql .= " ORDER BY occurred_at DESC, id DESC LIMIT 200";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $loadError = true;
    $msg .= "<span style='color:red;'>[오류] 목록 로드 실패: " . htmlspecialchars($e->getMessage()) . " (migration_crash_reports.sql 실행 여부 확인)</span>";
}

function type_badge($t) {
    $map = [
        'exception'    => ['#3a5', '예외'],
        'unhandled'    => ['#c73', '미처리'],
        'native_crash' => ['#c33', '네이티브'],
    ];
    $c = isset($map[$t]) ? $map[$t] : ['#666', $t];
    return "<span class='badge' style='background:" . $c[0] . ";'>" . htmlspecialchars($c[1]) . "</span>";
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>크래시 리포트</title>
    <style>
        body { background-color: #1e1e1e; color: #ddd; font-family: sans-serif; padding: 20px; }
        .header { display: flex; justify-content: space-between; border-bottom: 1px solid #444; padding-bottom: 10px; margin-bottom: 20px; }
        a { color: #ffcc00; text-decoration: none; }
        h3 { color: #fff; margin-top: 30px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 13px; }
        th, td { border: 1px solid #555; padding: 8px; text-align: center; vertical-align: middle; }
        th { background-color: #444; }
        tr:nth-child(even) { background-color: #2a2a2a; }
        .badge { display:inline-block; color:#fff; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:bold; }
        .msg { text-align:left; font-family: Consolas, monospace; color:#f88; word-break:break-all; }
        .tabs { margin: 10px 0; }
        .tabs a { display:inline-block; padding:6px 14px; margin-right:6px; border:1px solid #444; border-radius:4px; background:#2d2d2d; }
        .tabs a.on { background:#ffcc00; color:#000; font-weight:bold; }
        details { text-align:left; }
        summary { cursor:pointer; color:#8bf; font-size:12px; }
        pre { background:#111; border:1px solid #333; padding:10px; overflow-x:auto; max-height:300px; font-size:11px; color:#bbb; white-space:pre-wrap; word-break:break-all; }
        button.del-btn { background-color: #ff4444; color: white; padding: 4px 8px; border:none; border-radius:4px; cursor:pointer; }
        button.purge-btn { background-color: #555; color: #ffcc00; padding: 6px 12px; border:1px solid #777; border-radius:4px; cursor:pointer; }
        .meta { color:#888; font-size:11px; }
    </style>
</head>
<body>
    <div class="header">
        <h2>💥 크래시 리포트 (Crash Reports)</h2>
        <div><?php echo $msg; ?> <a href="admin_hub.php" style="margin-left:20px;">[허브로 돌아가기]</a></div>
    </div>

    <h3>📊 최근 7일 집계 — 같은 크래시가 몇 명한테 터졌나</h3>
    <table>
        <tr><th>종류</th><th>씬</th><th>메시지 (앞 80자)</th><th>발생 횟수</th><th>영향 유저 수</th></tr>
        <?php if (count($agg) === 0): ?>
            <tr><td colspan="5" style="color:#888;">최근 7일간 크래시가 없습니다. 🎉</td></tr>
        <?php else: foreach ($agg as $a): ?>
        <tr>
            <td><?php echo type_badge($a['report_type']); ?></td>
            <td><?php echo htmlspecialchars((string)$a['scene']); ?></td>
            <td class="msg"><?php echo htmlspecialchars((string)$a['msg']); ?></td>
            <td><?php echo (int)$a['hits']; ?></td>
            <td style="color:#ffcc00; font-weight:bold;"><?php echo (int)$a['users']; ?></td>
        </tr>
        <?php endforeach; endif; ?>
    </table>

    <h3>📋 개별 리포트 (최근 200건)</h3>
    <div class="tabs">
        <a href="crash_viewer.php" class="<?php echo $type === '' ? 'on' : ''; ?>">전체</a>
        <a href="?type=native_crash" class="<?php echo $type === 'native_crash' ? 'on' : ''; ?>">네이티브 크래시</a>
        <a href="?type=unhandled" class="<?php echo $type === 'unhandled' ? 'on' : ''; ?>">미처리 예외</a>
        <a href="?type=exception" class="<?php echo $type === 'exception' ? 'on' : ''; ?>">C# 예외</a>
        <form method="post" style="display:inline; float:right;" onsubmit="return confirm('30일 지난 리포트를 모두 삭제합니다. 진행할까요?');">
            <input type="hidden" name="action" value="purge">
            <button type="submit" class="purge-btn">🧹 30일 지난 리포트 정리</button>
        </form>
    </div>

    <p style="color:#888; font-size:12px;">
        ⚠️ <b>네이티브 크래시</b>의 <b>발생 시각(UTC)</b>은 실제로 죽은 시각이 아니라 <b>다음 실행 시각</b>입니다.
        실제 죽은 시점은 <b>로그 꼬리</b>를 펼쳐서 확인하세요.
    </p>

    <table>
        <tr>
            <th>ID</th><th>종류</th><th>유저</th><th>메시지 / 스택</th><th>씬</th>
            <th>버전</th><th>환경</th><th>발생 시각 (UTC)</th><th>관리</th>
        </tr>
        <?php if (count($rows) === 0): ?>
            <tr><td colspan="9" style="color:#888;"><?php echo $loadError ? "테이블을 읽을 수 없습니다." : "수집된 크래시 리포트가 없습니다."; ?></td></tr>
        <?php else: foreach ($rows as $r): ?>
        <tr>
            <td><?php echo (int)$r['id']; ?></td>
            <td><?php echo type_badge($r['report_type']); ?></td>
            <td>
                <?php if ($r['nickname'] !== null && $r['nickname'] !== ''): ?>
                    <?php echo htmlspecialchars($r['nickname']); ?>
                    <?php if ($r['login_id']): ?><br><span class="meta">@<?php echo htmlspecialchars($r['login_id']); ?></span><?php endif; ?>
                <?php else: ?>
                    <span class="meta">(익명 · 로그인 전)</span>
                <?php endif; ?>
            </td>
            <td class="msg">
                <?php echo nl2br(htmlspecialchars($r['message'])); ?>
                <?php if ($r['stack_trace']): ?>
                    <details><summary>스택트레이스 펼치기</summary><pre><?php echo htmlspecialchars($r['stack_trace']); ?></pre></details>
                <?php endif; ?>
                <?php if ($r['log_tail']): ?>
                    <details><summary>🔍 로그 꼬리 (직전 세션 — 실제 죽은 시점)</summary><pre><?php echo htmlspecialchars($r['log_tail']); ?></pre></details>
                <?php endif; ?>
            </td>
            <td><?php echo htmlspecialchars((string)$r['scene']); ?></td>
            <td>
                <?php echo htmlspecialchars((string)$r['app_version']); ?>
                <br><span class="meta"><?php echo htmlspecialchars((string)$r['unity_version']); ?></span>
            </td>
            <td class="meta" style="text-align:left;">
                <?php echo htmlspecialchars((string)$r['platform']); ?><br>
                <?php echo htmlspecialchars((string)$r['device_model']); ?><br>
                <?php echo htmlspecialchars((string)$r['gpu']); ?><br>
                RAM <?php echo number_format((int)$r['ram_mb']); ?> MB
            </td>
            <td class="meta">
                <?php echo htmlspecialchars($r['occurred_at']); ?>
                <br>(수신 <?php echo htmlspecialchars($r['received_at']); ?>)
            </td>
            <td>
                <form method="post" onsubmit="return confirm('이 크래시 리포트를 삭제하시겠습니까?');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="del_id" value="<?php echo (int)$r['id']; ?>">
                    <button type="submit" class="del-btn">삭제</button>
                </form>
            </td>
        </tr>
        <?php endforeach; endif; ?>
    </table>
</body>
</html>
