document.addEventListener('DOMContentLoaded', () => {
  const form          = document.getElementById('trackForm');
  const refInput      = document.getElementById('referenceInput');
  const trackBtn      = document.getElementById('trackBtn');
  const errorMsg      = document.getElementById('errorMsg');
  const card          = document.getElementById('resultCard');
  const nameEl        = document.getElementById('businessName');
  const refBadge      = document.getElementById('referenceBadge');
  const refNoText     = document.getElementById('referenceNoText');
  const steps         = document.querySelectorAll('#trackerSteps li');
  const statusEl      = document.getElementById('currentStatus');
  const logoBox       = document.getElementById('logoBox');
  const categoryList  = document.getElementById('categoryList');
  const summaryFields = document.getElementById('summaryFields');
  const extraFields   = document.getElementById('extraFields');
  const extraSection  = document.getElementById('extraSection');
  const extraCountEl  = document.getElementById('extraCount');
  const insightsEl    = document.getElementById('trackerInsights');
  const statusHelpEl  = document.getElementById('statusHelpText');
  const categoriesMetaCountEl = document.getElementById('categoriesMetaCount');

  const editActions = document.getElementById('editActions');
  const editRegistrationBtn = document.getElementById('editRegistrationBtn');

  const TITLE_NAME_SLUGS  = ['official_business_name', 'business_name', 'name_of_business', 'company_name'];
  const TITLE_NAME_LABELS = ['official.*business.*name', '^business\\s*name$', 'company\\s*name'];

  function isLogoField(f) {
    const slug  = String(f?.name  || '').toLowerCase();
    const label = String(f?.label || '').toLowerCase();
    const role  = String(f?.profile_role || '').toLowerCase();
    return role === 'logo' || /logo/.test(slug) || /logo/.test(label);
  }

  function fieldKey(f) {
    return String(f?.field_id || '') + '::' + String(f?.name || '').toLowerCase();
  }

  function statusInfo(s) {
    s = (s || '').toLowerCase();
    const map = {
      approved   : { text: 'Approved',          badge: 'status-approved' },
      merged     : { text: 'Merged',            badge: 'status-approved' },
      rejected   : { text: 'Rejected',          badge: 'status-rejected' },
      in_review  : { text: 'In Review',         badge: 'status-review' },
      needs_info : { text: 'Needs Information', badge: 'status-neutral' },
      pending    : { text: 'Pending Review',    badge: 'status-pending' },
      submitted  : { text: 'Submitted',         badge: 'status-pending' },
    };
    return map[s] || { text: s || 'Pending Review', badge: 'status-neutral' };
  }

  function statusHelpMessage(statusKey, canEdit) {
    const key = (statusKey || '').toLowerCase();
    if (canEdit) {
      if (key === 'needs_info') {
        return 'Additional information was requested. Use Edit registration to update your details, then save again.';
      }
      return 'Need to correct something? Use Edit registration to update your details while review is still open.';
    }
    switch (key) {
      case 'approved':
      case 'merged':
        return 'Your registration is approved. It will proceed to the voting stage for eligible awards.';
      case 'rejected':
        return 'Your registration was not approved. You may submit a new registration if the registration period is still open.';
      case 'in_review':
        return 'Your registration is under review by the committee. Editing is locked until a decision is made.';
      default:
        return 'Your registration is being processed. Editing is no longer available for this status.';
    }
  }

  function statusStep(s) {
    s = (s || '').toLowerCase();
    if (['approved', 'merged', 'rejected'].includes(s)) return 3;
    if (['in_review', 'needs_info'].includes(s)) return 2;
    return 1;
  }

  function findByName(names, fields) {
    const set = new Set((names || []).map(s => String(s).toLowerCase()));
    const row = (fields || []).find(f => set.has(String(f.name || '').toLowerCase()) && String(f.value || '').trim() !== '');
    return row || null;
  }

  function findByLabel(hints, fields) {
    if (!hints || !hints.length) return null;
    const re = new RegExp(hints.join('|'), 'i');
    const row = (fields || []).find(f => re.test(String(f.label || '')) && String(f.value || '').trim() !== '');
    return row || null;
  }

  function findByRole(role, fields) {
    const key = String(role || '').toLowerCase();
    if (!key) return null;
    return (fields || []).find(f => String(f.profile_role || '').toLowerCase() === key && String(f.value || '').trim() !== '') || null;
  }

  function findFieldValue(names, labelHints, fields, role) {
    return findByRole(role, fields)
      || findByName(names, fields)
      || findByLabel(labelHints, fields);
  }

  function isLikelyUrl(str) {
    return /^https?:\/\//i.test(str) || str.startsWith('/') || str.startsWith('./') || str.startsWith('../');
  }

  function isFileField(f) {
    return String(f?.type || '').toLowerCase() === 'file';
  }

  function fileNameFromPath(path) {
    const clean = String(path || '').split('?')[0];
    const base  = clean.split('/').pop() || clean;
    return base || 'document';
  }

  function stripLabel(label) {
    return String(label || '').replace(/\*/g, '').trim() || 'Document';
  }

  function renderValue(value, field) {
    const wrap = document.createElement('div');
    wrap.className = 'kv-v';
    if (!value) {
      wrap.textContent = '—';
      wrap.classList.add('text-muted');
      return wrap;
    }
    const raw = String(value);
    const isMayor = /mayor.*permit/i.test(`${field?.label || ''} ${field?.name || ''}`)
      || String(field?.profile_role || '') === 'mayor_permit';
    const isImage = /\.(png|jpe?g|webp|gif|svg)$/i.test(raw)
      || /uploads?\/nominations\/.+\.(png|jpe?g|webp|gif)$/i.test(raw);

    if ((isFileField(field) || isLikelyUrl(value) || isMayor) && isImage) {
      const a = document.createElement('a');
      a.href = value;
      a.target = '_blank';
      a.rel = 'noopener noreferrer';
      a.className = 'doc-link doc-link--image';
      a.title = fileNameFromPath(value);
      const img = document.createElement('img');
      img.src = value;
      img.alt = stripLabel(field?.label || "Mayor's Permit");
      img.className = 'doc-thumb';
      a.appendChild(img);
      const caption = document.createElement('span');
      caption.textContent = 'View photo';
      a.appendChild(caption);
      wrap.appendChild(a);
      return wrap;
    }

    if (isFileField(field) || isLikelyUrl(value)) {
      const a = document.createElement('a');
      a.href = value;
      a.target = '_blank';
      a.rel = 'noopener noreferrer';
      a.className = 'doc-link';
      a.innerHTML = '<i class="bi bi-file-earmark-arrow-down" aria-hidden="true"></i><span>View document</span>';
      a.title = fileNameFromPath(value);
      wrap.appendChild(a);
      return wrap;
    }

    if (isMayor && !isLikelyUrl(value) && !isImage) {
      wrap.textContent = value;
      const badge = document.createElement('span');
      badge.className = 'badge text-bg-light border ms-1';
      badge.textContent = 'Legacy text';
      wrap.appendChild(badge);
      return wrap;
    }

    if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
      const a = document.createElement('a');
      a.href = `mailto:${value}`;
      a.textContent = value;
      wrap.appendChild(a);
      return wrap;
    }
    if (/^https?:\/\//i.test(value)) {
      const a = document.createElement('a');
      a.href = value;
      a.target = '_blank';
      a.rel = 'noopener noreferrer';
      a.textContent = value;
      wrap.appendChild(a);
      return wrap;
    }
    wrap.textContent = value;
    return wrap;
  }

  function appendRow(container, label, value, field) {
    if (value == null || String(value).trim() === '') return;
    const k = document.createElement('div');
    k.className = 'kv-k';
    k.textContent = label;
    const v = renderValue(value, field || { type: isLikelyUrl(value) ? 'file' : 'text' });
    container.appendChild(k);
    container.appendChild(v);
  }

  function setLoading(loading) {
    if (!trackBtn) return;
    trackBtn.disabled = loading;
    trackBtn.innerHTML = loading
      ? '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Tracking…'
      : '<i class="bi bi-search me-1" aria-hidden="true"></i>Track';
  }

  /** Render all answered form fields dynamically (form schema driven). */
  function buildDetails(nom, fields) {
    summaryFields.innerHTML = '';
    extraFields.innerHTML = '';
    const list = Array.isArray(fields) ? fields : [];
    const used = new Set();

    const featuredSpecs = [
      {
        label: 'Owner / President / General Manager',
        names: ['owner_president_general_manager', 'owner_president_gm', 'owner_name', 'owner'],
        labels: ['owner', 'president', 'general\\s*manager'],
        role: 'owner_name',
        featuredValue: nom.owner_name,
      },
      {
        label: 'Mobile Number',
        names: ['mobile_number', 'mobile', 'phone', 'contact_number'],
        labels: ['mobile', 'phone', 'contact\\s*number'],
        role: 'mobile',
        featuredValue: nom.mobile_number,
      },
      {
        label: 'Designation in the Business / Company',
        names: ['designation'],
        labels: ['designation'],
        role: null,
        featuredValue: nom.designation,
      },
      {
        label: "Mayor's Permit",
        names: ['mayor_s_permit_number', 'mayors_permit_number', 'mayor_s_permit', 'mayors_permit', 'mayor_permit'],
        labels: ['mayor.*permit'],
        role: 'mayor_permit',
        featuredValue: nom.mayor_permit,
      },
      {
        label: 'Email',
        names: ['email_address', 'email', 'contact_email'],
        labels: ['\\bemail\\b'],
        role: 'email',
        featuredValue: nom.email,
      },
      {
        label: 'Business / Company Address',
        names: ['business_address', 'business_company_address', 'full_address'],
        labels: ['business.*address', 'company\\s*address'],
        role: 'address',
        featuredValue: nom.address,
      },
      {
        label: 'Website',
        names: ['website'],
        labels: ['website', 'facebook', 'instagram'],
        role: 'website',
        featuredValue: nom.website,
      },
    ];

    featuredSpecs.forEach((spec) => {
      const matched = findFieldValue(spec.names, spec.labels, list, spec.role);
      const value = (matched && matched.value) || spec.featuredValue || '';
      if (!String(value).trim()) return;
      const field = matched || {
        type: isLikelyUrl(value) || /\.(png|jpe?g|webp|gif)$/i.test(String(value)) ? 'file' : 'text',
        label: spec.label,
        name: (spec.names && spec.names[0]) || '',
        profile_role: spec.role || 'custom',
        value,
      };
      appendRow(summaryFields, spec.label, value, field);
      if (matched) used.add(fieldKey(matched));
    });

    const remaining = list.filter((f) => {
      if (!f || String(f.value || '').trim() === '') return false;
      if (isLogoField(f)) return false;
      if (used.has(fieldKey(f))) return false;
      // Skip business name — already shown as page title
      const role = String(f.profile_role || '').toLowerCase();
      const slug = String(f.name || '').toLowerCase();
      const label = String(f.label || '').toLowerCase();
      if (role === 'business_name') return false;
      if (TITLE_NAME_SLUGS.includes(slug)) return false;
      if (/official.*business.*name|^business\s*name$|company\s*name/.test(label)) return false;
      return true;
    });

    if (!remaining.length) {
      if (extraSection) extraSection.style.display = 'none';
      if (extraCountEl) extraCountEl.textContent = '';
      if (!summaryFields.children.length) {
        summaryFields.innerHTML = '<div class="text-muted">No registration details were found for this reference.</div>';
      }
      return;
    }

    if (extraSection) extraSection.style.display = '';
    if (extraCountEl) {
      extraCountEl.textContent = `(${remaining.length} field${remaining.length === 1 ? '' : 's'})`;
    }
    remaining.forEach((f) => {
      appendRow(extraFields, stripLabel(f.label || f.name || 'Field'), f.value, f);
    });
  }

  const statusActions = document.querySelector('.track-status-actions');

  function syncEditActions(nom) {
    if (!editActions || !editRegistrationBtn) return;
    const canEdit = !!nom.can_edit;
    editActions.classList.toggle('d-none', !canEdit);
    statusActions?.classList.toggle('has-edit', canEdit);
    if (!canEdit) return;
    const ref = encodeURIComponent(nom.reference_no || refInput?.value || '');
    const params = new URLSearchParams(window.location.search);
    const eventId = params.get('event_id');
    let hrefPath = `nomination_edit.php?ref=${ref}`;
    if (eventId) hrefPath += `&event_id=${encodeURIComponent(eventId)}`;
    const editBaseRaw = (typeof window.TOCCA_NOMINATION_BASE === 'string' && window.TOCCA_NOMINATION_BASE.trim())
      ? window.TOCCA_NOMINATION_BASE.trim()
      : (document.baseURI || window.location.href);
    const editBase = new URL(editBaseRaw, window.location.origin).href;
    editRegistrationBtn.href = new URL(hrefPath, editBase).href;
  }

  refInput?.addEventListener('input', () => {
    const pos = refInput.selectionStart;
    refInput.value = refInput.value.toUpperCase();
    if (typeof pos === 'number') {
      refInput.setSelectionRange(pos, pos);
    }
  });

  form.addEventListener('submit', e => {
    e.preventDefault();
    const ref = refInput.value.trim().toUpperCase();
    refInput.value = ref;
    if (!ref) return;

    errorMsg.style.display = 'none';
    card.style.display = 'none';
    setLoading(true);

    const urlBaseRaw = (typeof window.TOCCA_NOMINATION_BASE === 'string' && window.TOCCA_NOMINATION_BASE.trim())
      ? window.TOCCA_NOMINATION_BASE.trim()
      : (document.baseURI || window.location.href);
    const urlBase = new URL(urlBaseRaw, window.location.origin).href;
    const url = new URL('nomination_tracking.php', urlBase);
    url.searchParams.set('ajax', '1');
    url.searchParams.set('ref', ref);

    // Short in-tab cache so repeat Track of the same reference doesn't spam the server.
    const TRACK_CACHE_MS = 60000;
    if (!window.__toccaTrackCache) window.__toccaTrackCache = new Map();
    const cacheKey = ref;
    const cached = window.__toccaTrackCache.get(cacheKey);
    if (cached && (Date.now() - cached.at) < TRACK_CACHE_MS) {
      Promise.resolve(cached.payload)
        .then(handleTrackPayload)
        .finally(() => setLoading(false));
      return;
    }

    if (window.__toccaTrackInflight && window.__toccaTrackInflight.key === cacheKey) {
      window.__toccaTrackInflight.promise
        .then(handleTrackPayload)
        .finally(() => setLoading(false));
      return;
    }

    const req = fetch(url.href)
      .then(r => r.json())
      .then(payload => {
        if (payload && payload.status === 'success') {
          window.__toccaTrackCache.set(cacheKey, { at: Date.now(), payload });
        }
        return payload;
      });

    window.__toccaTrackInflight = { key: cacheKey, promise: req };
    req
      .then(handleTrackPayload)
      .catch(() => {
        errorMsg.textContent = 'Failed to fetch status. Please try again.';
        errorMsg.style.display = '';
        errorMsg.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      })
      .finally(() => {
        if (window.__toccaTrackInflight && window.__toccaTrackInflight.key === cacheKey) {
          window.__toccaTrackInflight = null;
        }
        setLoading(false);
      });
  });

  function handleTrackPayload(payload) {
    if (payload.status !== 'success') {
      errorMsg.textContent = payload.message || 'Reference number not found.';
      errorMsg.style.display = '';
      errorMsg.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      return;
    }

    const nom    = payload.data || {};
    const fields = nom.fields || [];
    const step   = statusStep(nom.status);
    const ref    = nom.reference_no || refInput.value.trim().toUpperCase();

    const titleName =
      nom.business_name ||
      findByName(TITLE_NAME_SLUGS, fields)?.value ||
      findByLabel(TITLE_NAME_LABELS, fields)?.value ||
      '—';
    nameEl.textContent = titleName;

    if (refNoText) refNoText.textContent = ref;
    if (refBadge) refBadge.style.display = '';

    const statusKey = (nom.status || '').toLowerCase();
    const isTerminalSuccess = ['approved', 'merged'].includes(statusKey);
    const isRejected = statusKey === 'rejected';

    steps.forEach((li, idx) => {
      li.classList.remove('completed', 'active', 'rejected');
      const circle = li.querySelector('.step-circle');

      if (isTerminalSuccess) {
        li.classList.add('completed');
      } else if (isRejected) {
        if (idx < steps.length - 1) li.classList.add('completed');
      } else {
        if (idx < step - 1) li.classList.add('completed');
        else if (idx === step - 1) li.classList.add('active');
      }

      const isCurrent =
        (!isTerminalSuccess && !isRejected && idx === step - 1) ||
        (isRejected && idx === steps.length - 1);
      if (circle) circle.setAttribute('aria-current', isCurrent ? 'step' : 'false');
    });

    const finalStep = steps[2];
    const finalIcon = finalStep?.querySelector('.step-circle i');
    const finalLabelFull = finalStep?.querySelector('.step-label-full');
    const finalLabelShort = finalStep?.querySelector('.step-label-short');
    if (isRejected) {
      finalStep.classList.add('completed', 'rejected');
      if (finalIcon) finalIcon.className = 'bi bi-x-lg';
      if (finalLabelFull) finalLabelFull.textContent = 'Rejected';
      if (finalLabelShort) finalLabelShort.textContent = 'Rejected';
    } else {
      finalStep.classList.remove('rejected');
      if (isTerminalSuccess && finalIcon) {
        finalIcon.className = 'bi bi-check-lg';
      } else if (finalIcon) {
        finalIcon.className = 'bi bi-flag-fill';
      }
      if (finalLabelFull) finalLabelFull.textContent = 'Completed';
      if (finalLabelShort) finalLabelShort.textContent = 'Done';
    }

    const info = statusInfo(nom.status);
    statusEl.textContent = info.text;
    statusEl.className = `status-badge ${info.badge}`;
    if (statusHelpEl) {
      statusHelpEl.textContent = statusHelpMessage(statusKey, !!nom.can_edit);
    }

    logoBox.innerHTML = '';
    let logoSrc =
      nom.logo_path ||
      findByName(['company_logo', 'logo', 'business_logo', 'business_company_logo'], fields)?.value ||
      findByLabel(['logo'], fields)?.value ||
      findByRole('logo', fields)?.value;
    if (logoSrc) {
      const img = document.createElement('img');
      img.src = logoSrc;
      img.alt = `${titleName} logo`;
      logoBox.appendChild(img);
      logoBox.classList.add('has-logo');
    } else {
      logoBox.classList.remove('has-logo');
      logoBox.innerHTML = '<i class="bi bi-building" aria-hidden="true"></i><span>No logo uploaded</span>';
    }

    categoryList.innerHTML = '';
    const groups = {};
    (nom.categories || []).forEach(({ category_name, question_name }) => {
      const key = (category_name && category_name.trim()) || 'Other';
      if (!groups[key]) groups[key] = [];
      if (question_name && question_name.trim()) groups[key].push(question_name.trim());
    });

    const catKeys = Object.keys(groups);
    let totalAwards = 0;
    if (!catKeys.length) {
      categoryList.innerHTML = '<li class="text-muted">No categories selected.</li>';
    } else {
      catKeys.forEach(cat => {
        const li = document.createElement('li');
        li.className = 'award-group';
        const title = document.createElement('div');
        title.className = 'award-cat';
        title.innerHTML = `<i class="bi bi-trophy" aria-hidden="true"></i>${cat}`;
        li.appendChild(title);
        const ul = document.createElement('ul');
        ul.className = 'award-items';
        groups[cat].forEach(q => {
          totalAwards += 1;
          const qi = document.createElement('li');
          qi.innerHTML = `<i class="bi bi-award" aria-hidden="true"></i><span>${q}</span>`;
          ul.appendChild(qi);
        });
        li.appendChild(ul);
        categoryList.appendChild(li);
      });
    }
    if (categoriesMetaCountEl) {
      if (!catKeys.length) {
        categoriesMetaCountEl.textContent = '';
      } else {
        categoriesMetaCountEl.textContent =
          `(${catKeys.length} categor${catKeys.length === 1 ? 'y' : 'ies'} · ${totalAwards} award${totalAwards === 1 ? '' : 's'})`;
      }
    }

    if (insightsEl) {
      const filledFields = (fields || []).filter(f => String(f?.value || '').trim() !== '').length;
      const chips = [
        { icon: 'bi-check2-circle', text: `${filledFields} filled field${filledFields === 1 ? '' : 's'}` },
        { icon: 'bi-grid-3x3-gap', text: `${catKeys.length} categor${catKeys.length === 1 ? 'y' : 'ies'}` },
        { icon: 'bi-award', text: `${totalAwards} award${totalAwards === 1 ? '' : 's'}` },
      ];
      insightsEl.innerHTML = chips
        .map(c => `<span class="insight-chip"><i class="bi ${c.icon}" aria-hidden="true"></i>${c.text}</span>`)
        .join('');
    }

    buildDetails(nom, fields);
    syncEditActions(nom);

    card.style.display = '';
    card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  const params = new URLSearchParams(window.location.search);
  if (params.has('ref')) {
    refInput.value = params.get('ref') || '';
    form.dispatchEvent(new Event('submit'));
  }

  // ---- Forgot reference recovery ----
  const forgotForm = document.getElementById('forgotRefForm');
  const forgotEmail = document.getElementById('forgotRefEmail');
  const forgotSubmit = document.getElementById('forgotRefSubmit');
  const forgotMsg = document.getElementById('forgotRefMsg');
  const forgotModalEl = document.getElementById('forgotRefModal');

  function showForgotMsg(text, ok) {
    if (!forgotMsg) return;
    forgotMsg.textContent = text;
    forgotMsg.className = `alert mt-3 mb-0 alert-${ok ? 'success' : 'danger'}`;
  }

  function clearForgotMsg() {
    if (!forgotMsg) return;
    forgotMsg.textContent = '';
    forgotMsg.className = 'alert mt-3 mb-0 d-none';
  }

  forgotModalEl?.addEventListener('hidden.bs.modal', () => {
    clearForgotMsg();
    forgotForm?.classList.remove('was-validated');
    if (forgotEmail) forgotEmail.value = '';
    if (forgotSubmit) forgotSubmit.disabled = false;
  });

  async function submitForgotReference() {
    if (!forgotEmail || !forgotSubmit) return;
    clearForgotMsg();
    forgotEmail.classList.remove('is-invalid');
    const email = forgotEmail.value.trim();
    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      forgotEmail.classList.add('is-invalid');
      forgotEmail.focus();
      return;
    }

    forgotSubmit.disabled = true;
    const original = forgotSubmit.innerHTML;
    forgotSubmit.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Sending…';

    const payload = { email };
    const eventId =
      params.get('event_id') ||
      document.getElementById('forgotRefBtn')?.getAttribute('data-event-id') ||
      '';
    if (eventId && Number(eventId) > 0) payload.event_id = Number(eventId);

    try {
      const recoverBaseRaw = (typeof window.TOCCA_NOMINATION_BASE === 'string' && window.TOCCA_NOMINATION_BASE.trim())
        ? window.TOCCA_NOMINATION_BASE.trim()
        : (document.baseURI || window.location.href);
      const recoverUrl = new URL('recover_reference.php', new URL(recoverBaseRaw, window.location.origin).href).href;
      const res = await fetch(recoverUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify(payload),
        cache: 'no-store',
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.status !== 'success') {
        throw new Error(data.message || 'Unable to send recovery email.');
      }
      showForgotMsg(data.message || 'Check your email for your reference number.', true);
    } catch (err) {
      showForgotMsg(err.message || 'Unable to send recovery email.', false);
    } finally {
      forgotSubmit.disabled = false;
      forgotSubmit.innerHTML = original;
    }
  }

  forgotSubmit?.addEventListener('click', submitForgotReference);
  forgotForm?.addEventListener('submit', (e) => {
    e.preventDefault();
    submitForgotReference();
  });
});
