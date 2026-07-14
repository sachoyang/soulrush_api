<?php
// ============================================================
// mask_helper.php
// 기본 해금 스킬 비트마스크(default_mask.txt) 공용 헬퍼
// ------------------------------------------------------------
// ⚠️ 환경 주의: 이 서버의 Apache 는 32비트 PHP 5.6 으로 구동된다.
//    (PHP_INT_SIZE = 4, PHP_INT_MAX ≈ 21억)
//    따라서 64비트 비트마스크를 PHP 네이티브 int 로 다루면
//    bit 31 이상에서 값이 깨진다. ((int) 캐스팅, <<, >>, ^ 모두 위험)
//
//    → 모든 64비트 연산은 bcmath 기반의 "10진수 문자열" 로 처리한다.
//      값의 정식 표현(canonical) 은 항상 [0, 2^64) 범위의 무부호 10진 문자열.
//      MySQL BIGINT(부호 있음) ↔ 이 무부호 표현 사이는 to_u64()/to_signed64()
//      로 변환한다. 클라이언트는 bit 0~62 만 쓰므로 실제로는 양수 범위.
// ============================================================

// ---- 64비트 상수 (문자열) ----
function u64_two_pow_64() { return '18446744073709551616'; } // 2^64
function u64_two_pow_63() { return '9223372036854775808'; }  // 2^63
// 비트 i 의 가중치 2^i (문자열)
function u64_bit_weight($i) { return bcpow('2', (string)((int)$i)); }

// default_mask.txt 절대 경로 (API 루트로 통일)
function default_mask_path() {
    return __DIR__ . '/default_mask.txt';
}

// 임의의 10진 문자열(부호 가능)을 [0, 2^64) 무부호 10진 문자열로 정규화.
// 비어 있거나 형식이 이상하면 '0'.
function to_u64($dec) {
    $dec = trim((string)$dec);
    if ($dec === '' || $dec === '-' || $dec === '+') return '0';
    // 숫자/부호 외 문자가 섞이면 안전하게 0 처리
    if (!preg_match('/^[+-]?[0-9]+$/', $dec)) return '0';
    // bcmath 스케일 0 으로 정수만 취급
    $dec = bcadd($dec, '0', 0);
    // 음수면 2^64 를 더해 무부호 64비트로
    if (bccomp($dec, '0', 0) < 0) {
        $dec = bcadd($dec, u64_two_pow_64(), 0);
    }
    // 범위 밖이면 2^64 모듈러
    $dec = bcmod($dec, u64_two_pow_64());
    if (bccomp($dec, '0', 0) < 0) {
        $dec = bcadd($dec, u64_two_pow_64(), 0);
    }
    return $dec;
}

// 무부호 64비트 표현을 MySQL BIGINT(부호 있는 64비트) 저장용 10진 문자열로.
// bit 63 이 켜져 있으면 음수로 변환(2의 보수). bit 0~62 만 쓰면 그대로 양수.
function to_signed64($udec) {
    $udec = to_u64($udec);
    if (bccomp($udec, u64_two_pow_63(), 0) >= 0) {
        return bcsub($udec, u64_two_pow_64(), 0); // 음수
    }
    return $udec;
}

// 기본 마스크 읽기. 파일이 없거나 비어 있으면 '0' 반환.
// 반환값: 무부호 64비트 10진 문자열.
function read_default_mask() {
    $path = default_mask_path();
    if (!file_exists($path)) {
        return '0';
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return '0';
    }
    return to_u64($raw);
}

// 기본 마스크 쓰기. 동시성 대비 LOCK_EX.
// $mask: 10진 문자열(부호 가능) 또는 int. 파일에는 무부호 10진으로 저장.
function write_default_mask($mask) {
    $path = default_mask_path();
    file_put_contents($path, to_u64($mask), LOCK_EX);
}

// 비트 i(0~63) 가 켜져 있는지: floor(value / 2^i) % 2 == 1
function u64_testbit($udec, $i) {
    $udec = to_u64($udec);
    $i = (int)$i;
    if ($i < 0 || $i > 63) return false;
    $shifted = bcdiv($udec, u64_bit_weight($i), 0);
    return bcmod($shifted, '2') === '1';
}

// 비트 i 토글(XOR) 후 무부호 64비트 문자열 반환.
function u64_togglebit($udec, $i) {
    $udec = to_u64($udec);
    $i = (int)$i;
    if ($i < 0 || $i > 63) return $udec;
    $w = u64_bit_weight($i);
    if (u64_testbit($udec, $i)) {
        return bcsub($udec, $w, 0); // 켜져 있으면 끔
    }
    return bcadd($udec, $w, 0);     // 꺼져 있으면 켬
}

// 64자리 2진 문자열로 변환 (좌측 0패딩). 부호 비트까지 정확히 표현.
function mask_to_bin64($value) {
    $udec = to_u64($value);
    $bits = '';
    for ($b = 63; $b >= 0; $b--) {
        $bits .= u64_testbit($udec, $b) ? '1' : '0';
    }
    return $bits;
}

// 64자리 2진 문자열을 4자리씩 공백으로 끊어 가독성 향상.
// 예: 0000 1011 0000 0101 ...
function group_bin4($bin64) {
    return implode(' ', str_split($bin64, 4));
}
