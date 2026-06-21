/**
 * Keeps client voter_id in sync with the PHP session on protected voting pages.
 */
export async function bootstrapVoterSession() {
  try {
    const res = await fetch('check_voter_session.php', { credentials: 'same-origin' });
    const data = await res.json();
    if (!data.can_access_ballot || !data.voter_id) {
      window.location.href = 'index.php';
      return null;
    }
    localStorage.setItem('voter_id', String(data.voter_id));
    return data.voter_id;
  } catch (err) {
    console.error('Session bootstrap failed:', err);
    window.location.href = 'index.php';
    return null;
  }
}
