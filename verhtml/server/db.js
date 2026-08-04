/* ============================================================
 *  db.js — MySQL 커넥션 풀 (원본 db_connect.php 대응)
 * ------------------------------------------------------------
 *  ★ BIGINT 처리가 이 파일의 핵심이다.
 *    user_data.unlocked_skills 는 64비트 BIGINT 인데, JS Number 는
 *    2^53 까지만 정확하다. 그래서 드라이버가 BIGINT 를 "문자열"로 주도록
 *    설정하고(supportBigNumbers + bigNumberStrings), 값 계산이 필요할 때만
 *    mask.js 에서 BigInt 로 변환한다.
 *    (원본 PHP 는 32비트라 bcmath 문자열 연산을 썼는데, 이유는 같고 도구만 다르다)
 * ============================================================ */

import mysql from 'mysql2/promise';
import { DB } from './config.js';

export const pool = mysql.createPool({
  host: DB.host,
  user: DB.user,
  password: DB.password,
  database: DB.database,
  charset: DB.charset,
  waitForConnections: true,
  connectionLimit: 10,
  queueLimit: 0,

  // BIGINT / DECIMAL 을 정밀도 손실 없이 문자열로 받는다.
  supportBigNumbers: true,
  bigNumberStrings: true,

  // DATE/DATETIME 도 문자열 그대로. (원본은 DATE_FORMAT 으로 포맷해서 내려주므로
  //  Date 객체로 변환되면 오히려 타임존이 끼어들어 값이 달라진다)
  dateStrings: true,
});

// SELECT 헬퍼 — 결과 행 배열만 돌려준다.
export async function query(sql, params = []) {
  const [rows] = await pool.execute(sql, params);
  return rows;
}

// 단건 SELECT — 없으면 null.
export async function queryOne(sql, params = []) {
  const rows = await query(sql, params);
  return rows.length > 0 ? rows[0] : null;
}

// INSERT/UPDATE/DELETE — { affectedRows, insertId } 등 결과 메타를 돌려준다.
export async function execute(sql, params = []) {
  const [result] = await pool.execute(sql, params);
  return result;
}

// 트랜잭션 실행. fn 이 던지면 자동 롤백.
// 원본의 beginTransaction / commit / rollBack 패턴을 그대로 감쌌다.
export async function withTransaction(fn) {
  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();
    const out = await fn(conn);
    await conn.commit();
    return out;
  } catch (e) {
    try { await conn.rollback(); } catch { /* 롤백 실패는 원래 에러를 가리지 않게 무시 */ }
    throw e;
  } finally {
    conn.release();
  }
}

// MySQL 무결성 제약 위반(중복 키) 여부.
// 원본이 PDOException 코드 '23000' 으로 판정하던 자리를 대신한다.
export function isDuplicateError(err) {
  return err && (err.errno === 1062 || err.code === 'ER_DUP_ENTRY' || err.sqlState === '23000');
}
