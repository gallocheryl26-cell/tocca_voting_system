/** Google-first voter authentication with legacy mobile/access-code fallback. */
(function (global) {
  'use strict';

  const config = global.TOCCA_GOOGLE_AUTH || {};
  let pendingLinkContinue = null;

  function element(id) {
    return document.getElementById(id);
  }

  function isEmbeddedBrowser() {
    const ua = navigator.userAgent || '';
    return /(FBAN|FBAV|Instagram|Messenger|Line\/|Twitter)/i.test(ua);
  }

  function setMessage(message, tone) {
    const target = element('googleAuthMessage');
    if (!target) return;
    target.textContent = message || '';
    target.className = 'small mt-3' + (message ? ' text-' + (tone || 'muted') : '');
  }

  function setBusy(busy) {
    const button = element('googleSignInBtn');
    const spinner = element('googleSignInSpinner');
    const label = element('googleSignInLabel');
    if (button) button.disabled = !!busy;
    spinner?.classList.toggle('d-none', !busy);
    if (label) label.textContent = busy ? 'Signing in…' : 'Continue with Google';
  }

  function modal() {
    const modalElement = element('googleAuthModal');
    return modalElement && global.bootstrap
      ? global.bootstrap.Modal.getOrCreateInstance(modalElement)
      : null;
  }

  function open() {
    if (!config.enabled) {
      global.showVoterVerificationModal?.();
      return;
    }
    setMessage('');
    element('googleEmbeddedBrowserWarning')?.classList.toggle('d-none', !isEmbeddedBrowser());
    modal()?.show();
    if (!global.firebase?.auth && typeof global.TOCCA_ensureFirebase === 'function') {
      setBusy(true);
      setMessage('Loading secure Google Sign-In…', 'muted');
      global.TOCCA_ensureFirebase()
        .then(() => setMessage(''))
        .catch((error) => setMessage(friendlyError(error), 'danger'))
        .finally(() => setBusy(false));
    }
  }

  function openLegacy() {
    modal()?.hide();
    global.VoterExistingLogin?.unlockForManualEntry?.();
    const legacyElement = element('existingVoterModal');
    setTimeout(() => {
      if (legacyElement && global.bootstrap) {
        global.bootstrap.Modal.getOrCreateInstance(legacyElement).show();
      }
    }, 180);
  }

  function cleanLegacyClientState() {
    [
      'otp_mobile', 'otpStep', 'draft_code', 'verified_mobile',
      'forgot_mobile', 'firebase_id_token'
    ].forEach((key) => localStorage.removeItem(key));
  }

  function redirectAfterLogin(data) {
    cleanLegacyClientState();
    localStorage.setItem('voter_id', String(data.voter_id));
    localStorage.setItem('voter_type', data.is_new ? 'new_google' : 'returning_google');
    localStorage.setItem('voter_auth_provider', 'google');
    if (data.email) localStorage.setItem('voter_google_email', data.email);
    localStorage.removeItem('currentIndexModal');

    let destination = 'category.php';
    if (data.completion_status === 'completed') {
      destination = 'thankyou.php';
    } else if (Number(config.qrChoiceId) > 0) {
      destination = 'qr_selected_category.php?choice_id=' + encodeURIComponent(String(config.qrChoiceId));
    }
    if (typeof global.toccaVoterGo === 'function') {
      global.toccaVoterGo(destination);
    } else {
      global.location.href = destination;
    }
  }

  function friendlyError(error) {
    const code = String(error?.code || '');
    if (code.includes('popup-closed-by-user') || code.includes('cancelled-popup-request')) {
      return 'Google Sign-In was cancelled. You can try again.';
    }
    if (code.includes('popup-blocked')) {
      return 'Your browser blocked the Google window. Allow pop-ups for this site and try again.';
    }
    if (code.includes('unauthorized-domain')) {
      return 'This website domain is not yet authorized in Firebase Authentication.';
    }
    if (code.includes('operation-not-supported-in-this-environment') || code.includes('web-storage-unsupported')) {
      return 'Google Sign-In is not supported inside this browser. Open the page in Chrome or Safari.';
    }
    return error?.message || 'Google Sign-In failed. Please try again.';
  }

  async function firebaseAuth() {
    if (typeof global.TOCCA_ensureFirebase === 'function') {
      await global.TOCCA_ensureFirebase();
    }
    if (!global.firebase?.auth) {
      throw new Error('Google authentication could not be loaded. Check your connection and try again.');
    }
    return global.firebase.auth();
  }

  async function signIn() {
    if (isEmbeddedBrowser()) {
      setMessage('Open this page in Chrome or Safari to use Google Sign-In.', 'danger');
      return;
    }

    setBusy(true);
    setMessage('Opening Google Sign-In…', 'muted');
    try {
      const auth = await firebaseAuth();
      await auth.setPersistence(global.firebase.auth.Auth.Persistence.SESSION);
      const provider = new global.firebase.auth.GoogleAuthProvider();
      provider.setCustomParameters({ prompt: 'select_account' });
      const result = await auth.signInWithPopup(provider);
      const idToken = await result.user.getIdToken(true);
      const response = await fetch('google_voter_login.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ id_token: idToken }),
      });
      const data = await response.json();
      if (!response.ok || data.status !== 'success') {
        throw new Error(data.message || 'Unable to start your voting session.');
      }
      // The application uses its own secure PHP session after token exchange.
      // Clear Firebase browser state so a shared device does not retain an account.
      await auth.signOut().catch((error) => console.warn('Firebase browser sign-out failed:', error));
      setMessage('Google account verified. Opening your ballot…', 'success');
      redirectAfterLogin(data);
    } catch (error) {
      console.error('Google voter sign-in failed:', error);
      setMessage(friendlyError(error), 'danger');
    } finally {
      setBusy(false);
    }
  }

  async function linkCurrentVoter() {
    if (isEmbeddedBrowser()) {
      throw new Error('Open this page in Chrome or Safari before linking Google.');
    }
    const auth = await firebaseAuth();
    const provider = new global.firebase.auth.GoogleAuthProvider();
    provider.setCustomParameters({ prompt: 'select_account' });
    const result = await auth.signInWithPopup(provider);
    const idToken = await result.user.getIdToken(true);
    const response = await fetch('link_google_voter.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ id_token: idToken }),
    });
    const data = await response.json();
    if (!response.ok || data.status !== 'success') {
      throw new Error(data.message || 'Unable to link this Google account.');
    }
    await auth.signOut().catch((error) => console.warn('Firebase browser sign-out failed:', error));
    return data;
  }

  function finishLinkOffer() {
    const callback = pendingLinkContinue;
    pendingLinkContinue = null;
    const linkModalElement = element('linkGoogleModal');
    if (linkModalElement && global.bootstrap) {
      global.bootstrap.Modal.getOrCreateInstance(linkModalElement).hide();
    }
    setTimeout(() => callback?.(), 180);
  }

  function offerLink(onContinue) {
    pendingLinkContinue = typeof onContinue === 'function' ? onContinue : null;
    const target = element('linkGoogleModal');
    if (!target || !global.bootstrap) {
      finishLinkOffer();
      return;
    }
    const message = element('linkGoogleMessage');
    if (message) message.textContent = '';
    global.bootstrap.Modal.getOrCreateInstance(target).show();
  }

  async function performLink() {
    const button = element('linkGoogleNowBtn');
    const spinner = element('linkGoogleSpinner');
    const label = element('linkGoogleLabel');
    const message = element('linkGoogleMessage');
    if (button) button.disabled = true;
    spinner?.classList.remove('d-none');
    if (label) label.textContent = 'Linking…';
    if (message) {
      message.textContent = '';
      message.className = 'small mt-2';
    }
    try {
      await linkCurrentVoter();
      if (message) {
        message.textContent = 'Google account linked successfully.';
        message.className = 'small mt-2 text-success';
      }
      setTimeout(finishLinkOffer, 650);
    } catch (error) {
      if (message) {
        message.textContent = friendlyError(error);
        message.className = 'small mt-2 text-danger';
      }
    } finally {
      if (button) button.disabled = false;
      spinner?.classList.add('d-none');
      if (label) label.textContent = 'Link Google account';
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    element('googleSignInBtn')?.addEventListener('click', signIn);
    element('legacyVoterLoginBtn')?.addEventListener('click', openLegacy);
    element('linkGoogleNowBtn')?.addEventListener('click', performLink);
    element('skipGoogleLinkBtn')?.addEventListener('click', finishLinkOffer);
    element('googleEmbeddedBrowserWarning')?.classList.toggle('d-none', !isEmbeddedBrowser());
  });

  global.ToccaGoogleAuth = { open, openLegacy, signIn, linkCurrentVoter, offerLink, isEmbeddedBrowser };
})(typeof window !== 'undefined' ? window : globalThis);
