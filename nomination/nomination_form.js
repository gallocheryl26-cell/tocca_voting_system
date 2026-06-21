document.addEventListener('DOMContentLoaded', () => {
  const $  = (sel, root = document) => (root || document).querySelector(sel);
  const $$ = (sel, root = document) => Array.from((root || document).querySelectorAll(sel));

  // ---------------------------------------------------------------------------
  // Nomination "Photos & Videos" gallery picker
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
    const label = btn?.querySelector('.btn-label');
    if (!spinner) return;
    spinner.classList.toggle('d-none', !spinning);
    btn.disabled = spinning;
    if (label) label.textContent = spinning ? 'Submitting…' : 'Submit Nomination';
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
  const PHONE_REGEX = /^09\d{9}$/;
  const MAYORS_PERMIT_REGEX = /^MP-\d{4}-ORM-\d{6}$/;
  const MAYORS_PERMIT_EXAMPLE = 'MP-2024-ORM-123456';

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
    const wrap = el.closest('.col-12, .col-md-6, .col-md-4, .form-check, .consent-block');
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

  function findInvalidFeedback(el) {
    if (!el) return null;
    const wrap = el.closest('.col-12, .col-md-6, .col-md-4, .form-check, .input-group')?.parentElement
      || el.parentElement;
    if (!wrap) return null;
    return wrap.querySelector('.invalid-feedback.js-field-error')
      || wrap.querySelector('.invalid-feedback');
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
    let fb = findInvalidFeedback(el);
    if (!fb) {
      fb = document.createElement('div');
      fb.className = 'invalid-feedback js-field-error';
      fb.setAttribute('role', 'alert');
      const host = el.closest('.col-12, .col-md-6, .col-md-4, .form-check') || el.parentElement;
      host?.appendChild(fb);
    }
    fb.textContent = message;
    fb.classList.add('d-block');
  }

  function clearStepFieldErrors(stepEl) {
    if (!stepEl) return;
    stepEl.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
    stepEl.querySelectorAll('.input-group.is-invalid').forEach(el => el.classList.remove('is-invalid'));
    stepEl.querySelectorAll('.invalid-feedback.js-field-error').forEach(fb => {
      fb.textContent = '';
      fb.classList.remove('d-block');
    });
    const estFb = stepEl.querySelector('#establishmentTypeSelect')?.closest('.col-12')?.querySelector('.invalid-feedback');
    if (estFb && !estFb.classList.contains('js-field-error')) {
      estFb.classList.remove('d-block');
    }
    const awardsErr = $('#awardsStepError');
    if (awardsErr) {
      awardsErr.classList.add('d-none');
      awardsErr.textContent = '';
    }
  }

  function showStepError(message, errors) {
    const list = errors && errors.length ? errors : [message];
    const unique = [...new Set(list.filter(Boolean))];
    if (!unique.length) return;
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
      if (el.id === 'establishmentTypeSelect') {
        if (!String(el.value || '').trim()) {
          fail(el, 'Please choose an establishment type.');
        }
        return;
      }
      if (!String(el.value || '').trim()) {
        fail(el, requiredFieldMessage(el));
      }
    });

    if (stepIdx === 0) {
      const phoneField = findFieldByLabelText(stepEl, /\b(mobile|phone|contact)\b/i);
      if (phoneField) {
        const phone = phoneField.value.trim();
        if (phone && !PHONE_REGEX.test(phone)) {
          fail(phoneField, 'Enter a valid Philippine mobile number (11 digits, starting with 09, e.g. 09171234567).');
        }
      }

      const mpField = findMayorsPermitField(stepEl);
      if (mpField && isTextMayorsPermitField(mpField)) {
        const mp = mpField.value.trim();
        if (mp && !MAYORS_PERMIT_REGEX.test(mp)) {
          fail(
            mpField,
            "Mayor's Permit Number must use the format MP-YYYY-ORM-123456 (example: " + MAYORS_PERMIT_EXAMPLE + ').'
          );
        }
      }

      const st = findFieldByLabelText(stepEl, /street/i);
      const br = findFieldByLabelText(stepEl, /barangay/i);
      if (st && !st.hasAttribute('required') && !st.value.trim()) {
        fail(st, (getFieldLabel(st) || 'Street / Building') + ' is required.');
      }
      if (br && !br.hasAttribute('required') && !br.value.trim()) {
        fail(br, (getFieldLabel(br) || 'Barangay') + ' is required.');
      }
    }

    if (stepIdx === 1) {
      if (totalSelectedAwards() === 0) {
        const msg = 'Select at least one award before continuing.';
        errors.push(msg);
        const awardsErr = $('#awardsStepError');
        if (awardsErr) {
          awardsErr.textContent = msg;
          awardsErr.classList.remove('d-none');
        }
        $('#awards')?.classList.add('border', 'border-danger', 'rounded', 'p-2');
      } else {
        $('#awards')?.classList.remove('border', 'border-danger', 'rounded', 'p-2');
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
      const firstInvalid = stepEl.querySelector('.is-invalid');
      if (firstInvalid) {
        firstInvalid.focus({ preventScroll: true });
        firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
      } else {
        $('#awardsStepError')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
      return false;
    }
    return true;
  }

  function validateAllSteps() {
    for (let i = 0; i < formSteps.length; i++) {
      if (!validateStep(i)) {
        currentStep = i;
        updateStepper();
        return false;
      }
    }
    return true;
  }
  let currentStep = 0;
  function updateStepper() {
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
    if (currentStep === 2) Promise.resolve(renderReview()).catch(console.error);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
  $$('.next-step').forEach(btn => btn.addEventListener('click', () => {
    if (validateStep(currentStep)) {
      currentStep = Math.min(currentStep + 1, formSteps.length - 1);
      updateStepper();
    }
  }));

  // Hints for formatted fields
  (function initFormattedFieldHints() {
    const step1 = $('.form-step[data-step="0"]', form);
    const mpField = findMayorsPermitField(step1);
    if (mpField && isTextMayorsPermitField(mpField)) {
      mpField.setAttribute('placeholder', MAYORS_PERMIT_EXAMPLE);
      mpField.setAttribute('autocomplete', 'off');
      mpField.setAttribute('aria-describedby', mpField.id ? mpField.id + '_format_help' : '');
      const wrap = mpField.closest('.col-12, .col-md-6, .col-md-4');
      if (wrap && !wrap.querySelector('[data-mayor-format-help]')) {
        const help = document.createElement('div');
        help.className = 'form-text';
        help.setAttribute('data-mayor-format-help', '1');
        help.id = mpField.id ? mpField.id + '_format_help' : '';
        help.textContent = 'Format: MP-YYYY-ORM-123456 (example: ' + MAYORS_PERMIT_EXAMPLE + ')';
        const fb = wrap.querySelector('.invalid-feedback.js-field-error');
        wrap.insertBefore(help, fb || null);
      }
    } else if (mpField && mpField.type === 'file') {
      const wrap = mpField.closest('.col-12, .col-md-6, .col-md-4');
      wrap?.querySelector('[data-mayor-format-help]')?.remove();
      mpField.removeAttribute('pattern');
    }
    const phoneField = findFieldByLabelText(step1, /\b(mobile|phone|contact)\b/i);
    if (phoneField) {
      phoneField.setAttribute('inputmode', 'numeric');
      phoneField.setAttribute('maxlength', '11');
      phoneField.setAttribute('pattern', '^09\\d{9}$');
      if (!phoneField.getAttribute('placeholder')) {
        phoneField.setAttribute('placeholder', '09171234567');
      }
      phoneField.addEventListener('input', () => {
        phoneField.value = String(phoneField.value || '').replace(/\D/g, '').slice(0, 11);
      });
    }
  })();

  // Live-clear invalid styling so the user gets immediate feedback as they fix errors.
  form?.addEventListener('input', (e) => {
    const el = e.target;
    if (!el || !el.classList) return;
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
    if (el.classList.contains('is-invalid')) clearFieldError(el);
  });
  $$('.prev-step').forEach(btn => btn.addEventListener('click', () => {
    currentStep = Math.max(currentStep - 1, 0);
    updateStepper();
  }));
  const awardsContainer     = $('#awards');
  const typeSelect          = $('#establishmentTypeSelect');

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

  function getNomMobileSelectParts(selectEl) {
    return selectEl?._nomMobile || null;
  }

  function ensureNomMobileSheet() {
    if (nomMobileSheet) return nomMobileSheet;

    const backdrop = document.createElement('div');
    backdrop.className = 'nom-mobile-select-backdrop';
    backdrop.hidden = true;

    const panel = document.createElement('div');
    panel.className = 'nom-mobile-select-panel';
    panel.hidden = true;

    const head = document.createElement('div');
    head.className = 'nom-mobile-select-panel-head';
    const title = document.createElement('span');
    title.className = 'nom-mobile-select-panel-title';
    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'nom-mobile-select-close';
    closeBtn.setAttribute('aria-label', 'Close');
    head.append(title, closeBtn);

    const list = document.createElement('div');
    list.className = 'nom-mobile-select-list';
    list.setAttribute('role', 'listbox');

    panel.append(head, list);
    document.body.appendChild(backdrop);
    document.body.appendChild(panel);

    backdrop.addEventListener('click', closeNomMobileSelect);
    closeBtn.addEventListener('click', closeNomMobileSelect);

    nomMobileSheet = { backdrop, panel, list, title, closeBtn };
    return nomMobileSheet;
  }

  function closeNomMobileSelect() {
    if (!nomMobileSelectOpen) return;
    const parts = getNomMobileSelectParts(nomMobileSelectOpen);
    const sheet = nomMobileSheet;
    if (sheet) {
      sheet.panel.hidden = true;
      sheet.backdrop.hidden = true;
    }
    parts?.trigger?.setAttribute('aria-expanded', 'false');
    nomMobileSelectOpen = null;
    document.body.classList.remove('nom-select-open');
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
    closeNomMobileSelect();
    fillNomMobileSheet(selectEl);
    const parts = getNomMobileSelectParts(selectEl);
    const sheet = ensureNomMobileSheet();
    if (!parts) return;
    sheet.panel.hidden = false;
    sheet.backdrop.hidden = false;
    parts.trigger.setAttribute('aria-expanded', 'true');
    nomMobileSelectOpen = selectEl;
    document.body.classList.add('nom-select-open');
    scrollSelectIntoView(selectEl);
    sheet.list.querySelector('.is-active')?.scrollIntoView({ block: 'nearest' });
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
        document.body.classList.add('nom-select-open');
        scrollSelectIntoView(sel);
      };
      const close = () => {
        window.setTimeout(() => {
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
  const showSelectedOnlyChk = $('#showSelectedOnly');
  const awardsCount         = $('#awardsCount');
  const awardsCountWrapper  = $('#awardsCountWrapper');
  const AWARDS_KEY = 'nomination_selected_awards_by_type';
  const TYPE_KEY   = 'nomination_selected_type';
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

  function nominationApiUrl(file) {
    return new URL(file, window.location.href).href;
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
    const data = { v: 1, savedAt: Date.now(), fields: {}, temps: {}, establishment_type_id: '' };
    const typeSel = $('#establishmentTypeSelect');
    if (typeSel) data.establishment_type_id = typeSel.value || '';

    const handledRadio = new Set();
    const handledCheckbox = new Set();

    root.querySelectorAll('input, select, textarea').forEach(el => {
      if (!el.name || el.type === 'file' || el.id === 'nominationMediaInput') return;
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
      if (el.tagName === 'SELECT' && el.id === 'establishmentTypeSelect') return;
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
      const sel = $('#establishmentTypeSelect');
      const trigger = sel?.closest('.nom-mobile-select')?.querySelector('.nom-mobile-select-trigger');
      (trigger || sel)?.focus();
    }, 400);
  });

  let types = [];
  const typeById = new Map();
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
  function saveAwards(typeId) {
    if (!typeId) return;
    var data = getSavedAwardsMap();
    var selected = $$('#awards input[type="checkbox"]:checked').map(cb => cb.value);
    data[typeId] = selected;
    localStorage.setItem(AWARDS_KEY, JSON.stringify(data));
    updateAwardsCount(selected.length);
  }
  function awardCard(id, name, checked) {
    const wrapper = document.createElement('div');
    wrapper.className = 'col-12 col-sm-6';
    const card = document.createElement('div');
    card.className = 'form-check d-flex align-items-start award-item border rounded p-2 h-100';
    const input = document.createElement('input');
    input.className = 'form-check-input ms-0 me-2';
    input.type = 'checkbox';
    input.id = 'award-' + id;
    input.value = String(id);
    input.checked = !!checked;
    const label = document.createElement('label');
    label.className = 'form-check-label flex-grow-1';
    label.setAttribute('for', 'award-' + id);
    label.textContent = name;
    card.appendChild(input);
    card.appendChild(label);
    wrapper.appendChild(card);
    return wrapper;
  }
  function loadAwardsForType(typeId, presetSelections) {
    awardsContainer.innerHTML = '';
    updateAwardsCount(0);
    if (!typeId) return;
    if (awardsController) awardsController.abort();
    awardsController = new AbortController();
    const savedSelections = presetSelections || (getSavedAwardsMap()[typeId] || []);
    const url = nominationApiUrl(
      'load_questions.php?establishment_type_id=' + encodeURIComponent(typeId) +
      '&category_id=' + encodeURIComponent(typeId) +
      '&event_id=' + encodeURIComponent(eventId)
    );
    fetch(url, { signal: awardsController.signal })
      .then(parseJSONResponse)
      .then(data => {
        if (data.status === 'success') {
          const list = Array.isArray(data.questions) ? data.questions : [];
          if (list.length > 0) {
            const frag = document.createDocumentFragment();
            awardsCache[typeId] = list;
            list.forEach(award => {
              const id = String(award.question_id);
              frag.appendChild(awardCard(id, award.question_name, savedSelections.includes(id)));
            });
            awardsContainer.appendChild(frag);
            updateAwardsCount(savedSelections.length);
            saveAwards(typeId);
            filterAwards();
          } else {
            awardsContainer.innerHTML = '<p class="text-muted">No awards available.</p>';
            saveAwards(typeId);
          }
        } else {
          throw new Error(data.message || 'Failed to load awards.');
        }
      })
      .catch(err => { if (err.name !== 'AbortError') { console.error(err); toast('Failed to load awards.', false); } });
  }
  function filterAwards() {
    const q = (awardsSearch?.value || '').toLowerCase().trim();
    const showOnly = !!showSelectedOnlyChk?.checked;
    $$('.award-item').forEach(item => {
      const labelTxt = item.querySelector('label')?.textContent.toLowerCase() || '';
      const cb = item.querySelector('input[type="checkbox"]');
      const matchesText = !q || labelTxt.includes(q);
      const matchesSel  = !showOnly || cb?.checked;
      item.style.display = (matchesText && matchesSel) ? '' : 'none';
    });
  }
  awardsSearch?.addEventListener('input', filterAwards);
  showSelectedOnlyChk?.addEventListener('change', filterAwards);
  $('#selectAllAwards')?.addEventListener('click', () => {
    $$('#awards input[type="checkbox"]').forEach(cb => cb.checked = true);
    saveAwards(typeSelect.value);
    filterAwards();
  });
  $('#clearAllAwards')?.addEventListener('click', () => {
    $$('#awards input[type="checkbox"]').forEach(cb => cb.checked = false);
    saveAwards(typeSelect.value);
    filterAwards();
  });
  typeSelect?.addEventListener('change', () => {
    const val = typeSelect.value;
    localStorage.setItem(TYPE_KEY, val);
    awardsContainer.innerHTML = '';
    updateAwardsCount(0);
    if (val) loadAwardsForType(val);
    scheduleDraftSave();
  });
  awardsContainer?.addEventListener('change', () => {
    saveAwards(typeSelect.value);
    filterAwards();
  });
  (function loadTypes() {
    if (!typeSelect) return;
    typeSelect.innerHTML = '<option value="">Loading…</option>';
    typeSelect.disabled = true;
    if (!eventId || eventId === '0') {
      typeSelect.innerHTML = '<option value="">No active event — contact organizer</option>';
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
          typeSelect.innerHTML = '<option value="">No types configured for this event</option>';
          toast('No establishment types are linked to this event yet. Ask an admin to set them up under Establishment Types.', false);
          typeSelect.disabled = true;
          return;
        }
        types = rows.map(function (r) {
          var id = (r.type_id != null) ? r.type_id
                  : (r.category_id != null ? r.category_id : r.id);
          var name = (r.type_name || r.category_name || r.name || '').toString();
          return { id: String(id), name: name };
        }).filter(function (x) { return x.id && x.name; });
        typeById.clear();
        var html = '<option value="">Select type…</option>';
        types.forEach(function (t) {
          typeById.set(t.id, t.name);
          html += '<option value="' + t.id + '">' + t.name.replace(/</g,'&lt;') + '</option>';
        });
        var selects = document.querySelectorAll('#establishmentTypeSelect');
        if (selects.length === 0) {
          console.warn('No element with id="establishmentTypeSelect" found.');
        } else {
          selects.forEach(function (sel) {
            sel.innerHTML = html;
            sel.disabled = false;
            refreshNomMobileSelect(sel);
          });
          if (selects.length > 1) console.warn('Multiple elements with id="establishmentTypeSelect" found:', selects.length);
          initNomMobileSelects(form);
          bindSelectMobileHelpers(form);
        }
        const savedType = localStorage.getItem(TYPE_KEY);
        const draft = loadPendingDraft();
        const draftType = draft?.establishment_type_id ? String(draft.establishment_type_id) : '';
        const preferredType = (draftType && typeById.has(draftType)) ? draftType
          : ((savedType && typeById.has(savedType)) ? savedType : '');

        if (preferredType && selects.length) {
          selects[0].value = preferredType;
          loadAwardsForType(preferredType);
        }
        if (draft) {
          applyStep1Draft(draft);
          selects.forEach((sel) => refreshNomMobileSelect(sel));
          if (draftType && typeById.has(draftType)) {
            toast('Draft restored from your last session.', true);
          } else if (Object.keys(draft.fields || {}).length || Object.keys(draft.temps || {}).length) {
            toast('Draft restored from your last session.', true);
          }
        }
      })
      .catch(err => {
        console.error('loadTypes:', err);
        const msg = err && err.message ? String(err.message) : 'Failed to load establishment types.';
        toast(msg, false);
        typeSelect.innerHTML = '<option value="">Failed to load types</option>';
      })
      .finally(() => {
        typeSelect.disabled = false;
      });
  })();
  async function ensureAwardsCachedForType(typeId) {
    if (awardsCache[typeId]) return awardsCache[typeId];
    const url = nominationApiUrl(
      'load_questions.php?establishment_type_id=' + encodeURIComponent(typeId) +
      '&category_id=' + encodeURIComponent(typeId) +
      '&event_id=' + encodeURIComponent(eventId)
    );
    const res = await fetch(url);
    const data = await res.json();
    awardsCache[typeId] = Array.isArray(data.questions) ? data.questions : [];
    return awardsCache[typeId];
  }
  function labelFor(el) {
    const lbl = form.querySelector(`label[for="${CSS.escape(el.id)}"]`);
    let t = (lbl?.textContent || el.id || '').trim();
    return t.replace(/\s*\*+\s*$/, '').trim();
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
    const savedMap = getSavedAwardsMap();
    const typeIds = Object.keys(savedMap).filter(id => savedMap[id] && savedMap[id].length);
    addSectionTitle(dl, 'Selected Awards');
    if (typeIds.length) {
      for (const typeId of typeIds) {
        const awards = await ensureAwardsCachedForType(typeId);
        const wanted = new Set(savedMap[typeId].map(String));
        const names = awards
          .filter(a => wanted.has(String(a.question_id)))
          .map(a => a.question_name);
        const typeName = typeById.get(String(typeId)) || ('Type ' + typeId);
        addDetailRow(dl, typeName, names.length ? names.join(', ') : '—');
      }
    } else {
      addDetailRow(dl, 'Awards', 'None selected');
    }

    box.appendChild(dl);
  }
  function ensureLegacyMirrors() {
    const step1 = $('.form-step[data-step="0"]', form);
    if (!step1) return;
    const map = [
      { re: /official\s+business\s+name/i,   key: 'business_name' },
      { re: /owner.*general\s+manager/i,     key: 'owner' },
      { re: /designation/i,                  key: 'designation' },
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
    if (!validateAllSteps()) return;
    const saved = getSavedAwardsMap();
    const allSelected = Array.from(new Set(Object.values(saved).flat().map(String)));
    if (selectedAwardsInput) selectedAwardsInput.value = JSON.stringify(allSelected);
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
        toast(data.message || 'Nomination submitted', true);
        setTimeout(() => {
          const qs = ref ? '?ref=' + encodeURIComponent(ref) : '';
          // replace() removes the form from history so Back does not return to a filled form
          window.location.replace('nomination_thankyou.php' + qs);
        }, 1200);
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
  if (preflight && window.matchMedia('(min-width: 768px)').matches) {
    preflight.open = true;
  }

  // If the browser restores the form from bfcache after a successful submit, avoid duplicate entry
  window.addEventListener('pageshow', (e) => {
    if (!e.persisted) return;
    let ref = '';
    try { ref = sessionStorage.getItem('nomination_last_ref') || ''; } catch (_) {}
    if (!ref) return;
    toast('Your nomination was already submitted.', true);
    window.location.replace('nomination_thankyou.php?ref=' + encodeURIComponent(ref));
  });
});
