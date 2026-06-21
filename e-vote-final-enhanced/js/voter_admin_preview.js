/**
 * Live preview bridge for admin Voter Portal settings (iframe + postMessage).
 */
(function () {
  'use strict';

  if (!window.TOCCA_ADMIN_PREVIEW) {
    return;
  }

  const PREVIEW_SOURCE = 'tocca-voter-portal-admin';

  function esc(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function mdToHtmlBasic(src) {
    let x = esc(src);
    x = x.replace(/\*\*(.+?)\*\*/gs, '<strong>$1</strong>');
    x = x.replace(/\*(.+?)\*/gs, '<em>$1</em>');
    const paras = x.split(/\n{2,}/).filter(Boolean);
    return paras.map((p) => '<p>' + p.replace(/\n/g, '<br>') + '</p>').join('');
  }

  function resolveLogoUrl(logo) {
    if (!logo) return '';
    if (/^data:/i.test(logo) || /^https?:\/\//i.test(logo)) return logo;
    let clean = String(logo).replace(/^\.\//, '');
    clean = clean.replace(/^\.\.\/e-vote-final-enhanced\//i, '');
    if (clean.startsWith('img/')) return clean;
    return 'img/' + clean.replace(/^img\//, '');
  }

  function applyAppearance(payload) {
    const logo = resolveLogoUrl(payload.headerLogo);
    if (typeof window.applyVoterAppearance === 'function') {
      window.applyVoterAppearance({
        bgColor: payload.bgColor,
        textColor: payload.textColor,
        headerLogo: logo,
      });
    }
    document.querySelectorAll('#headerLogo, #headerLogoBallot').forEach((img) => {
      if (img && logo) img.src = logo;
    });
  }

  function applyContent(copy) {
    if (!copy || typeof copy !== 'object') return;

    const introTitle = document.getElementById('introModalLabel');
    const introCopy = document.getElementById('introModalCopy');
    if (introTitle) introTitle.textContent = copy.intro_title || '';
    if (introCopy) introCopy.innerHTML = mdToHtmlBasic(copy.intro_body || '');

    const heroH1 = document.querySelector('.voter-hero h1');
    const heroP = document.querySelector('.voter-hero p');
    if (heroH1) heroH1.textContent = copy.how_to_title || '';
    if (heroP) heroP.textContent = copy.how_to_lead || '';

    const stepsOl = document.querySelector('.voter-instruction-steps');
    if (stepsOl && Array.isArray(copy.steps)) {
      stepsOl.innerHTML = copy.steps
        .map((step, idx) => {
          const title = esc(step.title || '');
          const body = esc(step.body || '');
          const dash = body ? ' — ' + body : '';
          return (
            '<li class="voter-instruction-step">'
            + '<span class="step-num" aria-hidden="true">' + (idx + 1) + '</span>'
            + '<div class="step-body"><strong>' + title + '</strong>' + dash + '</div>'
            + '</li>'
          );
        })
        .join('');
    }

    const note = document.querySelector('.voter-instruction-note');
    if (note) note.textContent = copy.footer_note || '';
  }

  function showIntroModal() {
    const el = document.getElementById('introModal');
    if (!el || typeof bootstrap === 'undefined') return;
    bootstrap.Modal.getOrCreateInstance(el).show();
  }

  function hideIntroModal() {
    const el = document.getElementById('introModal');
    if (!el || typeof bootstrap === 'undefined') return;
    const inst = bootstrap.Modal.getInstance(el);
    if (inst) inst.hide();
  }

  window.addEventListener('message', (event) => {
    if (event.origin !== window.location.origin) return;
    const data = event.data;
    if (!data || data.source !== PREVIEW_SOURCE) return;

    if (data.type === 'appearance') {
      applyAppearance(data.payload || {});
    } else if (data.type === 'content') {
      applyContent(data.payload || {});
    } else if (data.type === 'show-intro') {
      showIntroModal();
    } else if (data.type === 'hide-intro') {
      hideIntroModal();
    } else if (data.type === 'show-ballot') {
      hideIntroModal();
      showBallotView();
    } else if (data.type === 'show-landing') {
      hideIntroModal();
      showLandingView();
    }
  });

  function showBallotView() {
    const landing = document.getElementById('vpcPreviewLandingView');
    const ballot = document.getElementById('vpcPreviewBallotView');
    if (landing) {
      landing.classList.add('d-none');
      landing.setAttribute('aria-hidden', 'true');
    }
    if (ballot) {
      ballot.classList.remove('d-none');
      ballot.setAttribute('aria-hidden', 'false');
    }
    const logo = document.getElementById('headerLogo');
    const logoB = document.getElementById('headerLogoBallot');
    if (logo && logoB && logo.src) logoB.src = logo.src;
  }

  function showLandingView() {
    const landing = document.getElementById('vpcPreviewLandingView');
    const ballot = document.getElementById('vpcPreviewBallotView');
    if (ballot) {
      ballot.classList.add('d-none');
      ballot.setAttribute('aria-hidden', 'true');
    }
    if (landing) {
      landing.classList.remove('d-none');
      landing.setAttribute('aria-hidden', 'false');
    }
  }

  window.parent.postMessage({ source: PREVIEW_SOURCE, type: 'preview-ready' }, window.location.origin);
})();
