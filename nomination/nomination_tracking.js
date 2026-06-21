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
  const resultSubtextEl = document.getElementById('resultSubtext');

  const TITLE_NAME_SLUGS  = ['official_business_name', 'business_name', 'name_of_business', 'company_name'];
  const TITLE_NAME_LABELS = ['official.*business.*name', '^business\\s*name$', 'company\\s*name'];
  const SUMMARY_SLUGS = new Set([
    'official_business_name', 'business_name', 'name_of_business', 'company_name',
    'owner_president_general_manager', 'owner_president_gm', 'owner_name',
    'mayor_s_permit_number', 'mayors_permit_number', 'mayors_permit', 'mayor_permit',
    'business_address', 'business_company_address', 'full_address',
    'mobile_number', 'mobile', 'contact_phone', 'phone',
    'email_address', 'email', 'contact_email',
    'website', 'company_logo', 'logo', 'business_logo',
  ]);
  const SUMMARY_LABEL_RE = /official.*business.*name|^business\s*name$|company\s*name|owner|president|general\s*manager|mayor.*permit|business.*address|company\s*address|mobile|phone|email|website|designation|logo/i;

  function isLogoField(f) {
    const slug  = String(f?.name  || '').toLowerCase();
    const label = String(f?.label || '').toLowerCase();
    return /logo/.test(slug) || /logo/.test(label);
  }

  function isSummaryField(f) {
    const slug  = String(f?.name  || '').toLowerCase();
    const label = String(f?.label || '').toLowerCase();
    if (isLogoField(f)) return true;
    if (SUMMARY_SLUGS.has(slug)) return true;
    return SUMMARY_LABEL_RE.test(slug) || SUMMARY_LABEL_RE.test(label);
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

  function statusHelpMessage(statusKey) {
    switch ((statusKey || '').toLowerCase()) {
      case 'approved':
      case 'merged':
        return 'Your nomination is approved. It will proceed to the voting stage for eligible awards.';
      case 'rejected':
        return 'Your nomination was not approved. You may submit a new nomination if the nomination period is still open.';
      case 'in_review':
        return 'Your nomination is under review by the committee. Please wait for the final decision.';
      case 'needs_info':
        return 'Additional information may be needed. Please monitor your email or contact the organizers.';
      default:
        return 'Your nomination is queued for initial review.';
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
    const row = (fields || []).find(f => set.has(String(f.name || '').toLowerCase()));
    return (row && row.value) ? row.value : null;
  }

  function findByLabel(hints, fields) {
    if (!hints || !hints.length) return null;
    const re = new RegExp(hints.join('|'), 'i');
    const row = (fields || []).find(f => re.test(String(f.label || '')));
    return (row && row.value) ? row.value : null;
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

  function renderValue(value, field) {
    const wrap = document.createElement('div');
    wrap.className = 'kv-v';
    if (!value) {
      wrap.textContent = '—';
      wrap.classList.add('text-muted');
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
    if (!value) return;
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

  function buildSummary(nom, fields) {
    summaryFields.innerHTML = '';
    const titleName =
      nom.business_name ||
      findByName(TITLE_NAME_SLUGS, fields) ||
      findByLabel(TITLE_NAME_LABELS, fields) ||
      '—';

    const rows = [
      ['Owner / President / General Manager',
        nom.owner_name ||
        findByName(['owner_president_general_manager', 'owner_president_gm', 'owner_name', 'owner'], fields) ||
        findByLabel(['owner', 'president', 'general\\s*manager'], fields)],
      ['Mobile Number', nom.mobile_number || findByName(['mobile_number', 'mobile', 'phone', 'contact_number'], fields)],
      ['Designation in the Business / Company', nom.designation || findByLabel(['designation'], fields)],
      ["Mayor's Permit", nom.mayor_permit || findByName(['mayor_s_permit_number', 'mayors_permit_number'], fields)],
      ['Email', nom.email || findByName(['email_address', 'email'], fields)],
      ['Business / Company Address', nom.address || findByName(['business_address', 'business_company_address'], fields)],
      ['Website', nom.website || findByName(['website'], fields)],
    ];

    rows.forEach(([label, value]) => {
      const hint = label.replace(/\s*\/\s*/g, '|').split('|')[0].trim();
      const field = (fields || []).find(f => {
        const text = `${f.label || ''} ${f.name || ''}`;
        return new RegExp(hint.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i').test(text);
      });
      appendRow(summaryFields, label, value, field);
    });
  }

  function buildExtra(fields) {
    extraFields.innerHTML = '';
    const remaining = (fields || []).filter(f => f && f.value && !isSummaryField(f));
    if (!remaining.length) {
      if (extraSection) extraSection.style.display = 'none';
      if (extraCountEl) extraCountEl.textContent = '';
      return;
    }
    if (extraSection) extraSection.style.display = '';
    if (extraCountEl) extraCountEl.textContent = `(${remaining.length} field${remaining.length === 1 ? '' : 's'})`;
    remaining.forEach(f => {
      const k = document.createElement('div');
      k.className = 'kv-k';
      k.textContent = f.label || f.name || '';
      extraFields.appendChild(k);
      extraFields.appendChild(renderValue(f.value, f));
    });
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

    const url = `nomination_tracking.php?ajax=1&ref=${encodeURIComponent(ref)}&t=${Date.now()}`;

    fetch(url, { cache: 'no-store' })
      .then(r => r.json())
      .then(payload => {
        if (payload.status !== 'success') {
          errorMsg.textContent = payload.message || 'Reference number not found.';
          errorMsg.style.display = '';
          errorMsg.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
          return;
        }

        const nom    = payload.data || {};
        const fields = nom.fields || [];
        const step   = statusStep(nom.status);

        const titleName =
          nom.business_name ||
          findByName(TITLE_NAME_SLUGS, fields) ||
          findByLabel(TITLE_NAME_LABELS, fields) ||
          '—';
        nameEl.textContent = titleName;

        if (refNoText) refNoText.textContent = nom.reference_no || ref;
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
        if (resultSubtextEl) {
          resultSubtextEl.textContent = `Status: ${info.text} \u2022 Reference: ${nom.reference_no || ref}`;
        }
        if (statusHelpEl) {
          statusHelpEl.textContent = statusHelpMessage(statusKey);
        }

        logoBox.innerHTML = '';
        let logoSrc =
          nom.logo_path ||
          findByName(['company_logo', 'logo', 'business_logo'], fields) ||
          findByLabel(['logo'], fields);
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

        buildSummary(nom, fields);
        buildExtra(fields);

        card.style.display = '';
        card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      })
      .catch(() => {
        errorMsg.textContent = 'Failed to fetch status. Please try again.';
        errorMsg.style.display = '';
        errorMsg.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      })
      .finally(() => setLoading(false));
  });

  const params = new URLSearchParams(window.location.search);
  if (params.has('ref')) {
    refInput.value = params.get('ref') || '';
    form.dispatchEvent(new Event('submit'));
  }
});
