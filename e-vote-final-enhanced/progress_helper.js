window.getVotingProgress = async function () {
  const voterId = localStorage.getItem('voter_id');
  let eventId = localStorage.getItem('current_event_id');
  if (!eventId) {
    eventId = '1';
    localStorage.setItem('current_event_id', eventId);
  }
  if (!voterId) {
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
    const done = Array.isArray(finalized.answers)
      ? finalized.answers.length
      : 0;
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