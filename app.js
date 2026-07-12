(() => {
  const $ = (id) => document.getElementById(id);
  let lastResult = null;
  let lastCompare = null;
  let browseDir = '/var/www/html/blackwoodcabinet-upgrade/var/profiler';

  /** Always hit bottleneck/index.php even if the page URL has no trailing slash. */
  function apiEndpoint() {
    let path = window.location.pathname;
    if (/index\.php$/i.test(path)) {
      return path;
    }
    if (!path.endsWith('/')) {
      path += '/';
    }
    return path + 'index.php';
  }

  /**
   * Always POST to index.php with a clean URL (no path in query string).
   * Ad blockers often block GET URLs containing "profiler" → ERR_BLOCKED_BY_CLIENT.
   */
  function apiFetch(params) {
    const url = new URL(apiEndpoint(), window.location.origin);
    const body = new URLSearchParams();
    Object.entries(params).forEach(([k, v]) => {
      if (v !== undefined && v !== null) body.set(k, String(v));
    });
    return fetch(url.toString(), {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body,
      credentials: 'same-origin',
      cache: 'no-store',
    });
  }

  function setBrowseTarget(target) {
    const sel = $('browseTarget');
    if (sel) sel.value = target;
    document.querySelectorAll('.path-field').forEach((el) => {
      el.classList.toggle('active-target', el.dataset.target === target);
    });
  }

  function fillPathTarget(target, filePath) {
    const input = $(target) || $('path');
    if (input) input.value = filePath;
    setBrowseTarget(target);
    showError('');
    // Mirror into Analyze path when choosing after (handy for re-analyze)
    if (target === 'pathAfter' || target === 'path') {
      $('path').value = filePath;
    }
  }
  // sort state per table key: { key: 'sec'|'calls', dir: 'desc'|'asc' }
  const sortState = {
    top: { key: 'sec', dir: 'desc' },
    kw: { key: 'sec', dir: 'desc' },
    callers: { key: 'sec', dir: 'desc' },
    callees: { key: 'sec', dir: 'desc' },
    compare: { key: 'delta_sec', dir: 'desc' },
  };

  function sortRows(rows, state) {
    if (!rows || !rows.length) return [];
    const sorted = rows.slice();
    const mul = state.dir === 'asc' ? 1 : -1;
    sorted.sort((a, b) => {
      let av, bv;
      if (state.key === 'calls') {
        av = a.calls ?? 0; bv = b.calls ?? 0;
      } else if (state.key === 'delta_sec') {
        av = Math.abs(a.delta_sec ?? 0); bv = Math.abs(b.delta_sec ?? 0);
      } else if (state.key === 'before_sec') {
        av = a.before_sec ?? 0; bv = b.before_sec ?? 0;
      } else if (state.key === 'after_sec') {
        av = a.after_sec ?? 0; bv = b.after_sec ?? 0;
      } else if (state.key === 'delta_calls') {
        av = Math.abs(a.delta_calls ?? 0); bv = Math.abs(b.delta_calls ?? 0);
      } else {
        av = a.sec ?? 0; bv = b.sec ?? 0;
      }
      if (av === bv) return String(a.name || '').localeCompare(String(b.name || ''));
      return av < bv ? -1 * mul : av > bv ? 1 * mul : 0;
    });
    return sorted;
  }

  function sortHeader(label, colKey, tableKey) {
    const st = sortState[tableKey];
    const active = st.key === colKey;
    const ind = active ? (st.dir === 'asc' ? '▲' : '▼') : '';
    const title = `Sort by ${label}`;
    return `<th class="sortable" data-sort-table="${tableKey}" data-sort-key="${colKey}" title="${esc(title)}">${esc(label)}<span class="sort-ind">${ind}</span></th>`;
  }

  function bindSortHeaders(root) {
    root.querySelectorAll('th.sortable').forEach(th => {
      th.addEventListener('click', (ev) => {
        ev.preventDefault();
        ev.stopPropagation();
        const tableKey = th.getAttribute('data-sort-table');
        const colKey = th.getAttribute('data-sort-key');
        if (!tableKey || !sortState[tableKey]) return;
        const st = sortState[tableKey];
        if (st.key === colKey) {
          st.dir = st.dir === 'desc' ? 'asc' : 'desc';
        } else {
          st.key = colKey;
          st.dir = 'desc';
        }
        rerenderSortedTables();
      });
    });
  }

  function rerenderSortedTables() {
    if (!lastResult) return;
    renderTopTab(lastResult);
    const activeId = lastResult.detail ? lastResult.detail.id : null;
    $('tab-kw').innerHTML = tableHtml(lastResult.keywords, activeId, 'kw');
    bindRowClicks($('tab-kw'));
    bindSortHeaders($('tab-kw'));
    // Re-render detail edge tables with current sort (keep detail body structure)
    if (lastResult.detail) {
      renderDetail(lastResult.detail);
    }
    if (lastCompare) {
      renderCompare(lastCompare);
    }
  }

  function editorName() {
    const v = $('editorApp')?.value || 'cursor';
    if (v === 'vscode' || v === 'sublime') return v;
    return 'cursor';
  }
  function editorLabel() {
    const n = editorName();
    if (n === 'vscode') return 'VS Code';
    if (n === 'sublime') return 'Sublime Text';
    return 'Cursor';
  }
  async function openInEditor(file, line, ev) {
    if (ev) ev.preventDefault();
    if (!file || file[0] !== '/') return;
    try {
      const res = await apiFetch({
        action: 'open',
        file,
        line: line ? String(line) : '',
        editor: editorName(),
      });
      const data = await res.json();
      if (!data.ok) {
        showError(data.error || 'Open failed');
        return;
      }
      if (data.uri) {
        showError('');
        window.location.href = data.uri;
      }
    } catch (e) {
      showError(String(e));
    }
  }
  function fileLinkHtml(file, line, label) {
    if (!file || file[0] !== '/') {
      return label ? `<span class="meta">${esc(label)}</span>` : '<span class="meta">—</span>';
    }
    const text = label || (short(file, 50) + (line ? ':' + line : ''));
    const title = file + (line ? ':' + line : '');
    const ed = editorLabel();
    return `<span class="file-ref" title="${esc(title)}"><span class="file-path">${esc(text)}</span>`
      + `<button type="button" class="icon-open" title="Open in ${ed}" data-file="${esc(file)}" data-line="${line || ''}">✎</button></span>`;
  }
  function bindFileOpens(root) {
    root.querySelectorAll('button.icon-open').forEach(btn => {
      btn.addEventListener('click', (ev) => {
        ev.preventDefault();
        ev.stopPropagation();
        openInEditor(btn.getAttribute('data-file'), btn.getAttribute('data-line'), ev);
      });
    });
  }

  function fmtSec(s) {
    if (s == null) return '—';
    if (s >= 10) return s.toFixed(2) + 's';
    if (s >= 1) return s.toFixed(3) + 's';
    return (s * 1000).toFixed(1) + 'ms';
  }
  function fmtDeltaSimple(sec) {
    if (sec == null) return '—';
    const abs = Math.abs(sec);
    let body;
    if (abs >= 10) body = abs.toFixed(2) + 's';
    else if (abs >= 1) body = abs.toFixed(3) + 's';
    else body = (abs * 1000).toFixed(1) + 'ms';
    const sign = sec > 0 ? '+' : (sec < 0 ? '-' : '');
    const cls = sec < -0.001 ? 'delta-neg' : (sec > 0.001 ? 'delta-pos' : 'delta-zero');
    return `<span class="${cls}">${sign}${body}</span>`;
  }
  function fmtDeltaCalls(n) {
    n = Number(n) || 0;
    const sign = n > 0 ? '+' : (n < 0 ? '-' : '');
    const cls = n < 0 ? 'delta-neg' : (n > 0 ? 'delta-pos' : 'delta-zero');
    return `<span class="${cls}">${sign}${Math.abs(n)}</span>`;
  }

  function renderCompare(data) {
    lastCompare = data;
    const box = $('tab-compare');
    if (!data || !data.rows) {
      box.innerHTML = '<div class="empty">No compare data.</div>';
      return;
    }
    const rows = sortRows(data.rows, sortState.compare);
    let html = `<div class="stats" style="margin-bottom:.75rem">
      <div class="stat"><div class="v">${fmtSec(data.before_main_sec)}</div><div class="l">Before {main}</div></div>
      <div class="stat"><div class="v">${fmtSec(data.after_main_sec)}</div><div class="l">After {main}</div></div>
      <div class="stat"><div class="v">${fmtDeltaSimple(data.delta_main_sec)}</div><div class="l">Delta (after − before)</div></div>
    </div>
    <p class="hint" style="margin-top:0">Before: <code>${esc(data.before)}</code><br>After: <code>${esc(data.after)}</code><br>
    Green delta = faster after. Red = slower.</p>
    <table><thead><tr>
      ${sortHeader('Δ Time', 'delta_sec', 'compare')}
      ${sortHeader('Before', 'before_sec', 'compare')}
      ${sortHeader('After', 'after_sec', 'compare')}
      ${sortHeader('Δ Calls', 'delta_calls', 'compare')}
      <th>File</th><th>Function</th>
    </tr></thead><tbody>`;
    for (const r of rows) {
      html += `<tr>
        <td class="num">${fmtDeltaSimple(r.delta_sec)}</td>
        <td class="num">${fmtSec(r.before_sec)}</td>
        <td class="num">${fmtSec(r.after_sec)}</td>
        <td class="num">${fmtDeltaCalls(r.delta_calls)}</td>
        <td>${fileLinkHtml(r.file, r.line)}</td>
        <td class="name" title="${esc(r.name)}">${esc(short(r.name, 90))}</td>
      </tr>`;
    }
    html += `</tbody></table>`;
    box.innerHTML = html;
    bindFileOpens(box);
    bindSortHeaders(box);
  }

  async function compareProfiles() {
    showError('');
    const before = $('pathBefore').value.trim();
    const after = $('pathAfter').value.trim();
    if (!before || !after) {
      showError('Set both Compare before and Compare after (use Before/After buttons in the file list).');
      return;
    }
    const btn = $('btnCompare');
    btn.disabled = true;
    btn.textContent = 'Comparing…';
    try {
      const res = await apiFetch({
        action: 'compare',
        before,
        after,
        min_sec: $('minSec').value,
        top: $('topN').value,
      });
      const data = await res.json();
      if (!data.ok) { showError(data.error || 'Compare failed'); return; }
      renderCompare(data);
      switchTab('compare');
    } catch (e) {
      showError(String(e));
    } finally {
      btn.disabled = false;
      btn.textContent = 'Compare';
    }
  }

  function fmtBytes(n) {
    if (!n) return '—';
    if (n > 1e9) return (n/1e9).toFixed(2) + ' GB';
    if (n > 1e6) return (n/1e6).toFixed(1) + ' MB';
    return (n/1e3).toFixed(0) + ' KB';
  }
  function esc(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }
  function short(s, n=90) {
    s = String(s);
    return s.length > n ? s.slice(0, n-1) + '…' : s;
  }

  function showError(msg) {
    const el = $('sideError');
    if (!msg) { el.classList.add('hidden'); el.textContent=''; return; }
    el.textContent = msg;
    el.classList.remove('hidden');
  }

  async function browse(dir) {
    showError('');
    const box = $('browser');
    if (box) {
      box.innerHTML = '<div class="crumb">Loading…</div>';
    }
    try {
      const res = await apiFetch({ action: 'list_dir', dir: dir || browseDir });
      const text = await res.text();
      let data;
      try {
        data = JSON.parse(text);
      } catch (e) {
        showError('Browse failed: server did not return JSON. Check that index.php is reachable.');
        if (box) box.innerHTML = '<div class="crumb">Browse failed — see error above.</div>';
        console.error('Browse raw response', text.slice(0, 500));
        return;
      }
      if (!data.ok) {
        showError(data.error || 'Browse failed');
        if (box) box.innerHTML = '<div class="crumb">' + esc(data.error || 'Browse failed') + '</div>';
        return;
      }
      browseDir = data.dir;
      // Only sync Analyze path to the folder when that target is selected
      if (($('browseTarget')?.value || 'path') === 'path') {
        $('path').value = data.dir;
      }

      const targetLabel = $('browseTarget')?.selectedOptions?.[0]?.text || 'Analyze path';
      let html = `<div class="crumb">
        <span class="crumb-path">${esc(data.dir)}</span>
        <span class="crumb-help">Click a file to fill <b>${esc(targetLabel)}</b>, or use Before / After buttons. Folders navigate.</span>
      </div>`;
      if (data.parent) {
        html += `<div class="item dir" data-path="${esc(data.parent)}" data-dir="1"><div class="item-main">⬆ ..</div></div>`;
      }
      for (const root of (data.roots || [])) {
        if (root !== data.dir) {
          html += `<div class="item dir" data-path="${esc(root)}" data-dir="1"><div class="item-main">🏠 ${esc(root)}</div></div>`;
        }
      }
      for (const e of data.entries) {
        const cls = e.is_dir ? 'dir' : (e.is_profile ? 'file profile' : 'file');
        const meta = e.is_dir ? 'dir' : fmtBytes(e.size);
        const icon = e.is_dir ? '📁' : (e.is_profile ? '📊' : '📄');
        const openBtn = (!e.is_dir && !e.is_profile)
          ? `<button type="button" class="icon-open" title="Open in editor" data-file="${esc(e.path)}" data-line="">✎</button>`
          : '';
        const pickBtns = (!e.is_dir && e.is_profile)
          ? `<button type="button" class="pick-btn" data-pick="path" data-file="${esc(e.path)}" title="Use for Analyze">Analyze</button>`
            + `<button type="button" class="pick-btn" data-pick="pathBefore" data-file="${esc(e.path)}" title="Use as Compare before">Before</button>`
            + `<button type="button" class="pick-btn" data-pick="pathAfter" data-file="${esc(e.path)}" title="Use as Compare after">After</button>`
            + `<button type="button" class="pick-btn danger" data-delete="${esc(e.path)}" title="Delete this cachegrind file">Delete</button>`
          : '';
        html += `<div class="item ${cls}" data-path="${esc(e.path)}" data-dir="${e.is_dir ? 1 : 0}" data-profile="${e.is_profile ? 1 : 0}">
          <div class="item-main"><span class="name">${icon} ${esc(e.name)}</span></div>
          <div class="item-actions">${pickBtns}<span class="meta">${meta}</span>${openBtn}</div>
        </div>`;
      }
      if (!data.entries.length) {
        html += `<div class="item"><span class="meta">Empty folder</span></div>`;
      }
      if (data.truncated > 0) {
        html += `<div class="item"><span class="meta">… ${data.truncated} more entries not shown</span></div>`;
      }
      box.innerHTML = html;

      box.querySelectorAll('.item[data-path]').forEach((el) => {
        el.addEventListener('click', (ev) => {
          if (ev.target.closest && (ev.target.closest('button.icon-open') || ev.target.closest('button.pick-btn'))) return;
          const p = el.getAttribute('data-path');
          const isDir = el.getAttribute('data-dir') === '1';
          if (isDir) {
            browse(p);
            return;
          }
          const target = $('browseTarget')?.value || 'path';
          fillPathTarget(target, p);
          box.querySelectorAll('.item.selected').forEach((x) => x.classList.remove('selected'));
          el.classList.add('selected');
        });
      });

      box.querySelectorAll('button.pick-btn[data-pick]').forEach((btn) => {
        btn.addEventListener('click', (ev) => {
          ev.preventDefault();
          ev.stopPropagation();
          const target = btn.getAttribute('data-pick');
          const file = btn.getAttribute('data-file');
          fillPathTarget(target, file);
          box.querySelectorAll('.item.selected').forEach((x) => x.classList.remove('selected'));
          btn.closest('.item')?.classList.add('selected');
        });
      });

      box.querySelectorAll('button.pick-btn[data-delete]').forEach((btn) => {
        btn.addEventListener('click', async (ev) => {
          ev.preventDefault();
          ev.stopPropagation();
          const file = btn.getAttribute('data-delete');
          if (!file) return;
          const name = file.split('/').pop();
          if (!confirm(`Delete ${name}?\n\nThis cannot be undone.`)) return;
          btn.disabled = true;
          try {
            const res = await apiFetch({ action: 'delete_profile', path: file });
            const data = await res.json();
            if (!data.ok) {
              showError(data.error || 'Delete failed');
              btn.disabled = false;
              return;
            }
            ['path', 'pathBefore', 'pathAfter'].forEach((id) => {
              const input = $(id);
              if (input && input.value.trim() === file) input.value = '';
            });
            await browse(data.dir || browseDir);
          } catch (e) {
            showError(String(e));
            btn.disabled = false;
          }
        });
      });

      bindFileOpens(box);
      setBrowseTarget($('browseTarget')?.value || 'path');
    } catch (e) {
      showError('Browse error: ' + e);
      if (box) box.innerHTML = '<div class="crumb">Browse error — see message above.</div>';
      console.error(e);
    }
  }

  const TOP_STEP = 40;
  const TOP_MAX = 1000;

  function requestedTop() {
    return Math.min(TOP_MAX, Math.max(5, parseInt($('topN').value, 10) || 40));
  }

  function loadMoreHtml(rowCount) {
    const top = requestedTop();
    if (!rowCount) return '';
    if (rowCount < top) {
      return `<div class="load-more"><span class="meta">Showing all ${rowCount} rows above min seconds.</span></div>`;
    }
    if (top >= TOP_MAX) {
      return `<div class="load-more"><span class="meta">Showing top ${rowCount} (max ${TOP_MAX}). Raise min seconds to narrow the set.</span></div>`;
    }
    const next = Math.min(TOP_MAX, top + TOP_STEP);
    return `<div class="load-more">
      <span class="meta">Showing top ${rowCount}.</span>
      <button type="button" id="btnLoadMoreTop">Load more → Top ${next}</button>
    </div>`;
  }

  function bindLoadMore() {
    const btn = $('btnLoadMoreTop');
    if (!btn) return;
    btn.addEventListener('click', async () => {
      const next = Math.min(TOP_MAX, requestedTop() + TOP_STEP);
      $('topN').value = String(next);
      btn.disabled = true;
      btn.textContent = 'Loading…';
      switchTab('top');
      await analyze({ quiet: true });
    });
  }

  function renderTopTab(data) {
    const activeId = data.detail ? data.detail.id : null;
    const rows = data.top || [];
    $('tab-top').innerHTML = tableHtml(rows, activeId, 'top') + loadMoreHtml(rows.length);
    bindRowClicks($('tab-top'));
    bindSortHeaders($('tab-top'));
    bindLoadMore();
  }

  function tableHtml(rows, activeId, tableKey = 'top') {
    if (!rows || !rows.length) return '<div class="empty">No rows above the min-seconds threshold.</div>';
    const sorted = sortRows(rows, sortState[tableKey] || { key: 'sec', dir: 'desc' });
    let html = `<table><thead><tr>`
      + sortHeader('Time', 'sec', tableKey)
      + sortHeader('Calls', 'calls', tableKey)
      + `<th>File</th><th>Function</th></tr></thead><tbody>`;
    for (const r of sorted) {
      const active = activeId && r.id === activeId ? ' active' : '';
      html += `<tr class="clickable${active}" data-id="${r.id}">
        <td class="num">${fmtSec(r.sec)}</td>
        <td class="num">${r.calls ?? 0}</td>
        <td>${fileLinkHtml(r.file, r.line)}</td>
        <td class="name" title="${esc(r.name)}">${esc(short(r.name, 100))}</td>
      </tr>`;
    }
    html += `</tbody></table>`;
    return html;
  }

  function renderDetail(detail) {
    const box = $('detailBody');
    if (!detail) {
      box.innerHTML = '<div class="empty">No function selected.</div>';
      return;
    }
    const file = detail.file && String(detail.file)[0] === '/' ? detail.file : null;
    let html = `<p class="detail-title">${esc(detail.name)}</p>`;
    html += `<div class="stats">
      <div class="stat"><div class="v">${fmtSec(detail.sec)}</div><div class="l">Inclusive time</div></div>
      <div class="stat"><div class="v">${detail.calls}</div><div class="l">Calls into this fn</div></div>
      <div class="stat source-stat">
        <div class="v" style="font-size:.8rem;line-height:1.35">${file ? fileLinkHtml(file, detail.line, short(file, 52)) : esc(short(detail.file_hint || '—', 52))}</div>
        <div class="l">Source file ${file ? `<button type="button" class="open-editor primary" id="btnOpenSrc">Open in ${esc(editorLabel())}</button>` : '<span class="meta">(path unresolved)</span>'}</div>
      </div>
    </div>`;

    html += `<h2>Backtrace (who called this) — use ✎ or Open in ${esc(editorLabel())}</h2>`;
    if (detail.backtrace && detail.backtrace.length) {
      html += `<div class="backtrace">`;
      detail.backtrace.forEach((path, i) => {
        html += `<div class="path"><div class="meta">Path ${i+1}</div>`;
        path.forEach((step, idx) => {
          const prefix = idx === 0 ? '' : '<span class="arrow">→</span>';
          const canOpen = step.file && String(step.file)[0] === '/';
          const openBtn = canOpen
            ? `<button type="button" class="open-editor bt-open" data-file="${esc(step.file)}" data-line="${step.line || ''}">Open in ${esc(editorLabel())}</button>`
            : '';
          const fileBit = canOpen
            ? fileLinkHtml(step.file, step.line, short(String(step.file).split('/').pop() + (step.line ? ':' + step.line : ''), 36))
            : '';
          html += `<div class="step">
            ${prefix}<a href="#" class="fnlink" data-id="${step.id}" title="Show detail">${esc(short(step.name, 80))}</a>
            <span class="meta">(${fmtSec(step.sec)})</span>
            ${fileBit}
            ${openBtn}
          </div>`;
        });
        html += `</div>`;
      });
      html += `</div>`;
    } else {
      html += `<div class="empty">No caller edges recorded for this function.</div>`;
    }

    html += `<h2>Called by (callers)</h2>` + edgeTable(detail.callers, 'callers');
    html += `<h2>Calls (callees)</h2>` + edgeTable(detail.callees, 'callees');
    box.innerHTML = html;
    if (file) {
      const btn = $('btnOpenSrc');
      if (btn) btn.addEventListener('click', (ev) => openInEditor(file, detail.line, ev));
    }
    box.querySelectorAll('.bt-open').forEach(btn => {
      btn.addEventListener('click', (ev) => {
        ev.stopPropagation();
        openInEditor(btn.getAttribute('data-file'), btn.getAttribute('data-line'), ev);
      });
    });
    box.querySelectorAll('.fnlink').forEach(el => {
      el.addEventListener('click', (ev) => {
        ev.preventDefault();
        const id = parseInt(el.getAttribute('data-id'), 10);
        if (id) loadDetail(id);
      });
    });
    box.querySelectorAll('tr.clickable').forEach(el => {
      el.addEventListener('click', (ev) => {
        if (ev.target.closest && ev.target.closest('button.icon-open')) return;
        const id = parseInt(el.getAttribute('data-id'), 10);
        if (id) loadDetail(id);
      });
    });
    bindFileOpens(box);
    bindSortHeaders(box);
  }

  function edgeTable(rows, tableKey = 'callers') {
    if (!rows || !rows.length) return '<div class="empty">None</div>';
    const sorted = sortRows(rows, sortState[tableKey] || { key: 'sec', dir: 'desc' });
    let html = `<div class="panel-scroll"><table><thead><tr>`
      + sortHeader('Edge time', 'sec', tableKey)
      + sortHeader('Calls', 'calls', tableKey)
      + `<th>File</th><th>Function</th></tr></thead><tbody>`;
    for (const r of sorted) {
      html += `<tr class="clickable" data-id="${r.id}">
        <td class="num">${fmtSec(r.sec)}</td>
        <td class="num">${r.calls}</td>
        <td>${fileLinkHtml(r.file, r.line)}</td>
        <td class="name">${esc(short(r.name, 100))}</td>
      </tr>`;
    }
    return html + `</tbody></table></div>`;
  }

  function renderResult(data) {
    lastResult = data;
    $('summaryEmpty').classList.add('hidden');
    $('summaryBody').classList.remove('hidden');
    $('statMain').textContent = fmtSec(data.main_sec);
    $('statParse').textContent = (data.parse_ms / 1000).toFixed(1) + 's';
    $('statSize').textContent = fmtBytes(data.size_bytes);
    const hot = (data.top || []).find(r => r.name !== '{main}') || (data.top || [])[0];
    $('statHot').textContent = hot ? fmtSec(hot.sec) : '—';

    const ul = $('insights');
    ul.innerHTML = (data.insights || []).map(t => `<li>${esc(t)}</li>`).join('') || '<li>No insights.</li>';

    const activeId = data.detail ? data.detail.id : null;
    renderTopTab(data);
    $('tab-kw').innerHTML = tableHtml(data.keywords, activeId, 'kw');
    bindRowClicks($('tab-kw'));
    bindSortHeaders($('tab-kw'));
    renderDetail(data.detail);
  }

  function bindRowClicks(root) {
    bindFileOpens(root);
    root.querySelectorAll('tr.clickable').forEach(tr => {
      tr.addEventListener('click', (ev) => {
        if (ev.target.closest && ev.target.closest('button.icon-open')) return;
        const id = parseInt(tr.getAttribute('data-id'), 10);
        if (id) {
          switchTab('detail');
          loadDetail(id);
        }
      });
    });
  }

  async function analyze(extra = {}) {
    showError('');
    const path = $('path').value.trim();
    if (!path) { showError('Enter or browse to a cachegrind file path'); return; }
    // If path is a directory, just browse
    if (!path.split('/').pop().startsWith('cachegrind')) {
      await browse(path);
      showError('Select a cachegrind.out.* file from the browser list, then Analyze.');
      return;
    }
    const quiet = !!extra.quiet;
    delete extra.quiet;
    const top = requestedTop();
    $('topN').value = String(top);
    const btn = $('btnAnalyze');
    const prevLabel = btn.textContent;
    btn.disabled = true;
    if (!quiet) btn.textContent = 'Analyzing… (large files take 1–2 min)';
    try {
      const res = await apiFetch({
        action: 'analyze',
        path,
        min_sec: $('minSec').value,
        top: String(top),
        ...extra,
      });
      const data = await res.json();
      if (!data.ok) {
        showError(data.error || 'Analyze failed');
        if (quiet && lastResult) renderTopTab(lastResult);
        return;
      }
      renderResult(data);
      if (extra.fn_id || extra.find) switchTab('detail');
    } catch (e) {
      showError(String(e));
      if (quiet && lastResult) renderTopTab(lastResult);
    } finally {
      btn.disabled = false;
      btn.textContent = quiet ? prevLabel : 'Analyze';
    }
  }

  async function loadDetail(fnId) {
    const path = $('path').value.trim();
    try {
      const res = await apiFetch({
        action: 'analyze',
        path,
        min_sec: $('minSec').value,
        top: $('topN').value,
        fn_id: String(fnId),
        detail_only: '1',
      });
      const data = await res.json();
      if (!data.ok) { showError(data.error); return; }
      if (lastResult) {
        data.top = lastResult.top;
        data.keywords = lastResult.keywords;
        data.insights = lastResult.insights;
        data.main_sec = lastResult.main_sec;
        data.size_bytes = lastResult.size_bytes;
        data.parse_ms = data.cached ? lastResult.parse_ms : data.parse_ms;
      }
      renderResult(data);
    } catch (e) {
      showError(String(e));
    }
  }

  function switchTab(name) {
    document.querySelectorAll('.tabs button').forEach(b => b.classList.toggle('active', b.dataset.tab === name));
    ['top','kw','detail','compare'].forEach(t => {
      const el = document.getElementById('tab-' + t);
      if (el) el.classList.toggle('hidden', t !== name);
    });
  }

  document.querySelectorAll('.tabs button').forEach(b => {
    b.addEventListener('click', () => switchTab(b.dataset.tab));
  });
  $('btnAnalyze').addEventListener('click', () => analyze());
  $('btnCompare').addEventListener('click', () => compareProfiles());
  $('btnFind').addEventListener('click', () => {
    const find = $('findFn').value.trim();
    if (!find) return;
    analyze({ find });
  });
  $('browseTarget')?.addEventListener('change', () => {
    setBrowseTarget($('browseTarget').value);
  });

  // Clicking a path field sets it as the browse target
  ['path', 'pathBefore', 'pathAfter'].forEach((id) => {
    const el = $(id);
    if (!el) return;
    el.addEventListener('focus', () => setBrowseTarget(id));
  });

  const savedEditor = localStorage.getItem('bottleneck.editor');
  if (savedEditor === 'vscode' || savedEditor === 'cursor' || savedEditor === 'sublime') {
    $('editorApp').value = savedEditor;
  }
  $('editorApp').addEventListener('change', () => localStorage.setItem('bottleneck.editor', $('editorApp').value));

  setBrowseTarget('path');
  browse(browseDir);
})();
