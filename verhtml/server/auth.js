/* ============================================================
 *  auth.js — 비밀번호 해시 + 관리자 토큰
 * ------------------------------------------------------------
 *  ★ PHP 상호운용이 이 파일의 핵심이다.
 *
 *  [비밀번호]
 *   원본은 password_hash($pw, PASSWORD_DEFAULT) = bcrypt, 접두사 "$2y$".
 *   같은 DB 를 원본 PHP 와 이 Node 서버가 함께 쓰므로 양방향 호환이 필요하다.
 *
 *    · 검증: DB 의 "$2y$..." 를 bcryptjs 가 확실히 먹도록 "$2a$" 로 바꿔 비교한다.
 *    · 생성: bcryptjs 가 만든 "$2a$"/"$2b$" 를 "$2y$" 로 되돌려 저장한다.
 *      ⚠️ 이게 중요하다 — PHP 5.6 의 crypt_blowfish 는 $2a$/$2x$/$2y$ 만 알고
 *         "$2b$" 는 모른다. Node 가 $2b$ 로 저장해 버리면 원본 login.php 가
 *         그 유저의 비밀번호를 영영 검증하지 못한다.
 *      ($2a$/$2b$/$2y$ 는 알고리즘이 동일하며, 차이는 255바이트 이상 비밀번호의
 *       예외 처리뿐이라 실사용 범위에서는 접두사만 맞추면 완전히 호환된다)
 *
 *  [관리자 토큰]
 *   원본은 PHP 세션 쿠키($_SESSION['admin_logged_in'])를 썼지만,
 *   여기서는 토큰(X-Admin-Token 헤더) 방식이다.
 *   → 정적 프런트를 다른 주소에 올려도 크로스 오리진 쿠키 문제가 없다.
 * ============================================================ */

import fs from 'node:fs';
import crypto from 'node:crypto';
import bcrypt from 'bcryptjs';
import { ADMIN_PASSWORD, ADMIN_TOKEN_TTL, TOKEN_FILE } from './config.js';

// ---------- 비밀번호 (PHP password_hash / password_verify 대응) ----------

const BCRYPT_COST = 10; // PHP PASSWORD_DEFAULT 의 기본 cost 와 동일

export async function hashPassword(plain) {
  const hash = await bcrypt.hash(String(plain), BCRYPT_COST);
  // PHP 가 읽을 수 있도록 접두사를 $2y$ 로 통일해서 저장한다.
  return hash.replace(/^\$2[ab]\$/, '$2y$');
}

export async function verifyPassword(plain, hash) {
  if (!hash) return false;
  // bcryptjs 가 확실히 처리하는 $2a$ 로 바꿔서 비교한다.
  const normalized = String(hash).replace(/^\$2[by]\$/, '$2a$');
  try {
    return await bcrypt.compare(String(plain), normalized);
  } catch {
    // 해시 형식이 깨진 경우 (레거시/수동 편집 등) — 로그인 실패로 처리
    return false;
  }
}

// ---------- 관리자 토큰 ----------
// 파일 기반 저장소. 서버를 재시작해도 관리자 로그인이 유지된다.

function loadTokens() {
  try {
    const raw = fs.readFileSync(TOKEN_FILE, 'utf8');
    const obj = JSON.parse(raw);
    if (!obj || typeof obj !== 'object') return {};

    // 읽을 때마다 만료분을 걸러낸다.
    const now = Date.now();
    const alive = {};
    for (const [token, exp] of Object.entries(obj)) {
      if (Number(exp) > now) alive[token] = Number(exp);
    }
    return alive;
  } catch {
    return {}; // 파일이 없거나 깨졌으면 빈 상태에서 시작
  }
}

function saveTokens(tokens) {
  try {
    fs.writeFileSync(TOKEN_FILE, JSON.stringify(tokens));
  } catch (e) {
    console.error('[auth] 토큰 저장 실패:', e.message);
  }
}

export function checkAdminPassword(pw) {
  const a = Buffer.from(String(pw));
  const b = Buffer.from(ADMIN_PASSWORD);
  // 길이가 다르면 timingSafeEqual 이 던지므로 먼저 거른다.
  return a.length === b.length && crypto.timingSafeEqual(a, b);
}

export function issueToken() {
  const tokens = loadTokens();
  const token = crypto.randomBytes(32).toString('hex');
  tokens[token] = Date.now() + ADMIN_TOKEN_TTL;
  saveTokens(tokens);
  return token;
}

export function revokeToken(token) {
  if (!token) return;
  const tokens = loadTokens();
  delete tokens[token];
  saveTokens(tokens);
}

export function isTokenValid(token) {
  if (!token) return false;
  return Object.prototype.hasOwnProperty.call(loadTokens(), token);
}

// 요청에서 토큰 추출: 헤더 우선, 없으면 본문/쿼리.
export function tokenFromRequest(req) {
  const header = req.get('X-Admin-Token');
  if (header) return header.trim();
  return String(req.body?.token || req.query?.token || '').trim();
}

// 관리자 전용 라우트 가드 (Express 미들웨어).
// 원본에서 각 관리자 페이지 상단이 하던
//   if (!isset($_SESSION['admin_logged_in'])) header("Location: admin_hub.php")
// 역할. 프런트(js/api.js)는 status === 'unauthorized' 를 보면 로그인 화면으로 되돌린다.
export function requireAdmin(req, res, next) {
  if (!isTokenValid(tokenFromRequest(req))) {
    return res.status(401).json({ status: 'unauthorized', message: '관리자 인증이 필요합니다.' });
  }
  next();
}
