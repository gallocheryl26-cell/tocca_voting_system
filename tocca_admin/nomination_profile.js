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

  // Templates
  const TEMPLATES = {
    approved: {
      subject: 'Your registration has been approved',
      body: `
        <p>Great news! Your registration for {category_list} in {event_name} has been <strong>approved</strong>.</p>
        <p>Share the voting button or QR code below with your customers so they can vote for your business.</p>
      `
    },
    needs_info: {
      subject: 'We need a bit more information',
      body: `
        <p>Thanks for your registration for {category_list} in {event_name}. Before we proceed, we need a bit more information.</p>
        <p>Please reply with the requested details. Thank you!</p>
      `
    },
    rejected: {
      subject: 'Update on your registration',
      body: `
        <p>We appreciate your registration for {category_list} in {event_name}. After review, we&rsquo;re unable to proceed at this time.</p>
        <p>If you believe this is in error or need clarification, contact us at {support_email}.</p>
      `
    }
  };

  let currentNomination = null;
  let currentCategoryIds = [];
  let currentCategories  = [];
  let currentStatus = null;
  let currentAnswers = []; // NEW

  // NEW: hold the establishment type id so we can send it on approve
  let establishmentTypeId = null;

  // Status helpers
  function formatStatusLabel(key){
    const map = { pending:'Pending', in_review:'In Review', needs_info:'Needs Info', approved:'Approved', rejected:'Rejected', merged:'Merged' };
    return map[key] || String(key || '').replace(/_/g,' ').replace(/\b\w/g, c => c.toUpperCase());
  }
  function statusTone(key){
    const map = { pending:'warning', in_review:'info', needs_info:'secondary', approved:'success', rejected:'danger', merged:'info' };
    return map[key] || 'secondary';
  }
  function isLockedStatus(s){ return ['approved','rejected','merged'].includes(String(s || '').toLowerCase()); }
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
    const { timeoutMs = 20000, ...fetchOpts } = options;
    const ctrl = new AbortController();
    const t = setTimeout(() => ctrl.abort(), timeoutMs);
    let res, text;
    try {
      res = await fetch(url, { ...fetchOpts, signal: ctrl.signal, headers: { Accept: 'application/json', ...(fetchOpts?.headers || {}) } });
      text = await res.text();
    } catch (e) {
      if (e?.name === 'AbortError') {
        throw new Error('Request timed out. Please wait a moment and try again.');
      }
      throw e;
    } finally { clearTimeout(t); }
    let data;
    try { data = JSON.parse((text || '').trim()); }
    catch { throw new Error(`Non-JSON response from ${url} (HTTP ${res?.status ?? 'n/a'})`); }
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
      timeoutMs: sendEmail ? 90000 : 20000
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
      ensureGroup(row).awards.push({
        question_id: row.question_id ?? null,
        label: awardLabel,
        description: row.description ?? '',
        type: row.type ?? '',
        removed: false,
        reason_label: ''
      });
    });
    (removedList || []).forEach(row => {
      const awardLabel =
        row.award_name || row.question_name || row.name || (row.question_id ? `#${row.question_id}` : '');
      if (!awardLabel) return;
      ensureGroup(row).awards.push({
        question_id: row.question_id ?? null,
        label: awardLabel,
        description: '',
        type: '',
        removed: true,
        reason_label: row.reason_label || row.reason || ''
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
          category: cat.category_name || '',
          reason: a.reason_label || ''
        });
      });
    });
    return rows;
  }
  function fillAwardTable(tbody, rows, columns, emptyText) {
    if (!tbody) return;
    if (!rows.length) {
      tbody.innerHTML = `<tr><td colspan="${columns}" class="text-muted">${esc(emptyText)}</td></tr>`;
      return;
    }
    tbody.innerHTML = rows.map(r => {
      const qid = esc(r.question_id);
      let html = `<tr data-question-id="${qid}"><td>${esc(r.label)}</td><td>${esc(r.category || '—')}</td>`;
      if (columns === 3) html += `<td>${esc(r.reason || '—')}</td>`;
      return html + '</tr>';
    }).join('');
  }
  function renderCategoryTabs(catsFlat, catIdsFallback, removedList) {
    if (!catWrap || !approvedAwardsBody || !removedAwardsBody) return false;
    catLoader?.classList.remove('d-none');

    const grouped = groupCategories(catsFlat, removedList);
    const approvedRows = flattenAwardRows(grouped, false);
    const removedRows = flattenAwardRows(grouped, true);

    if (!grouped.length) {
      const empty = Array.isArray(catIdsFallback) && catIdsFallback.length
        ? `No award names returned for categories: ${catIdsFallback.map(id => `#${id}`).join(', ')}.`
        : 'No categories found.';
      fillAwardTable(approvedAwardsBody, [], 2, empty);
      fillAwardTable(removedAwardsBody, [], 3, 'No awards have been removed.');
      catLoader?.classList.add('d-none');
      return true;
    }

    fillAwardTable(approvedAwardsBody, approvedRows, 2, 'No remaining awards on this registration.');
    fillAwardTable(removedAwardsBody, removedRows, 3, 'No awards have been removed.');
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
    const locked = lockedByStatus || votingLocked;
    [btnApprove, btnNeeds, btnReject, ...validateButtons].forEach(b => {
      if (!b) return;
      b.disabled = locked;
      if (locked) {
        b.classList.add('disabled');
        b.setAttribute('aria-disabled', 'true');
        b.style.pointerEvents = 'none';
        b.tabIndex = -1;
      } else {
        b.classList.remove('disabled');
        b.removeAttribute('aria-disabled');
        b.style.pointerEvents = '';
        b.tabIndex = 0;
      }
    });
    if (mergeChoiceId) mergeChoiceId.disabled = locked;

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
    if (locked) {
      reason = lockedByStatus
        ? `Actions are disabled because this registration is already ${formatStatusLabel(status).toLowerCase()}.`
        : 'Actions are disabled while the voting period is in progress.';
    }
    hint.textContent = reason;
    hint.classList.toggle('d-none', !locked);
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
      const data = await fetchJSON(ENDPOINTS.getNomination(id), { cache:'no-store' });

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

      currentNomination   = Object.assign({}, r, { business_name: businessDisplay });
      currentCategoryIds  = catIds;
      currentCategories   = cats;
      currentStatus       = r.status || 'pending';

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
    if (isLockedStatus(currentStatus)) {
      showToast(lockedActionsMessage(currentStatus), false);
      return;
    }
    if (votingLocked) {
      showToast(votingLockToast, false);
      return;
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
      }

      await fetchJSON(ENDPOINTS.approveMerge, { method: 'POST', body: fd });

      if (actionKey === 'approve') {
        currentStatus = 'approved';
        setActionsState(currentStatus);
      }

      if (notify) {
        if (actionKey === 'approve') {
          if (btn) btn.innerHTML = `<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Preparing QR…`;
          await wait(1500);
        }
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
      const newLabel = formatStatusLabel(currentStatus);
      let baseMsg = (currentStatus !== prevStatus)
        ? `Status updated to ${newLabel}.`
        : `Status is already ${newLabel}.`;
      if (notify) baseMsg += '';
      showToast(baseMsg, true);

    } catch (e) {
      showToast(e.message || 'Action failed', false);
    } finally {
      stopSpin();
    }
  }

  function setModalStatus(statusKey) {
    const map = { approved:'Approved', needs_info:'Needs Info', rejected:'Rejected' };
    if (notifyModalStatusLabel) {
      notifyModalStatusLabel.textContent = map[statusKey] || 'Approved';
    }
  }
  function loadTemplate(statusKey) {
    const tpl = TEMPLATES[statusKey] || TEMPLATES.approved;
    notifySubject.value = tpl.subject;
    if (!quill) initQuill();
    quill.root.innerHTML = tpl.body.trim();
    renderPreview();
  }
  function applyPlaceholders(html, { preview = false } = {}) {
    const rec = currentNomination || {};
    let categoryLabels = [];
    if (currentCategories?.length) {
      categoryLabels = currentCategories.map(cat =>
        (cat.category_name ? `${cat.category_name}: ` : '') +
        (cat.award_name || cat.question_name || cat.name || `#${cat.question_id ?? cat.category_id ?? '?'}`)
      );
    } else if (currentCategoryIds?.length) {
      categoryLabels = currentCategoryIds.map(id => `#${id}`);
    }
    const categoryList = categoryLabels.length ? categoryLabels.join(', ') : 'your selected category';
    const eventName    = 'Tatak Ormoc Consumers’ Choice Awards';
    const supportEmail = 'support@tatakormoc.com';

    let out = html
      .replaceAll('{business_name}', sanitize(rec.business_name || 'Valued Business'))
      .replaceAll('{owner_name}', sanitize(rec.owner_name || (findByAliases(currentAnswers, NAME_ALIASES.owner_name)?.answer || '')))
      .replaceAll('{category_list}', sanitize(categoryList))
      .replaceAll('{event_name}', sanitize(eventName))
      .replaceAll('{support_email}', sanitize(supportEmail));
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
    if (isLockedStatus(currentStatus)) {
      showToast(lockedActionsMessage(currentStatus), false);
      return;
    }
    if (!notifyModal) return;
    initQuill();

    const statusKey = actionKey === 'approve' ? 'approved'
                     : actionKey === 'needs_info' ? 'needs_info'
                     : 'rejected';

    setModalStatus(statusKey);
    loadTemplate(statusKey);
    document.querySelectorAll('[data-approve-only]').forEach((el) => {
      el.classList.toggle('d-none', statusKey !== 'approved');
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

  btnApprove?.addEventListener('click',   (e) => { e.preventDefault(); e.stopPropagation(); openComposerFor('approve'); });
  btnNeeds?.addEventListener('click',     (e) => { e.preventDefault(); e.stopPropagation(); openComposerFor('needs_info'); });
  btnReject?.addEventListener('click',    (e) => { e.preventDefault(); e.stopPropagation(); openComposerFor('reject'); });

  validateButtons.forEach(b => {
    b?.addEventListener('click', async (e) => {
      e.preventDefault(); e.stopPropagation();
      if (isLockedStatus(currentStatus)) {
        showToast(lockedActionsMessage(currentStatus, 'validation'), false);
        return;
      }
      if (votingLocked) {
        showToast(votingLockToast, false);
        return;
      }
      await setInReview({ silent: false });
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

  loadProfile().then(async () => {
    const s = String(currentStatus || '').toLowerCase();
    if (!votingLocked && ['', 'pending', 'submitted', 'new'].includes(s)) {
      await setInReview({ silent: true });
    }
  });
})();
