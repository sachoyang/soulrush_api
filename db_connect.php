<?php
// 에러 출력 설정 (실제 서비스 배포 시에는 끄는 것이 좋음)
ini_set('display_errors', 1);
error_reporting(E_ALL);

$host = 'localhost';
$dbname = 'soulsusers';
$username = 'root';
$password = 'rlacodnjs0801!'; // 설정한 MySQL 비밀번호로 변경

try {
    // PDO 방식으로 안전하게 DB 접속
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    // 에러 발생 시 예외(Exception)를 던지도록 설정
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die(json_encode(["status" => "error", "message" => "DB 연결 실패: " . $e->getMessage()]));
}