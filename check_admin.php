<?php
header('Content-Type: application/json; charset=utf-8');
require 'db_connect.php';

// =============================================================
//  admin(디버그 권한) 서버 권위 검증
//  - F5 보스 강제 킬처럼 네트워크로 남을 죽이는 조작은, 호스트가 요청자가
//    진짜 admin 인지 서버에 되물어 확정한다(클라 하드코딩 목록 제거).
//  판정:
//   - 세션 토큰이 현재 유효 세션과 불일치 → {"status":"invalid","is_admin":0}
//   - 세션 유효 + is_admin=0        → {"status":"success","is_admin":0}
//   - 세션 유효 + is_admin=1        → {"status":"success","is_admin":1}
//  ⚠️ 세션 무효는 응답에 "invalid" 문자열 유지(클라 하트비트 규칙과 동일).
// =============================================================

$login_id      = isset($_POST['login_id'])      ? $_POST['login_id']      : '';
$session_token = isset($_POST['session_token']) ? $_POST['session_token'] : '';

try {
    $stmt = $pdo->prepare("SELECT session_token, is_admin FROM user_data WHERE login_id = ?");
    $stmt->execute([$login_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || $row['session_token'] !== $session_token) {
        echo json_encode(['status' => 'invalid', 'is_admin' => 0], JSON_NUMERIC_CHECK);
        exit;
    }

    echo json_encode(['status' => 'success', 'is_admin' => (int)$row['is_admin']], JSON_NUMERIC_CHECK);

} catch (PDOException $e) {
    // 세션 무효 규칙과 동일하게 안전한 실패(권한 없음)로 응답
    echo json_encode(['status' => 'invalid', 'is_admin' => 0], JSON_NUMERIC_CHECK);
}
?>
