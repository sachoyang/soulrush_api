/* ============================================================
 *  abilities.js — 스킬 모듈 관리 (원본 ability_manager.php)
 * ------------------------------------------------------------
 *  원본에서 PHP 가 서버에서 찍어 주던 3개 타입 표를 브라우저에서 렌더링한다.
 *  편집 폼을 DOM 안에서 옮겨 다니며 재사용하는 UX 는 원본 그대로 유지했다.
 * ============================================================ */

(() => {
  'use strict';

  const $ = (id) => document.getElementById(id);

  // 타입별 메타: 색/아이콘/기본값 컬럼/레벨 컬럼. 표를 3개로 분리해 렌더링한다.
  const TYPE_META = {
    Active: {
      color: 'var(--type-active)', icon: '⚡', label: '액티브 (Active)', range: '1~19',
      baseCols: [['쿨타임(초)', 'cooldown_seconds'], ['스태미나', 'stamina_cost']],
      lvlCols: [['배율 (skill_multiplier)', 'skill_multiplier']],
    },
    Passive: {
      color: 'var(--type-passive)', icon: '🛡️', label: '패시브 (Passive)', range: '20~39',
      baseCols: [],
      lvlCols: [
        ['최대체력', 'max_health_bonus'], ['최대스태미나', 'max_stamina_bonus'],
        ['방어 %', 'defense_bonus_percent'], ['공격 %', 'attack_damage_bonus_percent'],
      ],
    },
    Utility: {
      color: 'var(--type-utility)', icon: '🔧', label: '유틸리티 (Utility)', range: '40~60',
      baseCols: [['쿨타임(초)', 'cooldown_seconds'], ['스태미나', 'stamina_cost'], ['특수효과', 'special_effect']],
      lvlCols: [['체력회복', 'health_restore_amount'], ['스태미나회복', 'stamina_restore_amount']],
    },
  };

  // 타입별 레벨 입력 필드 정의
  const LEVEL_FIELDS = {
    Active: [{ name: 'skill_multiplier', label: '배율', def: 1 }],
    Passive: [
      { name: 'max_health_bonus', label: '최대체력', def: 0 },
      { name: 'max_stamina_bonus', label: '최대스태', def: 0 },
      { name: 'defense_bonus_percent', label: '방어%', def: 0 },
      { name: 'attack_damage_bonus_percent', label: '공격%', def: 0 },
    ],
    Utility: [
      { name: 'health_restore_amount', label: '체력회복', def: 0 },
      { name: 'stamina_restore_amount', label: '스태회복', def: 0 },
    ],
  };

  const BIT_RANGES = { Active: '(1~19)', Passive: '(20~39)', Utility: '(40~60)' };

  let abilities = [];        // 서버에서 받은 전체 스킬
  let currentExisting = null; // 수정 중인 스킬의 레벨값 보존 {level: {field:value}}

  /* ---------- 목록 로드 & 렌더 ---------- */

  async function load() {
    try {
      const r = await SR.get('/api/abilities');
      if (r.status !== 'success') {
        SR.showMessage(r.message, 'danger');
        abilities = r.abilities || [];
      } else {
        abilities = r.abilities;
      }
      render();
    } catch (e) {
      $('sections').innerHTML = `<div class="loading" style="color:var(--red);">${SR.escapeHtml(e.message)}</div>`;
    }
  }

  function render() {
    // 편집창이 표 안에 끼워져 있으면 렌더 전에 안전한 곳으로 빼 둔다(재렌더로 사라지지 않게).
    closeEditor();

    const byType = { Active: [], Passive: [], Utility: [] };
    for (const ab of abilities) {
      if (byType[ab.ability_type]) byType[ab.ability_type].push(ab);
    }

    $('sections').innerHTML = Object.entries(TYPE_META)
      .map(([type, meta]) => renderSection(type, meta, byType[type]))
      .join('');

    bindListEvents();
  }

  function renderSection(type, meta, list) {
    // 상세(레벨) 행 병합 컬럼 수 = 공통5 + 기본값 + basic/unlock/레벨/관리(4)
    const colCount = 5 + meta.baseCols.length + 4;

    const head = `
      <tr>
        <th>비트</th><th>ID</th><th>이름</th><th>등장</th><th>maxLv</th>
        ${meta.baseCols.map((c) => `<th>${c[0]}</th>`).join('')}
        <th>basic</th><th>unlock</th><th>레벨 수치</th><th>관리</th>
      </tr>`;

    const body = list.length === 0
      ? `<tr><td colspan="${colCount}" class="empty">등록된 ${type} 스킬이 없습니다.</td></tr>`
      : list.map((ab) => renderRow(ab, meta, colCount)).join('');

    return `
      <div class="type-section" style="border-color:${meta.color}">
        <h3 style="color:${meta.color};">
          ${meta.icon} ${meta.label}
          <span class="count">${list.length}개</span>
          <span class="range">bit_index ${meta.range}</span>
        </h3>
        <div class="table-wrap">
          <table class="skill-table">
            <thead>${head}</thead>
            <tbody>${body}</tbody>
          </table>
        </div>
      </div>`;
  }

  function renderRow(ab, meta, colCount) {
    const check = (v) => (v ? '<b style="color:#00ff88;">✔</b>' : '<span style="color:#666;">–</span>');

    const baseCells = meta.baseCols.map(([, key]) =>
      `<td>${key === 'special_effect' ? SR.escapeHtml(ab[key]) : SR.fmtNum(ab[key])}</td>`).join('');

    const lvlTable = ab.levels.length === 0
      ? '<span class="empty">레벨 데이터가 없습니다.</span>'
      : `<table class="lvl-table">
           <thead><tr><th>레벨</th>${meta.lvlCols.map((c) => `<th>${c[0]}</th>`).join('')}</tr></thead>
           <tbody>
             ${ab.levels.map((l) => `
               <tr>
                 <td class="lv">Lv ${l.level}</td>
                 ${meta.lvlCols.map(([, key]) =>
                   `<td>${l[key] !== undefined ? SR.fmtNum(l[key]) : '-'}</td>`).join('')}
               </tr>`).join('')}
           </tbody>
         </table>`;

    return `
      <tr data-id="${SR.escapeHtml(ab.ability_id)}">
        <td class="bit">${ab.bit_index}</td>
        <td class="mono">${SR.escapeHtml(ab.ability_id)}</td>
        <td class="name">${SR.escapeHtml(ab.display_name)}</td>
        <td>${ab.appear_stage}</td>
        <td>${ab.max_level}</td>
        ${baseCells}
        <td>${check(ab.is_basic_skill)}</td>
        <td>${check(ab.is_unlocked)}</td>
        <td>
          <button type="button" class="lvl-toggle" data-act="toggle">
            Lv 1~${ab.max_level} <span class="arrow">▾</span>
          </button>
        </td>
        <td class="actions">
          <button type="button" class="btn btn-edit" data-act="edit">수정</button>
          <button type="button" class="btn btn-danger" data-act="delete">삭제</button>
        </td>
      </tr>
      <tr class="detail-row" style="display:none;">
        <td colspan="${colCount}">${lvlTable}</td>
      </tr>`;
  }

  function bindListEvents() {
    $('sections').querySelectorAll('[data-act]').forEach((el) => {
      el.addEventListener('click', onRowAction);
    });
  }

  async function onRowAction(ev) {
    const el = ev.currentTarget;
    const tr = el.closest('tr');
    const id = tr.dataset.id;
    const act = el.dataset.act;

    if (act === 'toggle') { toggleDetail(el); return; }

    if (act === 'edit') {
      const ab = abilities.find((a) => a.ability_id === id);
      if (ab) editAbility(el, ab);
      return;
    }

    if (act === 'delete') {
      if (!confirm('정말 삭제하시겠습니까? (모든 타입/레벨 테이블에서 제거)')) return;
      try {
        const r = await SR.post('/api/abilities/delete', { del_id: id });
        SR.showMessage(r.message, r.status === 'success' ? (r.tone || 'good') : 'danger');
        if (r.status === 'success') await load();
      } catch (e) {
        SR.showMessage(e.message, 'danger');
      }
    }
  }

  /* ---------- 레벨 상세 접기/펼치기 ---------- */

  function toggleDetail(btn) {
    const detail = btn.closest('tr').nextElementSibling;
    if (!detail || !detail.classList.contains('detail-row')) return;

    const open = detail.style.display !== 'none';
    detail.style.display = open ? 'none' : 'table-row';
    btn.classList.toggle('open', !open);

    const arrow = btn.querySelector('.arrow');
    if (arrow) arrow.textContent = open ? '▾' : '▴';
  }

  function toggleAll(open) {
    $('sections').querySelectorAll('.detail-row').forEach((d) => {
      d.style.display = open ? 'table-row' : 'none';
      const btn = d.previousElementSibling?.querySelector('.lvl-toggle');
      if (btn) {
        btn.classList.toggle('open', open);
        const arrow = btn.querySelector('.arrow');
        if (arrow) arrow.textContent = open ? '▴' : '▾';
      }
    });
  }

  /* ---------- 편집 폼 ---------- */

  // 타입에 따라 base 필드(쿨타임/스태미나/특수효과) 표시 토글
  function updateBaseFields(type) {
    const show = { cooldown: false, stamina: false, special: false };
    if (type === 'Active') { show.cooldown = true; show.stamina = true; }
    if (type === 'Utility') { show.cooldown = true; show.stamina = true; show.special = true; }

    document.querySelectorAll('.base-field').forEach((el) => {
      el.style.display = show[el.dataset.base] ? 'block' : 'none';
    });

    $('bitHint').textContent = BIT_RANGES[type] || '';
  }

  // 레벨 행 렌더링. existing: {level: {field:value}} 맵(수정 시 값 채우기)
  function renderLevels(type, maxLevel, existing) {
    const fields = LEVEL_FIELDS[type] || [];
    const c = $('levelsContainer');
    c.innerHTML = '';

    for (let lv = 1; lv <= maxLevel; lv++) {
      const row = document.createElement('div');
      row.className = 'lvl-row';
      row.dataset.level = String(lv);

      let html = `<span class="lvl-tag">Lv ${lv}</span>`;
      for (const f of fields) {
        let val = f.def;
        if (existing && existing[lv] && existing[lv][f.name] !== undefined) val = existing[lv][f.name];
        html += `<label>${f.label}</label>`;
        html += `<input type="number" step="0.01" data-field="${f.name}" value="${val}">`;
      }
      row.innerHTML = html;
      c.appendChild(row);
    }
  }

  function onTypeOrLevelChange() {
    const type = $('ability_type').value;
    let maxLevel = parseInt($('max_level').value, 10);
    if (isNaN(maxLevel) || maxLevel < 1) maxLevel = 1;

    updateBaseFields(type);
    renderLevels(type, maxLevel, currentExisting);
  }

  // 편집 폼을 지정한 위치로 이동시켜 연다.
  //  · mode 'edit' : referenceTr 바로 아래(해당 스킬 밑)에 인라인으로 끼워 넣는다.
  //  · mode 'new'  : 목록 맨 위(#topEditorHost)에 연다.
  function showEditorAt(mode, referenceTr) {
    const panel = $('editorPanel');
    document.querySelectorAll('tr.editor-row').forEach((tr) => tr.remove());

    if (mode === 'edit' && referenceTr) {
      const tr = document.createElement('tr');
      tr.className = 'editor-row';
      const td = document.createElement('td');
      td.colSpan = 30; // 표 너비보다 크게 잡아 전체 폭 병합
      td.appendChild(panel);
      tr.appendChild(td);
      referenceTr.parentNode.insertBefore(tr, referenceTr.nextSibling);
    } else {
      $('topEditorHost').appendChild(panel);
    }

    panel.style.display = 'block';
    panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  // 편집 폼을 닫고 기본 보관 위치로 되돌린다.
  function closeEditor() {
    const panel = $('editorPanel');
    panel.style.display = 'none';
    $('editorHome').appendChild(panel);
    document.querySelectorAll('tr.editor-row').forEach((tr) => tr.remove());
  }

  function openNew() {
    resetFields();
    $('formTitle').textContent = '새 스킬 추가';
    $('formTitle').style.color = 'var(--green)';
    $('submitBtn').textContent = 'DB에 저장 (Save)';
    $('ability_id').readOnly = false;
    showEditorAt('new');
    onTypeOrLevelChange();
  }

  function editAbility(btn, data) {
    $('formTitle').textContent = '스킬 수정 중: ' + data.display_name;
    $('formTitle').style.color = 'var(--blue)';
    $('submitBtn').textContent = '수정사항 덮어쓰기 (Update)';

    $('ability_id').value = data.ability_id;
    $('ability_type').value = data.ability_type;
    $('bit_index').value = data.bit_index;
    $('display_name').value = data.display_name;
    $('appear_stage').value = data.appear_stage;
    $('max_level').value = data.max_level;
    $('is_basic_skill').value = data.is_basic_skill ? '1' : '0';
    $('is_unlocked').value = data.is_unlocked ? '1' : '0';
    $('cooldown_seconds').value = data.cooldown_seconds;
    $('stamina_cost').value = data.stamina_cost;
    $('special_effect').value = data.special_effect;
    $('description').value = data.description;

    // 레벨 값을 {level: {...}} 맵으로 변환해 보존
    currentExisting = {};
    for (const l of data.levels || []) currentExisting[l.level] = l;

    // 해당 스킬 행 바로 아래(레벨 상세행이 있으면 그 아래)에 편집창을 끼워 넣는다.
    const mainTr = btn.closest('tr');
    let ref = mainTr;
    const nx = mainTr.nextElementSibling;
    if (nx && nx.classList.contains('detail-row')) ref = nx;

    showEditorAt('edit', ref);
    onTypeOrLevelChange();
  }

  function resetFields() {
    $('abilityForm').reset();
    $('special_effect').value = 'None';
    currentExisting = null;
  }

  /* ---------- 저장 ---------- */

  function collectPayload() {
    const type = $('ability_type').value;

    const levels = [...$('levelsContainer').querySelectorAll('.lvl-row')].map((row) => {
      const l = { level: Number(row.dataset.level) };
      row.querySelectorAll('input[data-field]').forEach((inp) => {
        l[inp.dataset.field] = parseFloat(inp.value) || 0;
      });
      return l;
    });

    return {
      ability_id: $('ability_id').value.trim(),
      ability_type: type,
      bit_index: parseInt($('bit_index').value, 10) || 0,
      display_name: $('display_name').value,
      description: $('description').value,
      appear_stage: parseInt($('appear_stage').value, 10) || 1,
      is_basic_skill: $('is_basic_skill').value,
      is_unlocked: $('is_unlocked').value,
      max_level: parseInt($('max_level').value, 10) || 1,
      cooldown_seconds: parseFloat($('cooldown_seconds').value) || 0,
      stamina_cost: parseFloat($('stamina_cost').value) || 0,
      special_effect: $('special_effect').value || 'None',
      levels,
    };
  }

  $('abilityForm').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    try {
      const r = await SR.post('/api/abilities/save', collectPayload());
      SR.showMessage(r.message, r.status === 'success' ? (r.tone || 'good') : 'danger');
      if (r.status === 'success') await load(); // load() 안에서 편집창이 닫힌다
    } catch (e) {
      SR.showMessage(e.message, 'danger');
    }
  });

  /* ---------- 이벤트 배선 ---------- */

  $('ability_type').addEventListener('change', onTypeOrLevelChange);
  $('max_level').addEventListener('change', onTypeOrLevelChange);
  $('addBtn').addEventListener('click', openNew);
  $('resetBtn').addEventListener('click', () => { resetFields(); onTypeOrLevelChange(); });
  $('closeBtn').addEventListener('click', closeEditor);
  $('expandAll').addEventListener('click', () => toggleAll(true));
  $('collapseAll').addEventListener('click', () => toggleAll(false));

  /* ---------- 시작 ---------- */

  (async () => {
    SR.mountHeaderTools();
    // 편집 폼을 기본 보관 위치로 옮겨 숨겨둔다(목록이 상단에 먼저 보이도록).
    $('editorHome').appendChild($('editorPanel'));
    onTypeOrLevelChange();

    if (!await SR.guard()) return;
    await load();
  })();
})();
