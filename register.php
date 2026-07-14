<?php
header('Content-Type: application/json; charset=utf-8');
require 'db_connect.php'; // DB 접속 공통 모듈

$login_id = isset($_POST['login_id']) ? $_POST['login_id'] : '';
$password = isset($_POST['password']) ? $_POST['password'] : '';
$nickname = isset($_POST['nickname']) ? $_POST['nickname'] : '';

// 1. 빈 값 검사
if (empty($login_id) || empty($password) || empty($nickname)) {
    echo json_encode(["status" => "error", "message" => "모든 필드를 입력해주세요."]);
    exit;
}

try {
    // 2. 비밀번호 단방향 암호화 (Bcrypt 등 최신 PHP 기본 해시 알고리즘 사용)
    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    // 3. DB에 유저 정보 삽입 (Prepare 방식)
    //     [기본 지급 일원화] 신규 유저의 unlocked_skills 는 항상 0 으로 시작한다.
    //     "모든 유저 기본 지급" 여부는 abilities.basic_skill 전역 플래그가 전담하며,
    //     유저 비트마스크에는 유저가 직접 해금한 스킬만 담긴다. (구분 목적 유지)
    $stmt = $pdo->prepare("INSERT INTO user_data (login_id, password_hash, nickname, unlocked_skills) VALUES (?, ?, ?, 0)");
    $stmt->execute([$login_id, $password_hash, $nickname]);

    echo json_encode(["status" => "success", "message" => "회원가입이 완료되었습니다."]);

} catch(PDOException $e) {
    // 에러 코드 23000은 MySQL에서 UNIQUE 제약 조건(중복) 위반 시 발생합니다.
    if ($e->getCode() == 23000) {
        echo json_encode(["status" => "error", "message" => "이미 존재하는 아이디 또는 닉네임입니다."]);
    } else {
        echo json_encode(["status" => "error", "message" => "서버 에러: " . $e->getMessage()]);
    }
}