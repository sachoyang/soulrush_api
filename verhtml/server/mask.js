/* ============================================================
 *  mask.js — 64비트 스킬 비트마스크 헬퍼 (원본 mask_helper.php 대응)
 * ------------------------------------------------------------
 *  원본 PHP 는 32비트 빌드(PHP_INT_SIZE=4)라 64비트 마스크를 네이티브 int 로
 *  다루면 bit 31 이상이 깨졌고, 그래서 bcmath 10진수 문자열로 우회했다.
 *  Node 에는 BigInt 가 있으므로 그 우회가 통째로 사라진다 — 다만
 *  "값의 정식 표현은 [0, 2^64) 무부호 10진 문자열" 이라는 규약은 그대로 지킨다.
 *
 *  · DB(MySQL BIGINT)는 부호 있는 64비트 → 저장 시 toSigned64()
 *  · 클라이언트/응답은 무부호 10진 문자열 → 조회 시 toU64()
 *  · 클라이언트는 bit 0~62 만 쓰므로 실제로는 항상 양수 범위다.
 * ============================================================ */

const TWO_POW_64 = 1n << 64n;
const TWO_POW_63 = 1n << 63n;
const MASK_64 = TWO_POW_64 - 1n;

// 임의의 10진 문자열(부호 가능)/숫자를 [0, 2^64) 무부호 10진 문자열로 정규화.
// 비어 있거나 형식이 이상하면 '0'. (원본 to_u64() 와 동일 규칙)
export function toU64(dec) {
  const s = String(dec ?? '').trim();
  if (s === '' || s === '-' || s === '+') return '0';
  if (!/^[+-]?[0-9]+$/.test(s)) return '0';

  // BigInt 는 임의 정밀도라 2^64 모듈러만 취하면 끝. 음수는 2의 보수로 넘어간다.
  let v = BigInt(s) & MASK_64;
  if (v < 0n) v += TWO_POW_64;
  return v.toString();
}

// 무부호 64비트 표현 → MySQL BIGINT(부호 있음) 저장용 10진 문자열.
// bit 63 이 켜져 있으면 음수로 변환(2의 보수). bit 0~62 만 쓰면 그대로 양수.
export function toSigned64(udec) {
  const v = BigInt(toU64(udec));
  return (v >= TWO_POW_63 ? v - TWO_POW_64 : v).toString();
}

// 비트 i(0~63) 가 켜져 있는지.
export function testBit(udec, i) {
  const bit = Number(i);
  if (!Number.isInteger(bit) || bit < 0 || bit > 63) return false;
  return ((BigInt(toU64(udec)) >> BigInt(bit)) & 1n) === 1n;
}

// 비트 i 토글(XOR).
export function toggleBit(udec, i) {
  const bit = Number(i);
  if (!Number.isInteger(bit) || bit < 0 || bit > 63) return toU64(udec);
  return (BigInt(toU64(udec)) ^ (1n << BigInt(bit))).toString();
}

// 64자리 2진 문자열(좌측 0패딩). 부호 비트까지 정확히 표현.
export function toBin64(value) {
  return BigInt(toU64(value)).toString(2).padStart(64, '0');
}

// 64자리 2진 문자열을 4자리씩 끊어 가독성 향상. 예: 0000 1011 0000 0101 ...
export function groupBin4(bin64) {
  return bin64.match(/.{1,4}/g).join(' ');
}

// 켜져 있는 비트 인덱스 목록 (오름차순).
export function listBits(value) {
  const v = BigInt(toU64(value));
  const out = [];
  for (let i = 0n; i < 64n; i++) {
    if (((v >> i) & 1n) === 1n) out.push(Number(i));
  }
  return out;
}
