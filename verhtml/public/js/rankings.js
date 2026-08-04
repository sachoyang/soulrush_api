/* ============================================================
 *  rankings.js — 팀 랭킹 관리 (원본 ranking_viewer.php)
 * ------------------------------------------------------------
 *  상위 기록을 정렬해 보여주고, 부정 기록은 개별 삭제할 수 있다.
 *  순위는 서버 정렬 순서대로 1부터 매긴다(원본과 동일).
 * ============================================================ */

(() => {
  'use strict';

  const tbody = document.getElementById('rankRows');

  async function load() {
    try {
      const r = await SR.get('/api/rankings');

      if (r.status !== 'success') {
        SR.showMessage(r.message, 'danger');
        tbody.innerHTML = `<tr><td colspan="9" class="empty">목록을 불러오지 못했습니다.</td></tr>`;
        return;
      }

      if (r.rankings.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" class="empty">등록된 랭킹 기록이 없습니다.</td></tr>';
        return;
      }

      tbody.innerHTML = r.rankings.map(renderRow).join('');
      tbody.querySelectorAll('[data-act="delete"]').forEach((el) => {
        el.addEventListener('click', onDelete);
      });

    } catch (e) {
      tbody.innerHTML = `<tr><td colspan="9" style="color:var(--red);">${SR.escapeHtml(e.message)}</td></tr>`;
    }
  }

  function renderRow(r, i) {
    const rank = i + 1;
    const rankClass = rank <= 3 ? `rank${rank}` : '';

    const owner = r.login_id
      ? `<br><span class="meta">@${SR.escapeHtml(r.login_id)}</span>`
      : '';

    const members = r.members.length === 0
      ? '<span class="meta">(팀원 정보 없음 — 클라가 나중에 채움)</span>'
      : r.members.map((m) =>
          `<span class="member-chip">${SR.escapeHtml(m.nickname)} · ${SR.numFmt(m.damage)}</span>`).join('');

    return `
      <tr data-id="${r.id}">
        <td class="${rankClass}">${rank}</td>
        <td>${SR.escapeHtml(r.team_name)}${owner}</td>
        <td>${SR.fmtTime(r.clear_time_seconds)}</td>
        <td>${r.cleared_level}</td>
        <td>${r.party_size}</td>
        <td>${SR.numFmt(r.total_damage)}</td>
        <td class="members">${members}</td>
        <td class="meta">${SR.escapeHtml(r.cleared_at)}</td>
        <td><button type="button" class="btn btn-danger" data-act="delete">삭제</button></td>
      </tr>`;
  }

  async function onDelete(ev) {
    if (!confirm('이 랭킹 기록을 삭제하시겠습니까?')) return;

    const id = Number(ev.currentTarget.closest('tr').dataset.id);
    try {
      const r = await SR.post('/api/rankings/delete', { del_id: id });
      SR.showMessage(r.message, r.status === 'success' ? (r.tone || 'good') : 'danger');
      if (r.status === 'success') await load();
    } catch (e) {
      SR.showMessage(e.message, 'danger');
    }
  }

  (async () => {
    SR.mountHeaderTools();
    if (!await SR.guard()) return;
    await load();
  })();
})();
