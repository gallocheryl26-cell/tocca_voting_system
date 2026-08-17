import { allQuestions } from './summary_data.js';
import { isCompleteOpenTextAnswer, usesSingleOpenField } from './js/voting_field_labels.js';

function hasMeaningfulAnswer(sel, question = {}) {
  if (!sel || typeof sel !== 'object') return false;
  const fields = String(sel.answer_fields || question.answer_fields || '').toLowerCase();
  const typed = String(sel.freetext || sel.manual_input || '').trim();
  const singleField = usesSingleOpenField({
    ...sel,
    question_name: sel.question_name || question.question_name || '',
    field_labels: question.field_labels,
  });
  if (fields === 'product_business' || fields === 'song_singer') {
    return isCompleteOpenTextAnswer(typed, singleField);
  }
  if (sel.choice_id) return true;
  return isCompleteOpenTextAnswer(typed || String(sel.choice_text || '').trim(), singleField);
}

function answerFreetext(sel) {
  return String(sel?.freetext || sel?.manual_input || '').trim();
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

export async function finalizeQuestion(qid) {
  const allAnswers = JSON.parse(localStorage.getItem("allCategoryAnswers") || "{}");
  const finalized = JSON.parse(localStorage.getItem("finalizedAnswers") || "{}");
  const finalizedFromDB = JSON.parse(localStorage.getItem("finalizedFromDB") || "{}");

  if (finalized[qid] || finalizedFromDB[qid]) {
    console.log(`Question ${qid} already finalized, skipping`);
    return;
  }

  let answer = null;
  for (const catId in allAnswers) {
    const found = (allAnswers[catId].selections || []).find(sel => sel.question_id == qid);
    if (found) {
      answer = found;
      break;
    }
  }

  if (!answer) {
    console.warn(`No answer found for question ${qid}`);
    return;
  }

  const question = (Array.isArray(allQuestions) ? allQuestions : []).find((q) => q.question_id == qid) || {};
  if (!hasMeaningfulAnswer(answer, question)) {
    window.showToast?.('Please complete this award before casting.', 'warning');
    return;
  }

  const voterId = localStorage.getItem("voter_id");

  if (voterId) {
    const payload = {
      voters_id: voterId,
      finalized_votes: {
        [qid]: {
          choice_id: answer.choice_id || null,
          freetext: answerFreetext(answer)
        }
      },
      answers: [
        {
          question_id: qid,
          choice_id: answer.choice_id || null,
          freetext: answerFreetext(answer)
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
      if (data.status === "success") {
        finalized[qid] = true;
        localStorage.setItem("finalizedAnswers", JSON.stringify(finalized));
        window.showToast?.("Answer casted successfully.", "success");
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
  const originalText = voteAllBtn ? voteAllBtn.textContent : "";

  if (voteAllBtn) {
    voteAllBtn.disabled = true;
    voteAllBtn.textContent = "Voting...";
  }

  await window.fetchAllCategoriesAndQuestions?.();

  const allAnswers = JSON.parse(localStorage.getItem("allCategoryAnswers") || "{}");
  const finalized = JSON.parse(localStorage.getItem("finalizedAnswers") || "{}");
  const finalizedFromDB = JSON.parse(localStorage.getItem("finalizedFromDB") || "{}");

  const finalized_votes = {};
  const answers = [];
  const toMarkFinal = [];

  const activeQuestions = Array.isArray(allQuestions) ? allQuestions : [];
  for (const q of activeQuestions) {
    const selections = allAnswers[q.category_id]?.selections || [];
    const sel = selections.find((s) => Number(s.question_id) === Number(q.question_id));
    if (!sel) continue;

    const isAlreadyFinal = Boolean(finalizedFromDB[q.question_id]);
    const hasAnswer = hasMeaningfulAnswer(sel, q);
    if (!hasAnswer || isAlreadyFinal) continue;

    toMarkFinal.push(Number(q.question_id));

    finalized_votes[q.question_id] = {
      choice_id: sel.choice_id || null,
      freetext: answerFreetext(sel)
    };

    answers.push({
      question_id: q.question_id,
      choice_id: sel.choice_id || null,
      freetext: answerFreetext(sel)
    });
  }

  if (answers.length === 0) {
    window.showToast?.(
      "No award titles are ready to cast. Select a business or enter an answer for at least one award, then try again.",
      "warning",
      4500
    );
    if (voteAllBtn) {
      voteAllBtn.disabled = false;
      voteAllBtn.textContent = originalText;
    }
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
    if (voteAllBtn) {
      voteAllBtn.disabled = false;
      voteAllBtn.textContent = originalText;
    }
    return;
  }

  const voterId = localStorage.getItem("voter_id");

  if (!voterId) {
    window.showToast?.(
      "Your session has expired. Please sign in again from the home page.",
      "danger",
      5000
    );
    if (voteAllBtn) {
      voteAllBtn.disabled = false;
      voteAllBtn.textContent = originalText;
    }
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

    if (result.status === "success" || result.complete) {
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
          window.location.href = "thankyou.php";
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

  if (voteAllBtn) {
    voteAllBtn.disabled = false;
    voteAllBtn.textContent = originalText;
  }
}
