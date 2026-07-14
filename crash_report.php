<?php
header('Content-Type: application/json; charset=utf-8');
require 'db_connect.php';

// =============================================================
//  크래시 리포트 수집 (CRASH_REPORT_SERVER_SPEC.md)
//  - 인증 불필요: 로그인 전 크래시도 익명으로 수집한다.
//  - client_report_id 로 재전송 중복을 차단하되, 중복은 반드시 status="duplicate" 로.
//    (여기서 500을 던지면 클라가 그 리포트를 영원히 재전송한다)
// =============================================================

function respond($status, $message) {
    echo json_encode(['status' => $status, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// ⚠️ 이 서버의 PHP 5.6 에는 mbstring 이 로드되어 있지 않다. mb_* 를 직접 부르면 Fatal error.
//    아래 두 헬퍼는 mbstring 이 있으면 쓰고, 없으면 PCRE 로 UTF-8 안전하게 자른다.

// TEXT 컬럼은 64KB(바이트). 바이트로 자르되 끝의 깨진 멀티바이트 조각을 떼어낸다.
function cut_bytes($s, $maxBytes) {
    if (strlen($s) <= $maxBytes) return $s;
    if (function_exists('mb_strcut')) return mb_strcut($s, 0, $maxBytes, 'UTF-8');
    $s = substr($s, 0, $maxBytes);
    // 유효한 UTF-8 이 될 때까지 꼬리 바이트를 최대 3개까지 떼어낸다.
    for ($i = 0; $i < 3 && $s !== '' && !preg_match('//u', $s); $i++) {
        $s = substr($s, 0, -1);
    }
    return $s;
}

// VARCHAR 은 "문자" 수 기준. 한글 1자 = 1문자로 세야 한다.
function cut_chars($s, $maxChars) {
    if (function_exists('mb_substr')) return mb_substr($s, 0, $maxChars, 'UTF-8');
    // /u 는 문자 단위로 센다. 깨진 입력이면 /u 매치가 실패하므로 바이트 컷으로 폴백.
    $out = preg_replace('/^(.{0,' . (int)$maxChars . '}).*$/us', '$1', $s);
    return ($out === null) ? cut_bytes($s, $maxChars) : $out;
}

function post_str($key) {
    return isset($_POST[$key]) ? trim($_POST[$key]) : '';
}

$client_report_id = post_str('client_report_id');
$report_type      = post_str('report_type');
$message          = isset($_POST['message']) ? $_POST['message'] : ''; // 줄바꿈 보존 (trim 금지)
$occurred_at      = post_str('occurred_at');

// --- 1. 필수 필드 ---
if ($client_report_id === '' || $report_type === '' || $message === '' || $occurred_at === '') {
    respond('fail', '필수 필드 누락');
}

// --- 2. report_type 화이트리스트 ---
if (!in_array($report_type, ['exception', 'unhandled', 'native_crash'], true)) {
    respond('fail', '알 수 없는 report_type');
}

// occurred_at 은 UTC "yyyy-MM-dd HH:mm:ss" 고정. 형식이 깨지면 DB가 0000-00-00 으로 먹으므로 여기서 컷.
$dt = DateTime::createFromFormat('Y-m-d H:i:s', $occurred_at);
if (!$dt || $dt->format('Y-m-d H:i:s') !== $occurred_at) {
    respond('fail', 'occurred_at 형식 오류');
}

$login_id      = post_str('login_id');
$nickname      = post_str('nickname');
$session_token = post_str('session_token');
$client_ip     = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';

// 폼 필드는 전부 문자열로 도착한다. ram_mb 는 INT UNSIGNED 이므로 음수 방어.
$ram_mb = (int)post_str('ram_mb');
if ($ram_mb < 0) $ram_mb = 0;

$stack_trace = cut_bytes(isset($_POST['stack_trace']) ? $_POST['stack_trace'] : '', 60000);
$log_tail    = cut_bytes(isset($_POST['log_tail'])    ? $_POST['log_tail']    : '', 60000);

// VARCHAR 길이 컷 (strict mode 에서 초과 시 INSERT 자체가 실패한다)
$message      = cut_chars($message, 1000);
$scene        = cut_chars(post_str('scene'),         100);
$app_version  = cut_chars(post_str('app_version'),   30);
$unity_ver    = cut_chars(post_str('unity_version'), 30);
$platform     = cut_chars(post_str('platform'),      40);
$device_model = cut_chars(post_str('device_model'),  200);
$gpu          = cut_chars(post_str('gpu'),           200);
$login_id     = cut_chars($login_id, 50);
$nickname     = cut_chars($nickname, 50);

try {
    // --- 3. 유량 제어: 같은 IP 1분에 20건 초과면 거부 ---
    if ($client_ip !== '') {
        $rl = $pdo->prepare(
            "SELECT COUNT(*) AS c FROM crash_reports
             WHERE client_ip = ? AND received_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)"
        );
        $rl->execute([$client_ip]);
        $row = $rl->fetch(PDO::FETCH_ASSOC);
        if ((int)$row['c'] >= 20) {
            respond('fail', 'rate limited');
        }
    }

    // --- 4. 세션 검증 (토큰이 있을 때만). 불일치해도 리포트는 버리지 않고 익명으로 강등 ---
    // 토큰이 비었으면(로그인 전 크래시) 검증 없이 그대로 저장한다. 크래시 수집은 인증이 필수가 아니다.
    if ($session_token !== '') {
        $chk = $pdo->prepare("SELECT session_token FROM user_data WHERE login_id = ?");
        $chk->execute([$login_id]);
        $u = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$u || $u['session_token'] !== $session_token) {
            $login_id = '';
            $nickname = '';
        }
    }

    // --- 5. INSERT. UNIQUE 위반이면 duplicate ---
    $stmt = $pdo->prepare(
        "INSERT INTO crash_reports
            (client_report_id, report_type, login_id, nickname, message, stack_trace, log_tail,
             scene, app_version, unity_version, platform, device_model, gpu, ram_mb, client_ip, occurred_at)
         VALUES (:crid, :rt, :lid, :nick, :msg, :st, :lt, :scene, :av, :uv, :plat, :dev, :gpu, :ram, :ip, :oa)"
    );
    $stmt->execute([
        ':crid'  => $client_report_id,
        ':rt'    => $report_type,
        ':lid'   => ($login_id     !== '' ? $login_id     : null),
        ':nick'  => ($nickname     !== '' ? $nickname     : null),
        ':msg'   => $message,
        ':st'    => ($stack_trace  !== '' ? $stack_trace  : null),
        ':lt'    => ($log_tail     !== '' ? $log_tail     : null),
        ':scene' => ($scene        !== '' ? $scene        : null),
        ':av'    => ($app_version  !== '' ? $app_version  : null),
        ':uv'    => ($unity_ver    !== '' ? $unity_ver    : null),
        ':plat'  => ($platform     !== '' ? $platform     : null),
        ':dev'   => ($device_model !== '' ? $device_model : null),
        ':gpu'   => ($gpu          !== '' ? $gpu          : null),
        ':ram'   => $ram_mb,
        ':ip'    => ($client_ip    !== '' ? $client_ip    : null),
        ':oa'    => $occurred_at,
    ]);

    respond('success', '리포트 저장됨');

} catch (PDOException $e) {
    // 23000 = 무결성 제약 위반. uk_report(client_report_id) 재전송 중복.
    if ($e->getCode() === '23000') {
        respond('duplicate', '이미 수집된 리포트');
    }
    respond('fail', 'DB 에러: ' . $e->getMessage());
}
