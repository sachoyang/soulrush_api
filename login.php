<?php
header('Content-Type: application/json; charset=utf-8');
require 'db_connect.php';
require 'logger.php';
require 'mask_helper.php'; // default_mask.txt 공용 헬퍼

$login_id = isset($_POST['login_id']) ? $_POST['login_id'] : '';
$password = isset($_POST['password']) ? $_POST['password'] : '';

if (empty($login_id) || empty($password)) {
    echo json_encode(["status" => "error", "message" => "아이디나 비밀번호가 비어있습니다."]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM user_data WHERE login_id = ?");
    $stmt->execute([$login_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password_hash'])) {

        // 중복 접속 방지용 고유 세션 토큰 생성 및 DB 저장
        $session_token = md5(uniqid(mt_rand(), true));
        $tokenStmt = $pdo->prepare("UPDATE user_data SET session_token = ? WHERE login_id = ?");
        $tokenStmt->execute([$session_token, $login_id]);

        // [기본 지급 일원화] default_mask 자동 보정 로직 제거.
        //   - unlocked_skills 에는 유저가 직접 해금한 스킬만 담긴다(0으로 시작 가능).
        //   - "모든 유저 기본 지급"은 abilities.basic_skill 전역 플래그가 전담하며,
        //     클라가 (basic_skill==1) OR (유저 bit) 로 최종 풀을 계산한다.
        //   ⚠️ 32비트 PHP 이므로 (int) 캐스팅 금지. 무부호 64비트 문자열로 정규화만 한다.
        $unlocked_skills = to_u64($user['unlocked_skills']); // [0,2^64) 문자열

        // is_admin: 릴리즈 빌드 디버그 게이트(F5 보스 킬 등) 서버 권위 판정값.
        //   ⚠️ JsonUtility(int) 파싱이므로 반드시 JSON 숫자 0/1 로. 컬럼 없으면 0.
        $is_admin = isset($user['is_admin']) ? (int)$user['is_admin'] : 0;

        writeLog("LOGIN_SUCCESS", "User: " . $login_id);

        // unlocked_skills 는 숫자 문자열로 내려준다. (클라이언트는 long 으로 파싱)
        // 32비트 PHP 에서 큰 값을 JSON 숫자로 내리면 부정확하므로 문자열이 안전.
        //   🔴 JSON_NUMERIC_CHECK 금지! 이 플래그는 "모든" 숫자문자열을 숫자로 바꾸므로
        //      unlocked_skills 가 float 으로 뭉개진다(9223372036854775807 → 9.2233720368548e+18).
        //      is_admin 은 위에서 이미 (int) 라 플래그 없이도 JSON 숫자로 나간다.
        echo json_encode([
            "status" => "success",
            "message" => "로그인 성공",
            "nickname" => $user['nickname'],
            "unlocked_skills" => $unlocked_skills, // BIGINT(64비트) 숫자문자열. 클라이언트는 long으로 파싱
            "session_token" => $session_token, // 🔥 유니티로 토큰 전달
            "is_admin" => $is_admin // 0/1 (JSON 숫자). 클라 AuthResponse.is_admin 파싱
        ]);
    } else {
        writeLog("LOGIN_FAIL", "Attempted ID: " . $login_id);
        echo json_encode(["status" => "error", "message" => "아이디 또는 비밀번호가 틀렸습니다."]);
    }
} catch (PDOException $e) {
    echo json_encode(["status" => "error", "message" => "서버 에러: " . $e->getMessage()]);
}
