(function () {
  // ------- Base-path helper -------
  function api(path) {
    const base = (window.APP_BASE || new URL('.', location.href).pathname).replace(/\/+$/, '');
    return base + '/' + String(path).replace(/^\/+/, '');
  }

  // ------- Endpoints -------
  const ENDPOINTS = {
    statusUpdate : api('nomination_tracker_status.php'),
    getNomination: (id) => api(`nomination.php?action=get&id=${encodeURIComponent(id)}`),
    approveMerge : api('nomination.php'),
    releaseBallot: api('choice.php'),
  };

  // ------- Toast -------
  const toastEl   = document.getElementById('toastMsg');
  const toastBody = document.getElementById('toastBody');
  const toast     = toastEl ? new bootstrap.Toast(toastEl) : null;
  function showToast(msg, ok = true) {
    if (!toast) return;
    toastBody.textContent = msg;
    toastEl.classList.remove('text-bg-success','text-bg-danger');
    toastEl.classList.add(ok ? 'text-bg-success' : 'text-bg-danger');
    toast.show();
  }

  // ------- Spinner helper -------
  function spinButton(btn, labelWhenSpinning = 'Processing…') {
    if (!btn) return () => {};
    const originalHTML = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML =
      `<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>${labelWhenSpinning}`;
    return () => { btn.disabled = false; btn.innerHTML = originalHTML; };
  }

  // ------- Safe parser for MySQL DATETIME -------
  function parseSQLDateTime(s){
    if (!s) return null;
    const d = new Date(String(s).replace(' ', 'T')); // "YYYY-MM-DD HH:MM:SS" -> "YYYY-MM-DDTHH:MM:SS"
    return isNaN(d.getTime()) ? null : d;
  }

  // ------- DOM refs -------
  const id = Number(document.getElementById('nomination_id')?.value || 0);
  const loadingBox = document.getElementById('loadingBox');
  const errorBox   = document.getElementById('errorBox');
  const content    = document.getElementById('contentWrap');
  const votingLockNotice  = document.getElementById('votingLockNotice');
  const votingLockMessage = document.getElementById('votingLockMessage');
  const votingLocked = content?.dataset?.votingLock === '1';
  const votingLockToast = 'Voting period has started. Registration actions are disabled.';
  if (votingLockNotice) {
    votingLockNotice.classList.toggle('d-none', !votingLocked);
  }
  if (votingLockMessage) {
    const startLabel = content?.dataset?.voteStartLabel || '';
    const endLabel = content?.dataset?.voteEndLabel || '';
    let msg = 'Voting period has started for this event. Registration actions are disabled.';
    if (startLabel) msg += ` Voting began on ${startLabel}.`;
    if (endLabel) msg += ` Voting ends on ${endLabel}.`;
    votingLockMessage.textContent = msg;
  }

  const bizTitle    = document.getElementById('bizTitle');
  const statusBadge = document.getElementById('statusBadge');
  const logoBox     = document.getElementById('logoBox');

  // NEW: dynamic details target
  const dynamicDetails = document.getElementById('dynamicDetails');

  // Created/Updated footer spans
  const createdSpan = document.getElementById('created_at');
  const updatedSpan = document.getElementById('updated_at');

  // Legacy chips area (fallback)
  const categoriesWrap = document.getElementById('categoriesWrap');

  const catWrap     = document.getElementById('catAwards');
  const catLoader   = document.getElementById('catLoader');
  const approvedAwardsBody = document.querySelector('#approvedAwardsTable tbody');
  const removedAwardsBody  = document.querySelector('#removedAwardsTable tbody');

  // Actions
  const btnApprove    = document.getElementById('btnApprove');
  const btnNeeds      = document.getElementById('btnNeedsInfo');
  const btnReject     = document.getElementById('btnReject');
  const btnReopen     = document.getElementById('btnReopen');
  const btnStartReview = document.getElementById('btnStartReview');
  const btnReleaseBallot = document.getElementById('btnReleaseBallot');
  const ballotStageHint = document.getElementById('ballotStageHint');
  const ballotBadge = document.getElementById('ballotBadge');
  const mergeChoiceId = document.getElementById('mergeChoiceId');

  // Optional "Validate" buttons
  const validateButtons = Array.from(document.querySelectorAll('#btnValidate, [data-action="validate"], .btn-validate'));

  // Email composer
  const notifyModalEl = document.getElementById('notifyModal');
  const notifyModal   = notifyModalEl ? new bootstrap.Modal(notifyModalEl) : null;
  const notifyModalStatusLabel = document.getElementById('notifyModalStatusLabel');
  const notifySubject = document.getElementById('notifySubject');
  const notifyPreview = document.getElementById('notifyPreview');
  const updateStatusOnlyBtn = document.getElementById('updateStatusOnlyBtn');
  const sendAndUpdateBtn    = document.getElementById('sendAndUpdateBtn');
  const notifyContactAlert  = document.getElementById('notifyContactAlert');

  // ---- CSRF for status updates ----
  const csrfToken = document.getElementById('validateModal')?.dataset.csrf
                 || document.querySelector('meta[name="csrf"]')?.content
                 || '';

  let quill;
  function initQuill() {
    if (quill) return;
    quill = new Quill('#notifyEditor', {
      theme: 'snow',
      placeholder: 'Write your message here…',
      modules: {
        toolbar: [
          ['bold','italic','underline'],
          [{ 'list': 'ordered'}, { 'list': 'bullet' }],
          ['link'],
          [{ 'align': [] }],
          ['clean']
        ]
      }
    });
    quill.on('text-change', renderPreview);
  }

  // Templates (needs info / reject only — proceed to evaluation does not email)
  const TEMPLATES = {
    needs_info: {
      subject: 'We need a bit more information',
      body: `
        <p>Thanks for your registration for {category_list} in {event_name}. Before we proceed, we need a bit more information.</p>
        <p>Please use Track My Registration on the Tatak Ormoc website to update your application. This mailbox is not monitored, so do not reply to this email.</p>
      `
    },
    rejected: {
      subject: 'Update on your registration',
      body: `
        <p>We appreciate your registration for {category_list} in {event_name}. After review, we&rsquo;re unable to proceed at this time.</p>
        <p>If you believe this is in error, use Track My Registration on the Tatak Ormoc website to review your application.</p>
      `
    }
  };

  let currentNomination = null;
  let currentCategoryIds = [];
  let currentCategories  = [];
  let currentStatus = null;
  let currentAnswers = []; // NEW
  let currentChoiceId = null;
  let currentOnBallot = null;
  let currentBallotEligibility = null;
  let remainingAwardRows = [];
  window.toccaTwgStandingByQuestion = {};

  // NEW: hold the establishment type id so we can send it on approve
  let establishmentTypeId = null;

  // Status helpers
  function formatStatusLabel(key){
    const k = String(key || '').toLowerCase();
    if (currentOnBallot && (k === 'approved' || k === 'merged')) return 'On ballot';
    const map = { pending:'Pending', in_review:'In Review', needs_info:'Needs Info', approved:'Under evaluation', rejected:'Rejected', merged:'Merged' };
    return map[k] || String(key || '').replace(/_/g,' ').replace(/\b\w/g, c => c.toUpperCase());
  }
  function statusTone(key){
    const k = String(key || '').toLowerCase();
    if (currentOnBallot && (k === 'approved' || k === 'merged')) return 'primary';
    const map = { pending:'warning', in_review:'info', needs_info:'secondary', approved:'success', rejected:'danger', merged:'info' };
    return map[k] || 'secondary';
  }
  function isRejectedStatus(s){ return String(s || '').toLowerCase() === 'rejected'; }
  function isLockedStatus(s){ return ['approved','merged'].includes(String(s || '').toLowerCase()); }
  function isValidateLocked(s){
    const key = String(s || '').toLowerCase();
    return votingLocked || key === 'rejected';
  }
  function applyControlLock(btn, locked){
    if (!btn) return;
    btn.disabled = locked;
    if (locked) {
      btn.classList.add('disabled');
      btn.setAttribute('aria-disabled', 'true');
      btn.style.pointerEvents = 'none';
      btn.tabIndex = -1;
    } else {
      btn.classList.remove('disabled');
      btn.removeAttribute('aria-disabled');
      btn.style.pointerEvents = '';
      btn.tabIndex = 0;
    }
  }
  function lockedActionsMessage(s, kind = 'actions'){
    const label = formatStatusLabel(s).toLowerCase();
    if (kind === 'validation') {
      return `This registration is already ${label}. Validation is locked.`;
    }
    return `This registration is already ${label}. Review actions are locked.`;
  }

  // ---- Fetch helper (strict JSON) ----
  function wait(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
  }
  async function fetchJSON(url, options = {}) {
    const { timeoutMs = 20000, emailSend = false, ...fetchOpts } = options;
    const ctrl = new AbortController();
    const t = setTimeout(() => ctrl.abort(), timeoutMs);
    let res, text;
    try {
      res = await fetch(url, { ...fetchOpts, signal: ctrl.signal, headers: { Accept: 'application/json', ...(fetchOpts?.headers || {}) } });
      text = await res.text();
    } catch (e) {
      if (e?.name === 'AbortError') {
        throw new Error(emailSend
          ? 'The email is still being sent. Wait a few seconds, then refresh. Do not send it again until you confirm it did not arrive.'
          : 'The request took too long. Refresh the page. Saved registration details were not changed.');
      }
      const msg = String(e?.message || '');
      if (emailSend && /Failed to fetch|NetworkError|Load failed/i.test(msg)) {
        throw new Error('The connection dropped while sending. Refresh and check the inbox before sending again.');
      }
      throw e;
    } finally { clearTimeout(t); }
    let data;
    try { data = JSON.parse((text || '').trim()); }
    catch {
      if (emailSend && res && (res.status === 502 || res.status === 504 || res.status === 524)) {
        throw new Error('The server took too long. Refresh and check the inbox before sending again.');
      }
      if (!emailSend && res && (res.status === 502 || res.status === 504 || res.status === 524)) {
        throw new Error('The request took too long. Refresh the page. Saved registration details were not changed.');
      }
      throw new Error(`Non-JSON response from ${url} (HTTP ${res?.status ?? 'n/a'})`);
    }
    if (!res.ok || data.status !== 'success') {
      throw new Error(data.message || `Request failed (HTTP ${res.status})`);
    }
    return data;
  }

  // ---- Mark registration as IN REVIEW ----
  async function setInReview({ silent = true } = {}) {
    if (!id) return;
    if (votingLocked) {
      if (!silent) showToast(votingLockToast, false);
      return;
    }
    if (!csrfToken) { if (!silent) showToast('Missing CSRF token', false); return; }
    try {
      await fetchJSON(ENDPOINTS.statusUpdate, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csrf: csrfToken, nomination_id: id, status: 'in_review' })
      });
      currentStatus = 'in_review';
      if (statusBadge) statusBadge.innerHTML = badgeFor(currentStatus);
      setActionsState(currentStatus);
      if (!silent) showToast('Marked as In Review', true);
    } catch (e) {
      if (!silent) showToast(e.message || 'Failed to mark In Review', false);
    }
  }

  async function updateStatusViaComm(actionKey, { sendEmail=true, subject='', html='', missingFields='' } = {}) {
    const statusForUpdate = actionKey === 'approve' ? 'approved'
                         : actionKey === 'needs_info' ? 'needs_info'
                         : actionKey === 'reject' ? 'rejected'
                         : 'pending';

    const payload = {
      csrf: csrfToken,
      nomination_id: id,
      status: statusForUpdate,
      missing_fields: actionKey === 'needs_info' ? (missingFields || '') : '',
      send_email: !!sendEmail
    };
    if (sendEmail) {
      if (subject) payload.subject = subject;
      if (html)    payload.html    = html;
    }
    await fetchJSON(ENDPOINTS.statusUpdate, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
      timeoutMs: sendEmail ? 120000 : 20000,
      emailSend: !!sendEmail
    });
  }

  function badgeFor(status){
    return `<span class="badge text-bg-${statusTone(status)}">${formatStatusLabel(status)}</span>`;
  }

  // ---------- Categories/Awards ----------
  function esc(s) {
    return String(s ?? '')
      .replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;')
      .replaceAll('"','&quot;').replaceAll("'",'&#39;');
  }
  function groupCategories(flatList, removedList) {
    const map = new Map();
    function ensureGroup(row) {
      const cid   = Number(row.category_id ?? 0) || 0;
      const cname = row.category_name || (cid ? `Category ${cid}` : 'Other');
      const key   = cid ? `id:${cid}` : `name:${cname}`;
      if (!map.has(key)) map.set(key, { category_id: cid, category_name: cname, awards: [] });
      return map.get(key);
    }
    (flatList || []).forEach(row => {
      const awardLabel =
        row.award_name || row.question_name || row.name || (row.question_id ? `#${row.question_id}` : '');
      if (!awardLabel) return;
      const entryNames = Array.isArray(row.entry_names)
        ? row.entry_names.map((n) => String(n || '').trim()).filter(Boolean)
        : [];
      ensureGroup(row).awards.push({
        question_id: row.question_id ?? null,
        label: awardLabel,
        entry_names: entryNames,
        entry_kind: row.entry_kind || '',
        description: row.description ?? '',
        type: row.type ?? '',
        removed: false,
        reason_label: '',
        twg_rank: row.twg_rank ?? null,
        twg_average: row.twg_average ?? null,
        fully_graded: !!row.fully_graded,
        in_top5: !!(row.in_top5 || row.in_top10),
        scored: Number(row.scored || 0),
        member_count: Number(row.member_count || 0)
      });
    });
    (removedList || []).forEach(row => {
      const awardLabel =
        row.award_name || row.question_name || row.name || (row.question_id ? `#${row.question_id}` : '');
      if (!awardLabel) return;
      const entryNames = Array.isArray(row.entry_names)
        ? row.entry_names.map((n) => String(n || '').trim()).filter(Boolean)
        : [];
      ensureGroup(row).awards.push({
        question_id: row.question_id ?? null,
        label: awardLabel,
        entry_names: entryNames,
        entry_kind: row.entry_kind || '',
        description: '',
        type: '',
        removed: true,
        reason_label: row.reason_label || row.reason || '',
        entry_reasons: Array.isArray(row.entry_reasons) ? row.entry_reasons : []
      });
    });
    return Array.from(map.values());
  }
  function flattenAwardRows(grouped, removed) {
    const rows = [];
    (grouped || []).forEach(cat => {
      (cat.awards || []).filter(a => !!a.removed === removed).forEach(a => {
        rows.push({
          question_id: a.question_id ?? '',
          label: a.label,
          entry_names: a.entry_names || [],
          entry_kind: a.entry_kind || '',
          category: cat.category_name || '',
          reason: a.reason_label || '',
          entry_reasons: a.entry_reasons || [],
          twg_rank: a.twg_rank ?? null,
          twg_average: a.twg_average ?? null,
          fully_graded: !!a.fully_graded,
          in_top5: !!a.in_top5,
          uses_shortlist: a.uses_shortlist !== false
            && !String(cat.category_name || '').toLowerCase().includes('feeling'),
          scored: Number(a.scored || 0),
          member_count: Number(a.member_count || 0)
        });
      });
    });
    return rows;
  }
  function standingBadgeHtml(r) {
    const fully = !!r.fully_graded;
    const usesShortlist = r.uses_shortlist !== false;
    const inTop = !!r.in_top5;
    const rank = r.twg_rank != null && r.twg_rank !== '' ? Number(r.twg_rank) : null;
    const scored = Number(r.scored || 0);
    const total = Number(r.member_count || 7);
    if (fully && !usesShortlist) {
      return '<span class="badge text-bg-success twg-stand-badge">Eligible</span>';
    }
    if (fully && inTop) {
      const extra = rank ? ` · #${rank}` : '';
      return `<span class="badge text-bg-warning twg-stand-badge">Top 5${extra}</span>`;
    }
    if (fully) {
      const extra = rank ? ` · #${rank}` : '';
      return `<span class="badge text-bg-secondary twg-stand-badge">Not in Top 5${extra}</span>`;
    }
    if (scored > 0) {
      return `<span class="badge text-bg-light text-dark border twg-stand-badge">Scoring ${scored}/${total}</span>`;
    }
    if (currentChoiceId) {
      return `<span class="badge text-bg-light text-dark border twg-stand-badge">Not scored</span>`;
    }
    return '<span class="text-muted">—</span>';
  }
  function fillAwardTable(tbody, rows, columns, emptyText, options = {}) {
    if (!tbody) return;
    const withStanding = options.standing === true;
    const colCount = withStanding ? 3 : columns;
    if (!rows.length) {
      tbody.innerHTML = `<tr><td colspan="${colCount}" class="text-muted">${esc(emptyText)}</td></tr>`;
      return;
    }
    tbody.innerHTML = rows.map((r) => {
      const qid = esc(r.question_id);
      let labelHtml = esc(r.label);
      const kind = String(r.entry_kind || '');
      const names = Array.isArray(r.entry_names) ? r.entry_names.filter(Boolean) : [];
      if (names.length) {
        const kindLabel = kind === 'artist' ? 'Artist' : (kind === 'stylist' ? 'Stylist' : 'Product');
        labelHtml += `<div class="small text-muted mt-1"><span class="fw-semibold">${esc(kindLabel)}:</span> ${esc(names.join(', '))}</div>`;
      }
      if (withStanding && kind && !isRejectedStatus(currentStatus)) {
        const kindLabel = kind === 'artist' ? 'Artist name' : (kind === 'stylist' ? 'Stylist name' : 'Product name');
        const currentName = names[0] || '';
        labelHtml += `<div class="d-flex flex-wrap align-items-center gap-2 mt-2">
          <input type="text" class="form-control form-control-sm nom-product-input" maxlength="180"
                 data-question-id="${qid}" value="${esc(currentName)}" placeholder="${esc(kindLabel)}" style="max-width:16rem">
          <button type="button" class="btn btn-sm btn-outline-primary nom-product-save" data-question-id="${qid}">Save</button>
        </div>`;
      }
      let html = `<tr data-question-id="${qid}"><td>${labelHtml}</td><td>${esc(r.category || '—')}</td>`;
      if (withStanding) {
        html += `<td>${standingBadgeHtml(r)}</td>`;
      } else if (columns === 3) {
        const per = Array.isArray(r.entry_reasons) ? r.entry_reasons : [];
        let reasonHtml = esc(r.reason || '—');
        if (names.length && per.length === names.length) {
          const unique = [...new Set(per.map((x) => String(x || '').trim()).filter(Boolean))];
          if (unique.length > 1) {
            reasonHtml = names.map((n, i) => (
              `<div class="mb-1">${esc(n)} <span class="text-muted">— ${esc(per[i] || '—')}</span></div>`
            )).join('');
          } else if (unique.length === 1) {
            reasonHtml = esc(unique[0]);
          }
        }
        html += `<td>${reasonHtml}</td>`;
      }
      return html + '</tr>';
    }).join('');
  }
  function filterRemainingAwardRows(rows) {
    const mode = document.getElementById('awardStandingFilter')?.value || 'all';
    if (mode === 'top5') return rows.filter((r) => r.fully_graded && r.in_top5);
    if (mode === 'not_top5') return rows.filter((r) => r.fully_graded && !r.in_top5);
    if (mode === 'incomplete') return rows.filter((r) => !r.fully_graded);
    return rows;
  }
  function emptyStandingText(mode, hasAny) {
    if (!hasAny) return 'No remaining awards on this registration.';
    if (mode === 'top5') return 'None of this business’s remaining titles are eligible for the public ballot yet.';
    if (mode === 'not_top5') return 'No remaining Food or Service titles are fully scored outside the Top 5.';
    if (mode === 'incomplete') return 'Every remaining title is fully scored.';
    return 'No remaining awards on this registration.';
  }
  function renderRemainingAwardTable() {
    const mode = document.getElementById('awardStandingFilter')?.value || 'all';
    const visible = filterRemainingAwardRows(remainingAwardRows);
    fillAwardTable(
      approvedAwardsBody,
      visible,
      3,
      emptyStandingText(mode, remainingAwardRows.length > 0),
      { standing: true }
    );
  }
  approvedAwardsBody?.addEventListener('click', async (e) => {
    const btn = e.target.closest('.nom-product-save');
    if (!btn || !approvedAwardsBody.contains(btn)) return;
    e.preventDefault();
    if (isRejectedStatus(currentStatus)) {
      showToast(lockedActionsMessage(currentStatus), false);
      return;
    }
    const qid = Number(btn.getAttribute('data-question-id') || 0);
    const input = approvedAwardsBody.querySelector(`.nom-product-input[data-question-id="${qid}"]`);
    const name = String(input?.value || '').trim();
    if (qid <= 0) return;
    if (!name) {
      showToast('Enter a product name first.', false);
      input?.focus();
      return;
    }
    const stop = spinButton(btn, 'Saving…');
    try {
      const fd = new FormData();
      fd.append('action', 'save_award_entry');
      fd.append('nomination_id', String(id));
      fd.append('question_id', String(qid));
      fd.append('entry_name', name);
      await fetchJSON(ENDPOINTS.approveMerge, { method: 'POST', body: fd });
      showToast('Product name saved.', true);
      await loadProfile();
    } catch (err) {
      showToast(err.message || 'Could not save the product name.', false);
    } finally {
      stop();
    }
  });
  approvedAwardsBody?.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter') return;
    const input = e.target.closest('.nom-product-input');
    if (!input) return;
    e.preventDefault();
    const qid = input.getAttribute('data-question-id');
    approvedAwardsBody.querySelector(`.nom-product-save[data-question-id="${qid}"]`)?.click();
  });
  function applyStandingFromEligibility(rows) {
    const awards = Array.isArray(currentBallotEligibility?.awards) ? currentBallotEligibility.awards : [];
    const byQ = {};
    awards.forEach((a) => {
      const qid = Number(a.question_id || 0);
      if (qid) byQ[qid] = a;
    });
    window.toccaTwgStandingByQuestion = {};
    rows.forEach((r) => {
      const qid = Number(r.question_id || 0);
      const st = byQ[qid];
      if (st) {
        r.twg_rank = st.twg_rank ?? r.twg_rank ?? null;
        r.twg_average = st.twg_average ?? r.twg_average ?? null;
        r.fully_graded = !!(st.fully_graded || r.fully_graded);
        r.in_top5 = !!(st.in_top5 || st.in_top10 || r.in_top5);
        r.uses_shortlist = st.uses_shortlist !== false;
        r.scored = Number(st.scored || r.scored || 0);
        r.member_count = Number(st.member_count || r.member_count || 7);
      }
      if (qid) {
        window.toccaTwgStandingByQuestion[qid] = {
          fully_graded: !!r.fully_graded,
          in_top5: !!r.in_top5,
          in_top10: !!r.in_top5,
          uses_shortlist: r.uses_shortlist !== false,
          twg_rank: r.twg_rank,
          scored: r.scored,
          member_count: r.member_count || 7
        };
      }
    });
    if (typeof window.toccaStampValidateStanding === 'function') {
      window.toccaStampValidateStanding();
    }
    return rows;
  }
  function renderCategoryTabs(catsFlat, catIdsFallback, removedList) {
    if (!catWrap || !approvedAwardsBody || !removedAwardsBody) return false;
    catLoader?.classList.remove('d-none');

    const grouped = groupCategories(catsFlat, removedList);
    remainingAwardRows = applyStandingFromEligibility(flattenAwardRows(grouped, false));
    const removedRows = flattenAwardRows(grouped, true);

    if (!grouped.length) {
      const empty = Array.isArray(catIdsFallback) && catIdsFallback.length
        ? `No award names returned for categories: ${catIdsFallback.map(id => `#${id}`).join(', ')}.`
        : 'No categories found.';
      remainingAwardRows = [];
      fillAwardTable(approvedAwardsBody, [], 3, empty, { standing: true });
      fillAwardTable(removedAwardsBody, [], 3, 'No awards or products have been removed.');
      catLoader?.classList.add('d-none');
      return true;
    }

    renderRemainingAwardTable();
    fillAwardTable(removedAwardsBody, removedRows, 3, 'No awards or products have been removed.');
    catLoader?.classList.add('d-none');
    return true;
  }
  function renderCategoryChips(cats, catIds) {
    if (!categoriesWrap) return;
    categoriesWrap.innerHTML = '';
    if (cats?.length) {
      cats.forEach(cat => {
        const label =
          (cat.category_name ? `${cat.category_name}: ` : '') +
          (cat.award_name || cat.question_name || cat.name || `#${cat.question_id ?? cat.category_id ?? '?'}`);
        const span = document.createElement('span');
        span.className = 'pill';
        span.textContent = label;
        categoriesWrap.appendChild(span);
      });
    } else if (catIds?.length) {
      catIds.forEach(cid => {
        const span = document.createElement('span');
        span.className = 'pill';
        span.textContent = `#${cid}`;
        categoriesWrap.appendChild(span);
      });
    } else {
      categoriesWrap.innerHTML = '<span class="text-muted">None</span>';
    }
  }

  // ------- ACTIONS LOCK -------
  function setActionsState(status) {
    const lockedByStatus = isLockedStatus(status);
    const rejected = isRejectedStatus(status);
    const locked = lockedByStatus || votingLocked;
    const reviewLocked = locked || rejected;
    [btnApprove, btnNeeds, btnStartReview].forEach(b => applyControlLock(b, reviewLocked));
    applyControlLock(btnReject, votingLocked || rejected);
    validateButtons.forEach(b => applyControlLock(b, isValidateLocked(status)));
    if (mergeChoiceId) mergeChoiceId.disabled = locked;
    if (btnStartReview) {
      const showStart = !reviewLocked && ['pending', 'submitted', 'new', ''].includes(String(status || '').toLowerCase());
      btnStartReview.classList.toggle('d-none', !showStart);
    }
    if (btnReopen) {
      const showReopen = rejected && !votingLocked;
      btnReopen.classList.toggle('d-none', !showReopen);
      applyControlLock(btnReopen, !showReopen);
    }

    const hintId = 'actionsHint';
    let hint = document.getElementById(hintId);
    if (!hint) {
      hint = document.createElement('div');
      hint.id = hintId;
      hint.className = 'small mt-3 text-muted';
      const where = document.getElementById('actionsRow') || btnApprove?.parentElement || content;
      where?.appendChild(hint);
    }
    let reason = '';
    if (votingLocked) {
      reason = 'Actions are disabled while the voting period is in progress.';
    } else if (lockedByStatus) {
      reason = `This registration is already ${formatStatusLabel(status).toLowerCase()}. Proceed to evaluation is locked. You can still reject it if it should not proceed.`;
    } else if (rejected) {
      reason = 'This registration is rejected. Reopen it to continue review or proceed to evaluation.';
    }
    hint.textContent = reason;
    hint.classList.toggle('d-none', !reason);
    setBallotStageUI();
  }

  function setBallotStageUI() {
    const statusKey = String(currentStatus || '').toLowerCase();
    const isApproved = ['approved', 'merged'].includes(statusKey);
    const onBallot = currentOnBallot === true;
    const choiceId = Number(currentChoiceId || 0);

    if (ballotBadge) {
      ballotBadge.innerHTML = '';
    }
    if (statusBadge && currentStatus) {
      statusBadge.innerHTML = badgeFor(currentStatus);
    }

    if (btnReleaseBallot) {
      const showRelease = isApproved && !onBallot && choiceId > 0;
      const allGraded = currentBallotEligibility?.all_graded === true;
      btnReleaseBallot.classList.toggle('d-none', !showRelease);
      btnReleaseBallot.disabled = !showRelease || !allGraded;
      if (showRelease) {
        btnReleaseBallot.innerHTML = currentBallotEligibility?.none_in_top10
          ? '<i class="bi bi-envelope me-1"></i> Send evaluation notice'
          : '<i class="bi bi-megaphone me-1"></i> Confirm for public voting';
      }
    }

    if (ballotStageHint) {
      let msg = '';
      let cls = 'small mt-3';
      const elig = currentBallotEligibility;
      if (isApproved && onBallot) {
        msg = 'This business is on the public ballot for its eligible titles (TWG Top 5 for Food and Service; all fully graded Feelings titles). The QR code and voting link are emailed when you confirm for public voting. You can resend from File Maintenance → Businesses if needed.';
        cls += ' text-success';
      } else if (isApproved && !onBallot && elig && elig.remaining_count === 0) {
        msg = 'This business has no remaining award titles. Finish evaluation first.';
        cls += ' text-muted';
      } else if (isApproved && !onBallot && elig && !elig.all_graded) {
        const left = (Number(elig.remaining_count) || 0) - (Number(elig.graded_count) || 0);
        msg = left === 1
          ? 'Confirm for public voting stays disabled until the remaining award title is fully graded. Food and Service titles must place in the TWG Top 5. Feelings titles skip shortlisting.'
          : `Confirm for public voting stays disabled until all remaining award titles are fully graded (${left} still incomplete). Food and Service titles must place in the TWG Top 5. Feelings titles skip shortlisting.`;
        cls += ' text-muted';
      } else if (isApproved && !onBallot && elig?.none_in_top10) {
        msg = 'All remaining titles are graded, and none of the Food or Service titles placed in the TWG Top 5. Confirming will not add this business to the public ballot. You can email an evaluation notice instead.';
        cls += ' text-warning';
      } else if (isApproved && !onBallot && elig?.can_release) {
        const n = (elig.top10 || []).length;
        msg = n === 1
          ? 'Ready. Confirming adds the 1 eligible title to the public ballot and emails the QR code and voting link.'
          : `Ready. Confirming adds ${n} eligible titles to the public ballot and emails the QR code and voting link.`;
        cls += ' text-muted';
      } else if (isApproved && !onBallot) {
        msg = 'Under evaluation. Remove titles that do not qualify, finish TWG scoring, then confirm for public voting. Food and Service titles must place in the TWG Top 5. Feelings titles skip shortlisting.';
        cls += ' text-muted';
      }
      ballotStageHint.className = cls + (msg ? '' : ' d-none');
      ballotStageHint.textContent = msg;
    }
  }

  // ---------- Dynamic details helpers ----------
  const NAME_ALIASES = {
    business_name: ['business_name','official_business_name','company','company_name','business'],
    owner_name   : ['owner_name','owner','proprietor','owner_president_gm','owner_president_general_manager'],
    email        : ['email','contact_email','email_address'],
    mobile       : ['mobile_number','mobile','contact_phone','phone','telephone','contact_number'],
    address      : ['address','full_address','street','barangay'],
    website      : ['website','facebook','site','url'],
    mayor        : ['mayors_permit_no','mayors_permit_number','mayors_permit','mayor_permit_no','mayor_s_permit','mayor_s_permit_number','mayor_permit']
  };
  function norm(s){
    return String(s || '').trim().toLowerCase().replace(/\s+/g,'_');
  }
  function stripAsterisk(lbl){
    return String(lbl || '').replace(/\s*\*+$/, '').trim();
  }
  function findByAliases(answers, aliasList){
    const set = new Set(aliasList.map(norm));
    return answers.find(a => set.has(norm(a.name)) || set.has(norm(a.label))) || null;
  }
  function isValidEmail(value) {
    const s = String(value || '').trim();
    return s !== '' && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(s);
  }
  function getNomineeContact() {
    const rec = currentNomination || {};
    let email = String(rec.email || '').trim();
    if (!email && currentAnswers.length) {
      const row = findByAliases(currentAnswers, NAME_ALIASES.email);
      if (row?.answer) email = String(row.answer).trim();
    }
    let mobile = '';
    for (const key of ['mobile_number', 'phone', 'mobile']) {
      if (rec[key]) { mobile = String(rec[key]).trim(); break; }
    }
    if (!mobile && currentAnswers.length) {
      const row = findByAliases(currentAnswers, NAME_ALIASES.mobile);
      if (row?.answer) mobile = String(row.answer).trim();
    }
    return {
      email,
      mobile,
      hasValidEmail: isValidEmail(email),
      hasMobile: mobile.length > 0,
    };
  }
  function updateNotifyContactUI() {
    if (!notifyContactAlert || !sendAndUpdateBtn) return;
    const { email, mobile, hasValidEmail, hasMobile } = getNomineeContact();

    sendAndUpdateBtn.disabled = !hasValidEmail;
    sendAndUpdateBtn.title = hasValidEmail
      ? ''
      : 'No valid email on file — use Update Status Only and contact by phone if listed below.';

    const parts = [];
    if (hasValidEmail) {
      notifyContactAlert.className = 'alert alert-info small mb-3';
      parts.push(
        `<i class="bi bi-envelope-check me-1"></i> Email will be sent to ` +
        `<a href="mailto:${esc(email)}">${esc(email)}</a>.`
      );
      if (hasMobile) {
        parts.push(
          `<span class="d-block mt-1"><i class="bi bi-phone me-1"></i> ` +
          `You can also follow up by mobile: <a href="tel:${esc(mobile.replace(/\s/g, ''))}">${esc(mobile)}</a>.</span>`
        );
      }
    } else {
      notifyContactAlert.className = 'alert alert-warning small mb-3';
      parts.push(
        `<i class="bi bi-envelope-x me-1"></i> <strong>No email on file.</strong> ` +
        `Use <strong>Update Status Only</strong>, then contact the business manually`
      );
      if (hasMobile) {
        parts.push(
          ` via mobile: <a href="tel:${esc(mobile.replace(/\s/g, ''))}">${esc(mobile)}</a> ` +
          `(copy the message below for SMS if needed).`
        );
      } else {
        parts.push(` — no mobile number on file either.`);
      }
    }
    notifyContactAlert.innerHTML = parts.join('');
    notifyContactAlert.classList.remove('d-none');
  }
  function formatValue(item){
    const type = (item.type || '').toLowerCase();
    const val  = item.answer;
    if (val == null || String(val).trim() === '') return '–';

    if (type === 'email') return `<a href="mailto:${esc(val)}">${esc(val)}</a>`;
    if (type === 'url')   return `<a href="${esc(val)}" target="_blank" rel="noopener">${esc(val)}</a>`;
    if (type === 'tel')   return `<a href="tel:${esc(val)}">${esc(val)}</a>`;
    if (type === 'file') {
      const role = String(item.profile_role || '');
      const isMayor = role === 'mayor_permit'
        || /mayor.*permit/i.test(`${item.label || ''} ${item.name || ''}`);
      const raw = String(val);
      const isImage = /\.(png|jpe?g|webp|gif|svg)$/i.test(raw)
        || /uploads?\/nominations\/.+\.(png|jpe?g|webp|gif)$/i.test(raw);
      if (isImage) {
        const label = esc(stripAsterisk(item.label || 'File'));
        return `<button type="button" class="nom-inline-file-btn p-0 border-0 bg-transparent text-start"`
          + ` data-preview-url="${esc(val)}" data-preview-label="${label}"`
          + ` aria-label="View full size: ${label}">`
          + `<img src="${esc(val)}" alt="${label}" class="nom-inline-file">`
          + `<span class="nom-inline-file-zoom" aria-hidden="true"><i class="bi bi-zoom-in"></i></span>`
          + `<span class="nom-inline-file-missing text-muted d-none">Image unavailable</span>`
          + `</button>`;
      }
      const looksLikeFile = /^(https?:\/\/|\/).+|\\|uploads?\/|\.(pdf|doc|docx)$/i.test(raw);
      if (!looksLikeFile) {
        if (isMayor) {
          return `${esc(val)} <span class="badge text-bg-light border">Legacy text</span>`;
        }
        return esc(val);
      }
      return `<a href="${esc(val)}" target="_blank" rel="noopener">Download</a>`;
    }
    if (Array.isArray(val)) return val.join(', ');
    return esc(val);
  }

  function isLogoField(a){
    return /(logo)/i.test(`${a.name || ''} ${a.label || ''}`) && String(a.type || '').toLowerCase() === 'file';
  }
  function fieldKey(item){
    return `${norm(item.name)}::${norm(item.label)}`;
  }
  const ROLE_SECTION = {
    business_name: 'business',
    owner_name: 'contact',
    email: 'contact',
    mobile: 'contact',
    website: 'contact',
    mayor_permit: 'permits',
    address: 'location',
    logo: 'other',
  };
  function isMayorField(item){
    const role = String(item.profile_role || '');
    if (role === 'mayor_permit') return true;
    if (findByAliases([item], NAME_ALIASES.mayor)) return true;
    return /mayor.*permit|permit[_\s-]*number|permit[_\s-]*no\b/i.test(`${item.label || ''} ${item.name || ''}`);
  }
  function mayorValueRank(item){
    const val = String(item.answer || '').trim();
    if (!val) return 0;
    if (/\.(png|jpe?g|webp|gif|svg)$/i.test(val) || /uploads?\//i.test(val)) return 2;
    return 1;
  }
  function classifyField(item){
    const role = String(item.profile_role || '').trim();
    if (role && role !== 'custom' && ROLE_SECTION[role]) return ROLE_SECTION[role];
    const text = `${norm(item.label)} ${norm(item.name)}`;
    if (findByAliases([item], NAME_ALIASES.business_name)
        || /designation|type_of_ownership|proprietor|business_type|company_type|establishment_type/.test(text)) {
      return 'business';
    }
    if (findByAliases([item], NAME_ALIASES.owner_name) ||
        findByAliases([item], NAME_ALIASES.email) ||
        findByAliases([item], NAME_ALIASES.mobile) ||
        findByAliases([item], NAME_ALIASES.website) ||
        /owner|president|general_manager|contact|phone|telephone|email|mobile|website|facebook/.test(text)) {
      return 'contact';
    }
    if (findByAliases([item], NAME_ALIASES.mayor) || /permit|registration|license|dti|sec|bir/.test(text)) {
      return 'permits';
    }
    if (findByAliases([item], NAME_ALIASES.address) || /address|barangay|street|location|city|province/.test(text)) {
      return 'location';
    }
    if (String(item.name || '').toLowerCase() === 'establishment_type_id') {
      return 'other';
    }
    return 'other';
  }
  const SECTION_META = {
    business: { title: 'Business Information', icon: 'bi-building' },
    contact:  { title: 'Contact Details', icon: 'bi-person-lines-fill' },
    permits:  { title: 'Permits & Registration', icon: 'bi-file-earmark-check' },
    location: { title: 'Location', icon: 'bi-geo-alt' },
    other:    { title: 'Additional Details', icon: 'bi-card-list' },
  };
  function renderFieldItem(item){
    const label = stripAsterisk(item.label || item.name || '');
    if (!label) return '';
    const fieldName = esc(item.name || '');
    return `
      <div class="nom-dl-row" data-field-name="${fieldName}">
        <dt class="nom-dl-label">${esc(label)}</dt>
        <dd class="nom-dl-value">${formatValue(item)}</dd>
      </div>`;
  }

  function renderDynamicDetails(answers){
    if (!dynamicDetails) return;

    const buckets = { business: [], contact: [], permits: [], location: [], other: [] };
    const seen = new Set();
    let mayorBest = null;
    let mayorBestRank = -1;

    (answers || []).forEach(a => {
      if (isLogoField(a)) return;
      if (findByAliases([a], NAME_ALIASES.business_name)) return;
      const key = fieldKey(a);
      if (seen.has(key)) return;
      seen.add(key);
      if (isMayorField(a)) {
        const rank = mayorValueRank(a);
        if (rank > mayorBestRank) {
          mayorBest = a;
          mayorBestRank = rank;
        }
        return;
      }
      buckets[classifyField(a)].push(a);
    });
    if (mayorBest) buckets.permits.unshift(mayorBest);

    const order = ['business', 'contact', 'permits', 'location', 'other'];
    const sections = order
      .filter(id => buckets[id].length > 0)
      .map(id => {
        const meta = SECTION_META[id];
        return `
          <section class="nom-profile-section">
            <div class="nom-section-head"><i class="bi ${meta.icon} me-2"></i>${meta.title}</div>
            <dl class="nom-dl mb-0">
              ${buckets[id].map(renderFieldItem).join('')}
            </dl>
          </section>`;
      });

    dynamicDetails.innerHTML = sections.length
      ? `<div class="nom-profile-sections">${sections.join('')}</div>`
      : `<div class="text-muted">No details available.</div>`;
  }

  // ---- Load profile ----
  async function loadProfile(){
    try{
      const data = await fetchJSON(ENDPOINTS.getNomination(id), { cache:'no-store', timeoutMs: 120000 });

      const r       = data.nomination || {};
      const catIds  = Array.isArray(data.category_ids) ? data.category_ids : [];
      const cats    = Array.isArray(data.categories)    ? data.categories    : [];
      const removed = Array.isArray(data.removed_awards) ? data.removed_awards : [];
      const answers = Array.isArray(data.answers)       ? data.answers       : []; // requires GET to return answers

      currentAnswers = answers;

      loadingBox.classList.add('d-none'); errorBox.classList.add('d-none'); content.classList.remove('d-none');

      // Prefer dynamic “business name” from answers if DB column is empty
      const dynBiz = findByAliases(answers, NAME_ALIASES.business_name)?.answer || '';
      const businessDisplay = r.business_name || dynBiz || 'Business';
      if (bizTitle) bizTitle.textContent = businessDisplay;

      currentNomination   = Object.assign({}, r, { business_name: businessDisplay });
      currentCategoryIds  = catIds;
      currentCategories   = cats;
      currentStatus       = r.status || 'pending';
      currentChoiceId     = Number(data.choice_id || r.merged_choice_id || 0) || null;
      currentOnBallot     = data.on_ballot === true || data.on_ballot === 1 || data.on_ballot === '1';
      currentBallotEligibility = data.ballot_eligibility || null;

      // Status + dates
      statusBadge.innerHTML = badgeFor(r.status || 'pending');

      const cdt = parseSQLDateTime(r.created_at);
      const udt = parseSQLDateTime(r.updated_at);
      if (createdSpan) createdSpan.textContent = cdt ? cdt.toLocaleString() : (r.created_at || '—');
      if (updatedSpan) updatedSpan.textContent = udt ? udt.toLocaleString() : (r.updated_at || '—');

      // Logo
      let logoPath = r.logo_path || '';
      if (!logoPath) {
        const maybeLogo = answers.find(a =>
          (a.type || '').toLowerCase() === 'file' &&
          /(logo)/i.test(a.name || a.label || '') &&
          a.answer
        );
        if (maybeLogo) logoPath = maybeLogo.answer;
      }
      logoBox.innerHTML = '';
      logoBox.classList.toggle('nom-profile-logo--empty', !logoPath);
      if (logoPath) {
        const img = document.createElement('img');
        img.src = logoPath;
        img.alt = 'Business logo';
        logoBox.appendChild(img);
      } else {
        logoBox.innerHTML = '<i class="bi bi-shop fs-2 text-muted" aria-hidden="true"></i>';
      }

      // === Establishment type id (get from row or from answers field named "establishment_type_id") ===
      const typeFromRow = Number(r.establishment_type_id || 0) || null;
      const ansTypeItem = answers.find(a => String(a.name || '').toLowerCase() === 'establishment_type_id');
      const typeFromAns = ansTypeItem ? (Number(ansTypeItem.answer) || null) : null;
      establishmentTypeId = typeFromRow ?? typeFromAns; // prefer DB column if present

      // === Dynamic field/answers ===
      renderDynamicDetails(answers);

      // Categories / Awards
      const renderedTabs = renderCategoryTabs(cats, catIds, removed);
      if (!renderedTabs) renderCategoryChips(cats, catIds);

      // Lock UI if approved/rejected/merged
      setActionsState(currentStatus);

    } catch(e){
      loadingBox.classList.add('d-none');
      errorBox.classList.remove('d-none');
      errorBox.textContent = e.message || 'Failed to load registration.';
    }
  }

  // ---- Prevent native form submit via button clicks ----
  [btnApprove, btnNeeds, btnReject, ...validateButtons].forEach(btn => {
    if (!btn) return;
    const t = (btn.getAttribute('type') || '').toLowerCase();
    if (t === '' || t === 'submit') btn.setAttribute('type', 'button');
  });

  const possibleForm = btnApprove?.form || btnNeeds?.form || btnReject?.form || validateButtons[0]?.form;
  if (possibleForm) {
    possibleForm.addEventListener('submit', (e) => {
      if (e.submitter && (e.submitter === btnApprove || e.submitter === btnNeeds || e.submitter === btnReject || validateButtons.includes(e.submitter))) {
        e.preventDefault();
        e.stopPropagation();
      }
    }, true);
  }

  // ---- Action runner ----
  async function doAction(actionKey, { notify=false, subject='', message='', btn=null } = {}) {
    if (isLockedStatus(currentStatus) && actionKey !== 'reject') {
      showToast(lockedActionsMessage(currentStatus), false);
      return;
    }
    if (isRejectedStatus(currentStatus) && actionKey !== 'reopen') {
      showToast('Reopen this registration before changing its review status.', false);
      return;
    }
    if (votingLocked) {
      showToast(votingLockToast, false);
      return;
    }
    if (actionKey === 'approve') {
      notify = false;
    }
    const prevStatus = currentStatus;
    const stopSpin = spinButton(btn, 'Updating…');

    try {
      const fd = new FormData();
      fd.append('nomination_id', String(id));

      if (actionKey === 'approve') {
        fd.append('action', 'approve');
        const mergeId = (mergeChoiceId?.value || '').trim();
        if (mergeId) fd.append('target_choice_id', mergeId);

        // NEW: include establishment_type_id so backend can store it in tbl_choices
        if (establishmentTypeId) {
          fd.append('establishment_type_id', String(establishmentTypeId));
        }
      } else if (actionKey === 'needs_info') {
        fd.append('action', 'needs_info');
      } else if (actionKey === 'reject') {
        fd.append('action', 'reject');
      } else if (actionKey === 'reopen') {
        fd.append('action', 'reopen');
      }

      const result = await fetchJSON(ENDPOINTS.approveMerge, { method: 'POST', body: fd });

      if (actionKey === 'approve') {
        currentStatus = 'approved';
        currentOnBallot = false;
        if (result.choice_id) currentChoiceId = Number(result.choice_id);
        setActionsState(currentStatus);
      } else if (actionKey === 'reopen') {
        currentStatus = result.status || 'in_review';
        setActionsState(currentStatus);
      }

      if (notify) {
        if (btn) btn.innerHTML = `<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Sending…`;
        const missing = (actionKey === 'needs_info')
          ? (document.getElementById('missingFields')?.value.trim() || '')
          : '';
        await updateStatusViaComm(actionKey, {
          sendEmail: true,
          subject,
          html: message,
          missingFields: missing
        });
        await wait(1200);
      }

      await loadProfile();
      if (actionKey === 'approve') {
        showToast('Sent to evaluation. No email was sent. Confirm for public voting later to email the QR and voting link.', true);
      } else if (actionKey === 'reopen') {
        showToast('Registration reopened. You can continue review or proceed to evaluation.', true);
      } else {
        const newLabel = formatStatusLabel(currentStatus);
        let baseMsg = (currentStatus !== prevStatus)
          ? `Status updated to ${newLabel}.`
          : `Status is already ${newLabel}.`;
        showToast(baseMsg, true);
      }

    } catch (e) {
      showToast(e.message || 'Action failed', false);
    } finally {
      stopSpin();
    }
  }

  function setModalStatus(statusKey) {
    const map = { needs_info:'Needs Info', rejected:'Rejected' };
    if (notifyModalStatusLabel) {
      notifyModalStatusLabel.textContent = map[statusKey] || 'Needs Info';
    }
  }
  function loadTemplate(statusKey) {
    const tpl = TEMPLATES[statusKey] || TEMPLATES.needs_info;
    notifySubject.value = tpl.subject;
    if (!quill) initQuill();
    quill.root.innerHTML = tpl.body.trim();
    renderPreview();
  }
  function applyPlaceholders(html, { preview = false } = {}) {
    const rec = currentNomination || {};
    let categoryLabels = [];
    if (currentCategories?.length) {
      categoryLabels = currentCategories.map(cat => {
        const group = String(cat.category_name || '').trim();
        const award = String(
          cat.award_name || cat.question_name || cat.name || `#${cat.question_id ?? cat.category_id ?? '?'}`
        ).trim();
        const parts = [];
        if (group) parts.push(`<strong>${sanitize(group)}</strong>`);
        if (award) parts.push(`<strong>${sanitize(award)}</strong>`);
        return parts.join(': ');
      });
    } else if (currentCategoryIds?.length) {
      categoryLabels = currentCategoryIds.map(id => `<strong>${sanitize('#' + id)}</strong>`);
    }
    const categoryList = categoryLabels.length ? categoryLabels.join(', ') : 'your selected category';
    const eventName    = 'Tatak Ormoc Consumers’ Choice Awards';

    let out = html
      .replaceAll('{business_name}', sanitize(rec.business_name || 'Valued Business'))
      .replaceAll('{owner_name}', sanitize(rec.owner_name || (findByAliases(currentAnswers, NAME_ALIASES.owner_name)?.answer || '')))
      .replaceAll('{category_list}', categoryList)
      .replaceAll('{event_name}', sanitize(eventName))
      .replaceAll('{support_email}', '');
    if (preview) {
      out = out
        .replaceAll('{vote_url}', '<em>Voting link is added when this email is sent</em>')
        .replaceAll('{qr_code}', '<span class="d-inline-block border rounded bg-white p-3 text-muted">QR code is added when this email is sent</span>');
    }
    return out;
  }
  function renderPreview() {
    const rawHtml = quill ? quill.root.innerHTML : '';
    const replaced = applyPlaceholders(rawHtml, { preview: true });
    const rec = currentNomination || {};
    const statusLabel = notifyModalStatusLabel?.textContent || 'Needs Info';
    const headingMap = {
      'Needs Info': 'More Information Needed',
      Rejected: 'Registration Update',
    };
    if (window.toccaBrandedEmail) {
      window.toccaBrandedEmail.renderInto(notifyPreview, {
        heading: headingMap[statusLabel] || 'Registration Update',
        greetingName: rec.business_name || 'there',
        showRegistrationAssist: true,
        bodyHtml: replaced,
      });
      return;
    }
    notifyPreview.innerHTML = `<div style="white-space:normal; word-break:break-word;">${replaced}</div>`;
  }
  function sanitize(str) {
    return String(str)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;');
  }
  document.querySelectorAll('.placeholder-chip').forEach(chip => {
    chip.addEventListener('click', () => {
      const token = chip.getAttribute('data-token');
      if (!quill || !token) return;
      const range = quill.getSelection(true);
      quill.insertText(range.index, token);
      quill.setSelection(range.index + token.length, 0);
      renderPreview();
    });
  });

  function openComposerFor(actionKey) {
    if (votingLocked) {
      showToast(votingLockToast, false);
      return;
    }
    if (isLockedStatus(currentStatus) && actionKey !== 'reject') {
      showToast(lockedActionsMessage(currentStatus), false);
      return;
    }
    if (isRejectedStatus(currentStatus)) {
      showToast('Reopen this registration before changing its review status.', false);
      return;
    }
    if (!notifyModal) return;
    initQuill();

    const statusKey = actionKey === 'needs_info' ? 'needs_info' : 'rejected';

    setModalStatus(statusKey);
    loadTemplate(statusKey);
    document.querySelectorAll('[data-approve-only]').forEach((el) => {
      el.classList.add('d-none');
    });
    updateNotifyContactUI();
    notifyModal.show();

    updateStatusOnlyBtn.onclick = async (ev) => {
      ev.preventDefault(); ev.stopPropagation();
      await doAction(actionKey, { notify: false, btn: ev.currentTarget });
      notifyModal.hide();
    };

    sendAndUpdateBtn.onclick = async (ev) => {
      ev.preventDefault(); ev.stopPropagation();
      if (!getNomineeContact().hasValidEmail) {
        showToast('Business has no valid email on file. Use Update Status Only and contact by phone.', false);
        return;
      }
      const subj = notifySubject.value.trim();
      const html = applyPlaceholders(quill ? quill.root.innerHTML : '');
      await doAction(actionKey, { notify: true, subject: subj, message: html, btn: ev.currentTarget });
      notifyModal.hide();
    };
  }

  function confirmAction(options) {
    if (typeof window.adminConfirm === 'function') {
      return window.adminConfirm(options);
    }
    return Promise.resolve(window.confirm(options?.message || 'Are you sure?'));
  }

  function waitForModalHidden(el) {
    if (!el || !el.classList.contains('show')) return Promise.resolve();
    return new Promise((resolve) => {
      el.addEventListener('hidden.bs.modal', () => resolve(), { once: true });
    });
  }

  let ballotPreviewKind = 'qr';
  let ballotPreviewChoiceId = 0;
  let ballotPreviewTimer = null;
  let ballotPreviewResolve = null;

  function settleBallotPreview(result) {
    if (typeof ballotPreviewResolve !== 'function') return;
    const resolve = ballotPreviewResolve;
    ballotPreviewResolve = null;
    resolve(result);
  }

  async function fetchBallotEmailPreview(choiceId, kind, subject, message) {
    return fetchJSON(ENDPOINTS.releaseBallot, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'previewBallotEmail',
        choice_id: choiceId,
        kind,
        subject: subject || '',
        message: message || '',
      }),
      timeoutMs: 120000,
    });
  }

  function fillBallotEmailPreview(data, { htmlOnly = false } = {}) {
    const toEl = document.getElementById('ballotEmailTo');
    const subjEl = document.getElementById('ballotEmailSubject');
    const msgEl = document.getElementById('ballotEmailMessage');
    const msgWrap = document.getElementById('ballotEmailMessageWrap');
    const previewEl = document.getElementById('ballotEmailPreview');
    const alertEl = document.getElementById('ballotEmailPreviewAlert');
    const titleEl = document.getElementById('ballotEmailPreviewTitle');
    const sendBtn = document.getElementById('ballotEmailSendBtn');
    const isNotice = data.kind === 'notice';

    if (titleEl) titleEl.textContent = isNotice ? 'Preview evaluation notice' : 'Preview voting email';
    if (!htmlOnly) {
      if (toEl) toEl.value = data.to || '';
      if (subjEl) {
        subjEl.value = data.subject || '';
        subjEl.readOnly = isNotice;
      }
      if (msgWrap) msgWrap.classList.toggle('d-none', isNotice);
      if (msgEl && !isNotice) msgEl.value = data.message || '';
    }
    if (previewEl) previewEl.innerHTML = data.html || '';
    if (alertEl) {
      const warn = String(data.warning || '').trim();
      alertEl.textContent = warn;
      alertEl.classList.toggle('d-none', !warn);
    }
    if (sendBtn) {
      sendBtn.disabled = data.has_email === false;
      sendBtn.textContent = isNotice ? 'Send evaluation notice' : 'Send email & confirm';
    }
  }

  async function refreshBallotEmailPreview() {
    if (ballotPreviewKind !== 'qr' || !ballotPreviewChoiceId) return;
    const previewEl = document.getElementById('ballotEmailPreview');
    const subj = document.getElementById('ballotEmailSubject')?.value || '';
    const msg = document.getElementById('ballotEmailMessage')?.value || '';
    try {
      const data = await fetchBallotEmailPreview(ballotPreviewChoiceId, 'qr', subj, msg);
      if (previewEl) previewEl.innerHTML = data.html || '';
    } catch (err) {
      if (previewEl) previewEl.innerHTML = `<div class="p-3 text-danger">${esc(err.message || 'Could not refresh preview.')}</div>`;
    }
  }

  function scheduleBallotEmailPreviewRefresh() {
    clearTimeout(ballotPreviewTimer);
    ballotPreviewTimer = setTimeout(() => {
      refreshBallotEmailPreview();
    }, 400);
  }

  async function openBallotEmailPreview(choiceId, kind) {
    const modalEl = document.getElementById('ballotEmailPreviewModal');
    if (!modalEl) return { subject: '', message: '' };

    ballotPreviewKind = kind;
    ballotPreviewChoiceId = choiceId;
    const previewEl = document.getElementById('ballotEmailPreview');
    const sendBtn = document.getElementById('ballotEmailSendBtn');
    const titleEl = document.getElementById('ballotEmailPreviewTitle');
    if (titleEl) titleEl.textContent = kind === 'notice' ? 'Preview evaluation notice' : 'Preview voting email';
    if (previewEl) previewEl.innerHTML = '<div class="p-3 text-muted">Loading preview…</div>';
    if (sendBtn) sendBtn.disabled = true;

    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    let cancelled = false;
    const onHidden = () => { cancelled = true; };
    modalEl.addEventListener('hidden.bs.modal', onHidden, { once: true });
    modal.show();

    try {
      const data = await fetchBallotEmailPreview(choiceId, kind, '', '');
      if (cancelled) return null;
      modalEl.removeEventListener('hidden.bs.modal', onHidden);
      fillBallotEmailPreview(data);
    } catch (err) {
      modalEl.removeEventListener('hidden.bs.modal', onHidden);
      if (!cancelled) bootstrap.Modal.getInstance(modalEl)?.hide();
      throw err;
    }

    return new Promise((resolve) => {
      ballotPreviewResolve = resolve;
    });
  }

  (function bindBallotEmailPreviewModal() {
    const modalEl = document.getElementById('ballotEmailPreviewModal');
    if (!modalEl || modalEl.dataset.bound === '1') return;
    modalEl.dataset.bound = '1';
    const sendBtn = document.getElementById('ballotEmailSendBtn');
    const subjEl = document.getElementById('ballotEmailSubject');
    const msgEl = document.getElementById('ballotEmailMessage');

    sendBtn?.addEventListener('click', () => {
      settleBallotPreview({
        subject: (document.getElementById('ballotEmailSubject')?.value || '').trim(),
        message: (document.getElementById('ballotEmailMessage')?.value || '').trim(),
      });
      bootstrap.Modal.getInstance(modalEl)?.hide();
    });
    modalEl.addEventListener('hidden.bs.modal', () => settleBallotPreview(null));
    subjEl?.addEventListener('input', scheduleBallotEmailPreviewRefresh);
    msgEl?.addEventListener('input', scheduleBallotEmailPreviewRefresh);
  })();

  btnApprove?.addEventListener('click', async (e) => {
    e.preventDefault();
    e.stopPropagation();
    if (votingLocked) {
      showToast(votingLockToast, false);
      return;
    }
    if (isLockedStatus(currentStatus)) {
      showToast(lockedActionsMessage(currentStatus), false);
      return;
    }
    if (isRejectedStatus(currentStatus)) {
      showToast('Reopen this registration before proceeding to evaluation.', false);
      return;
    }
    const ok = await confirmAction({
      title: 'Proceed to evaluation',
      message: 'This creates the business record for TWG scoring. No email will be sent yet.',
      confirmLabel: 'Proceed to evaluation',
      confirmClass: 'btn-success',
    });
    if (!ok) return;
    doAction('approve', { notify: false, btn: btnApprove });
  });
  btnNeeds?.addEventListener('click',     (e) => { e.preventDefault(); e.stopPropagation(); openComposerFor('needs_info'); });
  btnReject?.addEventListener('click',    (e) => { e.preventDefault(); e.stopPropagation(); openComposerFor('reject'); });
  btnReopen?.addEventListener('click', async (e) => {
    e.preventDefault();
    e.stopPropagation();
    if (votingLocked) {
      showToast(votingLockToast, false);
      return;
    }
    if (!isRejectedStatus(currentStatus)) {
      showToast('Only a rejected registration can be reopened.', false);
      return;
    }
    const ok = await confirmAction({
      title: 'Reopen registration',
      message: 'This returns the registration to In Review so you can continue validation or proceed to evaluation. It does not email the business.',
      confirmLabel: 'Reopen',
      confirmClass: 'btn-primary',
    });
    if (!ok) return;
    doAction('reopen', { notify: false, btn: btnReopen });
  });
  document.getElementById('awardStandingFilter')?.addEventListener('change', () => {
    renderRemainingAwardTable();
  });

  function awardLabelsHtml(rows) {
    return (rows || []).map((row) => {
      const label = esc(row.label || [row.category_name, row.question_name].filter(Boolean).join(' · ') || 'Award');
      const names = Array.isArray(row.entry_names) ? row.entry_names.map((n) => String(n || '').trim()).filter(Boolean) : [];
      let extra = '';
      if (names.length) {
        const kind = String(row.entry_kind || '');
        const kindLabel = kind === 'artist' ? 'Artist' : (kind === 'stylist' ? 'Stylist' : 'Product');
        extra = `<br><span class="small text-muted">${esc(kindLabel)}: ${esc(names.join(', '))}</span>`;
      }
      return `• ${label}${extra}`;
    }).join('<br>');
  }

  btnReleaseBallot?.addEventListener('click', async (e) => {
    e.preventDefault();
    e.stopPropagation();
    const choiceId = Number(currentChoiceId || 0);
    if (!choiceId) {
      showToast('This registration is not linked to a business record yet.', false);
      return;
    }
    const elig = currentBallotEligibility;
    if (!elig?.all_graded) {
      showToast('Finish TWG scoring for every remaining award title before confirming for public voting.', false);
      return;
    }

    if (elig.none_in_top10) {
      const listed = awardLabelsHtml(elig.not_top10 || elig.awards);
      const ok = await confirmAction({
        title: 'Evaluation notice',
        html: `None of this business’s remaining Food or Service titles placed in the TWG Top 5, so they will not appear on the public ballot.<br><br>Email them that they were evaluated for:<br>${listed}`,
        confirmLabel: 'Preview evaluation notice',
        confirmClass: 'btn-warning',
      });
      if (!ok) return;
      await waitForModalHidden(document.getElementById('adminConfirmModal'));
      let preview;
      try {
        preview = await openBallotEmailPreview(choiceId, 'notice');
      } catch (err) {
        showToast(err.message || 'Could not load the email preview.', false);
        return;
      }
      if (!preview) return;
      const stopSpin = spinButton(btnReleaseBallot, 'Sending…');
      try {
        const data = await fetchJSON(ENDPOINTS.releaseBallot, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'notifyNotAdvanced', choice_id: choiceId }),
          timeoutMs: 120000,
          emailSend: true,
        });
        showToast(data.message || 'Evaluation notice emailed. This business was not added to the public ballot.');
      } catch (err) {
        showToast(err.message || 'Could not send the evaluation notice.', false);
      } finally {
        stopSpin();
        setBallotStageUI();
      }
      return;
    }

    const topHtml = awardLabelsHtml(elig.top10);
    const otherHtml = awardLabelsHtml(elig.not_top10);
    const ungradedNote = (elig.awards || []).some((row) => Number(row.ungraded_peers) > 0)
      ? '<br><br>Some other businesses in these awards are not fully graded yet. Top 5 (Food and Service) is based on currently graded TWG scores.'
      : '';
    const otherBlock = otherHtml
      ? `<br><br>Evaluated but not in the Top 5 (will not appear for public voting):<br>${otherHtml}`
      : '';
    const ok = await confirmAction({
      title: 'Confirm for public voting',
      html: `Food and Service titles in the TWG Top 5, plus fully graded Feelings titles, will be added to the public ballot. This emails the QR code and voting link.<br><br>On the public ballot:<br>${topHtml}${otherBlock}${ungradedNote}`,
      confirmLabel: 'Preview email',
      confirmClass: 'btn-primary',
    });
    if (!ok) return;
    await waitForModalHidden(document.getElementById('adminConfirmModal'));
    let preview;
    try {
      preview = await openBallotEmailPreview(choiceId, 'qr');
    } catch (err) {
      showToast(err.message || 'Could not load the email preview.', false);
      return;
    }
    if (!preview) return;
    const stopSpin = spinButton(btnReleaseBallot, 'Confirming…');
    try {
      const data = await fetchJSON(ENDPOINTS.releaseBallot, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'releaseToBallot',
          choice_id: choiceId,
          subject: preview.subject || '',
          message: preview.message || '',
        }),
        timeoutMs: 120000,
        emailSend: true,
      });
      currentOnBallot = true;
      if (data.eligibility) currentBallotEligibility = data.eligibility;
      setBallotStageUI();
      showToast(data.message || 'This business is now on the public ballot.');
      loadProfile();
    } catch (err) {
      showToast(err.message || 'Could not confirm this business for public voting.', false);
      try { await loadProfile(); } catch (_) {}
    } finally {
      stopSpin();
      setBallotStageUI();
    }
  });

  btnStartReview?.addEventListener('click', async (e) => {
    e.preventDefault();
    e.stopPropagation();
    if (isLockedStatus(currentStatus)) {
      showToast(lockedActionsMessage(currentStatus, 'review'), false);
      return;
    }
    if (votingLocked) {
      showToast(votingLockToast, false);
      return;
    }
    await setInReview({ silent: false });
  });

  validateButtons.forEach(b => {
    b?.addEventListener('click', async (e) => {
      e.preventDefault();
      if (votingLocked) {
        e.stopImmediatePropagation();
        showToast(votingLockToast, false);
        return;
      }
      if (String(currentStatus || '').toLowerCase() === 'rejected') {
        e.stopImmediatePropagation();
        showToast(lockedActionsMessage(currentStatus, 'validation'), false);
        return;
      }
      // After approve, TWG still uses Validate to remove titles. Do not start review again.
      if (!isLockedStatus(currentStatus)) {
        await setInReview({ silent: false });
      }
    });
  });

  // ------- Inline file preview (Mayor's permit, logos in details, etc.) -------
  const inlineFileModalEl = document.getElementById('inlineFilePreviewModal');
  const inlineFileModal   = inlineFileModalEl ? new bootstrap.Modal(inlineFileModalEl) : null;
  const inlineFileStage   = document.getElementById('inlineFilePreviewStage');
  const inlineFileTitle   = document.getElementById('inlineFilePreviewModalLabel');
  const inlineFileOpen    = document.getElementById('inlineFilePreviewOpen');

  function openInlineFilePreview(url, label) {
    if (!inlineFileModal || !inlineFileStage || !url) return;
    const safeLabel = label || 'Document';
    if (inlineFileTitle) inlineFileTitle.textContent = safeLabel;
    inlineFileStage.innerHTML = '';
    const img = document.createElement('img');
    img.src = url;
    img.alt = safeLabel;
    inlineFileStage.appendChild(img);
    if (inlineFileOpen) {
      inlineFileOpen.href = url;
      inlineFileOpen.classList.remove('d-none');
    }
    inlineFileModal.show();
  }

  document.addEventListener('click', (e) => {
    const btn = e.target.closest('.nom-inline-file-btn');
    if (!btn) return;
    if (btn.classList.contains('is-missing')) return;
    e.preventDefault();
    openInlineFilePreview(btn.dataset.previewUrl || '', btn.dataset.previewLabel || 'Document');
  });
  document.addEventListener('error', (e) => {
    const img = e.target;
    if (!(img instanceof HTMLImageElement) || !img.classList.contains('nom-inline-file')) return;
    const btn = img.closest('.nom-inline-file-btn');
    if (!btn) return;
    btn.classList.add('is-missing');
    img.classList.add('d-none');
    btn.querySelector('.nom-inline-file-missing')?.classList.remove('d-none');
  }, true);

  // ------- Submitted photos & videos lightbox -------
  (function initSubmittedMediaLightbox() {
    const modalEl = document.getElementById('submittedMediaModal');
    const grid    = document.getElementById('submittedMediaGrid');
    if (!modalEl || !grid) return;

    const modal     = new bootstrap.Modal(modalEl);
    const stage     = document.getElementById('submittedMediaStage');
    const captionEl = document.getElementById('submittedMediaCaption');
    const positionEl = document.getElementById('submittedMediaPosition');
    const btnPrev   = document.getElementById('submittedMediaPrev');
    const btnNext   = document.getElementById('submittedMediaNext');
    const tiles     = Array.from(grid.querySelectorAll('.submitted-media-tile'));
    if (!tiles.length || !stage) return;

    let currentIndex = 0;

    function showAt(index) {
      const tile = tiles[index];
      if (!tile) return;
      currentIndex = index;
      const url  = tile.dataset.url || '';
      const kind = tile.dataset.kind || 'image';
      const cap  = tile.dataset.caption || '';
      stage.innerHTML = '';
      if (kind === 'video') {
        const video = document.createElement('video');
        video.src = url;
        video.controls = true;
        video.playsInline = true;
        video.className = 'w-100';
        stage.appendChild(video);
      } else {
        const img = document.createElement('img');
        img.src = url;
        img.alt = cap || 'Submitted photo';
        stage.appendChild(img);
      }
      if (captionEl) captionEl.textContent = cap;
      if (positionEl) positionEl.textContent = `${index + 1} of ${tiles.length}`;
      if (btnPrev) btnPrev.disabled = index <= 0;
      if (btnNext) btnNext.disabled = index >= tiles.length - 1;
    }

    grid.addEventListener('click', (e) => {
      const tile = e.target.closest('.submitted-media-tile');
      if (!tile) return;
      const idx = parseInt(tile.dataset.index, 10);
      if (Number.isNaN(idx)) return;
      showAt(idx);
      modal.show();
    });

    btnPrev?.addEventListener('click', () => { if (currentIndex > 0) showAt(currentIndex - 1); });
    btnNext?.addEventListener('click', () => { if (currentIndex < tiles.length - 1) showAt(currentIndex + 1); });
    modalEl.addEventListener('hidden.bs.modal', () => {
      const vid = stage.querySelector('video');
      if (vid) { try { vid.pause(); } catch (_) { /* ignore */ } }
      stage.innerHTML = '';
    });
  })();

  // ---- Initial load ----

  loadProfile();
})();
