/* ============================================================
 *  routes/admin.js — 관리자 대시보드용 API
 * ------------------------------------------------------------
 *  원본에서 PHP 가 DB 조회 + HTML 렌더링을 한꺼번에 하던 5개 화면을
 *  "JSON API + 정적 HTML/CSS/JS" 로 분리한 것.
 *
 *    admin_hub.php       → POST /api/admin/login, /api/admin/logout
 *    log_viewer.php      → GET  /api/users, /api/server-log · POST /api/users/action
 *    ability_manager.php → GET  /api/abilities · POST /api/abilities/save, /delete
 *    ranking_viewer.php  → GET  /api/rankings · POST /api/rankings/delete
 *    crash_viewer.php    → GET  /api/crashes  · POST /api/crashes/action
 *
 *  알림 문구(message)는 원본 화면에 뜨던 문장을 그대로 돌려준다.
 * ============================================================ */

import express from 'express';
import fs from 'node:fs';
import { pool, query, queryOne, execute } from '../db.js';
import { LOG_FILE } from '../config.js';
import {
  checkAdminPassword, issueToken, revokeToken, requireAdmin, tokenFromRequest,
} from '../auth.js';
import { hashPassword } from '../auth.js';
import {
  abilityValidate, abilitySave, abilityDelete, fetchAbilities, av,
} from '../ability_write.js';

export const adminRouter = express.Router();

const int = (v, def = 0) => { const n = parseInt(v, 10); return Number.isNaN(n) ? def : n; };
const flt = (v, def = 0) => { const n = parseFloat(v); return Number.isNaN(n) ? def : n; };

const ok = (res, extra = {}) => res.json({ status: 'success', message: '', ...extra });
const fail = (res, message, extra = {}) => res.json({ status: 'error', message, ...extra });

/* ============================================================
 *  인증 — 원본 admin_hub.php 의 비밀번호 게이트
 * ============================================================ */

adminRouter.post('/admin/login', (req, res) => {
  const pw = String(req.body?.pw ?? '');
  if (pw === '') return fail(res, '비밀번호를 입력하세요.');
  if (!checkAdminPassword(pw)) return fail(res, '비밀번호가 틀렸습니다.');
  return ok(res, { token: issueToken() });
});

adminRouter.post('/admin/logout', (req, res) => {
  revokeToken(tokenFromRequest(req));
  return ok(res);
});

// 각 관리자 페이지가 진입할 때 호출. 원본에서 페이지 상단의 세션 체크 역할.
adminRouter.get('/admin/check', requireAdmin, (req, res) => ok(res));

// 이 아래 모든 라우트는 관리자 인증 필수.
adminRouter.use(requireAdmin);

/* ============================================================
 *  서버 로그 — 원본 log_viewer.php 상단 로그 패널
 * ============================================================ */
adminRouter.get('/server-log', (req, res) => {
  try {
    if (!fs.existsSync(LOG_FILE)) {
      return ok(res, { log: '아직 기록된 로그가 없습니다.' });
    }
    return ok(res, { log: fs.readFileSync(LOG_FILE, 'utf8') });
  } catch (e) {
    return fail(res, '로그 파일을 읽을 수 없습니다: ' + e.message);
  }
});

/* ============================================================
 *  유저 관리 — 원본 log_viewer.php 의 user_data 표
 * ============================================================ */
adminRouter.get('/users', async (req, res) => {
  // 보유 스킬 모달에서 쓸 bit_index → display_name 매핑
  let ability_map = {};
  try {
    const abs = await query('SELECT bit_index, display_name FROM abilities ORDER BY bit_index ASC');
    for (const ab of abs) ability_map[Number(ab.bit_index)] = String(ab.display_name);
  } catch {
    // 능력 테이블 조회 실패 시 매핑은 비워둔다 (모달은 비트 인덱스만 표시) — 원본과 동일
    ability_map = {};
  }

  try {
    const rows = await query(
      `SELECT idx, login_id, nickname, unlocked_skills, is_admin,
              DATE_FORMAT(created_at, '%m-%d %H:%i') AS created_at
       FROM user_data
       ORDER BY idx DESC`
    );

    const users = rows.map((u) => ({
      idx: Number(u.idx),
      login_id: String(u.login_id),
      nickname: String(u.nickname),
      // 🔴 문자열 유지! 64비트 BIGINT 를 JS Number 로 만들면 값이 뭉개진다.
      unlocked_skills: String(u.unlocked_skills),
      is_admin: Number(u.is_admin),
      created_at: String(u.created_at),
    }));

    return ok(res, { users, ability_map });

  } catch (e) {
    return fail(res, 'DB 조회 에러: ' + e.message, { users: [], ability_map: {} });
  }
});

adminRouter.post('/users/action', async (req, res) => {
  const action = String(req.body?.action ?? '');
  const target_idx = int(req.body?.target_idx, 0);

  if (target_idx <= 0) return fail(res, '대상 유저(target_idx)가 지정되지 않았습니다.');

  try {
    if (action === 'delete') {
      await execute('DELETE FROM user_data WHERE idx = ?', [target_idx]);
      return ok(res, {
        message: `[알림] 유저(IDX: ${target_idx})가 삭제되었습니다.`,
        tone: 'danger',
      });
    }

    if (action === 'reset') {
      // 프런트에서 입력받은 새 비밀번호. 없으면 원본과 동일하게 0000.
      let new_pw = String(req.body?.new_pw ?? '0000').trim();
      if (new_pw === '') new_pw = '0000';

      // 원본 PHP 도 읽을 수 있는 $2y$ bcrypt 로 저장된다(auth.js 참고).
      const hash = await hashPassword(new_pw);
      await execute('UPDATE user_data SET password_hash = ? WHERE idx = ?', [hash, target_idx]);

      return ok(res, {
        message: `[알림] 유저(IDX: ${target_idx})의 비밀번호가 [${new_pw}] (으)로 초기화되었습니다.`,
        tone: 'good',
      });
    }

    if (action === 'toggle_admin') {
      // is_admin 0↔1 토글. admin 계정만 릴리즈 디버그 기능(F5 보스 킬 등) 사용 가능.
      await execute('UPDATE user_data SET is_admin = 1 - is_admin WHERE idx = ?', [target_idx]);

      const row = await queryOne('SELECT login_id, is_admin FROM user_data WHERE idx = ?', [target_idx]);
      const state = row && Number(row.is_admin) === 1 ? '부여됨(1)' : '해제됨(0)';
      const who = row ? row.login_id : `IDX ${target_idx}`;

      return ok(res, {
        message: `[알림] 유저 [${who}] 의 admin 권한이 ${state} 상태가 되었습니다.`,
        tone: 'info',
        is_admin: row ? Number(row.is_admin) : 0,
      });
    }

    return fail(res, '알 수 없는 action: ' + action);

  } catch (e) {
    // toggle_admin 실패는 대개 is_admin 컬럼 부재 — 원본과 동일한 안내를 붙인다.
    const hint = action === 'toggle_admin' ? ' (migration_add_is_admin.sql 실행 여부 확인)' : '';
    return fail(res, `${action} 에러: ${e.message}${hint}`);
  }
});

/* ============================================================
 *  스킬 관리 — 원본 ability_manager.php
 * ============================================================ */
adminRouter.get('/abilities', async (req, res) => {
  const conn = await pool.getConnection();
  try {
    // 관리 화면은 DB 컬럼명 그대로(is_basic_skill / is_unlocked) 받는다.
    const abilities = await fetchAbilities(conn, { adminKeys: true });
    return ok(res, { abilities });
  } catch (e) {
    return fail(res,
      '[오류] 목록 로드 실패: ' + e.message + ' (마이그레이션 migration_skill_tables_v2.sql 실행 여부 확인)',
      { abilities: [] });
  } finally {
    conn.release();
  }
});

adminRouter.post('/abilities/save', async (req, res) => {
  const body = req.body ?? {};
  const type = String(av(body, 'ability_type', 'Passive'));

  const common = {
    ability_id: String(av(body, 'ability_id', '')).trim(),
    ability_type: type,
    bit_index: int(av(body, 'bit_index', 0)),
    display_name: String(av(body, 'display_name', '')),
    description: String(av(body, 'description', '')),
    appear_stage: int(av(body, 'appear_stage', 1), 1),
    is_basic_skill: int(av(body, 'is_basic_skill', 0)) ? 1 : 0,
    is_unlocked: int(av(body, 'is_unlocked', 0)) ? 1 : 0,
    max_level: int(av(body, 'max_level', 1), 1),
    cooldown_seconds: flt(av(body, 'cooldown_seconds', 0)),
    stamina_cost: flt(av(body, 'stamina_cost', 0)),
    special_effect: String(av(body, 'special_effect', 'None')),
  };

  // 레벨 배열. 원본 폼은 level[] / 값[] 을 인덱스로 맞춰 보냈지만,
  // 프런트가 JSON 이므로 [{level:1, ...}, ...] 형태로 그대로 받는다.
  const rawLevels = Array.isArray(body.levels) ? body.levels : [];
  const levels = rawLevels.filter((l) => l && typeof l === 'object').map((l) => {
    const row = { level: int(av(l, 'level', 0)) };
    if (type === 'Active') {
      row.skill_multiplier = flt(av(l, 'skill_multiplier', 1), 1);
    } else if (type === 'Utility') {
      row.health_restore_amount = flt(av(l, 'health_restore_amount', 0));
      row.stamina_restore_amount = flt(av(l, 'stamina_restore_amount', 0));
    } else { // Passive
      row.max_health_bonus = flt(av(l, 'max_health_bonus', 0));
      row.max_stamina_bonus = flt(av(l, 'max_stamina_bonus', 0));
      row.defense_bonus_percent = flt(av(l, 'defense_bonus_percent', 0));
      row.attack_damage_bonus_percent = flt(av(l, 'attack_damage_bonus_percent', 0));
    }
    return row;
  });

  const err = abilityValidate(common);
  if (err !== '') return fail(res, '[오류] ' + err);

  try {
    await abilitySave(common, levels);
    return ok(res, { message: '[알림] 스킬이 성공적으로 저장/수정되었습니다.', tone: 'good' });
  } catch (e) {
    return fail(res, '[오류] 저장 실패: ' + e.message);
  }
});

adminRouter.post('/abilities/delete', async (req, res) => {
  const id = String(req.body?.del_id ?? '').trim();
  if (id === '') return fail(res, '삭제할 ability_id 가 없습니다.');

  try {
    await abilityDelete(id);
    return ok(res, { message: '[알림] 스킬이 삭제되었습니다.', tone: 'danger' });
  } catch (e) {
    return fail(res, '[오류] 삭제 실패: ' + e.message);
  }
});

/* ============================================================
 *  팀 랭킹 관리 — 원본 ranking_viewer.php
 * ============================================================ */
adminRouter.get('/rankings', async (req, res) => {
  try {
    const rows = await query(
      `SELECT id, login_id, team_name, clear_time_seconds, cleared_level, party_size, total_damage, players_json,
              DATE_FORMAT(cleared_at,'%Y-%m-%d %H:%i:%s') AS cleared_at
       FROM team_rankings
       ORDER BY clear_time_seconds ASC, cleared_at ASC`
    );

    const rankings = rows.map((r) => {
      // players_json({"members":[...]}) 에서 members 배열만 꺼내 중첩으로 내려준다.
      let members = [];
      try {
        const pj = JSON.parse(r.players_json || '');
        if (pj && Array.isArray(pj.members)) members = pj.members;
      } catch { /* 깨진 JSON 은 빈 배열 */ }

      return {
        id: Number(r.id),
        login_id: r.login_id == null ? '' : String(r.login_id),
        team_name: String(r.team_name),
        clear_time_seconds: Number(r.clear_time_seconds),
        cleared_level: Number(r.cleared_level),
        party_size: Number(r.party_size),
        total_damage: Number(r.total_damage),
        members: members.map((m) => ({
          nickname: String(m?.nickname ?? ''),
          damage: int(m?.damage, 0),
        })),
        cleared_at: String(r.cleared_at),
      };
    });

    return ok(res, { rankings });

  } catch (e) {
    return fail(res,
      '[오류] 목록 로드 실패: ' + e.message + ' (migration_team_rankings.sql 실행 여부 확인)',
      { rankings: [] });
  }
});

adminRouter.post('/rankings/delete', async (req, res) => {
  const id = int(req.body?.del_id, 0);
  if (id <= 0) return fail(res, '삭제할 기록 ID 가 없습니다.');

  try {
    await execute('DELETE FROM team_rankings WHERE id = ?', [id]);
    return ok(res, { message: `[알림] 랭킹 기록(ID: ${id})이 삭제되었습니다.`, tone: 'danger' });
  } catch (e) {
    return fail(res, '삭제 에러: ' + e.message);
  }
});

/* ============================================================
 *  크래시 리포트 — 원본 crash_viewer.php
 * ============================================================ */
adminRouter.get('/crashes', async (req, res) => {
  let type = String(req.query?.type ?? '');
  if (!['exception', 'unhandled', 'native_crash'].includes(type)) type = '';

  try {
    // 최근 7일, 같은 크래시가 몇 명한테 터졌는지 (상위 20)
    const aggRows = await query(
      `SELECT report_type, scene, LEFT(message, 80) AS msg,
              COUNT(*) AS hits, COUNT(DISTINCT login_id) AS users
       FROM crash_reports
       WHERE occurred_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
       GROUP BY report_type, scene, msg
       ORDER BY users DESC, hits DESC
       LIMIT 20`
    );

    const agg = aggRows.map((a) => ({
      report_type: String(a.report_type),
      scene: a.scene == null ? '' : String(a.scene),
      msg: a.msg == null ? '' : String(a.msg),
      hits: Number(a.hits),
      users: Number(a.users),
    }));

    let sql = `SELECT id, client_report_id, report_type, login_id, nickname, message, stack_trace, log_tail,
                      scene, app_version, unity_version, platform, device_model, gpu, ram_mb,
                      DATE_FORMAT(occurred_at, '%Y-%m-%d %H:%i:%s') AS occurred_at,
                      DATE_FORMAT(received_at, '%Y-%m-%d %H:%i:%s') AS received_at
               FROM crash_reports`;
    const params = [];
    if (type !== '') {
      sql += ' WHERE report_type = ?';
      params.push(type);
    }
    sql += ' ORDER BY occurred_at DESC, id DESC LIMIT 200';

    const rows = await query(sql, params);
    const str = (v) => (v == null ? '' : String(v));

    const reports = rows.map((r) => ({
      id: Number(r.id),
      client_report_id: str(r.client_report_id),
      report_type: str(r.report_type),
      login_id: str(r.login_id),
      nickname: str(r.nickname),
      message: str(r.message),
      stack_trace: str(r.stack_trace),
      log_tail: str(r.log_tail),
      scene: str(r.scene),
      app_version: str(r.app_version),
      unity_version: str(r.unity_version),
      platform: str(r.platform),
      device_model: str(r.device_model),
      gpu: str(r.gpu),
      ram_mb: Number(r.ram_mb),
      occurred_at: str(r.occurred_at),
      received_at: str(r.received_at),
    }));

    return ok(res, { type, agg, reports });

  } catch (e) {
    return fail(res,
      '[오류] 목록 로드 실패: ' + e.message + ' (migration_crash_reports.sql 실행 여부 확인)',
      { type, agg: [], reports: [], load_error: true });
  }
});

adminRouter.post('/crashes/action', async (req, res) => {
  const action = String(req.body?.action ?? '');

  try {
    if (action === 'delete') {
      const id = int(req.body?.del_id, 0);
      if (id <= 0) return fail(res, '삭제할 리포트 ID 가 없습니다.');

      await execute('DELETE FROM crash_reports WHERE id = ?', [id]);
      return ok(res, { message: `[알림] 리포트(ID: ${id})가 삭제되었습니다.`, tone: 'danger' });
    }

    if (action === 'purge') {
      const r = await execute(
        'DELETE FROM crash_reports WHERE received_at < DATE_SUB(NOW(), INTERVAL 30 DAY)');
      return ok(res, {
        message: `[알림] 30일 지난 리포트 ${r.affectedRows}건을 정리했습니다.`,
        tone: 'warn',
      });
    }

    return fail(res, '알 수 없는 action: ' + action);

  } catch (e) {
    return fail(res, '에러: ' + e.message);
  }
});
