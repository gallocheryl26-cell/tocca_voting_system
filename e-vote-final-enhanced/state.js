function readLocalJson(key, fallback) {
  try {
    const raw = localStorage.getItem(key);
    if (!raw) return fallback;
    return JSON.parse(raw);
  } catch (e) {
    return fallback;
  }
}

export const state = {
  allCategoryAnswers: readLocalJson('allCategoryAnswers', {}),
  finalizedVotes: readLocalJson('finalizedVotes', {}),
  categoryChoicesInstance: null,
  questionChoiceInstances: [],
  userSelections: {},
  questionsData: [],
  filteredQuestionsData: [],
  allCategories: [],
  showingAll: false,
  showOffset: 0,
  currentCategoryId: null,
  currentCategoryName: '',
  currentVotingProfile: 'business',
  currentFieldLabels: null,
  categorySwitcherElement: null,
  categoryTitle: null,
  questionsContainer: null,
  loadingIndicator: null,
  controlsDiv: null,
  prevBtn: null,
  nextBtn: null,
  toggleViewBtn: null,
  submitVoteBtn: null,
  saveDraftBtn: null,
  questionSearchInput: null
};