/* ============================================================
 *  users.js — 유저 DB 관리 (원본 log_viewer.php)
 * ------------------------------------------------------------
 *  · 서버 로그 조회
 *  · user_data 표 (IDX / ID / 닉네임 / 스킬 마스크 / admin / 가입일 / 관리)
 *  · PW 초기화, admin 토글, 유저 삭제
 *  · 스킬 마스크 클릭 → 보유 스킬 모달 (bit_index → 스킬명)
 * ============================================================ */

(() => {
  'use strict';

  // bit_index → display_name 매핑. /api/users 응답에서 채워진다.
  let ABILITY_MAP = {};

  const $ = (id) => document.getElementById(id);

  // ---------- 로드 ----------

  async function loadServerLog() {
    try {
      const r = await SR.get('/api/server-log');
      $('serverLog').textContent = r.status === 'success' ? r.log : r.message;
    } catch (e) {
      $('serverLog').textContent = e.message;
    }
  }

  async function loadUsers() {
    const tbody = $('userRows');
    try {
      const r = await SR.get('/api/users');

      if (r.status !== 'success') {
        tbody.innerHTML = `<tr><td colspan="7" style="color:var(--red);">${SR.escapeHtml(r.message)}</td></tr>`;
        return;
      }

      ABILITY_MAP = r.ability_map || {};

      if (r.users.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7">가입된 유저가 없습니다.</td></tr>';
        return;
      }

      tbody.innerHTML = r.users.map(renderRow).join('');
      bindRowEvents();

    } catch (e) {
      tbody.innerHTML = `<tr><td colspan="7" style="color:var(--red);">${SR.escapeHtml(e.message)}</td></tr>`;
    }
  }

  function renderRow(u) {
    // unlocked_skills 는 문자열로 온다. BigInt 로 64자리 2진수를 만들어 4자리씩 끊어 보여준다.
    const bin = SR.groupBin4(SR.bin64(u.unlocked_skills));
    const isAdmin = u.is_admin === 1;

    return `
      <tr data-idx="${u.idx}" data-login="${SR.escapeHtml(u.login_id)}" data-skills="${SR.escapeHtml(u.unlocked_skills)}" data-nick="${SR.escapeHtml(u.nickname)}">
        <td>${u.idx}</td>
        <td class="left">${SR.escapeHtml(u.login_id)}</td>
        <td class="left">${SR.escapeHtml(u.nickname)}</td>
        <td class="left"><code class="skill-bin" data-act="skills" title="클릭 시 보유 스킬 보기">${bin}</code></td>
        <td>
          <button type="button" class="btn" data-act="toggle_admin"
                  style="${isAdmin ? 'background:var(--cyan);' : 'background:#555;color:#fff;'}">
            ${isAdmin ? 'ADMIN ✔' : '일반'}
          </button>
        </td>
        <td>${SR.escapeHtml(u.created_at)}</td>
        <td class="actions">
          <button type="button" class="btn btn-accent" data-act="reset">PW초기화</button>
          <button type="button" class="btn btn-danger" data-act="delete">삭제</button>
        </td>
      </tr>`;
  }

  // ---------- 행 액션 ----------

  function bindRowEvents() {
    $('userRows').querySelectorAll('[data-act]').forEach((el) => {
      el.addEventListener('click', onRowAction);
    });
  }

  async function onRowAction(ev) {
    const el = ev.currentTarget;
    const tr = el.closest('tr');
    const idx = Number(tr.dataset.idx);
    const loginId = tr.dataset.login;
    const act = el.dataset.act;

    if (act === 'skills') {
      showSkillModal(tr.dataset.skills, tr.dataset.nick);
      return;
    }

    if (act === 'delete') {
      if (!confirm(`경고: [${loginId}] 유저를 영구적으로 삭제하시겠습니까?`)) return;
      await runAction({ action: 'delete', target_idx: idx });
      return;
    }

    if (act === 'toggle_admin') {
      const isAdmin = el.textContent.includes('ADMIN');
      if (!confirm(`[${loginId}] 유저의 admin 권한을 ${isAdmin ? '해제' : '부여'}하시겠습니까?`)) return;
      await runAction({ action: 'toggle_admin', target_idx: idx });
      return;
    }

    if (act === 'reset') {
      // 원본과 동일하게 임시 비밀번호를 직접 지정받는다(기본값 0000).
      const newPw = prompt(
        `[${loginId}] 유저의 임시 비밀번호를 지정해주세요.\n(기본값은 0000 입니다)`, '0000');
      if (newPw === null || newPw.trim() === '') return;
      await runAction({ action: 'reset', target_idx: idx, new_pw: newPw.trim() });
    }
  }

  async function runAction(payload) {
    try {
      const r = await SR.post('/api/users/action', payload);
      SR.showMessage(r.message, r.status === 'success' ? (r.tone || 'good') : 'danger');
      if (r.status === 'success') await loadUsers();
    } catch (e) {
      SR.showMessage(e.message, 'danger');
    }
  }

  // ---------- 보유 스킬 모달 ----------

  function showSkillModal(decStr, nickname) {
    const bits = SR.listBits(decStr);

    const chips = bits.map((bit) => {
      const name = ABILITY_MAP[bit];
      return name !== undefined
        ? `<span class="chip">${SR.escapeHtml(name)}<span class="chip-bit">#${bit}</span></span>`
        // 매핑에 없는 비트 (스킬 미등록) — 비트 인덱스만 표시
        : `<span class="chip unknown">(미등록)<span class="chip-bit">#${bit}</span></span>`;
    });

    $('skillModalTitle').textContent = '보유 스킬 — ' + nickname;
    $('skillModalDec').textContent = 'unlocked_skills (10진수): ' + decStr;
    $('skillModalChips').innerHTML =
      chips.length > 0 ? chips.join('') : '<span class="empty">보유 스킬 없음</span>';
    $('skillModal').classList.add('open');
  }

  function closeSkillModal() {
    $('skillModal').classList.remove('open');
  }

  $('modalClose').addEventListener('click', closeSkillModal);
  $('skillModal').addEventListener('click', (ev) => {
    if (ev.target === $('skillModal')) closeSkillModal();
  });
  document.addEventListener('keydown', (ev) => {
    if (ev.key === 'Escape') closeSkillModal();
  });

  $('btnReload').addEventListener('click', () => {
    loadServerLog();
    loadUsers();
  });

  // ---------- 시작 ----------

  (async () => {
    SR.mountHeaderTools();
    if (!await SR.guard()) return;
    await Promise.all([loadServerLog(), loadUsers()]);
  })();
})();
