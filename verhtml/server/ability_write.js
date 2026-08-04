/* ============================================================
 *  ability_write.js — 스킬 UPSERT 공용 로직 (원본 ability_write.php 대응)
 * ------------------------------------------------------------
 *  에디터 업로드(/upload_ability.php)와 관리자 사이트(/api/abilities/save)가
 *  이 파일 하나를 공유한다. 원본의 "저장 규칙 단일화" 구조를 그대로 유지한 것.
 *
 *  7테이블 구성:
 *    abilities                (공통)
 *    active_abilities         / active_ability_levels
 *    passive_abilities        / passive_ability_levels
 *    utility_abilities        / utility_ability_levels
 *
 *  타입별 기본 테이블 + 레벨 테이블을 한 트랜잭션으로 재작성한다.
 * ============================================================ */

import { withTransaction } from './db.js';

// 값 안전 접근 헬퍼 (원본 av() 대응). null/undefined 일 때만 기본값.
export function av(obj, key, def = 0) {
  if (obj && typeof obj === 'object' && obj[key] !== undefined && obj[key] !== null) {
    return obj[key];
  }
  return def;
}

// 숫자 변환 — PHP 의 (int)/(float) 캐스팅처럼 "실패하면 0" 으로 동작시킨다.
// ("" 나 "abc" 가 NaN 으로 새어 나가 DB 에 들어가는 것을 막는다)
const int = (v, def = 0) => { const n = parseInt(v, 10); return Number.isNaN(n) ? def : n; };
const flt = (v, def = 0) => { const n = parseFloat(v);   return Number.isNaN(n) ? def : n; };

// ★ FLOAT(단정밀도) 컬럼 읽기 보정.
//   스킬 수치 컬럼은 전부 MySQL FLOAT 다. PHP(PDO)는 MySQL 이 텍스트로 보낸
//   "2.3" 을 그대로 받아 (float)"2.3" = 2.3 을 응답에 실었다.
//   반면 mysql2 는 4바이트 float32 를 float64 로 확장하므로 2.299999952316284 가 된다.
//   → float32 의 유효자릿수(약 7자리)로 되돌려 원본 PHP 와 같은 값이 나가게 한다.
//     (게임 쪽은 어차피 float 로 파싱해 비트가 동일하지만, 응답 JSON 이 원본과
//      달라 보이면 비교/디버깅이 번거로워지므로 맞춰 둔다)
function f32(v) {
  const n = Number(v);
  if (!Number.isFinite(n)) return 0;
  return Number(n.toPrecision(7));
}

// bit_index 타입별 범위. [lo, hi] (양끝 포함)
export function abilityBitRange(type) {
  const ranges = { Active: [1, 19], Passive: [20, 39], Utility: [40, 60] };
  return ranges[type] || [1, 60];
}

// 검증. 통과하면 '' , 실패하면 사람이 읽을 오류 문자열 반환. (원본 ability_validate 와 동일 문구)
export function abilityValidate(c) {
  if (String(av(c, 'ability_id', '')).trim() === '') return 'ability_id 가 비어있습니다.';

  if (!['Active', 'Passive', 'Utility'].includes(c.ability_type)) {
    return 'ability_type 이 올바르지 않습니다: ' + c.ability_type;
  }

  const [lo, hi] = abilityBitRange(c.ability_type);
  const bit = int(c.bit_index);
  if (bit < lo || bit > hi) {
    return `bit_index(${bit}) 가 ${c.ability_type} 범위(${lo}~${hi})를 벗어났습니다.`;
  }

  if (int(c.max_level) < 1) return 'max_level 은 1 이상이어야 합니다.';
  return '';
}

// 스킬 타입이 바뀌었을 때 이전 타입 테이블의 잔여 행 삭제.
async function cleanupOtherTypes(conn, abilityId, keepType) {
  const map = {
    Active:  ['active_abilities', 'active_ability_levels'],
    Passive: ['passive_abilities', 'passive_ability_levels'],
    Utility: ['utility_abilities', 'utility_ability_levels'],
  };
  for (const [type, tables] of Object.entries(map)) {
    if (type === keepType) continue;
    for (const t of tables) {
      await conn.execute(`DELETE FROM \`${t}\` WHERE ability_id = ?`, [abilityId]);
    }
  }
}

// 실제 저장. c: 공통 필드, levels: 레벨 배열(타입별 키만 채워짐).
export async function abilitySave(c, levels) {
  const abilityId = String(c.ability_id);
  const abilityType = String(c.ability_type);
  const maxLevel = Math.max(1, int(c.max_level, 1));

  await withTransaction(async (conn) => {
    // 1) 공통 abilities UPSERT (PK ability_id, bit_index UNIQUE)
    await conn.execute(
      `INSERT INTO abilities
          (ability_id, ability_type, bit_index, display_name, description, appear_stage, is_basic_skill, is_unlocked, max_level)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE
          ability_type   = VALUES(ability_type),
          bit_index      = VALUES(bit_index),
          display_name   = VALUES(display_name),
          description    = VALUES(description),
          appear_stage   = VALUES(appear_stage),
          is_basic_skill = VALUES(is_basic_skill),
          is_unlocked    = VALUES(is_unlocked),
          max_level      = VALUES(max_level)`,
      [
        abilityId,
        abilityType,
        int(c.bit_index),
        String(av(c, 'display_name', '')),
        String(av(c, 'description', '')),
        int(av(c, 'appear_stage', 1), 1),
        int(c.is_basic_skill) ? 1 : 0,
        int(c.is_unlocked) ? 1 : 0,
        maxLevel,
      ]
    );

    // 타입이 바뀌었을 수 있으므로 다른 타입 테이블의 잔여 행을 정리한다.
    await cleanupOtherTypes(conn, abilityId, abilityType);

    const cooldown = flt(av(c, 'cooldown_seconds', 0));
    const stamina  = flt(av(c, 'stamina_cost', 0));

    // 레벨 범위(1 ~ max_level) 밖은 원본과 동일하게 조용히 건너뛴다.
    const validLevels = levels.filter((l) => {
      const lv = int(av(l, 'level', 0));
      return lv >= 1 && lv <= maxLevel;
    });

    if (abilityType === 'Active') {
      await conn.execute(
        `INSERT INTO active_abilities (ability_id, cooldown_seconds, stamina_cost)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE cooldown_seconds = VALUES(cooldown_seconds), stamina_cost = VALUES(stamina_cost)`,
        [abilityId, cooldown, stamina]
      );

      await conn.execute('DELETE FROM active_ability_levels WHERE ability_id = ?', [abilityId]);
      for (const l of validLevels) {
        await conn.execute(
          'INSERT INTO active_ability_levels (ability_id, level, skill_multiplier) VALUES (?, ?, ?)',
          [abilityId, int(av(l, 'level', 0)), flt(av(l, 'skill_multiplier', 1), 1)]
        );
      }

    } else if (abilityType === 'Utility') {
      let special = String(av(c, 'special_effect', 'None'));
      if (special === '') special = 'None';

      await conn.execute(
        `INSERT INTO utility_abilities (ability_id, cooldown_seconds, stamina_cost, special_effect)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE cooldown_seconds = VALUES(cooldown_seconds), stamina_cost = VALUES(stamina_cost), special_effect = VALUES(special_effect)`,
        [abilityId, cooldown, stamina, special]
      );

      await conn.execute('DELETE FROM utility_ability_levels WHERE ability_id = ?', [abilityId]);
      for (const l of validLevels) {
        await conn.execute(
          `INSERT INTO utility_ability_levels (ability_id, level, health_restore_amount, stamina_restore_amount)
           VALUES (?, ?, ?, ?)`,
          [
            abilityId,
            int(av(l, 'level', 0)),
            flt(av(l, 'health_restore_amount', 0)),
            flt(av(l, 'stamina_restore_amount', 0)),
          ]
        );
      }

    } else { // Passive
      await conn.execute('INSERT IGNORE INTO passive_abilities (ability_id) VALUES (?)', [abilityId]);

      await conn.execute('DELETE FROM passive_ability_levels WHERE ability_id = ?', [abilityId]);
      for (const l of validLevels) {
        await conn.execute(
          `INSERT INTO passive_ability_levels
              (ability_id, level, max_health_bonus, max_stamina_bonus, defense_bonus_percent, attack_damage_bonus_percent)
           VALUES (?, ?, ?, ?, ?, ?)`,
          [
            abilityId,
            int(av(l, 'level', 0)),
            flt(av(l, 'max_health_bonus', 0)),
            flt(av(l, 'max_stamina_bonus', 0)),
            flt(av(l, 'defense_bonus_percent', 0)),
            flt(av(l, 'attack_damage_bonus_percent', 0)),
          ]
        );
      }
    }
  });
}

// 스킬 완전 삭제(공통 + 모든 타입 테이블).
export async function abilityDelete(abilityId) {
  const tables = [
    'active_ability_levels', 'active_abilities',
    'passive_ability_levels', 'passive_abilities',
    'utility_ability_levels', 'utility_abilities',
    'abilities',
  ];
  await withTransaction(async (conn) => {
    for (const t of tables) {
      await conn.execute(`DELETE FROM \`${t}\` WHERE ability_id = ?`, [abilityId]);
    }
  });
}

// 스킬 1개를 공통 + 타입별 기본값 + 레벨까지 합쳐 읽는다.
// get_abilities(게임용)와 관리자 목록이 공유하는 조회 로직.
export async function fetchAbilities(conn, { adminKeys = false } = {}) {
  const [rows] = await conn.execute('SELECT * FROM abilities ORDER BY bit_index ASC');
  const out = [];

  for (const a of rows) {
    const id = a.ability_id;
    const type = a.ability_type;

    // JSON 키 규칙이 두 벌이다:
    //   게임용   basic_skill / unlocked_skill  (원본 get_abilities.php)
    //   관리자용 is_basic_skill / is_unlocked  (원본 ability_manager.php = DB 컬럼명 그대로)
    const flags = adminKeys
      ? { is_basic_skill: Number(a.is_basic_skill), is_unlocked: Number(a.is_unlocked) }
      : { basic_skill: Number(a.is_basic_skill), unlocked_skill: Number(a.is_unlocked) };

    const ab = {
      ability_id: String(id),
      ability_type: String(type),
      bit_index: Number(a.bit_index),
      display_name: String(a.display_name),
      description: a.description == null ? '' : String(a.description),
      appear_stage: Number(a.appear_stage),
      ...flags,
      max_level: Number(a.max_level),
      cooldown_seconds: 0,
      stamina_cost: 0,
      // 원본 차이 유지: 게임용은 빈 문자열, 관리자용은 'None' 이 기본값이었다.
      special_effect: adminKeys ? 'None' : '',
      levels: [],
    };

    if (type === 'Active') {
      const [b] = await conn.execute(
        'SELECT cooldown_seconds, stamina_cost FROM active_abilities WHERE ability_id = ?', [id]);
      if (b.length) {
        ab.cooldown_seconds = f32(b[0].cooldown_seconds);
        ab.stamina_cost = f32(b[0].stamina_cost);
      }
      const [lv] = await conn.execute(
        'SELECT level, skill_multiplier FROM active_ability_levels WHERE ability_id = ? ORDER BY level ASC', [id]);
      ab.levels = lv.map((l) => ({
        level: Number(l.level),
        skill_multiplier: f32(l.skill_multiplier),
      }));

    } else if (type === 'Utility') {
      const [b] = await conn.execute(
        'SELECT cooldown_seconds, stamina_cost, special_effect FROM utility_abilities WHERE ability_id = ?', [id]);
      if (b.length) {
        ab.cooldown_seconds = f32(b[0].cooldown_seconds);
        ab.stamina_cost = f32(b[0].stamina_cost);
        ab.special_effect = String(b[0].special_effect);
      }
      const [lv] = await conn.execute(
        `SELECT level, health_restore_amount, stamina_restore_amount
         FROM utility_ability_levels WHERE ability_id = ? ORDER BY level ASC`, [id]);
      ab.levels = lv.map((l) => ({
        level: Number(l.level),
        health_restore_amount: f32(l.health_restore_amount),
        stamina_restore_amount: f32(l.stamina_restore_amount),
      }));

    } else { // Passive
      const [lv] = await conn.execute(
        `SELECT level, max_health_bonus, max_stamina_bonus, defense_bonus_percent, attack_damage_bonus_percent
         FROM passive_ability_levels WHERE ability_id = ? ORDER BY level ASC`, [id]);
      ab.levels = lv.map((l) => ({
        level: Number(l.level),
        max_health_bonus: f32(l.max_health_bonus),
        max_stamina_bonus: f32(l.max_stamina_bonus),
        defense_bonus_percent: f32(l.defense_bonus_percent),
        attack_damage_bonus_percent: f32(l.attack_damage_bonus_percent),
      }));
    }

    out.push(ab);
  }

  return out;
}
