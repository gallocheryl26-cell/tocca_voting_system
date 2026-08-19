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
  const approvedAwardsBody = document.querySelector('#approvedAwardsTable tbody');
  const removedAwardsBody  = document.querySelector('#removedAwardsTable tbody');
  const summaryFields = document.getElementById('summaryFields');
  const extraFields   = document.getElementById('extraFields');
  const extraSection  = document.getElementById('extraSection');
  const extraCountEl  = document.getElementById('extraCount');
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

  function statusInfo(s, onBallot) {
    s = (s || '').toLowerCase();
    if (s === 'approved' || s === 'merged') {
      if (onBallot) {
        return { text: 'Completed', badge: 'status-approved' };
      }
      return { text: 'Under evaluation', badge: 'status-evaluation' };
    }
    const map = {
      rejected   : { text: 'Rejected',          badge: 'status-rejected' },
      in_review  : { text: 'In Review',         badge: 'status-review' },
      needs_info : { text: 'Needs Information', badge: 'status-neutral' },
      pending    : { text: 'Pending Review',    badge: 'status-pending' },
      submitted  : { text: 'Submitted',         badge: 'status-pending' },
    };
    return map[s] || { text: s || 'Pending Review', badge: 'status-neutral' };
  }

  function statusHelpMessage(statusKey, canEdit, onBallot) {
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
        if (onBallot) {
          return 'Your registration is complete. Your business is on the public ballot for the remaining award titles. A voting QR and business voting link were emailed when it was confirmed for public voting.';
        }
        return 'Your registration is under evaluation. A voting QR and business voting link will be emailed when your business is confirmed for public voting.';
      case 'rejected':
        return 'Your registration was not approved. You may submit a new registration if the registration period is still open.';
      case 'in_review':
        return 'Your registration is under review by the committee. Editing is closed unless more information is requested.';
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

  function isWebsiteField(f) {
    const role = String(f?.profile_role || '').toLowerCase();
    const type = String(f?.type || '').toLowerCase();
    const text = `${f?.name || ''} ${f?.label || ''}`.toLowerCase();
    return role === 'website' || type === 'url' || /\b(website|web\s*site|facebook|instagram|social)\b/.test(text);
  }

  function isUploadedPath(value) {
    const raw = String(value || '').split('?')[0];
    return /uploads?\//i.test(raw)
      || /\.(pdf|docx?|xlsx?|pptx?|txt|zip)$/i.test(raw);
  }

  function websiteHref(value) {
    const v = String(value || '').trim();
    if (!v) return '';
    if (/^https?:\/\//i.test(v)) return v;
    return 'https://' + v.replace(/^\/+/, '');
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

    if (!isWebsiteField(field) && (isFileField(field) || isUploadedPath(raw) || isMayor) && isImage) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'doc-link doc-link--image';
      btn.title = fileNameFromPath(value);
      btn.setAttribute('data-open-photo', raw);
      btn.setAttribute('data-photo-title', stripLabel(field?.label || 'Photo'));
      const img = document.createElement('img');
      img.src = value;
      img.alt = stripLabel(field?.label || "Mayor's Permit");
      img.className = 'doc-thumb';
      btn.appendChild(img);
      const caption = document.createElement('span');
      caption.textContent = 'View photo';
      btn.appendChild(caption);
      wrap.appendChild(btn);
      return wrap;
    }

    if (!isWebsiteField(field) && (isFileField(field) || isUploadedPath(raw))) {
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

    if (isWebsiteField(field) || /^https?:\/\//i.test(raw) || /^(www\.|facebook\.com|instagram\.com)/i.test(raw)) {
      const a = document.createElement('a');
      a.href = websiteHref(raw);
      a.target = '_blank';
      a.rel = 'noopener noreferrer';
      a.textContent = raw;
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
    wrap.textContent = value;
    return wrap;
  }

  function appendRow(container, label, value, field) {
    if (value == null || String(value).trim() === '') return;
    const k = document.createElement('div');
    k.className = 'kv-k';
    k.textContent = label;
    const v = renderValue(value, field || { type: 'text' });
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
        label: 'Type of Ownership',
        names: ['designation', 'designation_in_the_business_company'],
        labels: ['designation', 'type of ownership', 'type of business'],
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
        label: 'Website/Facebook Page Link',
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
        type: spec.role === 'website' ? 'url' : 'text',
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

    const url = new URL(window.location.href);
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

    const req = fetch(url.href, {
      headers: {
        Accept: 'application/json',
        'ngrok-skip-browser-warning': '1',
      },
      cache: 'no-store',
    })
      .then(async (r) => {
        const text = await r.text();
        try {
          return JSON.parse(text);
        } catch (e) {
          throw new Error('Status lookup did not return data. Refresh and try again.');
        }
      })
      .then(payload => {
        if (payload && payload.status === 'success') {
          window.__toccaTrackCache.set(cacheKey, { at: Date.now(), payload });
        }
        return payload;
      });

    window.__toccaTrackInflight = { key: cacheKey, promise: req };
    req
      .then(handleTrackPayload)
      .catch((err) => {
        errorMsg.textContent = (err && err.message) ? err.message : 'Failed to fetch status. Please try again.';
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
    if (ref) {
      const next = new URL(window.location.href);
      if (next.searchParams.get('ref') !== ref) {
        next.searchParams.set('ref', ref);
        history.replaceState({}, '', next);
      }
    }

    const statusKey = (nom.status || '').toLowerCase();
    const onBallot = nom.on_ballot === true || nom.on_ballot === 1 || nom.on_ballot === '1';
    const isTerminalSuccess = ['approved', 'merged'].includes(statusKey);
    const isRejected = statusKey === 'rejected';
    const underEvaluation = isTerminalSuccess && !onBallot;

    steps.forEach((li, idx) => {
      li.classList.remove('completed', 'active', 'rejected');
      const circle = li.querySelector('.step-circle');

      if (underEvaluation) {
        if (idx < steps.length - 1) li.classList.add('completed');
        else li.classList.add('active');
      } else if (isTerminalSuccess) {
        li.classList.add('completed');
      } else if (isRejected) {
        if (idx < steps.length - 1) li.classList.add('completed');
      } else {
        if (idx < step - 1) li.classList.add('completed');
        else if (idx === step - 1) li.classList.add('active');
      }

      const isCurrent =
        (underEvaluation && idx === steps.length - 1) ||
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
      if (underEvaluation) {
        if (finalIcon) finalIcon.className = 'bi bi-hourglass-split';
        if (finalLabelFull) finalLabelFull.textContent = 'Under evaluation';
        if (finalLabelShort) finalLabelShort.textContent = 'Eval';
      } else if (isTerminalSuccess) {
        if (finalIcon) finalIcon.className = 'bi bi-check-lg';
        if (finalLabelFull) finalLabelFull.textContent = 'Completed';
        if (finalLabelShort) finalLabelShort.textContent = 'Done';
      } else {
        if (finalIcon) finalIcon.className = 'bi bi-flag-fill';
        if (finalLabelFull) finalLabelFull.textContent = 'Completed';
        if (finalLabelShort) finalLabelShort.textContent = 'Done';
      }
    }

    const info = statusInfo(nom.status, onBallot);
    statusEl.textContent = info.text;
    statusEl.className = `status-badge ${info.badge}`;
    if (statusHelpEl) {
      statusHelpEl.textContent = statusHelpMessage(statusKey, !!nom.can_edit, onBallot);
    }

    logoBox.innerHTML = '';
    logoBox.removeAttribute('data-open-photo');
    logoBox.removeAttribute('data-photo-title');
    logoBox.removeAttribute('tabindex');
    logoBox.setAttribute('role', 'img');
    let logoSrc =
      nom.logo_path ||
      findByName(['company_logo', 'logo', 'business_logo', 'business_company_logo'], fields)?.value ||
      findByLabel(['logo'], fields)?.value ||
      findByRole('logo', fields)?.value;
    if (logoSrc) {
      const img = document.createElement('img');
      img.src = logoSrc;
      img.alt = `${titleName} logo`;
      img.addEventListener('error', () => {
        logoBox.classList.remove('has-logo');
        logoBox.removeAttribute('data-open-photo');
        logoBox.removeAttribute('data-photo-title');
        logoBox.removeAttribute('tabindex');
        logoBox.setAttribute('role', 'img');
        logoBox.innerHTML = '<i class="bi bi-building" aria-hidden="true"></i><span>No logo uploaded</span>';
      });
      logoBox.appendChild(img);
      logoBox.classList.add('has-logo');
      logoBox.setAttribute('role', 'button');
      logoBox.tabIndex = 0;
      logoBox.setAttribute('data-open-photo', logoSrc);
      logoBox.setAttribute('data-photo-title', `${titleName} logo`);
    } else {
      logoBox.classList.remove('has-logo');
      logoBox.innerHTML = '<i class="bi bi-building" aria-hidden="true"></i><span>No logo uploaded</span>';
    }

    const groups = {};
    function ensureAwardGroup(catName) {
      const key = (catName && String(catName).trim()) || 'Other';
      if (!groups[key]) groups[key] = { active: [], removed: [] };
      return groups[key];
    }

    (nom.categories || []).forEach(({ category_name, question_name }) => {
      const g = ensureAwardGroup(category_name);
      const name = (question_name && String(question_name).trim()) || '';
      if (name) g.active.push(name);
    });

    (nom.removed_awards || []).forEach((row) => {
      const g = ensureAwardGroup(row.category_name);
      const name = (row.question_name && String(row.question_name).trim()) || '';
      if (!name) return;
      g.removed.push({
        name,
        reason: String(row.reason_label || row.reason || '').trim(),
      });
    });

    const catKeys = Object.keys(groups);
    let totalAwards = 0;
    let removedCount = 0;
    const approvedRows = [];
    const removedRows = [];
    catKeys.forEach((cat) => {
      groups[cat].active.forEach((name) => {
        totalAwards += 1;
        approvedRows.push({ name, category: cat });
      });
      groups[cat].removed.forEach((item) => {
        removedCount += 1;
        removedRows.push({ name: item.name, category: cat, reason: item.reason });
      });
    });

    function fillTrackTable(tbody, rows, columns, emptyText) {
      if (!tbody) return;
      tbody.innerHTML = '';
      if (!rows.length) {
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = columns;
        td.className = 'text-muted';
        td.textContent = emptyText;
        tr.appendChild(td);
        tbody.appendChild(tr);
        return;
      }
      rows.forEach((row) => {
        const tr = document.createElement('tr');
        const nameTd = document.createElement('td');
        nameTd.textContent = row.name;
        const catTd = document.createElement('td');
        catTd.textContent = row.category || '—';
        tr.appendChild(nameTd);
        tr.appendChild(catTd);
        if (columns === 3) {
          const reasonTd = document.createElement('td');
          reasonTd.textContent = row.reason || '—';
          tr.appendChild(reasonTd);
        }
        tbody.appendChild(tr);
      });
    }

    fillTrackTable(
      approvedAwardsBody,
      approvedRows,
      2,
      catKeys.length ? 'No remaining awards on this registration.' : 'No categories selected.'
    );
    fillTrackTable(
      removedAwardsBody,
      removedRows,
      3,
      'No awards have been removed.'
    );
    if (categoriesMetaCountEl) {
      if (!catKeys.length) {
        categoriesMetaCountEl.textContent = '';
      } else if (totalAwards === 0 && removedCount > 0) {
        categoriesMetaCountEl.textContent = `(${removedCount} removed)`;
      } else if (removedCount > 0) {
        const remainingCats = catKeys.filter(k => groups[k].active.length > 0).length;
        categoriesMetaCountEl.textContent =
          `(${remainingCats} categor${remainingCats === 1 ? 'y' : 'ies'} · ${totalAwards} award${totalAwards === 1 ? '' : 's'} · ${removedCount} removed)`;
      } else {
        const remainingCats = catKeys.filter(k => groups[k].active.length > 0).length;
        categoriesMetaCountEl.textContent =
          `(${remainingCats} categor${remainingCats === 1 ? 'y' : 'ies'} · ${totalAwards} award${totalAwards === 1 ? '' : 's'})`;
      }
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
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'ngrok-skip-browser-warning': '1',
        },
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

  const photoLightbox = document.getElementById('photoLightbox');
  const photoLightboxImg = document.getElementById('photoLightboxImg');
  const photoLightboxTitle = document.getElementById('photoLightboxTitle');
  const photoLightboxClose = document.getElementById('photoLightboxClose');

  function closePhotoLightbox() {
    if (!photoLightbox) return;
    photoLightbox.hidden = true;
    document.body.classList.remove('photo-lightbox-open');
    if (photoLightboxImg) {
      photoLightboxImg.removeAttribute('src');
      photoLightboxImg.alt = '';
    }
  }

  function openPhotoLightbox(src, title) {
    if (!photoLightbox || !photoLightboxImg || !src) return;
    photoLightboxImg.src = src;
    photoLightboxImg.alt = title || 'Photo';
    if (photoLightboxTitle) photoLightboxTitle.textContent = title || 'Photo';
    photoLightbox.hidden = false;
    document.body.classList.add('photo-lightbox-open');
    photoLightboxClose?.focus();
  }

  document.addEventListener('click', (e) => {
    const trigger = e.target.closest('[data-open-photo]');
    if (!trigger) return;
    e.preventDefault();
    openPhotoLightbox(
      trigger.getAttribute('data-open-photo'),
      trigger.getAttribute('data-photo-title') || 'Photo'
    );
  });

  logoBox?.addEventListener('keydown', (e) => {
    if (!logoBox.hasAttribute('data-open-photo')) return;
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      openPhotoLightbox(
        logoBox.getAttribute('data-open-photo'),
        logoBox.getAttribute('data-photo-title') || 'Photo'
      );
    }
  });

  photoLightboxClose?.addEventListener('click', closePhotoLightbox);
  photoLightbox?.addEventListener('click', (e) => {
    if (e.target === photoLightbox) closePhotoLightbox();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && photoLightbox && !photoLightbox.hidden) {
      closePhotoLightbox();
    }
  });
});
