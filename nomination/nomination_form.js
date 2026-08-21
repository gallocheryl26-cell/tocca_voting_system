document.addEventListener('DOMContentLoaded', () => {
  const $  = (sel, root = document) => (root || document).querySelector(sel);
  const $$ = (sel, root = document) => Array.from((root || document).querySelectorAll(sel));

  // ---------------------------------------------------------------------------
  // Registration "Photos & Videos" gallery picker
  // ---------------------------------------------------------------------------
  const MEDIA_MAX_FILES  = 8;
  const MEDIA_IMAGE_MAX  = 10 * 1024 * 1024;
  const MEDIA_VIDEO_MAX  = 100 * 1024 * 1024;
  const mediaDropzone    = $('#nominationMediaDropzone');
  const mediaInput       = $('#nominationMediaInput');
  const mediaList        = $('#nominationMediaList');
  const mediaSummary     = $('#nominationMediaSummary');
  const mediaCountEl     = $('#nominationMediaCount');
  const mediaSizeEl      = $('#nominationMediaSize');
  const mediaErrorEl     = $('#nominationMediaError');

  // Mirrors what's in mediaInput (a controlled DataTransfer list), so we can
  // add/remove without losing the user's choice — File inputs alone don't
  // allow editing once chosen.
  let mediaStore = [];

  function formatBytes(bytes) {
    if (!Number.isFinite(bytes) || bytes <= 0) return '0 B';
    const units = ['B','KB','MB','GB'];
    const i = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));
    return (bytes / Math.pow(1024, i)).toFixed(i ? 1 : 0) + ' ' + units[i];
  }
  function setMediaError(msg) {
    if (!mediaErrorEl) return;
    if (!msg) { mediaErrorEl.classList.add('d-none'); mediaErrorEl.textContent = ''; return; }
    mediaErrorEl.textContent = msg;
    mediaErrorEl.classList.remove('d-none');
  }
  function classifyFile(file) {
    if (file.type.startsWith('image/')) return 'image';
    if (file.type.startsWith('video/')) return 'video';
    return null;
  }
  function refreshMediaInput() {
    if (!mediaInput) return;
    const dt = new DataTransfer();
    mediaStore.forEach(item => dt.items.add(item.file));
    mediaInput.files = dt.files;
  }
  function refreshMediaSummary() {
    if (!mediaSummary) return;
    const count = mediaStore.length;
    const total = mediaStore.reduce((n, it) => n + (it.file.size || 0), 0);
    mediaSummary.classList.toggle('d-none', count === 0);
    if (mediaCountEl) mediaCountEl.textContent = String(count);
    if (mediaSizeEl)  mediaSizeEl.textContent  = formatBytes(total);
  }
  function renderMediaList() {
    if (!mediaList) return;
    mediaList.innerHTML = '';
    mediaStore.forEach((item, idx) => {
      const col = document.createElement('div');
      col.className = 'col-12 col-sm-6 col-md-4 col-lg-3';
      col.dataset.mediaIndex = String(idx);

      const tile = document.createElement('div');
      tile.className = 'media-tile';

      const preview = document.createElement('div');
      preview.className = 'media-preview';

      const isVideo = item.kind === 'video';
      if (isVideo) {
        const v = document.createElement('video');
        v.src = item.url;
        v.preload = 'metadata';
        v.muted = true;
        v.playsInline = true;
        preview.appendChild(v);
      } else {
        const img = document.createElement('img');
        img.src = item.url;
        img.alt = item.file.name;
        preview.appendChild(img);
      }

      const badge = document.createElement('span');
      badge.className = 'media-badge';
      badge.textContent = isVideo ? 'Video' : 'Image';
      preview.appendChild(badge);

      const removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'media-remove';
      removeBtn.setAttribute('aria-label', 'Remove ' + item.file.name);
      removeBtn.innerHTML = '<i class="fa-solid fa-xmark"></i>';
      removeBtn.addEventListener('click', () => removeMediaAt(idx));
      preview.appendChild(removeBtn);

      const meta = document.createElement('div');
      meta.className = 'media-meta';

      const name = document.createElement('div');
      name.className = 'media-name';
      name.textContent = item.file.name + ' · ' + formatBytes(item.file.size);
      meta.appendChild(name);

      const caption = document.createElement('input');
      caption.type = 'text';
      caption.maxLength = 255;
      caption.className = 'form-control form-control-sm caption-input';
      caption.placeholder = 'Caption (optional)';
      caption.name = 'nomination_media_caption[]';
      caption.value = item.caption || '';
      caption.addEventListener('input', () => { item.caption = caption.value; });
      meta.appendChild(caption);

      tile.appendChild(preview);
      tile.appendChild(meta);
      col.appendChild(tile);
      mediaList.appendChild(col);
    });
  }
  function removeMediaAt(idx) {
    const it = mediaStore[idx];
    if (it && it.url) URL.revokeObjectURL(it.url);
    mediaStore.splice(idx, 1);
    refreshMediaInput();
    renderMediaList();
    refreshMediaSummary();
    setMediaError('');
  }
  function addMediaFiles(filesLike) {
    setMediaError('');
    const incoming = Array.from(filesLike || []);
    if (!incoming.length) return;
    if (mediaStore.length + incoming.length > MEDIA_MAX_FILES) {
      setMediaError('You can upload at most ' + MEDIA_MAX_FILES + ' files. Some were not added.');
      incoming.splice(MEDIA_MAX_FILES - mediaStore.length);
    }
    incoming.forEach(file => {
      const kind = classifyFile(file);
      if (!kind) {
        setMediaError('Skipped "' + file.name + '" (only images and videos are allowed).');
        return;
      }
      const max = kind === 'image' ? MEDIA_IMAGE_MAX : MEDIA_VIDEO_MAX;
      if (file.size > max) {
        const mb = Math.round(max / (1024 * 1024));
        setMediaError('Skipped "' + file.name + '" (max ' + mb + ' MB for ' + kind + 's).');
        return;
      }
      mediaStore.push({
        file,
        kind,
        url: URL.createObjectURL(file),
        caption: ''
      });
    });
    refreshMediaInput();
    renderMediaList();
    refreshMediaSummary();
  }

  if (mediaInput) {
    mediaInput.addEventListener('change', (e) => {
      addMediaFiles(e.target.files);
      // Reset the underlying input so the same file can be re-added later if removed.
      mediaInput.value = '';
      refreshMediaInput();
    });
  }
  if (mediaDropzone) {
    ['dragenter','dragover'].forEach(evt => {
      mediaDropzone.addEventListener(evt, (e) => {
        e.preventDefault(); e.stopPropagation();
        mediaDropzone.classList.add('is-dragging');
      });
    });
    ['dragleave','dragend','drop'].forEach(evt => {
      mediaDropzone.addEventListener(evt, (e) => {
        e.preventDefault(); e.stopPropagation();
        mediaDropzone.classList.remove('is-dragging');
      });
    });
    mediaDropzone.addEventListener('drop', (e) => {
      const dt = e.dataTransfer;
      if (dt && dt.files && dt.files.length) addMediaFiles(dt.files);
    });
    mediaDropzone.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        mediaInput && mediaInput.click();
      }
    });
  }

  function updateToastPosition() {
    const container = document.getElementById('toastContainer');
    const stepperShell = document.querySelector('.stepper-shell');
    if (!container) return;

    const isMobile = window.matchMedia('(max-width: 767.98px)').matches;
    if (!isMobile || !stepperShell) {
      container.style.top = '12px';
      return;
    }

    const stepperBottom = stepperShell.getBoundingClientRect().bottom;
    const safeTop = 8;
    container.style.top = Math.max(safeTop, Math.round(stepperBottom + 8)) + 'px';
  }

  function toast(msg, ok = true) {
    const el = $('#toastMsg');
    const body = $('#toastBody');
    if (!el || !body) return alert(msg);
    el.className = `toast align-items-center text-bg-${ok ? 'success' : 'danger'} border-0`;
    body.textContent = msg;
    updateToastPosition();
    const instance = bootstrap.Toast.getOrCreateInstance(el, { delay: 4000 });
    el.addEventListener('shown.bs.toast', updateToastPosition, { once: true });
    instance.show();
  }

  window.addEventListener('resize', updateToastPosition, { passive: true });
  window.addEventListener('scroll', updateToastPosition, { passive: true });
  function spin(btn, spinning = true) {
    const spinner = btn?.querySelector('.spinner-border');
    if (!spinner) return;
    spinner.classList.toggle('d-none', !spinning);
    btn.disabled = spinning;
    btn.setAttribute('aria-busy', spinning ? 'true' : 'false');
    const full = btn.querySelector('.btn-label-full');
    const short = btn.querySelector('.btn-label-short');
    if (full) full.textContent = spinning ? 'Submitting…' : 'Submit Registration';
    if (short) short.textContent = spinning ? 'Submitting…' : 'Submit';
    if (!full && !short) {
      const label = btn.querySelector('.btn-label');
      if (label) label.textContent = spinning ? 'Submitting…' : 'Submit Registration';
    }
  }
  const form = $('#nominationForm');
  if (!form) return;
  const formSteps  = $$('.form-step');
  const stepper    = $('#stepper');
  const submitBtn  = $('#submitBtn');
  const selectedAwardsInput = $('#selectedAwardsInput');
  function findFieldByLabelText(rootEl, regex) {
    const labels = Array.from((rootEl || document).querySelectorAll('label'));
    const found = labels.find(l => regex.test((l.textContent || '').trim()));
    if (!found) return null;
    const fid = found.getAttribute('for');
    if (fid) {
      const byId = (rootEl || document).querySelector('#' + CSS.escape(fid));
      if (byId) return byId;
      const byName = (rootEl || document).querySelector(`[name="${fid}"]`);
      if (byName) return byName;
    }
    const nearest = found.closest('.col-12, .col-md-6, .col-md-4') || found.parentElement;
    return nearest ? nearest.querySelector('input, select, textarea') : null;
  }

  function isPhoneField(el) {
    if (!el || el.tagName !== 'INPUT') return false;
    if (isEmailInput(el)) return false;
    if (el.type === 'tel') return true;
    const wrap = el.closest('[data-field-type]');
    if (wrap?.getAttribute('data-field-type') === 'tel') return true;
    const role = el.closest('[data-profile-role]')?.getAttribute('data-profile-role');
    if (role === 'mobile') return true;
    const label = getFieldLabel(el).toLowerCase();
    if (/\bemail\b/.test(label)) return false;
    if (/\b(mobile|phone|telephone|cell)\b/.test(label)) return true;
    return /\bcontact\b/.test(label) && /\b(number|no\.?|num|mobile|phone)\b/.test(label);
  }

  function findPhoneFields(rootEl) {
    const root = rootEl || document;
    const seen = new Set();
    const out = [];
    root.querySelectorAll(
      '[data-profile-role="mobile"] input, [data-field-type="tel"] input, input[type="tel"]'
    ).forEach(el => {
      if (!(el instanceof HTMLInputElement) || seen.has(el) || !isPhoneField(el)) return;
      seen.add(el);
      out.push(el);
    });
    root.querySelectorAll('input, textarea').forEach(el => {
      if (!(el instanceof HTMLInputElement) || seen.has(el) || !isPhoneField(el)) return;
      seen.add(el);
      out.push(el);
    });
    return out;
  }

  function findPhoneField(rootEl) {
    return findPhoneFields(rootEl)[0] || null;
  }

  function isUrlField(el) {
    if (!el || el.tagName !== 'INPUT') return false;
    if (el.type === 'url') return true;
    const wrap = el.closest('[data-field-type]');
    if (wrap?.getAttribute('data-field-type') === 'url') return true;
    return el.closest('[data-profile-role="website"]') != null;
  }

  function isValidUrl(value) {
    const raw = String(value || '').trim();
    if (!raw) return true;
    try {
      const u = new URL(/^https?:\/\//i.test(raw) ? raw : 'https://' + raw);
      return Boolean(u.hostname && u.hostname.includes('.'));
    } catch {
      return false;
    }
  }

  function findUrlFields(root) {
    const scope = root || form;
    if (!scope) return [];
    const seen = new Set();
    const out = [];
    scope.querySelectorAll('input[type="url"], [data-field-type="url"] input, [data-profile-role="website"] input').forEach(el => {
      if (!(el instanceof HTMLInputElement) || seen.has(el)) return;
      seen.add(el);
      out.push(el);
    });
    return out;
  }

  function testInputPattern(el) {
    const pat = el.getAttribute('pattern');
    const val = String(el.value || '').trim();
    if (!pat || !val) return true;
    try {
      return new RegExp('^(?:' + pat + ')$').test(val);
    } catch {
      return true;
    }
  }
  const PHONE_REGEX = /^09\d{9}$/;
  const PHONE_INVALID_MSG = 'Enter a valid mobile number (11 digits, starting with 09).';
  const MAYORS_PERMIT_REGEX = /^MP-\d{4}-ORM-\d{6}$/;
  const MAYORS_PERMIT_EXAMPLE = 'MP-2026-ORM-123456';
  const MAYORS_PERMIT_INVALID_MSG = "Mayor's Permit Number must use the format MP-YYYY-ORM-123456 (example: " + MAYORS_PERMIT_EXAMPLE + ').';
  const MAYORS_PERMIT_INPUT_LEN = MAYORS_PERMIT_EXAMPLE.length;

  function isMayorsPermitField(el) {
    if (!el || el.tagName !== 'INPUT') return false;
    const role = el.closest('[data-profile-role]')?.getAttribute('data-profile-role');
    if (role === 'mayor_permit') return true;
    const label = getFieldLabel(el).toLowerCase();
    return /\b(mayor|permit)\b/.test(label);
  }

  function runMayorPermitCheck(el, opts = {}) {
    if (!isMayorsPermitField(el) || !isTextMayorsPermitField(el)) return { ok: true };
    const val = String(el.value || '').trim();
    if (val === '') {
      clearFieldError(el);
      return { ok: true };
    }
    const complete = val.length >= MAYORS_PERMIT_INPUT_LEN;
    if (!complete && !opts.onBlur) {
      clearFieldError(el);
      return { ok: true, partial: true };
    }
    if (!MAYORS_PERMIT_REGEX.test(val)) {
      if (opts.showError !== false) {
        setFieldError(el, MAYORS_PERMIT_INVALID_MSG);
      }
      return { ok: false };
    }
    clearFieldError(el);
    return { ok: true };
  }

  function runUrlCheck(el, opts = {}) {
    if (!isUrlField(el)) return { ok: true };
    const url = String(el.value || '').trim();
    if (url === '') {
      clearFieldError(el);
      return { ok: true };
    }
    if (!isValidUrl(url)) {
      if (opts.showError !== false) {
        setFieldError(el, (getFieldLabel(el) || 'Website URL') + ' must be a valid URL (e.g. https://example.com).');
      }
      return { ok: false };
    }
    clearFieldError(el);
    return { ok: true };
  }

  function runPatternLengthCheck(el, opts = {}) {
    if (!el || el.type === 'file' || el.type === 'checkbox' || el.type === 'radio') return { ok: true };
    if (isPhoneField(el) || isEmailInput(el) || isUrlField(el) || isMayorsPermitField(el)) return { ok: true };
    const val = String(el.value || '').trim();
    if (val === '') {
      return { ok: true };
    }
    const maxLen = el.getAttribute('maxlength');
    if (maxLen && val.length > parseInt(maxLen, 10)) {
      if (opts.showError !== false) {
        setFieldError(el, getFieldLabel(el) + ' must be at most ' + maxLen + ' characters.');
      }
      return { ok: false };
    }
    if (el.pattern && !testInputPattern(el)) {
      if (opts.showError !== false) {
        setFieldError(el, getFieldLabel(el) + ' format is invalid.');
      }
      return { ok: false };
    }
    return { ok: true };
  }

  function runLiveFieldCheck(el, opts = {}) {
    if (isPhoneField(el)) return runPhoneCheck(el, opts);
    if (isEmailInput(el)) return runEmailCheck(el, opts);
    if (isMayorsPermitField(el)) return runMayorPermitCheck(el, opts);
    if (isUrlField(el)) return runUrlCheck(el, opts);
    return runPatternLengthCheck(el, opts);
  }

  function usesLiveFormatCheck(el) {
    return isPhoneField(el) || isEmailInput(el) || isUrlField(el) || isMayorsPermitField(el)
      || !!(el?.getAttribute('pattern') || el?.getAttribute('maxlength'));
  }

  function clearFieldWarning(el) {
    el?.classList.remove('is-warning');
  }

  function setFieldWarning(el) {
    if (!el) return;
    el.classList.add('is-warning');
    el.classList.remove('is-invalid');
    getNomMobileSelectParts(el)?.trigger?.classList.remove('is-invalid');
    const fb = findInvalidFeedback(el);
    if (fb) {
      fb.textContent = '';
      fb.classList.remove('d-block');
    }
  }

  function runPhoneCheck(el, opts = {}) {
    if (!isPhoneField(el)) return { ok: true };
    const digits = String(el.value || '').replace(/\D/g, '');
    if (digits === '') {
      clearFieldError(el);
      return { ok: true };
    }
    const complete = digits.length >= 11;
    if (!complete && !opts.onBlur) {
      clearFieldError(el);
      return { ok: true, partial: true };
    }
    if (!PHONE_REGEX.test(digits)) {
      if (opts.showError !== false) {
        setFieldError(el, PHONE_INVALID_MSG);
      }
      return { ok: false };
    }
    clearFieldError(el);
    return { ok: true };
  }

  /** Mayor permit number format applies only to text-like fields, not file uploads. */
  function findMayorsPermitField(rootEl) {
    const root = rootEl || document;
    const byRole = root.querySelector('[data-profile-role="mayor_permit"] input:not([type="hidden"])');
    if (byRole) return byRole;
    return findFieldByLabelText(root, /mayor.*permit/i) || findFieldByLabelText(root, /mayor/i);
  }
  function isTextMayorsPermitField(el) {
    if (!el || el.type === 'file') return false;
    const wrap = el.closest('[data-field-type]');
    if (wrap && wrap.getAttribute('data-field-type') === 'file') return false;
    return true;
  }

  function getFieldLabel(el) {
    if (!el) return 'This field';
    const aria = (el.getAttribute('aria-label') || '').trim();
    if (aria) return aria;
    if (el.id) {
      const byFor = document.querySelector(`label[for="${CSS.escape(el.id)}"]`);
      const short = byFor?.getAttribute('data-short-label');
      if (short) return short.trim();
      if (byFor) return (byFor.textContent || '').replace(/\*/g, '').trim();
    }
    const wrap = fieldErrorHost(el);
    const lab = wrap?.querySelector('label.form-label, label.form-check-label');
    if (lab) {
      const short = lab.getAttribute('data-short-label');
      if (short) return short.trim();
      return (lab.textContent || '').replace(/\*/g, '').trim();
    }
    return (el.name || 'This field').trim();
  }

  function requiredFieldMessage(el) {
    if (!el) return 'This field is required.';
    if (el.id === 'confirmAccuracy') {
      return 'Please confirm that the information you entered is accurate.';
    }
    if (el.id === 'agreePrivacy') {
      return 'Please agree to the Privacy Policy before submitting.';
    }
    if (el.closest('.consent-block')) {
      return getFieldLabel(el) + ' (required).';
    }
    const label = getFieldLabel(el);
    if ((el.type === 'checkbox' || el.type === 'radio') && label.length > 72) {
      return 'Please complete this required confirmation.';
    }
    return label + ' is required.';
  }

  function fieldErrorHost(el) {
    if (!el) return null;
    const estHost = el.closest('#establishmentTypeField, [data-built-in="establishment_type"]');
    if (estHost) return estHost;
    return el.closest('[data-field-id]')
      || el.closest('.col-12, .col-md-6, .col-md-4, .form-check, .consent-block');
  }

  function findInvalidFeedback(el) {
    const host = fieldErrorHost(el);
    if (!host) return null;
    return host.querySelector('.invalid-feedback.js-field-error')
      || host.querySelector('.invalid-feedback');
  }

  function clearFieldError(el) {
    if (!el) return;
    el.classList.remove('is-invalid');
    getNomMobileSelectParts(el)?.trigger?.classList.remove('is-invalid');
    const fb = findInvalidFeedback(el);
    if (fb) {
      fb.textContent = '';
      fb.classList.remove('d-block');
    }
    el.closest('.input-group')?.classList.remove('is-invalid');
  }

  function setFieldError(el, message) {
    if (!el || !message) return;
    el.classList.add('is-invalid');
    getNomMobileSelectParts(el)?.trigger?.classList.add('is-invalid');
    el.closest('.input-group')?.classList.add('is-invalid');
    const host = fieldErrorHost(el) || el.parentElement;
    // Drop stray messages that were previously injected inside a single checkbox row
    if (host?.matches?.('#establishmentTypeField, [data-built-in="establishment_type"]')) {
      host.querySelectorAll('.nom-est-type-list .invalid-feedback').forEach((node) => node.remove());
      host.classList.add('is-invalid');
    }
    let fb = findInvalidFeedback(el);
    if (!fb) {
      fb = document.createElement('div');
      fb.className = 'invalid-feedback js-field-error';
      fb.setAttribute('role', 'alert');
      const list = host?.querySelector?.('#establishmentTypeCheckboxes, .nom-est-type-list');
      if (list && list.parentElement === host) {
        list.insertAdjacentElement('afterend', fb);
      } else {
        host?.appendChild(fb);
      }
    }
    fb.textContent = message;
    fb.classList.add('d-block');
  }

  // ---------------------------------------------------------------------------
  // Email typo detection (see email_check.js)
  // ---------------------------------------------------------------------------
  function isEmailInput(el) {
    if (!el || el.tagName !== 'INPUT') return false;
    if (el.type === 'email') return true;
    const wrap = el.closest('[data-field-type]');
    return wrap?.getAttribute('data-field-type') === 'email';
  }

  function emailFieldHost(el) {
    return fieldErrorHost(el) || el?.parentElement;
  }

  function emailConfirmKey(el) {
    return el?.name || el?.id || '';
  }

  function isEmailConfirmed(el) {
    return el?.dataset?.emailConfirmed === '1';
  }

  function setEmailConfirmed(el, confirmed) {
    if (!el) return;
    if (confirmed) {
      el.dataset.emailConfirmed = '1';
    } else {
      delete el.dataset.emailConfirmed;
    }
    syncEmailConfirmHidden(el);
  }

  function syncEmailConfirmHidden(el) {
    if (!el || !form) return;
    const key = emailConfirmKey(el);
    if (!key) return;
    const hiddenName = 'email_confirmed[' + key + ']';
    let hidden = form.querySelector('input[type="hidden"][data-email-confirmed="' + CSS.escape(key) + '"]');
    if (isEmailConfirmed(el)) {
      if (!hidden) {
        hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = hiddenName;
        hidden.value = '1';
        hidden.setAttribute('data-email-confirmed', key);
        hidden.setAttribute('data-legacy-mirror', '1');
        form.appendChild(hidden);
      }
    } else if (hidden) {
      hidden.remove();
    }
  }

  function clearEmailSuggestion(el) {
    const host = emailFieldHost(el);
    host?.querySelector('.email-suggest-hint')?.remove();
  }

  function showEmailSuggestion(el, check) {
    clearEmailSuggestion(el);
    if (!check?.suggestion) return;
    const host = emailFieldHost(el);
    if (!host) return;

    const hint = document.createElement('div');
    hint.className = 'email-suggest-hint';
    hint.setAttribute('role', 'status');
    hint.innerHTML =
      '<div><strong>Did you mean</strong> ' +
      '<span class="fw-semibold">' + check.suggestion.email + '</span>?</div>' +
      '<div class="email-suggest-actions">' +
      '<button type="button" class="btn btn-sm btn-primary js-email-use-suggest">Use suggested email</button>' +
      '<button type="button" class="btn btn-sm btn-outline-secondary js-email-keep-typed">Keep what I typed</button>' +
      '</div>';

    hint.querySelector('.js-email-use-suggest')?.addEventListener('click', () => {
      el.value = check.suggestion.email;
      setEmailConfirmed(el, false);
      clearFieldWarning(el);
      clearFieldError(el);
      clearEmailSuggestion(el);
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.focus();
    });

    hint.querySelector('.js-email-keep-typed')?.addEventListener('click', () => {
      setEmailConfirmed(el, true);
      clearFieldWarning(el);
      clearFieldError(el);
      clearEmailSuggestion(el);
    });

    host.appendChild(hint);
  }

  function runEmailCheck(el, opts = {}) {
    if (!isEmailInput(el) || typeof EmailCheck === 'undefined') {
      return { ok: true, check: null };
    }
    const raw = String(el.value || '').trim();
    clearEmailSuggestion(el);
    if (raw === '') {
      setEmailConfirmed(el, false);
      return { ok: true, check: null };
    }

    const check = EmailCheck.check(raw);
    if (!check.valid) {
      setEmailConfirmed(el, false);
      clearFieldWarning(el);
      if (opts.showError !== false) {
        setFieldError(el, check.error || 'Enter a valid email address.');
      }
      return { ok: false, check };
    }

    if (check.suggestion && !isEmailConfirmed(el)) {
      const typedNorm = (EmailCheck.parseAddress(raw)?.full || raw).toLowerCase();
      const suggestedNorm = check.suggestion.email.toLowerCase();
      if (typedNorm !== suggestedNorm) {
        showEmailSuggestion(el, check);
        setFieldWarning(el);
        return { ok: false, check, suggest: true };
      }
    }

    clearFieldWarning(el);
    clearFieldError(el);
    return { ok: true, check };
  }

  function findEmailFields(root) {
    const scope = root || form;
    if (!scope) return [];
    const seen = new Set();
    const out = [];
    scope.querySelectorAll('input[type="email"], [data-field-type="email"] input').forEach(el => {
      if (!(el instanceof HTMLInputElement) || seen.has(el)) return;
      seen.add(el);
      out.push(el);
    });
    return out;
  }

  function validateEmailFields(stepEl) {
    const errors = [];
    let firstBad = null;
    findEmailFields(stepEl).forEach(el => {
      const raw = String(el.value || '').trim();
      if (!raw && !el.hasAttribute('required')) return;
      const result = runEmailCheck(el, { showError: true });
      if (!result.ok) {
        const msg = result.check?.error
          || (result.suggest
            ? 'Please confirm your email or use the suggested address.'
            : 'Enter a valid email address.');
        errors.push((getFieldLabel(el) || 'Email') + ': ' + msg);
        if (!firstBad) firstBad = el;
      }
    });
    return { errors, firstBad };
  }

  function clearStepFieldErrors(stepEl) {
    if (!stepEl) return;
    stepEl.querySelectorAll('.is-invalid, .is-warning').forEach(el => {
      el.classList.remove('is-invalid', 'is-warning');
    });
    stepEl.querySelectorAll('.input-group.is-invalid').forEach(el => el.classList.remove('is-invalid'));
    stepEl.querySelectorAll('.invalid-feedback.js-field-error').forEach(fb => {
      fb.textContent = '';
      fb.classList.remove('d-block');
    });
    findEmailFields(stepEl).forEach(el => clearEmailSuggestion(el));
    const estHost = stepEl.querySelector('#establishmentTypeField') || stepEl.querySelector('[data-built-in="establishment_type"]');
    const estFb = estHost?.querySelector('.invalid-feedback');
    if (estFb && !estFb.classList.contains('js-field-error')) {
      estFb.classList.remove('d-block');
    }
    const awardsErr = $('#awardsStepError');
    if (awardsErr) {
      awardsErr.classList.add('d-none');
      awardsErr.textContent = '';
    }
  }

  function findFieldByLabel(labelText) {
    const target = String(labelText || '').trim().toLowerCase();
    if (!target || !form) return null;
    const inputs = form.querySelectorAll('input, select, textarea');
    for (const el of inputs) {
      const lab = getFieldLabel(el).toLowerCase();
      if (lab === target || lab.startsWith(target) || target.startsWith(lab)) return el;
    }
    return null;
  }

  function parseServerErrorField(errorMsg) {
    const msg = String(errorMsg || '').trim();
    if (!msg) return { label: null, message: msg };
    let m = msg.match(/^(.+?):\s+(.+)$/);
    if (m) return { label: m[1].trim(), message: m[2].trim() };
    m = msg.match(/^(.+?)\s+is required\.?$/i);
    if (m) return { label: m[1].trim(), message: msg };
    m = msg.match(/^(.+?)\s+must be\s+/i);
    if (m) return { label: m[1].trim(), message: msg };
    return { label: null, message: msg };
  }

  function stepIndexForElement(el) {
    const stepEl = el?.closest('.form-step');
    if (!stepEl || !formSteps?.length) return 2;
    const ds = stepEl.getAttribute('data-step');
    if (ds !== null && ds !== '') return Number(ds);
    return Math.max(0, Array.from(formSteps).indexOf(stepEl));
  }

  function applyServerValidationErrors(errors) {
    if (!Array.isArray(errors) || !errors.length) return false;
    formSteps.forEach(stepEl => clearStepFieldErrors(stepEl));
    let firstEl = null;
    let firstStep = formSteps.length - 1;
    errors.forEach(errMsg => {
      const parsed = parseServerErrorField(errMsg);
      let el = parsed.label ? findFieldByLabel(parsed.label) : null;
      if (!el && /establishment type|business categor|nature of business/i.test(errMsg)) {
        el = $('#establishmentTypeCheckboxes')?.querySelector('input[type="checkbox"]')
          || $('#establishmentTypeField');
      }
      if (!el && /privacy policy|information you entered is accurate/i.test(errMsg)) {
        el = /privacy/i.test(errMsg) ? $('#agreePrivacy') : $('#confirmAccuracy');
      }
      if (!el && /award/i.test(errMsg)) {
        firstStep = Math.min(firstStep, 1);
        const awardsErr = $('#awardsStepError');
        if (awardsErr) {
          awardsErr.textContent = errMsg;
          awardsErr.classList.remove('d-none');
        }
        $('#awards')?.classList.add('border', 'border-danger', 'rounded', 'p-2');
        return;
      }
      if (el) {
        setFieldError(el, parsed.message || errMsg);
        const si = stepIndexForElement(el);
        if (!firstEl || si < firstStep) {
          firstEl = el;
          firstStep = si;
        }
      }
    });
    currentStep = firstStep;
    updateStepper({ scrollTop: false });
    showStepError(null, errors);
    window.requestAnimationFrame(() => guideToFirstInvalid(formSteps[firstStep]));
    return true;
  }

  function showStepError(message, errors) {
    const list = errors && errors.length ? errors : [message];
    const unique = [...new Set(list.filter(Boolean))];
    if (!unique.length) return;

    const activeStep = formSteps?.[currentStep];
    const hasFieldErrors = !!activeStep?.querySelector('.is-invalid, .is-warning, .invalid-feedback.js-field-error.d-block');
    const awardsErr = $('#awardsStepError');
    const hasAwardsError = !!(awardsErr && !awardsErr.classList.contains('d-none') && awardsErr.textContent.trim());

    // Inline errors (and scroll-to-field) are enough on the current step — skip toast, especially on mobile.
    if (hasFieldErrors || hasAwardsError) {
      return;
    }

    let summary;
    if (unique.length === 1) {
      summary = unique[0];
    } else {
      const consentOnly = unique.every(msg =>
        /privacy policy|information you entered is accurate|required confirmation/i.test(msg)
      );
      summary = consentOnly
        ? 'Please confirm your details and agree to the Privacy Policy before submitting.'
        : unique[0] + ' (' + (unique.length - 1) + ' more issue' + (unique.length > 2 ? 's' : '') + ' below)';
    }
    toast(summary, false);
  }

  function getSavedAwardsMap() {
    try { return JSON.parse(localStorage.getItem(AWARDS_KEY)) || {}; } catch { return {}; }
  }
  function totalSelectedAwards() {
    const map = getSavedAwardsMap();
    return Object.values(map).reduce((n, arr) => n + ((arr && arr.length) ? arr.length : 0), 0);
  }
  function validateStep(stepIdx) {
    const stepEl = formSteps?.[stepIdx];
    if (!stepEl) return true;
    clearStepFieldErrors(stepEl);
    const errors = [];
    const seenGroups = new Set();

    function fail(el, message) {
      if (!message) return;
      setFieldError(el, message);
      errors.push(message);
    }

    stepEl.querySelectorAll('input, select, textarea').forEach(el => {
      if (!el.hasAttribute('required')) return;
      if (el.type === 'checkbox' || el.type === 'radio') {
        if (el.closest('.consent-block') || el.id === 'confirmAccuracy' || el.id === 'agreePrivacy') {
          return;
        }
        if (!el.name || seenGroups.has(el.name)) return;
        seenGroups.add(el.name);
        const checked = el.name
          ? stepEl.querySelector(`input[name="${CSS.escape(el.name)}"]:checked`)
          : (el.checked ? el : null);
        if (!checked) {
          fail(el, requiredFieldMessage(el));
        }
        return;
      }
      if (el.type === 'file') {
        const tempId = el.id ? el.id + '_temp' : '';
        const tempVal = tempId ? (document.getElementById(tempId)?.value || '') : '';
        if (!el.files?.length && !String(tempVal).trim()) {
          fail(el, requiredFieldMessage(el));
        }
        return;
      }
      if (el.id === 'establishmentTypeSelect' || el.name === 'establishment_type_ids[]') {
        return;
      }
      if (!String(el.value || '').trim()) {
        fail(el, requiredFieldMessage(el));
      }
    });

    const typeHost = stepEl.querySelector('#establishmentTypeField') || stepEl.querySelector('[data-built-in="establishment_type"]');
    if (typeHost) {
      const checked = typeHost.querySelectorAll('input[name="establishment_type_ids[]"]:checked');
      if (!checked.length) {
        fail(typeHost, 'Please select at least one nature of business.');
      } else {
        typeHost.classList.remove('is-invalid');
        const fb = typeHost.querySelector('.invalid-feedback.js-field-error, .invalid-feedback');
        if (fb) {
          fb.textContent = '';
          fb.classList.remove('d-block');
        }
      }
    }

    findPhoneFields(stepEl).forEach(phoneField => {
      const phone = String(phoneField.value || '').replace(/\D/g, '');
      if (phone && !PHONE_REGEX.test(phone)) {
        fail(phoneField, PHONE_INVALID_MSG);
      }
    });

    const mpField = findMayorsPermitField(stepEl);
    if (mpField && isTextMayorsPermitField(mpField)) {
      const mp = mpField.value.trim();
      if (mp && !MAYORS_PERMIT_REGEX.test(mp)) {
        fail(mpField, MAYORS_PERMIT_INVALID_MSG);
      }
    }

    findUrlFields(stepEl).forEach(urlField => {
      const url = String(urlField.value || '').trim();
      if (url && !isValidUrl(url)) {
        fail(urlField, (getFieldLabel(urlField) || 'Website URL') + ' must be a valid URL (e.g. https://example.com).');
      }
    });

    stepEl.querySelectorAll('input:not([type="hidden"]):not([type="file"]), textarea').forEach(el => {
      const val = String(el.value || '').trim();
      if (!val) return;
      const maxLen = el.getAttribute('maxlength');
      if (maxLen && val.length > parseInt(maxLen, 10)) {
        fail(el, getFieldLabel(el) + ' must be at most ' + maxLen + ' characters.');
      }
      if (el.pattern && !testInputPattern(el)) {
        fail(el, getFieldLabel(el) + ' format is invalid.');
      }
    });

    if (stepIdx === 0) {
      const st = findFieldByLabelText(stepEl, /street/i);
      const br = findFieldByLabelText(stepEl, /barangay/i);
      if (st && !st.hasAttribute('required') && !st.value.trim()) {
        fail(st, (getFieldLabel(st) || 'Street / Building') + ' is required.');
      }
      if (br && !br.hasAttribute('required') && !br.value.trim()) {
        fail(br, (getFieldLabel(br) || 'Barangay') + ' is required.');
      }

      const emailResult = validateEmailFields(stepEl);
      emailResult.errors.forEach(msg => errors.push(msg));
    }

    if (stepIdx === 1) {
      if (totalSelectedAwards() === 0) {
        const msg = 'Select at least one award title before continuing.';
        errors.push(msg);
        const awardsErr = $('#awardsStepError');
        if (awardsErr) {
          awardsErr.textContent = msg;
          awardsErr.classList.remove('d-none');
        }
        $('#awards')?.classList.add('border', 'border-danger', 'rounded', 'p-2');
      } else {
        $('#awards')?.classList.remove('border', 'border-danger', 'rounded', 'p-2');
        validateAwardEntries().forEach((msg) => errors.push(msg));
        if (errors.length) {
          const awardsErr = $('#awardsStepError');
          if (awardsErr && awardEntriesPanel && !awardEntriesPanel.classList.contains('d-none')) {
            awardsErr.textContent = errors[errors.length - 1];
            awardsErr.classList.remove('d-none');
          }
        }
      }
    }

    if (stepIdx === 2) {
      const confirmAcc = $('#confirmAccuracy');
      if (confirmAcc && !confirmAcc.checked) {
        fail(confirmAcc, 'Please confirm that the information you entered is accurate.');
      }
      const agree = $('#agreePrivacy');
      if (agree && !agree.checked) {
        fail(agree, 'Please agree to the Privacy Policy before submitting.');
      }
    }

    if (errors.length) {
      showStepError(null, errors);
      guideToFirstInvalid(stepEl);
      return false;
    }
    return true;
  }

  function resolveGuideTarget(invalidEl) {
    if (!invalidEl) return null;

    if (
      invalidEl.id === 'establishmentTypeField'
      || invalidEl.getAttribute('data-built-in') === 'establishment_type'
      || invalidEl.classList.contains('nom-est-type-list')
    ) {
      return invalidEl.querySelector('input[type="checkbox"]') || invalidEl;
    }

    if (invalidEl.matches?.('input[type="file"]')) {
      const id = invalidEl.id;
      const forLabel = id ? document.querySelector(`label[for="${CSS.escape(id)}"]`) : null;
      return forLabel || invalidEl.closest('.dropzone') || invalidEl.closest('[data-field-id]') || invalidEl;
    }

    const mobileParts = getNomMobileSelectParts(invalidEl);
    if (mobileParts?.trigger) return mobileParts.trigger;

    if (!/^(INPUT|SELECT|TEXTAREA|BUTTON|A)$/i.test(invalidEl.tagName)) {
      const nested = invalidEl.querySelector('.is-invalid')
        || invalidEl.querySelector('input, select, textarea, button');
      if (nested && nested !== invalidEl) return resolveGuideTarget(nested);
    }

    return invalidEl;
  }

  /** Scroll + focus the first incomplete/invalid control so nominees know what to fix. */
  function guideToFirstInvalid(stepEl) {
    const awardsErr = $('#awardsStepError');
    if (awardsErr && !awardsErr.classList.contains('d-none') && awardsErr.textContent.trim()) {
      awardsErr.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }

    const firstInvalid = stepEl?.querySelector('.is-invalid');
    if (!firstInvalid) return;

    const target = resolveGuideTarget(firstInvalid) || firstInvalid;
    const scrollEl = target.closest?.(
      '[data-field-id], #establishmentTypeField, [data-built-in="establishment_type"], .form-check, .consent-block, .dropzone, .nom-field-select-wrap, .nom-mobile-select'
    ) || target;

    document.querySelectorAll('.nom-field-guide').forEach((el) => el.classList.remove('nom-field-guide'));
    scrollEl.classList.add('nom-field-guide');
    window.setTimeout(() => scrollEl.classList.remove('nom-field-guide'), 1600);

    scrollEl.scrollIntoView({ behavior: 'smooth', block: 'center' });

    window.setTimeout(() => {
      try {
        if (target && typeof target.focus === 'function' && !target.disabled) {
          target.focus({ preventScroll: true });
        }
      } catch (_) { /* ignore non-focusable targets */ }
    }, 280);
  }

  function validateAllSteps() {
    for (let i = 0; i < formSteps.length; i++) {
      if (!validateStep(i)) {
        currentStep = i;
        updateStepper({ scrollTop: false });
        // Re-guide after the step pane is shown
        window.requestAnimationFrame(() => guideToFirstInvalid(formSteps[i]));
        return false;
      }
    }
    return true;
  }
  let currentStep = 0;
  function updateStepper(opts = {}) {
    const bubbles = $$('.step', stepper);
    bubbles.forEach((s, i) => {
      s.classList.toggle('active', i === currentStep);
      s.classList.toggle('completed', i < currentStep);
      if (i === currentStep) s.setAttribute('aria-current', 'step'); else s.removeAttribute('aria-current');
    });
    const pages = $$('.form-step');
    pages.forEach((page, idx) => {
      const ds = page.getAttribute('data-step');
      const stepIndex = ds !== null ? Number(ds) : idx;
      const active = stepIndex === currentStep;
      page.classList.toggle('active', active);
      page.style.display = active ? '' : 'none';
    });
    if (currentStep === 1) {
      loadAwardsForTypes(getSelectedTypeIds(), null, { immediate: true });
    }
    if (currentStep === 2) Promise.resolve(renderReview()).catch(console.error);
    const preflight = document.querySelector('.nom-preflight');
    if (preflight) {
      preflight.classList.toggle('d-none', currentStep !== 0);
      if (currentStep !== 0) preflight.open = false;
    }
    if (opts.scrollTop !== false) {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
  }
  $$('.next-step').forEach(btn => btn.addEventListener('click', () => {
    if (validateStep(currentStep)) {
      currentStep = Math.min(currentStep + 1, formSteps.length - 1);
      updateStepper();
    }
  }));

  // Placeholder + live inline validation for formatted fields (no duplicate helper text).
  (function initFormattedFieldHints() {
    function wireLiveFormatField(el) {
      if (!el || el.dataset.liveFormatWired === '1') return;
      el.dataset.liveFormatWired = '1';

      if (isPhoneField(el)) {
        el.setAttribute('inputmode', 'numeric');
        el.setAttribute('maxlength', '11');
        el.removeAttribute('pattern');
        el.setAttribute('autocomplete', 'tel');
        if (!el.getAttribute('placeholder')) {
          el.setAttribute('placeholder', '09171234567');
        }
        el.addEventListener('input', () => {
          el.value = String(el.value || '').replace(/\D/g, '').slice(0, 11);
          if (el.value.length === 11) {
            runPhoneCheck(el, { showError: true });
          } else {
            clearFieldError(el);
          }
        });
      } else if (isMayorsPermitField(el) && isTextMayorsPermitField(el)) {
        el.setAttribute('placeholder', MAYORS_PERMIT_EXAMPLE);
        el.setAttribute('autocomplete', 'off');
        el.addEventListener('input', () => {
          const val = String(el.value || '').trim();
          if (val.length >= MAYORS_PERMIT_INPUT_LEN) {
            runMayorPermitCheck(el, { showError: true });
          } else {
            clearFieldError(el);
          }
        });
      } else if (isMayorsPermitField(el) && el.type === 'file') {
        el.setAttribute('accept', '.png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp');
        el.addEventListener('change', () => {
          const file = el.files && el.files[0];
          if (!file) {
            clearFieldError(el);
            return;
          }
          const okMime = /image\/(png|jpeg|webp)/i.test(file.type || '');
          const okName = /\.(png|jpe?g|webp)$/i.test(file.name || '');
          if (!okMime && !okName) {
            setFieldError(el, "Mayor's Permit must be an image (PNG, JPG, or WEBP).");
            el.value = '';
            return;
          }
          clearFieldError(el);
        });
      } else if (isUrlField(el) && !el.getAttribute('placeholder')) {
        el.setAttribute('placeholder', 'https://example.com');
      }
    }

    form?.querySelectorAll('input:not([type="hidden"]), textarea').forEach(el => {
      if (usesLiveFormatCheck(el)) wireLiveFormatField(el);
    });
  })();

  // Live-clear invalid styling so the user gets immediate feedback as they fix errors.
  form?.addEventListener('input', (e) => {
    const el = e.target;
    if (!el || !el.classList) return;
    if (isEmailInput(el)) {
      setEmailConfirmed(el, false);
      clearFieldWarning(el);
      clearEmailSuggestion(el);
      return;
    }
    if (isPhoneField(el) || isMayorsPermitField(el)) return;
    if (usesLiveFormatCheck(el) && el.classList.contains('is-invalid')) {
      clearFieldError(el);
      return;
    }
    if (el.classList.contains('is-invalid')) clearFieldError(el);
    if (currentStep === 1) {
      const awardsErr = $('#awardsStepError');
      if (awardsErr && totalSelectedAwards() > 0) {
        awardsErr.classList.add('d-none');
        $('#awards')?.classList.remove('border', 'border-danger', 'rounded', 'p-2');
      }
    }
  });
  form?.addEventListener('change', (e) => {
    const el = e.target;
    if (!el || !el.classList) return;
    if (isEmailInput(el)) {
      runEmailCheck(el, { showError: false });
    }
    if (el.classList.contains('is-invalid')) clearFieldError(el);
  });
  form?.addEventListener('focusout', (e) => {
    const el = e.target;
    if (!el || !usesLiveFormatCheck(el)) return;
    runLiveFieldCheck(el, { showError: String(el.value || '').trim() !== '', onBlur: true });
  });
  $$('.prev-step').forEach(btn => btn.addEventListener('click', () => {
    currentStep = Math.max(currentStep - 1, 0);
    updateStepper();
  }));
  const awardsContainer     = $('#awards');
  const typeSelect          = null; // legacy single-select removed; types are checkboxes
  const typeFieldHost       = $('#establishmentTypeField') || $('[data-built-in="establishment_type"]');
  let typeCheckboxHost      = $('#establishmentTypeCheckboxes');

  function resolveNominationEventIdEarly() {
    const hidden = document.getElementById('event_id');
    const fromHidden = hidden?.value ? String(hidden.value).trim() : '';
    if (fromHidden && fromHidden !== '0') return fromHidden;
    const fromDataset = form?.dataset?.eventId ? String(form.dataset.eventId).trim() : '';
    if (fromDataset && fromDataset !== '0') return fromDataset;
    if (window.EVENT_ID != null && Number(window.EVENT_ID) > 0) {
      return String(window.EVENT_ID);
    }
    return '0';
  }

  function resolveNominationApiBase() {
    let base = (typeof window.TOCCA_NOMINATION_BASE === 'string' && window.TOCCA_NOMINATION_BASE.trim())
      ? window.TOCCA_NOMINATION_BASE.trim()
      : '';
    if (!base) {
      base = document.baseURI || window.location.href;
    }
    // new URL() requires an absolute base. Relative paths like "/app/nomination/" must be resolved.
    try {
      return new URL(base, window.location.origin).href;
    } catch (_) {
      return window.location.href;
    }
  }

  function nominationApiUrl(file) {
    try {
      return new URL(file, resolveNominationApiBase()).href;
    } catch (_) {
      return new URL(file, window.location.href).href;
    }
  }

  let types = [];
  const typeById = new Map();
  const eventIdEarly = resolveNominationEventIdEarly();

  function hydrateTypesFromDom(host) {
    const root = host || typeCheckboxHost || $('#establishmentTypeCheckboxes');
    if (!root) return [];
    const rows = [];
    root.querySelectorAll('input[name="establishment_type_ids[]"]').forEach((input) => {
      const id = String(input.value || '').trim();
      if (!id) return;
      const label = root.querySelector(`label[for="${CSS.escape(input.id)}"]`);
      const name = (label?.textContent || '').trim();
      if (!name) return;
      rows.push({ id, name });
      typeById.set(id, name);
    });
    if (rows.length) types = rows;
    return rows;
  }

  function renderTypeCheckboxes(host, rows) {
    const frag = document.createDocumentFragment();
    rows.forEach((t) => {
      typeById.set(t.id, t.name);
      const wrap = document.createElement('div');
      wrap.className = 'form-check';
      const input = document.createElement('input');
      input.className = 'form-check-input';
      input.type = 'checkbox';
      input.name = 'establishment_type_ids[]';
      input.value = t.id;
      input.id = 'est_type_' + t.id;
      const label = document.createElement('label');
      label.className = 'form-check-label';
      label.setAttribute('for', input.id);
      label.textContent = t.name;
      wrap.appendChild(input);
      wrap.appendChild(label);
      frag.appendChild(wrap);
    });
    host.innerHTML = '';
    host.appendChild(frag);
    host.setAttribute('data-types-bootstrapped', '1');
  }

  // Load types immediately (before heavier UI wiring) so the field never stays stuck.
  (function loadTypesEarly() {
    typeCheckboxHost = typeCheckboxHost || $('#establishmentTypeCheckboxes');
    if (!typeCheckboxHost) return;

    const already = hydrateTypesFromDom(typeCheckboxHost);
    if (already.length) {
      typeCheckboxHost.setAttribute('data-types-bootstrapped', '1');
      return;
    }

    typeCheckboxHost.innerHTML = '<div class="text-muted small py-2" data-types-placeholder="1">Loading types…</div>';
    if (!eventIdEarly || eventIdEarly === '0') {
      typeCheckboxHost.innerHTML = '<div class="text-danger small">No active event — contact organizer</div>';
      return;
    }

    fetch(nominationApiUrl('load_categories.php?event_id=' + encodeURIComponent(eventIdEarly)), {
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin',
    })
      .then((res) => res.text().then((txt) => {
        if (!res.ok) throw new Error(res.status + ' ' + res.statusText + (txt ? (': ' + txt) : ''));
        return JSON.parse(txt);
      }))
      .then((data) => {
        if (data.status !== 'success') throw new Error(data.message || 'Failed to load types.');
        const raw = Array.isArray(data.types) ? data.types
                  : Array.isArray(data.categories) ? data.categories
                  : [];
        const rows = raw.map((r) => {
          const id = (r.type_id != null) ? r.type_id
                  : (r.category_id != null ? r.category_id : r.id);
          const name = (r.type_name || r.category_name || r.name || '').toString();
          return { id: String(id), name };
        }).filter((x) => x.id && x.name);
        typeCheckboxHost = $('#establishmentTypeCheckboxes') || typeCheckboxHost;
        if (!typeCheckboxHost) return;
        if (rows.length === 0) {
          typeCheckboxHost.innerHTML = '<div class="text-muted small">No types configured for this event</div>';
          return;
        }
        types = rows;
        typeById.clear();
        renderTypeCheckboxes(typeCheckboxHost, rows);
      })
      .catch((err) => {
        console.error('loadTypesEarly:', err);
        typeCheckboxHost = $('#establishmentTypeCheckboxes') || typeCheckboxHost;
        if (typeCheckboxHost) {
          typeCheckboxHost.innerHTML = '<div class="text-danger small">Failed to load types</div>';
        }
      });
  })();

  function scrollSelectIntoView(selectEl) {
    if (!selectEl || !window.matchMedia('(max-width: 767.98px)').matches) return;
    const wrap = selectEl.closest('.nom-field-select-wrap') || selectEl.closest('.nom-mobile-select') || selectEl;
    window.setTimeout(() => {
      wrap.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }, 80);
  }

  /* Mobile: native <select> popups ignore width CSS (Chrome/Android). Use a bottom sheet. */
  const NOM_MOBILE_MQ = window.matchMedia('(max-width: 767.98px)');
  let nomMobileSelectOpen = null;
  let nomMobileSheet = null;
  let nomMobileScrollLocked = false;
  let nomMobileScrollY = 0;

  function getNomMobileSelectParts(selectEl) {
    return selectEl?._nomMobile || null;
  }

  function getNomVisualViewportHeight() {
    const vv = window.visualViewport;
    if (vv && vv.height > 0) return vv.height;
    return window.innerHeight || document.documentElement.clientHeight || 640;
  }

  function lockNomMobileBodyScroll() {
    if (nomMobileScrollLocked) return;
    nomMobileScrollY = window.scrollY || window.pageYOffset || 0;
    document.body.classList.add('nom-select-open', 'nom-select-locked');
    document.body.style.top = `-${nomMobileScrollY}px`;
    nomMobileScrollLocked = true;
  }

  function unlockNomMobileBodyScroll() {
    if (!nomMobileScrollLocked) {
      document.body.classList.remove('nom-select-open', 'nom-select-locked');
      document.body.style.top = '';
      return;
    }
    document.body.classList.remove('nom-select-open', 'nom-select-locked');
    document.body.style.top = '';
    nomMobileScrollLocked = false;
    window.scrollTo(0, nomMobileScrollY);
  }

  function updateNomMobileSheetOverflowState() {
    const sheet = nomMobileSheet;
    if (!sheet || sheet.panel.hidden) return;
    const { list, panel, hint } = sheet;
    const hasMore = list.scrollHeight > list.clientHeight + 2;
    const nearBottom = list.scrollTop + list.clientHeight >= list.scrollHeight - 8;
    const showHint = hasMore && !nearBottom;
    panel.classList.toggle('has-more-below', showHint);
    if (hint) {
      hint.hidden = !showHint;
      hint.textContent = showHint ? 'Scroll for more options' : '';
      hint.setAttribute('aria-hidden', showHint ? 'false' : 'true');
    }
  }

  function layoutNomMobileSheet() {
    const sheet = nomMobileSheet;
    if (!sheet || sheet.panel.hidden) return;

    const vh = getNomVisualViewportHeight();
    const maxPanel = Math.max(240, Math.floor(vh * 0.92));
    sheet.panel.style.maxHeight = `${maxPanel}px`;

    // Measure with hint hidden first, then reserve space if the list overflows.
    if (sheet.hint) {
      sheet.hint.hidden = true;
      sheet.hint.textContent = '';
    }
    sheet.panel.classList.remove('has-more-below');

    const headH = sheet.head?.offsetHeight || 0;
    let listMax = Math.max(160, maxPanel - headH);
    sheet.list.style.maxHeight = `${listMax}px`;

    const overflows = sheet.list.scrollHeight > sheet.list.clientHeight + 2;
    if (overflows && sheet.hint) {
      sheet.hint.hidden = false;
      sheet.hint.textContent = 'Scroll for more options';
      sheet.panel.classList.add('has-more-below');
      const hintH = sheet.hint.offsetHeight || 36;
      listMax = Math.max(140, maxPanel - headH - hintH);
      sheet.list.style.maxHeight = `${listMax}px`;
    }

    updateNomMobileSheetOverflowState();
  }

  function ensureNomMobileSheet() {
    if (nomMobileSheet) return nomMobileSheet;

    const backdrop = document.createElement('div');
    backdrop.className = 'nom-mobile-select-backdrop';
    backdrop.hidden = true;

    const panel = document.createElement('div');
    panel.className = 'nom-mobile-select-panel';
    panel.hidden = true;
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');

    const head = document.createElement('div');
    head.className = 'nom-mobile-select-panel-head';
    const title = document.createElement('span');
    title.className = 'nom-mobile-select-panel-title';
    title.id = 'nomMobileSelectTitle';
    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'nom-mobile-select-close';
    closeBtn.setAttribute('aria-label', 'Close');
    closeBtn.innerHTML = '&times;';
    head.append(title, closeBtn);

    const list = document.createElement('div');
    list.className = 'nom-mobile-select-list';
    list.setAttribute('role', 'listbox');
    list.setAttribute('aria-labelledby', 'nomMobileSelectTitle');

    const hint = document.createElement('div');
    hint.className = 'nom-mobile-select-hint';
    hint.hidden = true;
    hint.setAttribute('aria-hidden', 'true');

    panel.append(head, list, hint);
    document.body.appendChild(backdrop);
    document.body.appendChild(panel);

    backdrop.addEventListener('click', closeNomMobileSelect);
    closeBtn.addEventListener('click', closeNomMobileSelect);
    list.addEventListener('scroll', updateNomMobileSheetOverflowState, { passive: true });

    const onViewportChange = () => {
      if (nomMobileSelectOpen) layoutNomMobileSheet();
    };
    window.addEventListener('resize', onViewportChange, { passive: true });
    window.visualViewport?.addEventListener('resize', onViewportChange, { passive: true });
    window.visualViewport?.addEventListener('scroll', onViewportChange, { passive: true });

    nomMobileSheet = { backdrop, panel, list, title, closeBtn, head, hint };
    return nomMobileSheet;
  }

  function closeNomMobileSelect() {
    if (!nomMobileSelectOpen && !(nomMobileSheet && !nomMobileSheet.panel.hidden)) {
      unlockNomMobileBodyScroll();
      return;
    }
    const parts = getNomMobileSelectParts(nomMobileSelectOpen);
    const sheet = nomMobileSheet;
    if (sheet) {
      sheet.panel.hidden = true;
      sheet.backdrop.hidden = true;
      sheet.panel.classList.remove('has-more-below');
      sheet.panel.style.maxHeight = '';
      sheet.list.style.maxHeight = '';
      if (sheet.hint) {
        sheet.hint.hidden = true;
        sheet.hint.textContent = '';
      }
    }
    parts?.trigger?.setAttribute('aria-expanded', 'false');
    nomMobileSelectOpen = null;
    unlockNomMobileBodyScroll();
  }

  function refreshNomMobileSelect(selectEl) {
    const parts = getNomMobileSelectParts(selectEl);
    if (!parts) return;
    const { trigger } = parts;
    const selOpt = selectEl.options[selectEl.selectedIndex];
    trigger.textContent = selOpt ? selOpt.text : 'Select…';
    trigger.disabled = !!selectEl.disabled;
    trigger.classList.toggle('is-placeholder', !String(selectEl.value || '').trim());
    trigger.classList.toggle('is-invalid', selectEl.classList.contains('is-invalid'));
  }

  function fillNomMobileSheet(selectEl) {
    const sheet = ensureNomMobileSheet();
    const parts = getNomMobileSelectParts(selectEl);
    const label =
      document.querySelector(`label[for="${CSS.escape(selectEl.id)}"]`) ||
      selectEl.closest('.col-12, .col-md-6, .col-md-4')?.querySelector('label.form-label');
    sheet.title.textContent = (label?.textContent || 'Select an option').replace(/\s*\*+\s*/g, '').trim();
    sheet.panel.setAttribute('aria-label', sheet.title.textContent || 'Select an option');

    sheet.list.innerHTML = '';
    const selectedVal = selectEl.value;
    Array.from(selectEl.options).forEach((opt) => {
      const item = document.createElement('button');
      item.type = 'button';
      item.className = 'nom-mobile-select-option';
      item.setAttribute('role', 'option');
      item.setAttribute('aria-selected', opt.value === selectedVal ? 'true' : 'false');
      item.textContent = opt.text;
      if (!opt.value) item.classList.add('nom-mobile-select-option--muted');
      if (opt.disabled) item.disabled = true;
      if (opt.value === selectedVal) item.classList.add('is-active');
      item.addEventListener('click', () => {
        if (item.disabled) return;
        selectEl.value = opt.value;
        selectEl.dispatchEvent(new Event('change', { bubbles: true }));
        closeNomMobileSelect();
      });
      sheet.list.appendChild(item);
    });
    refreshNomMobileSelect(selectEl);
  }

  function openNomMobileSelect(selectEl) {
    if (!NOM_MOBILE_MQ.matches || selectEl.disabled) return;
    if (nomMobileSelectOpen && nomMobileSelectOpen !== selectEl) {
      closeNomMobileSelect();
    }
    fillNomMobileSheet(selectEl);
    const parts = getNomMobileSelectParts(selectEl);
    const sheet = ensureNomMobileSheet();
    if (!parts) return;

    sheet.panel.hidden = false;
    sheet.backdrop.hidden = false;
    parts.trigger.setAttribute('aria-expanded', 'true');
    nomMobileSelectOpen = selectEl;
    lockNomMobileBodyScroll();

    // Layout after paint so header/list measurements are accurate on all phones.
    window.requestAnimationFrame(() => {
      layoutNomMobileSheet();
      const active = sheet.list.querySelector('.is-active');
      if (active) {
        active.scrollIntoView({ block: 'nearest', inline: 'nearest' });
      } else {
        sheet.list.scrollTop = 0;
      }
      updateNomMobileSheetOverflowState();
      // Second pass after fonts/safe-area settle
      window.setTimeout(() => {
        layoutNomMobileSheet();
        updateNomMobileSheetOverflowState();
      }, 50);
    });
  }

  function wireNomMobileLabel(selectEl, trigger) {
    if (!selectEl.id) return;
    const label = document.querySelector(`label[for="${CSS.escape(selectEl.id)}"]`);
    if (!label || label.dataset.nomMobileLabel === '1') return;
    if (!trigger.id) trigger.id = `${selectEl.id}_trigger`;
    label.dataset.nomMobileLabel = '1';
    const sync = () => {
      label.setAttribute('for', NOM_MOBILE_MQ.matches ? trigger.id : selectEl.id);
    };
    sync();
    NOM_MOBILE_MQ.addEventListener('change', sync);
  }

  function initNomMobileSelect(selectEl) {
    if (!selectEl || !selectEl.classList.contains('form-select')) return;
    if (selectEl.closest('.nom-mobile-select')) {
      refreshNomMobileSelect(selectEl);
      return;
    }

    const wrap = document.createElement('div');
    wrap.className = 'nom-mobile-select';
    selectEl.parentNode.insertBefore(wrap, selectEl);
    wrap.appendChild(selectEl);
    selectEl.classList.add('nom-mobile-select-native');

    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'form-select nom-mobile-select-trigger w-100';
    trigger.setAttribute('aria-haspopup', 'listbox');
    trigger.setAttribute('aria-expanded', 'false');
    trigger.setAttribute('aria-hidden', 'true');
    trigger.tabIndex = -1;

    wrap.appendChild(trigger);
    selectEl._nomMobile = { wrap, trigger };

    trigger.addEventListener('click', () => openNomMobileSelect(selectEl));
    selectEl.addEventListener('change', () => refreshNomMobileSelect(selectEl));

    wireNomMobileLabel(selectEl, trigger);
    refreshNomMobileSelect(selectEl);
  }

  function initNomMobileSelects(root) {
    const scope = root || form;
    if (!scope) return;
    scope.querySelectorAll('select.form-select').forEach(initNomMobileSelect);
    updateNomMobileSelectMode();
  }

  function updateNomMobileSelectMode() {
    const on = NOM_MOBILE_MQ.matches;
    document.querySelectorAll('.nom-mobile-select').forEach((w) => {
      w.classList.toggle('nom-mobile-select--on', on);
      const native = w.querySelector('select.nom-mobile-select-native');
      const trigger = w.querySelector('.nom-mobile-select-trigger');
      if (native) {
        native.tabIndex = on ? -1 : 0;
        if (on) {
          native.setAttribute('aria-hidden', 'true');
        } else {
          native.removeAttribute('aria-hidden');
        }
      }
      if (trigger) {
        trigger.tabIndex = on ? 0 : -1;
        if (on) {
          trigger.removeAttribute('aria-hidden');
        } else {
          trigger.setAttribute('aria-hidden', 'true');
        }
      }
    });
    if (!on) closeNomMobileSelect();
  }

  NOM_MOBILE_MQ.addEventListener('change', updateNomMobileSelectMode);
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeNomMobileSelect();
  });

  function bindSelectMobileHelpers(root) {
    const scope = root || form;
    if (!scope) return;
    scope.querySelectorAll('select.form-select').forEach((sel) => {
      if (sel.closest('.nom-mobile-select')) return;
      if (sel.dataset.nomSelectBound === '1') return;
      sel.dataset.nomSelectBound = '1';
      const open = () => {
        if (nomMobileSelectOpen) return;
        document.body.classList.add('nom-select-open');
        scrollSelectIntoView(sel);
      };
      const close = () => {
        window.setTimeout(() => {
          // Custom bottom sheet owns this class while open — don't clear it on native blur.
          if (nomMobileSelectOpen || nomMobileScrollLocked) return;
          if (!scope.querySelector('select.form-select:focus')) {
            document.body.classList.remove('nom-select-open');
          }
        }, 200);
      };
      sel.addEventListener('focus', open);
      sel.addEventListener('mousedown', open);
      sel.addEventListener('touchstart', open, { passive: true });
      sel.addEventListener('blur', close);
      sel.addEventListener('change', close);
    });
  }

  initNomMobileSelects(form);
  bindSelectMobileHelpers(form);
  const awardsSearch        = $('#awardsSearch');
  const awardsCount         = $('#awardsCount');
  const awardsCountWrapper  = $('#awardsCountWrapper');
  const AWARDS_KEY = 'nomination_selected_awards_v2';
  const TYPE_KEY   = 'nomination_selected_types_v2';

  function getSelectedTypeIds() {
    const host = document.getElementById('establishmentTypeCheckboxes')
      || typeFieldHost
      || form;
    return Array.from(host.querySelectorAll('input[name="establishment_type_ids[]"]:checked'))
      .map(cb => String(cb.value))
      .filter(Boolean);
  }

  function typeIdsCacheKey(ids) {
    return (ids || []).slice().map(String).sort().join(',') || '';
  }

  function persistSelectedTypes() {
    const ids = getSelectedTypeIds();
    localStorage.setItem(TYPE_KEY, JSON.stringify(ids));
    return ids;
  }
  function resolveNominationEventId() {
    const hidden = document.getElementById('event_id');
    const fromHidden = hidden?.value ? String(hidden.value).trim() : '';
    if (fromHidden && fromHidden !== '0') return fromHidden;
    const fromDataset = form?.dataset?.eventId ? String(form.dataset.eventId).trim() : '';
    if (fromDataset && fromDataset !== '0') return fromDataset;
    if (window.EVENT_ID != null && Number(window.EVENT_ID) > 0) {
      return String(window.EVENT_ID);
    }
    return '0';
  }

  const eventId = resolveNominationEventId();
  const DRAFT_KEY  = 'nomination_form_draft_v1_' + eventId;
  const draftStatusEl = $('#draftStatus');
  let draftSaveTimer = null;

  function getStep1Root() {
    return $('.form-step[data-step="0"]', form);
  }
  function loadPendingDraft() {
    try {
      const raw = localStorage.getItem(DRAFT_KEY);
      if (!raw) return null;
      return JSON.parse(raw);
    } catch {
      return null;
    }
  }
  function collectStep1Draft() {
    const root = getStep1Root();
    if (!root) return null;
    const data = { v: 2, savedAt: Date.now(), fields: {}, temps: {}, establishment_type_ids: [] };
    data.establishment_type_ids = getSelectedTypeIds();

    const handledRadio = new Set();
    const handledCheckbox = new Set();

    root.querySelectorAll('input, select, textarea').forEach(el => {
      if (!el.name || el.type === 'file' || el.id === 'nominationMediaInput') return;
      if (el.name === 'establishment_type_ids[]') return;
      if (el.type === 'hidden' && el.id && el.id.endsWith('_temp')) {
        if (el.value) data.temps[el.id] = el.value;
        return;
      }
      if (el.type === 'radio') {
        if (handledRadio.has(el.name)) return;
        handledRadio.add(el.name);
        const sel = root.querySelector(`input[name="${CSS.escape(el.name)}"]:checked`);
        data.fields[el.name] = sel ? sel.value : '';
        return;
      }
      if (el.type === 'checkbox') {
        if (/\[\]$/.test(el.name)) {
          if (handledCheckbox.has(el.name)) return;
          handledCheckbox.add(el.name);
          data.fields[el.name] = $$(`input[name="${CSS.escape(el.name)}"]:checked`, root).map(cb => cb.value);
        } else {
          data.fields[el.name] = el.checked;
        }
        return;
      }
      // Includes <select> (e.g. Type of Ownership). File inputs are skipped above.
      const key = el.name || el.id;
      if (key) data.fields[key] = el.value;
    });
    return data;
  }
  function applyStep1Draft(draft) {
    if (!draft) return;
    const root = getStep1Root();
    if (!root) return;

    Object.entries(draft.fields || {}).forEach(([name, val]) => {
      const els = root.querySelectorAll(`[name="${CSS.escape(name)}"]`);
      if (!els.length) return;
      const first = els[0];
      if (first.type === 'checkbox') {
        if (Array.isArray(val)) {
          els.forEach(cb => { cb.checked = val.includes(cb.value); });
        } else {
          first.checked = !!val;
        }
      } else if (first.type === 'radio') {
        els.forEach(r => { r.checked = (r.value === val); });
      } else if (els.length === 1) {
        first.value = val ?? '';
        if (first.tagName === 'SELECT') {
          if (typeof refreshNomMobileSelect === 'function') {
            refreshNomMobileSelect(first);
          }
          first.dispatchEvent(new Event('change', { bubbles: true }));
        }
      }
    });

    Object.entries(draft.temps || {}).forEach(([id, path]) => {
      const el = document.getElementById(id);
      if (el) el.value = path;
    });
  }
  function showDraftStatus(mode) {
    if (!draftStatusEl) return;
    draftStatusEl.classList.remove('d-none', 'is-saving');
    if (mode === 'saving') {
      draftStatusEl.classList.add('is-saving');
      draftStatusEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Saving draft…';
    } else {
      draftStatusEl.innerHTML = '<i class="fa-solid fa-floppy-disk me-1"></i> Draft saved';
    }
    clearTimeout(showDraftStatus._hideTimer);
    showDraftStatus._hideTimer = setTimeout(() => draftStatusEl.classList.add('d-none'), 2500);
  }
  function saveDraftNow() {
    try {
      const data = collectStep1Draft();
      if (!data) return;
      localStorage.setItem(DRAFT_KEY, JSON.stringify(data));
      showDraftStatus('saved');
    } catch (err) {
      console.warn('Draft save failed', err);
    }
  }
  function scheduleDraftSave() {
    showDraftStatus('saving');
    clearTimeout(draftSaveTimer);
    draftSaveTimer = setTimeout(saveDraftNow, 500);
  }
  function clearDraft() {
    localStorage.removeItem(DRAFT_KEY);
  }

  const step1Root = getStep1Root();
  step1Root?.addEventListener('input', scheduleDraftSave);
  step1Root?.addEventListener('change', scheduleDraftSave);

  $('#startNominationBtn')?.addEventListener('click', () => {
    const target = $('#step1Establishment') || step1Root;
    target?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    setTimeout(() => {
      const firstType = $('#establishmentTypeCheckboxes input[type="checkbox"]');
      firstType?.focus();
    }, 400);
  });

  let awardsController;
  const awardsCache = {}; 
  function parseJSONResponse(res) {
    return res.text().then(txt => {
      if (!res.ok) throw new Error(res.status + ' ' + res.statusText + (txt ? (': ' + txt) : ''));
      return JSON.parse(txt);
    });
  }
  function updateAwardsCount(count) {
    if (!awardsCount) return;
    awardsCount.textContent = count > 0 ? String(count) : '';
    awardsCountWrapper?.classList.toggle('d-none', count === 0);
  }
  function saveAwards(typeIds) {
    const key = typeIdsCacheKey(Array.isArray(typeIds) ? typeIds : getSelectedTypeIds());
    if (!key) return;
    var data = getSavedAwardsMap();
    var selected = Array.from(new Set(
      $$('#awards input[type="checkbox"]:checked').map(cb => cb.dataset.awardId || cb.value)
    ));
    data[key] = selected;
    localStorage.setItem(AWARDS_KEY, JSON.stringify(data));
    updateAwardsCount(selected.length);
  }
  function awardCard(id, name, checked, instanceKey) {
    const wrapper = document.createElement('div');
    wrapper.className = 'col-12 col-sm-6 col-lg-4 col-xl-3 award-col';
    const card = document.createElement('div');
    card.className = 'form-check d-flex align-items-start award-item border rounded p-2 h-100';
    const input = document.createElement('input');
    input.className = 'form-check-input ms-0 me-2';
    input.type = 'checkbox';
    const uid = instanceKey ? ('award-' + id + '-' + instanceKey) : ('award-' + id);
    input.id = uid;
    input.value = String(id);
    input.dataset.awardId = String(id);
    input.checked = !!checked;
    const label = document.createElement('label');
    label.className = 'form-check-label flex-grow-1';
    label.setAttribute('for', uid);
    label.textContent = name;
    card.appendChild(input);
    card.appendChild(label);
    wrapper.appendChild(card);
    return wrapper;
  }
  function dedupeAwards(list) {
    const byId = new Map();
    (list || []).forEach((award) => {
      const id = String(award?.question_id ?? '');
      if (!id || byId.has(id)) return;
      byId.set(id, award);
    });
    return Array.from(byId.values()).sort((a, b) =>
      String(a.question_name || '').localeCompare(String(b.question_name || ''), undefined, { sensitivity: 'base' })
    );
  }
  function renderAwardsIntoContainer(container, list, ids, savedSelections) {
    if (!container) return;
    const cacheKey = typeIdsCacheKey(ids);
    const awards = dedupeAwards(list);
    if (!awards.length) {
      container.innerHTML = '<p class="text-muted">No award titles available for the selected type(s).</p>';
      saveAwards(ids);
      updateAwardsCount(0);
      return;
    }
    const frag = document.createDocumentFragment();
    const section = document.createElement('section');
    section.className = 'award-category-group';
    const grid = document.createElement('div');
    grid.className = 'row g-2 award-category-grid';
    awards.forEach((award) => {
      const id = String(award.question_id);
      grid.appendChild(awardCard(id, award.question_name, savedSelections.includes(id), ''));
    });
    section.appendChild(grid);
    frag.appendChild(section);
    container.innerHTML = '';
    container.appendChild(frag);
    if (cacheKey) awardsCache[cacheKey] = list;
    updateAwardsCount(savedSelections.length);
    saveAwards(ids);
    filterAwards();
    renderAwardEntriesPanel();
  }

  const awardEntriesPanel = $('#awardEntriesPanel');
  const awardEntriesInput = $('#awardEntriesInput');
  const ENTRIES_KEY = 'nomination_award_entries_v1';

  function getSavedEntriesMap() {
    try { return JSON.parse(localStorage.getItem(ENTRIES_KEY)) || {}; } catch { return {}; }
  }
  function persistEntriesMap(map) {
    localStorage.setItem(ENTRIES_KEY, JSON.stringify(map || {}));
  }
  function awardMetaById(list) {
    const map = {};
    (list || []).forEach((a) => {
      const id = String(a.question_id || '');
      if (id) map[id] = a;
    });
    return map;
  }
  function collectAwardEntriesFromDom() {
    const out = {};
    if (!awardEntriesPanel) return out;
    awardEntriesPanel.querySelectorAll('[data-award-entry-qid]').forEach((block) => {
      const qid = String(block.getAttribute('data-award-entry-qid') || '');
      if (!qid) return;
      const multi = block.getAttribute('data-entry-multiple') === '1';
      const values = [];
      block.querySelectorAll('input[data-entry-name]').forEach((inp) => {
        const v = String(inp.value || '').trim().replace(/\s+/g, ' ');
        if (v) values.push(v);
      });
      if (!values.length) return;
      out[qid] = multi ? Array.from(new Set(values.map((v) => v.toLowerCase()))).map((key) => {
        return values.find((v) => v.toLowerCase() === key) || key;
      }) : [values[0]];
    });
    return out;
  }
  function syncAwardEntriesHidden() {
    const entries = collectAwardEntriesFromDom();
    if (awardEntriesInput) awardEntriesInput.value = JSON.stringify(entries);
    const typeKey = typeIdsCacheKey(getSelectedTypeIds());
    if (typeKey) {
      const map = getSavedEntriesMap();
      map[typeKey] = entries;
      persistEntriesMap(map);
    }
    return entries;
  }
  function validateAwardEntries() {
    const errors = [];
    if (!awardEntriesPanel || awardEntriesPanel.classList.contains('d-none')) return errors;
    awardEntriesPanel.querySelectorAll('[data-award-entry-qid]').forEach((block) => {
      const qid = String(block.getAttribute('data-award-entry-qid') || '');
      const label = block.getAttribute('data-entry-label') || 'name';
      const title = block.getAttribute('data-award-title') || 'this award';
      const values = [];
      block.querySelectorAll('input[data-entry-name]').forEach((inp) => {
        const v = String(inp.value || '').trim();
        if (v) values.push(v);
        inp.classList.toggle('is-invalid', !v && block.querySelectorAll('input[data-entry-name]').length === 1);
      });
      if (!values.length) {
        errors.push('Please enter at least one ' + label.toLowerCase() + ' for "' + title + '".');
        block.classList.add('is-invalid-entry');
      } else {
        block.classList.remove('is-invalid-entry');
      }
    });
    return errors;
  }
  function renderAwardEntriesPanel() {
    if (!awardEntriesPanel) return;
    const typeIds = getSelectedTypeIds();
    const cacheKey = typeIdsCacheKey(typeIds);
    const list = (cacheKey && awardsCache[cacheKey]) ? awardsCache[cacheKey] : [];
    const meta = awardMetaById(list);
    const selected = Array.from(new Set(
      $$('#awards input[type="checkbox"]:checked').map((cb) => String(cb.dataset.awardId || cb.value || ''))
    )).filter(Boolean);
    const saved = (cacheKey && getSavedEntriesMap()[cacheKey]) ? getSavedEntriesMap()[cacheKey] : {};
    const blocks = [];
    selected.forEach((qid) => {
      const award = meta[qid];
      if (!award || !award.entry_kind) return;
      const kind = String(award.entry_kind);
      const label = String(award.entry_label || 'Name');
      const multi = !!award.entry_multiple;
      const max = Math.max(1, Number(award.entry_max) || (multi ? 3 : 1));
      const title = String(award.question_name || '');
      const existing = Array.isArray(saved[qid]) ? saved[qid].map(String) : [];
      const seed = (existing.length ? existing : ['']).slice(0, max);
      const rows = seed.map((val) => {
        return `<div class="input-group mb-2 award-entry-row">
          <input type="text" class="form-control" data-entry-name maxlength="180"
            placeholder="${multi ? 'e.g. Burger' : ('Enter ' + label.toLowerCase())}"
            value="${String(val).replace(/"/g, '&quot;')}" aria-label="${label} for ${title}">
          ${multi ? `<button type="button" class="btn btn-outline-secondary award-entry-remove" title="Remove">&times;</button>` : ''}
        </div>`;
      }).join('');
      const atMax = seed.length >= max;
      blocks.push(`<div class="award-entry-block border rounded p-3 mb-2" data-award-entry-qid="${qid}"
        data-entry-kind="${kind}" data-entry-multiple="${multi ? '1' : '0'}" data-entry-max="${max}"
        data-entry-label="${label.replace(/"/g, '&quot;')}" data-award-title="${title.replace(/"/g, '&quot;')}">
        <div class="fw-semibold mb-1">${title}</div>
        <div class="small text-muted mb-2">${multi
          ? ('Add up to ' + max + ' product names for this award.')
          : ('Enter the ' + label.toLowerCase() + ' for this award.')}</div>
        <div class="award-entry-rows">${rows}</div>
        ${multi ? `<button type="button" class="btn btn-sm btn-outline-primary award-entry-add${atMax ? ' d-none' : ''}">
          <i class="fa-solid fa-plus me-1"></i>Add another product
        </button>` : ''}
      </div>`);
    });
    if (!blocks.length) {
      awardEntriesPanel.classList.add('d-none');
      awardEntriesPanel.innerHTML = '';
      if (awardEntriesInput) awardEntriesInput.value = '{}';
      return;
    }
    awardEntriesPanel.classList.remove('d-none');
    awardEntriesPanel.innerHTML = `<div class="fw-semibold mb-2">Details for selected award titles</div>${blocks.join('')}`;
    syncAwardEntriesHidden();
  }

  function refreshAwardEntryAddButtons(block) {
    if (!block) return;
    const max = Math.max(1, Number(block.getAttribute('data-entry-max')) || 1);
    const count = block.querySelectorAll('.award-entry-row').length;
    const addBtn = block.querySelector('.award-entry-add');
    if (addBtn) addBtn.classList.toggle('d-none', count >= max);
  }

  awardEntriesPanel?.addEventListener('click', (e) => {
    const addBtn = e.target.closest?.('.award-entry-add');
    if (addBtn) {
      const block = addBtn.closest('[data-award-entry-qid]');
      const rows = block?.querySelector('.award-entry-rows');
      const max = Math.max(1, Number(block?.getAttribute('data-entry-max')) || 1);
      if (rows && rows.querySelectorAll('.award-entry-row').length < max) {
        const wrap = document.createElement('div');
        wrap.className = 'input-group mb-2 award-entry-row';
        wrap.innerHTML = `<input type="text" class="form-control" data-entry-name maxlength="180" placeholder="e.g. Cake">
          <button type="button" class="btn btn-outline-secondary award-entry-remove" title="Remove">&times;</button>`;
        rows.appendChild(wrap);
        wrap.querySelector('input')?.focus();
      }
      refreshAwardEntryAddButtons(block);
      syncAwardEntriesHidden();
      return;
    }
    const removeBtn = e.target.closest?.('.award-entry-remove');
    if (removeBtn) {
      const row = removeBtn.closest('.award-entry-row');
      const block = removeBtn.closest('[data-award-entry-qid]');
      const rows = removeBtn.closest('.award-entry-rows');
      if (row && rows) {
        if (rows.querySelectorAll('.award-entry-row').length > 1) row.remove();
        else {
          const inp = row.querySelector('input');
          if (inp) inp.value = '';
        }
      }
      refreshAwardEntryAddButtons(block);
      syncAwardEntriesHidden();
    }
  });
  awardEntriesPanel?.addEventListener('input', (e) => {
    if (e.target?.matches?.('input[data-entry-name]')) syncAwardEntriesHidden();
  });

  let awardsLoadTimer = null;
  let awardsInFlightKey = '';

  function loadAwardsForTypes(typeIds, presetSelections, opts = {}) {
    const container = awardsContainer || $('#awards');
    if (!container) return;
    const ids = (typeIds || []).map(String).filter(Boolean);
    const immediate = opts.immediate === true;
    const debounceMs = Number.isFinite(opts.debounceMs) ? opts.debounceMs : 300;

    if (!ids.length) {
      clearTimeout(awardsLoadTimer);
      awardsLoadTimer = null;
      if (awardsController) {
        awardsController.abort();
        awardsController = null;
      }
      awardsInFlightKey = '';
      container.innerHTML = '<p class="text-muted">Select at least one nature of business to see award titles.</p>';
      updateAwardsCount(0);
      renderAwardEntriesPanel();
      return;
    }

    const cacheKey = typeIdsCacheKey(ids);
    const savedSelections = presetSelections || (getSavedAwardsMap()[cacheKey] || []);

    // Instant path: already have this type-set in memory — no network.
    if (Array.isArray(awardsCache[cacheKey])) {
      clearTimeout(awardsLoadTimer);
      awardsLoadTimer = null;
      renderAwardsIntoContainer(container, awardsCache[cacheKey], ids, savedSelections);
      return;
    }

    const runFetch = () => {
      awardsLoadTimer = null;
      // Same request already in flight — wait for it.
      if (awardsInFlightKey === cacheKey && awardsController) {
        return;
      }
      if (awardsController) awardsController.abort();
      awardsController = new AbortController();
      awardsInFlightKey = cacheKey;

      let url;
      try {
        url = nominationApiUrl(
          'load_questions.php?establishment_type_ids=' + encodeURIComponent(ids.join(',')) +
          '&event_id=' + encodeURIComponent(eventId)
        );
      } catch (err) {
        console.error('loadAwardsForTypes url:', err);
        awardsInFlightKey = '';
        container.innerHTML = '<p class="text-danger">Failed to load award titles.</p>';
        return;
      }

      container.innerHTML = '<p class="text-muted">Loading award titles…</p>';
      fetch(url, { signal: awardsController.signal })
        .then(parseJSONResponse)
        .then(data => {
          if (data.status === 'success') {
            const list = Array.isArray(data.questions) ? data.questions : [];
            awardsCache[cacheKey] = list;
            // Only paint if this response still matches the latest selection.
            const latestKey = typeIdsCacheKey(getSelectedTypeIds());
            if (latestKey && latestKey !== cacheKey) return;
            renderAwardsIntoContainer(container, list, ids, savedSelections);
          } else {
            throw new Error(data.message || 'Failed to load award titles.');
          }
        })
        .catch(err => {
          if (err.name === 'AbortError') return;
          console.error(err);
          container.innerHTML = '<p class="text-danger">Failed to load award titles. Please try again.</p>';
          toast('Failed to load award titles.', false);
        })
        .finally(() => {
          if (awardsInFlightKey === cacheKey) awardsInFlightKey = '';
        });
    };

    if (immediate) {
      clearTimeout(awardsLoadTimer);
      awardsLoadTimer = null;
      runFetch();
      return;
    }

    // Debounce: rapid checkbox clicks → one request after the user pauses.
    clearTimeout(awardsLoadTimer);
    awardsLoadTimer = setTimeout(runFetch, debounceMs);
  }

  function filterAwards() {
    const q = (awardsSearch?.value || '').toLowerCase().trim();
    $$('#awards .award-col').forEach(col => {
      const item = col.querySelector('.award-item');
      const labelTxt = item?.querySelector('label')?.textContent.toLowerCase() || '';
      col.hidden = !!(q && !labelTxt.includes(q));
    });
  }
  awardsSearch?.addEventListener('input', filterAwards);
  $('#selectAllAwards')?.addEventListener('click', () => {
    $$('#awards input[type="checkbox"]').forEach(cb => cb.checked = true);
    saveAwards(getSelectedTypeIds());
    filterAwards();
  });
  $('#clearAllAwards')?.addEventListener('click', () => {
    $$('#awards input[type="checkbox"]').forEach(cb => cb.checked = false);
    saveAwards(getSelectedTypeIds());
    filterAwards();
  });
  typeCheckboxHost?.addEventListener('change', (e) => {
    if (!e.target?.matches?.('input[name="establishment_type_ids[]"]')) return;
    typeFieldHost?.classList.remove('is-invalid');
    const ids = persistSelectedTypes();
    try {
      loadAwardsForTypes(ids); // debounced + cached
    } catch (err) {
      console.error('loadAwardsForTypes:', err);
      const container = awardsContainer || $('#awards');
      if (container) container.innerHTML = '<p class="text-danger">Failed to load awards.</p>';
    }
    scheduleDraftSave();
  });
  awardsContainer?.addEventListener('change', (e) => {
    if (!e.target?.matches?.('input[data-award-id]')) return;
    saveAwards(getSelectedTypeIds());
    filterAwards();
    renderAwardEntriesPanel();
  });
  (function loadTypes() {
    typeCheckboxHost = $('#establishmentTypeCheckboxes') || typeCheckboxHost;
    if (!typeCheckboxHost) return;

    const finishWithRows = (rows) => {
      types = rows;
      typeById.clear();
      if (!typeCheckboxHost.querySelector('input[name="establishment_type_ids[]"]')) {
        renderTypeCheckboxes(typeCheckboxHost, rows);
      } else {
        hydrateTypesFromDom(typeCheckboxHost);
      }

      let preferredTypes = [];
      try {
        const saved = JSON.parse(localStorage.getItem(TYPE_KEY) || '[]');
        if (Array.isArray(saved)) preferredTypes = saved.map(String);
      } catch (_) {}
      const draft = loadPendingDraft();
      const draftTypes = Array.isArray(draft?.establishment_type_ids)
        ? draft.establishment_type_ids.map(String)
        : (draft?.establishment_type_id ? [String(draft.establishment_type_id)] : []);
      if (draftTypes.length) preferredTypes = draftTypes;

      preferredTypes = preferredTypes.filter(id => typeById.has(id));
      preferredTypes.forEach(id => {
        const cb = typeCheckboxHost.querySelector(`input[value="${CSS.escape(id)}"]`);
        if (cb) cb.checked = true;
      });

      if (preferredTypes.length) {
        persistSelectedTypes();
        try {
          // Immediate on restore so Step 2 is ready without waiting for debounce.
          loadAwardsForTypes(preferredTypes, null, { immediate: true });
        } catch (err) {
          console.error('loadAwardsForTypes:', err);
        }
      } else {
        (awardsContainer || $('#awards')).innerHTML = '<p class="text-muted">Select at least one nature of business to see award titles.</p>';
      }
      if (draft) {
        applyStep1Draft(draft);
        preferredTypes.forEach(id => {
          const cb = typeCheckboxHost.querySelector(`input[value="${CSS.escape(id)}"]`);
          if (cb) cb.checked = true;
        });
        if (preferredTypes.length || Object.keys(draft.fields || {}).length || Object.keys(draft.temps || {}).length) {
          toast('Draft restored from your last session.', true);
        }
      }
    };

    const existing = hydrateTypesFromDom(typeCheckboxHost);
    if (existing.length) {
      // Types already on the page (server-rendered) — no load_categories network call.
      finishWithRows(existing);
      return;
    }

    typeCheckboxHost.innerHTML = '<div class="text-muted small py-2" data-types-placeholder="1">Loading types…</div>';
    if (!eventId || eventId === '0') {
      typeCheckboxHost.innerHTML = '<div class="text-danger small">No active event — contact organizer</div>';
      toast('No active event is configured. Establishment types cannot be loaded.', false);
      return;
    }

    fetch(nominationApiUrl('load_categories.php?event_id=' + encodeURIComponent(eventId)), {
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin',
    })
      .then(parseJSONResponse)
      .then(data => {
        if (data.status !== 'success') throw new Error(data.message || 'Failed to load types.');
        const rows = Array.isArray(data.types) ? data.types
                  : Array.isArray(data.categories) ? data.categories
                  : [];
        if (rows.length === 0) {
          typeCheckboxHost.innerHTML = '<div class="text-muted small">No types configured for this event</div>';
          toast('No natures of business are linked to this event yet. Ask an admin to set them up under Nature of Business.', false);
          return;
        }
        const mapped = rows.map(function (r) {
          var id = (r.type_id != null) ? r.type_id
                  : (r.category_id != null ? r.category_id : r.id);
          var name = (r.type_name || r.category_name || r.name || '').toString();
          return { id: String(id), name: name };
        }).filter(function (x) { return x.id && x.name; });
        finishWithRows(mapped);
      })
      .catch(err => {
        console.error('loadTypes:', err);
        const msg = err && err.message ? String(err.message) : 'Failed to load nature of business.';
        toast(msg, false);
        typeCheckboxHost.innerHTML = '<div class="text-danger small">Failed to load types</div>';
      });
  })();
  async function ensureAwardsCachedForTypes(typeIds) {
    const ids = (typeIds || []).map(String).filter(Boolean);
    const key = typeIdsCacheKey(ids);
    if (!key) return [];
    if (awardsCache[key]) return awardsCache[key];
    const url = nominationApiUrl(
      'load_questions.php?establishment_type_ids=' + encodeURIComponent(ids.join(',')) +
      '&event_id=' + encodeURIComponent(eventId)
    );
    const res = await fetch(url);
    const data = await res.json();
    awardsCache[key] = Array.isArray(data.questions) ? data.questions : [];
    return awardsCache[key];
  }
  function labelFor(el) {
    if (!el) return '';
    const host = el.closest('[data-field-id]');
    const byFor = el.id ? form.querySelector(`label[for="${CSS.escape(el.id)}"]`) : null;
    const byHost = host?.querySelector(':scope > label.form-label, label.form-label');
    let t = (byFor?.textContent || byHost?.textContent || '').trim();
    t = t.replace(/\s*\*+\s*$/, '').replace(/\s+/g, ' ').trim();
    if (t && !/^nf_\d+/i.test(t)) return t;
    const role = (host?.getAttribute('data-profile-role') || '').trim();
    if (role === 'mayor_permit') return "Mayor's Permit";
    return t || 'Field';
  }
  function addDetailRow(container, label, value) {
    const row = document.createElement('div');
    row.className = 'review-row';
    const dt = document.createElement('dt');
    dt.textContent = label;
    const dd = document.createElement('dd');
    if (value && value.toString().trim()) {
      dd.textContent = value;
    } else {
      dd.innerHTML = '<span class="text-muted fst-italic">Not provided</span>';
    }
    row.appendChild(dt);
    row.appendChild(dd);
    container.appendChild(row);
  }
  function addDetailListRow(container, label, items, emptyText) {
    const row = document.createElement('div');
    row.className = 'review-row review-row--list';
    const dt = document.createElement('dt');
    dt.textContent = label;
    const dd = document.createElement('dd');
    const list = Array.isArray(items)
      ? items.map(s => String(s || '').trim()).filter(Boolean)
      : [];
    if (!list.length) {
      dd.innerHTML = '<span class="text-muted fst-italic">' + (emptyText || 'Not provided') + '</span>';
    } else {
      const ul = document.createElement('ul');
      ul.className = 'review-item-list';
      list.forEach(name => {
        const li = document.createElement('li');
        li.textContent = name;
        ul.appendChild(li);
      });
      dd.appendChild(ul);
    }
    row.appendChild(dt);
    row.appendChild(dd);
    container.appendChild(row);
  }
  function addSectionTitle(container, text) {
    const t = document.createElement('div');
    t.className = 'review-section-title';
    t.textContent = text;
    container.appendChild(t);
  }
  async function renderReview() {
    const box = $('#reviewSummary');
    if (!box) return;
    box.innerHTML = '';

    const dl = document.createElement('dl');

    // -------- Business details --------
    addSectionTitle(dl, 'Business Details');
    const selectedTypeNames = getSelectedTypeIds().map(id => typeById.get(String(id)) || ('Type ' + id));
    addDetailListRow(dl, 'Nature of Business', selectedTypeNames);

    const step1 = $('.form-step[data-step="0"]', form);
    const inputs = $$('input, select, textarea', step1)
      .filter(el => el.id && el.id.startsWith('nf_') && el.type !== 'hidden');
    const handledRadio = new Set();
    const handledCBGrp = new Set();
    inputs.forEach(el => {
      const label = labelFor(el);
      if (el.type === 'radio') {
        if (handledRadio.has(el.name)) return;
        handledRadio.add(el.name);
        const selected = form.querySelector(`input[name="${CSS.escape(el.name)}"]:checked`);
        addDetailRow(dl, label, selected ? selected.value : '');
        return;
      }
      if (el.type === 'checkbox') {
        if (/\[\]$/.test(el.name)) {
          if (handledCBGrp.has(el.name)) return;
          handledCBGrp.add(el.name);
          const vals = $$(`input[name="${CSS.escape(el.name)}"]:checked`, form).map(cb => cb.value);
          addDetailRow(dl, label, vals.join(', '));
        } else {
          addDetailRow(dl, label, el.checked ? (el.value || 'Yes') : 'No');
        }
        return;
      }
      if (el.type === 'file') {
        const fileName = el.files?.[0]?.name || '';
        addDetailRow(dl, label, fileName);
        const tmp = form.querySelector(`#${CSS.escape(el.id)}_temp`);
        const showWarn = !!(tmp?.value && !el.files?.length);
        $('#logoTempReminder')?.classList.toggle('d-none', !showWarn);
        return;
      }
      if (el.tagName === 'SELECT') {
        const valText = el.selectedIndex >= 0 ? el.options[el.selectedIndex].text : '';
        addDetailRow(dl, label, valText);
        return;
      }
      addDetailRow(dl, label, el.value);
    });

    // -------- Photos & Videos --------
    if (mediaStore && mediaStore.length) {
      addSectionTitle(dl, 'Photos & Videos');
      const imgs = mediaStore.filter(m => m.kind === 'image').length;
      const vids = mediaStore.filter(m => m.kind === 'video').length;
      const parts = [];
      if (imgs) parts.push(imgs + ' image' + (imgs === 1 ? '' : 's'));
      if (vids) parts.push(vids + ' video' + (vids === 1 ? '' : 's'));
      addDetailRow(dl, 'Files attached', parts.join(' · '));
    }

    // -------- Awards --------
    const typeIds = getSelectedTypeIds();
    const cacheKey = typeIdsCacheKey(typeIds);
    const savedMap = getSavedAwardsMap();
    const selectedAwardIds = (savedMap[cacheKey] || []).map(String);
    addSectionTitle(dl, 'Selected Award Title(s)');
    if (selectedAwardIds.length && typeIds.length) {
      const awards = await ensureAwardsCachedForTypes(typeIds);
      const wanted = new Set(selectedAwardIds);
      const names = awards
        .filter(a => wanted.has(String(a.question_id)))
        .map(a => {
          const title = String(a.question_name || '').trim();
          const cat = String(a.category_name || '').trim();
          return cat ? (title + ' (' + cat + ')') : title;
        });
      addDetailListRow(dl, 'Award title(s)', names, 'None selected');

      const entries = syncAwardEntriesHidden();
      const entryLines = [];
      awards.filter(a => wanted.has(String(a.question_id)) && a.entry_kind).forEach((a) => {
        const qid = String(a.question_id);
        const vals = Array.isArray(entries[qid]) ? entries[qid].filter(Boolean) : [];
        if (!vals.length) return;
        const kind = String(a.entry_kind);
        const kindLabel = kind === 'artist' ? 'Artist' : (kind === 'stylist' ? 'Stylist' : 'Product');
        entryLines.push(String(a.question_name || '') + ': ' + kindLabel + ' — ' + vals.join(', '));
      });
      if (entryLines.length) {
        addDetailListRow(dl, 'Registered names', entryLines);
      }
    } else {
      addDetailListRow(dl, 'Award title(s)', [], 'None selected');
    }

    box.appendChild(dl);
  }
  function ensureLegacyMirrors() {
    const step1 = $('.form-step[data-step="0"]', form);
    if (!step1) return;
    const map = [
      { re: /official\s+business\s+name/i,   key: 'business_name' },
      { re: /owner.*general\s+manager/i,     key: 'owner' },
      { re: /designation|type of ownership|type of business/i, key: 'designation' },
      { re: /mayor.*permit/i,                key: 'mayors_permit' },
      { re: /mobile\s+number/i,              key: 'mobile' },
      { re: /contact\s+email/i,              key: 'email' },
      { re: /street.*building/i,             key: 'street' },
      { re: /barangay/i,                     key: 'barangay' },
    ];
    $$('input[type="hidden"][data-legacy-mirror="1"]', form).forEach(n => n.remove());
    for (const { re, key } of map) {
      if (form.elements[key]) continue;
      const el = findFieldByLabelText(step1, re);
      if (!el) continue;
      let val = '';
      if (el.type === 'radio') {
        const sel = form.querySelector(`input[name="${CSS.escape(el.name)}"]:checked`);
        val = sel ? sel.value : '';
      } else if (el.type === 'checkbox') {
        if (/\[\]$/.test(el.name)) {
          val = $$(`input[name="${CSS.escape(el.name)}"]:checked`, form).map(cb => cb.value).join(', ');
        } else {
          val = el.checked ? (el.value || '1') : '';
        }
      } else if (el.tagName === 'SELECT') {
        val = el.value || '';
      } else if (el.type !== 'file') {
        val = el.value || '';
      } else {
        continue; 
      }
      const hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = key;
      hidden.value = val;
      hidden.setAttribute('data-legacy-mirror', '1');
      form.appendChild(hidden);
    }
  }
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    if (form.dataset.formReady === '0') {
      toast('Registration form is not configured yet. Please contact the administrator.', false);
      return;
    }
    if (!validateAllSteps()) return;
    const saved = getSavedAwardsMap();
    const allSelected = Array.from(new Set(Object.values(saved).flat().map(String)));
    if (selectedAwardsInput) selectedAwardsInput.value = JSON.stringify(allSelected);
    syncAwardEntriesHidden();
    ensureLegacyMirrors();
    refreshMediaInput && refreshMediaInput();

    const fd = new FormData(form);
    spin(submitBtn, true);

    const xhr = new XMLHttpRequest();
    xhr.open('POST', form.action, true);
    xhr.responseType = 'text';
    xhr.setRequestHeader('Accept', 'application/json');

    const label = submitBtn?.querySelector('.btn-label');

    if (xhr.upload) {
      xhr.upload.onprogress = (ev) => {
        if (!ev.lengthComputable || !label) return;
        const pct = Math.max(0, Math.min(100, (ev.loaded / ev.total) * 100));
        label.textContent = pct < 100 ? 'Uploading… ' + pct.toFixed(0) + '%' : 'Finalizing…';
      };
    }
    xhr.onload = () => {
      spin(submitBtn, false);
      let data = null;
      try { data = JSON.parse(xhr.responseText || '{}'); } catch (_) {}
      if (xhr.status >= 200 && xhr.status < 300 && data?.status === 'success') {
        clearDraft();
        localStorage.removeItem(AWARDS_KEY);
        localStorage.removeItem(TYPE_KEY);
        const ref = data.reference_no ? String(data.reference_no) : '';
        if (ref) {
          try { sessionStorage.setItem('nomination_last_ref', ref); } catch (_) {}
        }
        toast(data.message || 'Registration submitted', true);
        setTimeout(() => {
          const qs = ref ? '?ref=' + encodeURIComponent(ref) : '';
          // replace() removes the form from history so Back does not return to a filled form
          window.location.replace('nomination_thankyou.php' + qs);
        }, 1200);
      } else if (Array.isArray(data?.errors) && data.errors.length && applyServerValidationErrors(data.errors)) {
        /* field-level errors already shown */
      } else {
        const msg = data?.message
          || (Array.isArray(data?.errors) ? data.errors.join(' ') : '')
          || ('Submission failed (HTTP ' + xhr.status + ').');
        toast(msg, false);
      }
    };
    xhr.onerror = () => {
      spin(submitBtn, false);
      toast('Submission failed (network error).', false);
    };
    xhr.send(fd);
  });
  updateStepper();
  updateToastPosition();
  const preflight = document.querySelector('.nom-preflight');
  if (preflight) preflight.open = true;

  // If the browser restores the form from bfcache after a successful submit, avoid duplicate entry
  window.addEventListener('pageshow', (e) => {
    if (!e.persisted) return;
    let ref = '';
    try { ref = sessionStorage.getItem('nomination_last_ref') || ''; } catch (_) {}
    if (!ref) return;
    toast('Your registration was already submitted.', true);
    window.location.replace('nomination_thankyou.php?ref=' + encodeURIComponent(ref));
  });
});
