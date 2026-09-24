window.ensureCurrentEventId = async function () {
  try {
    const res = await fetch('get_all_categories.php', { credentials: 'same-origin' });
    const data = await res.json();
    if (data && data.status === 'success' && data.event_id) {
      const id = String(data.event_id);
      localStorage.setItem('current_event_id', id);
      return id;
    }
  } catch (e) {
    console.warn('Could not resolve current event id', e);
  }
  return localStorage.getItem('current_event_id') || '';
};

window.getVotingProgress = async function () {
  const voterId = localStorage.getItem('voter_id');
  let eventId = localStorage.getItem('current_event_id');
  if (!eventId) {
    eventId = await window.ensureCurrentEventId();
  }
  if (!voterId || !eventId) {
    return {
      done: 0,
      drafted: 0,
      unanswered: 0,
      notVoted: 0,
      total: 0,
      questionCount: 0
    };
  }
  try {
    const [draftRes, finalizedRes] = await Promise.all([
      fetch('load_all_drafts.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ voter_id: voterId, event_id: eventId })
      }),
      fetch('get_finalized_answers.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ voter_id: voterId, event_id: eventId })
      })
    ]);

    const drafts = await draftRes.json();
    const finalized = await finalizedRes.json();

    const questionCount = Array.isArray(drafts)
      ? drafts.reduce((sum, cat) => sum + (cat.questions?.length || 0), 0)
      : 0;
    const drafted = Array.isArray(drafts)
      ? drafts.reduce(
          (sum, cat) =>
            sum +
            (cat.questions?.filter(q => q.is_answered && q.is_finalized == 0).length || 0),
          0
        )
      : 0;
    const rawFinalized = Array.isArray(finalized.answers)
      ? finalized.answers
      : (Array.isArray(finalized.finalized) ? finalized.finalized : []);
    const doneIds = new Set(
      rawFinalized
        .map((item) => (typeof item === 'object' ? item.question_id : item))
        .map((id) => parseInt(id, 10))
        .filter(Number.isFinite)
    );
    const done = doneIds.size;
    const unanswered = Math.max(questionCount - done - drafted, 0);
    const notVoted = drafted + unanswered;
    return {
      done,
      drafted,
      unanswered,
      notVoted,
      total: questionCount,
      questionCount
    };
  } catch (err) {
    console.error('Failed to fetch voting progress', err);
    return {
      done: 0,
      drafted: 0,
      unanswered: 0,
      notVoted: 0,
      total: 0,
      questionCount: 0
    };
  }
};
