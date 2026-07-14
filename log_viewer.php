<?php session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: admin_hub.php");
    exit;
}
require 'db_connect.php';
require 'mask_helper.php'; // 64비트 마스크 변환 헬퍼
// ==========================================
// 로그인 성공 시 보여지는 대시보드
// ==========================================
$action_msg = "";

// 모달(JS BigInt)에서 사용할 bit_index → display_name 매핑 로드
$ability_map = [];
try {
    $abStmt = $pdo->query("SELECT bit_index, display_name FROM abilities ORDER BY bit_index ASC");
    foreach ($abStmt->fetchAll(PDO::FETCH_ASSOC) as $ab) {
        $ability_map[(int)$ab['bit_index']] = $ab['display_name'];
    }
} catch (PDOException $e) {
    // 능력 테이블 조회 실패 시 매핑은 비워둔다 (모달은 비트 인덱스만 표시)
}

// 5. 버튼 액션 처리
if (isset($_POST['action']) && isset($_POST['target_idx'])) {
    $target_idx = (int)$_POST['target_idx'];

    if ($_POST['action'] === 'delete') {
        try {
            $stmt = $pdo->prepare("DELETE FROM user_data WHERE idx = ?");
            $stmt->execute([$target_idx]);
            $action_msg = "<span style='color: #ff4444;'>[알림] 유저(IDX: $target_idx)가 삭제되었습니다.</span>";
        } catch (PDOException $e) {
            $action_msg = "<span style='color: red;'>삭제 에러: " . $e->getMessage() . "</span>";
        }
    } elseif ($_POST['action'] === 'reset') {
        // 🔥 자바스크립트에서 넘어온 새 비밀번호 받기 (없으면 0000)
        $new_pw = isset($_POST['new_pw']) ? trim($_POST['new_pw']) : '0000';

        try {
            // 입력받은 새 비밀번호를 암호화(Hash)하여 DB에 저장
            $new_password_hash = password_hash($new_pw, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE user_data SET password_hash = ? WHERE idx = ?");
            $stmt->execute([$new_password_hash, $target_idx]);

            // 화면 상단에 어떤 비밀번호로 바꿨는지 알림 띄워주기
            $action_msg = "<span style='color: #00ff00;'>[알림] 유저(IDX: $target_idx)의 비밀번호가 <b>[$new_pw]</b> (으)로 초기화되었습니다.</span>";
        } catch (PDOException $e) {
            $action_msg = "<span style='color: red;'>초기화 에러: " . $e->getMessage() . "</span>";
        }
    } elseif ($_POST['action'] === 'toggle_admin') {
        // is_admin 0↔1 토글. admin 계정만 릴리즈 디버그 기능(F5 보스 킬 등) 사용 가능.
        try {
            $stmt = $pdo->prepare("UPDATE user_data SET is_admin = 1 - is_admin WHERE idx = ?");
            $stmt->execute([$target_idx]);
            $chk = $pdo->prepare("SELECT login_id, is_admin FROM user_data WHERE idx = ?");
            $chk->execute([$target_idx]);
            $row = $chk->fetch(PDO::FETCH_ASSOC);
            $state = ($row && (int)$row['is_admin'] === 1) ? "부여됨(1)" : "해제됨(0)";
            $who = $row ? htmlspecialchars($row['login_id']) : "IDX $target_idx";
            $action_msg = "<span style='color: #00ffcc;'>[알림] 유저 [$who] 의 admin 권한이 <b>$state</b> 상태가 되었습니다.</span>";
        } catch (PDOException $e) {
            $action_msg = "<span style='color: red;'>admin 토글 에러: " . $e->getMessage() . " (migration_add_is_admin.sql 실행 여부 확인)</span>";
        }
    }
}
?>
<!DOCTYPE html>
<html>

<head>
    <title>Soul Rush 관리자 대시보드</title>
    <style>
        body {
            background-color: #1e1e1e;
            color: #00ff00;
            font-family: 'Consolas', monospace;
            margin: 0;
            padding: 20px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .logout-btn {
            background-color: #ff4444;
            color: white;
            padding: 5px 10px;
            text-decoration: none;
            border-radius: 4px;
            font-family: sans-serif;
        }

        /* 세로 배치: 서버 로그(위) → DB 표(아래, 넓게) */
        .dashboard-container {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .panel {
            background-color: #2d2d2d;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #444;
        }

        /* 서버 로그 패널: 필요할 때만 펼치므로 높이를 차지하지 않음 */
        #logPanel {
            flex: 0 0 auto;
        }

        /* DB 패널: 표를 넓게 보여주고, 행이 많으면 내부 스크롤 */
        #dbPanel {
            flex: 1 1 auto;
            overflow-y: auto;
        }

        h2 {
            margin-top: 0;
            color: #ffcc00;
            border-bottom: 1px solid #555;
            padding-bottom: 10px;
        }

        pre {
            white-space: pre-wrap;
            word-wrap: break-word;
            max-height: 40vh;
            overflow-y: auto;
            margin-bottom: 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            font-size: 14px;
        }

        th,
        td {
            border: 1px solid #555;
            padding: 6px;
            text-align: left;
            color: #ddd;
            vertical-align: middle;
        }

        th {
            background-color: #444;
            color: #fff;
            font-weight: bold;
        }

        tr:nth-child(even) {
            background-color: #333;
        }

        .text-center {
            text-align: center;
        }

        .action-form {
            display: inline;
            margin: 0;
        }

        .btn {
            padding: 4px 8px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
            font-weight: bold;
            color: #000;
        }

        .btn-reset {
            background-color: #ffcc00;
            margin-right: 5px;
        }

        .btn-delete {
            background-color: #ff4444;
            color: white;
        }

        .skill-bin {
            cursor: pointer;
            color: #00ff00;
            font-family: 'Consolas', monospace;
            font-size: 12px;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .skill-bin:hover {
            color: #ffcc00;
            text-decoration: underline;
        }

        /* 스킬 보유 현황 모달 */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background-color: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .modal-box {
            background-color: #2d2d2d;
            border: 1px solid #555;
            border-radius: 8px;
            padding: 25px;
            width: 600px;
            max-width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }

        .modal-box h3 {
            margin-top: 0;
            color: #ffcc00;
        }

        .modal-close {
            float: right;
            cursor: pointer;
            color: #ff4444;
            font-weight: bold;
            font-size: 18px;
        }

        .chip {
            display: inline-block;
            background-color: #00aaff;
            color: #000;
            padding: 4px 10px;
            margin: 4px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: bold;
        }

        .chip .chip-bit {
            color: #003355;
            font-size: 11px;
            margin-left: 4px;
        }
    </style>
</head>

<body>
    <div class="header">
        <div>
            <h2 style="display:inline; border:none;">🛠️ Soul Rush Admin </h2>
            <?php if (!empty($action_msg)) echo " &nbsp; $action_msg"; ?>
        </div>
        <a href="admin_hub.php" class="logout-btn" style="background-color: #555; margin-right: 10px;">허브로 돌아가기</a>
        <a href="?logout=true" class="logout-btn">로그아웃</a>
    </div>

    <div class="dashboard-container">

        <div class="panel" id="logPanel">
            <h2>📜 Server Logs <button class="btn btn-reset" onclick="location.reload()" style="float:right;">수동 새로고침</button></h2>
            <details>
                <summary style="cursor:pointer; color:#ffcc00; padding:6px 0;">서버 로그 펼치기/접기 (기본 접힘)</summary>
                <pre><?php
                        $logFile = 'server_log.txt';
                        if (file_exists($logFile)) {
                            echo htmlspecialchars(file_get_contents($logFile));
                        } else {
                            echo "아직 기록된 로그가 없습니다.";
                        }
                        ?></pre>
            </details>
        </div>

        <div class="panel" id="dbPanel">
            <h2>🗄️ Database (user_data)</h2>
            <table>
                <thead>
                    <tr>
                        <th class="text-center">IDX</th>
                        <th>Login ID</th>
                        <th>Nickname</th>
                        <th>Skills</th>
                        <th class="text-center">Admin</th>
                        <th>Created At</th>
                        <th class="text-center">관리</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    try {
                        $stmt = $pdo->query("SELECT idx, login_id, nickname, unlocked_skills, is_admin, created_at FROM user_data ORDER BY idx DESC");
                        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

                        if (count($users) > 0) {
                            foreach ($users as $user) {
                                echo "<tr>";
                                echo "<td class='text-center'>" . htmlspecialchars($user['idx']) . "</td>";
                                echo "<td>" . htmlspecialchars($user['login_id']) . "</td>";
                                echo "<td>" . htmlspecialchars($user['nickname']) . "</td>";

                                // unlocked_skills: 64비트 2진수(4자리 묶음)로 표시 + 클릭 시 모달
                                $skill_dec = (string)$user['unlocked_skills']; // BIGINT 원본(부호 포함 10진 문자열)
                                $skill_bin = group_bin4(mask_to_bin64($skill_dec));
                                echo "<td>";
                                echo "<code class='skill-bin' title='클릭 시 보유 스킬 보기' "
                                    . "onclick='showSkillModal(\"" . htmlspecialchars($skill_dec, ENT_QUOTES) . "\", \"" . htmlspecialchars($user['nickname'], ENT_QUOTES) . "\")'>"
                                    . $skill_bin
                                    . "</code>";
                                echo "</td>";

                                // is_admin 토글 셀 (디버그 권한)
                                $isAdmin = (int)$user['is_admin'] === 1;
                                echo "<td class='text-center'>";
                                echo "<form method='post' class='action-form' onsubmit=\"return confirm('[" . htmlspecialchars($user['login_id']) . "] 유저의 admin 권한을 " . ($isAdmin ? '해제' : '부여') . "하시겠습니까?');\">";
                                echo "<input type='hidden' name='action' value='toggle_admin'>";
                                echo "<input type='hidden' name='target_idx' value='" . $user['idx'] . "'>";
                                if ($isAdmin) {
                                    echo "<button type='submit' class='btn' style='background-color:#00ffcc;'>ADMIN ✔</button>";
                                } else {
                                    echo "<button type='submit' class='btn' style='background-color:#555; color:#fff;'>일반</button>";
                                }
                                echo "</form>";
                                echo "</td>";

                                echo "<td>" . date('m-d H:i', strtotime($user['created_at'])) . "</td>";

                                echo "<td class='text-center'>";

                                // 🔥 수정된 PW 초기화 폼 (숨겨진 input으로 새 비밀번호 전달)
                                echo "<form method='post' class='action-form' id='reset_form_" . $user['idx'] . "'>";
                                echo "<input type='hidden' name='action' value='reset'>";
                                echo "<input type='hidden' name='target_idx' value='" . $user['idx'] . "'>";
                                echo "<input type='hidden' name='new_pw' id='new_pw_" . $user['idx'] . "' value='0000'>";
                                // 버튼 누를 때 JS 함수 호출
                                echo "<button type='button' class='btn btn-reset' onclick='triggerReset(" . $user['idx'] . ", \"" . htmlspecialchars($user['login_id']) . "\")'>PW초기화</button>";
                                echo "</form>";

                                // 삭제 버튼
                                echo "<form method='post' class='action-form' onsubmit=\"return confirm('경고: [" . htmlspecialchars($user['login_id']) . "] 유저를 영구적으로 삭제하시겠습니까?');\">";
                                echo "<input type='hidden' name='action' value='delete'>";
                                echo "<input type='hidden' name='target_idx' value='" . $user['idx'] . "'>";
                                echo "<button type='submit' class='btn btn-delete'>삭제</button>";
                                echo "</form>";

                                echo "</td>";
                                echo "</tr>";
                            }
                        } else {
                            echo "<tr><td colspan='7' class='text-center'>가입된 유저가 없습니다.</td></tr>";
                        }
                    } catch (PDOException $e) {
                        echo "<tr><td colspan='7' style='color:red;'>DB 조회 에러: " . $e->getMessage() . "</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 스킬 보유 현황 모달 -->
    <div class="modal-overlay" id="skillModal" onclick="if(event.target===this) closeSkillModal()">
        <div class="modal-box">
            <span class="modal-close" onclick="closeSkillModal()">✖</span>
            <h3 id="skillModalTitle">보유 스킬</h3>
            <p style="color:#888; font-size:12px;" id="skillModalDec"></p>
            <div id="skillModalChips"></div>
        </div>
    </div>

    <script>
        // PHP 에서 심어준 bit_index → display_name 매핑
        const ABILITY_MAP = <?php echo json_encode($ability_map, JSON_UNESCAPED_UNICODE); ?>;

        // 64비트 마스크(부호 있는 10진 문자열)를 받아 보유 스킬을 모달로 표시
        function showSkillModal(decStr, nickname) {
            // BigInt 로 64비트 연산. 음수(부호 비트)면 64비트 무부호로 보정.
            let value = BigInt(decStr);
            if (value < 0n) {
                value += (1n << 64n);
            }

            const chips = [];
            for (let i = 0n; i < 64n; i++) {
                if (((value >> i) & 1n) === 1n) {
                    const bit = Number(i);
                    const name = ABILITY_MAP[bit];
                    if (name !== undefined) {
                        chips.push('<span class="chip">' + escapeHtml(name) + '<span class="chip-bit">#' + bit + '</span></span>');
                    } else {
                        // 매핑에 없는 비트 (스킬 미등록) — 비트 인덱스만 표시
                        chips.push('<span class="chip" style="background-color:#777;">(미등록)<span class="chip-bit">#' + bit + '</span></span>');
                    }
                }
            }

            document.getElementById('skillModalTitle').innerText = '보유 스킬 — ' + nickname;
            document.getElementById('skillModalDec').innerText = 'unlocked_skills (10진수): ' + decStr;
            document.getElementById('skillModalChips').innerHTML =
                chips.length > 0 ? chips.join('') : '<span style="color:#888;">보유 스킬 없음</span>';
            document.getElementById('skillModal').style.display = 'flex';
        }

        function closeSkillModal() {
            document.getElementById('skillModal').style.display = 'none';
        }

        function escapeHtml(s) {
            return String(s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        // 🔥 비밀번호 초기화 팝업 띄우는 함수
        function triggerReset(idx, loginId) {
            // 사용자에게 새 비밀번호 입력받기 (기본값 "0000")
            let newPw = prompt("[" + loginId + "] 유저의 임시 비밀번호를 지정해주세요.\n(기본값은 0000 입니다)", "0000");

            // 취소를 누르지 않았고, 빈칸이 아닐 때만 폼 제출
            if (newPw !== null && newPw.trim() !== "") {
                // 숨겨둔 input 태그에 입력받은 비밀번호를 쏙 넣고
                document.getElementById('new_pw_' + idx).value = newPw.trim();
                // 폼을 전송!
                document.getElementById('reset_form_' + idx).submit();
            }
        }
    </script>
</body>

</html>