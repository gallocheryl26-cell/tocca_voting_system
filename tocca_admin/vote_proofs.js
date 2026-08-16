document.addEventListener('DOMContentLoaded', () => {
  const prefill = window.TOCCA_PROOF_PREFILL || {};
  const hasEvent = window.TOCCA_PROOF_HAS_EVENT === true;
  let appliedPrefill = false;

  const categoryEl = document.getElementById('filterCategory');
  const awardEl = document.getElementById('filterAward');
  const businessEl = document.getElementById('filterBusiness');
  const proofEl = document.getElementById('filterProof');
  const mobileEl = document.getElementById('filterMobile');
  const dateFromEl = document.getElementById('filterDateFrom');
  const dateToEl = document.getElementById('filterDateTo');
  const tbody = document.getElementById('proofsTableBody');
  const form = document.getElementById('proofFilterForm');
  const resetBtn = document.getElementById('filterResetBtn');

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function fillSelect(select, items, valueKey, labelKey, current, allLabel) {
    if (!select) return;
    const keep = current != null ? String(current) : '';
    select.innerHTML = `<option value="">${escapeHtml(allLabel)}</option>`;
    (items || []).forEach((item) => {
      const value = String(item[valueKey] ?? '');
      const opt = document.createElement('option');
      opt.value = value;
      opt.textContent = item[labelKey] || value;
      if (keep !== '' && keep === value) opt.selected = true;
      select.appendChild(opt);
    });
  }

  function currentParams() {
    const params = new URLSearchParams();
    const categoryId = categoryEl?.value || (prefill.category_id ? String(prefill.category_id) : '');
    const questionId = awardEl?.value || (prefill.question_id ? String(prefill.question_id) : '');
    const choiceId = businessEl?.value || (prefill.choice_id ? String(prefill.choice_id) : '');
    if (categoryId) params.set('category_id', categoryId);
    if (questionId) params.set('question_id', questionId);
    if (choiceId) params.set('choice_id', choiceId);
    if (proofEl?.value && proofEl.value !== 'all') params.set('proof', proofEl.value);
    if (mobileEl?.value.trim()) params.set('mobile', mobileEl.value.trim());
    if (prefill.voters_id) params.set('voters_id', String(prefill.voters_id));
    if (dateFromEl?.value) params.set('date_from', dateFromEl.value);
    if (dateToEl?.value) params.set('date_to', dateToEl.value);
    return params;
  }

  function applyPrefillOnce() {
    if (prefill.proof && proofEl) proofEl.value = prefill.proof;
    if (prefill.mobile && mobileEl) mobileEl.value = prefill.mobile;
  }

  function renderRows(rows) {
    if (!tbody) return;
    if (!rows.length) {
      tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">No matching votes.</td></tr>';
      return;
    }
    tbody.innerHTML = rows.map((row) => {
      const thumb = row.thumb_url
        ? `<img class="proof-thumb" src="${escapeHtml(row.thumb_url)}" alt="">`
        : '<span class="proof-thumb proof-thumb--empty" title="No photo"><i class="bi bi-image"></i></span>';
      const viewDisabled = Number(row.proof_count) < 1 ? ' disabled' : '';
      return `<tr>
        <td>${thumb}</td>
        <td>${escapeHtml(row.mobile_number)}<div class="small text-muted">ID ${row.voters_id}</div></td>
        <td>${escapeHtml(row.category_name)}</td>
        <td>${escapeHtml(row.question_name)}</td>
        <td>${escapeHtml(row.choice_name)}</td>
        <td>${Number(row.proof_count) || 0}</td>
        <td>${escapeHtml(row.vote_at)}</td>
        <td>
          <button type="button" class="btn btn-sm btn-outline-primary view-proofs-btn"${viewDisabled}
            data-voters-id="${row.voters_id}" data-question-id="${row.question_id}">
            View
          </button>
        </td>
      </tr>`;
    }).join('');
  }

  function loadList() {
    if (!hasEvent) {
      if (tbody) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-danger">No active event. Activate one under File Maintenance → Events.</td></tr>';
      }
      return;
    }
    if (tbody) {
      tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">Loading…</td></tr>';
    }
    fetch('vote_proofs_api.php?' + currentParams().toString(), { credentials: 'same-origin' })
      .then((res) => res.json())
      .then((data) => {
        if (data.status !== 'success') {
          throw new Error(data.message || 'Failed to load.');
        }
        fillSelect(categoryEl, data.filters?.categories || [], 'category_id', 'category_name', categoryEl?.value || prefill.category_id, 'All categories');
        fillSelect(awardEl, data.filters?.awards || [], 'question_id', 'question_name', awardEl?.value || prefill.question_id, 'All awards');
        fillSelect(businessEl, data.filters?.businesses || [], 'choice_id', 'choice_name', businessEl?.value || prefill.choice_id, 'All businesses');
        if (!appliedPrefill) {
          if (prefill.category_id && categoryEl) categoryEl.value = String(prefill.category_id);
          if (prefill.question_id && awardEl) awardEl.value = String(prefill.question_id);
          if (prefill.choice_id && businessEl) businessEl.value = String(prefill.choice_id);
          appliedPrefill = true;
          prefill.category_id = 0;
          prefill.question_id = 0;
          prefill.choice_id = 0;
        }
        document.getElementById('statShown').textContent = String(data.stats?.shown ?? 0);
        document.getElementById('statWith').textContent = String(data.stats?.with_proof ?? 0);
        document.getElementById('statWithout').textContent = String(data.stats?.without_proof ?? 0);
        renderRows(data.rows || []);
      })
      .catch((err) => {
        if (tbody) {
          tbody.innerHTML = `<tr><td colspan="8" class="text-center text-danger">${escapeHtml(err.message || 'Failed to load.')}</td></tr>`;
        }
      });
  }

  function openLightbox(votersId, questionId) {
    const captionEl = document.getElementById('proofLightboxCaption');
    const filesEl = document.getElementById('proofLightboxFiles');
    if (captionEl) captionEl.textContent = 'Loading…';
    if (filesEl) filesEl.innerHTML = '';
    const modalEl = document.getElementById('proofLightbox');
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();

    const params = new URLSearchParams({
      action: 'files',
      voters_id: String(votersId),
      question_id: String(questionId),
    });
    fetch('vote_proofs_api.php?' + params.toString(), { credentials: 'same-origin' })
      .then((res) => res.json())
      .then((data) => {
        if (data.status !== 'success') {
          throw new Error(data.message || 'Could not open proofs.');
        }
        if (captionEl) captionEl.textContent = data.caption || '';
        const files = data.files || [];
        if (!files.length) {
          filesEl.innerHTML = '<p class="text-muted mb-0">No proof photos for this vote.</p>';
          return;
        }
        filesEl.innerHTML = files.map((file) => (
          `<figure class="mb-4">
            <img class="proof-lightbox-img d-block mx-auto" src="${escapeHtml(file.url)}" alt="Proof of purchase">
            <figcaption class="small text-muted text-center mt-2">${escapeHtml(file.caption || '')}</figcaption>
          </figure>`
        )).join('');
      })
      .catch((err) => {
        if (captionEl) captionEl.textContent = err.message || 'Could not open proofs.';
      });
  }

  applyPrefillOnce();

  form?.addEventListener('submit', (e) => {
    e.preventDefault();
    loadList();
  });

  categoryEl?.addEventListener('change', () => {
    if (awardEl) awardEl.value = '';
    if (businessEl) businessEl.value = '';
    loadList();
  });
  awardEl?.addEventListener('change', () => {
    if (businessEl) businessEl.value = '';
    loadList();
  });

  resetBtn?.addEventListener('click', () => {
    prefill.voters_id = 0;
    prefill.category_id = 0;
    prefill.question_id = 0;
    prefill.choice_id = 0;
    if (categoryEl) categoryEl.value = '';
    if (awardEl) awardEl.value = '';
    if (businessEl) businessEl.value = '';
    if (proofEl) proofEl.value = 'all';
    if (mobileEl) mobileEl.value = '';
    if (dateFromEl) dateFromEl.value = '';
    if (dateToEl) dateToEl.value = '';
    loadList();
  });

  tbody?.addEventListener('click', (e) => {
    const btn = e.target.closest('.view-proofs-btn');
    if (!btn || btn.disabled) return;
    openLightbox(btn.dataset.votersId, btn.dataset.questionId);
  });

  loadList();
});
