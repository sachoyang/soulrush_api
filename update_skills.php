<?php
header('Content-Type: application/json; charset=utf-8');
require 'db_connect.php';
require 'mask_helper.php'; // 64비트 마스크(문자열) 헬퍼

$login_id = isset($_POST['login_id']) ? $_POST['login_id'] : '';
$session_token = isset($_POST['session_token']) ? $_POST['session_token'] : '';
$unlocked_skills = isset($_POST['unlocked_skills']) ? $_POST['unlocked_skills'] : '';

if (empty($login_id) || empty($session_token) || $unlocked_skills === '') {
    echo json_encode(["status" => "error", "message" => "요청 데이터가 부족합니다."]);
    exit;
}

try {
    // 🔥 [추가됨] DB에 저장된 현재 토큰이 일치하는지 확인 (중복 접속 튕겨내기)
    $checkStmt = $pdo->prepare("SELECT session_token FROM user_data WHERE login_id = ?");
    $checkStmt->execute([$login_id]);
    $user = $checkStmt->fetch(PDO::FETCH_ASSOC);

    // 유저가 없거나, 유니티가 보낸 토큰과 DB의 토큰이 다르면 컷!
    if (!$user || $user['session_token'] !== $session_token) {
        echo json_encode(["status" => "error", "message" => "다른 기기에서 접속하여 연결이 끊어졌습니다."]);
        exit;
    }

    // ⚠️ 32비트 PHP: (int) 캐스팅하면 bit 31 이상이 잘려나간다.
    //    클라이언트가 보낸 unlocked_skills(숫자/숫자문자열)를 무부호 64비트 문자열로
    //    정규화한 뒤, BIGINT 저장용 부호 표현으로 변환해 저장한다.
    $skills_u64 = to_u64($unlocked_skills);          // [0,2^64) 문자열
    $skills_db  = to_signed64($skills_u64);          // BIGINT(부호 있음) 저장용
    $stmt = $pdo->prepare("UPDATE user_data SET unlocked_skills = ? WHERE login_id = ?");
    $stmt->execute([$skills_db, $login_id]);

    echo json_encode([
        "status" => "success",
        "message" => "스킬 데이터가 성공적으로 저장되었습니다.",
        "updated_skills" => $skills_u64 // 숫자문자열로 반환 (클라이언트는 long 파싱)
    ]);

} catch(PDOException $e) {
    echo json_encode(["status" => "error", "message" => "데이터 저장 실패: " . $e->getMessage()]);
}