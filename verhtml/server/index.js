/* ============================================================
 *  index.js — Soul Rush API 서버 (Node.js)
 * ------------------------------------------------------------
 *  한 프로세스가 두 가지를 서빙한다.
 *    1) 게임 클라이언트(Unity)용 REST API   → routes/game.js  (원본 PHP 와 동일 경로/응답)
 *    2) 관리자 대시보드 (정적 HTML/CSS/JS + JSON API) → public/ + routes/admin.js
 *
 *  실행:  npm install  →  npm start
 *  접속:  http://localhost:3000/        (관리자 대시보드)
 *         http://localhost:3000/login.php  (게임 API — Unity 는 주소만 이쪽으로)
 * ============================================================ */

import express from 'express';
import multer from 'multer';
import { PORT, PUBLIC_DIR, LEGACY_ROOT, DB } from './config.js';
import { pool } from './db.js';
import { gameRouter } from './routes/game.js';
import { adminRouter } from './routes/admin.js';

const app = express();

// 프록시(Apache reverse proxy 등) 뒤에 놓일 때 실제 클라이언트 IP 를 잡기 위해.
// crash_report 의 유량 제어와 서버 로그의 IP 가 이 값을 쓴다.
app.set('trust proxy', true);

// ---------- CORS ----------
// 관리자 프런트를 다른 주소에서 띄우거나, 브라우저/에디터에서 API 를 직접 찔러도 되도록 연다.
// 인증은 쿠키가 아니라 X-Admin-Token 헤더라서 크로스 오리진에서도 문제가 없다.
app.use((req, res, next) => {
  res.setHeader('Access-Control-Allow-Origin', req.headers.origin || '*');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type, X-Admin-Token');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
  res.setHeader('Access-Control-Max-Age', '86400');
  if (req.method === 'OPTIONS') return res.sendStatus(204);
  next();
});

// ---------- 본문 파싱 ----------
// Unity 의 UnityWebRequest 는 보내는 방식에 따라 본문 형식이 다르다.
//   WWWForm                       → multipart/form-data
//   Dictionary / 직접 만든 문자열 → application/x-www-form-urlencoded
//   새 클라이언트/관리자 프런트   → application/json
// PHP 의 $_POST 는 앞의 둘을 알아서 처리해 줬으므로, 세 가지를 모두 받아 req.body 로 통일한다.
app.use(express.json({ limit: '2mb' }));
app.use(express.urlencoded({ extended: true, limit: '2mb' }));
app.use(multer().none()); // multipart/form-data 의 텍스트 필드 → req.body

// ---------- 관리자 대시보드 (정적 파일) ----------
app.use(express.static(PUBLIC_DIR, { extensions: ['html'] }));

// ---------- API ----------
app.use('/api', adminRouter); // 관리자용
app.use('/', gameRouter);     // 게임용 (login.php 등 원본과 같은 경로)

// ---------- 404 ----------
app.use((req, res) => {
  res.status(404).json({ status: 'error', message: '없는 경로입니다: ' + req.path });
});

// ---------- 에러 핸들러 ----------
// 어떤 실패든 HTML 에러 페이지 대신 JSON 으로 응답한다.
// (클라이언트가 JSON 파싱만 하므로, HTML 이 튀어나오면 원인 파악이 어려워진다)
app.use((err, req, res, _next) => {
  console.error('[error]', req.method, req.path, '-', err.message);

  // multer: 예상치 못한 파일 필드
  if (err?.code === 'LIMIT_UNEXPECTED_FILE') {
    return res.status(400).json({ status: 'error', message: '파일 업로드는 지원하지 않습니다.' });
  }
  res.status(500).json({ status: 'error', message: '서버 에러: ' + err.message });
});

// ---------- 기동 ----------
// DB 를 먼저 찔러 보고, 안 되면 조용히 뜨는 대신 원인을 명확히 알려준다.
try {
  const conn = await pool.getConnection();
  conn.release();
  console.log(`[DB] 연결 성공 — ${DB.user}@${DB.host}/${DB.database}`);
} catch (e) {
  console.error(`[DB] 연결 실패 — ${DB.user}@${DB.host}/${DB.database}`);
  console.error('     ' + e.message);
  console.error('     MySQL 이 켜져 있는지, server/config.js 의 접속 정보가 맞는지 확인하세요.');
  process.exit(1);
}

app.listen(PORT, () => {
  console.log('');
  console.log('  Soul Rush API (Node.js)');
  console.log('  ────────────────────────────────────────────');
  console.log(`  관리자 대시보드 : http://localhost:${PORT}/`);
  console.log(`  게임 API        : http://localhost:${PORT}/login.php  등`);
  console.log(`  서버 로그 파일  : ${LEGACY_ROOT}\\server_log.txt`);
  console.log('  ────────────────────────────────────────────');
  console.log('');
});
