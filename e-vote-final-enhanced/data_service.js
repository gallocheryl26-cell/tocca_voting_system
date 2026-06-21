function getCurrentEventId() {
  let eventId = localStorage.getItem('current_event_id');
  if (!eventId) {
    eventId = '1';
    localStorage.setItem('current_event_id', eventId);
  }
  return eventId;
}

export function loadAllDrafts(voterId, eventId = getCurrentEventId()) {
  return fetch('load_all_drafts.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ voter_id: voterId, event_id: eventId })
  }).then(res => res.json());
}

export function saveDraft(data) {
  return fetch('save_draft.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data)
  }).then(res => res.json());
}

export function loadQuestions(categoryId) {
  const url = `load_questions.php?category_id=${encodeURIComponent(categoryId)}`;
  return fetch(url, { method: 'GET', credentials: 'same-origin' })
    .then(async res => {
      const text = await res.text();
      if (!res.ok) {
        throw new Error(`HTTP error fetching questions! status: ${res.status} - ${text}`);
      }
      try {
        return JSON.parse(text);
      } catch (err) {
        throw new Error(`Invalid JSON response from load_questions.php: ${text}`);
      }
    });
}

export function loadQuestionsWithChoices(categoryId) {
  const url = `load_questions_with_choices.php?category_id=${encodeURIComponent(categoryId)}`;
  return fetch(url, { method: 'GET', credentials: 'same-origin' })
    .then(async res => {
      const text = await res.text();
      if (!res.ok) {
        throw new Error(`HTTP error fetching questions with choices! status: ${res.status} - ${text}`);
      }
      try {
        return JSON.parse(text);
      } catch (err) {
        throw new Error(`Invalid JSON response from load_questions_with_choices.php: ${text}`);
      }
    });
}

export function loadChoices(questionId) {
  return fetch(`load_choices.php?question_id=${questionId}`)
    .then(res => res.json());
}

export async function loadUserSelectionsFromDB(categoryId, voterId, eventId = getCurrentEventId()) {
  const url = `load_category_draft.php?category_id=${categoryId}&voter_id=${voterId}&event_id=${eventId}`;
  const response = await fetch(url);
  return response.json();
}