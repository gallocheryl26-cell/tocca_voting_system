function escapeHtml(str = '') {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function pluralize(count, singular, plural = `${singular}s`) {
  return `${count} ${count === 1 ? singular : plural}`;
}
document.addEventListener('DOMContentLoaded', async () => {
  if (typeof window.fetchFinalizedAnswersFromDB === 'function') {
    await window.fetchFinalizedAnswersFromDB();
  }
  const signOutBtn = document.getElementById('signOutBtn');
  const confirmSignOutModalEl = document.getElementById('confirmSignOutModal');
  const confirmSignOutMessage = document.getElementById('confirmSignOutMessage');
  const confirmSignOutBtn = document.getElementById('confirmSignOutBtn');
  const confirmSignOutCancelBtn = confirmSignOutModalEl?.querySelector('[data-bs-dismiss="modal"]');
  const confirmSignOutModal = confirmSignOutModalEl
    ? new bootstrap.Modal(confirmSignOutModalEl)
    : null;
 signOutBtn?.addEventListener('click', async (e) => {
    e.preventDefault();
    const progress = await getVotingProgress();
    const drafted = progress.drafted ?? 0;
    const remaining = progress.unanswered ?? Math.max((progress.questionCount ?? 0) - progress.done - drafted, 0);
    const done = progress.done ?? 0;
    const total = progress.questionCount ?? 0;
    const hasPending = remaining > 0 || drafted > 0;
    const remainingText = pluralize(remaining, 'unanswered award');
    const draftText = pluralize(drafted, 'draft vote');
    const doneText = pluralize(done, 'cast vote');
    const totalText = pluralize(total, 'award title');
    const pendingSummary = hasPending
      ? `<div class="alert alert-warning small mb-3">
          <strong>You still have ${escapeHtml(remainingText)}</strong> and
          <strong>${escapeHtml(draftText)} waiting to be cast</strong>.
        </div>`
      : `<div class="alert alert-success small mb-3">
          <strong>All your answered awards have already been cast.</strong>
        </div>`;
    const isQrEstablishment =
      document.body?.classList?.contains('voter-page--qr-establishment') ||
      /qr_selected_category\.php/i.test(window.location.pathname || '');
    const qrMainSiteBlock = isQrEstablishment
      ? `<div class="alert alert-info small mb-3" role="note">
          <strong>You can keep voting after signing out.</strong>
          Use the main voting site to answer awards for <em>other</em> businesses and categories.
          Your mobile number and access code work there too.
          <div class="mt-2">
            <a href="${window.toccaVoterUrl ? window.toccaVoterUrl('summarypoll.php') : 'summarypoll.php'}" class="alert-link fw-semibold">Open main voting site</a>
          </div>
        </div>`
      : '';
    const message =
      `${qrMainSiteBlock}
       ${pendingSummary}
       <div class="small text-muted mb-2">Current event progress:</div>
       <ul class="small mb-3 ps-3">
         <li>${escapeHtml(doneText)} out of ${escapeHtml(totalText)}</li>
         <li>Unanswered: ${escapeHtml(String(remaining))}</li>
         <li>Draft (answered, not yet cast): ${escapeHtml(String(drafted))}</li>
       </ul>
       <p class="small mb-0 text-muted">
         These counts are from your <strong>current event session</strong>. You can continue voting now, or sign out and resume later using your mobile number and access code.
       </p>`;
    if (confirmSignOutMessage) confirmSignOutMessage.innerHTML = message;
    if (confirmSignOutBtn) {
      confirmSignOutBtn.textContent = hasPending ? 'Sign Out Anyway' : 'Sign Out';
    }
    if (confirmSignOutCancelBtn) {
      confirmSignOutCancelBtn.textContent = hasPending ? 'Continue Voting' : 'Cancel';
    }
    confirmSignOutModal?.show();
  });
  confirmSignOutBtn?.addEventListener('click', async () => {
    confirmSignOutModal?.hide();
    await handleSignOut();
  });
});
async function handleSignOut() {
  try {
    const progress = await getVotingProgress();
    sessionStorage.setItem('signoutProgress', JSON.stringify(progress));
    const voterId = localStorage.getItem('voter_id');
    const eventId = localStorage.getItem('current_event_id');
    if (voterId) sessionStorage.setItem('voter_id', voterId);
    if (eventId) sessionStorage.setItem('current_event_id', eventId);
    if (window.firebase?.auth) {
      await firebase.auth().signOut();
    }
    localStorage.clear();
    window.toccaVoterGo('thankyou.php');
  } catch (err) {
    console.error('Sign out failed', err);
    if (typeof showToast === 'function') {
      showToast('Failed to sign out. Please try again.', 'danger');
    }
  }
}
