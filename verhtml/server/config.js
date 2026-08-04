/* ============================================================
 *  config.js — 서버 설정
 * ------------------------------------------------------------
 *  원본 db_connect.php / admin_hub.php 의 설정값을 그대로 옮겨 온 것.
 *  실서비스에서는 환경변수로 덮어쓰는 것을 권장한다.
 *    예)  set SR_DB_PASS=... && npm start
 * ============================================================ */

import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

// verhtml/server → verhtml
export const APP_ROOT = path.resolve(__dirname, '..');
// verhtml → soulrush_api (원본 PHP 프로젝트 루트)
export const LEGACY_ROOT = path.resolve(APP_ROOT, '..');

export const PORT = Number(process.env.SR_PORT || 3000);

export const DB = {
  host: process.env.SR_DB_HOST || 'localhost',
  database: process.env.SR_DB_NAME || 'soulsusers',
  user: process.env.SR_DB_USER || 'root',
  password: process.env.SR_DB_PASS || 'rlacodnjs0801!',
  charset: 'utf8mb4',
};

// 관리자 대시보드 비밀번호 (원본 admin_hub.php 의 $admin_pw)
export const ADMIN_PASSWORD = process.env.SR_ADMIN_PW || 'admin1234';

// 관리자 토큰 유효 시간(밀리초). 기본 12시간.
export const ADMIN_TOKEN_TTL = Number(process.env.SR_ADMIN_TTL || 12 * 60 * 60 * 1000);

// 서버 로그 파일.
// 원본 logger.php 와 같은 파일을 쓰도록 soulrush_api 루트를 가리킨다
// → PHP 로 남은 기록과 Node 로 남는 기록이 한 파일에 이어서 쌓인다.
export const LOG_FILE = process.env.SR_LOG_FILE || path.join(LEGACY_ROOT, 'server_log.txt');

// 관리자 토큰 저장소 (서버를 재시작해도 로그인이 유지되도록 파일로 보관)
export const TOKEN_FILE = path.join(APP_ROOT, '_tokens.json');

// 정적 프런트(HTML/CSS/JS) 폴더
export const PUBLIC_DIR = path.join(APP_ROOT, 'public');
