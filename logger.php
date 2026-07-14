<?php
function writeLog($action, $message) {
    $logFile = 'server_log.txt';
    $time = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR']; // 접속한 유저의 IP
    // 로그 한 줄 생성
    $logEntry = "[$time] [IP: $ip] [$action] $message" . PHP_EOL;
    // 텍스트 파일에 이어쓰기 (파일이 없으면 자동 생성됨)
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}