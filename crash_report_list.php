<?php
header('Content-Type: application/json; charset=utf-8');
require 'db_connect.php';

// =============================================================
//  크래시 리포트 목록 조회 (GET crash_report_list.php?limit=50&type=native_crash)
//  - type 생략 시 전체. limit 은 1~200 으로 클램프.
// =============================================================

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
if ($limit < 1)   $limit = 1;
if ($limit > 200) $limit = 200;

$type = isset($_GET['type']) ? trim($_GET['type']) : '';
if ($type !== '' && !in_array($type, ['exception', 'unhandled', 'native_crash'], true)) {
    echo json_encode(['status' => 'fail', 'message' => '알 수 없는 type', 'reports' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $sql = "SELECT id, report_type, nickname, message, scene, app_version, gpu,
                   DATE_FORMAT(occurred_at, '%Y-%m-%d %H:%i:%s') AS occurred_at
            FROM crash_reports";
    $params = [];
    if ($type !== '') {
        $sql .= " WHERE report_type = ?";
        $params[] = $type;
    }
    // LIMIT 은 바인딩하면 문자열로 인용되므로, 위에서 정수 클램프한 값을 직접 박는다.
    $sql .= " ORDER BY occurred_at DESC, id DESC LIMIT " . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($reports as &$r) {
        $r['id'] = (int)$r['id'];
    }
    unset($r);

    echo json_encode(
        ['status' => 'success', 'message' => '', 'reports' => $reports],
        JSON_UNESCAPED_UNICODE
    );
} catch (PDOException $e) {
    echo json_encode(
        ['status' => 'fail', 'message' => 'DB 에러: ' . $e->getMessage(), 'reports' => []],
        JSON_UNESCAPED_UNICODE
    );
}
