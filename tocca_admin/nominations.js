(() => {
  // ---------- Path helpers ----------
  function api(path) {
    const base = (window.APP_BASE || new URL('.', location.href).pathname).replace(/\/+$/, '');
    return base + '/' + String(path).replace(/^\/+/, '');
  }
  const NOM_FILE = 'nomination.php'; // your backend controller
  const ENDPOINTS = { list: () => api(NOM_FILE) };

  // ---------- DOM refs ----------
  const tbody = document.querySelector('#nomTable tbody');
  const nomSsrMetaEl = document.getElementById('nomSsrMeta');
  const nomTableBody = document.getElementById('nomTableBody');
  const statusFilter = document.getElementById('statusFilter');
  const paginationInfo = document.getElementById('paginationInfo');
  const paginationContainer = document.getElementById('paginationContainer');

  // ---------- Toast ----------
  const toastEl   = document.getElementById('toastMsg');
  const toastBody = document.getElementById('toastBody');
  const toast     = toastEl ? new bootstrap.Toast(toastEl) : null;
  function showToast(msg, ok = true) {
    if (!toast) { try { alert(msg); } catch(_) {} return; }
    toastBody.textContent = msg;
    toastEl.classList.remove('text-bg-success','text-bg-danger');
    toastEl.classList.add(ok ? 'text-bg-success' : 'text-bg-danger');
    toast.show();
  }

  // ---------- Utils ----------
  function formatStatusLabel(key){
    key = String(key || '').toLowerCase();
    const map = {
      pending   : 'Pending',
      in_review : 'In Review',
      needs_info: 'Needs Information',
      approved  : 'Approved',
      rejected  : 'Rejected',
      merged    : 'Merged'
    };
    return map[key] || key.replace(/_/g,' ').replace(/\b\w/g, c => c.toUpperCase());
  }
  function statusTone(key){
    key = String(key || '').toLowerCase();
    const map = {
      in_review : 'primary',
      pending   : 'warning',
      needs_info: 'secondary',
      approved  : 'success',
      rejected  : 'danger',
      merged    : 'info'
    };
    return map[key] || 'secondary';
  }
  function escapeHtml(str) {
    return String(str ?? '')
      .replaceAll('&','&amp;')
      .replaceAll('<','&lt;')
      .replaceAll('>','&gt;')
      .replaceAll('"','&quot;')
      .replaceAll("'", '&#39;');
  }
  function getEventId() {
    const v = String(nomTableBody?.dataset?.eventId ?? '').trim();
    return /^\d+$/.test(v) ? v : null;
  }
  function buildNominationsReturnUrl() {
    const params = new URLSearchParams();
    const status = statusFilter?.value;
    if (status && status !== 'all') params.set('status', status);
    if (currentPage > 1) params.set('page', String(currentPage));
    const qs = params.toString();
    return qs ? `nominations.php?${qs}` : 'nominations.php';
  }
  function restoreListStateFromUrl() {
    const qs = new URLSearchParams(location.search);
    const status = qs.get('status');
    const page = parseInt(qs.get('page') || '1', 10);
    if (status && statusFilter) {
      const allowed = ['pending', 'in_review', 'needs_info', 'approved', 'rejected', 'merged', 'all'];
      if (allowed.includes(status)) statusFilter.value = status;
    }
    return Number.isFinite(page) && page > 0 ? page : 1;
  }
  function showEmpty(msg) {
    if (tbody) {
      tbody.innerHTML = `<tr><td colspan="3" class="text-center text-muted py-4">${escapeHtml(msg)}</td></tr>`;
    }
    if (paginationInfo) paginationInfo.textContent = '';
    if (paginationContainer) paginationContainer.innerHTML = '';
  }

  // ---------- Pagination ----------
  const PAGE_SIZE = 10;
  let currentPage = 1;
  let total = 0;

  // fetch JSON with timeout + helpful error
  async function fetchJSON(url, options) {
    const ctrl = new AbortController();
    const t = setTimeout(() => ctrl.abort(), 20000);
    let res, text;
    try {
      res = await fetch(url, { ...options, signal: ctrl.signal, cache: 'no-store' });
      text = await res.text();
    } finally { clearTimeout(t); }
    let data;
    try { data = JSON.parse(text); }
    catch { throw new Error(`Non-JSON from ${url} (HTTP ${res?.status ?? 'n/a'})`); }
    if (!res.ok || data.status !== 'success') {
      throw new Error(data.message || `Request failed (HTTP ${res.status})`);
    }
    return data;
  }

  // ---------- Data loader ----------
  async function loadPage(page = 1) {
    if (!tbody) return;
    currentPage = page;

    const eventId = getEventId();
    const selectedStatus = statusFilter?.value || 'all';

    // If there’s no unarchived event (empty dropdown), don’t call API.
    if (!eventId) {
      showEmpty('No unarchived event found. Please restore or create an event.');
      return;
    }

    tbody.innerHTML = `
      <tr><td colspan="3" class="text-center py-4">
        <div class="spinner-border spinner-border-sm me-2" role="status"></div>
        Loading…
      </td></tr>`;
    if (paginationInfo) paginationInfo.textContent = '';
    if (paginationContainer) paginationContainer.innerHTML = '';

    try {
      const params = new URLSearchParams({
        action   : 'list',
        page     : String(currentPage),
        page_size: String(PAGE_SIZE),
        event_id : eventId                      // ← ALWAYS send event_id
      });
      if (selectedStatus && selectedStatus !== 'all') params.set('status', selectedStatus);

      const url = `${ENDPOINTS.list()}?${params.toString()}`;
      const data = await fetchJSON(url);

      const rows = Array.isArray(data.rows) ? data.rows : [];
      total = Number(data.total || rows.length || 0);

      renderTable(rows);
      renderPagination(total, currentPage, PAGE_SIZE);
    } catch (e) {
      tbody.innerHTML = `<tr><td colspan="3" class="text-center text-danger py-4">${escapeHtml(e.message)}</td></tr>`;
      console.error('[nominations.js] loadPage error:', e);
      showToast('Failed to load nominations.', false);
    }
  }

  // ---------- Renderers ----------
  function renderTable(rows) {
    if (!rows.length) {
      tbody.innerHTML = `<tr><td colspan="3" class="text-center text-muted py-4">No nominations found.</td></tr>`;
      if (paginationInfo) paginationInfo.textContent = '0–0 of 0';
      return;
    }

    const html = rows.map(r => {
      const id = r.nomination_id ?? r.id ?? '';
      const name = escapeHtml(r.business_name || r.biz_name || '—');
      const address = escapeHtml(r.address || '');
      const email = escapeHtml(r.email || '');
      const phone = escapeHtml(r.mobile_number || r.mobile || '');
      const contact = [email, phone].filter(Boolean).join(' • ');
      const status = String(r.status || 'pending').toLowerCase();

      const mediaCount = Number(r.media_count || 0);
      const mediaBadge = mediaCount > 0
        ? `<span class="badge bg-info-subtle text-info border border-info-subtle ms-2" title="${mediaCount} photo/video file(s) submitted">
             <i class="bi bi-images"></i> ${mediaCount}
           </span>`
        : '';

      return `
        <tr class="nom-row" data-id="${escapeHtml(id)}" style="cursor:pointer;">
          <td>
            <div class="fw-semibold">${name}${mediaBadge}</div>
            <div class="small text-muted">${address}</div>
          </td>
          <td>${contact || '—'}</td>
          <td><span class="badge text-bg-${statusTone(status)}">${formatStatusLabel(status)}</span></td>
        </tr>`;
    }).join('');

    tbody.innerHTML = html;

    const start = (currentPage - 1) * PAGE_SIZE + 1;
    const end = Math.min(currentPage * PAGE_SIZE, total);
    if (paginationInfo) paginationInfo.textContent = `${start}–${end} of ${total}`;
  }

  function renderPagination(totalCount, page, pageSize) {
    if (!paginationContainer) return;

    const totalPages = Math.max(1, Math.ceil(totalCount / pageSize));
    const ul = paginationContainer;
    ul.innerHTML = '';

    const makeItem = (label, targetPage, { disabled = false, active = false, srLabel = '' } = {}) => {
      const li = document.createElement('li');
      li.className = `page-item${disabled ? ' disabled' : ''}${active ? ' active' : ''}`;
      const a = document.createElement('a');
      a.className = 'page-link';
      a.href = '#';
      a.innerHTML = srLabel
        ? `<span aria-hidden="true">${label}</span><span class="visually-hidden">${srLabel}</span>`
        : label;
      a.addEventListener('click', (e) => {
        e.preventDefault();
        if (!disabled && !active) loadPage(targetPage);
      });
      li.appendChild(a);
      ul.appendChild(li);
    };

    // First / Prev
    makeItem('&laquo;', 1, { disabled: page === 1, srLabel: 'First' });
    makeItem('&lsaquo;', page - 1, { disabled: page === 1, srLabel: 'Previous' });

    // Numeric window + ellipses
    const windowSize = 1;
    const pages = [];
    for (let p = 1; p <= totalPages; p++) {
      if (p === 1 || p === totalPages || (p >= page - windowSize && p <= page + windowSize)) {
        pages.push(p);
      }
    }
    let last = 0;
    pages.forEach(p => {
      if (p - last > 1) {
        const li = document.createElement('li');
        li.className = 'page-item disabled';
        li.innerHTML = `<span class="page-link">…</span>`;
        ul.appendChild(li);
      }
      makeItem(String(p), p, { active: p === page });
      last = p;
    });

    // Next / Last
    makeItem('&rsaquo;', page + 1, { disabled: page === totalPages, srLabel: 'Next' });
    makeItem('&raquo;', totalPages, { disabled: page === totalPages, srLabel: 'Last' });
  }

  // ---------- Row click → profile ----------
  if (tbody) {
    tbody.addEventListener('click', (e) => {
      const row = e.target.closest('tr.nom-row');
      if (row?.dataset?.id) {
        const base = (window.APP_BASE || new URL('.', location.href).pathname).replace(/\/+$/, '');
        const returnUrl = encodeURIComponent(buildNominationsReturnUrl());
        location.href = `${base}/nomination_profile.php?id=${encodeURIComponent(row.dataset.id)}&return=${returnUrl}`;
      }
    });
  }

  function readSsrMeta() {
    if (!nomSsrMetaEl) return null;
    try {
      return JSON.parse(nomSsrMetaEl.textContent || '{}');
    } catch (_) {
      return null;
    }
  }

  function filtersMatchSsr(meta) {
    if (!meta || !tbody?.dataset?.ssr) return false;
    const eventId = getEventId();
    const status = statusFilter?.value || 'all';
    return String(meta.event_id || '') === String(eventId || '')
      && String(meta.status || 'all') === String(status)
      && Number(meta.page || 1) === currentPage;
  }

  function hydrateFromSsr() {
    const meta = readSsrMeta();
    if (!filtersMatchSsr(meta)) return false;
    total = Number(meta.total || 0);
    currentPage = Number(meta.page || 1);
    renderPagination(total, currentPage, PAGE_SIZE);
    return true;
  }

  // ---------- Filters ----------
  statusFilter?.addEventListener('change', () => loadPage(1));

  // ---------- Kickoff ----------
  document.addEventListener('DOMContentLoaded', () => {
    currentPage = restoreListStateFromUrl();
    if (statusFilter && !statusFilter.value) statusFilter.value = 'all';

    if (!getEventId()) {
      showEmpty('No unarchived event found. Please restore or create an event.');
      return;
    }

    if (hydrateFromSsr()) {
      return;
    }

    loadPage(currentPage);
  });
})();