/**
 * Keeps client voter_id in sync with the PHP session on protected voting pages.
 */
if (typeof window.toccaVoterGo !== 'function') {
  window.toccaVoterGo = function (page) {
    window.location.href = (typeof window.toccaVoterUrl === 'function') ? window.toccaVoterUrl(page) : page;
  };
}

export async function bootstrapVoterSession() {
  try {
    const res = await fetch('check_voter_session.php', { credentials: 'same-origin' });
    const data = await res.json();
    if (!data.can_access_ballot || !data.voter_id) {
      window.toccaVoterGo('index.php');
      return null;
    }
    try {
      localStorage.setItem('voter_id', String(data.voter_id));
    } catch (storageErr) {}
    return data.voter_id;
  } catch (err) {
    console.error('Session bootstrap failed:', err);
    window.toccaVoterGo('index.php');
    return null;
  }
}
