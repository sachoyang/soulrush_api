/* ============================================================
 *  hub.js — 로그인 게이트 + 허브 (원본 admin_hub.php)
 * ------------------------------------------------------------
 *  원본은 세션이 없으면 로그인 폼을, 있으면 메뉴를 출력하는 한 파일이었다.
 *  여기서도 한 페이지에서 두 화면을 토글하는 구조를 그대로 유지한다.
 * ============================================================ */

(() => {
  'use strict';

  const loginView = document.getElementById('loginView');
  const hubView = document.getElementById('hubView');
  const loginError = document.getElementById('loginError');

  function showLogin() {
    loginView.style.display = 'flex';
    hubView.style.display = 'none';
    document.getElementById('apiBaseLabel').textContent = SR.apiBase() || '(같은 서버)';
    document.getElementById('pwInput').focus();
  }

  function showHub() {
    loginView.style.display = 'none';
    hubView.style.display = 'block';
    document.getElementById('hubApiBase').textContent = SR.apiBase() || 'same-origin';
  }

  // 진입 시: 토큰이 살아 있으면 바로 허브, 아니면 로그인.
  async function init() {
    if (!SR.getToken()) {
      showLogin();
      return;
    }
    try {
      const r = await SR.get('/api/admin/check');
      if (r.status === 'success') showHub();
      else { SR.clearToken(); showLogin(); }
    } catch (e) {
      // 서버가 안 떠 있거나 주소가 틀린 경우 — 로그인 화면에서 원인을 보여준다.
      SR.clearToken();
      showLogin();
      loginError.textContent = e.message;
    }
  }

  document.getElementById('loginForm').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    loginError.textContent = '';

    const pw = document.getElementById('pwInput').value;
    try {
      const r = await SR.post('/api/admin/login', { pw });
      if (r.status === 'success') {
        SR.setToken(r.token);
        document.getElementById('pwInput').value = '';
        showHub();
      } else {
        loginError.textContent = r.message || '로그인에 실패했습니다.';
      }
    } catch (e) {
      loginError.textContent = e.message;
    }
  });

  document.getElementById('btnApiBaseLogin').addEventListener('click', SR.promptApiBase);
  document.getElementById('btnApiBaseHub').addEventListener('click', SR.promptApiBase);
  document.getElementById('btnLogout').addEventListener('click', SR.logout);

  init();
})();
