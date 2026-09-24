let rowToEdit = null;
let choicesCache = [];
let currentEmailName = "Business";
let currentQrFilename = "";
let currentVoteUrl = "";

const QR_EMAIL_DEFAULT_MESSAGE =
`Thank you for participating in the Tatak Ormoc Consumers' Choice Awards.

Print or share the QR poster below. The same file is attached so you can download it.

Use your business voting link when you promote — customers can scan the QR or tap the link to vote for you.`;

function votingPageLinkHtml(title, hint, url, first) {
  const href = String(url || '').trim();
  if (!href || !window.toccaBrandedEmail) return '';
  const safe = window.toccaBrandedEmail.escapeHtml(href);
  const top = first ? '18px' : '16px';
  return '<p style="margin:' + top + ' 0 4px;font-weight:700;">' + window.toccaBrandedEmail.escapeHtml(title) + '</p>'
    + '<p style="margin:0 0 6px;color:#4b5563;font-size:14px;line-height:1.5;">' + window.toccaBrandedEmail.escapeHtml(hint) + '</p>'
    + '<p style="margin:0 0 4px;word-break:break-all;"><a href="' + safe + '" style="color:#2563eb;">' + safe + '</a></p>';
}

function renderQrEmailPreview(targetId, name, message, businessUrl) {
  const target = document.getElementById(targetId);
  if (!target || !window.toccaBrandedEmail) return;
  const portalUrl = window.toccaVotePortalUrl || '';
  const bizUrl = businessUrl || currentVoteUrl || '';
  const sameAsPortal = bizUrl.replace(/\/+$/, '') === portalUrl.replace(/\/+$/, '');
  const showBusiness = Boolean(bizUrl) && !sameAsPortal;
  const posterHtml = typeof window.toccaBrandedEmail.qrPosterHtml === 'function'
    ? window.toccaBrandedEmail.qrPosterHtml()
    : '';
  window.toccaBrandedEmail.renderInto(target, {
    heading: 'Shortlisted for public voting',
    greetingName: name || 'Business',
    showRegistrationAssist: false,
    bodyHtml: window.toccaBrandedEmail.plainToHtml(message || QR_EMAIL_DEFAULT_MESSAGE, name || 'Business')
      + posterHtml
      + (showBusiness ? votingPageLinkHtml(
        'Your business (best to promote)',
        'Share this on Facebook, Messenger, or posters so customers go straight to voting for your business.',
        bizUrl,
        true
      ) : '')
      + votingPageLinkHtml(
        'All awards',
        'Share this if you want customers to browse every category and pick businesses themselves.',
        portalUrl,
        !showBusiness
      ),
  });
}
let questionsByCategory = [];           
let selectedQuestionIdsSet = new Set();
let awardEntryByQuestion = {}; 
let establishmentTypes = [];           
let establishmentTypesPromise = null;
let establishmentTypesFeatureEnabled = false;
let selectedEstablishmentTypeIds = [];
const nameInputEl = document.getElementById('editName');
const emailInputEl = document.getElementById('editEmail');
const nameFeedbackEl = document.getElementById('editNameFeedback');
const emailFeedbackEl = document.getElementById('editEmailFeedback');
const establishmentTypeSelectEl = document.getElementById('establishmentTypeSelect'); // legacy (may be null)
const establishmentTypeCheckboxesEl = document.getElementById('establishmentTypeCheckboxes');
const establishmentTypeFeedbackEl = document.getElementById('establishmentTypeFeedback');
const establishmentTypeGroupEl  = document.getElementById('establishmentTypeGroup');
const establishmentTypeNoticeEl = document.getElementById('establishmentTypeNotice');
const questionCheckboxContainer = document.getElementById('questionCheckboxContainer');

function normalizeTypeId(value) {
  if (value === null || value === undefined || value === '') return null;
  const numeric = Number(value);
  return Number.isNaN(numeric) ? null : numeric;
}

function escapeHtmlAttr(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/"/g, '&quot;')
    .replace(/</g, '&lt;');
}

/** Bootstrap confirmation dialog; falls back to window.confirm if modal is missing. */
function confirmAction(options) {
  if (typeof window.adminConfirm === 'function') {
    return window.adminConfirm(options);
  }
  return Promise.resolve(window.confirm(options?.message || 'Are you sure?'));
}

async function copyTextToClipboard(text) {
  const value = String(text || '');
  if (!value) return false;
  try {
    await navigator.clipboard.writeText(value);
    return true;
  } catch (e) {
    const ta = document.createElement('textarea');
    ta.value = value;
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    const ok = document.execCommand('copy');
    document.body.removeChild(ta);
    return ok;
  }
}
function getEstablishmentTypeName(typeId) {
  const norm = normalizeTypeId(typeId);
  if (norm === null) return '';
  const match = establishmentTypes.find(t => normalizeTypeId(t?.type_id ?? t?.id) === norm);
  return match?.type_name || match?.name || (norm !== null ? `Type ${norm}` : '');
}
function getEstablishmentTypeNames(typeIds) {
  return (typeIds || [])
    .map(id => getEstablishmentTypeName(id))
    .filter(Boolean);
}
function resetFormValidation() {
  if (nameInputEl) { nameInputEl.classList.remove('is-invalid'); nameFeedbackEl && (nameFeedbackEl.textContent = ''); }
  if (emailInputEl){ emailInputEl.classList.remove('is-invalid'); emailFeedbackEl && (emailFeedbackEl.textContent = ''); }
  establishmentTypeGroupEl?.classList.remove('is-invalid');
  if (establishmentTypeFeedbackEl) {
    establishmentTypeFeedbackEl.classList.add('d-none');
  }
}
nameInputEl?.addEventListener('input', () => { nameInputEl.classList.remove('is-invalid'); nameFeedbackEl && (nameFeedbackEl.textContent = ''); });
emailInputEl?.addEventListener('input', () => { emailInputEl.classList.remove('is-invalid'); emailFeedbackEl && (emailFeedbackEl.textContent = ''); });

function showSuccessToast(message) {
  const body = document.getElementById("toastSuccessBody");
  body.textContent = message;
  const toast = new bootstrap.Toast(document.getElementById("toastSuccess"));
  toast.show();
}
function showInfoToast(message, isSuccess = true) {
  const el = document.getElementById("toastSuccess");
  const body = document.getElementById("toastSuccessBody");
  el.className = `toast align-items-center text-bg-${isSuccess ? "success" : "danger"} border-0`;
  body.textContent = message;
  const toast = new bootstrap.Toast(el, { delay: 4000 });
  toast.show();
}

async function checkQrExists(filename) {
  if (!filename) return false;
  const url = 'qrcodes/' + encodeURIComponent(filename);
  try {
    const r = await fetch(url, { method: 'HEAD', cache: 'no-store' });
    if (r.ok) return true;
    const r2 = await fetch(url, { method: 'GET', cache: 'no-store' });
    return r2.ok;
  } catch { return false; }
}

const qrPreviewImg       = document.getElementById('qrImg');
const regenerateQrBtnEl = document.getElementById('regenerateQRBtn');
const qrPreviewBusyOverlayEl = document.getElementById('qrPreviewBusyOverlay');
const qrActionHintEl = document.getElementById('qrActionHint');
const QR_ACTION_HINT_DEFAULT =
  '<strong>Refresh</strong> updates the preview from Admin Settings. ' +
  '<strong>Regenerate</strong> overwrites the saved poster file for this establishment.';
let qrFrameDefaults = null;
let qrPreviewChoiceId = null;
let qrModalBusy = false;

function setQrModalBusy(busy, options = {}) {
  const { showOverlay = false, hint = '' } = options;
  qrModalBusy = !!busy;
  const actionBtns = [regenerateQrBtnEl, document.getElementById('saveQRBtn')];

  actionBtns.forEach((btn) => {
    if (!btn) return;
    btn.disabled = qrModalBusy;
  });

  if (qrPreviewBusyOverlayEl) {
    const overlayOn = qrModalBusy && showOverlay;
    qrPreviewBusyOverlayEl.classList.toggle('d-none', !overlayOn);
    qrPreviewBusyOverlayEl.classList.toggle('d-flex', overlayOn);
  }

  if (qrActionHintEl) {
    if (qrModalBusy && hint) {
      qrActionHintEl.innerHTML = `<span class="text-primary"><span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>${hint}</span>`;
    } else {
      qrActionHintEl.innerHTML = QR_ACTION_HINT_DEFAULT;
    }
  }
}

function setButtonLoading(btn, loading, loadingHtml, idleHtml) {
  if (!btn) return;
  if (loading) {
    if (!btn.dataset.idleHtml) btn.dataset.idleHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = loadingHtml;
  } else {
    btn.disabled = false;
    btn.innerHTML = btn.dataset.idleHtml || idleHtml || btn.innerHTML;
    delete btn.dataset.idleHtml;
  }
}

async function loadQrFrameDefaults() {
  if (qrFrameDefaults !== null) return qrFrameDefaults;

  if (window.qrFrameDefaults && typeof window.qrFrameDefaults === 'object') {
    qrFrameDefaults = window.qrFrameDefaults;
  } else if (window.qrFrameConfig) {
    const cfg = window.qrFrameConfig;
    qrFrameDefaults = {
      frameConfig: typeof cfg === 'string' ? cfg : JSON.stringify(cfg)
    };
  } else {
    qrFrameDefaults = {};
  }

  return qrFrameDefaults;
}


function resolveFrameDataset(dataset) {
  if (dataset) {
    const keys = Object.keys(dataset);
    if (keys.some(key => dataset[key] !== undefined && dataset[key] !== null && dataset[key] !== '')) {
      return dataset;
    }
  }
  return qrFrameDefaults || window.qrFrameDefaults || null;
}

function appendFrameParamsFromDataset(dataset, params) {
  if (!params) return;

  const source = resolveFrameDataset(dataset);
  if (!source) return;

  if (source.frameConfig) {
    params.set('frame_config', source.frameConfig);
  }

  const frameUseRaw =
    source.frameUse ??
    source.use_frame ??
    source.frame_use;

  if (frameUseRaw !== undefined && frameUseRaw !== null && frameUseRaw !== '') {
    const on = frameUseRaw === true || frameUseRaw === '1' || frameUseRaw === 1 || frameUseRaw === 'true';
    params.set('use_frame', on ? '1' : '0');
  }

  const framePath = source.framePath ?? source.frame_path;
  if (framePath) {
    params.set('frame_path', framePath);
  }

  const numericMap = [
    ['frameBoxX', 'frame_box_x'],
    ['frameBoxY', 'frame_box_y'],
    ['frameBoxW', 'frame_box_w'],
    ['frameBoxH', 'frame_box_h'],
    ['cardSidePad', 'card_side_pad'],
    ['cardTopPad', 'card_top_pad'],
    ['labelStripHMin', 'label_strip_h_min'],
    ['labelStripHMax', 'label_strip_h_max'],
    ['gapQrToLabel', 'gap_qr_to_label'],
    ['qrSideMin', 'qr_side_min'],
    ['labelSidePad', 'label_side_pad'],
    ['labelTopPad', 'label_top_pad'],
    ['labelBottomPad', 'label_bottom_pad'],
    ['labelLineSpacing', 'label_line_spacing'],
    ['labelFontSize', 'label_font_size'],
    ['captionBoxX', 'caption_box_x'],
    ['captionBoxY', 'caption_box_y'],
    ['captionBoxW', 'caption_box_w'],
    ['captionBoxH', 'caption_box_h'],
    ['captionFontSize', 'caption_font_size']
  ];

  numericMap.forEach(([dataKey, paramKey]) => {
    const val = source[dataKey];
    if (val !== undefined && val !== null && val !== '') {
      params.set(paramKey, val);
    }
  });
}

function appendGlobalFrameConfig(params) {
  if (!params) return;
  const cfg = window.qrFrameConfig;
  if (!cfg) return;

  try {
    const serialized = (typeof cfg === 'string') ? cfg : JSON.stringify(cfg);
    if (serialized) {
      params.set('frame_config', serialized);
    }
  } catch (e) {
    console.warn('Unable to serialize qrFrameConfig', e);
  }
}

function syncFrameDataset(sourceDataset, targetEl) {
  if (!targetEl) return;
  const fallback = qrFrameDefaults || window.qrFrameDefaults || {};
  const keys = [
    'frameConfig','framePath','frameUse','frameBoxX','frameBoxY','frameBoxW','frameBoxH',
    'cardSidePad','cardTopPad','labelStripHMin','labelStripHMax','gapQrToLabel','qrSideMin',
    'labelSidePad','labelTopPad','labelBottomPad','labelLineSpacing','labelFontSize',
    'captionBoxX','captionBoxY','captionBoxW','captionBoxH','captionFontSize'
  ];
  keys.forEach(key => {
    if (sourceDataset && sourceDataset[key] !== undefined && sourceDataset[key] !== null && sourceDataset[key] !== '') {
      targetEl.dataset[key] = sourceDataset[key];
    } else if (fallback[key] !== undefined && fallback[key] !== null && fallback[key] !== '') {
      targetEl.dataset[key] = fallback[key];
    } else {
      delete targetEl.dataset[key];
    }
  });
}

function applyFrameDefaults(dataset) {
  if (!dataset || !qrFrameDefaults) return;
  Object.entries(qrFrameDefaults).forEach(([key, value]) => {
    if (value === undefined || value === null || value === '') return;
    if (dataset[key] === undefined || dataset[key] === null || dataset[key] === '') {
      dataset[key] = value;
    }
  });
}

function syncEstablishmentTypeGroupVisibility() {
  if (!establishmentTypeGroupEl) return;
  establishmentTypesFeatureEnabled = establishmentTypes.length > 0;
  establishmentTypeGroupEl.classList.toggle('d-none', !establishmentTypesFeatureEnabled);
}

function updateEstablishmentTypeNotice(hasAwards = true, removedCount = 0) {
  if (!establishmentTypeNoticeEl) return;
  if (!establishmentTypesFeatureEnabled) {
    establishmentTypeNoticeEl.textContent = 'Select all types that apply to show the combined awards list.';
    return;
  }

  if (!establishmentTypes.length) {
    establishmentTypeNoticeEl.textContent = 'No natures of business are available yet.';
    return;
  }

  const typeNames = getEstablishmentTypeNames(selectedEstablishmentTypeIds);
  if (!typeNames.length) {
    establishmentTypeNoticeEl.textContent = 'Select all types that apply to show the combined awards list.';
    return;
  }

  if (!hasAwards) {
    establishmentTypeNoticeEl.textContent = `No awards are currently tagged for ${typeNames.join(', ')}.`;
    return;
  }

  if (removedCount > 0) {
    const plural = removedCount === 1 ? '' : 's';
    const verb   = removedCount === 1 ? 'was' : 'were';
    establishmentTypeNoticeEl.textContent = `${removedCount} previously selected award${plural} ${verb} removed because they are not available for ${typeNames.join(', ')}.`;
    return;
  }
  establishmentTypeNoticeEl.textContent = `Showing awards for: ${typeNames.join(', ')}.`;
}

function populateEstablishmentTypeSelect() {
  const host = establishmentTypeCheckboxesEl;
  if (!host) return;
  const prev = selectedEstablishmentTypeIds.slice();
  host.innerHTML = '';
  if (!establishmentTypes.length) {
    host.innerHTML = '<div class="text-muted small">No natures of business available</div>';
    selectedEstablishmentTypeIds = [];
    return;
  }
  const frag = document.createDocumentFragment();
  establishmentTypes.forEach(t => {
    const id = normalizeTypeId(t?.type_id ?? t?.id);
    if (id === null) return;
    const wrap = document.createElement('div');
    wrap.className = 'form-check';
    const input = document.createElement('input');
    input.className = 'form-check-input js-est-type';
    input.type = 'checkbox';
    input.value = String(id);
    input.id = 'choice_est_type_' + id;
    const label = document.createElement('label');
    label.className = 'form-check-label';
    label.setAttribute('for', input.id);
    label.textContent = t?.type_name || t?.name || ('Type ' + id);
    wrap.appendChild(input);
    wrap.appendChild(label);
    frag.appendChild(wrap);
  });
  host.appendChild(frag);
  setSelectedEstablishmentTypes(prev);
}

async function fetchEstablishmentTypes() {
  try {
    const res = await fetch('choice.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'loadEstablishmentTypes' })
    });
    const data = await res.json().catch(() => ({ status: 'error' }));

    if (data.status === 'success') {
      establishmentTypes = Array.isArray(data.data) ? data.data : [];
      establishmentTypesFeatureEnabled = data.no_active_event !== true && establishmentTypes.length > 0;
      if (data.no_active_event) {
        establishmentTypeNoticeEl &&
          (establishmentTypeNoticeEl.textContent =
            'No active event. Activate an event to manage nature of business.');
      }
    } else {
      establishmentTypes = [];
      establishmentTypesFeatureEnabled = false;
    }
  } catch (e) {
    console.error('Failed to load establishment types', e);
    establishmentTypes = [];
    establishmentTypesFeatureEnabled = false;
  }

  populateEstablishmentTypeSelect();
  syncEstablishmentTypeGroupVisibility();
  updateEstablishmentTypeNotice(true);
}

function ensureEstablishmentTypesLoaded(forceRefresh = false) {
  if (!establishmentTypeCheckboxesEl && !establishmentTypeSelectEl) return Promise.resolve();
  if (forceRefresh || !establishmentTypesPromise) {
    establishmentTypesPromise = fetchEstablishmentTypes();
  }
  return establishmentTypesPromise;
}

function setSelectedEstablishmentTypes(values) {
  const ids = (Array.isArray(values) ? values : [values])
    .map(normalizeTypeId)
    .filter(v => v !== null);
  selectedEstablishmentTypeIds = [...new Set(ids)];
  establishmentTypeCheckboxesEl?.querySelectorAll('input.js-est-type').forEach(cb => {
    cb.checked = selectedEstablishmentTypeIds.includes(Number(cb.value));
  });
  updateEstablishmentTypeNotice(true);
}

function setSelectedEstablishmentType(value) {
  setSelectedEstablishmentTypes(value == null || value === '' ? [] : [value]);
}

establishmentTypeCheckboxesEl?.addEventListener('change', (e) => {
  if (!e.target?.matches?.('input.js-est-type')) return;
  const prevSelected = Array.from(selectedQuestionIdsSet);
  establishmentTypeGroupEl?.classList.remove('is-invalid');
  establishmentTypeFeedbackEl?.classList.add('d-none');
  selectedEstablishmentTypeIds = [...establishmentTypeCheckboxesEl.querySelectorAll('input.js-est-type:checked')]
    .map(cb => Number(cb.value));
  updateEstablishmentTypeNotice(true);
  loadQuestionsCheckboxes(prevSelected, selectedEstablishmentTypeIds);
});

function groupQuestionsByCategory(items = []) {
  const map = new Map();
  items.forEach(item => {
    const cid = item.category_id ?? null;
    const key = cid === null ? 'uncategorized' : String(cid);
    if (!map.has(key)) {
      map.set(key, {
        key,
        category_id: cid,
        category_name: item.category_name || 'Uncategorized',
        awards: []
      });
    }
    map.get(key).awards.push({
      question_id: Number(item.question_id),
      question_name: item.question_name,
      category_name: item.category_name || '',
      needs_entry: !!item.needs_entry,
      entry_kind: item.entry_kind || '',
      entry_label: item.entry_label || 'Product name'
    });
  });

  const grouped = Array.from(map.values()).map(cat => ({
    ...cat,
    awards: cat.awards.sort((a, b) => a.question_name.localeCompare(b.question_name))
  }));

  return grouped.sort((a, b) => a.category_name.localeCompare(b.category_name));
}

function collectAwardEntryInputs() {
  document.querySelectorAll('.choice-entry-input').forEach((el) => {
    const qid = String(el.getAttribute('data-question-id') || '');
    if (qid) awardEntryByQuestion[qid] = el.value;
  });
}

function renderQuestionCheckboxes(removedCount = 0) {
  collectAwardEntryInputs();
  const container = questionCheckboxContainer;
  if (!container) return;
  container.innerHTML = '';
  if (!questionsByCategory.length) {
    container.innerHTML = '<tr><td colspan="2" class="text-center text-muted">No awards available for this event.</td></tr>';
    updateEstablishmentTypeNotice(false, removedCount);
    return;
  }
  let hasAwards = false;
  questionsByCategory.forEach(category => {
    const header = document.createElement('tr');
    header.classList.add('table-active');
    header.innerHTML = `<td colspan="2" class="fw-semibold">${category.category_name}</td>`;
    container.appendChild(header);

    if (!category.awards.length) {
      const empty = document.createElement('tr');
      empty.innerHTML = '<td colspan="2" class="ps-4 text-muted fst-italic">No awards in this category yet.</td>';
      container.appendChild(empty);
      return;
    }

    hasAwards = true;

    category.awards.forEach(award => {
      const qid = Number(award.question_id);
      const isChecked = selectedQuestionIdsSet.has(qid);
      const needsEntry = !!award.needs_entry;
      const saved = awardEntryByQuestion[String(qid)] || awardEntryByQuestion[qid] || '';
      const tr = document.createElement('tr');
      tr.setAttribute('data-question-id', String(qid));
      const entryHtml = needsEntry
        ? `<div class="choice-entry-wrap mt-2${isChecked ? '' : ' d-none'}">
             <label class="form-label small mb-1" for="entry${qid}">${award.entry_label || 'Product name'}</label>
             <input type="text" class="form-control form-control-sm choice-entry-input" id="entry${qid}"
                    data-question-id="${qid}" maxlength="180" value="${String(saved).replace(/"/g, '&quot;')}"
                    placeholder="e.g. Halo-halo" ${isChecked ? '' : 'disabled'}>
           </div>`
        : '';

      tr.innerHTML = `
        <td class="text-center">
          <input class="form-check-input question-checkbox" type="checkbox"
                 data-question-id="${qid}" id="q${qid}"
                 ${isChecked ? 'checked' : ''}>
        </td>
        <td>
          <label class="form-check-label" for="q${qid}">
            ${award.question_name}
          </label>
          ${award.category_name ? `<div class="text-muted small">${award.category_name}</div>` : ''}
          ${entryHtml}
        </td>
      `;

      container.appendChild(tr);
    });
  });

  updateEstablishmentTypeNotice(hasAwards, removedCount);
}

if (questionCheckboxContainer) {
  questionCheckboxContainer.addEventListener('change', (e) => {
    const el = e.target;
    if (!el || !el.classList || !el.classList.contains('question-checkbox')) return;

    const id = Number(el.dataset.questionId || el.value);
    if (Number.isNaN(id)) return;

    if (el.checked) selectedQuestionIdsSet.add(id);
    else selectedQuestionIdsSet.delete(id);
    const wrap = el.closest('tr')?.querySelector('.choice-entry-wrap');
    const input = wrap?.querySelector('.choice-entry-input');
    if (wrap && input) {
      wrap.classList.toggle('d-none', !el.checked);
      input.disabled = !el.checked;
      if (el.checked) input.focus();
    }
  });
}

function loadQuestionsCheckboxes(selected = [], typeIds = null) {
  const container = questionCheckboxContainer;
  if (!container) return;

  const initialSelected = Array.isArray(selected) ? selected.map(Number) : [];
  selectedQuestionIdsSet = new Set(initialSelected);
  const beforeSelected = new Set(selectedQuestionIdsSet);
  const typeSelectionVisible =
    establishmentTypeGroupEl &&
    !establishmentTypeGroupEl.classList.contains('d-none') &&
    establishmentTypes.length > 0;

  const rawTypes = typeIds ?? selectedEstablishmentTypeIds;
  const normalizedTypes = (Array.isArray(rawTypes) ? rawTypes : [rawTypes])
    .map(normalizeTypeId)
    .filter(v => v !== null);

  if (typeSelectionVisible && normalizedTypes.length === 0) {
    questionsByCategory = [];
    selectedQuestionIdsSet.clear();
    container.innerHTML =
      '<tr><td colspan="2" class="text-center text-muted">Select at least one nature of business to see awards.</td></tr>';
    updateEstablishmentTypeNotice(false, 0);
    return;
  }

  container.innerHTML = '<tr><td colspan="2" class="text-center text-muted">Loading awards...</td></tr>';

  const payload = { action: 'loadAll' };
  if (normalizedTypes.length) payload.establishment_type_ids = normalizedTypes;

  fetch('choice.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  })
    .then(res => res.json())
    .then(result => {
      if (result.status !== 'success') {
        questionsByCategory = [];
        container.innerHTML = `<tr><td colspan="2" class="text-center text-danger">${result.message || 'Failed to load awards.'}</td></tr>`;
        updateEstablishmentTypeNotice(false);
        return;
      }

      const items = Array.isArray(result.data) ? result.data : [];
      questionsByCategory = groupQuestionsByCategory(items);

      let removedCount = 0;
      if (selectedQuestionIdsSet.size) {
        const availableIds = new Set();
        questionsByCategory.forEach(cat => cat.awards.forEach(a => availableIds.add(a.question_id)));
        selectedQuestionIdsSet = new Set([...selectedQuestionIdsSet].filter(id => availableIds.has(id)));
        removedCount = beforeSelected.size - selectedQuestionIdsSet.size;
      }

      renderQuestionCheckboxes(removedCount);
    })
    .catch(() => {
      questionsByCategory = [];
      container.innerHTML = '<tr><td colspan="2" class="text-center text-danger">Failed to load awards.</td></tr>';
      updateEstablishmentTypeNotice(false);
    });
}

function destroyChoicesDataTable() {
  if (typeof window.jQuery === 'undefined' || !$.fn.DataTable) {
    return;
  }
  const $table = $('#datatablesSimple');
  if (!$.fn.DataTable.isDataTable($table)) {
    return;
  }
  const dt = $table.DataTable();
  dt.clear();
  dt.destroy(false);
  // Drop responsive/column-sizing classes DataTables may leave after destroy
  $table.find('tr.child').remove();
  $table.removeClass('dtr-inline collapsed');
}

function loadChoices() {
  fetch('choice.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'loadAllChoices' })
  })
    .then(res => res.json())
    .then(result => {
      const table    = typeof window.jQuery !== 'undefined' ? $('#datatablesSimple') : null;
      const tableBody = document.getElementById('tableBody');

      destroyChoicesDataTable();
      tableBody.innerHTML = '';

      if (result.status === 'success') {
        choicesCache = Array.isArray(result.data) ? result.data : [];

        result.data.forEach(choice => {
          const row = document.createElement('tr');
          row.setAttribute('data-id', choice.choice_id);
          row.setAttribute('data-name', choice.choice_name);
          row.setAttribute('data-email', choice.email || '');
          row.setAttribute('data-type-id', choice.establishment_type_id ?? '');
          row.setAttribute('data-type-ids', JSON.stringify(choice.establishment_type_ids || []));
          row.setAttribute('data-type-name', choice.establishment_type_name || '');

          const typeIds = Array.isArray(choice.establishment_type_ids)
            ? choice.establishment_type_ids
            : (normalizeTypeId(choice.establishment_type_id) !== null ? [normalizeTypeId(choice.establishment_type_id)] : []);
          const typeLabel = choice.establishment_type_name
            || (typeIds.length ? typeIds.map(id => getEstablishmentTypeName(id) || ('Type ' + id)).join(', ') : '—');

          const voteUrl = choice.vote_url || '';
          const voteCell = voteUrl
            ? `<div class="d-flex flex-column gap-1 vote-link-cell">
                <code class="small text-break vote-url-text" title="${escapeHtmlAttr(voteUrl)}">${escapeHtmlSimple(voteUrl)}</code>
                <button type="button" class="btn btn-outline-success btn-sm copyVoteBtn align-self-start" data-vote-url="${escapeHtmlAttr(voteUrl)}" title="Copy voting link (same URL encoded in QR)"><i class="bi bi-link-45deg"></i> Copy</button>
              </div>`
            : '<span class="text-muted small">—</span>';

          const onBallot = Number(choice.on_ballot ?? 1) === 1;
          const emailBlocked = !onBallot;
          const emailAlreadySent = Number(choice.qr_sent) === 1;
          const emailDisabled = emailBlocked || emailAlreadySent;
          const emailTitle = emailBlocked
            ? 'Confirm for public voting before sending the QR email'
            : (emailAlreadySent ? 'Already sent' : '');
          const emailBtnClass = emailAlreadySent
            ? 'btn-success disabled'
            : (emailBlocked ? 'btn-outline-secondary disabled' : 'btn-outline-primary');
          const emailBtnLabel = emailAlreadySent ? ' Sent' : ' Send Email';
          const emailBtnIcon = emailAlreadySent ? 'bi-check-circle-fill' : 'bi-envelope';

          row.innerHTML = `
            <td>${choice.choice_name}</td>
            <td>${choice.email || ''}</td>
            <td class="vote-cell">${voteCell}</td>
            <td>${typeLabel}</td>
            <td>
              <div class="form-check form-switch">
                <input class="form-check-input status-switch" type="checkbox" data-id="${choice.choice_id}" ${choice.status == 1 ? 'checked' : ''}>
                <span class="form-check-label status-label">${choice.status == 1 ? 'Active' : 'Inactive'}</span>
              </div>
              ${!onBallot && Number(choice.status) === 1
                ? `<button type="button" class="btn btn-sm btn-outline-primary mt-2 releaseBallotBtn" data-id="${choice.choice_id}">Confirm for voting</button>`
                : ''}
            </td>
            <td class="actions">
              <div class="admin-table-actions" role="group">
                <button type="button" class="btn btn-sm btn-edit editBtn">Edit</button>
                <button type="button" class="btn btn-sm btn-danger deleteBtn">Delete</button>
                <button type="button" class="btn btn-outline-info btn-sm mediaBtn"
                        data-id="${choice.choice_id}"
                        data-name="${choice.choice_name}">
                  <i class="bi bi-images"></i> Media
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm generateQRBtn" data-id="${choice.choice_id}">
                  <i class="bi bi-qr-code"></i> QR
                </button>
                <button type="button" class="btn btn-sm sendEmailBtn ${emailBtnClass}"
                        data-id="${choice.choice_id}"
                        data-name="${choice.choice_name}"
                        data-email="${choice.email ?? ''}"
                        data-vote-url="${choice.vote_url ?? ''}"
                        data-filename="${choice.choice_name.toLowerCase().replace(/\W+/g, '_')}.png"
                        data-on-ballot="${onBallot ? '1' : '0'}"
                        ${emailDisabled ? `disabled title="${emailTitle}"` : ''}>
                  <i class="bi ${emailBtnIcon}"></i>${emailBtnLabel}
                </button>
              </div>
            </td>`;

          const frameOptions = choice.qr_frame_options ?? choice.frame_options ?? null;
          const framePath = choice.qr_frame_path ?? choice.frame_path ?? (frameOptions && (frameOptions.frame_path ?? frameOptions.path)) ?? '';

          const generateBtn = row.querySelector('.generateQRBtn');
          if (generateBtn) {
            if (framePath) generateBtn.dataset.framePath = framePath;
            if (frameOptions) {
              try {
                const serialized = typeof frameOptions === 'string' ? frameOptions : JSON.stringify(frameOptions);
                if (serialized) generateBtn.dataset.frameConfig = serialized;
              } catch (err) {
                console.warn('Failed to serialize frame options for choice', choice.choice_id, err);
              }
            }

            const datasetMap = [
              ['qr_frame_use', 'frameUse'],
              ['frame_use', 'frameUse'],
              ['use_frame', 'frameUse'],
              ['qr_frame_box_x', 'frameBoxX'],
              ['frame_box_x', 'frameBoxX'],
              ['qr_frame_box_y', 'frameBoxY'],
              ['frame_box_y', 'frameBoxY'],
              ['qr_frame_box_w', 'frameBoxW'],
              ['frame_box_w', 'frameBoxW'],
              ['qr_frame_box_h', 'frameBoxH'],
              ['frame_box_h', 'frameBoxH'],
              ['qr_card_side_pad', 'cardSidePad'],
              ['card_side_pad', 'cardSidePad'],
              ['qr_card_top_pad', 'cardTopPad'],
              ['card_top_pad', 'cardTopPad'],
              ['qr_label_strip_h_min', 'labelStripHMin'],
              ['label_strip_h_min', 'labelStripHMin'],
              ['qr_label_strip_h_max', 'labelStripHMax'],
              ['label_strip_h_max', 'labelStripHMax'],
              ['qr_gap_qr_to_label', 'gapQrToLabel'],
              ['gap_qr_to_label', 'gapQrToLabel'],
              ['qr_side_min', 'qrSideMin'],
              ['qr_label_side_pad', 'labelSidePad'],
              ['label_side_pad', 'labelSidePad'],
              ['qr_label_top_pad', 'labelTopPad'],
              ['label_top_pad', 'labelTopPad'],
              ['qr_label_bottom_pad', 'labelBottomPad'],
              ['label_bottom_pad', 'labelBottomPad'],
              ['qr_label_line_spacing', 'labelLineSpacing'],
              ['label_line_spacing', 'labelLineSpacing'],
              ['qr_label_font_size', 'labelFontSize'],
              ['label_font_size', 'labelFontSize'],
              ['caption_box_x', 'captionBoxX'],
              ['caption_box_y', 'captionBoxY'],
              ['caption_box_w', 'captionBoxW'],
              ['caption_box_h', 'captionBoxH'],
              ['caption_font_size', 'captionFontSize']
            ];

            datasetMap.forEach(([sourceKey, dataKey]) => {
              const value = choice[sourceKey];
              if (value !== undefined && value !== null && value !== '') {
                generateBtn.dataset[dataKey] = value;
              }
            });
            syncFrameDataset(generateBtn.dataset, generateBtn);
          }

          tableBody.appendChild(row);
        });

        if (table && typeof window.jQuery !== 'undefined' && $.fn.DataTable) {
          table.DataTable({
            pageLength: 5,
            lengthMenu: [5, 10, 25, 50],
            lengthChange: true,
            searching: true,
            ordering: false,
            info: true,
            responsive: false,
            autoWidth: false,
            columnDefs: [
              { orderable: false, targets: 5 },
              { className: 'actions text-nowrap', targets: 5 }
            ],
            language: { emptyTable: 'No businesses found for this event.' }
          });
        }
      } else if (result.status !== 'success') {
        showInfoToast(result.message || 'Could not load establishments.', false);
      }
    })
    .catch(() => {
      showInfoToast('Failed to load establishments.', false);
    });
}

document.getElementById('addRowBtn')?.addEventListener('click', async () => {
  await ensureEstablishmentTypesLoaded(true);

  rowToEdit = null;
  nameInputEl.value = '';
  emailInputEl.value = '';
  awardEntryByQuestion = {};
  resetFormValidation();
  document.getElementById('editModalLabel').textContent = 'Add Business';

  setSelectedEstablishmentTypes([]);

  loadQuestionsCheckboxes([], []);
  bootstrap.Modal.getOrCreateInstance(document.getElementById('editModal')).show();
});

document.getElementById('saveChangesBtn')?.addEventListener('click', () => {
  resetFormValidation();

  const name = nameInputEl.value.trim();
  const email = emailInputEl.value.trim();
  const selectedQuestionIds = Array.from(selectedQuestionIdsSet);
  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

  let hasError = false;
  const msgs = [];

  if (!name) { nameInputEl.classList.add('is-invalid'); nameFeedbackEl && (nameFeedbackEl.textContent = 'Business name is required.'); hasError = true; msgs.push('Business name is required.'); }
  if (!email) { emailInputEl.classList.add('is-invalid'); emailFeedbackEl && (emailFeedbackEl.textContent = 'Business email is required.'); hasError = true; msgs.push('Business email is required.'); }
  else if (!emailRegex.test(email)) { emailInputEl.classList.add('is-invalid'); emailFeedbackEl && (emailFeedbackEl.textContent = 'Enter a valid business email.'); hasError = true; msgs.push('Enter a valid business email.'); }

  const typeVisible = !establishmentTypeGroupEl.classList.contains('d-none') && establishmentTypes.length > 0;
  if (typeVisible && selectedEstablishmentTypeIds.length === 0) {
    establishmentTypeGroupEl?.classList.add('is-invalid');
    establishmentTypeFeedbackEl?.classList.remove('d-none');
    hasError = true; msgs.push('Please select at least one nature of business.');
  }
  if (selectedQuestionIds.length === 0) { hasError = true; msgs.push('Please select at least one award.'); }

  collectAwardEntryInputs();
  const awardEntries = {};
  questionsByCategory.forEach((cat) => {
    (cat.awards || []).forEach((award) => {
      const qid = Number(award.question_id);
      if (!selectedQuestionIdsSet.has(qid) || !award.needs_entry) return;
      const value = String(awardEntryByQuestion[String(qid)] || awardEntryByQuestion[qid] || '').trim();
      if (!value) {
        hasError = true;
        msgs.push(`Enter a ${(award.entry_label || 'product name').toLowerCase()} for ${award.question_name}.`);
      } else {
        awardEntries[qid] = value;
      }
    });
  });

  if (hasError) {
    const text = [...new Set(msgs)].join('\n') || 'Please fix the highlighted fields.';
    showInfoToast(text, false);
    return;
  }

  const payload = {
    action: rowToEdit ? 'update' : 'create',
    choice_name: name,
    email: email,
    question_ids: selectedQuestionIds,
    award_entries: awardEntries,
    establishment_type_ids: typeVisible ? selectedEstablishmentTypeIds : []
  };
  if (rowToEdit) payload.choice_id = parseInt(rowToEdit.choice_id, 10);

  fetch('choice.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
    .then(res => res.json())
    .then(result => {
      if (result.status === 'success') {
        showInfoToast(rowToEdit ? 'Business updated successfully!' : 'Business added successfully!', true);
        loadChoices();
        bootstrap.Modal.getInstance(document.getElementById('editModal')).hide();
      } else if (result.status === 'duplicate') {
        nameInputEl.classList.add('is-invalid');
        nameFeedbackEl && (nameFeedbackEl.textContent = 'This establishment already exists.');
        showInfoToast('This establishment already exists.', false);
      } else {
        showInfoToast(result.message || 'Error saving establishment.', false);
      }
    });
});

document.getElementById('tableBody')?.addEventListener('click', async (e) => {
  const editBtn = e.target.closest('.editBtn');
  if (editBtn) {
    const row = editBtn.closest('tr');
    const choiceId = row?.getAttribute('data-id');
    if (!row || !choiceId) return;

    await ensureEstablishmentTypesLoaded(true);

    rowToEdit = { choice_id: choiceId };
    nameInputEl.value  = row.getAttribute('data-name') || '';
    emailInputEl.value = row.getAttribute('data-email') || '';
    document.getElementById('editModalLabel').textContent = 'Edit Business';
    resetFormValidation();

    if (!establishmentTypeGroupEl.classList.contains('d-none') && establishmentTypes.length > 0) {
      let rowTypeIds = [];
      try {
        rowTypeIds = JSON.parse(row.getAttribute('data-type-ids') || '[]');
      } catch (_) {
        rowTypeIds = [];
      }
      if (!Array.isArray(rowTypeIds) || !rowTypeIds.length) {
        const single = row.getAttribute('data-type-id');
        if (single) rowTypeIds = [single];
      }
      setSelectedEstablishmentTypes(rowTypeIds);
    } else {
      setSelectedEstablishmentTypes([]);
    }

    fetch('choice.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'getLinkedQuestions', choice_id: parseInt(choiceId, 10) })
    })
      .then(res => res.json())
      .then(result => {
        awardEntryByQuestion = result.award_entries && typeof result.award_entries === 'object'
          ? Object.fromEntries(Object.entries(result.award_entries).map(([k, v]) => [String(k), String(v || '')]))
          : {};
        loadQuestionsCheckboxes(result.data || [], selectedEstablishmentTypeIds);
        bootstrap.Modal.getOrCreateInstance(document.getElementById('editModal')).show();
      });
    return;
  }

  const deleteBtn = e.target.closest('.deleteBtn');
  if (deleteBtn) {
    const row = deleteBtn.closest('tr');
    const choiceId = row?.getAttribute('data-id');
    if (!row || !choiceId) return;

    const establishmentName = row.getAttribute('data-name') || 'this establishment';
    const runDelete = () => {
      fetch('choice.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete', ids: [parseInt(choiceId, 10)] })
      })
        .then(res => res.json())
        .then(result => {
          if (result.status === 'success') {
            loadChoices();
            showInfoToast('Business deleted successfully!', true);
          } else {
            showInfoToast(result.message || 'Failed to delete establishment.', false);
          }
        })
        .catch(() => showInfoToast('Failed to delete establishment.', false));
    };

    const confirmed = await confirmAction({
      title: 'Delete establishment',
      message: `Are you sure you want to delete "${establishmentName}"? This action cannot be undone.`,
      confirmLabel: 'Delete',
      confirmClass: 'btn-danger',
    });
    if (confirmed) {
      runDelete();
    }
  }
});

document.getElementById('tableBody')?.addEventListener('change', e => {
  if (!e.target.classList.contains('status-switch')) return;
  const toggle = e.target;
  const id = toggle.getAttribute('data-id');
  const newStatus = toggle.checked ? 1 : 0;
  const labelEl = toggle.closest('.form-switch')?.querySelector('.status-label');
  const row = toggle.closest('tr');
  const name = row?.getAttribute('data-name') || 'this establishment';

  const revertToggle = () => {
    toggle.checked = !toggle.checked;
    if (labelEl) {
      labelEl.textContent = toggle.checked ? 'Active' : 'Inactive';
    }
  };

  const applyStatus = () => {
    fetch('choice.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'toggleStatus', choice_id: parseInt(id, 10), status: newStatus })
    })
      .then(res => res.json())
      .then(result => {
        if (result.status === 'success') {
          if (labelEl) {
            labelEl.textContent = newStatus === 1 ? 'Active' : 'Inactive';
          }
          showSuccessToast(newStatus === 1 ? 'Business activated successfully.' : 'Business deactivated successfully.');
        } else {
          revertToggle();
          showInfoToast('Failed to update status.', false);
        }
      })
      .catch(err => {
        console.error('Error updating status:', err);
        revertToggle();
        showInfoToast('Something went wrong while updating status.', false);
      });
  };

  const message =
    newStatus === 1
      ? `"${name}" will become visible to voters. Activate this establishment?`
      : `"${name}" will no longer be visible to voters. Deactivate this establishment?`;

  confirmAction({
    title: newStatus === 1 ? 'Activate establishment' : 'Deactivate establishment',
    message,
    confirmLabel: newStatus === 1 ? 'Activate' : 'Deactivate',
    confirmClass: newStatus === 1 ? 'btn-primary' : 'btn-warning',
  }).then((confirmed) => {
    if (confirmed) {
      applyStatus();
    } else {
      revertToggle();
    }
  });
});
document.body.addEventListener("click", function (e) {
  const btn = e.target.closest(".generateQRBtn");
  if (!btn) return;
  const choiceId = btn.getAttribute("data-id");
  if (!choiceId) { alert("Missing choice ID"); return; }
  const params = new URLSearchParams({ choice_id: choiceId, force: '1' });
  const qrBtn = btn;
  const prevLabel = qrBtn.innerHTML;
  qrBtn.disabled = true;
  qrBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Loading…';

  fetch(`generate_save_qr.php?${params.toString()}`, { cache: 'no-store' })
    .then(res => res.json())
    .then(data => {
      if (data.status === 'success' || data.status === 'skipped') {
        const qrImg  = document.getElementById('qrImg');
        const saveBtn = document.getElementById('saveQRBtn');

        if (qrImg)  qrImg.src = data.path + '?cb=' + Date.now();
        if (saveBtn) {
          saveBtn.setAttribute('data-id', choiceId);
          saveBtn.setAttribute('data-name', data.name);
        }

        qrPreviewChoiceId = Number(choiceId);
        loadQrFrameDefaults();

        bootstrap.Modal
          .getOrCreateInstance(document.getElementById('qrModal'))
          .show();

        showInfoToast(
          data.status === 'success'
            ? `QR code generated for "${data.name}"`
            : `Showing existing QR for "${data.name}"`,
          true
        );
      } else {
        alert(data.message || 'Error generating QR code');
      }
    })
    .catch(err => {
      console.error('Generate QR error:', err);
      showInfoToast('Error generating QR code.', false);
    })
    .finally(() => {
      qrBtn.disabled = false;
      qrBtn.innerHTML = prevLabel;
    });
});

document.getElementById("regenerateQRBtn")?.addEventListener("click", async () => {
  if (qrModalBusy) return;
  const saveBtn = document.getElementById("saveQRBtn");
  const regBtn = regenerateQrBtnEl;
  const choiceId = saveBtn?.getAttribute("data-id");
  if (!choiceId || !regBtn) return;

  const params = new URLSearchParams({ choice_id: choiceId, force: 1 });

  const idleRegHtml = regBtn.innerHTML;
  setQrModalBusy(true, {
    showOverlay: true,
    hint: 'Regenerating poster… This may take a few seconds. Please wait.',
  });
  setButtonLoading(
    regBtn,
    true,
    '<span class="spinner-border spinner-border-sm me-1"></span> Regenerating…',
    idleRegHtml
  );

  try {
    const res = await fetch(`generate_save_qr.php?${params.toString()}`, { cache: 'no-store' });
    const data = await res.json();
    if (data.status === 'success') {
      const qrImg = document.getElementById('qrImg');
      if (qrImg) qrImg.src = data.path + '?cb=' + Date.now();
      qrPreviewChoiceId = Number(choiceId);
      showInfoToast(`QR code regenerated for "${data.name}". You can send the QR email again.`, true);
      if (typeof loadChoices === 'function') loadChoices();
    } else {
      alert(data.message || 'Failed to regenerate QR.');
    }
  } catch (err) {
    console.error('Regenerate QR failed:', err);
    showInfoToast('QR regeneration error', false);
  } finally {
    setQrModalBusy(false);
    setButtonLoading(regBtn, false, '', idleRegHtml);
  }
});

document.body.addEventListener('click', function (e) {
  const btn = e.target.closest('.saveQRBtn');
  if (!btn) return;
  const name = btn.getAttribute('data-name') || 'qr';
  const choiceId = btn.getAttribute('data-id') || '';
  const filename = name.toLowerCase().replace(/\W+/g, '_') + ".png";
  const filePath = "qrcodes/" + filename;

  const link = document.createElement('a');
  link.href = filePath + "?cb=" + Date.now();
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);

  if (choiceId) {
    const body = new URLSearchParams();
    body.set('choice_id', choiceId);
    body.set('action', 'export');
    fetch('log_qr_audit.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
      credentials: 'same-origin',
    }).catch((err) => console.warn('QR download audit failed:', err));
  }
});

document.body.addEventListener("click", async function (e) {
  const releaseBtn = e.target.closest(".releaseBallotBtn");
  if (releaseBtn) {
    e.preventDefault();
    const choiceId = Number(releaseBtn.getAttribute("data-id"));
    if (!choiceId) return;
    if (!window.confirm('Confirm this business for public voting? This emails the QR code and voting link to the business.')) {
      return;
    }
    const original = releaseBtn.innerHTML;
    releaseBtn.disabled = true;
    releaseBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Confirming…';
    try {
      const { res, data } = await fetchQrEmailJson('choice.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ action: 'releaseToBallot', choice_id: choiceId }),
        credentials: 'same-origin',
      });
      if (!res.ok || data.status !== 'success') {
        showInfoToast(data.message || 'Could not confirm this business for voting.', false);
        loadChoices();
        releaseBtn.disabled = false;
        releaseBtn.innerHTML = original;
        return;
      }
      showInfoToast(data.message || 'This business is now on the public ballot.', true);
      loadChoices();
    } catch (err) {
      showInfoToast(err.message || 'Could not confirm this business for voting.', false);
      loadChoices();
      releaseBtn.disabled = false;
      releaseBtn.innerHTML = original;
    }
    return;
  }

  const btn = e.target.closest(".sendEmailBtn");
  if (!btn || btn.disabled || btn.classList.contains('disabled')) return;

  if (btn.getAttribute('data-on-ballot') === '0') {
    showInfoToast('Confirm this business for public voting before sending the QR email.', false);
    return;
  }

  const email    = btn.getAttribute("data-email") ?? "";
  const name     = btn.getAttribute("data-name")  ?? "Business";
  const choiceId = btn.getAttribute("data-id");
  const filename = btn.getAttribute("data-filename") ?? "";

  if (!email) { showInfoToast(`No email address available for "${name}".`, false); return; }
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { showInfoToast(`Invalid email format: ${email}`, false); return; }

  const hasQr = await checkQrExists(filename);
  if (!hasQr) { showInfoToast(`No QR code found for "${name}". Please generate the QR first.`, false); return; }

  currentEmailName = name;
  currentQrFilename = filename;
  currentVoteUrl = btn.getAttribute("data-vote-url") ?? "";

  document.getElementById("emailChoiceId").value = choiceId;
  document.getElementById("emailTo").value = email;
  document.getElementById("emailSubject").value = "Your QR Code for Tatak Ormoc Voting";
  document.getElementById("emailMessage").value = QR_EMAIL_DEFAULT_MESSAGE;
  renderQrEmailPreview("emailPreview", name, QR_EMAIL_DEFAULT_MESSAGE);

  const modalEl = document.getElementById("sendEmailModal");
  const bsModal  = bootstrap.Modal.getOrCreateInstance(modalEl);

  modalEl.addEventListener("shown.bs.modal", () => {
    const msgEl = document.getElementById("emailMessage");
    msgEl.style.height = "auto";
    msgEl.style.height = msgEl.scrollHeight + "px";
    msgEl.addEventListener("input", () => {
      msgEl.style.height = "auto";
      msgEl.style.height = msgEl.scrollHeight + "px";
      renderQrEmailPreview("emailPreview", currentEmailName, msgEl.value);
    });
  }, { once: true });

  bsModal.show();
});

document.addEventListener("DOMContentLoaded", () => {
  const emailForm = document.getElementById("customEmailForm");
  if (!emailForm) return;
  emailForm.addEventListener("submit", async function (e) {
    e.preventDefault();
    const emailToEl = document.getElementById("emailTo");
    const emailMessageEl = document.getElementById("emailMessage");
    const choiceIdEl = document.getElementById("emailChoiceId");
    const subjectEl = document.getElementById("emailSubject");
    const email = emailToEl.value.trim();
    const message = emailMessageEl.value.trim();
    const choiceId = choiceIdEl.value;
    const subject = subjectEl.value.trim();
    const name = currentEmailName || "Business";
    if (!email || !message || !choiceId || !subject) { showInfoToast("All fields are required.", false); return; }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { showInfoToast(`Invalid email format: ${email}`, false); return; }
    const sendBtn = document.getElementById("sendEmailSubmitBtn");
    if (!sendBtn) {
      showInfoToast("Send button not found. Please reload the page.", false);
      return;
    }
    const originalText = sendBtn.innerHTML;
    sendBtn.innerHTML = `<span class="spinner-border spinner-border-sm me-2"></span>Sending...`;
    sendBtn.disabled = true;

    const sendOnce = async (payload) => {
      const { res, data } = await fetchQrEmailJson('send_email.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      if (!res.ok && data.status !== 'error') {
        throw new Error(data.message || `HTTP ${res.status}`);
      }
      return data;
    };

    try {
      const payload = { choice_id: choiceId, email, name, message, subject, qr_path: currentQrFilename || "" };
      let data = await sendOnce(payload);

      if (data.status === "success") {
        showInfoToast("Email sent successfully!", true);
        bootstrap.Modal.getInstance(document.getElementById("sendEmailModal")).hide();
        const rowBtn = document.querySelector(`.sendEmailBtn[data-id="${choiceId}"]`);
        if (rowBtn) {
          rowBtn.classList.remove('btn-outline-primary');
          rowBtn.classList.add('btn-success', 'disabled');
          rowBtn.innerHTML = `<i class="bi bi-check-circle-fill"></i> Sent Email`;
        }
        loadChoices();
        return;
      }

      if (data.error_code === "QR_NOT_FOUND") {
        const proceed = await confirmAction({
          title: 'QR code required',
          message: 'No QR found for this business. Generate it now?',
          confirmLabel: 'Generate QR',
          confirmClass: 'btn-primary',
        });
        if (!proceed) {
          showInfoToast("Please generate the QR first, then try again.", false);
        } else {
          const params = new URLSearchParams({ choice_id: choiceId, force: '1' });
          const genRes = await fetch(`generate_save_qr.php?${params.toString()}`);
          const genData = await genRes.json();
          if (genData.status === "success" || genData.status === "skipped") {
            if (genData.path) {
              currentQrFilename = genData.path.split('/').pop();
              payload.qr_path = currentQrFilename;
            }
            const retry = await sendOnce(payload);
            if (retry.status === "success") {
              showInfoToast("QR generated and email sent!", true);
              bootstrap.Modal.getInstance(document.getElementById("sendEmailModal")).hide();
              const rowBtn = document.querySelector(`.sendEmailBtn[data-id="${choiceId}"]`);
              if (rowBtn) {
                rowBtn.classList.remove('btn-outline-primary');
                rowBtn.classList.add('btn-success', 'disabled');
                rowBtn.innerHTML = `<i class="bi bi-check-circle-fill"></i> Sent Email`;
              }
              loadChoices();
            } else {
              showInfoToast(retry.message || "Failed to send email after generating QR.", false);
            }
          } else {
            showInfoToast(genData.message || "Failed to generate QR.", false);
          }
        }
      } else {
        showInfoToast(data.message || "Failed to send email.", false);
      }
    } catch (err) {
      console.error("Email send failed:", err);
      showInfoToast(qrEmailFetchError(err) || "Error occurred while sending email.", false);
    } finally {
      if (sendBtn) {
        sendBtn.innerHTML = originalText;
        sendBtn.disabled = false;
      }
    }
  });
});
function collectUnsentChoiceIds() {
  const dt = $.fn.DataTable.isDataTable('#datatablesSimple')
    ? $('#datatablesSimple').DataTable()
    : null;

  const nodes = dt ? Array.from(dt.rows({ search: 'applied' }).nodes())
                   : Array.from(document.querySelectorAll('#tableBody tr'));

  const ids = [];
  nodes.forEach(tr => {
    const btn = tr.querySelector('.sendEmailBtn');
    if (!btn || btn.classList.contains('disabled')) return;
    const id = Number(btn.getAttribute('data-id'));
    if (id) ids.push(id);
  });
  return ids;
}
function collectUnsentRowsInfo() {
  const dt = $.fn.DataTable.isDataTable('#datatablesSimple')
    ? $('#datatablesSimple').DataTable()
    : null;

  const nodes = dt ? Array.from(dt.rows({ search: 'applied' }).nodes())
                   : Array.from(document.querySelectorAll('#tableBody tr'));

  const items = [];
  nodes.forEach(tr => {
    const btn = tr.querySelector('.sendEmailBtn');
    if (!btn || btn.classList.contains('disabled')) return;
    items.push({
      id: Number(btn.getAttribute('data-id')) || null,
      name: btn.getAttribute('data-name') || tr.getAttribute('data-name') || 'Business',
      email: btn.getAttribute('data-email') || tr.getAttribute('data-email') || '',
      filename: btn.getAttribute('data-filename') || ''
    });
  });
  return items;
}

const SEND_ALL_CHUNK_SIZE = 3;
const QR_EMAIL_FETCH_MS = 120000;

function qrEmailFetchError(err, res) {
  if (err?.name === 'AbortError') {
    return 'Request timed out. Wait a few seconds, then refresh — the QR email may already have been sent.';
  }
  const msg = String(err?.message || '');
  if (/Failed to fetch|NetworkError|Load failed/i.test(msg)) {
    return 'Network error while sending. Refresh and check whether the QR email already went out.';
  }
  if (res && (res.status === 502 || res.status === 504 || res.status === 524)) {
    return 'The server took too long. Refresh — the QR email may already have been sent.';
  }
  return msg || 'Unknown error while sending the QR email.';
}

async function fetchQrEmailJson(url, options = {}, timeoutMs = QR_EMAIL_FETCH_MS) {
  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), timeoutMs);
  let res;
  try {
    res = await fetch(url, { ...options, signal: ctrl.signal, cache: options.cache || 'no-store' });
    const text = await res.text();
    let data;
    try {
      data = JSON.parse((text || '').trim());
    } catch (parseErr) {
      throw new Error(qrEmailFetchError(parseErr, res));
    }
    return { res, data };
  } catch (err) {
    throw new Error(qrEmailFetchError(err, res));
  } finally {
    clearTimeout(timer);
  }
}

function chunkArray(list, size) {
  const chunks = [];
  for (let i = 0; i < list.length; i += size) {
    chunks.push(list.slice(i, i + size));
  }
  return chunks;
}

function setSendAllProgress({ done, total, okCount, failCount, label }) {
  const wrap = document.getElementById('sendAllProgressWrap');
  const bar = document.getElementById('sendAllProgressBar');
  const countEl = document.getElementById('sendAllProgressCount');
  const labelEl = document.getElementById('sendAllProgressLabel');
  if (!wrap || !bar) return;
  wrap.classList.remove('d-none');
  const pct = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0;
  bar.style.width = `${pct}%`;
  bar.setAttribute('aria-valuenow', String(pct));
  bar.textContent = `${pct}%`;
  if (countEl) countEl.textContent = `${done} / ${total}`;
  if (labelEl) {
    labelEl.textContent = label || `Sending… (${okCount} sent, ${failCount} failed)`;
  }
}

function hideSendAllProgress() {
  const wrap = document.getElementById('sendAllProgressWrap');
  const bar = document.getElementById('sendAllProgressBar');
  if (wrap) wrap.classList.add('d-none');
  if (bar) {
    bar.classList.remove('progress-bar-animated');
    bar.style.width = '0%';
    bar.textContent = '0%';
  }
}

async function sendAllQrEmails(choiceIds, { subject, message, logFailure = true } = {}) {
  const payload = { choice_ids: choiceIds, subject: subject || '', message: message || '', log_failure: !!logFailure };
  const { res, data } = await fetchQrEmailJson('send_all_email.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
  if (!res.ok || data.status !== 'success') {
    throw new Error(data.message || `Request failed (${res.status})`);
  }
  return data;
}

async function sendAllQrEmailsBatched(choiceIds, { subject, message, logFailure = true, onProgress } = {}) {
  const chunks = chunkArray(choiceIds, SEND_ALL_CHUNK_SIZE);
  const aggregate = { status: 'success', ok: [], fail: [], ok_count: 0, fail_count: 0 };
  let done = 0;
  const total = choiceIds.length;

  setSendAllProgress({ done: 0, total, okCount: 0, failCount: 0, label: 'Starting batch send…' });

  for (let i = 0; i < chunks.length; i++) {
    const chunk = chunks[i];
    setSendAllProgress({
      done,
      total,
      okCount: aggregate.ok_count,
      failCount: aggregate.fail_count,
      label: `Sending batch ${i + 1} of ${chunks.length}…`
    });

    const result = await sendAllQrEmails(chunk, { subject, message, logFailure });
    aggregate.ok = aggregate.ok.concat(result.ok || []);
    aggregate.fail = aggregate.fail.concat(result.fail || []);
    aggregate.ok_count += Number(result.ok_count) || 0;
    aggregate.fail_count += Number(result.fail_count) || 0;
    done += chunk.length;

    if (onProgress) {
      onProgress({ done, total, ok_count: aggregate.ok_count, fail_count: aggregate.fail_count });
    }
    setSendAllProgress({
      done,
      total,
      okCount: aggregate.ok_count,
      failCount: aggregate.fail_count,
      label: done >= total ? 'Complete' : 'Sending…'
    });
  }

  return aggregate;
}
function markSentButtons(okArray) {
  okArray.forEach(({ choice_id }) => {
    const btn = document.querySelector(`.sendEmailBtn[data-id="${choice_id}"]`);
    if (!btn) return;
    btn.classList.remove('btn-outline-primary');
    btn.classList.add('btn-success', 'disabled');
    btn.innerHTML = `<i class="bi bi-check-circle-fill"></i> Sent Email`;
  });
}

document.getElementById("sendAllEmailsBtn")?.addEventListener("click", async () => {
  const items = collectUnsentRowsInfo();
  if (!items.length) { showInfoToast("No unsent businesses found.", false); return; }

  const checks = await Promise.all(items.map(it => it.filename ? checkQrExists(it.filename) : Promise.resolve(false)));
  const missing = items.filter((it, idx) => !checks[idx]);

  if (missing.length) {
    showInfoToast(`${missing.length} establishment(s) don't have a QR yet. Please generate their QR first.`, false);
    return;
  }

  bootstrap.Modal.getOrCreateInstance(document.getElementById("sendAllEmailModal")).show();
  const allMsg = document.getElementById("allEmailMessage");
  if (allMsg && !allMsg.dataset.previewBound) {
    allMsg.dataset.previewBound = "1";
    const refreshAllPreview = () => renderQrEmailPreview("allEmailPreview", "[Business name]", allMsg.value, "");
    allMsg.addEventListener("input", refreshAllPreview);
    refreshAllPreview();
  } else {
    renderQrEmailPreview("allEmailPreview", "[Business name]", allMsg?.value || QR_EMAIL_DEFAULT_MESSAGE, "");
  }
});

document.getElementById("confirmSendAllEmailsBtn")?.addEventListener("click", async () => {
  const subject = document.getElementById("allEmailSubject")?.value.trim();
  const message = document.getElementById("allEmailMessage")?.value.trim();
  if (!subject || !message) { showInfoToast("Please fill in both the subject and message.", false); return; }
  const ids = collectUnsentChoiceIds();
  if (!ids.length) { showInfoToast("No unsent businesses found.", false); return; }
  const sendConfirmed = await confirmAction({
    title: 'Send QR emails',
    message: `Send QR emails to ${ids.length} establishment(s) that haven't received them yet?`,
    confirmLabel: 'Send emails',
    confirmClass: 'btn-success',
  });
  if (!sendConfirmed) return;
  const btn = document.getElementById("confirmSendAllEmailsBtn");
  const cancelBtn = document.querySelector('#sendAllEmailModal .btn-secondary');
  const originalText = btn.innerHTML;
  btn.disabled = true;
  if (cancelBtn) cancelBtn.disabled = true;
  btn.innerHTML = `<span class="spinner-border spinner-border-sm me-2"></span>Sending...`;

  try {
    const result = await sendAllQrEmailsBatched(ids, {
      subject,
      message,
      logFailure: true,
      onProgress: ({ done, total, ok_count, fail_count }) => {
        setSendAllProgress({
          done,
          total,
          okCount: ok_count,
          failCount: fail_count,
          label: `Sending ${done} of ${total}…`
        });
      }
    });
    markSentButtons(result.ok || []);
    bootstrap.Modal.getInstance(document.getElementById("sendAllEmailModal"))?.hide();
    hideSendAllProgress();
    showInfoToast(`Sent: ${result.ok_count}, Failed: ${result.fail_count}`, result.fail_count === 0);
    loadChoices?.();
    try { window.dispatchEvent(new Event('comm:qr:refresh')); } catch {}
    try { localStorage.setItem('comm:qr:refresh', String(Date.now())); } catch {}
    if (result.fail_count > 0) console.warn('Failures:', result.fail);
  } catch (err) {
    console.error("Send All Error:", err);
    hideSendAllProgress();
    showInfoToast(err.message || "Batch send failed. Refresh and check which emails already went out.", false);
  } finally {
    btn.disabled = false;
    if (cancelBtn) cancelBtn.disabled = false;
    btn.innerHTML = originalText;
  }
});
async function generateSingleQrViaSaveEndpoint(choiceId, { force = false } = {}) {
  if (!choiceId) throw new Error('Missing choiceId');

  const params = new URLSearchParams({ choice_id: choiceId });
  if (force) params.set('force', '1');

  const res  = await fetch(`generate_save_qr.php?${params.toString()}`, { cache: 'no-store' });
  const data = await res.json().catch(() => ({ status: 'error', message: 'Invalid JSON' }));
  if (!res.ok) throw new Error(data.message || `HTTP ${res.status}`);
  return data;
}

function getAllChoiceIdsFromTable() {
  return Array.from(document.querySelectorAll('#tableBody tr[data-id]'))
    .map(tr => tr.getAttribute('data-id'))
    .filter(Boolean);
}

function getActiveChoiceIdsFromTable() {
  const dt = $.fn.DataTable.isDataTable('#datatablesSimple')
    ? $('#datatablesSimple').DataTable()
    : null;

  const nodes = dt
    ? dt.rows().nodes().toArray()      
    : Array.from(document.querySelectorAll('#tableBody tr[data-id]'));

  const ids = [];
  nodes.forEach(tr => {
    const switchEl = tr.querySelector('.status-switch');
    if (switchEl && !switchEl.checked) return; 
    const id = tr.getAttribute('data-id');
    if (id) ids.push(id);
  });
  return ids;
}

let bulkQrJobRunning = false;
let bulkQrJobCancelled = false;

function bulkQrProgressBarClass({ done, total, label, mode, failed = 0 }) {
  const stopped = label === 'Stopped' || label === 'Stopping…';
  const complete = !stopped && total > 0 && done >= total && label === 'Complete';

  if (stopped) {
    return { color: 'bg-secondary', striped: false, animated: false };
  }
  if (complete && !stopped) {
    return {
      color: failed > 0 ? 'bg-warning' : 'bg-success',
      striped: false,
      animated: false,
    };
  }
  return {
    color: mode === 'regenerate' ? 'bg-primary' : 'bg-success',
    striped: true,
    animated: true,
  };
}

function setBulkQrProgress({
  done,
  total,
  label,
  generated = 0,
  skipped = 0,
  failed = 0,
  regenerated = 0,
  mode = 'generate',
}) {
  const wrap = document.getElementById('bulkQrProgressBar');
  const countEl = document.getElementById('bulkQrProgressCount');
  const labelEl = document.getElementById('bulkQrProgressLabel');
  const statsEl = document.getElementById('bulkQrProgressStats');
  if (!wrap) return;

  const pct = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0;
  const bar = bulkQrProgressBarClass({ done, total, label, mode, failed });
  wrap.style.width = `${pct}%`;
  wrap.setAttribute('aria-valuenow', String(pct));
  wrap.textContent = `${pct}%`;
  wrap.className = 'progress-bar';
  if (bar.striped) wrap.classList.add('progress-bar-striped');
  if (bar.animated) wrap.classList.add('progress-bar-animated');
  wrap.classList.add(bar.color);

  if (countEl) countEl.textContent = `${done} / ${total}`;
  if (labelEl) labelEl.textContent = label || `Processing ${done} of ${total}…`;

  if (statsEl) {
    if (mode === 'regenerate') {
      statsEl.textContent = `Regenerated: ${regenerated} · Failed: ${failed} · Not processed: ${Math.max(0, total - done)}`;
    } else {
      statsEl.textContent = `Generated: ${generated} · Skipped (already had file): ${skipped} · Failed: ${failed} · Not processed: ${Math.max(0, total - done)}`;
    }
  }
}

function finishBulkQrProgressModal({ cancelled, mode, generated, skipped, failed, regenerated, total, done }) {
  bulkQrJobRunning = false;
  const genBtn = document.getElementById('generateAllQRBtn');
  const regBtn = document.getElementById('regenerateAllQRBtn');
  [genBtn, regBtn].forEach((b) => {
    if (b) b.disabled = false;
  });
  const bar = document.getElementById('bulkQrProgressBar');
  const cancelBtn = document.getElementById('bulkQrCancelBtn');
  const closeBtn = document.getElementById('bulkQrCloseBtn');
  const hint = document.getElementById('bulkQrProgressHint');

  if (bar) {
    bar.classList.remove('progress-bar-animated', 'progress-bar-striped');
    bar.classList.remove('bg-primary', 'bg-success', 'bg-danger', 'bg-warning', 'bg-secondary');
    if (cancelled) {
      bar.classList.add('bg-secondary');
    } else if (failed > 0) {
      bar.classList.add('bg-warning');
    } else {
      bar.classList.add('bg-success');
    }
  }
  if (cancelBtn) cancelBtn.classList.add('d-none');
  if (closeBtn) closeBtn.classList.remove('d-none');
  if (hint) {
    hint.textContent = cancelled
      ? `Stopped after ${done} of ${total}. Run again to continue — Generate skips existing files; Regenerate overwrites.`
      : 'Finished. You may close this dialog.';
  }

  const ok = mode === 'regenerate' ? failed === 0 && !cancelled : failed === 0 && !cancelled;
  if (cancelled) {
    showInfoToast(
      mode === 'regenerate'
        ? `Stopped: ${regenerated} regenerated, ${failed} failed, ${total - done} not processed.`
        : `Stopped: ${generated} generated, ${skipped} skipped, ${failed} failed, ${total - done} not processed.`,
      false
    );
  } else if (mode === 'regenerate') {
    showInfoToast(
      `${regenerated} QR(s) regenerated${failed ? `, ${failed} failed` : ''}. You can send QR emails again.`,
      ok
    );
  } else {
    showInfoToast(
      `${generated} QR(s) generated, ${skipped} skipped${failed ? `, ${failed} failed` : ''}.`,
      ok
    );
  }
}

async function runBulkQrJob(ids, { force = false } = {}) {
  const mode = force ? 'regenerate' : 'generate';
  const modalEl = document.getElementById('bulkQrProgressModal');
  const titleEl = document.getElementById('bulkQrProgressTitle');
  const cancelBtn = document.getElementById('bulkQrCancelBtn');
  const closeBtn = document.getElementById('bulkQrCloseBtn');
  const hint = document.getElementById('bulkQrProgressHint');

  if (!modalEl || typeof bootstrap === 'undefined') {
    showInfoToast('Progress dialog is not available. Refresh the page.', false);
    return;
  }

  bulkQrJobRunning = true;
  bulkQrJobCancelled = false;

  const genBtn = document.getElementById('generateAllQRBtn');
  const regBtn = document.getElementById('regenerateAllQRBtn');
  [genBtn, regBtn].forEach((b) => {
    if (b) b.disabled = true;
  });

  if (titleEl) {
    titleEl.textContent = mode === 'regenerate' ? 'Regenerating QR codes' : 'Generating QR codes';
  }
  if (hint) {
    hint.textContent =
      'Keep this window open until the job finishes. You can stop after the current establishment completes.';
  }
  if (cancelBtn) cancelBtn.classList.remove('d-none');
  if (closeBtn) closeBtn.classList.add('d-none');

  setBulkQrProgress({
    done: 0,
    total: ids.length,
    label: 'Starting…',
    generated: 0,
    skipped: 0,
    failed: 0,
    regenerated: 0,
    mode,
  });

  const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
  modal.show();

  await loadQrFrameDefaults().catch(() => null);

  let generated = 0;
  let skipped = 0;
  let failed = 0;
  let regenerated = 0;
  let done = 0;

  for (let i = 0; i < ids.length; i++) {
    if (bulkQrJobCancelled) break;

    const id = ids[i];
    setBulkQrProgress({
      done,
      total: ids.length,
      label: `Processing establishment ${i + 1} of ${ids.length}…`,
      generated,
      skipped,
      failed,
      regenerated,
      mode,
    });

    try {
      const res = await generateSingleQrViaSaveEndpoint(id, { force });
      if (res.status === 'success') {
        if (force) regenerated++;
        else generated++;
      } else if (res.status === 'skipped') {
        skipped++;
      } else {
        failed++;
      }
    } catch (err) {
      console.error(`Bulk QR ${mode} failed for`, id, err);
      failed++;
    }

    done = i + 1;
    setBulkQrProgress({
      done,
      total: ids.length,
      label: bulkQrJobCancelled ? 'Stopping…' : `Completed ${done} of ${ids.length}…`,
      generated,
      skipped,
      failed,
      regenerated,
      mode,
    });

    await new Promise((resolve) => setTimeout(resolve, 0));
  }

  setBulkQrProgress({
    done,
    total: ids.length,
    label: bulkQrJobCancelled ? 'Stopped' : 'Complete',
    generated,
    skipped,
    failed,
    regenerated,
    mode,
  });

  finishBulkQrProgressModal({
    cancelled: bulkQrJobCancelled,
    mode,
    generated,
    skipped,
    failed,
    regenerated,
    total: ids.length,
    done,
  });

  if (mode === 'regenerate' && regenerated > 0 && typeof loadChoices === 'function') {
    await loadChoices();
  }
}

document.getElementById('bulkQrCancelBtn')?.addEventListener('click', () => {
  if (!bulkQrJobRunning) return;
  bulkQrJobCancelled = true;
  const cancelBtn = document.getElementById('bulkQrCancelBtn');
  if (cancelBtn) {
    cancelBtn.disabled = true;
    cancelBtn.textContent = 'Stopping…';
  }
});

document.getElementById('bulkQrProgressModal')?.addEventListener('hidden.bs.modal', () => {
  const cancelBtn = document.getElementById('bulkQrCancelBtn');
  if (cancelBtn) {
    cancelBtn.disabled = false;
    cancelBtn.textContent = 'Stop';
  }
});

async function startBulkQrFromButton(triggerBtn, { force = false } = {}) {
  const ids = getActiveChoiceIdsFromTable();
  if (!ids.length) {
    showInfoToast('No active establishments found.', false);
    return;
  }

  const mode = force ? 'regenerate' : 'generate';
  const confirmed = await confirmAction({
    title: mode === 'regenerate' ? 'Regenerate QR codes' : 'Generate QR codes',
    message:
      mode === 'regenerate'
        ? `This will overwrite QR codes for ${ids.length} active business(es). Continue?`
        : `Generate QR codes for ${ids.length} active business(es)? Existing QR files will be kept (skipped).`,
    confirmLabel: mode === 'regenerate' ? 'Regenerate' : 'Generate',
    confirmClass: mode === 'regenerate' ? 'btn-danger' : 'btn-primary',
  });
  if (!confirmed) return;

  const originalText = triggerBtn.innerHTML;
  triggerBtn.disabled = true;
  triggerBtn.innerHTML =
    mode === 'regenerate'
      ? '<span class="spinner-border spinner-border-sm me-2"></span>Regenerating…'
      : '<span class="spinner-border spinner-border-sm me-2"></span>Generating…';

  try {
    await runBulkQrJob(ids, { force });
  } finally {
    triggerBtn.disabled = false;
    triggerBtn.innerHTML = originalText;
  }
}

document.getElementById('generateAllQRBtn')?.addEventListener('click', () => {
  const btn = document.getElementById('generateAllQRBtn');
  if (btn) startBulkQrFromButton(btn, { force: false });
});

document.getElementById('regenerateAllQRBtn')?.addEventListener('click', () => {
  const btn = document.getElementById('regenerateAllQRBtn');
  if (btn) startBulkQrFromButton(btn, { force: true });
});



/* =========================
   Business Media Manager
   ========================= */
const mediaManagerEl       = document.getElementById('mediaManagerModal');
const mediaManagerLabel    = document.getElementById('mediaManagerLabel');
const mediaChoiceIdEl      = document.getElementById('mediaChoiceId');
const mediaUploadForm      = document.getElementById('mediaUploadForm');
const mediaFileInputEl     = document.getElementById('mediaFileInput');
const mediaCaptionInputEl  = document.getElementById('mediaCaptionInput');
const mediaUploadBtn       = document.getElementById('mediaUploadBtn');
const mediaUploadProgress  = document.getElementById('mediaUploadProgress');
const mediaUploadProgressB = document.getElementById('mediaUploadProgressBar');
const mediaGalleryListEl   = document.getElementById('mediaGalleryList');

function escapeHtmlSimple(str) {
  return String(str ?? '')
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function renderMediaItem(item) {
  const isVideo = item.media_type === 'video';
  const url = item.file_path.startsWith('http') || item.file_path.startsWith('/')
    ? item.file_path
    : item.file_path;
  const preview = isVideo
    ? `<video src="${escapeHtmlSimple(url)}" controls preload="metadata" style="width:100%;max-height:160px;background:#000;border-radius:.25rem;"></video>`
    : `<img src="${escapeHtmlSimple(url)}" alt="${escapeHtmlSimple(item.caption || 'Media')}" style="width:100%;height:160px;object-fit:cover;border-radius:.25rem;">`;
  const caption = item.caption ? escapeHtmlSimple(item.caption) : '';
  return `
    <div class="col-12 col-md-6 col-lg-4" data-media-id="${item.id}">
      <div class="border rounded p-2 h-100 d-flex flex-column gap-2">
        ${preview}
        <div class="small text-muted">
          <span class="badge bg-${isVideo ? 'dark' : 'secondary'} text-uppercase me-1">${isVideo ? 'Video' : 'Image'}</span>
          ${caption || '<span class="fst-italic">No caption</span>'}
        </div>
        <div class="d-flex gap-2 mt-auto">
          <button type="button" class="btn btn-sm btn-outline-secondary flex-grow-1 mediaEditCaptionBtn" data-id="${item.id}" data-caption="${escapeHtmlSimple(item.caption || '')}">
            <i class="bi bi-pencil"></i> Caption
          </button>
          <button type="button" class="btn btn-sm btn-outline-danger flex-grow-1 mediaDeleteBtn" data-id="${item.id}">
            <i class="bi bi-trash"></i> Delete
          </button>
        </div>
      </div>
    </div>`;
}

function setMediaGalleryEmpty(message = 'No media uploaded yet.') {
  if (!mediaGalleryListEl) return;
  mediaGalleryListEl.innerHTML =
    `<div class="col-12 text-center text-muted small py-3">${escapeHtmlSimple(message)}</div>`;
}

async function loadChoiceMedia(choiceId) {
  if (!mediaGalleryListEl) return;
  setMediaGalleryEmpty('Loading...');
  try {
    const res = await fetch('choice_media.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'list', choice_id: choiceId })
    });
    const result = await res.json();
    if (result.status !== 'success') {
      setMediaGalleryEmpty(result.message || 'Could not load media.');
      return;
    }
    const items = Array.isArray(result.data) ? result.data : [];
    if (items.length === 0) { setMediaGalleryEmpty(); return; }
    mediaGalleryListEl.innerHTML = items.map(renderMediaItem).join('');
  } catch (err) {
    console.error('loadChoiceMedia failed', err);
    setMediaGalleryEmpty('Failed to load media.');
  }
}

document.getElementById('tableBody')?.addEventListener('click', (e) => {
  const btn = e.target.closest('.mediaBtn');
  if (!btn) return;
  const choiceId = btn.getAttribute('data-id');
  const name = btn.getAttribute('data-name') || 'Business';
  if (!choiceId) return;
  if (mediaChoiceIdEl) mediaChoiceIdEl.value = choiceId;
  if (mediaManagerLabel) mediaManagerLabel.textContent = `Business Media — ${name}`;
  if (mediaFileInputEl) mediaFileInputEl.value = '';
  if (mediaCaptionInputEl) mediaCaptionInputEl.value = '';
  if (mediaUploadProgress) mediaUploadProgress.classList.add('d-none');
  if (mediaUploadProgressB) mediaUploadProgressB.style.width = '0%';
  loadChoiceMedia(parseInt(choiceId, 10));
  bootstrap.Modal.getOrCreateInstance(mediaManagerEl).show();
});

mediaUploadForm?.addEventListener('submit', (e) => {
  e.preventDefault();
  const choiceId = parseInt(mediaChoiceIdEl?.value || '0', 10);
  const file = mediaFileInputEl?.files?.[0];
  if (!choiceId || !file) {
    showInfoToast('Please choose a file to upload.', false);
    return;
  }

  const fd = new FormData();
  fd.append('action', 'upload');
  fd.append('choice_id', String(choiceId));
  fd.append('caption', (mediaCaptionInputEl?.value || '').trim());
  fd.append('file', file);

  const xhr = new XMLHttpRequest();
  xhr.open('POST', 'choice_media.php', true);

  if (xhr.upload && mediaUploadProgress && mediaUploadProgressB) {
    mediaUploadProgress.classList.remove('d-none');
    mediaUploadProgressB.style.width = '0%';
    xhr.upload.onprogress = (ev) => {
      if (!ev.lengthComputable) return;
      const pct = Math.max(0, Math.min(100, (ev.loaded / ev.total) * 100));
      mediaUploadProgressB.style.width = pct.toFixed(0) + '%';
    };
  }

  mediaUploadBtn && (mediaUploadBtn.disabled = true);

  xhr.onload = () => {
    mediaUploadBtn && (mediaUploadBtn.disabled = false);
    let payload = null;
    try { payload = JSON.parse(xhr.responseText); } catch (_) {}
    if (xhr.status >= 200 && xhr.status < 300 && payload?.status === 'success') {
      showInfoToast('Media uploaded.', true);
      if (mediaFileInputEl) mediaFileInputEl.value = '';
      if (mediaCaptionInputEl) mediaCaptionInputEl.value = '';
      loadChoiceMedia(choiceId);
    } else {
      showInfoToast(payload?.message || 'Upload failed.', false);
    }
    if (mediaUploadProgress) {
      mediaUploadProgress.classList.add('d-none');
      mediaUploadProgressB.style.width = '0%';
    }
  };
  xhr.onerror = () => {
    mediaUploadBtn && (mediaUploadBtn.disabled = false);
    showInfoToast('Upload failed (network error).', false);
    if (mediaUploadProgress) {
      mediaUploadProgress.classList.add('d-none');
      mediaUploadProgressB.style.width = '0%';
    }
  };
  xhr.send(fd);
});

mediaGalleryListEl?.addEventListener('click', async (e) => {
  const delBtn = e.target.closest('.mediaDeleteBtn');
  const capBtn = e.target.closest('.mediaEditCaptionBtn');
  const choiceId = parseInt(mediaChoiceIdEl?.value || '0', 10);

  if (delBtn) {
    const id = parseInt(delBtn.getAttribute('data-id') || '0', 10);
    if (!id) return;
    const mediaDeleteConfirmed = await confirmAction({
      title: 'Delete media',
      message: 'Delete this media item? This cannot be undone.',
      confirmLabel: 'Delete',
      confirmClass: 'btn-danger',
    });
    if (!mediaDeleteConfirmed) return;
    try {
      const res = await fetch('choice_media.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete', id })
      });
      const result = await res.json();
      if (result.status === 'success') {
        showInfoToast('Media deleted.', true);
        if (choiceId) loadChoiceMedia(choiceId);
      } else {
        showInfoToast(result.message || 'Delete failed.', false);
      }
    } catch (err) {
      console.error('media delete failed', err);
      showInfoToast('Delete failed.', false);
    }
    return;
  }

  if (capBtn) {
    const id = parseInt(capBtn.getAttribute('data-id') || '0', 10);
    if (!id) return;
    const current = capBtn.getAttribute('data-caption') || '';
    const next = prompt('Update caption (leave blank to clear):', current);
    if (next === null) return;
    try {
      const res = await fetch('choice_media.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'update', id, caption: next })
      });
      const result = await res.json();
      if (result.status === 'success') {
        showInfoToast('Caption updated.', true);
        if (choiceId) loadChoiceMedia(choiceId);
      } else {
        showInfoToast(result.message || 'Update failed.', false);
      }
    } catch (err) {
      console.error('caption update failed', err);
      showInfoToast('Update failed.', false);
    }
  }
});

document.getElementById('tableBody')?.addEventListener('click', async (e) => {
  const btn = e.target.closest('.copyVoteBtn');
  if (!btn) return;
  e.preventDefault();
  const url = btn.getAttribute('data-vote-url') || '';
  if (await copyTextToClipboard(url)) {
    showInfoToast('Vote link copied (share with voters / social media).', true);
  } else {
    showInfoToast('Could not copy link.', false);
  }
});

document.getElementById('copyAllVoteLinksBtn')?.addEventListener('click', async () => {
  const lines = choicesCache
    .filter(c => c.vote_url)
    .map(c => `${c.choice_name}\t${c.vote_url}`);
  if (!lines.length) {
    showInfoToast('No vote links available. Reload the table first.', false);
    return;
  }
  if (await copyTextToClipboard(lines.join('\n'))) {
    showInfoToast(`Copied ${lines.length} vote link(s) (name + URL per line).`, true);
  } else {
    showInfoToast('Could not copy to clipboard.', false);
  }
});

/* =========================
   Boot
   ========================= */
document.addEventListener('DOMContentLoaded', () => {
  ensureEstablishmentTypesLoaded();
  loadChoices();
});
