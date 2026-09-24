import {
  finalizeQuestion,
  finalizeAllCategories,
  hasFinalizedVotes
} from './vote_finalization.js?v=cast4';

// Avoid fetching the entire catalog when finalizing from QR page
window.fetchAllCategoriesAndQuestions = async () => {};

// Apply voter UI styles
fetch('get_voter_style.php')
  .then((res) => res.json())
  .then((data) => window.applyVoterAppearance?.(data));

const voteAllBtn = document.getElementById('voteAllBtn');
const finishBtn = document.getElementById('finishBtn');
const openLegacyBtn = document.getElementById('openLegacyBtn');
const qrCompletePanel = document.getElementById('qrCompletePanel');
const qrCategoryChip = document.getElementById('qrCategoryChip');
const qrHeroText = document.querySelector('.voter-hero--qr p');
const summaryContainer = document.getElementById('summaryContainer');
const voteCountText = document.getElementById('voteCountText');
const categoryCountText = document.getElementById('categoryCountText');

let voterId = localStorage.getItem('voter_id') || null;
const name = localStorage.getItem('qr_business') || 'Unknown Business';
const currentChoiceId = localStorage.getItem('qr_choice_id');
const lastChoiceId = localStorage.getItem('qr_last_choice_id');
const otpMobileInput = document.getElementById('otpMobileInput');
localStorage.setItem('qr_last_choice_id', currentChoiceId);

let finalizedFromDB = JSON.parse(localStorage.getItem('finalizedFromDB') || '{}');
let isVoteAllInProgress = false;

function notify(message, tone = 'info', duration = 3500) {
  if (typeof window.showToast === 'function') {
    window.showToast(message, tone, duration);
  }
}

async function loadFinalizedAnswersFromDB() {
  const storedId = localStorage.getItem('voter_id');
  let eventId = localStorage.getItem('current_event_id');
  if (!storedId) return;
  if (!eventId) {
    eventId = typeof window.ensureCurrentEventId === 'function'
      ? await window.ensureCurrentEventId()
      : '';
  }

  voterId = storedId;

  try {
    const resAns = await fetch('get_finalized_answers.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ voter_id: storedId, event_id: eventId })
    });
    const dataAns = await resAns.json();
    if (dataAns.status === 'success' && Array.isArray(dataAns.answers)) {
      dataAns.answers.forEach(q => {
        finalizedFromDB[parseInt(q)] = true;
      });
      localStorage.setItem('finalizedFromDB', JSON.stringify(finalizedFromDB));
    }
  } catch (err) {
    console.error('Failed to load finalized questions from DB', err);
  }
}

let total = 0;
let answered = 0;
let currentCategories = [];

const businessName = document.getElementById('businessName');
if (businessName && name) {
  businessName.textContent = name;
}

// Load business questions and render them grouped
async function loadBusinessQuestions() {
  const choiceId = localStorage.getItem('qr_choice_id');
  if (!choiceId) {
    summaryContainer.innerHTML = "<p class='text-danger mb-0'>Invalid business ID. Please return to the QR voting start page.</p>";
    notify('Invalid business ID. Please restart from the QR page.', 'danger');
    return;
  }

  try {
    const res = await fetch(`get_business_questions.php?choice_id=${choiceId}`, { credentials: 'same-origin' });
    const data = await res.json();
    if (data.status === 'success' && Array.isArray(data.categories)) {
      currentCategories = data.categories;
      renderSummaryGrouped(data.categories);
    } else {
      summaryContainer.innerHTML = "<p class='text-danger mb-0'>No award titles found for this business.</p>";
      notify('No award titles found for this business.', 'warning');
    }
  } catch (err) {
    console.error('Error loading business questions:', err);
    summaryContainer.innerHTML = "<p class='text-danger mb-0'>Failed to load award titles. Please refresh and try again.</p>";
    notify('Failed to load award titles.', 'danger');
  }
}

// Render questions grouped by category
function renderSummaryGrouped(categories) {
  summaryContainer.innerHTML = '';
  const allAnswers = JSON.parse(localStorage.getItem('allCategoryAnswers') || '{}');
  const finalized = JSON.parse(localStorage.getItem('finalizedAnswers') || '{}');

  total = 0;
  answered = 0;

  categories.forEach(cat => {
    const questions = cat.questions;
    if (questions.length === 0) return;

    const section = document.createElement('div');
    section.className = 'category-box';

    const header = document.createElement('h3');
    header.className = 'category-title';
    header.textContent = cat.category_name;
    section.appendChild(header);

    questions.forEach(q => {
      total++;
      const isAnswered = finalized[q.question_id] || finalizedFromDB[q.question_id];
      if (isAnswered) answered++;

      const questionDiv = document.createElement('div');
      questionDiv.className = 'qr-award-row';
      questionDiv.dataset.questionId = String(q.question_id);
      const isMeryenda = /meryenda/i.test(q.question_name || '');

      const questionLabel = document.createElement('div');
      questionLabel.className = 'qr-award-row__name';
      questionLabel.textContent = q.question_name;

      let actionEl;
      if (isAnswered) {
        actionEl = document.createElement('span');
        actionEl.className = 'qr-award-row__status';
        actionEl.textContent = 'Voted';
      } else {
        const voteBtn = document.createElement('button');
        voteBtn.className = 'btn btn-sm btn-outline-success qr-award-row__btn';
        voteBtn.textContent = 'Vote';
        actionEl = voteBtn;
        voteBtn.addEventListener('click', async () => {
        const confirmMsg = `Cast your vote for "${name}" in "${q.question_name}"?`;
        const confirmed = (typeof window.showVoterConfirm === 'function')
          ? await window.showVoterConfirm({
              title: 'Confirm vote',
              message: confirmMsg,
              confirmText: 'Cast vote',
              cancelText: 'Cancel',
              confirmClass: 'btn-success'
            })
          : window.confirm(confirmMsg);
        if (!confirmed) return;
        const whereToBuy = isMeryenda
          ? String(questionDiv.querySelector('.meryenda-where')?.value || '').trim()
          : '';
        if (isMeryenda && !whereToBuy) {
          notify('Please enter the vendor name and location.', 'warning');
          return;
        }

        voteBtn.disabled = true;
        voteBtn.textContent = 'Casting...';
        try {
          storeAnswer(cat, q, whereToBuy);
          await finalizeQuestion(q.question_id);
          notify('Vote submitted.', 'success', 2500);
          await loadBusinessQuestions();
        } catch (err) {
          console.error('Failed to cast vote:', err);
          notify('Failed to cast vote. Please try again.', 'danger');
          voteBtn.disabled = false;
          voteBtn.textContent = 'Vote';
        }
        });
      }

      if (isAnswered) {
        questionDiv.classList.add('qr-award-row--done');
      }

      questionDiv.appendChild(questionLabel);
      if (isMeryenda && !isAnswered) {
        const where = document.createElement('input');
        where.type = 'text';
        where.className = 'form-control form-control-sm mt-2 meryenda-where';
        where.placeholder = 'Vendor name / location';
        where.maxLength = 180;
        where.setAttribute('aria-label', 'Vendor name / location');
        questionDiv.appendChild(where);
      }
      questionDiv.appendChild(actionEl);
      section.appendChild(questionDiv);
    });

    summaryContainer.appendChild(section);
  });

  if (voteCountText) {
    voteCountText.textContent = `${answered} of ${total} awards voted`;
  }
  if (categoryCountText) {
    categoryCountText.textContent = String(categories.length);
  }
  const percent = total ? Math.round((answered / total) * 100) : 0;
  const progressBar = document.getElementById('progressBarText');
  const progressTrack = document.querySelector('.qr-progress-track');
  if (progressBar) {
    progressBar.style.width = `${percent}%`;
    progressBar.setAttribute('aria-label', `${percent}% complete`);
    progressBar.setAttribute('title', `${percent}% complete`);
  }
  if (progressTrack) {
    progressTrack.setAttribute('aria-valuenow', String(percent));
    progressTrack.setAttribute('aria-label', `Voting progress ${percent} percent`);
  }
  const allDone = total > 0 && answered === total;
  applyQrCompletionState(allDone, categories.length);

  if (voteAllBtn && !allDone) {
    voteAllBtn.disabled = isVoteAllInProgress;
    voteAllBtn.textContent = 'Vote all';
  }
}

function applyQrCompletionState(allDone, categoryCount = 0) {
  document.body.classList.toggle('qr-all-done', allDone);
  qrCompletePanel?.classList.toggle('d-none', !allDone);

  if (qrHeroText) {
    qrHeroText.textContent = allDone
      ? 'All awards for this business are submitted. Continue on the main voting site for other categories.'
      : 'Tap Vote on each award below. Use the main voting site for other businesses and categories.';
  }

  if (qrCategoryChip) {
    const hideCategories = categoryCount <= 1;
    qrCategoryChip.classList.toggle('d-none', hideCategories);
    if (hideCategories && document.querySelector('.qr-insights')) {
      document.querySelector('.qr-insights').classList.toggle('qr-insights--single', true);
    }
  }
}

function storeAnswer(cat, q, whereToBuy = '') {
  const choiceId = parseInt(localStorage.getItem('qr_choice_id') || '0');
  const business = localStorage.getItem('qr_business') || '';
  const isMeryenda = /meryenda/i.test(q.question_name || '');
  const allAnswers = JSON.parse(localStorage.getItem('allCategoryAnswers') || '{}');

  if (!allAnswers[cat.category_id]) {
    allAnswers[cat.category_id] = { category_name: cat.category_name, selections: [] };
  }

  const sel = allAnswers[cat.category_id].selections;
  const entry = {
    question_id: q.question_id,
    question_name: q.question_name,
    choice_id: choiceId,
    answer_fields: isMeryenda ? 'meryenda' : '',
    manual_input: isMeryenda ? whereToBuy : '',
    freetext: isMeryenda ? whereToBuy : '',
    choice_text: isMeryenda && whereToBuy ? `${business} — ${whereToBuy}` : business
  };
  const idx = sel.findIndex(s => s.question_id == q.question_id);
  if (idx !== -1) sel[idx] = { ...sel[idx], ...entry };
  else sel.push(entry);

  localStorage.setItem('allCategoryAnswers', JSON.stringify(allAnswers));
}

async function finalizeAllVotes() {
  if (!voteAllBtn || isVoteAllInProgress) return;
  if (!currentCategories.length) {
    notify('No award titles loaded yet.', 'warning');
    return;
  }
  if (total > 0 && answered >= total) {
    notify('All awards are already voted for this business.', 'info');
    return;
  }
  const remaining = Math.max(total - answered, 0);
  const voteAllMsg =
    remaining > 0
      ? `Cast ${remaining} vote${remaining === 1 ? '' : 's'} for "${name}" on all remaining awards?`
      : `Cast all votes for "${name}"?`;
  const confirmed = (typeof window.showVoterConfirm === 'function')
    ? await window.showVoterConfirm({
        title: 'Vote all',
        message: voteAllMsg,
        confirmText: 'Vote all',
        cancelText: 'Cancel',
        confirmClass: 'btn-success'
      })
    : window.confirm(voteAllMsg);
  if (!confirmed) return;

  isVoteAllInProgress = true;
  voteAllBtn.disabled = true;
  voteAllBtn.textContent = 'Casting...';

  for (const cat of currentCategories) {
    for (const q of cat.questions || []) {
      if (!/meryenda/i.test(q.question_name || '')) continue;
      if (finalizedFromDB[q.question_id]) continue;
      const whereInput = summaryContainer.querySelector(`.qr-award-row[data-question-id="${q.question_id}"] .meryenda-where`);
      if (!whereInput) continue;
      const where = String(whereInput.value || '').trim();
      if (!where) {
        notify('Enter the vendor name and location for Most Popular Local Meryenda before voting all.', 'warning');
        isVoteAllInProgress = false;
        voteAllBtn.disabled = false;
        voteAllBtn.textContent = 'Vote all';
        return;
      }
    }
  }

  currentCategories.forEach(cat => {
    (cat.questions || []).forEach(q => {
      const where = String(
        summaryContainer.querySelector(`.qr-award-row[data-question-id="${q.question_id}"] .meryenda-where`)?.value || ''
      ).trim();
      storeAnswer(cat, q, where);
    });
  });

  window.voteAllBtn = voteAllBtn;
  window.finishBtn = finishBtn;

  try {
    await finalizeAllCategories();
    notify('All votes for this business were submitted.', 'success', 4000);
    await loadBusinessQuestions();
  } catch (err) {
    console.error('Vote all failed:', err);
    notify(err?.message || 'Failed to cast all votes. Please try again.', 'danger');
  } finally {
    isVoteAllInProgress = false;
    if (voteAllBtn && !(total > 0 && answered >= total)) {
      voteAllBtn.disabled = false;
      voteAllBtn.textContent = 'Vote All';
    }
  }
}

window.addEventListener('DOMContentLoaded', async () => {
  if (!localStorage.getItem('voter_id')) {
    window.location.href = 'qr_vote.php';
    return;
  }
  await loadFinalizedAnswersFromDB();
  loadBusinessQuestions();
  voteAllBtn?.addEventListener('click', finalizeAllVotes);
  const goMainSite = () => {
    window.toccaVoterGo('summarypoll.php');
  };
  openLegacyBtn?.addEventListener('click', goMainSite);
});