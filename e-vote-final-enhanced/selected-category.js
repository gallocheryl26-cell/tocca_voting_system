import { state } from './state.js';
import { bootstrapVoterSession } from './voter_bootstrap.js';
import {
  loadAllDrafts,
  saveDraft,
  loadQuestions,
  loadQuestionsWithChoices,
  loadUserSelectionsFromDB,
} from './data_service.js';
import {
  destroyQuestionChoiceInstances,
  initializeQuestionDropdown,
  updateFieldStates,
  allFreetextPairsValid,
  renderPaginatedQuestions,
  renderSingleQuestion,
  getProofValidationMessage,
} from './question_renderer.js';
import { getProofCount } from './js/vote_proof_upload.js';
import { resolveFieldLabels } from './js/voting_field_labels.js';

let voterId = localStorage.getItem('voter_id') || null;
const eventId = localStorage.getItem('current_event_id') || '1';

function notifyVoter(message, tone = 'warning', delay = 4000) {
  if (typeof window.showToast === 'function') {
    window.showToast(message, tone, delay);
  } else {
    alert(message);
  }
}

const urlParams = new URLSearchParams(window.location.search);
const editQuestionId = urlParams.get("edit_question");
const categoryIdFromURL = urlParams.get("category_id");

let allCategoryAnswers = JSON.parse(localStorage.getItem("allCategoryAnswers") || "{}");
let finalizedVotes = JSON.parse(localStorage.getItem("finalizedVotes") || "{}");
let categoryChoicesInstance = null;
let questionChoiceInstances = [];
let userSelections = {};
let questionsData = []; // Holds ALL questions for the loaded category
let filteredQuestionsData = []; // Holds questions matching the current search term
let allCategories = [];
let showOffset = 0; // Tracks the index for pagination or single question view

const showLimit = 5; // Questions per page in list view

let questionSearchInput;
let categorySwitcherElement;
let categoryTitle, questionsContainer, loadingIndicator, controlsDiv;
let prevBtn, nextBtn, toggleViewBtn, submitVoteBtn, saveDraftBtn;

Object.assign(state, {
  allCategoryAnswers,
  finalizedVotes,
  categoryChoicesInstance,
  questionChoiceInstances,
  userSelections,
  questionsData,
  allCategories,
  showOffset,
  questionSearchInput,
  categorySwitcherElement,
  categoryTitle,
  questionsContainer,
  loadingIndicator,
  controlsDiv,
  prevBtn,
  nextBtn,
  toggleViewBtn,
  submitVoteBtn,
  saveDraftBtn
});

state.showingAll = false;

function debounce(fn, delay = 300) {
  let timeout;
  return (...args) => {
    clearTimeout(timeout);
    timeout = setTimeout(() => fn(...args), delay);
  };
}

const debouncedAutoSave = debounce(() => saveVotesAndRedirect(false), 300);

function applyCategoryVotingLabels(source) {
  if (!source) {
    return;
  }
  if (source.field_labels && source.field_labels.proof_label) {
    state.currentFieldLabels = source.field_labels;
    state.currentVotingProfile = source.field_labels.profile || source.voting_profile || 'business';
    return;
  }
  if (source.voting_profile) {
    state.currentVotingProfile = source.voting_profile;
    state.currentFieldLabels = resolveFieldLabels(source.voting_profile);
  }
}

function hidePrevNextButtons() {
  if (state.prevBtn) state.prevBtn.classList.add('d-none');
  if (state.nextBtn) state.nextBtn.classList.add('d-none');
  if (state.prevBtn && state.prevBtn.parentElement) {
    state.prevBtn.parentElement.classList.add('d-none');
  }
}

window.addEventListener('beforeunload', () => {
  saveCurrentSelections();
  saveCurrentCategoryToGlobal();
});

function removeEditParamFromURL() {
    const url = new URL(window.location.href);
    url.searchParams.delete("edit_question");
    window.history.replaceState({}, document.title, url.toString());
}

function updateCategoryParamInURL(categoryId) {
    const url = new URL(window.location.href);
    url.searchParams.set("category_id", categoryId);
    window.history.replaceState({}, document.title, url.toString());
}

async function fetchAndApplyUserSelections(categoryId) {
    const data = await loadUserSelectionsFromDB(categoryId, voterId, eventId);
    if (data.status === 'success' && Array.isArray(data.selections)) {
        data.selections.forEach(sel => {
            const originalIndex = questionsData.findIndex(q => q.question_id == sel.question_id);
            if (originalIndex !== -1) {
                userSelections[originalIndex] = {
                    selectedOption: sel.choice_id ? sel.choice_id.toString() : "",
                    choiceText: sel.choice_text || "",
                    proofImages: Array.isArray(sel.proof_images) ? sel.proof_images : [],
                    proofCount: Array.isArray(sel.proof_images) ? sel.proof_images.length : 0,
                };
            }
        });
        saveCurrentCategoryToGlobal();
        checkIfAllQuestionsAnsweredGlobally();
    }
}

window.addEventListener("DOMContentLoaded", async () => {
  const sessionVoterId = await bootstrapVoterSession();
  if (!sessionVoterId) return;
  voterId = String(sessionVoterId);

  if (categoryIdFromURL) {
    localStorage.setItem("selected_category_id", categoryIdFromURL);
  }

    categorySwitcherElement = document.getElementById('categorySwitcher');
    categoryTitle = document.getElementById("selectedCategoryTitle");
    questionsContainer = document.getElementById("questionsContainer");
    loadingIndicator = document.getElementById("loadingIndicator");
    controlsDiv = document.getElementById("controls");
    prevBtn = document.getElementById("prevBtn");
    nextBtn = document.getElementById("nextBtn");
    toggleViewBtn = document.getElementById("toggleViewBtn");
    saveDraftBtn = document.getElementById("saveDraftBtn");
    submitVoteBtn = document.getElementById("submitVoteBtn");
    questionSearchInput = document.getElementById('questionSearchInput');

    Object.assign(state, {
      categorySwitcherElement,
      categoryTitle,
      questionsContainer,
      loadingIndicator,
      controlsDiv,
      prevBtn,
      nextBtn,
      toggleViewBtn,
      saveDraftBtn,
      submitVoteBtn,
      questionSearchInput
    });

    if (categorySwitcherElement) {
        try {
            categoryChoicesInstance = new Choices(categorySwitcherElement, {
                searchEnabled: false, 
                itemSelectText: '',
                allowHTML: false,
                placeholder: false,
                removeItemButton: false,
                shouldSort: true,
            });
            state.categoryChoicesInstance = categoryChoicesInstance;
            categoryChoicesInstance.disable();
            console.log("Choices.js initialized for categories (search disabled).");
        } catch (error) {
            console.error("Failed to initialize Choices.js:", error);
            if (categorySwitcherElement) {
                categorySwitcherElement.innerHTML = '<option value="">Error initializing dropdown</option>';
                categorySwitcherElement.disabled = true;
            }
        }
    } else {
        console.error("Category switcher element (#categorySwitcher) not found!");
    }

    loadAllDrafts(voterId, eventId).then(data => {
    if (Array.isArray(data)) {
        const existing = JSON.parse(localStorage.getItem("allCategoryAnswers") || "{}");
        data.forEach(category => {
            const catId = category.category_id;
            const prev = existing[catId] || { category_name: category.category_name, selections: [] };
            const prevSelections = Array.isArray(prev.selections) ? [...prev.selections] : [];

            category.questions.forEach(q => {
                const updated = {
                    question_id: q.question_id,
                    question_name: q.question_name,
                    choice_id: q.choice_id || null,
                    choice_text: q.selected_answer_text || q.choice_text || "",
                    proof_images: Array.isArray(q.proof_images) ? q.proof_images : [],
                };
                const idx = prevSelections.findIndex(sel => sel.question_id == q.question_id);
                const hasDbAnswer = q.choice_id !== null;
                if (idx !== -1) {
                    if (hasDbAnswer) {
                        prevSelections[idx] = updated;
                    }
                } else {
                    prevSelections.push(updated);
                }
            });

            existing[catId] = {
                category_name: category.category_name,
                selections: prevSelections
            };
        });
        localStorage.setItem("allCategoryAnswers", JSON.stringify(existing));
        allCategoryAnswers = existing;
        state.allCategoryAnswers = allCategoryAnswers;
        console.log("Preloaded all draft answers into localStorage (merged)");
    }

    fetch('get_all_categories.php')
  .then(response => {
    if (!response.ok) throw new Error(`HTTP error fetching categories! status: ${response.status}`);
    return response.json();
  })
  .then(data => {
    if (data.status === 'success' && Array.isArray(data.categories)) {
      allCategories = data.categories;

      const voterType = localStorage.getItem("voter_type");
      if (voterType === "existing") {
        const unanswered = JSON.parse(localStorage.getItem("unanswered") || "[]");
        const unansweredCatIds = [...new Set(unanswered.map(q => String(q.category_id)))];
        if (unansweredCatIds.length > 0) {
          allCategories = allCategories.filter(cat => unansweredCatIds.includes(String(cat.id)));
        }
      }

      if (allCategories.length === 0) {
        if (categoryTitle) categoryTitle.textContent = "NO CATEGORY TO VOTE";
        if (questionsContainer) {
          questionsContainer.innerHTML = `
            <div class="voter-empty-state">
              <div class="voter-empty-icon" aria-hidden="true"><i class="fa-solid fa-circle-check"></i></div>
              <p class="voter-empty-title">All categories completed</p>
              <p class="voter-empty-text">You have no remaining categories to answer here. Review and cast your votes on the summary page.</p>
              <a href="summarypoll.php" class="btn-voter-cta">Go to Vote Summary</a>
            </div>`;
        }
        if (controlsDiv) controlsDiv.classList.add('d-none');
        checkIfAllQuestionsAnsweredGlobally();
        return;
      }

      let initialCategoryId = categoryIdFromURL || localStorage.getItem("selected_category_id");

      if (!initialCategoryId && allCategories.length > 0) {
        initialCategoryId = allCategories[0].id.toString();
      }

      populateCategorySwitcher(initialCategoryId);

      const matchedInitial = allCategories.find(cat => cat.id == initialCategoryId);
      if (matchedInitial) {
        const initialCategoryName = matchedInitial.name;
        loadCategory(initialCategoryId, initialCategoryName);
        updateCategoryParamInURL(initialCategoryId);
        console.log(`Loaded category: ${initialCategoryName} (ID: ${initialCategoryId})`);
      } else if (categoryTitle) {
        categoryTitle.textContent = "NO CATEGORY SELECTED";
      }
    } else {
      throw new Error(data.message || 'Failed to load category list from server.');
    }
  })
  .catch(error => {
    console.error("Error fetching category list:", error);
    if (categoryChoicesInstance) {
      categoryChoicesInstance.setChoices(
        [{ value: '', label: 'Error loading categories', selected: true, disabled: true }],
        'value', 'label', true
      );
      categoryChoicesInstance.enable();
    }
  });
    });
    saveCurrentCategoryToGlobal(); 
    checkIfAllQuestionsAnsweredGlobally();
    categorySwitcherElement?.addEventListener('change', (event) => {
        if (!allFreetextPairsValid()) {
            notifyVoter(getProofValidationMessage(true));
            categoryChoicesInstance.setChoiceByValue(localStorage.getItem("selected_category_id") || '');
            return;
        }

        const newCategoryId = categoryChoicesInstance.getValue(true);
        if (!newCategoryId) return;
        saveCurrentSelections();
        saveCurrentCategoryToGlobal();
        if (newCategoryId == localStorage.getItem("selected_category_id")) {
            console.log("Same category re-selected — forcing reload to apply any unsaved changes.");
        }
        const selectedCategory = allCategories.find(cat => cat.id == newCategoryId);
        const newCategoryName = selectedCategory ? selectedCategory.name : 'Unknown Category';
        console.log(`Category switch requested to: ${newCategoryName} (ID: ${newCategoryId})`);

        const dataToSave = getCurrentCategoryAnswersForDB();
        if (dataToSave && dataToSave.selections && dataToSave.selections.length > 0) {
                saveDraft(dataToSave).then(result => console.log("Draft auto-saved on category switch:", result.message));
            }

        if (questionSearchInput) {
            questionSearchInput.value = '';
        }
        loadCategory(newCategoryId, newCategoryName);
        localStorage.setItem("selected_category_id", newCategoryId);
        localStorage.setItem("selected_category_name", newCategoryName);
        updateCategoryParamInURL(newCategoryId);
        console.log(`Context switched to new category: ${newCategoryName}`);
        checkIfAllQuestionsAnsweredGlobally();
    });
    questionSearchInput?.addEventListener('input', () => {
        if (!state.showingAll) {
            console.log("Search works best in 'List View'.");
        }
        filterAndRenderQuestions(); 
    });
    nextBtn?.addEventListener("click", () => {
        if (!allFreetextPairsValid()) {
            notifyVoter(getProofValidationMessage());
            return;
        }
        saveCurrentSelections(); 
        if (state.showingAll) {
            const total = state.filteredQuestionsData.length;
            if (showOffset + showLimit < total) {
                showOffset += showLimit;
                state.showOffset = showOffset;
                renderPaginatedQuestions(state.filteredQuestionsData, { saveCurrentSelections, saveCurrentCategoryToGlobal, saveVotesAndRedirect, checkIfAllQuestionsAnsweredGlobally, restoreTempSelections });
            }
        } else if (showOffset < questionsData.length - 1) { // Still based on original data length for single view
            showOffset++;
            state.showOffset = showOffset;
            renderSingleQuestion({ saveCurrentSelections, saveCurrentCategoryToGlobal, saveVotesAndRedirect, checkIfAllQuestionsAnsweredGlobally });
        }
    });
    prevBtn?.addEventListener("click", () => {
        if (!allFreetextPairsValid()) {
            notifyVoter(getProofValidationMessage());
            return;
        }
        saveCurrentSelections(); 
        if (state.showingAll) {
            if (showOffset > 0) {
                showOffset = Math.max(0, showOffset - showLimit);
                state.showOffset = showOffset;
                renderPaginatedQuestions(state.filteredQuestionsData, { saveCurrentSelections, saveCurrentCategoryToGlobal, saveVotesAndRedirect, checkIfAllQuestionsAnsweredGlobally, restoreTempSelections });
            }
        } else if (showOffset > 0) {
            showOffset--;
            state.showOffset = showOffset;
            renderSingleQuestion({ saveCurrentSelections, saveCurrentCategoryToGlobal, saveVotesAndRedirect, checkIfAllQuestionsAnsweredGlobally });
        }
    });
    toggleViewBtn?.addEventListener("click", () => {
        if (!allFreetextPairsValid()) {
            notifyVoter(getProofValidationMessage());
            return;
        }
        saveCurrentSelections(); 
        if (questionSearchInput) {
            questionSearchInput.value = '';
        }

        if (state.showingAll) { 
            showOffset = 0; 
            state.showOffset = showOffset;
            renderSingleQuestion({ saveCurrentSelections, saveCurrentCategoryToGlobal, saveVotesAndRedirect, checkIfAllQuestionsAnsweredGlobally });
        } else { 
            showOffset = 0; 
            state.showOffset = showOffset;
            filterAndRenderQuestions(); 
        }
        checkIfAllQuestionsAnsweredGlobally();
    });
    saveDraftBtn?.addEventListener("click", saveVotesAndRedirect);
    submitVoteBtn?.addEventListener("click", saveVotesAndRedirect);
    restoreTempSelections();
});
function filterAndRenderQuestions() {
    if (!questionsContainer || !questionSearchInput) return;
    const searchTerm = questionSearchInput.value.trim().toLowerCase();
    filteredQuestionsData = searchTerm
        ? questionsData.filter(q =>
            q.question_name.toLowerCase().includes(searchTerm))
        : [...questionsData];
    state.filteredQuestionsData = filteredQuestionsData;

    if (editQuestionId) {
        const jumpToIndex = questionsData.findIndex(q => q.question_id == editQuestionId);
        if (jumpToIndex !== -1) {
            showOffset = jumpToIndex;
            state.showOffset = showOffset;
        }
    }
    const currentEditId = new URLSearchParams(window.location.search).get("edit_question");
    if (currentEditId) {
        const jumpToIndex = questionsData.findIndex(q => q.question_id == currentEditId);
        if (jumpToIndex !== -1) {
            showOffset = jumpToIndex;
            state.showOffset = showOffset;
            console.log("Restored edit jump to:", currentEditId);
        }
    } else {
        showOffset = 0;
        state.showOffset = showOffset;
    }
    renderPaginatedQuestions(filteredQuestionsData, { saveCurrentSelections, saveCurrentCategoryToGlobal, saveVotesAndRedirect, checkIfAllQuestionsAnsweredGlobally, restoreTempSelections });
}
function saveCurrentCategoryToGlobal() {
  const existing = JSON.parse(localStorage.getItem("allCategoryAnswers") || "{}");
  questionsData.forEach((question, index) => {
    if (!(index in userSelections)) return;
    const sel = userSelections[index] || {};
    const categoryId = question.category_id;
    const categoryName = allCategories.find(cat => parseInt(cat.id) === parseInt(categoryId))?.name || "Unknown";
    const answer = {
      question_id: question.question_id,
      question_name: question.question_name,
      choice_id: sel.selectedOption || null,
      choice_text:
        sel.choiceText ||
        question.choices?.find(c => c.choice_id == sel.selectedOption)?.choice_name ||
        "",
      proof_images: sel.proofImages || [],
    };
    if (!existing[categoryId]) {
      existing[categoryId] = {
        category_name: categoryName,
        selections: []
      };
    }
    existing[categoryId].selections = existing[categoryId].selections.filter(
      q => q.question_id !== answer.question_id
    );
    if (answer.choice_id !== null) {
      existing[categoryId].selections.push(answer);
    }
  });
  localStorage.setItem("allCategoryAnswers", JSON.stringify(existing));
  allCategoryAnswers = existing;
  state.allCategoryAnswers = allCategoryAnswers;
  console.log("[GLOBAL] Saved all current answers to correct categories");
}
let tempSelections = JSON.parse(localStorage.getItem("temp_vote_answers") || "[]");
function restoreTempSelections() {
  const tempAnswers = JSON.parse(
    localStorage.getItem("temp_vote_answers") || "{}"
  );
  const globalAnswers = JSON.parse(
    localStorage.getItem("allCategoryAnswers") || "{}"
  );
  const currentCategoryId =
    localStorage.getItem("selected_category_id") || categoryIdFromURL;

  let selections = [];
  if (
    tempAnswers[currentCategoryId] &&
    Array.isArray(tempAnswers[currentCategoryId].selections)
  ) {
    selections = tempAnswers[currentCategoryId].selections;
  } else if (
    globalAnswers[currentCategoryId] &&
    Array.isArray(globalAnswers[currentCategoryId].selections)
  ) {
    selections = globalAnswers[currentCategoryId].selections;
  } else {
    return;
  }
  selections.forEach((sel) => {
    const index = questionsData.findIndex(
      (q) => q.question_id == sel.question_id
    );
    if (index === -1) return;

    userSelections[index] = {
      selectedOption: sel.choice_id ? sel.choice_id.toString() : "",
      choiceText: sel.choice_text || "",
      proofImages: Array.isArray(sel.proof_images) ? sel.proof_images : [],
      proofCount: Array.isArray(sel.proof_images) ? sel.proof_images.length : 0,
    };
  });
  const blocks = document.querySelectorAll(".question-block");
  blocks.forEach((block) => {
    const questionId = parseInt(block.dataset.questionId);
    const selection = selections.find(
      (item) => parseInt(item.question_id) === questionId
    );
    if (!selection) return;
    const select = block.querySelector("select");
    if (select) {
      select.value = selection.choice_id || "";
      if (select.choicesInstance && selection.choice_id) {
        try {
          select.choicesInstance.setChoiceByValue(String(selection.choice_id));
        } catch (err) {
          console.warn("Failed to sync Choices dropdown", err);
        }
      }
      select.dispatchEvent(new Event("change"));
    }
  });
  checkIfAllQuestionsAnsweredGlobally();
}
function saveCurrentSelections() {
  const questionBlocks = document.querySelectorAll(".question-block");
  questionBlocks.forEach(block => {
    const index = parseInt(block.dataset.originalIndex);
    const select = block.querySelector("select");
    const selectedOption = select ? select.value.trim() : "";
    const selectedText =
      select && select.selectedIndex > 0
        ? select.options[select.selectedIndex].text.trim()
        : "";
    const proofImages = [];
    block.querySelectorAll('.vote-proof-thumb').forEach((thumb) => {
      const img = thumb.querySelector('img');
      proofImages.push({
        proof_id: parseInt(thumb.dataset.proofId || '0', 10),
        url: img?.getAttribute('src') || '',
        is_final: thumb.dataset.isFinal === '1',
      });
    });
    userSelections[index] = {
      selectedOption,
      choiceText: selectedText,
      proofImages,
      proofCount: proofImages.length,
    };
  });
}
function getCurrentCategoryAnswersForDB() {
  saveCurrentSelections();
  const currentCategoryId = localStorage.getItem("selected_category_id") || categoryIdFromURL;
  const category_id = parseInt(currentCategoryId);
  if (isNaN(category_id)) {
    console.error("[SAVE] Invalid category_id detected:", currentCategoryId);
    return null;
  }
    const matchedCategory = allCategories.find(cat => cat.id == category_id);
    const currentCategoryName = matchedCategory ? matchedCategory.name : "Uncategorized";
    const selections = questionsData.map((question, index) => {
        const sel = userSelections[index] || {};
        return {
        question_id: question.question_id,
        question_name: question.question_name,
        choice_id: sel.selectedOption || null,
        freetext: null,
        choice_text:
          sel.choiceText ||
          question.choices?.find(c => c.choice_id == sel.selectedOption)?.choice_name ||
          ""
        };
    });
  return { category_id, category_name: currentCategoryName, voter_id: voterId, selections };
}
async function saveVotesAndRedirect(eOrShouldRedirect = true) {
  const shouldRedirect =
    typeof eOrShouldRedirect === 'boolean'
      ? eOrShouldRedirect
      : eOrShouldRedirect?.target?.id === 'submitVoteBtn';

  if (typeof eOrShouldRedirect === 'object') {
    eOrShouldRedirect.preventDefault?.();
  }
  if (!allFreetextPairsValid()) {
    notifyVoter(getProofValidationMessage());
    return;
  }
  saveCurrentSelections();
  saveCurrentCategoryToGlobal();
  const payload = getCurrentCategoryAnswersForDB();
  if (payload && payload.selections.length > 0) {
    payload.voter_id = voterId;
    try {
      const result = await saveDraft(payload);
      console.log('Draft saved:', result.message || result.status);
    } catch (err) {
      console.error('Failed to save draft', err);
    }
  }
  localStorage.setItem('temp_vote_answers', JSON.stringify(allCategoryAnswers));
  window.dispatchEvent(new Event('answersUpdated'));
  if (shouldRedirect) {
    localStorage.removeItem('from_summary');
    window.location.href = 'summarypoll.php';
  }
}
function checkIfAllQuestionsAnsweredGlobally() {
  if (submitVoteBtn) {
    submitVoteBtn.disabled = false;
  }
}
function populateCategorySwitcher(currentCategoryId = '') {
    if (!categoryChoicesInstance || !allCategories) {
        console.warn("Choices.js instance or category list not available for population.");
        return;
    }
    const voterType = localStorage.getItem("voter_type");
    let choicesToShow = allCategories;
    if (voterType === "existing") {
        const unanswered = JSON.parse(localStorage.getItem("unanswered") || "[]");
        const unansweredCatIds = [...new Set(unanswered.map(q => String(q.category_id)))];
        if (unansweredCatIds.length > 0) {
          choicesToShow = allCategories.filter(cat => unansweredCatIds.includes(String(cat.id)));
        }
    }
    const choices = choicesToShow.map(category => ({
        value: category.id.toString(),
        label: category.name,
        selected: currentCategoryId && category.id == currentCategoryId,
        disabled: false,
    }));
    console.log("Filtered dropdown categories for existing voter:", choices);
    categoryChoicesInstance.setChoices(choices, 'value', 'label', true);
    if (currentCategoryId) {
        categoryChoicesInstance.setChoiceByValue(currentCategoryId.toString());
    }
    categoryChoicesInstance.enable();
}

async function loadCategory(categoryId, categoryName) {
    console.log(`Loading category: ${categoryName} (ID: ${categoryId})`);
    localStorage.setItem("selected_category_id", categoryId);
    saveCurrentCategoryToGlobal();
    questionsData = [];
    filteredQuestionsData = [];
    userSelections = {};
    showOffset = 0;
    state.questionsData = questionsData;
    state.filteredQuestionsData = filteredQuestionsData;
    state.userSelections = userSelections;
    state.showOffset = showOffset;
    state.showingAll = false;
    if (questionSearchInput) {
        questionSearchInput.value = '';
        questionSearchInput.disabled = true;
    }
    const matchedCategory = allCategories.find(cat => cat.id == categoryId);
    const safeCategoryName = matchedCategory?.name || categoryName || "Uncategorized";
    applyCategoryVotingLabels(matchedCategory);
    state.currentCategoryId = categoryId;
    state.currentCategoryName = safeCategoryName;
    if (categoryTitle) {
        categoryTitle.textContent = safeCategoryName;
        localStorage.setItem("selected_category_name", safeCategoryName);
    }
    if (questionsContainer) questionsContainer.innerHTML = '';
    if (loadingIndicator) loadingIndicator.classList.remove('d-none');
    if (controlsDiv) controlsDiv.classList.add('d-none');
        hidePrevNextButtons();
    if (categoryChoicesInstance) categoryChoicesInstance.disable();
    try {
        const data = await loadQuestionsWithChoices(categoryId);
        if (data.status !== "success" || !Array.isArray(data.questions)) {
            throw new Error(data.message || "Failed to load award titles from server.");
        }
        applyCategoryVotingLabels(data);
        if (data.questions.length === 0) {
            console.log(`No award titles found for category ${categoryId}.`);
            questionsContainer.innerHTML = '<p class="text-info text-center mt-4">No award titles available for this category.</p>';
            return;
        }
        const voterType = localStorage.getItem("voter_type");
        const allUnanswered = JSON.parse(localStorage.getItem("unanswered") || "[]");
        if (voterType === "existing" && allUnanswered.length > 0) {
            const unansweredForThisCategory = allUnanswered.filter(q => q.category_id == categoryId);

            if (unansweredForThisCategory.length === 0) {
                questionsContainer.innerHTML = '<p class="text-info text-center mt-4">Responded award titles in this category are all casted.</p>';
                if (loadingIndicator) loadingIndicator.classList.add('d-none');
                return;
            }
            const unvoted = data.questions.filter(q => unansweredForThisCategory.some(uq => uq.question_id == q.question_id));
            questionsData = unvoted.sort((a, b) => a.question_id - b.question_id);
            filteredQuestionsData = [...questionsData];
            state.questionsData = questionsData;
            state.filteredQuestionsData = filteredQuestionsData;
            if (questionsData.length === 0) {
                questionsContainer.innerHTML = `
              <div class="voter-empty-state">
                <p class="voter-empty-title">All votes cast in this category</p>
                <p class="voter-empty-text">Every award title here has been submitted. Pick another category or open your summary.</p>
                <a href="summarypoll.php" class="btn-voter-cta">Go to Vote Summary</a>
              </div>`;
                if (loadingIndicator) loadingIndicator.classList.add('d-none');
                if (controlsDiv) controlsDiv.classList.add('d-none');
                hidePrevNextButtons();
                if (categoryChoicesInstance) {
                    categoryChoicesInstance.enable();
                    categoryChoicesInstance.setChoiceByValue(categoryId.toString());
                }
                checkIfAllQuestionsAnsweredGlobally();
                return;
            }
            const currentEditId = new URLSearchParams(window.location.search).get("edit_question");
            if (currentEditId) {
                const jumpToIndex = questionsData.findIndex(q => q.question_id == currentEditId);
                if (jumpToIndex !== -1) {
                    showOffset = jumpToIndex;
                    state.showOffset = showOffset;
                    console.log("Will scroll to question ID:", currentEditId, "at index", jumpToIndex);
                } else {
                    console.warn("Edit question ID not found in questionsData:", currentEditId);
                }
            }
            await fetchAndApplyUserSelections(categoryId);
            if (questionsData.length > 0) {
                renderSingleQuestion({ saveCurrentSelections, saveCurrentCategoryToGlobal, saveVotesAndRedirect, checkIfAllQuestionsAnsweredGlobally });
                restoreTempSelections();
                if (controlsDiv) controlsDiv.classList.remove('d-none');
                if (questionSearchInput) questionSearchInput.disabled = false;
            } else {
                questionsContainer.innerHTML = '<p class="text-info text-center mt-4">Award titles for this category are unavailable.</p>';
                if (controlsDiv) controlsDiv.classList.add('d-none');
                hidePrevNextButtons();
            }
            if (loadingIndicator) loadingIndicator.classList.add('d-none');
            if (categoryChoicesInstance) {
                categoryChoicesInstance.enable();
                categoryChoicesInstance.setChoiceByValue(categoryId.toString());
            }
            checkIfAllQuestionsAnsweredGlobally();
            return;
        }
        const finalizedLocal = JSON.parse(localStorage.getItem("finalizedAnswers") || "{}");
        const finalizedDB = JSON.parse(localStorage.getItem("finalizedFromDB") || "{}");
        const unvoted = data.questions.filter(q => !(finalizedLocal[q.question_id] || finalizedDB[q.question_id]));
        questionsData = unvoted.sort((a, b) => a.question_id - b.question_id);
        filteredQuestionsData = [...questionsData];
        state.questionsData = questionsData;
        state.filteredQuestionsData = filteredQuestionsData;
        if (questionsData.length === 0) {
            questionsContainer.innerHTML = `
              <div class="voter-empty-state">
                <p class="voter-empty-title">All votes cast in this category</p>
                <p class="voter-empty-text">Every award title here has been submitted. Pick another category or open your summary.</p>
                <a href="summarypoll.php" class="btn-voter-cta">Go to Vote Summary</a>
              </div>`;
            if (loadingIndicator) loadingIndicator.classList.add('d-none');
            if (controlsDiv) controlsDiv.classList.add('d-none');
            hidePrevNextButtons();
            if (categoryChoicesInstance) {
                categoryChoicesInstance.enable();
                categoryChoicesInstance.setChoiceByValue(categoryId.toString());
            }
            checkIfAllQuestionsAnsweredGlobally();
            return;
        }
        const currentEditId = new URLSearchParams(window.location.search).get("edit_question");
        if (currentEditId) {
            const jumpToIndex = questionsData.findIndex(q => q.question_id == currentEditId);
            if (jumpToIndex !== -1) {
                showOffset = jumpToIndex;
                state.showOffset = showOffset;
                console.log("Jumping to edited question ID:", currentEditId, "at index", jumpToIndex);
            }
        }
        await fetchAndApplyUserSelections(categoryId);
        if (allCategoryAnswers[categoryId]) {
            console.log(`[RESTORE] Rehydrating selections from memory for category ${categoryId}`);
            const selections = allCategoryAnswers[categoryId].selections;
            selections.forEach(sel => {
                const index = questionsData.findIndex(q => q.question_id == sel.question_id);
                if (index !== -1) {
                    userSelections[index] = {
                        selectedOption: sel.choice_id ? sel.choice_id.toString() : "",
                        choiceText: sel.choice_text || "",
                        proofImages: Array.isArray(sel.proof_images) ? sel.proof_images : [],
                        proofCount: Array.isArray(sel.proof_images) ? sel.proof_images.length : 0,
                    };
                }
            });
        }
        if (questionsData.length > 0) {
            renderSingleQuestion({ saveCurrentSelections, saveCurrentCategoryToGlobal, saveVotesAndRedirect, checkIfAllQuestionsAnsweredGlobally });
            restoreTempSelections();
            if (controlsDiv) controlsDiv.classList.remove('d-none');
            if (questionSearchInput) questionSearchInput.disabled = false;
        } else {
            questionsContainer.innerHTML = '<p class="text-info text-center mt-4">Award titles for this category are unavailable.</p>';
            controlsDiv.classList.add('d-none');
            hidePrevNextButtons();
        }
    } catch (error) {
        console.error(`Error loading category ${categoryId}:`, error);
        questionsContainer.innerHTML = `<p class="alert alert-danger text-center mt-4">Error loading award titles: ${error.message}</p>`;
        controlsDiv.classList.add('d-none');
        hidePrevNextButtons();
        if (questionSearchInput) questionSearchInput.disabled = true;
    } finally {
        if (loadingIndicator) loadingIndicator.classList.add('d-none');
        if (categoryChoicesInstance) {
            categoryChoicesInstance.enable();
            categoryChoicesInstance.setChoiceByValue(categoryId.toString());
        }
        checkIfAllQuestionsAnsweredGlobally();
        console.log(`Finished loading attempt for category ${categoryId}`);
    }
}
