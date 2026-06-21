/**
 * Prevent stacked Bootstrap modals / duplicate backdrops on voter pages.
 */
(function (global) {
  function dismissOrphanBackdrops() {
    const openCount = document.querySelectorAll('.modal.show').length;
    const backdrops = document.querySelectorAll('.modal-backdrop');
    if (backdrops.length > openCount) {
      backdrops.forEach((el, idx) => {
        if (idx >= openCount) el.remove();
      });
    }
    if (openCount === 0) {
      document.body.classList.remove('modal-open');
      document.body.style.removeProperty('overflow');
      document.body.style.removeProperty('padding-right');
      backdrops.forEach((el) => el.remove());
    }
  }

  function hideAllVoterModals(exceptId = null) {
    document.querySelectorAll('.modal').forEach((el) => {
      if (exceptId && el.id === exceptId) return;
      const inst = global.bootstrap?.Modal?.getInstance(el);
      if (inst) {
        try {
          inst.hide();
        } catch (e) {
          /* ignore */
        }
      }
      el.classList.remove('show');
      el.setAttribute('aria-hidden', 'true');
      el.removeAttribute('aria-modal');
      el.style.display = '';
    });
    dismissOrphanBackdrops();
  }

  function showVoterModal(modalEl) {
    if (!modalEl || !global.bootstrap?.Modal) return null;
    hideAllVoterModals(modalEl.id || null);
    const inst = global.bootstrap.Modal.getOrCreateInstance(modalEl);
    inst.show();
    setTimeout(dismissOrphanBackdrops, 80);
    return inst;
  }

  document.addEventListener('hidden.bs.modal', () => {
    setTimeout(dismissOrphanBackdrops, 50);
  });

  document.addEventListener('show.bs.modal', (event) => {
    const target = event.target;
    if (!target?.classList?.contains('modal')) return;
    document.querySelectorAll('.modal.show').forEach((el) => {
      if (el === target) return;
      const inst = global.bootstrap?.Modal?.getInstance(el);
      if (inst) {
        try {
          inst.hide();
        } catch (e) {
          /* ignore */
        }
      }
    });
    setTimeout(dismissOrphanBackdrops, 100);
  });

  global.VoterModalStack = {
    dismissOrphanBackdrops,
    hideAllVoterModals,
    showVoterModal,
  };
})(window);
