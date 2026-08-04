/* ============================================================
 *  api.js — 모든 관리자 페이지가 공유하는 얇은 클라이언트 레이어
 * ------------------------------------------------------------
 *  · API 주소 관리 (config.js 기본값 + localStorage 오버라이드)
 *  · 관리자 토큰 보관 및 X-Admin-Token 헤더 자동 첨부
 *  · 401 응답이면 로그인 화면으로 되돌리기
 *  · 페이지 공통 UI (헤더 알림 / API 주소 변경 / 로그아웃)
 *  · 64비트 마스크·숫자·HTML 이스케이프 유틸
 * ============================================================ */

const SR = (() => {
  'use strict';

  const LS_TOKEN = 'sr_admin_token';
  const LS_BASE = 'sr_api_base';

  // ---------- API 주소 ----------

  function apiBase() {
    let override = null;
    try { override = localStorage.getItem(LS_BASE); } catch { /* 시크릿 모드 등 */ }
    const base = override ?? window.SOULRUSH_API_BASE ?? '';
    return base.replace(/\/+$/, ''); // 끝 슬래시 제거
  }

  function setApiBase(v) {
    try {
      if (v) localStorage.setItem(LS_BASE, v);
      else localStorage.removeItem(LS_BASE);
    } catch { /* 저장 실패는 무시 */ }
  }

  // ---------- 토큰 ----------

  function getToken() {
    try { return localStorage.getItem(LS_TOKEN) || ''; } catch { return ''; }
  }

  function setToken(t) {
    try { localStorage.setItem(LS_TOKEN, t); } catch { /* 무시 */ }
  }

  function clearToken() {
    try { localStorage.removeItem(LS_TOKEN); } catch { /* 무시 */ }
  }

  // ---------- 호출 ----------

  // GET. params 는 쿼리스트링으로 붙는다.
  async function get(path, params) {
    const qs = params ? '?' + new URLSearchParams(params) : '';
    return request(path + qs, { method: 'GET' });
  }

  // POST. body 는 JSON 으로 보낸다.
  async function post(path, body) {
    return request(path, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body || {}),
    });
  }

  async function request(path, opts) {
    const headers = { ...(opts.headers || {}) };
    const token = getToken();
    if (token) headers['X-Admin-Token'] = token;

    let res;
    try {
      res = await fetch(apiBase() + path, { ...opts, headers });
    } catch (e) {
      // 네트워크 자체가 안 닿는 경우 — 주소 오타/서버 미기동이 대부분이다.
      throw new Error(
        `서버에 연결할 수 없습니다 (${apiBase() || '같은 출처'}).\n` +
        `Node 서버가 켜져 있는지, [API 주소] 설정이 맞는지 확인하세요.\n(${e.message})`
      );
    }

    // 인증 만료 → 로그인 화면으로
    if (res.status === 401) {
      clearToken();
      if (!location.pathname.endsWith('/') && !location.pathname.endsWith('index.html')) {
        location.href = 'index.html';
      }
      throw new Error('관리자 인증이 만료되었습니다. 다시 로그인해 주세요.');
    }

    const text = await res.text();
    try {
      return JSON.parse(text);
    } catch {
      throw new Error('서버 응답을 해석할 수 없습니다:\n' + text.slice(0, 300));
    }
  }

  // ---------- 페이지 가드 ----------
  // 각 관리자 페이지 진입 시 호출. 토큰이 없거나 만료면 로그인 화면으로 돌린다.
  async function guard() {
    if (!getToken()) {
      location.href = 'index.html';
      return false;
    }
    try {
      const r = await get('/api/admin/check');
      if (r.status !== 'success') {
        clearToken();
        location.href = 'index.html';
        return false;
      }
      return true;
    } catch (e) {
      showMessage(e.message, 'danger');
      return false;
    }
  }

  async function logout() {
    try { await post('/api/admin/logout'); } catch { /* 서버가 죽어 있어도 로컬 토큰은 지운다 */ }
    clearToken();
    location.href = 'index.html';
  }

  // ---------- 공통 UI ----------

  // 화면 상단 알림. 원본에서 PHP 가 $action_msg 로 찍어 주던 자리.
  function showMessage(text, tone = 'info') {
    const el = document.getElementById('msg');
    if (!el) return;
    el.textContent = text;
    el.className = 'msg msg-' + tone;
    el.style.display = text ? 'inline-block' : 'none';
  }

  // API 주소 변경 다이얼로그
  function promptApiBase() {
    const cur = apiBase();
    const next = prompt(
      'API 서버 주소를 입력하세요.\n' +
      '비워 두면 이 페이지와 같은 서버(same-origin)를 사용합니다.\n\n' +
      '예) http://192.168.0.10:3000',
      cur
    );
    if (next === null) return; // 취소
    setApiBase(next.trim().replace(/\/+$/, ''));
    location.reload();
  }

  // 모든 페이지 헤더 우측의 공통 버튼을 심는다.
  function mountHeaderTools() {
    const host = document.getElementById('headerTools');
    if (!host) return;

    const base = apiBase();
    host.innerHTML = `
      <span class="api-base" title="현재 바라보는 API 서버">${escapeHtml(base || 'same-origin')}</span>
      <button type="button" class="btn btn-ghost" id="btnApiBase">API 주소</button>
      <a href="index.html" class="btn btn-ghost">허브</a>
      <button type="button" class="btn btn-danger" id="btnLogout">로그아웃</button>
    `;
    host.querySelector('#btnApiBase').addEventListener('click', promptApiBase);
    host.querySelector('#btnLogout').addEventListener('click', logout);
  }

  // ---------- 유틸 ----------

  function escapeHtml(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  // 원본 PHP 의 number_format() 자리 — 천 단위 콤마.
  function numFmt(n) {
    return Number(n || 0).toLocaleString('en-US');
  }

  // 원본 ability_manager.php 의 fmtNum() 자리 —
  // 소수점 뒤 불필요한 0 제거. 예: 8.0000 → 8, 1.2000 → 1.2
  function fmtNum(v) {
    if (v === null || v === undefined || v === '' || isNaN(Number(v))) return String(v ?? '');
    const s = Number(v).toFixed(4).replace(/0+$/, '').replace(/\.$/, '');
    return s === '' || s === '-' ? '0' : s;
  }

  // 원본 ranking_viewer.php 의 fmt_time() 자리 — "3:07 (187s)"
  function fmtTime(sec) {
    const s = parseInt(sec, 10) || 0;
    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')} (${s}s)`;
  }

  // ---------- 64비트 마스크 (서버 mask.js 의 브라우저 짝) ----------
  // BIGINT 는 JS Number 로 다루면 깨지므로 반드시 BigInt 로 받는다.

  const TWO_POW_64 = 1n << 64n;

  function toU64(decStr) {
    let v = BigInt(String(decStr || '0').trim() || '0');
    if (v < 0n) v += TWO_POW_64; // 음수(부호 비트)면 무부호 64비트로 보정
    return v & (TWO_POW_64 - 1n);
  }

  function bin64(decStr) {
    return toU64(decStr).toString(2).padStart(64, '0');
  }

  // 64자리 2진수를 4자리씩 끊어 가독성 향상
  function groupBin4(bin) {
    return bin.match(/.{1,4}/g).join(' ');
  }

  // 켜져 있는 비트 인덱스 목록
  function listBits(decStr) {
    const v = toU64(decStr);
    const out = [];
    for (let i = 0n; i < 64n; i++) {
      if (((v >> i) & 1n) === 1n) out.push(Number(i));
    }
    return out;
  }

  return {
    apiBase, setApiBase, promptApiBase,
    getToken, setToken, clearToken,
    get, post, guard, logout,
    showMessage, mountHeaderTools,
    escapeHtml, numFmt, fmtNum, fmtTime,
    toU64, bin64, groupBin4, listBits,
  };
})();
