function showToast(message, type = "info", delay = 3000) {
  const toastEl = document.getElementById("validationToast");
  if (!toastEl) {
    alert(message);
    return;
  }
  const body = toastEl.querySelector(".toast-body");
  if (body) {
    body.textContent = message;
  }
  const closeBtn = toastEl.querySelector(".btn-close");
  if (closeBtn) {
    closeBtn.remove();
  }
  toastEl.className = `toast text-bg-${type}`;
  const existing = bootstrap.Toast.getInstance(toastEl);
  if (existing) {
    existing.dispose();
  }
  const bsToast = new bootstrap.Toast(toastEl, {
    autohide: true,
    delay,
  });
  bsToast.show();
}

window.showToast = showToast;