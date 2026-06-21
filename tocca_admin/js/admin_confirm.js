/**
 * Bootstrap confirmation dialog (replaces window.confirm).
 */
(function () {
  let pendingResolve = null;
  let settled = false;

  function getModal() {
    return document.getElementById('adminConfirmModal');
  }

  function settle(result) {
    if (settled || typeof pendingResolve !== 'function') {
      return;
    }
    settled = true;
    const resolve = pendingResolve;
    pendingResolve = null;
    resolve(!!result);
  }

  function init() {
    const modalEl = getModal();
    if (!modalEl || modalEl.dataset.bound === '1') {
      return;
    }
    modalEl.dataset.bound = '1';

    const okBtn = document.getElementById('adminConfirmModalOk');
    const cancelBtn = document.getElementById('adminConfirmModalCancel');

    okBtn?.addEventListener('click', () => {
      settle(true);
      bootstrap.Modal.getInstance(modalEl)?.hide();
    });

    cancelBtn?.addEventListener('click', () => settle(false));

    modalEl.addEventListener('hidden.bs.modal', () => {
      settle(false);
    });
  }

  window.adminConfirm = function adminConfirm(options) {
    init();

    const modalEl = getModal();
    if (!modalEl) {
      return Promise.resolve(window.confirm(options?.message || 'Are you sure?'));
    }

    const titleEl = document.getElementById('adminConfirmModalTitle');
    const messageEl = document.getElementById('adminConfirmModalMessage');
    const okBtn = document.getElementById('adminConfirmModalOk');

    const title = options?.title !== undefined ? options.title : 'Please confirm';
    const message = options?.message || 'Are you sure?';
    const confirmLabel = options?.confirmLabel || 'Yes, continue';
    const confirmClass = options?.confirmClass || 'btn-primary';
    const headerEl = document.getElementById('adminConfirmModalHeader');

    if (titleEl) {
      titleEl.textContent = title || 'Please confirm';
    }
    if (headerEl) {
      const showHeader = !!title;
      headerEl.classList.toggle('d-none', !showHeader);
    }
    if (messageEl) {
      if (options?.html) {
        messageEl.innerHTML = options.html;
      } else {
        messageEl.textContent = message;
      }
    }
    if (okBtn) {
      okBtn.textContent = confirmLabel;
      okBtn.className = 'btn ' + confirmClass;
    }

    settled = false;
    return new Promise((resolve) => {
      pendingResolve = resolve;
      bootstrap.Modal.getOrCreateInstance(modalEl).show();
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
