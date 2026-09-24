function getCurrentEventId() {
  return localStorage.getItem('current_event_id') || '';
}

function parseSaveResponse(res, text) {
  let parsed = null;
  try {
    parsed = JSON.parse((text || '').trim());
  } catch (e) {
    const looksHtml = /<html|<body|<!doctype/i.test(text || '');
    if (!res.ok || looksHtml) {
      throw new Error('Saving took too long. Please tap Proceed again.');
    }
    throw new Error(res.ok ? 'Invalid response while saving.' : `Could not save (HTTP ${res.status}).`);
  }
  if (!res.ok && (!parsed || parsed.status !== 'success')) {
    throw new Error(parsed.message || `Could not save (HTTP ${res.status}).`);
  }
  if (parsed && parsed.status && parsed.status !== 'success') {
    throw new Error(parsed.message || 'Could not save your answers.');
  }
  return parsed;
}

async function saveDraftOnce(data, signal) {
  const res = await fetch('save_draft.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify(data),
    signal,
  });
  const text = await res.text();
  return parseSaveResponse(res, text);
}

export async function saveDraft(data) {
  const run = () => {
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), 45000);
    return saveDraftOnce(data, ctrl.signal).finally(() => clearTimeout(timer));
  };
  try {
    return await run();
  } catch (err) {
    const msg = String(err?.message || err?.name || '');
    const retryable = err?.name === 'AbortError'
      || /Failed to fetch|NetworkError|timeout|too long|Invalid response|HTTP 5/i.test(msg);
    if (!retryable) throw err;
    try {
      return await run();
    } catch (retryErr) {
      if (retryErr?.name === 'AbortError') {
        throw new Error('Saving took too long. Please tap Proceed again.');
      }
      throw retryErr;
    }
  }
}

export function loadAllDrafts(voterId, eventId = getCurrentEventId()) {
  return fetch('load_all_drafts.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ voter_id: voterId, event_id: eventId })
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