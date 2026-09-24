import { state } from './state.js';
import { getActiveFieldLabels, getLabelsForQuestion, awardUsesOpenText, awardUsesMeryenda, awardUsesProductField, parseOpenTextPair, titleCaseOpenTextPart, usesSingleOpenField, matchNamedChoice, MERYENDA_OTHER } from './js/voting_field_labels.js?v=cast4';
import {
  buildProofUploadHtml,
  bindProofUploadHandlers,
  markInvalidProofSections,
  setProofSectionEnabled,
} from './js/vote_proof_upload.js';

// local debounce utility
function debounce(fn, delay = 300) {
  let timeout;
  return (...args) => {
    clearTimeout(timeout);
    timeout = setTimeout(() => fn(...args), delay);
  };
}

// Escape potentially unsafe HTML characters. Question and category
// names come from the server and may contain characters that would be
// interpreted as HTML, breaking the display. Encoding them ensures they
// render exactly as provided.
function escapeHtml(str = '') {
  return str
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function getListInstruction(labels = null, openText = false, product = false) {
  const L = labels || getActiveFieldLabels(state);
  if (openText) {
    return L.list_instruction || 'Type your answer below.';
  }
  if (product) {
    return L.list_instruction || 'Type the product and the business name.';
  }
  return L.list_instruction || 'Pick from the list. A photo is optional.';
}

export function getProofValidationMessage(forCategorySwitch = false) {
  if (document.querySelector('.meryenda-where.is-invalid, .meryenda-product.is-invalid')) {
    return forCategorySwitch
      ? 'Please choose the product and enter the vendor name and location before changing categories.'
      : 'Please choose the product and enter the vendor name and location.';
  }
  const L = getActiveFieldLabels(state);
  return forCategorySwitch
    ? L.validation_message_switch || L.validation_message
    : L.validation_message;
}

/** @deprecated use getProofValidationMessage */
export function getManualValidationMessage(forCategorySwitch = false) {
  return getProofValidationMessage(forCategorySwitch);
}

function sanitizeChoiceLogoUrl(url = '') {
  const u = String(url || '').trim();
  if (!u || /^\s*javascript:/i.test(u) || /^\s*data:/i.test(u)) {
    return '';
  }
  return u.replace(/"/g, '%22').replace(/'/g, '%27');
}

/** Label HTML for Choices.js (logo before business name). */
function buildChoiceOptionLabel(choice) {
  const name = escapeHtml(choice?.choice_name || '');
  const logo = sanitizeChoiceLogoUrl(choice?.logo_url);
  if (!logo) {
    return name;
  }
  return (
    '<span class="choice-option-label">' +
    `<img src="${logo}" alt="" class="choice-option-logo" loading="lazy" ` +
    'onerror="this.style.display=\'none\'">' +
    `<span class="choice-option-name">${name}</span></span>`
  );
}

const BUSINESS_PLACEHOLDER_LABEL = 'Choose a business…';

function dropdownPlaceholderLabel(question = null, choices = []) {
  if (awardUsesMeryenda(question, state)) return 'Choose a product…';
  const list = choices.length ? choices : (question?.choices || []);
  if (list.some((c) => c.is_named_entry)) {
    const kind = String(list.find((c) => c.entry_kind)?.entry_kind || list[0]?.entry_kind || '');
    if (kind === 'artist') return 'Choose a business - artist…';
    if (kind === 'stylist') return 'Choose a business - stylist…';
    return 'Choose a business - product…';
  }
  return BUSINESS_PLACEHOLDER_LABEL;
}

function normalizeSelectedChoiceValue(selectedValue = '') {
  if (selectedValue == null) return '';
  const selected = String(selectedValue).trim();
  if (selected === '' || selected === '0') return '';
  return selected;
}

function buildChoiceSelectItems(choices, selectedValue = '') {
  const selected = normalizeSelectedChoiceValue(selectedValue);
  const seen = new Set();
  const items = [];
  (choices || []).forEach(choice => {
    const value = String(choice.choice_id ?? '');
    if (!value || seen.has(value)) return;
    seen.add(value);
    items.push({
      value,
      label: buildChoiceOptionLabel(choice),
      customProperties: { plainName: String(choice.choice_name || '') },
      selected: selected !== '' && value === selected,
      disabled: false
    });
  });
  return items;
}

// Preview button — only when the selected business has uploaded media.
function buildViewBusinessButtonHtml(selectedChoiceId, selectedChoiceName, hasMedia = false) {
  if (!hasMedia || !selectedChoiceId) {
    return '';
  }
  const cid = String(selectedChoiceId);
  const cname = selectedChoiceName ? escapeHtml(selectedChoiceName) : '';
  return (
    `<button type="button" class="btn btn-sm mt-2 view-business-btn view-choice-media-btn" ` +
    `data-choice-id="${cid}" data-choice-name="${cname}" ` +
    `aria-label="Preview photos and videos of the selected business">` +
    `<i class="fas fa-images me-1"></i>Preview photos &amp; videos` +
    `</button>`
  );
}

function buildMediaHintInnerHtml(choiceName) {
  const name = escapeHtml(choiceName || '');
  return (
    `<span class="choice-media-hint__icon" aria-hidden="true"><i class="fa-solid fa-circle-play"></i></span>` +
    `<span class="choice-media-hint__text">` +
    `<span class="choice-media-hint__lead">Photos and videos are available for </span>` +
    `<strong class="choice-media-hint__name">${name}</strong>. ` +
    `<span class="choice-media-hint__trail">Tap the preview button below to browse before you vote.</span>` +
    `</span>`
  );
}

function buildMediaHintHtml(choices, selectedId) {
  const selected = (choices || []).find(c => String(c.choice_id) === String(selectedId));
  if (selected?.has_media) {
    return (
      `<div class="choice-media-hint small mb-2" role="status">` +
      buildMediaHintInnerHtml(selected.choice_name) +
      `</div>`
    );
  }
  return `<div class="choice-media-hint d-none small mb-2" role="status"></div>`;
}

function updateMediaHint(formGroup, choices, selectedId) {
  const hint = formGroup.querySelector('.choice-media-hint');
  const btn = formGroup.querySelector('.view-choice-media-btn');
  if (!hint) return;

  const selected = (choices || []).find(c => String(c.choice_id) === String(selectedId));
  if (selected?.has_media) {
    hint.classList.remove('d-none');
    hint.innerHTML = buildMediaHintInnerHtml(selected.choice_name);
  } else {
    hint.classList.add('d-none');
    hint.innerHTML = '';
  }
}

/** Keep preview controls in sync with dropdown selection. */
function refreshPreviewControls(formGroup, choices = []) {
  const select = formGroup.querySelector('select');
  let btn = formGroup.querySelector('.view-choice-media-btn');
  const hint = formGroup.querySelector('.choice-media-hint');
  const selectValue = select && !select.disabled ? select.value : '';

  const hidePreviewBtn = (targetBtn) => {
    if (!targetBtn) return;
    targetBtn.dataset.choiceId = '';
    targetBtn.dataset.choiceName = '';
    targetBtn.disabled = true;
    targetBtn.classList.add('d-none');
    targetBtn.setAttribute('aria-hidden', 'true');
  };

  if (!selectValue) {
    hidePreviewBtn(btn);
    if (hint) {
      hint.classList.add('d-none');
      hint.innerHTML = '';
    }
    return;
  }

  const choice = (choices || []).find(c => String(c.choice_id) === String(selectValue));
  if (!choice?.has_media) {
    hidePreviewBtn(btn);
    if (hint) {
      hint.classList.add('d-none');
      hint.innerHTML = '';
    }
    return;
  }

  if (!btn) {
    const anchor = hint || select;
    if (anchor) {
      anchor.insertAdjacentHTML(
        'afterend',
        buildViewBusinessButtonHtml(choice.choice_id, choice.choice_name, true)
      );
      btn = formGroup.querySelector('.view-choice-media-btn');
    }
  }
  if (!btn) return;

  btn.classList.remove('d-none');
  btn.removeAttribute('aria-hidden');
  btn.dataset.choiceId = selectValue;
  btn.dataset.choiceName = choice?.choice_name || '';
  btn.disabled = false;
  updateMediaHint(formGroup, choices, selectValue);
}

// Wire up the view-business button inside a question block so it opens the
// shared media modal for whatever choice is currently selected.
function attachViewBusinessHandlers(formGroup, choices = []) {
  const select = formGroup.querySelector('select');
  if (!select) return;

  formGroup._choiceList = choices;

  function refreshFromSelect() {
    refreshPreviewControls(formGroup, choices);
  }

  select.addEventListener('change', refreshFromSelect);
  refreshFromSelect();

  if (formGroup.dataset.mediaPreviewBound === '1') return;
  formGroup.dataset.mediaPreviewBound = '1';
  formGroup.addEventListener('click', (ev) => {
    const btn = ev.target.closest('.view-choice-media-btn');
    if (!btn || !formGroup.contains(btn)) return;
    if (btn.disabled || btn.classList.contains('d-none')) return;
    const cid = parseInt(btn.dataset.choiceId || '0', 10);
    const cname = btn.dataset.choiceName || '';
    if (!cid || !window.ChoiceMediaViewer || typeof window.ChoiceMediaViewer.open !== 'function') return;
    window.ChoiceMediaViewer.open(cid, cname);
  });
}

export function destroyQuestionChoiceInstances() {
  state.questionChoiceInstances.forEach(inst => {
    try { inst.destroy(); } catch (e) {}
  });
  state.questionChoiceInstances = [];
}

export function initializeQuestionDropdown(selectEl, choices = [], selectedValue = '', placeholderLabel = '') {
  if (!selectEl) return;
  const selected = normalizeSelectedChoiceValue(selectedValue);
  const choiceItems = buildChoiceSelectItems(choices, selected);
  const searchEnabled = choiceItems.filter((item) => item.value).length >= 8;
    const named = (choices || []).some((c) => c.is_named_entry);
    const productList = (choices || []).some((c) => String(c.choice_id) === MERYENDA_OTHER);
    const placeholder = placeholderLabel || dropdownPlaceholderLabel(null, choices);

  try {
    if (selectEl.choicesInstance) {
      try {
        selectEl.choicesInstance.destroy();
      } catch (e) {}
      selectEl.choicesInstance = null;
    }
    // Start blank so Choices cannot inherit the first business as a default.
    selectEl.innerHTML = `<option value="" selected>${placeholder}</option>`;
    selectEl.value = '';
    selectEl.removeAttribute('data-choice');
    selectEl.removeAttribute('data-choice-orig-style');

    const instance = new Choices(selectEl, {
      searchEnabled,
      itemSelectText: '',
      shouldSort: false,
      allowHTML: true,
      placeholder: true,
      placeholderValue: placeholder,
      searchPlaceholderValue: productList ? 'Search products…' : (named ? 'Search…' : 'Search businesses…'),
      noResultsText: productList ? 'No matching products' : (named ? 'No matching options' : 'No businesses found'),
      noChoicesText: productList ? 'No products listed for this award yet' : (named ? 'No options listed for this award yet' : 'No businesses listed for this award'),
      removeItemButton: true,
      choices: choiceItems
    });
    state.questionChoiceInstances.push(instance);
    selectEl.choicesInstance = instance;
    if (selected) {
      try {
        instance.setChoiceByValue(selected);
      } catch (e) {}
    } else {
      try {
        instance.removeActiveItems();
        instance.setChoiceByValue('');
      } catch (e) {}
      selectEl.value = '';
    }
  } catch (err) {
    console.error('Failed to init dropdown:', err);
  }
}

export function updateFieldStates(selectEl) {
  const formGroup = selectEl?.closest('.question-block');
  if (formGroup) {
    refreshPreviewControls(formGroup, formGroup._choiceList || []);
    setProofSectionEnabled(formGroup, Boolean(selectEl?.value));
    syncMeryendaOther(formGroup);
  }
}

export function validateFreetextPair() {
  return true;
}

function markInvalidMeryenda(scope = document) {
  let ok = true;
  scope.querySelectorAll('.question-block').forEach((block) => {
    const whereInput = block.querySelector('.meryenda-where');
    const productInput = block.querySelector('.meryenda-product');
    const select = block.querySelector('select');
    if (!whereInput || !select) return;
    whereInput.classList.remove('is-invalid');
    productInput?.classList.remove('is-invalid');
    const value = String(select.value || '');
    if (!value) return;
    if (!String(whereInput.value || '').trim()) {
      whereInput.classList.add('is-invalid');
      ok = false;
    }
    if (value === MERYENDA_OTHER && productInput && !String(productInput.value || '').trim()) {
      productInput.classList.add('is-invalid');
      ok = false;
    }
  });
  return ok;
}

function syncMeryendaOther(formGroup) {
  const select = formGroup?.querySelector('select');
  const wrap = formGroup?.querySelector('.meryenda-product-wrap');
  if (!select || !wrap) return;
  wrap.classList.toggle('d-none', String(select.value || '') !== MERYENDA_OTHER);
}

export function allFreetextPairsValid(scope = document) {
  return markInvalidProofSections(scope) && markInvalidMeryenda(scope);
}

function buildQuestionFieldsHtml(question, selection = {}) {
  const labels = getLabelsForQuestion(question, state);
  const openText = awardUsesOpenText(question, state);
  let html = `<label class="form-label fw-bold">${escapeHtml(question.question_name)}</label>`;

  if (openText) {
    const stored = selection.choiceText || selection.freetext || selection.manual_input || '';
    const singleField = usesSingleOpenField({ ...labels, question_name: question.question_name });
    const pair = parseOpenTextPair(stored);
    const titleVal = escapeHtml(singleField ? stored : pair.title);
    const singerVal = escapeHtml(singleField ? '' : pair.singer);
    const titleLabel = escapeHtml(labels.open_label || (singleField ? 'Name of Place' : 'Song title'));
    const singerLabel = escapeHtml(labels.open_label_2 || 'Singer');
    const titlePh = escapeHtml(labels.open_placeholder || (singleField ? 'Type the name of the place' : 'Type the song title'));
    const singerPh = escapeHtml(labels.open_placeholder_2 || 'Type the singer');
    const qid = question.question_id;
    html += `<label class="form-label mt-2 fw-normal text-primary d-block">${escapeHtml(getListInstruction(labels, true))}</label>`;
    html += `<div class="open-text-pair${singleField ? ' open-text-pair--single' : ''}">`;
    html += `<div class="open-text-field">` +
      `<label class="form-label small text-muted mb-1" for="open-text-title-${qid}">${titleLabel}</label>` +
      `<input type="text" class="form-control open-text-title" id="open-text-title-${qid}" ` +
      `maxlength="180" autocomplete="off" placeholder="${titlePh}" value="${titleVal}" ` +
      `aria-label="${titleLabel} for ${escapeHtml(question.question_name)}">` +
      `</div>`;
    if (!singleField) {
      html += `<div class="open-text-field">` +
        `<label class="form-label small text-muted mb-1" for="open-text-singer-${qid}">${singerLabel}</label>` +
        `<input type="text" class="form-control open-text-singer" id="open-text-singer-${qid}" ` +
        `maxlength="180" autocomplete="off" placeholder="${singerPh}" value="${singerVal}" ` +
        `aria-label="${singerLabel} for ${escapeHtml(question.question_name)}">` +
        `</div>`;
    }
    html += `</div>`;
    if (labels.show_proof) {
      html += buildProofUploadHtml(question.question_id, selection.proofImages || [], labels);
    }
    return html;
  }

  if (awardUsesMeryenda(question, state)) {
    const labels = getLabelsForQuestion(question, state);
    const stored = String(selection.freetext || selection.manual_input || '').trim();
    const listedId = matchNamedChoice(
      question.choices || [],
      selection.selectedOption,
      ''
    );
    const isOther = String(selection.selectedOption || '') === MERYENDA_OTHER
      || (!listedId && stored.includes('—'));
    const pair = isOther ? parseOpenTextPair(stored || selection.choiceText || '') : { title: '', singer: '' };
    const whereVal = escapeHtml(isOther ? pair.singer : stored);
    const productVal = escapeHtml(isOther ? pair.title : '');
    const qid = question.question_id;
    html += `<label class="form-label mt-2 fw-normal text-primary d-block">${escapeHtml(labels.list_instruction || '')}</label>`;
    const productLabel = escapeHtml(labels.open_label || 'Product');
    const productPh = escapeHtml(labels.open_placeholder || 'Type the product');
    const vendorLabel = escapeHtml(labels.open_label_2 || 'Vendor name / location');
    const vendorPh = escapeHtml(labels.open_placeholder_2 || 'Type the vendor name and location');
    html += `<label class="form-label small text-muted mb-1" for="meryenda-kind-${qid}">${productLabel}</label>`;
    html += `<select class="form-select mb-2 choice-select-with-logos" id="meryenda-kind-${qid}" aria-label="${productLabel} for ${escapeHtml(question.question_name)}"></select>`;
    html += `<div class="meryenda-product-wrap open-text-field mb-2${isOther ? '' : ' d-none'}">`;
    html += `<label class="form-label small text-muted mb-1" for="meryenda-product-${qid}">${productLabel}</label>`;
    html += `<input type="text" class="form-control meryenda-product" id="meryenda-product-${qid}" maxlength="180" autocomplete="off" placeholder="${productPh}" value="${productVal}" aria-label="${productLabel} for ${escapeHtml(question.question_name)}">`;
    html += `</div>`;
    html += `<label class="form-label small text-muted mb-1" for="meryenda-where-${qid}">${vendorLabel}</label>`;
    html += `<input type="text" class="form-control meryenda-where" id="meryenda-where-${qid}" maxlength="180" autocomplete="off" placeholder="${vendorPh}" value="${whereVal}" aria-label="${vendorLabel} for ${escapeHtml(question.question_name)}">`;
    return html;
  }

  const hasChoices = (question.choices || []).length > 0;
  if (!hasChoices) {
    html += `<p class="text-muted small mt-2">No businesses listed for this award yet.</p>`;
    return html;
  }
  const selectedChoice = matchNamedChoice(
    question.choices || [],
    selection.selectedOption,
    selection.choiceText || selection.freetext || ''
  );
  const useProduct = awardUsesProductField(question, state);
  html += `<label class="form-label mt-2 fw-normal text-primary d-block">${escapeHtml(getListInstruction(labels, false, useProduct))}</label>`;
  if (useProduct) {
    const productVal = escapeHtml(selection.freetext || selection.manual_input || '');
    const productLabel = escapeHtml(labels.open_label || 'Product Name');
    const productPh = escapeHtml(labels.open_placeholder || 'Type the product name');
    const qid = question.question_id;
    html += `<div class="open-text-pair">`;
    html += `<div class="open-text-field">` +
      `<label class="form-label small text-muted mb-1" for="open-text-product-${qid}">${productLabel}</label>` +
      `<input type="text" class="form-control open-text-product" id="open-text-product-${qid}" ` +
      `maxlength="180" autocomplete="off" placeholder="${productPh}" value="${productVal}" ` +
      `aria-label="${productLabel} for ${escapeHtml(question.question_name)}">` +
      `</div>`;
    html += `<div class="open-text-field">` +
      `<label class="form-label small text-muted mb-1">Business Name</label>` +
      `<select class="form-select choice-select-with-logos" aria-label="Business for ${escapeHtml(question.question_name)}" required></select>` +
      `</div>`;
    html += `</div>`;
  } else {
    html += `<select class="form-select mb-2 choice-select-with-logos" aria-label="Answer for ${escapeHtml(question.question_name)}" required></select>`;
  }
  html += buildMediaHintHtml(question.choices, selectedChoice ? selectedChoice.choice_id : '');
  html += buildViewBusinessButtonHtml(
    selectedChoice ? selectedChoice.choice_id : '',
    selectedChoice ? selectedChoice.choice_name : '',
    selectedChoice ? selectedChoice.has_media : false
  );
  if (labels.show_proof !== false) {
    html += buildProofUploadHtml(question.question_id, selection.proofImages || [], labels);
  }
  return html;
}

function wireQuestionBlock(formGroup, question, selection, helpers, isFinalized) {
  const { saveCurrentSelections, saveCurrentCategoryToGlobal, saveVotesAndRedirect, checkIfAllQuestionsAnsweredGlobally } =
    helpers;
  const openText = awardUsesOpenText(question, state);
  const hasChoices = (question.choices || []).length > 0;

  if (openText) {
    const inputs = formGroup.querySelectorAll('.open-text-title, .open-text-singer');
    if (inputs.length) {
      const persist = debounce(() => {
        saveCurrentSelections?.();
        saveCurrentCategoryToGlobal?.();
        saveVotesAndRedirect?.(false);
        checkIfAllQuestionsAnsweredGlobally?.();
      }, 300);
      inputs.forEach((input) => {
        input.addEventListener('input', persist);
        input.addEventListener('change', persist);
        input.addEventListener('blur', () => {
          const next = titleCaseOpenTextPart(input.value);
          if (next !== input.value) {
            input.value = next;
            persist();
          }
        });
      });
    }
    const labels = getLabelsForQuestion(question, state);
    if (labels.show_proof) {
      bindProofUploadHandlers(formGroup, {
        onChange: () => {
          saveCurrentSelections?.();
          saveCurrentCategoryToGlobal?.();
          saveVotesAndRedirect?.(false);
          checkIfAllQuestionsAnsweredGlobally?.();
        },
        onError: (msg) => {
          if (typeof window.showToast === 'function') window.showToast(msg, 'danger');
          else alert(msg);
        },
      });
      setProofSectionEnabled(formGroup, true);
    }
    if (isFinalized) {
      formGroup.classList.add('bg-light', 'border-success', 'position-relative');
      const badge = document.createElement('span');
      badge.textContent = 'Voted';
      badge.className = 'badge bg-success position-absolute top-0 end-0 m-2';
      formGroup.appendChild(badge);
      inputs.forEach((input) => { input.disabled = true; });
      if (labels.show_proof) {
        setProofSectionEnabled(formGroup, false);
      }
    }
    state.questionsContainer.appendChild(formGroup);
    return;
  }

  if (!hasChoices) {
    state.questionsContainer.appendChild(formGroup);
    return;
  }

  const meryenda = awardUsesMeryenda(question, state);
  const choiceList = meryenda
    ? [...(question.choices || []), { choice_id: MERYENDA_OTHER, choice_name: 'Not on the list' }]
    : (question.choices || []);
  const selectDropdown = formGroup.querySelector('select');
  const selectedChoice = meryenda
    ? null
    : matchNamedChoice(
        question.choices || [],
        selection.selectedOption,
        selection.choiceText || selection.freetext || ''
      );
  let selectedValue = (selectedChoice && selectedChoice.choice_id) || selection.selectedOption || '';
  if (meryenda) {
    const stored = String(selection.freetext || selection.manual_input || '');
    const listed = matchNamedChoice(question.choices || [], selection.selectedOption, '');
    if (String(selection.selectedOption || '') === MERYENDA_OTHER || (!listed && stored.includes('—'))) {
      selectedValue = MERYENDA_OTHER;
    } else {
      selectedValue = listed ? String(listed.choice_id) : '';
    }
  }
  initializeQuestionDropdown(
    selectDropdown,
    choiceList,
    selectedValue,
    dropdownPlaceholderLabel(question, question.choices || [])
  );
  attachViewBusinessHandlers(formGroup, question.choices || []);

  const proofCallbacks = {
    onChange: () => {
      saveCurrentSelections?.();
      saveCurrentCategoryToGlobal?.();
      saveVotesAndRedirect?.(false);
      checkIfAllQuestionsAnsweredGlobally?.();
    },
    onError: (msg) => {
      if (typeof window.showToast === 'function') window.showToast(msg, 'danger');
      else alert(msg);
    },
  };
  bindProofUploadHandlers(formGroup, proofCallbacks);

  if (isFinalized) {
    formGroup.classList.add('bg-light', 'border-success', 'position-relative');
    const badge = document.createElement('span');
    badge.textContent = 'Voted';
    badge.className = 'badge bg-success position-absolute top-0 end-0 m-2';
    formGroup.appendChild(badge);
    const select = formGroup.querySelector('select');
    if (select) select.disabled = true;
    const productInput = formGroup.querySelector('.open-text-product');
    if (productInput) productInput.disabled = true;
    formGroup.querySelectorAll('.meryenda-where, .meryenda-product').forEach((input) => {
      input.disabled = true;
    });
    setProofSectionEnabled(formGroup, false);
    state.questionsContainer.appendChild(formGroup);
    return;
  }

  const select = formGroup.querySelector('select');
  if (select) {
    select.addEventListener('change', () => {
      updateFieldStates(select);
      saveCurrentSelections?.();
      saveCurrentCategoryToGlobal?.();
      saveVotesAndRedirect?.(false);
      checkIfAllQuestionsAnsweredGlobally?.();
    });
    updateFieldStates(select);
  }
  formGroup.querySelectorAll('.meryenda-where, .meryenda-product').forEach((input) => {
    const persist = debounce(() => {
      saveCurrentSelections?.();
      saveCurrentCategoryToGlobal?.();
      saveVotesAndRedirect?.(false);
      checkIfAllQuestionsAnsweredGlobally?.();
    }, 300);
    input.addEventListener('input', persist);
    input.addEventListener('change', persist);
    input.addEventListener('blur', () => {
      const next = titleCaseOpenTextPart(input.value);
      if (next !== input.value) {
        input.value = next;
        persist();
      }
    });
  });
  const productInput = formGroup.querySelector('.open-text-product');
  if (productInput) {
    const persist = debounce(() => {
      saveCurrentSelections?.();
      saveCurrentCategoryToGlobal?.();
      saveVotesAndRedirect?.(false);
      checkIfAllQuestionsAnsweredGlobally?.();
    }, 300);
    productInput.addEventListener('input', persist);
    productInput.addEventListener('change', persist);
    productInput.addEventListener('blur', () => {
      const next = titleCaseOpenTextPart(productInput.value);
      if (next !== productInput.value) {
        productInput.value = next;
        persist();
      }
    });
    if (isFinalized) {
      productInput.disabled = true;
    }
  }
  state.questionsContainer.appendChild(formGroup);
}

export function renderPaginatedQuestions(questionsToRender = state.questionsData, helpers = {}) {
  const { saveCurrentSelections, saveCurrentCategoryToGlobal, saveVotesAndRedirect, checkIfAllQuestionsAnsweredGlobally, restoreTempSelections } = helpers;
  if (!state.questionsContainer) return;
  state.showingAll = true;
  destroyQuestionChoiceInstances();
  state.questionsContainer.innerHTML = '';
  if (questionsToRender.length === 0) {
    const searchTerm = state.questionSearchInput?.value.trim();
    if (searchTerm) {
      state.questionsContainer.innerHTML = '<p class="text-muted text-center mt-3">No award titles found.</p>';
    } else {
      state.questionsContainer.innerHTML = '<p class="text-info text-center mt-4">No questions available for this category.</p>';
    }
    const existingPagination = state.questionsContainer.querySelector('.pagination-controls');
    if (existingPagination) existingPagination.remove();
    if (state.toggleViewBtn) state.toggleViewBtn.textContent = 'Single View';
    if (state.prevBtn) state.prevBtn.classList.add('d-none');
    if (state.nextBtn) state.nextBtn.classList.add('d-none');
        if (state.prevBtn && state.prevBtn.parentElement) {
      state.prevBtn.parentElement.classList.add('d-none');
    }
    if (state.submitVoteBtn) state.submitVoteBtn.classList.add('d-none');
    return;
  }
    if (state.prevBtn && state.prevBtn.parentElement) {
    state.prevBtn.parentElement.classList.remove('d-none');
  }

  const visibleQuestions = questionsToRender.slice(state.showOffset, state.showOffset + 5);
  visibleQuestions.forEach(question => {
    const originalIndex = state.questionsData.findIndex(q => q.question_id === question.question_id);
    const selection = state.userSelections[originalIndex] || {};
    const formGroup = document.createElement('div');
    formGroup.className = 'voter-question-card question-block mb-4';
    formGroup.dataset.originalIndex = originalIndex;
    formGroup.dataset.questionId = question.question_id;
    formGroup.innerHTML = buildQuestionFieldsHtml(question, selection);
    const finalizedAnswers = JSON.parse(localStorage.getItem('finalizedAnswers') || '{}');
    const finalizedFromDB = JSON.parse(localStorage.getItem('finalizedFromDB') || '{}');
    const isFinalized =
      finalizedAnswers[String(question.question_id)] === true ||
      finalizedFromDB[String(question.question_id)] === true;
    wireQuestionBlock(formGroup, question, selection, helpers, isFinalized);
    if (isFinalized) {
      restoreTempSelections && restoreTempSelections();
      return;
    }
  });
  const existingPagination = state.questionsContainer.querySelector('.pagination-controls');
  if (existingPagination) existingPagination.remove();
  const existingIndicator = state.questionsContainer.querySelector('.page-indicator');
  if (existingIndicator) existingIndicator.remove();
  const totalFilteredQuestions = questionsToRender.length;
  const totalPages = Math.ceil(totalFilteredQuestions / 5) || 1;
  const currentPage = Math.floor(state.showOffset / 5) + 1;
  if (state.prevBtn) {
    state.prevBtn.classList.remove('d-none');
    state.prevBtn.disabled = state.showOffset === 0;
  }
  if (state.nextBtn) {
    state.nextBtn.classList.remove('d-none');
    state.nextBtn.disabled = state.showOffset + 5 >= totalFilteredQuestions;
  }

  const pageIndicator = document.createElement('div');
  pageIndicator.className = 'text-muted small text-center mt-2 page-indicator';
  pageIndicator.textContent = `Page ${currentPage} of ${totalPages}`;
  state.questionsContainer.appendChild(pageIndicator);

  if (state.toggleViewBtn) state.toggleViewBtn.textContent = 'Change to Single Item View';
  if (state.submitVoteBtn) state.submitVoteBtn.classList.remove('d-none');
}

export function renderSingleQuestion(helpers = {}) {
  const { saveCurrentSelections, saveCurrentCategoryToGlobal, saveVotesAndRedirect, checkIfAllQuestionsAnsweredGlobally } = helpers;
  if (!state.questionsContainer) return;
  state.showingAll = false;
  destroyQuestionChoiceInstances();
  state.questionsContainer.innerHTML = '';
  if (state.questionsData.length === 0) {
    state.questionsContainer.innerHTML = '<p class="text-info text-center">No awards loaded.</p>';
    if (state.prevBtn) state.prevBtn.disabled = true;
    if (state.nextBtn) state.nextBtn.disabled = true;
    if (state.prevBtn) state.prevBtn.classList.add('d-none');
    if (state.nextBtn) state.nextBtn.classList.add('d-none');
    if (state.prevBtn && state.prevBtn.parentElement) {
      state.prevBtn.parentElement.classList.add('d-none');
    }
    if (state.toggleViewBtn) state.toggleViewBtn.disabled = true;
    if (state.submitVoteBtn) state.submitVoteBtn.classList.add('d-none');
    if (state.questionSearchInput) state.questionSearchInput.disabled = true;
    return;
  } else {
    if (state.toggleViewBtn) state.toggleViewBtn.disabled = false;
    if (state.questionSearchInput) state.questionSearchInput.disabled = false;
  }
  if (state.prevBtn && state.prevBtn.parentElement) {
    state.prevBtn.parentElement.classList.remove('d-none');
  }
  const question = state.questionsData[state.showOffset];
  const originalIndex = state.showOffset;
  let finalizedAnswers = {};
  let finalizedFromDB = {};
  try {
    finalizedAnswers = JSON.parse(localStorage.getItem('finalizedAnswers') || '{}') || {};
  } catch (e) {}
  try {
    finalizedFromDB = JSON.parse(localStorage.getItem('finalizedFromDB') || '{}') || {};
  } catch (e) {}
  const isFinalized =
    finalizedAnswers[String(question.question_id)] === true ||
    finalizedFromDB[String(question.question_id)] === true;
  let selection = state.userSelections[originalIndex] || {};
  if (isFinalized && (!selection || !selection.selectedOption)) {
    const tempAnswers = JSON.parse(localStorage.getItem('temp_vote_answers') || '{}');
    const categoryId = question.category_id;
    const finalizedSel = tempAnswers[categoryId]?.selections?.find(sel => sel.question_id == question.question_id);
    if (finalizedSel) {
      selection = {
        selectedOption: finalizedSel.choice_id || '',
        choiceText: finalizedSel.choice_text || '',
        proofImages: finalizedSel.proof_images || [],
        freetext: finalizedSel.freetext || finalizedSel.manual_input || '',
      };
      state.userSelections[originalIndex] = selection;
    }
  }
  const formGroup = document.createElement('div');
  formGroup.className = 'voter-question-card question-block mb-4';
  formGroup.dataset.originalIndex = originalIndex;
  formGroup.dataset.questionId = question.question_id;
  formGroup.innerHTML =
    `<p class="text-muted small text-center mb-2">Award ${originalIndex + 1} of ${state.questionsData.length}</p>` +
    buildQuestionFieldsHtml(question, selection);
  wireQuestionBlock(formGroup, question, selection, helpers, isFinalized);
  if (state.prevBtn) {
    state.prevBtn.disabled = originalIndex === 0;
    state.prevBtn.classList.remove('d-none');
  }
  if (state.nextBtn) {
    state.nextBtn.disabled = originalIndex === state.questionsData.length - 1;
    state.nextBtn.classList.remove('d-none');
  }
  if (state.toggleViewBtn) state.toggleViewBtn.textContent = 'Change to List View';
}