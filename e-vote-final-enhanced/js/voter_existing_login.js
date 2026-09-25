/**
 * Existing voter login: validation, lockout UI, verify_existing.php.
 * Call VoterExistingLogin.init({ onSuccess }) after DOM ready.
 */
(function (global) {
  'use strict';

  const MOBILE_RE = /^09\d{9}$/;
  const CODE_RE = /^\d{4}$/;

  let lockTimer = null;
  let lockEndsAt = 0;

  function el(id) {
    return document.getElementById(id);
  }

  function notify(message, tone = 'info', duration = 3200) {
    if (typeof global.showToast === 'function' && message) {
      global.showToast(message, tone, duration);
    }
  }

  function showLoginError(message) {
    const errorDisplay = el('loginError');
    if (!errorDisplay) return;
    errorDisplay.textContent = message;
    errorDisplay.style.display = 'block';
  }

  function hideLoginError() {
    const errorDisplay = el('loginError');
    if (!errorDisplay) return;
    errorDisplay.textContent = '';
    errorDisplay.style.display = 'none';
  }

  function setSubmitEnabled(enabled) {
    const btn = el('checkDraftBtn');
    if (btn) btn.disabled = !enabled;
  }

  function clearLockTimer() {
    if (lockTimer) {
      clearInterval(lockTimer);
      lockTimer = null;
    }
    lockEndsAt = 0;
  }

  function formatCountdown(totalSeconds) {
    const s = Math.max(0, totalSeconds);
    const m = Math.floor(s / 60);
    const r = s % 60;
    return m + ':' + (r < 10 ? '0' : '') + r;
  }

  function startLockCountdown(seconds) {
    clearLockTimer();
    lockEndsAt = Date.now() + seconds * 1000;
    setSubmitEnabled(false);

    const tick = () => {
      const left = Math.ceil((lockEndsAt - Date.now()) / 1000);
      if (left <= 0) {
        clearLockTimer();
        setSubmitEnabled(true);
        hideLoginError();
        return;
      }
      showLoginError(
        'Too many incorrect access code attempts. Try again in ' + formatCountdown(left) + '.'
      );
    };

    tick();
    lockTimer = setInterval(tick, 1000);
  }

  function bindDigitOnlyInput(input) {
    if (!input) return;
    input.setAttribute('inputmode', 'numeric');
    input.setAttribute('autocomplete', 'one-time-code');
    input.addEventListener('input', () => {
      input.value = input.value.replace(/\D/g, '').slice(0, 4);
      hideLoginError();
    });
  }

  function validateForm(mobile, draftCode) {
    if (!MOBILE_RE.test(mobile)) {
      return 'Enter a valid 11-digit mobile number starting with 09.';
    }
    if (!CODE_RE.test(draftCode)) {
      return 'Access code must be exactly 4 digits.';
    }
    return null;
  }

  function handleVerifyResponse(data, mobile, draftCode, onSuccess) {
    if (data.status === 'success') {
      clearLockTimer();
      hideLoginError();
      if (typeof onSuccess === 'function') {
        onSuccess(data, mobile, draftCode);
      }
      return;
    }

    if (data.code === 'locked' && Number(data.lock_seconds) > 0) {
      notify(data.message || 'Too many attempts. Please wait before trying again.', 'warning', 4000);
      startLockCountdown(Number(data.lock_seconds));
      return;
    }

    const msg = data.message || 'Incorrect access code.';
    showLoginError(msg);
    notify(msg, 'danger');
  }

  async function submitLogin(onSuccess) {
    if (lockEndsAt > Date.now()) {
      return;
    }

    const mobile = (el('existingMobile')?.value || '').trim();
    const draftCode = (el('draftCode')?.value || '').trim();
    const validationError = validateForm(mobile, draftCode);
    if (validationError) {
      showLoginError(validationError);
      notify(validationError, 'warning');
      return;
    }

    hideLoginError();
    setSubmitEnabled(false);
    const btn = el('checkDraftBtn');
    const prevLabel = btn ? btn.textContent : '';
    if (btn) btn.textContent = 'Checking…';

    try {
      const res = await fetch('verify_existing.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ mobile_number: mobile, draft_code: draftCode }),
      });
      const data = await res.json();
      handleVerifyResponse(data, mobile, draftCode, onSuccess);
    } catch (err) {
      console.error(err);
      showLoginError('Something went wrong. Please try again.');
      notify('Something went wrong while verifying your access code.', 'danger');
    } finally {
      if (btn) btn.textContent = prevLabel || 'Continue';
      if (lockEndsAt <= Date.now()) {
        setSubmitEnabled(true);
      }
    }
  }

  function lockCheckedMobile(mobile) {
    const input = el('existingMobile');
    if (!input) return;
    const value = String(mobile || '').replace(/\D/g, '').slice(0, 11);
    if (!/^09\d{9}$/.test(value)) {
      return;
    }
    input.value = value;
    input.readOnly = true;
    input.classList.add('bg-light');
    input.setAttribute('aria-readonly', 'true');
    input.tabIndex = -1;
  }

  function unlockForManualEntry() {
    const input = el('existingMobile');
    if (!input) return;
    input.value = '';
    input.readOnly = false;
    input.classList.remove('bg-light');
    input.removeAttribute('aria-readonly');
    input.tabIndex = 0;
    hideLoginError();
    setTimeout(() => input.focus(), 200);
  }

  function init(options) {
    const onSuccess = options && options.onSuccess;
    const btn = el('checkDraftBtn');
    bindDigitOnlyInput(el('draftCode'));

    btn?.addEventListener('click', () => submitLogin(onSuccess));

    el('draftCode')?.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        submitLogin(onSuccess);
      }
    });

    const modalEl = el('existingVoterModal');
    modalEl?.addEventListener('shown.bs.modal', () => {
      if (el('existingMobile')?.readOnly) {
        el('draftCode')?.focus();
      }
    });
  }

  global.VoterExistingLogin = { init, validateForm, showLoginError, hideLoginError, lockCheckedMobile, unlockForManualEntry };
})(typeof window !== 'undefined' ? window : globalThis);
