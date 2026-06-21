/**
 * Voter-facing controller for the "View Business" modal.
 *
 * Loads photos/videos for a selected establishment (choice) from
 * get_choice_media.php and renders them inside a Bootstrap carousel.
 *
 * Exposed entry point:
 *   ChoiceMediaViewer.open(choiceId, choiceName)
 */
(function (global) {
  'use strict';

  const ENDPOINT = 'get_choice_media.php';

  const els = {};
  let carouselInstance = null;
  let activeChoiceId = null;
  let captionMap = new Map();
  let captionTimer = null;

  function escapeHtml(str) {
    return String(str ?? '')
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function cacheEls() {
    if (els.modal) return true;
    els.modal       = document.getElementById('choiceMediaModal');
    if (!els.modal) return false;
    els.label       = document.getElementById('choiceMediaModalLabel');
    els.loading     = document.getElementById('choiceMediaLoading');
    els.empty       = document.getElementById('choiceMediaEmpty');
    els.error       = document.getElementById('choiceMediaError');
    els.wrapper     = document.getElementById('choiceMediaCarouselWrapper');
    els.carousel    = document.getElementById('choiceMediaCarousel');
    els.inner       = document.getElementById('choiceMediaCarouselInner');
    els.indicators  = document.getElementById('choiceMediaIndicators');
    els.caption     = document.getElementById('choiceMediaCaption');
    els.counter     = document.getElementById('choiceMediaCounter');
    return true;
  }

  function show(el) { el && el.classList.remove('d-none'); }
  function hide(el) { el && el.classList.add('d-none'); }

  function hideStatePanels() {
    [els.loading, els.empty, els.error, els.wrapper].forEach(el => hide(el));
  }

  function showOnly(el) {
    hideStatePanels();
    show(el);
  }

  function resetUI() {
    pauseAllVideos();
    captionMap.clear();
    if (els.inner) els.inner.innerHTML = '';
    if (els.indicators) els.indicators.innerHTML = '';
    if (els.caption) els.caption.textContent = '';
    if (els.counter) els.counter.textContent = '';
    if (els.error) els.error.textContent = '';
    hide(els.empty);
    hide(els.error);
    hide(els.wrapper);
    show(els.loading);
  }

  function pauseAllVideos() {
    if (!els.inner) return;
    els.inner.querySelectorAll('video').forEach(v => {
      try { v.pause(); } catch (_) {}
    });
  }

  function renderItems(items) {
    if (!els.inner || !els.indicators) return;
    els.inner.innerHTML = '';
    els.indicators.innerHTML = '';
    captionMap.clear();

    items.forEach((item, idx) => {
      const active = idx === 0 ? ' active' : '';
      const slide = document.createElement('div');
      slide.className = 'carousel-item' + active;
      slide.dataset.mediaIndex = String(idx);

      let inner = '';
      if (item.media_type === 'video') {
        inner = `<video src="${escapeHtml(item.url)}" controls preload="metadata" playsinline></video>`;
      } else {
        inner =
          `<img src="${escapeHtml(item.url)}" ` +
          `alt="${escapeHtml(item.caption || 'Business photo')}" loading="lazy">`;
      }
      slide.innerHTML = `<div class="d-flex align-items-center justify-content-center">${inner}</div>`;
      els.inner.appendChild(slide);

      const indicator = document.createElement('button');
      indicator.type = 'button';
      indicator.setAttribute('data-bs-target', '#choiceMediaCarousel');
      indicator.setAttribute('data-bs-slide-to', String(idx));
      indicator.setAttribute('aria-label', 'Slide ' + (idx + 1));
      if (idx === 0) {
        indicator.classList.add('active');
        indicator.setAttribute('aria-current', 'true');
      }
      els.indicators.appendChild(indicator);

      captionMap.set(idx, item.caption || '');
    });

    updateCaptionForActive(0, items.length);
  }

  function updateCaptionForActive(idx, total) {
    if (!els.caption) return;
    const text = captionMap.get(idx) || '';
    els.caption.textContent = text;
    if (els.counter && total > 0) {
      els.counter.textContent = `${idx + 1} of ${total}`;
    }
  }

  function initCarousel() {
    if (!global.bootstrap || !els.carousel) return;
    try {
      carouselInstance && carouselInstance.dispose();
    } catch (_) {}
    carouselInstance = new global.bootstrap.Carousel(els.carousel, {
      interval: false, // never auto-advance — voter is browsing
      ride: false,
      pause: true,
      wrap: true,
    });
    els.carousel.addEventListener('slide.bs.carousel', onSlide);
    els.carousel.addEventListener('slid.bs.carousel', onSlid);
  }

  function onSlide() {
    pauseAllVideos();
  }
  function onSlid(ev) {
    const idx = (ev && typeof ev.to === 'number') ? ev.to : 0;
    const total = captionMap.size;
    updateCaptionForActive(idx, total);
  }

  async function loadAndShow(choiceId, choiceName) {
    if (!cacheEls() || !global.bootstrap) return;
    activeChoiceId = choiceId;
    if (els.label) {
      els.label.textContent = choiceName
        ? 'Preview — ' + choiceName
        : 'Business Preview';
    }
    resetUI();

    const modal = global.bootstrap.Modal.getOrCreateInstance(els.modal);
    modal.show();

    try {
      const res = await fetch(
        ENDPOINT + '?choice_id=' + encodeURIComponent(choiceId),
        { credentials: 'same-origin' }
      );
      const result = await res.json().catch(() => ({ status: 'error' }));
      if (activeChoiceId !== choiceId) return; // another request superseded us

      hide(els.loading);
      if (result.status !== 'success') {
        if (els.error) els.error.textContent = result.message || 'Could not load media.';
        show(els.error);
        return;
      }
      const items = Array.isArray(result.data) ? result.data : [];
      if (items.length === 0) {
        show(els.empty);
        return;
      }
      renderItems(items);
      show(els.wrapper);
      initCarousel();
    } catch (err) {
      console.error('choice media load failed', err);
      hide(els.loading);
      if (els.error) els.error.textContent = 'Could not load media. Please try again.';
      show(els.error);
    }
  }

  function ensureHidesPauseVideos() {
    if (!cacheEls()) return;
    els.modal.addEventListener('hidden.bs.modal', () => {
      pauseAllVideos();
      activeChoiceId = null;
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', ensureHidesPauseVideos, { once: true });
  } else {
    ensureHidesPauseVideos();
  }

  global.ChoiceMediaViewer = {
    open: loadAndShow,
  };
})(window);
