import { state } from './state.js';
import { getActiveFieldLabels, getLabelsForQuestion } from './js/voting_field_labels.js';
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

function getListInstruction(labels = null) {
  const L = labels || getActiveFieldLabels(state);
  return L.list_instruction || 'Pick your choice from the list. Proof of purchase below is optional.';
}

export function getProofValidationMessage(forCategorySwitch = false) {
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

function buildChoiceSelectItems(choices, selectedValue = '') {
  const selected = selectedValue != null && selectedValue !== '' ? String(selectedValue) : '';
  const seen = new Set();
  const items = [];
  (choices || []).forEach(choice => {
    const value = String(choice.choice_id ?? '');
    if (!value || seen.has(value)) return;
    seen.add(value);
    items.push({
      value,
      label: buildChoiceOptionLabel(choice),
      selected: value === selected,
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

export function initializeQuestionDropdown(selectEl, choices = [], selectedValue = '') {
  if (!selectEl) return;
  const selected =
    selectedValue !== '' && selectedValue != null
      ? String(selectedValue)
      : (selectEl.value ? String(selectEl.value) : '');
  const choiceItems = buildChoiceSelectItems(choices, selected);
  const searchEnabled = choiceItems.length >= 8;

  try {
    if (selectEl.choicesInstance) {
      try {
        selectEl.choicesInstance.destroy();
      } catch (e) {}
      selectEl.choicesInstance = null;
    }
    // Avoid duplicating placeholder: Choices reads existing <option> nodes if any remain.
    selectEl.innerHTML = '';
    selectEl.removeAttribute('data-choice');
    selectEl.removeAttribute('data-choice-orig-style');

    const instance = new Choices(selectEl, {
      searchEnabled,
      itemSelectText: '',
      shouldSort: false,
      allowHTML: true,
      placeholder: true,
      placeholderValue: 'Choose a business…',
      searchPlaceholderValue: 'Search businesses…',
      noResultsText: 'No businesses found',
      noChoicesText: 'No businesses listed for this award',
      choices: choiceItems
    });
    state.questionChoiceInstances.push(instance);
    selectEl.choicesInstance = instance;
  } catch (err) {
    console.error('Failed to init dropdown:', err);
  }
}

export function updateFieldStates(selectEl) {
  const formGroup = selectEl?.closest('.question-block');
  if (formGroup) {
    refreshPreviewControls(formGroup, formGroup._choiceList || []);
    setProofSectionEnabled(formGroup, Boolean(selectEl?.value));
  }
}

export function validateFreetextPair() {
  return true;
}

export function allFreetextPairsValid(scope = document) {
  return markInvalidProofSections(scope);
}

function buildQuestionFieldsHtml(question, selection = {}) {
  const labels = getLabelsForQuestion(question, state);
  const hasChoices = (question.choices || []).length > 0;
  let html = `<label class="form-label fw-bold">${escapeHtml(question.question_name)}</label>`;
  if (!hasChoices) {
    html += `<p class="text-muted small mt-2">No businesses listed for this award yet.</p>`;
    return html;
  }
  const selectedChoice =
    (question.choices || []).find((c) => selection.selectedOption == c.choice_id) || null;
  html += `<label class="form-label mt-2 fw-normal text-primary d-block">${escapeHtml(getListInstruction(labels))}</label>`;
  html += `<select class="form-select mb-2 choice-select-with-logos" aria-label="Answer for ${escapeHtml(question.question_name)}" required></select>`;
  html += buildMediaHintHtml(question.choices, selectedChoice ? selectedChoice.choice_id : '');
  html += buildViewBusinessButtonHtml(
    selectedChoice ? selectedChoice.choice_id : '',
    selectedChoice ? selectedChoice.choice_name : '',
    selectedChoice ? selectedChoice.has_media : false
  );
  html += buildProofUploadHtml(question.question_id, selection.proofImages || [], labels);
  return html;
}

function wireQuestionBlock(formGroup, question, selection, helpers, isFinalized) {
  const { saveCurrentSelections, saveCurrentCategoryToGlobal, saveVotesAndRedirect, checkIfAllQuestionsAnsweredGlobally } =
    helpers;
  const hasChoices = (question.choices || []).length > 0;
  if (!hasChoices) {
    state.questionsContainer.appendChild(formGroup);
    return;
  }

  const selectDropdown = formGroup.querySelector('select');
  initializeQuestionDropdown(selectDropdown, question.choices || [], selection.selectedOption || '');
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
      state.questionsContainer.innerHTML = `<p class="text-muted text-center mt-3">No questions found matching "${searchTerm}".</p>`;
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
  const finalizedAnswers = JSON.parse(localStorage.getItem('finalizedAnswers') || '{}');
  const finalizedFromDB = JSON.parse(localStorage.getItem('finalizedFromDB') || '{}');
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