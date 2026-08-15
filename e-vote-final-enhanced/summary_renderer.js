import { allCategories, allQuestions } from './summary_data.js';
import { finalizeQuestion } from './vote_finalization.js';

const summaryContainer = document.getElementById('summaryContainer');
const finishBtn = document.getElementById('finishBtn');

export function updateCategoryProgress(barEl, voted, notVoted, total, textEl) {
  if (!barEl) return;
  const percent = total > 0 ? Math.round((voted / total) * 100) : 0;
  barEl.style.width = `${percent}%`;
  barEl.setAttribute('aria-valuenow', String(percent));
  barEl.setAttribute('aria-valuemin', '0');
  barEl.setAttribute('aria-valuemax', '100');
  const text = `${voted} voted · ${notVoted} pending · ${total} total`;
  if (textEl) textEl.textContent = text;
}

function selectionIsReady(saved = {}) {
  const hasChoice = Boolean(saved.choice_id || saved.choice_text);
  const proofCount = Array.isArray(saved.proof_images) ? saved.proof_images.length : 0;
  return hasChoice && proofCount >= 1;
}

function renderSummaryOverview(allCategories, allQuestions) {
  const overviewEl = document.getElementById('summaryOverview');
  if (!overviewEl) return;

  const finalized = JSON.parse(localStorage.getItem('finalizedAnswers') || '{}');
  const finalizedFromDB = JSON.parse(localStorage.getItem('finalizedFromDB') || '{}');
  const allAnswers = JSON.parse(localStorage.getItem('allCategoryAnswers') || '{}');

  let voted = 0;
  let drafted = 0;
  let unanswered = 0;

  allQuestions.forEach(q => {
    const isFinal = finalized[q.question_id] || finalizedFromDB[q.question_id];
    if (isFinal) {
      voted++;
      return;
    }
    const catAnswers = allAnswers[q.category_id]?.selections || [];
    const saved = catAnswers.find(s => s.question_id == q.question_id) || {};
    const hasAnswer = selectionIsReady(saved);
    if (hasAnswer) drafted++;
    else unanswered++;
  });

  const total = allQuestions.length;

  overviewEl.innerHTML = `
    <div class="summary-stat stat-voted">
      <span class="summary-stat-value">${voted}</span>
      <span class="summary-stat-label">Voted</span>
    </div>
    <div class="summary-stat stat-pending">
      <span class="summary-stat-value">${drafted}</span>
      <span class="summary-stat-label">Ready to cast</span>
    </div>
    <div class="summary-stat">
      <span class="summary-stat-value">${unanswered}</span>
      <span class="summary-stat-label">Unanswered</span>
    </div>
    <div class="summary-stat">
      <span class="summary-stat-value">${total}</span>
      <span class="summary-stat-label">Award titles</span>
    </div>`;
}

export function renderSummary() {
  const allAnswers = JSON.parse(localStorage.getItem('allCategoryAnswers') || '{}');
  const finalized = JSON.parse(localStorage.getItem('finalizedAnswers') || '{}');
  const finalizedFromDB = JSON.parse(localStorage.getItem('finalizedFromDB') || '{}');
  const voterType = localStorage.getItem('voter_type') || 'new';
  const allUnanswered = JSON.parse(localStorage.getItem('unanswered') || '[]');

  summaryContainer.innerHTML = '';
  renderSummaryOverview(allCategories, allQuestions);

  for (const cat of allCategories) {
    const questionsInCat = allQuestions.filter(q => q.category_id == cat.id);

    let questionsToRender = questionsInCat;
    if (voterType === 'existing') {
      const savedSelections = allAnswers[cat.id]?.selections || [];
      const hasContent =
        savedSelections.length > 0 ||
        questionsInCat.some(q => finalized[q.question_id] || finalizedFromDB[q.question_id]);

      const unansweredIds = new Set(allUnanswered.map(uq => uq.question_id));
      const hasUnanswered = questionsInCat.some(q => unansweredIds.has(q.question_id));

      if (!hasContent && !hasUnanswered) {
        continue;
      }
    }

    const section = document.createElement('div');
    section.className = 'accordion-item';

    const votedInCat = questionsInCat.filter(q =>
      finalized[q.question_id] === true || finalizedFromDB[q.question_id] === true
    ).length;
    const selections = allAnswers[cat.id]?.selections || [];

    let answeredNotFinal = 0;
    let unansweredCount = 0;

    questionsInCat.forEach(q => {
      const isFinal =
        finalized[q.question_id] === true ||
        finalizedFromDB[q.question_id] === true;
      if (isFinal) return;

      const saved = selections.find(sel => sel.question_id == q.question_id) || {};
      const hasAnswer = selectionIsReady(saved);

      if (hasAnswer) answeredNotFinal++;
      else unansweredCount++;
    });

    const totalInCat = questionsInCat.length;
    const notVotedInCat = answeredNotFinal + unansweredCount;
    const headingId = `heading-${cat.id}`;
    const collapseId = `collapse-${cat.id}`;

    section.innerHTML = `
    <h2 class="accordion-header" id="${headingId}">
      <button class="accordion-button collapsed" type="button"
        data-bs-toggle="collapse" data-bs-target="#${collapseId}"
        aria-expanded="false" aria-controls="${collapseId}">

        <div class="summary-button-content d-flex flex-column flex-md-row justify-content-md-between align-items-md-center gap-2 w-100">
          <div class="category-title text-center text-md-start mb-2 mb-md-0">${cat.name}</div>

          <div class="summary-progress-wrap">
            <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="progressWrap-${cat.id}">
              <div class="progress-bar bg-success" id="progressBar-${cat.id}" style="width: 0%;"></div>
            </div>
            <p class="summary-progress-label" id="progressText-${cat.id}">0 voted · 0 pending · 0 total</p>
          </div>
        </div>

      </button>
    </h2>

      <div id="${collapseId}" class="accordion-collapse collapse" aria-labelledby="${headingId}">
        <div class="accordion-body p-0">
          <ul class="list-group list-group-flush" id="questionList-${cat.id}"></ul>
        </div>
      </div>
    `;

    const ul = section.querySelector(`#questionList-${cat.id}`);

    const sortedQuestions = [...questionsToRender].sort((a, b) => {
      const status = q => {
        const isFinal =
          finalized[q.question_id] === true ||
          finalizedFromDB[q.question_id] === true;
        const saved =
          allAnswers[cat.id]?.selections?.find(
            sel => sel.question_id == q.question_id
          ) || {};
        const hasAnswer = selectionIsReady(saved);
        if (!isFinal && hasAnswer) return 0;
        if (!isFinal && !hasAnswer) return 1;
        return 2;
      };
      return status(a) - status(b);
    });

    sortedQuestions.forEach(q => {
      const isFinalFromDB = finalizedFromDB[q.question_id] === true;
      const isFinalNow = finalized[q.question_id] === true;
      const isFinal = isFinalNow || isFinalFromDB;

      const saved = allAnswers[cat.id]?.selections?.find(sel => sel.question_id == q.question_id) || {};
      const hasAnswer = saved && selectionIsReady(saved);

      const li = document.createElement('li');
      li.className = 'summary-row list-group-item d-flex flex-column flex-sm-row align-items-stretch text-start';
      if (isFinal) {
        li.classList.add('finalized-item');
      }

      const text = document.createElement('div');
      text.className = 'flex-grow-1 min-w-0';

      const questionRow = document.createElement('div');
      questionRow.className = 'd-flex flex-wrap justify-content-between align-items-start gap-2';

      const questionName = document.createElement('span');
      questionName.className = 'question-title';
      questionName.textContent = q.question_name;
      questionRow.appendChild(questionName);

      if (isFinal) {
        const finalizedBadge = document.createElement('span');
        finalizedBadge.className = 'voter-badge voter-badge--voted';
        finalizedBadge.innerHTML = '<i class="fa-solid fa-check" aria-hidden="true"></i> Voted';
        questionRow.appendChild(finalizedBadge);
      } else if (hasAnswer) {
        const pendingBadge = document.createElement('span');
        pendingBadge.className = 'voter-badge voter-badge--pending';
        pendingBadge.textContent = 'Ready to cast';
        questionRow.appendChild(pendingBadge);
      }

      const answerText = document.createElement('div');
      answerText.className = 'answer-preview';

      if (!hasAnswer) {
        answerText.classList.add('is-empty');
        answerText.textContent = 'No response yet';
      } else {
        answerText.textContent =
          saved.choice_text || `Choice #${saved.choice_id}`;
      }

      text.appendChild(questionRow);
      text.appendChild(answerText);
      const proofCount = Array.isArray(saved.proof_images) ? saved.proof_images.length : 0;
      if (hasAnswer && proofCount > 0) {
        const proofNote = document.createElement('div');
        proofNote.className = 'text-muted small mt-1';
        proofNote.textContent = `${proofCount} proof photo${proofCount === 1 ? '' : 's'} uploaded`;
        text.appendChild(proofNote);
      }

      if (saved.choice_id && saved.has_media) {
        const previewBtn = document.createElement('button');
        previewBtn.type = 'button';
        previewBtn.className = 'btn btn-sm btn-outline-primary choice-preview-btn mt-2';
        previewBtn.innerHTML = '<i class="fa-solid fa-images me-1" aria-hidden="true"></i> Preview business';
        previewBtn.addEventListener('click', (e) => {
          e.preventDefault();
          e.stopPropagation();
          const name = saved.choice_text || '';
          if (window.ChoiceMediaViewer && typeof window.ChoiceMediaViewer.open === 'function') {
            window.ChoiceMediaViewer.open(parseInt(saved.choice_id, 10), name);
          }
        });
        text.appendChild(previewBtn);
      }

      li.appendChild(text);

      if (!isFinal && !window.isFinalized) {
        const buttonGroup = document.createElement('div');
        buttonGroup.className = 'summary-row-actions';

        const editBtn = document.createElement('button');
        editBtn.className = 'btn btn-sm btn-outline-primary btn-voter-edit';
        editBtn.innerHTML = '<i class="fa-solid fa-pen me-1" aria-hidden="true"></i> Edit';
        editBtn.onclick = () => {
          localStorage.setItem('edit_return', 'summarypoll.php');
          window.location.href = `selected-category.php?category_id=${cat.id}&edit_question=${q.question_id}`;
        };

        const voteBtn = document.createElement('button');
        voteBtn.className = 'btn btn-sm btn-voter-cast';
        voteBtn.innerHTML = '<i class="fa-solid fa-check me-1" aria-hidden="true"></i> Cast vote';
        voteBtn.onclick = async () => {
          if (!hasAnswer) {
            window.focusSummaryAttention?.(li, collapseId);
            window.showToast?.(
              'Choose an answer first. Tap Edit to answer this award title.',
              'warning',
              4500
            );
            return;
          }
          const confirmed = await window.showVoterConfirm?.({
            title: 'Cast this vote?',
            message: 'This cannot be undone. Your selection will be recorded.',
            confirmText: 'Cast vote',
            cancelText: 'Not yet',
            confirmClass: 'btn-success',
          });
          if (!confirmed) return;
          await finalizeQuestion(q.question_id);
          const collapseEl = document.getElementById(collapseId);
          if (collapseEl) {
            const bsCollapse = bootstrap.Collapse.getOrCreateInstance(collapseEl);
            bsCollapse.hide();
          }
        };
        buttonGroup.appendChild(editBtn);
        buttonGroup.appendChild(voteBtn);
        li.appendChild(buttonGroup);
      }
      ul.appendChild(li);
    });

    summaryContainer.appendChild(section);
    const bar = section.querySelector(`#progressBar-${cat.id}`);
    const textEl = section.querySelector(`#progressText-${cat.id}`);
    updateCategoryProgress(bar, votedInCat, notVotedInCat, totalInCat, textEl);
  }
  if (window.isFinalized && finishBtn) finishBtn.style.display = 'none';
}

window.renderSummary = renderSummary;