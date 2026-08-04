/* ============================================================
 *  routes/game.js — 게임 클라이언트(Unity)용 REST API
 * ------------------------------------------------------------
 *  ★ 원본 PHP 와 "경로 · 파라미터 · 응답 JSON" 을 그대로 맞춘 포팅이다.
 *    경로도 login.php / get_abilities.php 처럼 .php 확장자까지 유지했다.
 *    → Unity 쪽은 서버 주소(호스트:포트)만 바꾸면 코드 수정 없이 붙는다.
 *      (확장자 없는 /login, /get_abilities 별칭도 함께 등록해 둔다)
 *
 *  대응표
 *    login.php             → POST /login.php
 *    register.php          → POST /register.php
 *    check_session.php     → POST /check_session.php
 *    check_admin.php       → POST /check_admin.php
 *    update_skills.php     → POST /update_skills.php
 *    get_abilities.php     → GET  /get_abilities.php
 *    get_rankings.php      → GET  /get_rankings.php?limit=
 *    submit_ranking.php    → POST /submit_ranking.php
 *    upload_ability.php    → POST /upload_ability.php
 *    crash_report.php      → POST /crash_report.php
 *    crash_report_list.php → GET  /crash_report_list.php?limit=&type=
 * ============================================================ */

import express from 'express';
import crypto from 'node:crypto';
import { pool, query, queryOne, execute, isDuplicateError } from '../db.js';
import { toU64, toSigned64 } from '../mask.js';
import { hashPassword, verifyPassword } from '../auth.js';
import { writeLog, clientIp } from '../logger.js';
import { abilityValidate, abilitySave, fetchAbilities } from '../ability_write.js';

export const gameRouter = express.Router();

// ---- 확장자 없는 별칭 (/login → /login.php) ----
// 기존 Unity 코드는 .php 경로를 그대로 쓰면 되고,
// 새로 붙이는 클라이언트는 확장자 없는 깔끔한 경로를 쓸 수 있게 열어 둔다.
const GAME_ENDPOINTS = [
  'login', 'register', 'check_session', 'check_admin', 'update_skills',
  'get_abilities', 'get_rankings', 'submit_ranking', 'upload_ability',
  'crash_report', 'crash_report_list',
];

gameRouter.use((req, res, next) => {
  const name = req.path.replace(/^\//, '');
  if (GAME_ENDPOINTS.includes(name)) {
    // req.url 에는 쿼리스트링이 붙어 있을 수 있으므로 경로 부분만 바꾼다.
    req.url = '/' + name + '.php' + req.url.slice(req.path.length);
  }
  next();
});

// ---------- 요청 파라미터 헬퍼 ----------

// $_POST['x'] / $_GET['x'] 자리. 항상 문자열로 돌려준다.
function p(req, key, def = '') {
  const v = req.body?.[key] ?? req.query?.[key];
  return v === undefined || v === null ? def : String(v);
}

// PHP empty() 의 의미를 그대로 옮긴 것.
// ⚠️ PHP 는 문자열 "0" 도 empty 로 친다. 원본이 empty() 로 막던 자리를
//    JS 의 !v 로 바꾸면 동작이 미묘하게 달라지므로 그대로 재현한다.
function phpEmpty(v) {
  return v === undefined || v === null || v === '' || v === '0';
}

// PHP (int) / (float) 캐스팅 — 숫자로 못 읽으면 0.
const int = (v, def = 0) => { const n = parseInt(v, 10); return Number.isNaN(n) ? def : n; };

// ---------- 세션 토큰 ----------
// 원본: md5(uniqid(mt_rand(), true)) → 32자리 hex.
// 컬럼 폭이 동일하도록 길이 32 hex 를 유지하되, 난수는 암호학적으로 안전한 것을 쓴다.
function newSessionToken() {
  return crypto.randomBytes(16).toString('hex');
}

/* ============================================================
 *  login.php — 로그인
 * ============================================================ */
gameRouter.post('/login.php', async (req, res) => {
  const login_id = p(req, 'login_id');
  const password = p(req, 'password');

  if (phpEmpty(login_id) || phpEmpty(password)) {
    return res.json({ status: 'error', message: '아이디나 비밀번호가 비어있습니다.' });
  }

  try {
    const user = await queryOne('SELECT * FROM user_data WHERE login_id = ?', [login_id]);

    if (user && await verifyPassword(password, user.password_hash)) {
      // 중복 접속 방지용 고유 세션 토큰 생성 및 DB 저장
      const session_token = newSessionToken();
      await execute('UPDATE user_data SET session_token = ? WHERE login_id = ?', [session_token, login_id]);

      // [기본 지급 일원화] default_mask 자동 보정 없음.
      //   unlocked_skills 에는 유저가 직접 해금한 스킬만 담긴다(0으로 시작 가능).
      //   "모든 유저 기본 지급"은 abilities.basic_skill 전역 플래그가 전담하며,
      //   클라가 (basic_skill==1) OR (유저 bit) 로 최종 풀을 계산한다.
      const unlocked_skills = toU64(user.unlocked_skills);

      // is_admin: 릴리즈 빌드 디버그 게이트(F5 보스 킬 등) 서버 권위 판정값.
      const is_admin = user.is_admin == null ? 0 : Number(user.is_admin);

      writeLog('LOGIN_SUCCESS', 'User: ' + login_id, req);

      // unlocked_skills 는 숫자 "문자열" 로 내려준다.
      //   64비트 값을 JSON 숫자로 내리면 JS/파서 쪽에서 정밀도가 깨진다
      //   (9223372036854775807 → 9.2233720368548e+18). 클라이언트는 long 으로 파싱한다.
      return res.json({
        status: 'success',
        message: '로그인 성공',
        nickname: user.nickname,
        unlocked_skills,          // BIGINT(64비트) 숫자문자열
        session_token,
        is_admin,                 // 0/1 (JSON 숫자)
      });
    }

    writeLog('LOGIN_FAIL', 'Attempted ID: ' + login_id, req);
    return res.json({ status: 'error', message: '아이디 또는 비밀번호가 틀렸습니다.' });

  } catch (e) {
    return res.json({ status: 'error', message: '서버 에러: ' + e.message });
  }
});

/* ============================================================
 *  register.php — 회원가입
 * ============================================================ */
gameRouter.post('/register.php', async (req, res) => {
  const login_id = p(req, 'login_id');
  const password = p(req, 'password');
  const nickname = p(req, 'nickname');

  if (phpEmpty(login_id) || phpEmpty(password) || phpEmpty(nickname)) {
    return res.json({ status: 'error', message: '모든 필드를 입력해주세요.' });
  }

  try {
    // 비밀번호 단방향 해시. 원본 PHP 가 읽을 수 있도록 $2y$ bcrypt 로 저장된다(auth.js 참고).
    const password_hash = await hashPassword(password);

    // 신규 유저의 unlocked_skills 는 항상 0 으로 시작한다.
    await execute(
      'INSERT INTO user_data (login_id, password_hash, nickname, unlocked_skills) VALUES (?, ?, ?, 0)',
      [login_id, password_hash, nickname]
    );

    return res.json({ status: 'success', message: '회원가입이 완료되었습니다.' });

  } catch (e) {
    // UNIQUE 제약 조건(중복) 위반
    if (isDuplicateError(e)) {
      return res.json({ status: 'error', message: '이미 존재하는 아이디 또는 닉네임입니다.' });
    }
    return res.json({ status: 'error', message: '서버 에러: ' + e.message });
  }
});

/* ============================================================
 *  check_session.php — 하트비트(중복 접속 감지) + admin 동시 확인
 *    ⚠️ 세션 무효 응답의 "invalid" 문자열은 클라 하트비트 규칙이므로 절대 바꾸지 말 것.
 * ============================================================ */
gameRouter.post('/check_session.php', async (req, res) => {
  const login_id = p(req, 'login_id');
  const session_token = p(req, 'session_token');

  if (phpEmpty(login_id) || phpEmpty(session_token)) {
    return res.json({ status: 'error', message: '데이터 부족' });
  }

  try {
    const user = await queryOne('SELECT session_token, is_admin FROM user_data WHERE login_id = ?', [login_id]);

    if (user && user.session_token === session_token) {
      return res.json({ status: 'valid', is_admin: Number(user.is_admin) });
    }
    return res.json({ status: 'invalid', is_admin: 0 });

  } catch {
    return res.json({ status: 'error' });
  }
});

/* ============================================================
 *  check_admin.php — admin(디버그 권한) 서버 권위 검증
 *    F5 보스 강제 킬처럼 네트워크로 남을 죽이는 조작은, 호스트가 요청자가
 *    진짜 admin 인지 서버에 되물어 확정한다(클라 하드코딩 목록 제거).
 * ============================================================ */
gameRouter.post('/check_admin.php', async (req, res) => {
  const login_id = p(req, 'login_id');
  const session_token = p(req, 'session_token');

  try {
    const row = await queryOne('SELECT session_token, is_admin FROM user_data WHERE login_id = ?', [login_id]);

    if (!row || row.session_token !== session_token) {
      return res.json({ status: 'invalid', is_admin: 0 });
    }
    return res.json({ status: 'success', is_admin: Number(row.is_admin) });

  } catch {
    // 세션 무효 규칙과 동일하게 안전한 실패(권한 없음)로 응답
    return res.json({ status: 'invalid', is_admin: 0 });
  }
});

/* ============================================================
 *  update_skills.php — 해금 스킬 비트마스크 저장
 * ============================================================ */
gameRouter.post('/update_skills.php', async (req, res) => {
  const login_id = p(req, 'login_id');
  const session_token = p(req, 'session_token');
  const unlocked_skills = p(req, 'unlocked_skills');

  // ⚠️ unlocked_skills 만 empty() 가 아니라 === '' 검사다.
  //    "0"(스킬 없음)은 정상 값이므로 막으면 안 된다. 원본 그대로 유지.
  if (phpEmpty(login_id) || phpEmpty(session_token) || unlocked_skills === '') {
    return res.json({ status: 'error', message: '요청 데이터가 부족합니다.' });
  }

  try {
    // DB에 저장된 현재 토큰이 일치하는지 확인 (중복 접속 튕겨내기)
    const user = await queryOne('SELECT session_token FROM user_data WHERE login_id = ?', [login_id]);

    if (!user || user.session_token !== session_token) {
      return res.json({ status: 'error', message: '다른 기기에서 접속하여 연결이 끊어졌습니다.' });
    }

    // 클라이언트가 보낸 값을 무부호 64비트로 정규화한 뒤, BIGINT 저장용 부호 표현으로 변환.
    const skills_u64 = toU64(unlocked_skills);
    const skills_db = toSigned64(skills_u64);
    await execute('UPDATE user_data SET unlocked_skills = ? WHERE login_id = ?', [skills_db, login_id]);

    return res.json({
      status: 'success',
      message: '스킬 데이터가 성공적으로 저장되었습니다.',
      updated_skills: skills_u64, // 숫자문자열로 반환 (클라이언트는 long 파싱)
    });

  } catch (e) {
    return res.json({ status: 'error', message: '데이터 저장 실패: ' + e.message });
  }
});

/* ============================================================
 *  get_abilities.php — 스킬 카탈로그 조회 (7테이블 → 한 스킬=한 오브젝트)
 *    클라 파싱 대상: AbilityDBResponse → List<AbilityDBData>
 *    JSON 키 규칙: DB의 is_basic_skill/is_unlocked → 응답은 basic_skill/unlocked_skill.
 * ============================================================ */
gameRouter.get('/get_abilities.php', async (req, res) => {
  const conn = await pool.getConnection();
  try {
    const data = await fetchAbilities(conn, { adminKeys: false });
    return res.json({ status: 'success', data });
  } catch (e) {
    return res.json({ status: 'error', message: 'DB 에러: ' + e.message });
  } finally {
    conn.release();
  }
});

/* ============================================================
 *  get_rankings.php — 팀 랭킹 조회 (상위 limit 팀)
 *    정렬: clear_time_seconds ASC, cleared_at ASC. rank 는 1부터 서버가 매김.
 * ============================================================ */
gameRouter.get('/get_rankings.php', async (req, res) => {
  let limit = int(p(req, 'limit', '10'), 10);
  if (limit <= 0 || limit > 100) limit = 10;

  try {
    // LIMIT 은 파라미터 바인딩 시 문자열로 인용되는 문제가 있어, 위에서 정수로 클램프한 값을 직접 박는다.
    const rows = await query(
      `SELECT team_name, clear_time_seconds, cleared_level, total_damage, players_json,
              DATE_FORMAT(cleared_at, '%Y-%m-%d %H:%i:%s') AS cleared_at
       FROM team_rankings
       ORDER BY clear_time_seconds ASC, cleared_at ASC
       LIMIT ${limit}`
    );

    const data = rows.map((r, i) => {
      // players_json({"members":[...]}) 에서 members 배열만 꺼내 중첩으로 그대로 내보낸다
      let members = [];
      try {
        const pj = JSON.parse(r.players_json || '');
        if (pj && Array.isArray(pj.members)) members = pj.members;
      } catch { /* 깨진 JSON 은 빈 배열 */ }

      return {
        rank: i + 1,
        team_name: String(r.team_name),
        clear_time_seconds: Number(r.clear_time_seconds),
        cleared_level: Number(r.cleared_level),
        total_damage: Number(r.total_damage),
        // 숫자/문자 타입 보정
        members: members.map((m) => ({
          nickname: String(m?.nickname ?? ''),
          damage: int(m?.damage, 0),
        })),
        cleared_at: String(r.cleared_at),
      };
    });

    return res.json({ status: 'success', message: '', data });

  } catch (e) {
    return res.json({ status: 'fail', message: 'DB 에러: ' + e.message, data: [] });
  }
});

/* ============================================================
 *  submit_ranking.php — 팀 랭킹 등록 (3인 협동 클리어 시 방장이 1번만 호출)
 * ============================================================ */
gameRouter.post('/submit_ranking.php', async (req, res) => {
  const login_id = p(req, 'login_id');
  const session_token = p(req, 'session_token');
  const team_name = p(req, 'team_name', 'Unknown');
  const clear_time = int(p(req, 'clear_time_seconds', '0'));
  const cleared_level = int(p(req, 'cleared_level', '0'));
  const party_size = int(p(req, 'party_size', '3'), 3);
  const total_damage = int(p(req, 'total_damage', '0'));
  let players_json = p(req, 'players_json', '{"members":[]}');

  // 기본 방어: 3인 기록만, 비정상 시간 컷(0 이하 / 24h 초과)
  if (party_size !== 3 || clear_time <= 0 || clear_time > 86400) {
    return res.json({ status: 'fail', message: 'invalid record', rank: 0 });
  }

  try {
    // 세션 검증(check_session 과 동일 로직). 유효하지 않으면 등록 거부.
    if (login_id !== '' && session_token !== '') {
      const u = await queryOne('SELECT session_token FROM user_data WHERE login_id = ?', [login_id]);
      if (!u || u.session_token !== session_token) {
        return res.json({ status: 'invalid', message: '세션이 유효하지 않습니다.', rank: 0 });
      }
    }

    // players_json 이 올바른 JSON 인지 가볍게 검증(깨졌으면 빈 배열로 대체)
    try {
      if (JSON.parse(players_json) === null) players_json = '{"members":[]}';
    } catch {
      players_json = '{"members":[]}';
    }

    await execute(
      `INSERT INTO team_rankings
          (login_id, team_name, clear_time_seconds, cleared_level, party_size, total_damage, players_json)
       VALUES (?, ?, ?, ?, ?, ?, ?)`,
      [login_id !== '' ? login_id : null, team_name, clear_time, cleared_level, party_size, total_damage, players_json]
    );

    // 방금 등록한 팀 순위: 나보다 빠른(작은) 기록 수 + 1
    const r = await queryOne('SELECT COUNT(*) + 1 AS r FROM team_rankings WHERE clear_time_seconds < ?', [clear_time]);

    return res.json({ status: 'success', message: '기록이 등록되었습니다.', rank: Number(r.r) });

  } catch (e) {
    return res.json({ status: 'fail', message: 'DB 에러: ' + e.message, rank: 0 });
  }
});

/* ============================================================
 *  upload_ability.php — 스킬 업로드 (Unity 에디터 AbilityUploadWindow → 서버)
 *    한 번에 스킬 1개. 검증/저장은 ability_write.js 공용 로직에 위임.
 * ============================================================ */
gameRouter.post('/upload_ability.php', async (req, res) => {
  // 공통 필드 (JSON 키 basic_skill/unlocked_skill → is_basic_skill/is_unlocked)
  const common = {
    ability_id: p(req, 'ability_id').trim(),
    ability_type: p(req, 'ability_type', 'Passive'),
    bit_index: int(p(req, 'bit_index', '0')),
    display_name: p(req, 'display_name'),
    description: p(req, 'description'),
    appear_stage: int(p(req, 'appear_stage', '1'), 1),
    is_basic_skill: int(p(req, 'basic_skill', '0')),
    is_unlocked: int(p(req, 'unlocked_skill', '0')),
    max_level: int(p(req, 'max_level', '1'), 1),
    cooldown_seconds: parseFloat(p(req, 'cooldown_seconds', '0')) || 0,
    stamina_cost: parseFloat(p(req, 'stamina_cost', '0')) || 0,
    special_effect: p(req, 'special_effect', 'None'),
  };

  const err = abilityValidate(common);
  if (err !== '') {
    return res.json({ status: 'fail', message: err });
  }

  // levels_json 파싱: {"levels":[ {...}, ... ]}
  let levels = [];
  try {
    const parsed = JSON.parse(p(req, 'levels_json', '{"levels":[]}'));
    if (parsed && Array.isArray(parsed.levels)) levels = parsed.levels;
  } catch { /* 깨진 JSON 은 빈 레벨 */ }

  try {
    await abilitySave(common, levels);
    return res.json({ status: 'success', message: '업로드 성공: ' + common.ability_id });
  } catch (e) {
    return res.json({ status: 'fail', message: 'DB 에러: ' + e.message });
  }
});

/* ============================================================
 *  crash_report.php — 크래시 리포트 수집
 *    · 인증 불필요: 로그인 전 크래시도 익명으로 수집한다.
 *    · client_report_id 로 재전송 중복을 차단하되, 중복은 반드시 status="duplicate" 로.
 *      (여기서 500을 던지면 클라가 그 리포트를 영원히 재전송한다)
 * ============================================================ */

// TEXT 컬럼은 64KB(바이트) 제한. 바이트로 자르되 끝의 깨진 멀티바이트 조각을 떼어낸다.
function cutBytes(s, maxBytes) {
  const buf = Buffer.from(s, 'utf8');
  if (buf.length <= maxBytes) return s;
  // Buffer → 문자열 변환 시 잘린 꼬리 바이트는 U+FFFD 가 되므로, 그 조각만 제거한다.
  let out = buf.subarray(0, maxBytes).toString('utf8');
  if (out.endsWith('�')) out = out.slice(0, -1);
  return out;
}

// VARCHAR 은 "문자" 수 기준. 한글 1자 = 1문자로 세야 한다.
// [...s] 로 코드포인트 단위 분해 → PHP mb_substr 과 같은 기준이 된다.
function cutChars(s, maxChars) {
  const chars = [...s];
  return chars.length <= maxChars ? s : chars.slice(0, maxChars).join('');
}

// occurred_at 은 UTC "yyyy-MM-dd HH:mm:ss" 고정.
// 형식이 깨지면 DB가 0000-00-00 으로 먹으므로 여기서 컷한다.
// (PHP DateTime::createFromFormat 후 재포맷 비교와 동일하게, 실제로 존재하는 날짜인지까지 본다)
function isValidDateTime(s) {
  const m = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/.exec(s);
  if (!m) return false;
  const [, y, mo, d, h, mi, sec] = m.map(Number);
  if (mo < 1 || mo > 12 || d < 1 || d > 31 || h > 23 || mi > 59 || sec > 59) return false;
  const dt = new Date(Date.UTC(y, mo - 1, d, h, mi, sec));
  return dt.getUTCFullYear() === y && dt.getUTCMonth() === mo - 1 && dt.getUTCDate() === d;
}

gameRouter.post('/crash_report.php', async (req, res) => {
  const respond = (status, message) => res.json({ status, message });

  const client_report_id = p(req, 'client_report_id').trim();
  const report_type = p(req, 'report_type').trim();
  let message = p(req, 'message'); // 줄바꿈 보존 (trim 금지)
  const occurred_at = p(req, 'occurred_at').trim();

  // --- 1. 필수 필드 ---
  if (client_report_id === '' || report_type === '' || message === '' || occurred_at === '') {
    return respond('fail', '필수 필드 누락');
  }

  // --- 2. report_type 화이트리스트 ---
  if (!['exception', 'unhandled', 'native_crash'].includes(report_type)) {
    return respond('fail', '알 수 없는 report_type');
  }

  if (!isValidDateTime(occurred_at)) {
    return respond('fail', 'occurred_at 형식 오류');
  }

  let login_id = cutChars(p(req, 'login_id').trim(), 50);
  let nickname = cutChars(p(req, 'nickname').trim(), 50);
  const session_token = p(req, 'session_token').trim();
  const client_ip = clientIp(req);

  // ram_mb 는 INT UNSIGNED 이므로 음수 방어.
  let ram_mb = int(p(req, 'ram_mb', '0'));
  if (ram_mb < 0) ram_mb = 0;

  const stack_trace = cutBytes(p(req, 'stack_trace'), 60000);
  const log_tail = cutBytes(p(req, 'log_tail'), 60000);

  // VARCHAR 길이 컷 (strict mode 에서 초과 시 INSERT 자체가 실패한다)
  message = cutChars(message, 1000);
  const scene = cutChars(p(req, 'scene').trim(), 100);
  const app_version = cutChars(p(req, 'app_version').trim(), 30);
  const unity_ver = cutChars(p(req, 'unity_version').trim(), 30);
  const platform = cutChars(p(req, 'platform').trim(), 40);
  const device_model = cutChars(p(req, 'device_model').trim(), 200);
  const gpu = cutChars(p(req, 'gpu').trim(), 200);

  try {
    // --- 3. 유량 제어: 같은 IP 1분에 20건 초과면 거부 ---
    if (client_ip !== '') {
      const row = await queryOne(
        `SELECT COUNT(*) AS c FROM crash_reports
         WHERE client_ip = ? AND received_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)`,
        [client_ip]
      );
      if (Number(row.c) >= 20) {
        return respond('fail', 'rate limited');
      }
    }

    // --- 4. 세션 검증 (토큰이 있을 때만). 불일치해도 리포트는 버리지 않고 익명으로 강등 ---
    // 토큰이 비었으면(로그인 전 크래시) 검증 없이 그대로 저장한다. 크래시 수집은 인증이 필수가 아니다.
    if (session_token !== '') {
      const u = await queryOne('SELECT session_token FROM user_data WHERE login_id = ?', [login_id]);
      if (!u || u.session_token !== session_token) {
        login_id = '';
        nickname = '';
      }
    }

    // --- 5. INSERT. UNIQUE 위반이면 duplicate ---
    const orNull = (v) => (v !== '' ? v : null);
    await execute(
      `INSERT INTO crash_reports
          (client_report_id, report_type, login_id, nickname, message, stack_trace, log_tail,
           scene, app_version, unity_version, platform, device_model, gpu, ram_mb, client_ip, occurred_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
      [
        client_report_id, report_type, orNull(login_id), orNull(nickname), message,
        orNull(stack_trace), orNull(log_tail), orNull(scene), orNull(app_version), orNull(unity_ver),
        orNull(platform), orNull(device_model), orNull(gpu), ram_mb, orNull(client_ip), occurred_at,
      ]
    );

    return respond('success', '리포트 저장됨');

  } catch (e) {
    // 무결성 제약 위반 = uk_report(client_report_id) 재전송 중복.
    if (isDuplicateError(e)) {
      return respond('duplicate', '이미 수집된 리포트');
    }
    return respond('fail', 'DB 에러: ' + e.message);
  }
});

/* ============================================================
 *  crash_report_list.php — 크래시 리포트 목록 조회 (요약 필드)
 * ============================================================ */
gameRouter.get('/crash_report_list.php', async (req, res) => {
  let limit = int(p(req, 'limit', '50'), 50);
  if (limit < 1) limit = 1;
  if (limit > 200) limit = 200;

  const type = p(req, 'type').trim();
  if (type !== '' && !['exception', 'unhandled', 'native_crash'].includes(type)) {
    return res.json({ status: 'fail', message: '알 수 없는 type', reports: [] });
  }

  try {
    let sql = `SELECT id, report_type, nickname, message, scene, app_version, gpu,
                      DATE_FORMAT(occurred_at, '%Y-%m-%d %H:%i:%s') AS occurred_at
               FROM crash_reports`;
    const params = [];
    if (type !== '') {
      sql += ' WHERE report_type = ?';
      params.push(type);
    }
    // LIMIT 은 위에서 정수 클램프한 값을 직접 박는다.
    sql += ` ORDER BY occurred_at DESC, id DESC LIMIT ${limit}`;

    const reports = await query(sql, params);
    reports.forEach((r) => { r.id = Number(r.id); });

    return res.json({ status: 'success', message: '', reports });

  } catch (e) {
    return res.json({ status: 'fail', message: 'DB 에러: ' + e.message, reports: [] });
  }
});
