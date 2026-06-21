import { bootstrapVoterSession } from './voter_bootstrap.js';
import {
  fetchAllCategoriesAndQuestions,
  fetchFinalizedAnswersFromDB,
  fetchExistingAnswersFromDB
} from './summary_data.js';
import { renderSummary } from './summary_renderer.js';
import { hasFinalizedVotes, finalizeAllCategories } from './vote_finalization.js';

window.isFinalized = localStorage.getItem('vote_finalized') === 'true';

const backToCategoriesBtn = document.getElementById('backToCategoriesBtn');
backToCategoriesBtn?.addEventListener('click', (e) => {
  e.preventDefault();
  localStorage.setItem('from_summary', 'true');
  window.location.href = 'category.php';
});

document.addEventListener('DOMContentLoaded', async () => {
  const sessionVoterId = await bootstrapVoterSession();
  if (!sessionVoterId) return;

  const voteAllBtn = document.getElementById('voteAllBtn');

  if (voteAllBtn) window.voteAllBtn = voteAllBtn;

  voteAllBtn?.addEventListener('click', finalizeAllCategories);

  history.pushState(null, null, location.href);
  window.onpopstate = () => history.go(1);

  await fetchAllCategoriesAndQuestions();
  await fetchFinalizedAnswersFromDB();
  if (localStorage.getItem('voter_type') === 'existing') {
    await fetchExistingAnswersFromDB();
  }
  renderSummary();
});

window.addEventListener('storage', (e) => {
  if (e.key === 'allCategoryAnswers' || e.key === 'finalizedAnswers') renderSummary();
});

window.addEventListener('answersUpdated', renderSummary);