export let allCategories = [];
export let allQuestions = [];
export async function fetchAllCategoriesAndQuestions() {
  try {
    const [categoryRes, questionRes] = await Promise.all([
      fetch("get_all_categories.php"),
      fetch("load_all_questions.php"),
    ]);
    const [categoryText, questionText] = await Promise.all([
      categoryRes.text(),
      questionRes.text(),
    ]);
    if (!categoryRes.ok) {
      throw new Error(`HTTP error fetching categories! status: ${categoryRes.status} - ${categoryText}`);
    }
    if (!questionRes.ok) {
      throw new Error(`HTTP error fetching awards! status: ${questionRes.status} - ${questionText}`);
    }
    let categoryData;
    let questionData;
    try {
      categoryData = JSON.parse(categoryText);
    } catch (err) {
      throw new Error(`Invalid JSON response from get_all_categories.php: ${categoryText}`);
    }
    try {
      questionData = JSON.parse(questionText);
    } catch (err) {
      throw new Error(`Invalid JSON response from load_all_questions.php: ${questionText}`);
    }
    if (categoryData.status === "success") {
      allCategories = categoryData.categories;
      if (categoryData.event_id) {
        localStorage.setItem("current_event_id", categoryData.event_id.toString());
      }
    }
    if (questionData.status === "success") {
      const activeIds = new Set(
        (categoryData.status === "success" ? categoryData.categories : [])
          .map(c => parseInt(c.id))
      );
      allQuestions = questionData.questions.filter(q => activeIds.has(q.category_id));
    }
    localStorage.setItem("questionCount", allQuestions.length);
  } catch (err) {
    console.error("Failed to load categories or awards", err);
    localStorage.setItem("questionCount", allQuestions.length);
  }
}
function getCurrentEventId() {
  return localStorage.getItem("current_event_id") || "";
}
export async function fetchFinalizedAnswersFromDB() {
  const voterId = localStorage.getItem("voter_id");
  let eventId = getCurrentEventId();
  if (!eventId && typeof window.ensureCurrentEventId === "function") {
    eventId = await window.ensureCurrentEventId();
  }
  if (!voterId || !eventId) return;
  try {
    const res = await fetch("get_finalized_answers.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ voter_id: voterId, event_id: eventId }),
    });
    const text = await res.text();
    if (!res.ok) {
      throw new Error(
        `HTTP error fetching finalized answers! status: ${res.status} - ${text}`
      );
    }
    let data;
    try {
      data = JSON.parse(text);
    } catch (err) {
      throw new Error(
        `Invalid JSON response from get_finalized_answers.php: ${text}`
      );
    }
    if (data.status === "success") {
      const ids = data.finalized || data.answers || [];
      const fromDB = {};
      for (const item of ids) {
        const id = typeof item === "object" ? item.question_id : item;
        if (id != null) {
          fromDB[parseInt(id)] = true;
        }
      }
      localStorage.setItem("finalizedFromDB", JSON.stringify(fromDB));
    }
  } catch (err) {
    console.warn("Failed to fetch vote answers", err);
  }
}
export async function fetchExistingAnswersFromDB() {
  const voterId = localStorage.getItem("voter_id");
  let eventId = getCurrentEventId();
  if (!eventId && typeof window.ensureCurrentEventId === "function") {
    eventId = await window.ensureCurrentEventId();
  }
  if (!voterId || !eventId) return;
  try {
    const draftRes = await fetch('load_all_drafts.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ voter_id: voterId, event_id: eventId })
    });
        const drafts = await draftRes.json();
    if (Array.isArray(drafts)) {
      const existing = JSON.parse(localStorage.getItem('allCategoryAnswers') || '{}');
      drafts.forEach(category => {
        const catId = category.category_id;
        const prev = existing[catId] || { category_name: category.category_name, selections: [] };
        const prevSelections = Array.isArray(prev.selections) ? [...prev.selections] : [];

        category.questions.forEach(q => {
          const updated = {
            question_id: q.question_id,
            manual_input: q.manual_input || '',
            choice_text: q.selected_answer_text || q.choice_text || '',
            choice_id: q.choice_id || null,
            has_media: Boolean(q.has_media),
          };
          const idx = prevSelections.findIndex(sel => sel.question_id == q.question_id);
          const hasAnswer = q.choice_id !== null || (q.manual_input && q.manual_input.trim() !== '');
          if (idx !== -1) {
            if (hasAnswer) prevSelections[idx] = { ...prevSelections[idx], ...updated };
          } else if (hasAnswer) {
            prevSelections.push(updated);
          }
        });
        existing[catId] = {
          category_name: category.category_name,
          selections: prevSelections
        };
      });
      localStorage.setItem('allCategoryAnswers', JSON.stringify(existing));
    }
    const params = new URLSearchParams({ voter_id: voterId, event_id: eventId });
    const res = await fetch(`load_existing_votes.php?${params.toString()}`);
    const data = await res.json();
    if (data.status === 'success' && Array.isArray(data.answers)) {
      const existing = JSON.parse(localStorage.getItem('allCategoryAnswers') || '{}');
      data.answers.forEach(ans => {
        const q = allQuestions.find(q => q.question_id == ans.question_id);
        if (!q) return;
        const catId = q.category_id;
        if (!existing[catId]) {
          const cat = allCategories.find(c => c.id == catId) || {};
          existing[catId] = { category_name: cat.name || '', selections: [] };
        }
        const updated = {
          question_id: ans.question_id,
          manual_input: ans.manual_input || '',
          choice_text: ans.choice_text || '',
          choice_id: ans.choice_id || null,
          has_media: Boolean(ans.has_media),
        };
        const idx = existing[catId].selections.findIndex(s => s.question_id == ans.question_id);
        if (idx !== -1) {
          existing[catId].selections[idx] = { ...existing[catId].selections[idx], ...updated };
        } else {
          existing[catId].selections.push(updated);
        }
      });
      localStorage.setItem('allCategoryAnswers', JSON.stringify(existing));
    }
  } catch (err) {
    console.warn("Failed to load existing answers", err);
  }
}
window.fetchAllCategoriesAndQuestions = fetchAllCategoriesAndQuestions;
window.fetchFinalizedAnswersFromDB = fetchFinalizedAnswersFromDB;