import { allQuestions } from './summary_data.js';

function hasMeaningfulAnswer(sel) {
  if (!sel || typeof sel !== 'object') return false;
  return Boolean(
    sel.choice_id ||
    (sel.choice_text && String(sel.choice_text).trim() !== '') ||
    (sel.manual_input && String(sel.manual_input).trim() !== '')
  );
}

export function buildFinalizedAnswerArray() {
  const allCategoryAnswers = JSON.parse(localStorage.getItem("allCategoryAnswers") || "{}");
  const finalizedAnswers = JSON.parse(localStorage.getItem("finalizedAnswers") || "{}");
  const result = [];

  for (const catId in allCategoryAnswers) {
    const selections = allCategoryAnswers[catId]?.selections || [];
    selections.forEach(sel => {
      if (finalizedAnswers[sel.question_id]) {
        const ft = sel.manual_input && sel.manual_input.trim() !== ''
          ? sel.manual_input.trim()
          : "";
        result.push({
          question_id: sel.question_id,
          choice_id: sel.choice_id || null,
          freetext: ft
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
        const ft = sel.manual_input && sel.manual_input.trim() !== ''
          ? sel.manual_input.trim()
          : "";
        result[sel.question_id] = {
          choice_id: sel.choice_id || null,
          freetext: ft
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

  // Skip if this question is already finalized either locally or in the DB
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

  const voterId = localStorage.getItem("voter_id");

  if (voterId) {
    const payload = {
      voters_id: voterId,
      finalized_votes: {
        [qid]: {
          choice_id: answer.choice_id || null,
          freetext:
            answer.manual_input && answer.manual_input.trim() !== ''
              ? answer.manual_input.trim()
              : ''
        }
      },
      answers: [
        {
          question_id: qid,
          choice_id: answer.choice_id || null,
          freetext:
            answer.manual_input && answer.manual_input.trim() !== ''
              ? answer.manual_input.trim()
              : ''
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
    const hasAnswer = Boolean(
      sel.choice_id ||
      (sel.choice_text && sel.choice_text.trim() !== "") ||
      (sel.manual_input && sel.manual_input.trim() !== "")
    );
    if (hasAnswer && !finalized[sel.question_id] && !finalizedFromDB[sel.question_id]) {
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

  // Build payload from active event questions only, so stale local data
  // (other events/categories) cannot inflate the "Cast all" count.
  const activeQuestions = Array.isArray(allQuestions) ? allQuestions : [];
  for (const q of activeQuestions) {
    const selections = allAnswers[q.category_id]?.selections || [];
    const sel = selections.find((s) => Number(s.question_id) === Number(q.question_id));
    if (!sel) continue;

    // Use DB-finalized as source of truth. Local finalized state should not block retries.
    const isAlreadyFinal = Boolean(finalizedFromDB[q.question_id]);
    const hasAnswer = hasMeaningfulAnswer(sel);
    if (!hasAnswer || isAlreadyFinal) continue;

    const cleanText = (sel.manual_input && String(sel.manual_input).trim()) || "";
    toMarkFinal.push(Number(q.question_id));

    finalized_votes[q.question_id] = {
      choice_id: sel.choice_id || null,
      freetext: cleanText
    };

    answers.push({
      question_id: q.question_id,
      choice_id: sel.choice_id || null,
      freetext: cleanText
    });
  }

  if (answers.length === 0) {
    window.showToast?.(
      "No award titles are ready to cast. Answer at least one question, then try again.",
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

    if (result.status === "success") {
      // Only mark locally after server confirms.
      toMarkFinal.forEach((qid) => {
        if (Number.isFinite(qid)) finalized[qid] = true;
      });
      localStorage.setItem("finalizedAnswers", JSON.stringify(finalized));

      const questionRes = await fetch("load_all_questions.php");
      const questionData = await questionRes.json();
      const activeQ = questionData.questions || [];
      const activeQids = activeQ.map(q => parseInt(q.question_id));
      const finalizedSet = new Set([
        ...Object.keys(finalized).map(Number),
        ...Object.keys(finalizedFromDB).map(Number)
      ]);
      const allFinal = activeQids.every(qid => finalizedSet.has(qid));

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

      if (typeof window.showToast === "function") {
        window.showToast("Votes submitted successfully. You can continue with remaining award titles.", "success");
      } else {
        alert("Votes submitted successfully.");
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