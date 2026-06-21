/**
 * Styled confirm dialog for voter pages (replaces window.confirm when modal is present).
 */
function showVoterConfirm(options = {}) {
  const {
    title = 'Please confirm',
    message = '',
    confirmText = 'Confirm',
    cancelText = 'Cancel',
    confirmClass = 'btn-primary',
  } = options;

  const modalEl = document.getElementById('voterConfirmModal');
  if (!modalEl || typeof bootstrap === 'undefined') {
    const fallback = message || title;
    return Promise.resolve(window.confirm(fallback));
  }

  const titleEl = document.getElementById('voterConfirmTitle');
  const messageEl = document.getElementById('voterConfirmMessage');
  const confirmBtn = document.getElementById('voterConfirmOkBtn');
  const cancelBtn = modalEl.querySelector('[data-voter-confirm-cancel]');

  if (titleEl) titleEl.textContent = title;
  if (messageEl) messageEl.textContent = message;
  if (confirmBtn) {
    confirmBtn.textContent = confirmText;
    confirmBtn.className = `btn ${confirmClass}`;
  }
  if (cancelBtn) cancelBtn.textContent = cancelText;

  return new Promise((resolve) => {
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    let settled = false;

    const finish = (value) => {
      if (settled) return;
      settled = true;
      confirmBtn?.removeEventListener('click', onConfirm);
      modalEl.removeEventListener('hidden.bs.modal', onHidden);
      resolve(value);
    };

    const onConfirm = () => {
      modal.hide();
      finish(true);
    };

    const onHidden = () => {
      finish(false);
    };

    confirmBtn?.addEventListener('click', onConfirm, { once: true });
    modalEl.addEventListener('hidden.bs.modal', onHidden, { once: true });
    modal.show();
  });
}

/**
 * Highlight a summary row and expand its category accordion.
 */
function focusSummaryAttention(rowEl, collapseId) {
  if (!rowEl) return;
  document.querySelectorAll('.summary-row--attention').forEach((el) => {
    el.classList.remove('summary-row--attention');
  });
  rowEl.classList.add('summary-row--attention');
  const collapseEl = collapseId ? document.getElementById(collapseId) : null;
  if (collapseEl && !collapseEl.classList.contains('show') && typeof bootstrap !== 'undefined') {
    bootstrap.Collapse.getOrCreateInstance(collapseEl).show();
  }
  rowEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  const editBtn = rowEl.querySelector('.btn-voter-edit');
  editBtn?.focus({ preventScroll: true });
}

window.showVoterConfirm = showVoterConfirm;
window.focusSummaryAttention = focusSummaryAttention;
