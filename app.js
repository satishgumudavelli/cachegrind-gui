(() => {
  const $ = (id) => document.getElementById(id);
  let lastResult = null;
  let lastCompare = null;
  let lastBrowse = null;
  let browseDir = '/var/www/html/blackwoodcabinet-upgrade/var/profiler';
  let analyzeAbort = null;
  /** Previous browse folders for Back button (most recent at end). */
  const browseHistory = [];
  const BROWSE_HISTORY_MAX = 40;
  /** Previous function details when drilling through backtrace / callers / callees. */
  const detailHistory = [];
  const DETAIL_HISTORY_MAX = 40;

  function pushDetailHistory(fnId) {
    if (!fnId) return;
    if (detailHistory[detailHistory.length - 1] === fnId) return;
    detailHistory.push(fnId);
    if (detailHistory.length > DETAIL_HISTORY_MAX) detailHistory.shift();
  }

  function clearDetailHistory() {
    detailHistory.length = 0;
  }

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
  function apiFetch(params, opts = {}) {
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
      signal: opts.signal,
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
  const sortState = {
    top: { key: 'sec', dir: 'desc' },
    kw: { key: 'sec', dir: 'desc' },
    callers: { key: 'sec', dir: 'desc' },
    callees: { key: 'sec', dir: 'desc' },
    compare: { key: 'delta_sec', dir: 'desc' },
    modules: { key: 'self_sec', dir: 'desc' },
    plugins: { key: 'self_sec', dir: 'desc' },
    apis: { key: 'sec', dir: 'desc' },
    apiServices: { key: 'sec', dir: 'desc' },
  };

  function currentSortBy() {
    return ($('sortBy')?.value === 'self') ? 'self' : 'incl';
  }

  function currentScopeFilter() {
    return $('scopeFilter')?.value || 'all';
  }

  function rowPassesScope(row) {
    const f = currentScopeFilter();
    const scope = row.scope || 'other';
    if (f === 'all') return true;
    if (f === 'app') return scope === 'app';
    if (f === 'vendor') return scope === 'vendor';
    if (f === 'framework') return scope === 'framework' || scope === 'php';
    if (f === 'hide_vendor') return scope !== 'vendor' && scope !== 'framework' && scope !== 'php';
    return true;
  }

  function filterRows(rows) {
    return (rows || []).filter(rowPassesScope);
  }

  function sortRows(rows, state) {
    if (!rows || !rows.length) return [];
    const sorted = rows.slice();
    const mul = state.dir === 'asc' ? 1 : -1;
    sorted.sort((a, b) => {
      let av, bv;
      if (state.key === 'calls') {
        av = a.calls ?? 0; bv = b.calls ?? 0;
      } else if (state.key === 'self_sec') {
        av = a.self_sec ?? 0; bv = b.self_sec ?? 0;
      } else if (state.key === 'functions') {
        av = a.functions ?? 0; bv = b.functions ?? 0;
      } else if (state.key === 'pct') {
        av = a.pct ?? 0; bv = b.pct ?? 0;
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
      if (av === bv) return String(a.name || a.module || '').localeCompare(String(b.name || b.module || ''));
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
    $('tab-kw').innerHTML = tableHtml(filterRows(lastResult.keywords), activeId, 'kw');
    bindRowClicks($('tab-kw'));
    bindSortHeaders($('tab-kw'));
    renderModules(lastResult);
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

  async function browse(dir, opts = {}) {
    showError('');
    const box = $('browser');
    const fromHistory = !!opts.fromHistory;
    const prevDir = browseDir;
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

      // Push previous folder when navigating forward to a different dir
      if (!fromHistory && prevDir && data.dir && prevDir !== data.dir) {
        if (browseHistory[browseHistory.length - 1] !== prevDir) {
          browseHistory.push(prevDir);
          if (browseHistory.length > BROWSE_HISTORY_MAX) browseHistory.shift();
        }
      }

      browseDir = data.dir;
      lastBrowse = data;
      // Only sync Analyze path to the folder when that target is selected
      if (($('browseTarget')?.value || 'path') === 'path') {
        $('path').value = data.dir;
      }

      const targetLabel = $('browseTarget')?.selectedOptions?.[0]?.text || 'Analyze path';
      const canHistoryBack = browseHistory.length > 0;
      const canParentBack = !!data.parent;
      let html = `<div class="crumb">
        <div class="crumb-nav">
          <button type="button" class="crumb-back" id="btnBrowseBack" ${canHistoryBack || canParentBack ? '' : 'disabled'} title="${canHistoryBack ? 'Previous folder' : (canParentBack ? 'Parent folder' : 'No previous folder')}">← Back</button>
          ${canParentBack ? `<button type="button" class="crumb-up" id="btnBrowseUp" data-path="${esc(data.parent)}" title="Parent folder">⬆ Up</button>` : ''}
        </div>
        <span class="crumb-path">${esc(data.dir)}</span>
        <span class="crumb-help">Click a file to fill <b>${esc(targetLabel)}</b>, or use Before / After buttons. Folders navigate.${data.baseline ? ' Baseline: <code>' + esc(data.baseline.split('/').pop()) + '</code>.' : ''}</span>
      </div>`;
      if (data.parent) {
        html += `<div class="item dir" data-path="${esc(data.parent)}" data-dir="1"><div class="item-main">⬆ ..</div></div>`;
      }
      for (const root of (data.roots || [])) {
        if (root !== data.dir) {
          html += `<div class="item dir" data-path="${esc(root)}" data-dir="1"><div class="item-main">🏠 ${esc(root)}</div></div>`;
        }
      }

      const endpoints = data.endpoints && typeof data.endpoints === 'object' ? data.endpoints : {};
      const epKeys = Object.keys(endpoints);
      if (epKeys.length) {
        html += `<div class="endpoint-groups"><h3>By endpoint</h3>`;
        for (const ep of epKeys.sort()) {
          const names = endpoints[ep] || [];
          html += `<div class="endpoint-group"><span class="ep">${esc(short(ep, 60))}</span>`;
          for (const name of names) {
            const full = data.dir.replace(/\/$/, '') + '/' + name;
            html += `<button type="button" class="pick-btn" data-pick="path" data-file="${esc(full)}" title="${esc(name)}">${esc(short(name.replace(/^cachegrind\.out\.?/, ''), 18))}</button>`;
          }
          html += `</div>`;
        }
        html += `</div>`;
      }

      for (const e of data.entries) {
        const cls = e.is_dir ? 'dir' : (e.is_profile ? 'file profile' : 'file');
        const meta = e.is_dir ? 'dir' : fmtBytes(e.size);
        const icon = e.is_dir ? '📁' : (e.is_profile ? '📊' : '📄');
        const badges = [];
        if (e.is_baseline) badges.push('<span class="badge baseline">baseline</span>');
        if (e.label) badges.push(`<span class="badge">${esc(short(e.label, 28))}</span>`);
        if (e.endpoint) badges.push(`<span class="badge endpoint">${esc(short(e.endpoint, 32))}</span>`);
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
          <div class="item-main"><span class="name">${icon} ${esc(e.name)}</span>${badges.join('')}</div>
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

      const btnBack = $('btnBrowseBack');
      if (btnBack) {
        btnBack.addEventListener('click', (ev) => {
          ev.preventDefault();
          ev.stopPropagation();
          if (browseHistory.length) {
            const prev = browseHistory.pop();
            browse(prev, { fromHistory: true });
            return;
          }
          if (data.parent) browse(data.parent, { fromHistory: true });
        });
      }
      const btnUp = $('btnBrowseUp');
      if (btnUp) {
        btnUp.addEventListener('click', (ev) => {
          ev.preventDefault();
          ev.stopPropagation();
          const p = btnUp.getAttribute('data-path');
          if (p) browse(p);
        });
      }

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
    const rows = filterRows(data.top || []);
    $('tab-top').innerHTML = tableHtml(rows, activeId, 'top') + loadMoreHtml(rows.length);
    bindRowClicks($('tab-top'));
    bindSortHeaders($('tab-top'));
    bindLoadMore();
  }

  function scopeTag(scope) {
    if (!scope || scope === 'other') return '';
    return `<span class="scope-tag ${esc(scope)}">${esc(scope)}</span>`;
  }

  function tableHtml(rows, activeId, tableKey = 'top') {
    if (!rows || !rows.length) return '<div class="empty">No rows above the min-seconds threshold (or filtered out by scope).</div>';
    const sorted = sortRows(rows, sortState[tableKey] || { key: 'sec', dir: 'desc' });
    const showSelf = currentSortBy() === 'self' || tableKey === 'top';
    let html = `<table><thead><tr>`
      + sortHeader(showSelf && currentSortBy() === 'self' ? 'Self' : 'Time', currentSortBy() === 'self' ? 'self_sec' : 'sec', tableKey)
      + (showSelf && currentSortBy() !== 'self' ? sortHeader('Self', 'self_sec', tableKey) : '')
      + sortHeader('Calls', 'calls', tableKey)
      + `<th>File</th><th>Function</th></tr></thead><tbody>`;
    for (const r of sorted) {
      const active = activeId && r.id === activeId ? ' active' : '';
      const timeVal = currentSortBy() === 'self' ? (r.self_sec ?? r.sec) : r.sec;
      html += `<tr class="clickable${active}" data-id="${r.id}">
        <td class="num">${fmtSec(timeVal)}</td>`
        + (showSelf && currentSortBy() !== 'self' ? `<td class="num">${fmtSec(r.self_sec ?? 0)}</td>` : '')
        + `<td class="num">${r.calls ?? 0}</td>
        <td>${fileLinkHtml(r.file, r.line)}</td>
        <td class="name" title="${esc(r.name)}">${scopeTag(r.scope)}${esc(short(r.name, 100))}</td>
      </tr>`;
    }
    html += `</tbody></table>`;
    return html;
  }

  function renderModules(data) {
    const box = $('tab-modules');
    if (!box) return;
    const rows = filterRows(data.modules || []);
    if (!rows.length) {
      box.innerHTML = '<div class="empty">No Magento Vendor\\Module hotspots found (or filtered out).</div>';
      return;
    }
    const sorted = sortRows(rows, sortState.modules);
    let html = `<p class="hint" style="margin-top:0">Self-time rolled up by <code>Vendor\\Module</code>. Prefer this for Magento blame.</p>
      <table><thead><tr>
      ${sortHeader('Self', 'self_sec', 'modules')}
      ${sortHeader('Peak incl', 'sec', 'modules')}
      ${sortHeader('Fns', 'functions', 'modules')}
      <th>Module</th></tr></thead><tbody>`;
    for (const r of sorted) {
      html += `<tr>
        <td class="num">${fmtSec(r.self_sec)}</td>
        <td class="num">${fmtSec(r.sec)}</td>
        <td class="num">${r.functions}</td>
        <td class="name">${scopeTag(r.scope)}${esc(r.module)}</td>
      </tr>`;
    }
    html += `</tbody></table>`;
    box.innerHTML = html;
    bindSortHeaders(box);
  }

  function pluginKindLabel(kind) {
    if (kind === 'interceptor') return 'Interceptor';
    if (kind === 'plugin') return 'Plugin';
    if (kind === 'plugin_list') return 'PluginList';
    return kind || '—';
  }

  function renderPlugins(data) {
    const box = $('tab-plugins');
    if (!box) return;
    const plugins = data.plugins;
    if (!plugins || (!(plugins.hotspots || []).length && !(plugins.kinds || []).length)) {
      box.innerHTML = '<div class="empty">No Interceptor / Plugin / PluginList frames above the min-seconds threshold.</div>';
      return;
    }
    let html = `<p class="hint" style="margin-top:0">Magento interception tax: time in <code>Interceptor</code>, <code>Plugin</code> (before/around/after), and <code>PluginList</code> plumbing. Self-time sums avoid double-counting inclusive stacks.</p>
      <div class="stats" style="margin-bottom:.75rem">
        <div class="stat"><div class="v">${fmtSec(plugins.total_self_sec || 0)}</div><div class="l">Plugin tax (self)</div></div>
        <div class="stat"><div class="v">${(plugins.pct_of_main || 0).toFixed(1)}%</div><div class="l">of {main}</div></div>
      </div>`;
    if ((plugins.kinds || []).length) {
      html += `<h2 style="margin-top:0">By kind</h2><table><thead><tr>
        <th>Self</th><th>Peak incl</th><th>Fns</th><th>Calls</th><th>Kind</th>
      </tr></thead><tbody>`;
      for (const k of plugins.kinds) {
        html += `<tr>
          <td class="num">${fmtSec(k.self_sec)}</td>
          <td class="num">${fmtSec(k.sec)}</td>
          <td class="num">${k.functions}</td>
          <td class="num">${k.calls}</td>
          <td class="name"><span class="scope-tag framework">${esc(pluginKindLabel(k.kind))}</span></td>
        </tr>`;
      }
      html += `</tbody></table>`;
    }
    const rows = filterRows(plugins.hotspots || []);
    const sorted = sortRows(rows, sortState.plugins);
    html += `<h2>Hotspots</h2>`;
    if (!sorted.length) {
      html += `<div class="empty">No hotspots pass the current scope filter.</div>`;
    } else {
      html += `<table><thead><tr>
        ${sortHeader('Self', 'self_sec', 'plugins')}
        ${sortHeader('Incl', 'sec', 'plugins')}
        ${sortHeader('Calls', 'calls', 'plugins')}
        <th>Kind</th><th>File</th><th>Function</th>
      </tr></thead><tbody>`;
      for (const r of sorted) {
        html += `<tr class="clickable" data-id="${r.id}">
          <td class="num">${fmtSec(r.self_sec)}</td>
          <td class="num">${fmtSec(r.sec)}</td>
          <td class="num">${r.calls}</td>
          <td>${esc(pluginKindLabel(r.kind))}</td>
          <td>${fileLinkHtml(r.file, r.line)}</td>
          <td class="name" title="${esc(r.name)}">${scopeTag(r.scope)}${esc(short(r.name, 90))}</td>
        </tr>`;
      }
      html += `</tbody></table>`;
    }
    box.innerHTML = html;
    bindRowClicks(box);
    bindSortHeaders(box);
  }

  function renderApis(data) {
    const box = $('tab-apis');
    if (!box) return;
    const apis = data.apis || {};
    const services = apis.services || [];
    const calls = apis.calls || [];
    if (!services.length && !calls.length) {
      box.innerHTML = '<div class="empty">No HTTP / network call sites found in this Cachegrind profile above the min-seconds threshold.</div>';
      return;
    }
    const main = data.main_sec || 0;
    let html = `<p class="hint" style="margin-top:0">From this Cachegrind profile only: HTTP boundaries (<code>curl</code>, Guzzle, <code>restCall</code>, …) and their direct callers. Service = <code>Vendor\\Module</code> from the function name when present. Cachegrind does not include request URLs.</p>`;

    if (services.length) {
      const sortedSvc = sortRows(services, sortState.apiServices);
      html += `<h2 style="margin-top:0">By service</h2><table><thead><tr>
        ${sortHeader('Time', 'sec', 'apiServices')}
        ${sortHeader('% of request', 'pct', 'apiServices')}
        ${sortHeader('Calls', 'calls', 'apiServices')}
        ${sortHeader('Fns', 'functions', 'apiServices')}
        <th>Service</th></tr></thead><tbody>`;
      for (const s of sortedSvc) {
        html += `<tr>
          <td class="num">${fmtSec(s.sec)}</td>
          <td class="num">${(s.pct ?? 0).toFixed(1)}%</td>
          <td class="num">${s.calls ?? 0}</td>
          <td class="num">${s.functions ?? 0}</td>
          <td class="name">${esc(s.service)}</td>
        </tr>`;
      }
      html += `</tbody></table>`;
      const totalSvc = services.reduce((m, s) => m + (s.sec || 0), 0);
      if (main > 0) {
        html += `<p class="meta" style="margin:.5rem 0 1rem">Sum of service maxima ≈ ${fmtSec(totalSvc)} (${((totalSvc / main) * 100).toFixed(1)}% of {main}).</p>`;
      }
    }

    if (calls.length) {
      const activeId = data.detail ? data.detail.id : null;
      const sorted = sortRows(calls, sortState.apis);
      html += `<h2>Call sites</h2><table><thead><tr>
        ${sortHeader('Time', 'sec', 'apis')}
        ${sortHeader('Self', 'self_sec', 'apis')}
        ${sortHeader('%', 'pct', 'apis')}
        ${sortHeader('Calls', 'calls', 'apis')}
        <th>Service</th><th>Via</th><th>File</th><th>Function</th></tr></thead><tbody>`;
      for (const r of sorted) {
        const active = activeId && r.id === activeId ? ' active' : '';
        html += `<tr class="clickable${active}" data-id="${r.id}">
          <td class="num">${fmtSec(r.sec)}</td>
          <td class="num">${fmtSec(r.self_sec ?? 0)}</td>
          <td class="num">${(r.pct ?? 0).toFixed(1)}%</td>
          <td class="num">${r.calls ?? 0}</td>
          <td>${esc(r.service || '')}</td>
          <td class="meta">${esc(r.via || '')}</td>
          <td>${fileLinkHtml(r.file, r.line)}</td>
          <td class="name" title="${esc(r.name)}">${esc(short(r.name, 90))}</td>
        </tr>`;
      }
      html += `</tbody></table>`;
    }

    box.innerHTML = html;
    bindSortHeaders(box);
    bindRowClicks(box);
  }

  const FLAME_COLORS = {
    app: '#2d6a4f',
    vendor: '#9a3412',
    framework: '#5b21b6',
    php: '#1d4ed8',
    other: '#334155',
  };

  function renderFlame(data) {
    const box = $('tab-flame');
    if (!box) return;
    const root = data.flame;
    if (!root) {
      box.innerHTML = '<div class="empty">No flame tree available.</div>';
      return;
    }
    const width = Math.max(720, (box.clientWidth || 800) - 16);
    const rowH = 22;
    const rects = [];
    function walk(node, depth, x0, x1) {
      if (!node || x1 - x0 < 1) return;
      const maxDepth = 10;
      if (depth > maxDepth) return;
      rects.push({
        x: x0, y: depth * rowH, w: x1 - x0, h: rowH - 1,
        node,
        color: FLAME_COLORS[node.scope] || FLAME_COLORS.other,
      });
      const kids = (node.children || []).filter((c) => (c.value || 0) > 0);
      const total = kids.reduce((s, c) => s + (c.value || 0), 0) || node.value || 1;
      let cursor = x0;
      for (const c of kids) {
        const share = (c.value || 0) / total;
        const w = (x1 - x0) * share;
        walk(c, depth + 1, cursor, cursor + w);
        cursor += w;
      }
    }
    walk(root, 0, 0, width);
    const height = Math.max(rowH, (Math.max(...rects.map((r) => r.y), 0) + rowH + 8));
    let svg = `<div class="flame-tip">Click a block to open backtrace. Color = scope (app / vendor / framework / php).</div>
      <div class="flame-wrap"><svg width="${width}" height="${height}" viewBox="0 0 ${width} ${height}">`;
    rects.forEach((r, i) => {
      const label = short(r.node.name || '', Math.max(8, Math.floor(r.w / 7)));
      svg += `<g data-idx="${i}">
        <rect x="${r.x.toFixed(1)}" y="${r.y}" width="${Math.max(0.5, r.w).toFixed(1)}" height="${r.h}" fill="${r.color}" data-id="${r.node.id || ''}">
          <title>${esc(r.node.name)} — ${fmtSec(r.node.value)} incl / ${fmtSec(r.node.self)} self</title>
        </rect>
        ${r.w > 36 ? `<text x="${(r.x + 4).toFixed(1)}" y="${r.y + 15}" fill="#e8eef5" font-size="11">${esc(label)}</text>` : ''}
      </g>`;
    });
    svg += `</svg></div>`;
    box.innerHTML = svg;
    box.querySelectorAll('rect[data-id]').forEach((el) => {
      el.addEventListener('click', () => {
        const id = parseInt(el.getAttribute('data-id'), 10);
        if (id) {
          switchTab('detail');
          loadDetail(id);
        }
      });
    });
  }

  function renderTrend(data) {
    const box = $('tab-trend');
    if (!box) return;
    const points = data.points || [];
    if (!points.length) {
      box.innerHTML = '<div class="empty">No profiles found in this folder.</div>';
      return;
    }
    const max = Math.max(...points.map((p) => p.main_sec || 0), 0.001);
    let html = `<p class="hint" style="margin-top:0">Folder: <code>${esc(data.dir)}</code> — {main} seconds over recent profiles (oldest → newest).</p>
      <div class="trend-bar">`;
    for (const p of points) {
      const h = Math.max(2, Math.round(((p.main_sec || 0) / max) * 120));
      const label = p.label || p.endpoint || p.name.replace(/^cachegrind\.out\.?/, '').slice(0, 12);
      html += `<div class="trend-col" title="${esc(p.name)} — ${fmtSec(p.main_sec)}${p.label ? ' — ' + p.label : ''}">
        <div class="bar" style="height:${h}px"></div>
        <div class="lbl">${esc(short(label, 18))}</div>
      </div>`;
    }
    html += `</div><table><thead><tr><th>When</th><th>{main}</th><th>Label</th><th>Endpoint</th><th>File</th></tr></thead><tbody>`;
    for (const p of points.slice().reverse()) {
      const when = p.mtime ? new Date(p.mtime * 1000).toLocaleString() : '—';
      html += `<tr class="clickable" data-path="${esc(p.path)}">
        <td>${esc(when)}</td>
        <td class="num">${fmtSec(p.main_sec)}</td>
        <td>${esc(p.label || '—')}</td>
        <td>${esc(p.endpoint || '—')}</td>
        <td class="name">${esc(short(p.name, 40))}</td>
      </tr>`;
    }
    html += `</tbody></table>`;
    box.innerHTML = html;
    box.querySelectorAll('tr.clickable[data-path]').forEach((tr) => {
      tr.addEventListener('click', () => {
        fillPathTarget('path', tr.getAttribute('data-path'));
        analyze();
      });
    });
  }

  function renderDetail(detail) {
    const box = $('detailBody');
    if (!detail) {
      box.innerHTML = '<div class="empty">No function selected.</div>';
      return;
    }
    const file = detail.file && String(detail.file)[0] === '/' ? detail.file : null;
    const canDetailBack = detailHistory.length > 0;
    let html = `<div class="detail-toolbar">
      <button type="button" class="detail-back" id="btnDetailBack" ${canDetailBack ? '' : 'disabled'} title="${canDetailBack ? 'Previous function' : 'No previous function'}">← Back</button>
      <p class="detail-title">${esc(detail.name)}</p>
    </div>`;
    html += `<div class="stats">
      <div class="stat"><div class="v">${fmtSec(detail.sec)}</div><div class="l">Inclusive time</div></div>
      <div class="stat"><div class="v">${fmtSec(detail.self_sec || 0)}</div><div class="l">Self time</div></div>
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

    const btnBack = $('btnDetailBack');
    if (btnBack) {
      btnBack.addEventListener('click', (ev) => {
        ev.preventDefault();
        if (!detailHistory.length) return;
        const prev = detailHistory.pop();
        loadDetail(prev, { fromHistory: true });
      });
    }
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

  function fillMetaForm(meta) {
    meta = meta || {};
    if ($('metaLabel')) $('metaLabel').value = meta.label || '';
    if ($('metaNotes')) $('metaNotes').value = meta.notes || '';
    if ($('metaEndpoint')) $('metaEndpoint').value = meta.endpoint || '';
    if ($('metaMethod')) $('metaMethod').value = meta.method || '';
  }

  function renderResult(data) {
    lastResult = data;
    $('summaryEmpty').classList.add('hidden');
    $('summaryBody').classList.remove('hidden');
    $('statMain').textContent = fmtSec(data.main_sec);
    $('statParse').textContent = (data.parse_ms / 1000).toFixed(1) + 's' + (data.cached ? ' (cache)' : '');
    $('statSize').textContent = fmtBytes(data.size_bytes);
    const filteredTop = filterRows(data.top || []);
    const hot = filteredTop.find(r => r.name !== '{main}') || filteredTop[0] || (data.top || [])[0];
    $('statHot').textContent = hot ? fmtSec(currentSortBy() === 'self' ? (hot.self_sec ?? hot.sec) : hot.sec) : '—';

    const ul = $('insights');
    ul.innerHTML = (data.insights || []).map(t => `<li>${esc(t)}</li>`).join('') || '<li>No insights.</li>';

    fillMetaForm(data.meta);
    const activeId = data.detail ? data.detail.id : null;
    renderTopTab(data);
    $('tab-kw').innerHTML = tableHtml(filterRows(data.keywords), activeId, 'kw');
    bindRowClicks($('tab-kw'));
    bindSortHeaders($('tab-kw'));
    renderModules(data);
    renderPlugins(data);
    renderApis(data);
    renderFlame(data);
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
    const cancelBtn = $('btnCancelAnalyze');
    const progress = $('analyzeProgress');
    const progressLbl = $('analyzeProgressLbl');
    const prevLabel = btn.textContent;
    // Fresh Analyze resets drill history; Find keeps prior detail on the stack
    if (extra.find || extra.fn_id) {
      const prevId = lastResult && lastResult.detail ? lastResult.detail.id : null;
      if (prevId) pushDetailHistory(prevId);
    } else if (!quiet) {
      clearDetailHistory();
    }
    if (analyzeAbort) analyzeAbort.abort();
    analyzeAbort = new AbortController();
    btn.disabled = true;
    if (cancelBtn) cancelBtn.classList.remove('hidden');
    if (progress) progress.classList.add('active');
    if (progressLbl) {
      progressLbl.textContent = quiet
        ? 'Refreshing results…'
        : 'Parsing profile… large files can take 1–3 minutes. Use Cancel to abort.';
    }
    if (!quiet) btn.textContent = 'Analyzing…';
    try {
      const res = await apiFetch({
        action: 'analyze',
        path,
        min_sec: $('minSec').value,
        top: String(top),
        sort_by: currentSortBy(),
        ...extra,
      }, { signal: analyzeAbort.signal });
      const data = await res.json();
      if (!data.ok) {
        showError(data.error || 'Analyze failed');
        if (quiet && lastResult) renderTopTab(lastResult);
        return;
      }
      renderResult(data);
      if (extra.fn_id || extra.find) switchTab('detail');
    } catch (e) {
      if (e && e.name === 'AbortError') {
        showError('Analyze cancelled.');
      } else {
        showError(String(e));
      }
      if (quiet && lastResult) renderTopTab(lastResult);
    } finally {
      btn.disabled = false;
      btn.textContent = quiet ? prevLabel : 'Analyze';
      if (cancelBtn) cancelBtn.classList.add('hidden');
      if (progress) progress.classList.remove('active');
      analyzeAbort = null;
    }
  }

  async function loadDetail(fnId, opts = {}) {
    const fromHistory = !!opts.fromHistory;
    const prevId = lastResult && lastResult.detail ? lastResult.detail.id : null;
    if (!fromHistory && prevId && prevId !== fnId) {
      pushDetailHistory(prevId);
    }
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
        data.modules = lastResult.modules;
        data.plugins = lastResult.plugins;
        data.apis = lastResult.apis;
        data.flame = lastResult.flame;
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
    ['top','modules','plugins','apis','flame','kw','detail','compare','trend'].forEach(t => {
      const el = document.getElementById('tab-' + t);
      if (el) el.classList.toggle('hidden', t !== name);
    });
    if (name === 'trend') loadTrend();
  }

  async function loadTrend() {
    const dir = browseDir || ($('path').value.trim().replace(/\/cachegrind\.out.*$/, '') || '');
    if (!dir) {
      $('tab-trend').innerHTML = '<div class="empty">Browse to a profiler folder first.</div>';
      return;
    }
    $('tab-trend').innerHTML = '<div class="empty">Loading trend…</div>';
    try {
      const res = await apiFetch({ action: 'trend', dir, limit: '20' });
      const data = await res.json();
      if (!data.ok) {
        $('tab-trend').innerHTML = `<div class="empty">${esc(data.error || 'Trend failed')}</div>`;
        return;
      }
      renderTrend(data);
    } catch (e) {
      $('tab-trend').innerHTML = `<div class="empty">${esc(String(e))}</div>`;
    }
  }

  async function saveMeta() {
    const path = $('path').value.trim();
    if (!path || !path.split('/').pop().startsWith('cachegrind')) {
      showError('Select a cachegrind profile before saving a label.');
      return;
    }
    try {
      const res = await apiFetch({
        action: 'meta_save',
        path,
        label: $('metaLabel')?.value || '',
        notes: $('metaNotes')?.value || '',
        endpoint: $('metaEndpoint')?.value || '',
        method: $('metaMethod')?.value || '',
      });
      const data = await res.json();
      if (!data.ok) { showError(data.error || 'Save failed'); return; }
      showError('');
      if (lastResult) lastResult.meta = data.meta;
      await browse(browseDir);
    } catch (e) {
      showError(String(e));
    }
  }

  async function setBaseline() {
    const path = $('path').value.trim();
    if (!path || !path.split('/').pop().startsWith('cachegrind')) {
      showError('Select a cachegrind profile to set as baseline.');
      return;
    }
    try {
      const res = await apiFetch({ action: 'baseline_set', path });
      const data = await res.json();
      if (!data.ok) { showError(data.error || 'Baseline failed'); return; }
      showError('');
      await browse(browseDir);
    } catch (e) {
      showError(String(e));
    }
  }

  async function compareBaseline() {
    const after = $('path').value.trim();
    const baseline = lastBrowse?.baseline || lastResult?.baseline;
    if (!baseline) {
      showError('No baseline set in this folder. Select a profile and click Set baseline.');
      return;
    }
    if (!after || !after.split('/').pop().startsWith('cachegrind')) {
      showError('Select the current (after) profile in Analyze path.');
      return;
    }
    $('pathBefore').value = baseline;
    $('pathAfter').value = after;
    await compareProfiles();
  }

  function exportReport(format = 'html') {
    if (!lastResult) {
      showError('Analyze a profile first, then Export.');
      return;
    }
    const payload = {
      exported_at: new Date().toISOString(),
      path: lastResult.path,
      main_sec: lastResult.main_sec,
      meta: lastResult.meta,
      insights: lastResult.insights,
      top: filterRows(lastResult.top || []).slice(0, 40),
      modules: filterRows(lastResult.modules || []).slice(0, 30),
      plugins: lastResult.plugins || null,
      apis: lastResult.apis || null,
      compare: lastCompare,
    };
    const stamp = Date.now();
    if (format === 'json') {
      const blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'bottleneck-report-' + stamp + '.json';
      a.click();
      URL.revokeObjectURL(a.href);
      return;
    }
    const html = buildHtmlReport(payload);
    const blob = new Blob([html], { type: 'text/html;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'bottleneck-report-' + stamp + '.html';
    a.click();
    URL.revokeObjectURL(a.href);
  }

  function buildHtmlReport(payload) {
    const meta = payload.meta || {};
    const rows = (payload.top || []).map((r, i) =>
      `<tr><td>${i + 1}</td><td>${esc(fmtSec(r.sec))}</td><td>${esc(fmtSec(r.self_sec || 0))}</td><td>${r.calls || 0}</td><td>${esc(r.name || '')}</td></tr>`
    ).join('');
    const mods = (payload.modules || []).map((m) =>
      `<tr><td>${esc(fmtSec(m.self_sec))}</td><td>${esc(fmtSec(m.sec))}</td><td>${m.functions || 0}</td><td>${esc(m.module || '')}</td></tr>`
    ).join('');
    const pluginKinds = ((payload.plugins && payload.plugins.kinds) || []).map((k) =>
      `<tr><td>${esc(fmtSec(k.self_sec))}</td><td>${esc(pluginKindLabel(k.kind))}</td><td>${k.functions || 0}</td></tr>`
    ).join('');
    const insights = (payload.insights || []).map((t) => `<li>${esc(t)}</li>`).join('');
    let compareBlock = '';
    if (payload.compare && payload.compare.rows) {
      const c = payload.compare;
      const deltaTxt = (sec) => {
        const n = Number(sec) || 0;
        const sign = n > 0 ? '+' : (n < 0 ? '-' : '');
        return sign + fmtSec(Math.abs(n));
      };
      const crow = (c.rows || []).slice(0, 25).map((r) =>
        `<tr><td>${esc(deltaTxt(r.delta_sec))}</td><td>${esc(fmtSec(r.before_sec))}</td><td>${esc(fmtSec(r.after_sec))}</td><td>${esc(r.name || '')}</td></tr>`
      ).join('');
      compareBlock = `<h2>Compare</h2>
        <p>Before {main}: ${esc(fmtSec(c.before_main_sec))} → After: ${esc(fmtSec(c.after_main_sec))} (Δ ${esc(deltaTxt(c.delta_main_sec))})</p>
        <table><thead><tr><th>Δ Time</th><th>Before</th><th>After</th><th>Function</th></tr></thead><tbody>${crow}</tbody></table>`;
    }
    const title = meta.label || (payload.path ? String(payload.path).split('/').pop() : 'Bottleneck report');
    return `<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8">
<title>${esc(title)} — Bottleneck</title>
<style>
  body{font:14px/1.45 system-ui,sans-serif;max-width:960px;margin:1.5rem auto;padding:0 1rem;color:#1a1a1a}
  h1{font-size:1.25rem;margin:0 0 .35rem} h2{font-size:1.05rem;margin:1.5rem 0 .5rem;border-bottom:1px solid #ddd;padding-bottom:.25rem}
  .meta{color:#555;font-size:.9rem} code{font-size:.85em;word-break:break-all}
  table{border-collapse:collapse;width:100%;font-size:.85rem;margin:.5rem 0 1rem}
  th,td{border:1px solid #ddd;padding:.35rem .5rem;text-align:left} th{background:#f4f4f4}
  td:nth-child(-n+4){font-variant-numeric:tabular-nums} ul{padding-left:1.2rem}
  .foot{margin-top:2rem;color:#888;font-size:.8rem}
</style></head><body>
<h1>Bottleneck report</h1>
<p class="meta">
  ${esc(payload.exported_at || '')}<br>
  Profile: <code>${esc(payload.path || '')}</code><br>
  {main}: <b>${esc(fmtSec(payload.main_sec || 0))}</b>
  ${meta.label ? '<br>Label: ' + esc(meta.label) : ''}
  ${meta.endpoint ? '<br>Endpoint: ' + esc((meta.method || '') + ' ' + meta.endpoint) : ''}
  ${meta.notes ? '<br>Notes: ' + esc(meta.notes) : ''}
  ${payload.plugins ? '<br>Plugin tax (self): ' + esc(fmtSec(payload.plugins.total_self_sec || 0)) + ' (' + (payload.plugins.pct_of_main || 0).toFixed(1) + '% of main)' : ''}
</p>
<h2>Top functions</h2>
<table><thead><tr><th>#</th><th>Incl</th><th>Self</th><th>Calls</th><th>Function</th></tr></thead><tbody>${rows || '<tr><td colspan="5">None</td></tr>'}</tbody></table>
<h2>Suspect modules</h2>
<table><thead><tr><th>Self</th><th>Peak incl</th><th>Fns</th><th>Module</th></tr></thead><tbody>${mods || '<tr><td colspan="4">None</td></tr>'}</tbody></table>
<h2>Plugin / interceptor tax</h2>
<table><thead><tr><th>Self</th><th>Kind</th><th>Fns</th></tr></thead><tbody>${pluginKinds || '<tr><td colspan="3">None</td></tr>'}</tbody></table>
${compareBlock}
<h2>Insights</h2>
<ul>${insights || '<li>None</li>'}</ul>
<p class="foot">Generated by Bottleneck Cachegrind GUI. Attach this file to a ticket or PR.</p>
</body></html>`;
  }

  async function copyAiPrompt() {
    if (!lastResult) {
      showError('Analyze a profile first.');
      return;
    }
    const top = filterRows(lastResult.top || []).filter((r) => r.name !== '{main}').slice(0, 12);
    const mods = filterRows(lastResult.modules || []).slice(0, 8);
    const apiSvc = (lastResult.apis && lastResult.apis.services) ? lastResult.apis.services.slice(0, 10) : [];
    const apiCalls = (lastResult.apis && lastResult.apis.calls) ? lastResult.apis.calls.slice(0, 12) : [];
    const lines = [
      'You are helping optimize a Magento/PHP request from an Xdebug Cachegrind profile.',
      `Profile: ${lastResult.path}`,
      `Total {main}: ${fmtSec(lastResult.main_sec)}`,
      lastResult.meta?.endpoint ? `Endpoint: ${lastResult.meta.method || ''} ${lastResult.meta.endpoint}` : '',
      lastResult.meta?.label ? `Label: ${lastResult.meta.label}` : '',
      lastResult.meta?.notes ? `Notes: ${lastResult.meta.notes}` : '',
      '',
      'Top hotspots:',
      ...top.map((r) => `- ${fmtSec(r.sec)} incl / ${fmtSec(r.self_sec || 0)} self / ${r.calls || 0} calls — ${r.name}${r.file ? ' @ ' + r.file + (r.line ? ':' + r.line : '') : ''}`),
      '',
      'Suspect modules (self-time):',
      ...mods.map((m) => `- ${fmtSec(m.self_sec)} self — ${m.module}`),
      '',
      lastResult.plugins
        ? `Plugin/interceptor tax: ${fmtSec(lastResult.plugins.total_self_sec || 0)} self (${(lastResult.plugins.pct_of_main || 0).toFixed(1)}% of main)`
        : '',
      ...((lastResult.plugins && lastResult.plugins.kinds) || []).map((k) => `- ${fmtSec(k.self_sec)} — ${pluginKindLabel(k.kind)} (${k.functions} fns)`),
      '',
      '3rd-party / HTTP (from profile: curl/Guzzle/restCall + callers):',
      ...(apiSvc.length ? apiSvc.map((s) => `- ${fmtSec(s.sec)} (${(s.pct || 0).toFixed(1)}%) — ${s.service}`) : ['- (none detected)']),
      '',
      'HTTP-related call sites:',
      ...(apiCalls.length ? apiCalls.map((c) => `- ${fmtSec(c.sec)} / ${c.calls || 0} calls — [${c.service}] ${c.name}`) : ['- (none)']),
      '',
      'Insights:',
      ...(lastResult.insights || []).map((t) => `- ${t}`),
      '',
      'Explain the likely bottlenecks and suggest concrete Magento-safe fixes (caching, N+1, plugins, session locks, external API latency, etc.).',
    ].filter(Boolean);
    const text = lines.join('\n');
    try {
      await navigator.clipboard.writeText(text);
      showError('');
      alert('AI prompt copied to clipboard — paste into Cursor chat.');
    } catch (e) {
      showError('Clipboard failed: ' + e);
    }
  }

  async function cleanupProfiles() {
    const dir = browseDir;
    if (!dir) {
      showError('Browse to a profiler folder first.');
      return;
    }
    const keepDays = prompt('Delete profiles older than how many days? (0 = use keep-newest only)', '14');
    if (keepDays === null) return;
    const keepNewest = prompt('Also keep this many newest profiles (0 = ignore)', '10');
    if (keepNewest === null) return;
    try {
      const dry = await apiFetch({
        action: 'cleanup',
        dir,
        keep_days: String(parseInt(keepDays, 10) || 0),
        keep_newest: String(parseInt(keepNewest, 10) || 0),
        dry_run: '1',
      });
      const preview = await dry.json();
      if (!preview.ok) { showError(preview.error || 'Cleanup failed'); return; }
      const n = (preview.candidates || []).length;
      if (!n) {
        alert('Nothing to delete with those rules.');
        return;
      }
      if (!confirm(`Delete ${n} profile(s) (~${fmtBytes(preview.candidates.reduce((s, c) => s + (c.size || 0), 0))})?\nBaseline is kept.`)) {
        return;
      }
      const res = await apiFetch({
        action: 'cleanup',
        dir,
        keep_days: String(parseInt(keepDays, 10) || 0),
        keep_newest: String(parseInt(keepNewest, 10) || 0),
      });
      const data = await res.json();
      if (!data.ok) { showError(data.error || 'Cleanup failed'); return; }
      alert(`Deleted ${data.deleted.length} file(s), freed ${fmtBytes(data.freed_bytes)}.`);
      await browse(dir);
    } catch (e) {
      showError(String(e));
    }
  }

  function rerenderFromFilters() {
    if (!lastResult) return;
    sortState.top.key = currentSortBy() === 'self' ? 'self_sec' : 'sec';
    renderResult(lastResult);
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
  $('btnSaveMeta')?.addEventListener('click', () => saveMeta());
  $('btnBaseline')?.addEventListener('click', () => setBaseline());
  $('btnCompareBaseline')?.addEventListener('click', () => compareBaseline());
  $('btnExport')?.addEventListener('click', () => exportReport('html'));
  $('btnExportJson')?.addEventListener('click', () => exportReport('json'));
  $('btnAiPrompt')?.addEventListener('click', () => copyAiPrompt());
  $('btnCleanup')?.addEventListener('click', () => cleanupProfiles());
  $('btnCancelAnalyze')?.addEventListener('click', () => {
    if (analyzeAbort) analyzeAbort.abort();
  });
  $('scopeFilter')?.addEventListener('change', () => {
    localStorage.setItem('bottleneck.scope', currentScopeFilter());
    rerenderFromFilters();
  });
  $('sortBy')?.addEventListener('change', () => {
    localStorage.setItem('bottleneck.sortBy', currentSortBy());
    // Re-analyze so server sorts by self/incl for top list threshold
    if (lastResult?.path) analyze({ quiet: true });
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

  const savedScope = localStorage.getItem('bottleneck.scope');
  if (savedScope && $('scopeFilter')) $('scopeFilter').value = savedScope;
  const savedSort = localStorage.getItem('bottleneck.sortBy');
  if (savedSort && $('sortBy')) $('sortBy').value = savedSort;
  if (savedSort === 'self') sortState.top.key = 'self_sec';

  setBrowseTarget('path');
  browse(browseDir);
})();

/* legacy boot removed */