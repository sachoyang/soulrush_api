/* ============================================================
 *  logger.js — 서버 로그 기록 (원본 logger.php 의 writeLog() 대응)
 * ------------------------------------------------------------
 *  원본과 같은 server_log.txt 에, 같은 한 줄 포맷으로 이어 쓴다.
 *    [YYYY-MM-DD HH:mm:ss] [IP: x.x.x.x] [ACTION] message
 *  → PHP 시절 기록과 Node 기록이 한 파일에서 그대로 이어진다.
 * ============================================================ */

import fs from 'node:fs';
import { LOG_FILE } from './config.js';

// PHP date('Y-m-d H:i:s') 와 동일하게 "서버 로컬 시간" 기준으로 찍는다.
function stamp() {
  const d = new Date();
  const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ` +
         `${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`;
}

// Express req 에서 접속 IP 추출. PHP 의 $_SERVER['REMOTE_ADDR'] 자리.
export function clientIp(req) {
  const raw = req?.ip || req?.socket?.remoteAddress || '';
  // Node 는 IPv4 를 IPv6 매핑(::ffff:127.0.0.1)으로 주는 경우가 있어 벗겨낸다.
  return raw.replace(/^::ffff:/, '');
}

export function writeLog(action, message, req) {
  const line = `[${stamp()}] [IP: ${clientIp(req)}] [${action}] ${message}\n`;
  try {
    fs.appendFileSync(LOG_FILE, line);
  } catch (e) {
    // 로그를 못 남긴다고 API 요청 자체를 실패시키지는 않는다(원본도 동일).
    console.error('[logger] 로그 기록 실패:', e.message);
  }
}
