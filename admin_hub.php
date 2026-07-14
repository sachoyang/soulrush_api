<?php
session_start();

$admin_pw = 'admin1234'; // 관리자 비밀번호

if (isset($_POST['admin_login'])) {
    if ($_POST['pw_input'] === $admin_pw) {
        $_SESSION['admin_logged_in'] = true;
        header("Location: admin_hub.php");
        exit;
    } else {
        $login_error = "비밀번호가 틀렸습니다.";
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: admin_hub.php");
    exit;
}

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>관리자 로그인</title>
        <style>
            body { background-color: #1e1e1e; color: #fff; display: flex; justify-content: center; align-items: center; height: 100vh; font-family: sans-serif; }
            .login-box { background-color: #2d2d2d; padding: 30px; border-radius: 8px; text-align: center; border: 1px solid #444; }
            input { padding: 10px; margin: 10px 0; border-radius: 4px; border: 1px solid #666; background-color: #333; color: #fff; width: 200px; }
            button { padding: 10px 20px; background-color: #ffcc00; font-weight: bold; cursor: pointer; border-radius: 4px; border: none;}
        </style>
    </head>
    <body>
        <div class="login-box">
            <h2>🔐 Soul Rush 통합 관리자</h2>
            <form method="post">
                <input type="password" name="pw_input" placeholder="비밀번호 입력" required autofocus><br>
                <button type="submit" name="admin_login">접속</button>
            </form>
            <?php if (isset($login_error)) echo "<p style='color:#ff4444;'>$login_error</p>"; ?>
        </div>
    </body>
    </html>
<?php
    exit; 
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Soul Rush Admin Hub</title>
    <style>
        body { background-color: #1e1e1e; color: #fff; font-family: sans-serif; padding: 50px; text-align: center; }
        .menu-container { display: flex; justify-content: center; gap: 20px; margin-top: 50px; }
        .menu-btn { display: block; padding: 20px 40px; background-color: #2d2d2d; color: #00ff00; text-decoration: none; border: 1px solid #444; border-radius: 8px; font-size: 20px; font-weight: bold; transition: 0.3s; }
        .menu-btn:hover { background-color: #ffcc00; color: #000; }
        .logout-btn { display: inline-block; margin-top: 50px; color: #ff4444; text-decoration: none; border: 1px solid #ff4444; padding: 10px 20px; border-radius: 4px; }
    </style>
</head>
<body>
    <h1>🛠️ Soul Rush 통합 관리자 대시보드</h1>
    <div class="menu-container">
        <a href="log_viewer.php" class="menu-btn">👤 유저 DB 관리<br><span style="font-size:14px; color:#aaa;">(가입자 확인, PW 초기화)</span></a>
        <a href="ability_manager.php" class="menu-btn">⚔️ 스킬 모듈 관리<br><span style="font-size:14px; color:#aaa;">(능력 생성, 수치 수정)</span></a>
        <a href="ranking_viewer.php" class="menu-btn">🏆 팀 랭킹 관리<br><span style="font-size:14px; color:#aaa;">(클리어 기록 조회, 삭제)</span></a>
        <a href="crash_viewer.php" class="menu-btn">💥 크래시 리포트<br><span style="font-size:14px; color:#aaa;">(예외/네이티브 크래시 확인)</span></a>
    </div>
    <a href="?logout=true" class="logout-btn">로그아웃</a>
</body>
</html>