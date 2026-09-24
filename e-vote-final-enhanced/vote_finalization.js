import { allQuestions } from './summary_data.js?v=cast4';
import {
  selectionIsReady,
  numericChoiceId,
  listedAnswerLabel,
  matchNamedChoice,
  isCompleteOpenTextAnswer,
  usesSingleOpenField,
  plainChoiceDisplayName,
  sanitizeStoredAnswerMap,
} from './js/voting_field_labels.js?v=cast4';

function hasMeaningfulAnswer(sel, question = {}) {
  return selectionIsReady(sel, question);
}

function answerFreetext(sel) {
  return String(sel?.freetext || sel?.manual_input || '').trim();
}

const categoryChoicesCache = new Map();

async function loadCategoryChoices(categoryId) {
  const key = String(categoryId || '');
  if (!key) return [];
  if (categoryChoicesCache.has(key)) return categoryChoicesCache.get(key);
  try {
    const res = await fetch(`load_questions_with_choices.php?category_id=${encodeURIComponent(key)}`);
    const data = await res.json();
    const questions = Array.isArray(data?.questions) ? data.questions : [];
    categoryChoicesCache.set(key, questions);
    return questions;
  } catch (e) {
    console.warn('Failed to load award choices for cast', e);
    return [];
  }
}

function persistRecoveredChoice(qid, choiceId, choiceText = '') {
  if (!choiceId) return;
  try {
    const allAnswers = JSON.parse(localStorage.getItem('allCategoryAnswers') || '{}');
    Object.keys(allAnswers).forEach((catId) => {
      const found = (allAnswers[catId]?.selections || []).find(
        (sel) => Number(sel.question_id) === Number(qid)
      );
      if (!found) return;
      found.choice_id = choiceId;
      if (choiceText) found.choice_text = plainChoiceDisplayName(choiceText);
    });
    localStorage.setItem('allCategoryAnswers', JSON.stringify(allAnswers));
  } catch (e) {}
}

async function ensureSubmittableAnswer(sel, question = {}) {
  const fields = String(sel.answer_fields || question.answer_fields || '').toLowerCase();
  const songLike =
    fields === 'song_singer' ||
    (!fields && /song|music|anthem|opm/i.test(String(sel.question_name || question.question_name || '')));
  if (songLike) {
    const text = answerFreetext(sel) || listedAnswerLabel(sel);
    const singleField = usesSingleOpenField({
      ...sel,
      question_name: sel.question_name || question.question_name || '',
      field_labels: question.field_labels,
    });
    if (!isCompleteOpenTextAnswer(text, singleField)) return null;
    return { choice_id: null, freetext: text };
  }

  let choiceId = numericChoiceId(sel);
  const label = listedAnswerLabel(sel) || answerFreetext(sel);
  let catId = question.category_id || sel.category_id;
  if (!catId && Array.isArray(allQuestions)) {
    const foundQ = allQuestions.find(
      (item) => Number(item.question_id) === Number(sel.question_id || question.question_id)
    );
    catId = foundQ?.category_id;
  }
  if (catId) {
    const loaded = await loadCategoryChoices(catId);
    const q = loaded.find((item) => Number(item.question_id) === Number(sel.question_id || question.question_id));
    const match = matchNamedChoice(q?.choices || [], choiceId || sel.choice_id, label);
    if (match?.choice_id) {
      choiceId = Number(match.choice_id);
      persistRecoveredChoice(sel.question_id || question.question_id, choiceId, match.choice_name || label);
    }
  }
  if (!(choiceId > 0)) return null;
  return { choice_id: choiceId, freetext: '' };
}

function submissionWroteVotes(data) {
  if (!data || data.status !== 'success') return false;
  if (data.writes == null) return true;
  return Number(data.writes) > 0;
}

export function buildFinalizedAnswerArray() {
  const allCategoryAnswers = JSON.parse(localStorage.getItem("allCategoryAnswers") || "{}");
  const finalizedAnswers = JSON.parse(localStorage.getItem("finalizedAnswers") || "{}");
  const result = [];

  for (const catId in allCategoryAnswers) {
    const selections = allCategoryAnswers[catId]?.selections || [];
    selections.forEach(sel => {
      if (finalizedAnswers[sel.question_id]) {
        result.push({
          question_id: sel.question_id,
          choice_id: sel.choice_id || null,
          freetext: answerFreetext(sel)
        });
      }
    });
  }
  return result;
}

export function buildFinalizedVotesObject() {
  const allCategoryAnswers = JSON.parse(localStorage.getItem("allCategoryAnswers") || "{}");
  const finalizedAnswers = JSON.parse(localStorage.getItem("finalizedAnswers") || "{}");
  const result = {};

  for (const catId in allCategoryAnswers) {
    const selections = allCategoryAnswers[catId]?.selections || [];
    selections.forEach(sel => {
      if (finalizedAnswers[sel.question_id]) {
        result[sel.question_id] = {
          choice_id: sel.choice_id || null,
          freetext: answerFreetext(sel)
        };
      }
    });
  }
  return result;
}

export function hasFinalizedVotes() {
  const votes = buildFinalizedVotesObject();
  return Object.keys(votes).length > 0;
}

function readAnswerMap() {
  let allAnswers = {};
  try {
    allAnswers = JSON.parse(localStorage.getItem('allCategoryAnswers') || '{}');
  } catch (e) {
    allAnswers = {};
  }
  sanitizeStoredAnswerMap(allAnswers);
  return allAnswers;
}

function findBestStoredAnswer(qid, question = {}) {
  const allAnswers = readAnswerMap();
  const qidNum = Number(qid);
  const preferred = question.category_id;
  const candidates = [];
  Object.keys(allAnswers).forEach((catId) => {
    const found = (allAnswers[catId]?.selections || []).find(
      (sel) => Number(sel.question_id) === qidNum
    );
    if (found) candidates.push({ catId, sel: found });
  });
  if (!candidates.length) return null;
  candidates.sort((a, b) => {
    const aReady = hasMeaningfulAnswer(a.sel, question) ? 1 : 0;
    const bReady = hasMeaningfulAnswer(b.sel, question) ? 1 : 0;
    if (aReady !== bReady) return bReady - aReady;
    const aPref = String(a.catId) === String(preferred) ? 1 : 0;
    const bPref = String(b.catId) === String(preferred) ? 1 : 0;
    return bPref - aPref;
  });
  return candidates[0].sel;
}

export async function finalizeQuestion(qid, savedAnswer = null) {
  const finalized = JSON.parse(localStorage.getItem("finalizedAnswers") || "{}");
  const finalizedFromDB = JSON.parse(localStorage.getItem("finalizedFromDB") || "{}");

  if (finalized[qid] || finalized[String(qid)] || finalizedFromDB[qid] || finalizedFromDB[String(qid)]) {
    console.log(`Question ${qid} already finalized, skipping`);
    return;
  }

  const question = (Array.isArray(allQuestions) ? allQuestions : []).find((q) => q.question_id == qid) || {};
  const answer = savedAnswer || findBestStoredAnswer(qid, question);
  const resolved = await ensureSubmittableAnswer(answer || {}, question);
  if (!resolved) {
    window.showToast?.(
      answer && (listedAnswerLabel(answer) || numericChoiceId(answer) > 0)
        ? 'This pick could not be submitted. Tap Edit, select it again, then Cast.'
        : 'Please complete this award before casting.',
      'warning',
      5000
    );
    return;
  }

  const voterId = localStorage.getItem("voter_id");

  if (voterId) {
    const payload = {
      voters_id: voterId,
      finalized_votes: {
        [qid]: {
          choice_id: resolved.choice_id,
          freetext: resolved.freetext
        }
      },
      answers: [
        {
          question_id: qid,
          choice_id: resolved.choice_id,
          freetext: resolved.freetext
        }
      ]
    };
    try {
      const res = await fetch("submit_vote.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
      });
      const data = await res.json();
      console.log("Single vote submitted:", data);
      if (submissionWroteVotes(data)) {
        finalized[qid] = true;
        localStorage.setItem("finalizedAnswers", JSON.stringify(finalized));
        window.showToast?.("Answer casted successfully.", "success");
      } else if (data.status === "success") {
        window.showToast?.(
          data.message || "This vote could not be recorded. Tap Edit, select it again, then Cast.",
          "warning",
          5000
        );
      } else {
        window.showToast?.(data.message || "Failed to submit vote", "danger");
      }
    } catch (err) {
      console.error("Failed to submit vote", err);
      window.showToast?.("Failed to submit vote", "danger");
    }
  }

  window.renderSummary?.();
}

export function finalizeAllInCategory(categoryId) {
  const allAnswers = JSON.parse(localStorage.getItem("allCategoryAnswers") || "{}");
  const finalized = JSON.parse(localStorage.getItem("finalizedAnswers") || "{}");
  const finalizedFromDB = JSON.parse(localStorage.getItem("finalizedFromDB") || "{}");
  const selections = allAnswers[categoryId]?.selections || [];

  let updated = false;
  selections.forEach(sel => {
    const question = (Array.isArray(allQuestions) ? allQuestions : []).find((q) => q.question_id == sel.question_id) || {};
    if (hasMeaningfulAnswer(sel, question) && !finalized[sel.question_id] && !finalizedFromDB[sel.question_id]) {
      finalized[sel.question_id] = true;
      updated = true;
    }
  });
  if (updated) {
    localStorage.setItem("finalizedAnswers", JSON.stringify(finalized));
    window.renderSummary?.();
  }
}

export async function finalizeAllCategories() {
  const voteAllBtn = window.voteAllBtn;
  const finishBtn = window.finishBtn;
  const originalHtml = voteAllBtn ? voteAllBtn.innerHTML : "";

  const restoreVoteAll = () => {
    if (!voteAllBtn) return;
    voteAllBtn.disabled = false;
    voteAllBtn.innerHTML = originalHtml;
    voteAllBtn.removeAttribute('aria-busy');
  };

  await window.fetchAllCategoriesAndQuestions?.();

  let allAnswers = {};
  try {
    allAnswers = JSON.parse(localStorage.getItem("allCategoryAnswers") || "{}");
  } catch (e) {
    allAnswers = {};
  }
  sanitizeStoredAnswerMap(allAnswers);
  const finalized = JSON.parse(localStorage.getItem("finalizedAnswers") || "{}");
  const finalizedFromDB = JSON.parse(localStorage.getItem("finalizedFromDB") || "{}");

  const finalized_votes = {};
  const answers = [];
  const toMarkFinal = [];

  const isAlreadyFinal = (qid) =>
    Boolean(finalized[qid] || finalized[String(qid)] || finalizedFromDB[qid] || finalizedFromDB[String(qid)]);

  const activeQuestions = Array.isArray(allQuestions) ? allQuestions : [];
  for (const q of activeQuestions) {
    if (isAlreadyFinal(q.question_id)) continue;
    const sel = findBestStoredAnswer(q.question_id, q);
    if (!sel || !hasMeaningfulAnswer(sel, q)) continue;

    const resolved = await ensureSubmittableAnswer(sel, q);
    if (!resolved) continue;

    toMarkFinal.push(Number(q.question_id));

    finalized_votes[q.question_id] = {
      choice_id: resolved.choice_id,
      freetext: resolved.freetext
    };

    answers.push({
      question_id: q.question_id,
      choice_id: resolved.choice_id,
      freetext: resolved.freetext
    });
  }

  if (answers.length === 0) {
    window.showToast?.(
      "No award titles are ready to cast. Select a business or enter an answer for at least one award, then try again.",
      "warning",
      4500
    );
    restoreVoteAll();
    window.renderSummary?.();
    return;
  }

  const countLabel =
    answers.length === 1
      ? "1 answered award title"
      : `${answers.length} answered award titles`;
  const confirmed = await window.showVoterConfirm?.({
    title: "Cast all ready votes?",
    message: `You are about to cast ${countLabel}. This cannot be undone.`,
    confirmText: "Cast all",
    cancelText: "Not yet",
    confirmClass: "btn-success",
  });
  if (!confirmed) {
    restoreVoteAll();
    return;
  }

  if (voteAllBtn) {
    voteAllBtn.disabled = true;
    voteAllBtn.textContent = "Voting...";
    voteAllBtn.setAttribute('aria-busy', 'true');
  }

  const voterId = localStorage.getItem("voter_id");

  if (!voterId) {
    window.showToast?.(
      "Your session has expired. Please sign in again from the home page.",
      "danger",
      5000
    );
    restoreVoteAll();
    return;
  }

  try {
    const res = await fetch("submit_vote.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        voters_id: voterId,
        finalized_votes,
        answers
      })
    });

    const result = await res.json();
    console.log("VoteAll Submit Result:", result);

    if (submissionWroteVotes(result) || result.complete) {
      toMarkFinal.forEach((qid) => {
        if (Number.isFinite(qid)) finalized[qid] = true;
      });
      localStorage.setItem("finalizedAnswers", JSON.stringify(finalized));

      let allFinal = Boolean(result.complete);
      if (!allFinal) {
        const eventId = localStorage.getItem("current_event_id") || "";
        const questionUrl = eventId
          ? `load_all_questions.php?event_id=${encodeURIComponent(eventId)}`
          : "load_all_questions.php";
        const questionRes = await fetch(questionUrl);
        const questionData = await questionRes.json();
        const activeQ = questionData.questions || [];
        const activeQids = activeQ.map(q => parseInt(q.question_id, 10)).filter(Number.isFinite);
        const finalizedSet = new Set([
          ...Object.keys(finalized).map(Number),
          ...Object.keys(finalizedFromDB).map(Number)
        ]);
        allFinal = activeQids.length > 0 && activeQids.every(qid => finalizedSet.has(qid));
      }

      if (allFinal) {
        localStorage.setItem("vote_finalized", "true");
        window.isFinalized = true;
        if (finishBtn) finishBtn.style.display = "none";
      }

      window.renderSummary?.();

      if (allFinal) {
        const doneMsg =
          "All votes have been cast. Thank you for participating in the Tatak Ormoc Consumers’ Choice Awards.";
        if (typeof window.showToast === "function") {
          window.showToast(doneMsg, "success");
        } else {
          alert(doneMsg);
        }
        setTimeout(() => {
          window.toccaVoterGo("thankyou.php");
        }, 2000);
        return;
      }

      const okMsg = result.message || "Votes submitted successfully. You can continue with remaining award titles.";
      if (typeof window.showToast === "function") {
        window.showToast(okMsg, "success");
      } else {
        alert(okMsg);
      }

    } else {
      window.showToast?.(result.message || "Failed to submit votes.", "danger", 5000);
    }

  } catch (error) {
    console.error("VoteAll error:", error);
    window.showToast?.("An error occurred while submitting your votes.", "danger", 5000);
  }

  restoreVoteAll();
  window.renderSummary?.();
}
