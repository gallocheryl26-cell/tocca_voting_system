import { state } from './state.js';
import { getActiveFieldLabels, getLabelsForQuestion } from './js/voting_field_labels.js';

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

/** Plain-language manual entry when no dropdown choices (choice_type 0). */
function buildManualDualFieldsHtml(selection = {}, question = null, labels = null) {
  const questionName = typeof question === 'string' ? question : question?.question_name || '';
  const L = labels || (question && typeof question === 'object' ? getLabelsForQuestion(question, state) : getActiveFieldLabels(state));
  const q = escapeHtml(questionName);
  const answerVal = escapeHtml(selection.manualAnswer || '');
  const estVal = escapeHtml(selection.manualEstablishment || '');
  const f1 = escapeHtml(L.field1_label);
  const f2 = escapeHtml(L.field2_label);
  const p1 = escapeHtml(L.field1_placeholder);
  const p2 = escapeHtml(L.field2_placeholder);
  const instruction = escapeHtml(L.instruction);
  return (
    `<label class="form-label mt-2 fw-normal text-primary d-block">${instruction}</label>` +
    `<div class="row g-2 mt-1">` +
    `<div class="col-12 col-md-6">` +
    `<label class="form-label d-block">${f1}</label>` +
    `<input type="text" class="form-control manual-answer w-100" placeholder="${p1}" value="${answerVal}" aria-label="${f1} for ${q}" autocomplete="off" inputmode="text" required>` +
    `</div>` +
    `<div class="col-12 col-md-6">` +
    `<label class="form-label d-block">${f2}</label>` +
    `<input type="text" class="form-control manual-establishment w-100" placeholder="${p2}" value="${estVal}" aria-label="${f2} for ${q}" autocomplete="off" inputmode="text" required>` +
    `</div></div>`
  );
}

/** Manual fallback when a dropdown is shown but the choice is not listed. */
function buildManualOtherFieldHtml(selection = {}, question = null, labels = null) {
  const questionName = typeof question === 'string' ? question : question?.question_name || '';
  const L = labels || (question && typeof question === 'object' ? getLabelsForQuestion(question, state) : getActiveFieldLabels(state));
  const q = escapeHtml(questionName);
  const answerVal = escapeHtml(selection.manualAnswer || '');
  const otherLabel = escapeHtml(L.other_label);
  const otherPh = escapeHtml(L.other_placeholder);
  return (
    `<label class="form-label d-block">${otherLabel}</label>` +
    `<input type="text" class="form-control manual-answer" placeholder="${otherPh}" value="${answerVal}" aria-label="Other choice for ${q}" autocomplete="off" inputmode="text">`
  );
}

function getListInstruction(labels = null) {
  const L = labels || getActiveFieldLabels(state);
  return L.list_instruction || 'Pick from the list. If you do not see your choice, type it in the box below.';
}

export function getManualValidationMessage(forCategorySwitch = false) {
  const L = getActiveFieldLabels(state);
  return forCategorySwitch
    ? L.validation_message_switch || L.validation_message
    : L.validation_message;
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

/** Keep preview controls in sync with dropdown vs manual-answer mode. */
function refreshPreviewControls(formGroup, choices = []) {
  const select = formGroup.querySelector('select');
  let btn = formGroup.querySelector('.view-choice-media-btn');
  const hint = formGroup.querySelector('.choice-media-hint');
  const answerInput = formGroup.querySelector('.manual-answer');
  const estInput = formGroup.querySelector('.manual-establishment');

  const manualText = answerInput?.value.trim() || '';
  const estText = estInput?.value.trim() || '';
  const usingManual = manualText !== '' || estText !== '';
  const selectValue = select && !select.disabled ? select.value : '';

  const hidePreviewBtn = (targetBtn) => {
    if (!targetBtn) return;
    targetBtn.dataset.choiceId = '';
    targetBtn.dataset.choiceName = '';
    targetBtn.disabled = true;
    targetBtn.classList.add('d-none');
    targetBtn.setAttribute('aria-hidden', 'true');
  };

  if (usingManual || !selectValue) {
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

export function updateFieldStates(selectEl, answerEl, estEl = null) {
  if (!answerEl) return;

  const isTextFocused =
    document.activeElement === answerEl ||
    (estEl && document.activeElement === estEl);

  if (selectEl && selectEl.value && !isTextFocused) {
    answerEl.value = '';
    if (estEl) {
      estEl.value = '';
      estEl.disabled = true;
    }
  } else {
    answerEl.disabled = false;
    if (estEl) estEl.disabled = false;
  }

  if (answerEl.value.trim() !== '' || (estEl && estEl.value.trim() !== '')) {
    if (selectEl) {
      selectEl.value = '';
      selectEl.disabled = true;
            if (selectEl.choicesInstance) {
        try {
          selectEl.choicesInstance.removeActiveItems();
        } catch (e) {}
      }
    }
  } else {
    if (selectEl) selectEl.disabled = false;
  }

  if (estEl) {
    validateFreetextPair(answerEl, estEl);
  }

  const formGroup = answerEl.closest('.question-block');
  if (formGroup) {
    refreshPreviewControls(formGroup, formGroup._choiceList || []);
  }
}

export function validateFreetextPair(ansEl, estEl) {
  if (!ansEl || !estEl) return true;
  const ansVal = ansEl.value.trim();
  const estVal = estEl.value.trim();
  const bothEmpty = ansVal === '' && estVal === '';
  const bothFilled = ansVal !== '' && estVal !== '';
  const partial = !bothEmpty && !bothFilled;
  ansEl.classList.toggle('is-invalid', partial);
  estEl.classList.toggle('is-invalid', partial);
  return !partial;
}

export function allFreetextPairsValid(scope = document) {
  let valid = true;
  scope.querySelectorAll('.question-block').forEach(block => {
    const ans = block.querySelector('.manual-answer');
    const est = block.querySelector('.manual-establishment');
    if (ans && est) {
      if (!validateFreetextPair(ans, est)) {
        valid = false;
      }
    }
  });
  return valid;
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
    let inputFields = `<label class="form-label fw-bold">${escapeHtml(question.question_name)}</label>`;
    if (question.choice_type === 1) {
      const selectedChoice = (question.choices || []).find(c => selection.selectedOption == c.choice_id) || null;
      inputFields += `<label class="form-label mt-2 fw-normal text-primary d-block">${escapeHtml(getListInstruction())}</label><select class="form-select mb-2 choice-select-with-logos" aria-label="Answer for question ${escapeHtml(question.question_name)}"></select>`;
      inputFields += buildMediaHintHtml(question.choices, selectedChoice ? selectedChoice.choice_id : '');
      inputFields += buildViewBusinessButtonHtml(
        selectedChoice ? selectedChoice.choice_id : '',
        selectedChoice ? selectedChoice.choice_name : '',
        selectedChoice ? selectedChoice.has_media : false
      );
    }
    if (question.choice_type === 0) {
      inputFields += buildManualDualFieldsHtml(selection, question);
    } else {
      inputFields += buildManualOtherFieldHtml(selection, question);
    }
    formGroup.innerHTML = inputFields;
    const selectDropdown = formGroup.querySelector('select');
    initializeQuestionDropdown(selectDropdown, question.choices || [], selection.selectedOption || '');
    attachViewBusinessHandlers(formGroup, question.choices || []);
    const finalizedAnswers = JSON.parse(localStorage.getItem('finalizedAnswers') || '{}');
    const isFinalized = finalizedAnswers[String(question.question_id)] === true;
    if (isFinalized) {
      formGroup.classList.add('bg-light', 'border-success', 'position-relative');
      const badge = document.createElement('span');
      badge.textContent = 'Voted';
      badge.className = 'badge bg-success position-absolute top-0 end-0 m-2';
      formGroup.appendChild(badge);
      const select = formGroup.querySelector('select');
      const answerInput = formGroup.querySelector('.manual-answer');
      const estInput = formGroup.querySelector('.manual-establishment');
      if (select) select.disabled = true;
      if (answerInput) answerInput.disabled = true;
      if (estInput) estInput.disabled = true;
      state.questionsContainer.appendChild(formGroup);
      if (state.prevBtn) {
        state.prevBtn.disabled = originalIndex === 0;
        state.prevBtn.classList.remove('d-none');
      }
      if (state.nextBtn) {
        state.nextBtn.disabled = originalIndex === state.questionsData.length - 1;
        state.nextBtn.classList.remove('d-none');
      }
      if (state.toggleViewBtn) state.toggleViewBtn.textContent = 'List View';
      restoreTempSelections && restoreTempSelections();
      return;
    }
    const select = formGroup.querySelector('select');
    const answerInput = formGroup.querySelector('.manual-answer');
    const estInput = formGroup.querySelector('.manual-establishment');
    if (question.choice_type == 1) {
      if (select) {
        select.addEventListener('change', () => {
          updateFieldStates(select, answerInput || estInput);
          saveCurrentSelections();
          saveCurrentCategoryToGlobal();
          saveVotesAndRedirect && saveVotesAndRedirect(false);
          checkIfAllQuestionsAnsweredGlobally && checkIfAllQuestionsAnsweredGlobally();
        });
      }
      if (answerInput) {
        answerInput.addEventListener('input', () => {
          updateFieldStates(select, answerInput);
          saveCurrentSelections();
          saveCurrentCategoryToGlobal();
          saveVotesAndRedirect && saveVotesAndRedirect(false);
          checkIfAllQuestionsAnsweredGlobally && checkIfAllQuestionsAnsweredGlobally();
        });
      }
      updateFieldStates(select, answerInput);
    } else if (question.choice_type === 0) {
      if (answerInput) answerInput.removeAttribute('required');
      if (estInput) estInput.removeAttribute('required');
      const pairedAutoSave = debounce(() => {
        saveCurrentSelections();
        saveCurrentCategoryToGlobal();
        saveVotesAndRedirect && saveVotesAndRedirect(false);
      }, 500);
      const handleInput = () => {
        updateFieldStates(null, answerInput, estInput);
        saveCurrentSelections();
        saveCurrentCategoryToGlobal();
        checkIfAllQuestionsAnsweredGlobally && checkIfAllQuestionsAnsweredGlobally();
      };
      const handleBlur = () => {
        if (answerInput.value.trim() !== '' && estInput.value.trim() !== '') {
          pairedAutoSave();
        }
      };
      if (answerInput) {
        answerInput.addEventListener('input', handleInput);
        answerInput.addEventListener('blur', handleBlur);
      }
      if (estInput) {
        estInput.addEventListener('input', handleInput);
        estInput.addEventListener('blur', handleBlur);
      }
      updateFieldStates(null, answerInput, estInput);
    } else {
      if (select) {
        select.addEventListener('change', () => {
          saveCurrentSelections();
          saveCurrentCategoryToGlobal();
          saveVotesAndRedirect && saveVotesAndRedirect(false);
          checkIfAllQuestionsAnsweredGlobally && checkIfAllQuestionsAnsweredGlobally();
        });
      }
      if (answerInput) {
        answerInput.addEventListener('input', () => {
          saveCurrentSelections();
          saveCurrentCategoryToGlobal();
          saveVotesAndRedirect && saveVotesAndRedirect(false);
          checkIfAllQuestionsAnsweredGlobally && checkIfAllQuestionsAnsweredGlobally();
        });
      }
    }
    state.questionsContainer.appendChild(formGroup);
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
  const isFinalized = finalizedAnswers[String(question.question_id)] === true;
  let selection = state.userSelections[originalIndex] || {};
  if (isFinalized && (!selection || (!selection.manualAnswer && !selection.selectedOption))) {
    const tempAnswers = JSON.parse(localStorage.getItem('temp_vote_answers') || '{}');
    const categoryId = question.category_id;
    const finalizedSel = tempAnswers[categoryId]?.selections?.find(sel => sel.question_id == question.question_id);
    if (finalizedSel) {
      selection = {
        selectedOption: finalizedSel.choice_id || '',
        manualInput: finalizedSel.freetext || '',
        manualAnswer: '',
        manualEstablishment: ''
      };
      if (finalizedSel.freetext && finalizedSel.freetext.includes(' - ')) {
        const [answer, source] = finalizedSel.freetext.split(' - ');
        selection.manualAnswer = answer || '';
        selection.manualEstablishment = source || '';
      } else {
        selection.manualAnswer = finalizedSel.freetext || '';
      }
      state.userSelections[originalIndex] = selection;
    }
  }
  const formGroup = document.createElement('div');
  formGroup.className = 'voter-question-card question-block mb-4';
  formGroup.dataset.originalIndex = originalIndex;
  formGroup.dataset.questionId = question.question_id;
  let inputFields = `<p class="text-muted small text-center mb-2">Award ${originalIndex + 1} of ${state.questionsData.length}</p><label class="form-label fw-bold d-block mb-3">${escapeHtml(question.question_name)}</label>`;
  if (question.choice_type === 1) {
    const selectedChoice = (question.choices || []).find(c => selection.selectedOption == c.choice_id) || null;
    inputFields += `<label class="form-label mt-2 fw-normal text-primary d-block">${escapeHtml(getListInstruction())}</label><select class="form-select mb-2 choice-select-with-logos" aria-label="Answer for award ${escapeHtml(question.question_name)}"></select>`;
    inputFields += buildMediaHintHtml(question.choices, selectedChoice ? selectedChoice.choice_id : '');
    inputFields += buildViewBusinessButtonHtml(
      selectedChoice ? selectedChoice.choice_id : '',
      selectedChoice ? selectedChoice.choice_name : '',
      selectedChoice ? selectedChoice.has_media : false
    );
  }
  if (question.choice_type === 0) {
    inputFields += buildManualDualFieldsHtml(selection, question);
  } else {
    inputFields += buildManualOtherFieldHtml(selection, question);
  }
  formGroup.innerHTML = inputFields;
  const selectDropdown = formGroup.querySelector('select');
  initializeQuestionDropdown(selectDropdown, question.choices || [], selection.selectedOption || '');
  attachViewBusinessHandlers(formGroup, question.choices || []);
  const select = formGroup.querySelector('select');
  const answerInput = formGroup.querySelector('.manual-answer');
  const estInput = formGroup.querySelector('.manual-establishment');
  if (isFinalized) {
    formGroup.classList.add('bg-light', 'border-success', 'position-relative');
    const badge = document.createElement('span');
    badge.textContent = 'Voted';
    badge.className = 'badge bg-success position-absolute top-0 end-0 m-2';
    formGroup.appendChild(badge);
    if (select) select.disabled = true;
    if (answerInput) answerInput.disabled = true;
    if (estInput) estInput.disabled = true;
    state.questionsContainer.appendChild(formGroup);
    if (state.prevBtn) {
      state.prevBtn.disabled = originalIndex === 0;
      state.prevBtn.classList.remove('d-none');
    }
    if (state.nextBtn) {
      state.nextBtn.disabled = originalIndex === state.questionsData.length - 1;
      state.nextBtn.classList.remove('d-none');
    }
    if (state.toggleViewBtn) state.toggleViewBtn.textContent = 'Change to List View';
    return;
  }
  if (question.choice_type === 1) {
    if (select) {
      select.addEventListener('change', () => {
        updateFieldStates(select, answerInput);
        saveCurrentSelections();
        saveCurrentCategoryToGlobal();
        saveVotesAndRedirect && saveVotesAndRedirect(false);
        checkIfAllQuestionsAnsweredGlobally && checkIfAllQuestionsAnsweredGlobally();
      });
    }
    if (answerInput) {
      answerInput.addEventListener('input', () => {
        updateFieldStates(select, answerInput);
        saveCurrentSelections();
        saveCurrentCategoryToGlobal();
        saveVotesAndRedirect && saveVotesAndRedirect(false);
        checkIfAllQuestionsAnsweredGlobally && checkIfAllQuestionsAnsweredGlobally();
      });
    }
    updateFieldStates(select, answerInput);
  } else if (question.choice_type === 0) {
    if (answerInput) answerInput.removeAttribute('required');
    if (estInput) estInput.removeAttribute('required');
    const pairSave = debounce(() => {
      saveCurrentSelections();
      saveCurrentCategoryToGlobal();
      saveVotesAndRedirect && saveVotesAndRedirect(false);
    }, 500);
    const handlePairInput = () => {
      updateFieldStates(null, answerInput, estInput);
      saveCurrentSelections();
      saveCurrentCategoryToGlobal();
      checkIfAllQuestionsAnsweredGlobally && checkIfAllQuestionsAnsweredGlobally();
    };
    const maybeSave = () => {
      if (answerInput.value.trim() !== '' && estInput.value.trim() !== '') {
        pairSave();
      }
    };
    if (answerInput) {
      answerInput.addEventListener('input', handlePairInput);
      answerInput.addEventListener('blur', maybeSave);
    }
    if (estInput) {
      estInput.addEventListener('input', handlePairInput);
      estInput.addEventListener('blur', maybeSave);
    }
    updateFieldStates(null, answerInput, estInput);
  } else {
    if (select) {
      select.addEventListener('change', () => {
        saveCurrentSelections();
        saveCurrentCategoryToGlobal();
        saveVotesAndRedirect && saveVotesAndRedirect(false);
        checkIfAllQuestionsAnsweredGlobally && checkIfAllQuestionsAnsweredGlobally();
      });
    }
    if (answerInput) {
      answerInput.addEventListener('input', () => {
        saveCurrentSelections();
        saveCurrentCategoryToGlobal();
        saveVotesAndRedirect && saveVotesAndRedirect(false);
        checkIfAllQuestionsAnsweredGlobally && checkIfAllQuestionsAnsweredGlobally();
      });
    }
  }
  state.questionsContainer.appendChild(formGroup);
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