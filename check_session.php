<?php
header('Content-Type: application/json; charset=utf-8');
require 'db_connect.php';

$login_id = isset($_POST['login_id']) ? $_POST['login_id'] : '';
$session_token = isset($_POST['session_token']) ? $_POST['session_token'] : '';

if (empty($login_id) || empty($session_token)) {
    echo json_encode(["status" => "error", "message" => "데이터 부족"]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT session_token, is_admin FROM user_data WHERE login_id = ?");
    $stmt->execute([$login_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // DB의 토큰과 유니티가 보낸 토큰이 일치하면 valid, 다르면 invalid.
    // 하트비트와 admin 검증을 한 번에: valid 일 때 is_admin(0/1)을 함께 실어준다.
    //   ⚠️ 세션 무효 규칙("invalid" 문자열)은 그대로 유지 — 클라 하트비트가 이걸로 강제 로그아웃 판단.
    if ($user && $user['session_token'] === $session_token) {
        echo json_encode(["status" => "valid", "is_admin" => (int)$user['is_admin']], JSON_NUMERIC_CHECK);
    } else {
        echo json_encode(["status" => "invalid", "is_admin" => 0], JSON_NUMERIC_CHECK);
    }
} catch(PDOException $e) {
    echo json_encode(["status" => "error"]);
}