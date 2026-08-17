/**
 * Proof-of-purchase image upload for voter questions.
 */

export const PROOF_MAX_FILES = 5;

export function buildProofUploadHtml(questionId, proofs = [], labels = null) {
  const L = labels || {};
  const label = escapeProofHtml(L.proof_label || 'Proof of purchase (optional)');
  const hint = escapeProofHtml(
    L.proof_hint ||
      'Add a photo if you have one. You can still vote without it.'
  );
  const addLabel = escapeProofHtml(L.proof_add_label || 'Add photo');
  const qid = Number(questionId) || 0;

  const thumbs = (proofs || [])
    .map(
      (p) =>
        `<div class="vote-proof-thumb" data-proof-id="${p.proof_id}" data-is-final="${p.is_final ? '1' : '0'}">` +
        `<img src="${escapeProofHtml(p.url)}" alt="Proof of purchase" loading="lazy">` +
        (p.is_final
          ? ''
          : `<button type="button" class="vote-proof-remove" aria-label="Remove proof" title="Remove">&times;</button>`) +
        `</div>`
    )
    .join('');

  const atMax = (proofs || []).length >= PROOF_MAX_FILES;

  return (
    `<div class="vote-proof-section mt-3" data-question-id="${qid}">` +
    `<label class="form-label fw-semibold d-block">${label}</label>` +
    `<p class="text-muted small mb-2">${hint}</p>` +
    `<div class="vote-proof-gallery">${thumbs}</div>` +
    `<div class="vote-proof-actions mt-2">` +
    `<label class="btn btn-outline-primary btn-sm vote-proof-add${atMax ? ' disabled' : ''}"` +
    `${atMax ? ' aria-disabled="true"' : ''}>` +
    `<i class="fa-solid fa-camera me-1" aria-hidden="true"></i>${addLabel}` +
    `<input type="file" class="vote-proof-input visually-hidden" accept="image/png,image/jpeg,image/webp,image/gif"` +
    ` data-question-id="${qid}"${atMax ? ' disabled' : ''}>` +
    `</label>` +
    `<span class="vote-proof-status small text-muted ms-2" aria-live="polite"></span>` +
    `</div></div>`
  );
}

function escapeProofHtml(str = '') {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

export function getProofCount(block) {
  if (!block) return 0;
  return block.querySelectorAll('.vote-proof-thumb').length;
}

export function questionHasValidProof(block) {
  return getProofCount(block) >= 1;
}

export function allProofSectionsValid(scope = document) {
  return true;
}

export function markInvalidProofSections(scope = document) {
  (scope || document).querySelectorAll('.vote-proof-section').forEach((section) => {
    section.classList.remove('is-invalid');
  });
  return true;
}

export async function uploadProofFile(questionId, file) {
  const fd = new FormData();
  fd.append('question_id', String(questionId));
  fd.append('proof', file);
  const res = await fetch('upload_vote_proof.php', {
    method: 'POST',
    body: fd,
    credentials: 'same-origin',
  });
  const data = await res.json();
  if (data.status !== 'success' || !data.proof) {
    throw new Error(data.message || 'Upload failed.');
  }
  return data.proof;
}

export async function deleteProofFile(proofId) {
  const res = await fetch('delete_vote_proof.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ proof_id: proofId }),
    credentials: 'same-origin',
  });
  const data = await res.json();
  if (data.status !== 'success') {
    throw new Error(data.message || 'Could not remove proof.');
  }
}

function appendProofThumb(gallery, proof, isFinal = false) {
  if (!gallery) return;
  const wrap = document.createElement('div');
  wrap.className = 'vote-proof-thumb';
  wrap.dataset.proofId = String(proof.proof_id);
  wrap.dataset.isFinal = isFinal ? '1' : '0';
  wrap.innerHTML =
    `<img src="${proof.url}" alt="Proof of purchase" loading="lazy">` +
    (isFinal
      ? ''
      : `<button type="button" class="vote-proof-remove" aria-label="Remove proof" title="Remove">&times;</button>`);
  gallery.appendChild(wrap);
}

function refreshProofAddButton(section) {
  const gallery = section.querySelector('.vote-proof-gallery');
  const input = section.querySelector('.vote-proof-input');
  const addBtn = section.querySelector('.vote-proof-add');
  const count = gallery ? gallery.querySelectorAll('.vote-proof-thumb').length : 0;
  const atMax = count >= PROOF_MAX_FILES;
  if (input) input.disabled = atMax;
  if (addBtn) {
    addBtn.classList.toggle('disabled', atMax);
    if (atMax) addBtn.setAttribute('aria-disabled', 'true');
    else addBtn.removeAttribute('aria-disabled');
  }
}

export function bindProofUploadHandlers(formGroup, callbacks = {}) {
  const section = formGroup.querySelector('.vote-proof-section');
  if (!section || section.dataset.proofBound === '1') return;
  section.dataset.proofBound = '1';

  const statusEl = section.querySelector('.vote-proof-status');
  const setStatus = (msg) => {
    if (statusEl) statusEl.textContent = msg || '';
  };

  section.addEventListener('change', async (ev) => {
    const input = ev.target.closest('.vote-proof-input');
    if (!input || !section.contains(input)) return;
    const file = input.files && input.files[0];
    input.value = '';
    if (!file) return;

    const qid = parseInt(section.dataset.questionId || '0', 10);
    if (!qid) return;

    setStatus('Uploading…');
    try {
      const proof = await uploadProofFile(qid, file);
      appendProofThumb(section.querySelector('.vote-proof-gallery'), proof, false);
      refreshProofAddButton(section);
      section.classList.remove('is-invalid');
      setStatus('');
      callbacks.onChange?.();
    } catch (err) {
      setStatus('');
      callbacks.onError?.(err.message || 'Upload failed.');
    }
  });

  section.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('.vote-proof-remove');
    if (!btn || !section.contains(btn)) return;
    const thumb = btn.closest('.vote-proof-thumb');
    if (!thumb || thumb.dataset.isFinal === '1') return;
    const proofId = parseInt(thumb.dataset.proofId || '0', 10);
    if (!proofId) return;

    setStatus('Removing…');
    try {
      await deleteProofFile(proofId);
      thumb.remove();
      refreshProofAddButton(section);
      setStatus('');
      callbacks.onChange?.();
    } catch (err) {
      setStatus('');
      callbacks.onError?.(err.message || 'Could not remove proof.');
    }
  });
}

export function setProofSectionEnabled(formGroup, enabled) {
  const section = formGroup?.querySelector('.vote-proof-section');
  if (!section) return;
  section.querySelectorAll('.vote-proof-input, .vote-proof-add, .vote-proof-remove').forEach((el) => {
    el.disabled = !enabled;
  });
  if (!enabled) {
    section.classList.add('vote-proof-section--disabled');
  } else {
    section.classList.remove('vote-proof-section--disabled');
  }
}
