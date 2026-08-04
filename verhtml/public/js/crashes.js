/* ============================================================
 *  crashes.js — 크래시 리포트 뷰어 (원본 crash_viewer.php)
 * ------------------------------------------------------------
 *  · 상단: 최근 7일 집계(같은 크래시가 몇 명한테 터졌는지)
 *  · 하단: 개별 리포트 목록. 스택트레이스/로그 꼬리는 접었다 펼침.
 *  · 종류 탭은 페이지 이동 없이 다시 조회한다(주소창의 ?type= 은 동기화).
 * ============================================================ */

(() => {
  'use strict';

  const TYPE_BADGE = {
    exception:    ['#3a5', '예외'],
    unhandled:    ['#c73', '미처리'],
    native_crash: ['#c33', '네이티브'],
  };

  function typeBadge(t) {
    const [color, label] = TYPE_BADGE[t] || ['#666', t];
    return `<span class="badge" style="background:${color};">${SR.escapeHtml(label)}</span>`;
  }

  // 현재 탭. 주소창의 ?type= 을 초기값으로 쓴다(원본 링크 구조와 호환).
  let currentType = new URLSearchParams(location.search).get('type') || '';
  if (!['exception', 'unhandled', 'native_crash'].includes(currentType)) currentType = '';

  const $ = (id) => document.getElementById(id);

  async function load() {
    syncTabs();

    try {
      const r = await SR.get('/api/crashes', currentType ? { type: currentType } : {});

      if (r.status !== 'success') {
        SR.showMessage(r.message, 'danger');
      }

      renderAgg(r.agg || []);
      renderReports(r.reports || [], r.status !== 'success' || r.load_error);

    } catch (e) {
      $('aggRows').innerHTML = `<tr><td colspan="5" style="color:var(--red);">${SR.escapeHtml(e.message)}</td></tr>`;
      $('reportRows').innerHTML = `<tr><td colspan="9" style="color:var(--red);">${SR.escapeHtml(e.message)}</td></tr>`;
    }
  }

  function renderAgg(agg) {
    $('aggRows').innerHTML = agg.length === 0
      ? '<tr><td colspan="5" class="empty">최근 7일간 크래시가 없습니다. 🎉</td></tr>'
      : agg.map((a) => `
          <tr>
            <td>${typeBadge(a.report_type)}</td>
            <td>${SR.escapeHtml(a.scene)}</td>
            <td class="crash-msg">${SR.escapeHtml(a.msg)}</td>
            <td>${a.hits}</td>
            <td style="color:var(--accent); font-weight:bold;">${a.users}</td>
          </tr>`).join('');
  }

  function renderReports(reports, loadError) {
    if (reports.length === 0) {
      $('reportRows').innerHTML =
        `<tr><td colspan="9" class="empty">${loadError ? '테이블을 읽을 수 없습니다.' : '수집된 크래시 리포트가 없습니다.'}</td></tr>`;
      return;
    }

    $('reportRows').innerHTML = reports.map(renderReport).join('');
    $('reportRows').querySelectorAll('[data-act="delete"]').forEach((el) => {
      el.addEventListener('click', onDelete);
    });
  }

  function renderReport(r) {
    const user = r.nickname
      ? `${SR.escapeHtml(r.nickname)}${r.login_id ? `<br><span class="meta">@${SR.escapeHtml(r.login_id)}</span>` : ''}`
      : '<span class="meta">(익명 · 로그인 전)</span>';

    // 원본의 nl2br() 자리 — 메시지의 줄바꿈을 살린다.
    const message = SR.escapeHtml(r.message).replace(/\n/g, '<br>');

    const stack = r.stack_trace
      ? `<details><summary>스택트레이스 펼치기</summary><pre>${SR.escapeHtml(r.stack_trace)}</pre></details>` : '';

    const logTail = r.log_tail
      ? `<details><summary>🔍 로그 꼬리 (직전 세션 — 실제 죽은 시점)</summary><pre>${SR.escapeHtml(r.log_tail)}</pre></details>` : '';

    return `
      <tr data-id="${r.id}">
        <td>${r.id}</td>
        <td>${typeBadge(r.report_type)}</td>
        <td>${user}</td>
        <td class="crash-msg">${message}${stack}${logTail}</td>
        <td>${SR.escapeHtml(r.scene)}</td>
        <td>
          ${SR.escapeHtml(r.app_version)}
          <br><span class="meta">${SR.escapeHtml(r.unity_version)}</span>
        </td>
        <td class="meta left">
          ${SR.escapeHtml(r.platform)}<br>
          ${SR.escapeHtml(r.device_model)}<br>
          ${SR.escapeHtml(r.gpu)}<br>
          RAM ${SR.numFmt(r.ram_mb)} MB
        </td>
        <td class="meta">
          ${SR.escapeHtml(r.occurred_at)}
          <br>(수신 ${SR.escapeHtml(r.received_at)})
        </td>
        <td><button type="button" class="btn btn-danger" data-act="delete">삭제</button></td>
      </tr>`;
  }

  async function onDelete(ev) {
    if (!confirm('이 크래시 리포트를 삭제하시겠습니까?')) return;

    const id = Number(ev.currentTarget.closest('tr').dataset.id);
    await runAction({ action: 'delete', del_id: id });
  }

  async function runAction(payload) {
    try {
      const r = await SR.post('/api/crashes/action', payload);
      SR.showMessage(r.message, r.status === 'success' ? (r.tone || 'good') : 'danger');
      if (r.status === 'success') await load();
    } catch (e) {
      SR.showMessage(e.message, 'danger');
    }
  }

  // ---------- 탭 ----------

  function syncTabs() {
    $('tabs').querySelectorAll('button[data-type]').forEach((b) => {
      b.classList.toggle('on', b.dataset.type === currentType);
    });

    // 주소창도 맞춰 둔다 — 새로고침/북마크해도 같은 탭이 열린다.
    const qs = currentType ? '?type=' + currentType : location.pathname;
    history.replaceState(null, '', currentType ? qs : location.pathname);
  }

  $('tabs').querySelectorAll('button[data-type]').forEach((b) => {
    b.addEventListener('click', () => {
      currentType = b.dataset.type;
      load();
    });
  });

  $('purgeBtn').addEventListener('click', async () => {
    if (!confirm('30일 지난 리포트를 모두 삭제합니다. 진행할까요?')) return;
    await runAction({ action: 'purge' });
  });

  // ---------- 시작 ----------

  (async () => {
    SR.mountHeaderTools();
    if (!await SR.guard()) return;
    await load();
  })();
})();
