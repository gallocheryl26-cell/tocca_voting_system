/**
 * Dynamic Voter Portal settings — live preview, flow map, AJAX save.
 */
(function () {
  'use strict';

  const cfg = window.TOCCA_VOTER_PORTAL || {};
  const PREVIEW_SOURCE = 'tocca-voter-portal-admin';
  const previewFrame = document.getElementById('voterLivePreview');
  const saveStatusEl = document.getElementById('vpcSaveStatus');
  const contentForm = document.getElementById('voterPortalCopyForm');
  const appearanceForm = document.getElementById('voterAppearanceForm');
  const bgInput = document.getElementById('voter_bg_color');
  const textInput = document.getElementById('voter_text_color');
  const bgHex = document.getElementById('voter_bg_hex');
  const textHex = document.getElementById('voter_text_hex');
  const logoFile = document.getElementById('voter_header_logo');
  const currentLogo = document.getElementById('voterCurrentLogo');
  const presetWrap = document.getElementById('voterPresets');

  let previewReady = false;
  let draftLogoUrl = cfg.headerLogoAdmin || '';
  let pushTimer = null;
  let dirty = false;
  let previewMode = 'landing';

  function showToast(message, type) {
    if (typeof window.showToast === 'function') {
      window.showToast(message, type || 'success');
      return;
    }
    try { alert(message); } catch (_) {}
  }

  function setSaveStatus(text, kind) {
    if (!saveStatusEl) return;
    saveStatusEl.textContent = text || '';
    saveStatusEl.className = 'vpc-save-status small' + (kind ? ' text-' + kind : ' text-muted');
  }

  function markDirty() {
    dirty = true;
    setSaveStatus('Unsaved changes', 'warning');
  }

  function markSaved() {
    dirty = false;
    const now = new Date();
    const stamp = now.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    setSaveStatus('Saved at ' + stamp, 'success');
  }

  function activeForm() {
    const appearancePane = document.getElementById('tabAppearancePane');
    if (appearancePane && appearancePane.classList.contains('active')) {
      return appearanceForm;
    }
    return contentForm;
  }

  function postToPreview(message) {
    if (!previewFrame || !previewFrame.contentWindow || !previewReady) return;
    previewFrame.contentWindow.postMessage(
      Object.assign({ source: PREVIEW_SOURCE }, message),
      window.location.origin
    );
  }

  function collectContent() {
    const copy = {
      intro_title: document.getElementById('intro_title')?.value || '',
      intro_body: document.getElementById('intro_body')?.value || '',
      how_to_title: document.getElementById('how_to_title')?.value || '',
      how_to_lead: document.getElementById('how_to_lead')?.value || '',
      footer_note: document.getElementById('footer_note')?.value || '',
      steps: [],
    };
    for (let i = 1; i <= 4; i++) {
      copy.steps.push({
        title: document.getElementById('step_title_' + i)?.value || '',
        body: document.getElementById('step_body_' + i)?.value || '',
      });
    }
    return copy;
  }

  function collectAppearance() {
    return {
      bgColor: bgInput?.value || '#ffffff',
      textColor: textInput?.value || '#000000',
      headerLogo: draftLogoUrl || '',
    };
  }

  function pushPreviewNow() {
    postToPreview({ type: 'appearance', payload: collectAppearance() });
    postToPreview({ type: 'content', payload: collectContent() });
    if (previewMode === 'welcome') {
      postToPreview({ type: 'show-intro' });
    } else if (previewMode === 'ballot') {
      postToPreview({ type: 'show-ballot' });
    } else {
      postToPreview({ type: 'show-landing' });
    }
  }

  function schedulePreviewPush() {
    window.clearTimeout(pushTimer);
    pushTimer = window.setTimeout(pushPreviewNow, 160);
  }

  function bindLiveFields(root) {
    if (!root) return;
    root.querySelectorAll('input, textarea, select').forEach((el) => {
      ['input', 'change', 'keyup'].forEach((evt) => {
        el.addEventListener(evt, () => {
          markDirty();
          schedulePreviewPush();
        });
      });
    });
  }

  function syncHexFromColor(colorEl, hexEl) {
    if (!colorEl || !hexEl) return;
    hexEl.value = colorEl.value;
  }

  function syncColorFromHex(hexEl, colorEl) {
    if (!hexEl || !colorEl) return;
    const v = hexEl.value.trim();
    if (/^#[0-9A-Fa-f]{6}$/.test(v)) {
      colorEl.value = v;
      markDirty();
      schedulePreviewPush();
    }
  }

  function wireColorSync() {
    if (bgInput && bgHex) {
      bgInput.addEventListener('input', () => {
        syncHexFromColor(bgInput, bgHex);
        markDirty();
        schedulePreviewPush();
      });
      bgHex.addEventListener('change', () => syncColorFromHex(bgHex, bgInput));
      bgHex.addEventListener('blur', () => syncColorFromHex(bgHex, bgInput));
    }
    if (textInput && textHex) {
      textInput.addEventListener('input', () => {
        syncHexFromColor(textInput, textHex);
        markDirty();
        schedulePreviewPush();
      });
      textHex.addEventListener('change', () => syncColorFromHex(textHex, textInput));
      textHex.addEventListener('blur', () => syncColorFromHex(textHex, textInput));
    }
  }

  async function ajaxSave(form) {
    const fd = new FormData(form);
    fd.set('ajax', '1');
    const res = await fetch(form.getAttribute('action') || window.location.pathname + window.location.search, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
    });
    const text = await res.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch (_) {
      throw new Error('Could not read server response.');
    }
    if (!res.ok || data.status !== 'success') {
      throw new Error(data.message || 'Save failed.');
    }
    return data;
  }

  function wireSave(form) {
    if (!form) return;
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = form.querySelector('button[type="submit"]');
      if (btn) btn.disabled = true;
      setSaveStatus('Saving…', 'muted');
      try {
        const data = await ajaxSave(form);
        markSaved();
        showToast(data.message || 'Saved.', 'success');
        if (data.appearance && currentLogo) {
          draftLogoUrl = data.appearance.adminLogoSrc || draftLogoUrl;
          currentLogo.src = draftLogoUrl;
        }
        pushPreviewNow();
      } catch (err) {
        console.error(err);
        setSaveStatus('Save failed', 'danger');
        showToast(err.message || 'Save failed.', 'danger');
      } finally {
        if (btn) btn.disabled = false;
      }
    });
  }

  function wirePresets() {
    if (!presetWrap) return;
    const presets = [
      { n: 'Classic Light', bg: '#ffffff', text: '#0f172a' },
      { n: 'Soft Cream', bg: '#fef7ec', text: '#1f2937' },
      { n: 'Cool Mint', bg: '#ecfdf5', text: '#064e3b' },
      { n: 'Sky', bg: '#eff6ff', text: '#0c4a6e' },
      { n: 'High Contrast', bg: '#ffffff', text: '#000000' },
      { n: 'Dark Mode', bg: '#0f172a', text: '#e2e8f0' },
      { n: 'Sunset', bg: '#fff1f2', text: '#9f1239' },
      { n: 'Sepia', bg: '#f5ecd9', text: '#3f2d18' },
    ];

    presets.forEach((p) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'preset-btn';
      btn.title = p.n + ' — bg ' + p.bg + ', text ' + p.text;
      const sw = document.createElement('span');
      sw.className = 'swatches';
      [p.bg, p.text].forEach((c) => {
        const dot = document.createElement('span');
        dot.style.background = c;
        sw.appendChild(dot);
      });
      const lab = document.createElement('span');
      lab.textContent = p.n;
      btn.append(sw, lab);
      btn.addEventListener('click', () => {
        if (bgInput) bgInput.value = p.bg;
        if (textInput) textInput.value = p.text;
        syncHexFromColor(bgInput, bgHex);
        syncHexFromColor(textInput, textHex);
        presetWrap.querySelectorAll('.preset-btn.active').forEach((el) => el.classList.remove('active'));
        btn.classList.add('active');
        markDirty();
        schedulePreviewPush();
      });
      presetWrap.appendChild(btn);
    });
  }

  function setPreviewToolbarActive(id) {
    ['vpcPreviewLanding', 'vpcPreviewWelcome', 'vpcPreviewBallot'].forEach((btnId) => {
      const btn = document.getElementById(btnId);
      if (!btn) return;
      btn.classList.toggle('active', btnId === id);
    });
  }

  function wirePreviewToolbar() {
    document.getElementById('vpcPreviewWelcome')?.addEventListener('click', () => {
      previewMode = 'welcome';
      setPreviewToolbarActive('vpcPreviewWelcome');
      postToPreview({ type: 'show-intro' });
    });
    document.getElementById('vpcPreviewLanding')?.addEventListener('click', () => {
      previewMode = 'landing';
      setPreviewToolbarActive('vpcPreviewLanding');
      postToPreview({ type: 'show-landing' });
    });
    document.getElementById('vpcPreviewBallot')?.addEventListener('click', () => {
      previewMode = 'ballot';
      setPreviewToolbarActive('vpcPreviewBallot');
      postToPreview({ type: 'show-ballot' });
      pushPreviewNow();
    });
    document.getElementById('vpcPreviewReload')?.addEventListener('click', () => {
      if (!previewFrame) return;
      previewReady = false;
      previewFrame.src = previewFrame.src;
    });
  }

  function wireFlowMap() {
    const jumps = {
      welcome: () => {
        document.getElementById('tab-content-btn')?.click();
        const collapse = document.getElementById('vpcCollapseWelcome');
        if (collapse && !collapse.classList.contains('show')) {
          bootstrap.Collapse.getOrCreateInstance(collapse, { toggle: false }).show();
        }
        document.getElementById('intro_title')?.focus();
        previewMode = 'welcome';
        setPreviewToolbarActive('vpcPreviewWelcome');
        postToPreview({ type: 'show-intro' });
      },
      landing: () => {
        document.getElementById('tab-content-btn')?.click();
        const collapse = document.getElementById('vpcCollapseLanding');
        if (collapse && !collapse.classList.contains('show')) {
          bootstrap.Collapse.getOrCreateInstance(collapse, { toggle: false }).show();
        }
        document.getElementById('how_to_title')?.focus();
        previewMode = 'landing';
        setPreviewToolbarActive('vpcPreviewLanding');
        postToPreview({ type: 'show-landing' });
      },
    };

    document.querySelectorAll('.vpc-flow-jump').forEach((btn) => {
      btn.addEventListener('click', () => {
        const key = btn.getAttribute('data-flow-jump');
        if (jumps[key]) jumps[key]();
      });
    });

    document.querySelectorAll('.vpc-flow-item.is-editable').forEach((item) => {
      item.addEventListener('mouseenter', () => item.classList.add('is-active'));
      item.addEventListener('mouseleave', () => item.classList.remove('is-active'));
    });
  }

  document.getElementById('vpcUseSuggestedLead')?.addEventListener('click', () => {
    const lead = document.getElementById('how_to_lead');
    if (!lead || !cfg.suggestedLead) return;
    lead.value = cfg.suggestedLead;
    markDirty();
    schedulePreviewPush();
    showToast('Suggested intro applied — save to keep.', 'info');
  });

  window.addEventListener('message', (event) => {
    if (event.origin !== window.location.origin) return;
    if (!event.data || event.data.source !== PREVIEW_SOURCE) return;
    if (event.data.type === 'preview-ready') {
      previewReady = true;
      pushPreviewNow();
    }
  });

  window.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
      e.preventDefault();
      const form = activeForm();
      if (form) form.requestSubmit();
    }
  });

  window.addEventListener('beforeunload', (e) => {
    if (!dirty) return;
    e.preventDefault();
    e.returnValue = '';
  });

  if (logoFile && currentLogo) {
    logoFile.addEventListener('change', () => {
      const file = logoFile.files && logoFile.files[0];
      if (!file) return;
      const reader = new FileReader();
      reader.onload = (ev) => {
        draftLogoUrl = ev.target?.result || draftLogoUrl;
        currentLogo.src = draftLogoUrl;
        markDirty();
        schedulePreviewPush();
      };
      reader.readAsDataURL(file);
    });
  }

  bindLiveFields(contentForm);
  bindLiveFields(appearanceForm);
  wireColorSync();
  wirePresets();
  wireSave(contentForm);
  wireSave(appearanceForm);
  wirePreviewToolbar();
  wireFlowMap();

  if (previewFrame) {
    previewFrame.addEventListener('load', () => {
      window.setTimeout(() => {
        if (!previewReady) pushPreviewNow();
      }, 500);
    });
  }

  setSaveStatus('Live preview active', 'muted');
})();
