(() => {
  const tableBody = document.querySelector('#typesTable tbody');
  const typesTableWrapper = document.getElementById('typesTableWrapper');
  const typesLoadingRow = document.getElementById('typesLoadingRow');
  const typesEmptyState = document.getElementById('typesEmptyState');
  const featureDisabledAlert = document.getElementById('featureDisabledAlert');
  const typesCard = document.getElementById('typesCard');
  const addTypeBtn = document.getElementById('addTypeBtn');

  // Modal + form
  const typeForm = document.getElementById('typeForm');
  const typeNameInput = document.getElementById('typeName');
  const typeNameFeedback = document.getElementById('typeNameFeedback');
  const modalElement = document.getElementById('typeModal');
  const modalTitle = document.getElementById('typeModalLabel');

  // Awards area
  const awardsListEl = document.getElementById('awardsList');
  const awardsLoadingState = document.getElementById('awardsLoadingState');
  const selectAllAwardsBtn = document.getElementById('selectAllAwardsBtn');
  const clearAllAwardsBtn = document.getElementById('clearAllAwardsBtn');
  const awardsFeedback = document.getElementById('awardsFeedback');

  // Toast
  const toastEl = document.getElementById('typesToast');
  const toastBody = document.getElementById('typesToastBody');

  /* ===========================
   * Utilities / API
   * =========================== */
  async function api(action, payload = {}) {
    const res = await fetch('establishment_type.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action, ...payload }),
    });
    return res.json();
  }

  function getBootstrapModal() {
    if (!modalElement || typeof bootstrap === 'undefined' || !bootstrap.Modal) return null;
    return bootstrap.Modal.getOrCreateInstance(modalElement);
  }
  function getBootstrapToast() {
    if (!toastEl || typeof bootstrap === 'undefined' || !bootstrap.Toast) return null;
    return bootstrap.Toast.getOrCreateInstance(toastEl);
  }
  function showToast(message, variant = 'success') {
    const toastInstance = getBootstrapToast();
    if (!toastInstance || !toastEl || !toastBody) return;
    toastEl.classList.remove('text-bg-success','text-bg-danger','text-bg-warning','text-bg-info');
    toastEl.classList.add(
      {success:'text-bg-success',danger:'text-bg-danger',warning:'text-bg-warning',info:'text-bg-info'}[variant] || 'text-bg-success'
    );
    toastBody.textContent = message;
    toastInstance.show();
  }
  function escapeHtml(v){const d=document.createElement('div');d.textContent=v??'';return d.innerHTML;}

  let awardsLoaded = false;
  let awardsGroups = [];
  let featureEnabled = true;

  function setFeatureDisabled(message) {
    featureEnabled = false;
    if (featureDisabledAlert) {
      featureDisabledAlert.textContent = message || 'Business categories are not available right now.';
      featureDisabledAlert.classList.add('show');
    }
    typesCard?.classList.add('d-none');
    addTypeBtn?.setAttribute('disabled','disabled');
  }
  function clearFeatureDisabled() {
    featureEnabled = true;
    featureDisabledAlert?.classList.remove('show');
    typesCard?.classList.remove('d-none');
    addTypeBtn?.removeAttribute('disabled');
  }

  /* ===========================
   * Awards rendering + validate
   * =========================== */
  function collectSelectedAwardIds() {
    if (!awardsListEl) return [];
    const ids = [];
    awardsListEl.querySelectorAll('input[type="checkbox"]').forEach(cb => {
      if (cb.checked) {
        const id = Number(cb.dataset.questionId);
        if (id > 0) ids.push(id);
      }
    });
    return ids;
  }
  function validateAtLeastOneAwardSelected() {
    const hasAny = collectSelectedAwardIds().length > 0;
    if (!hasAny) {
      if (awardsFeedback) {
        awardsFeedback.style.display = 'block';
        awardsFeedback.textContent = 'Please select at least one award.';
      }
      awardsListEl?.classList.add('is-invalid-awards');
      awardsListEl?.setAttribute('aria-invalid','true');
      awardsListEl?.scrollIntoView({behavior:'smooth', block:'center'});
    } else {
      if (awardsFeedback) awardsFeedback.style.display = 'none';
      awardsListEl?.classList.remove('is-invalid-awards');
      awardsListEl?.removeAttribute('aria-invalid');
    }
    return hasAny;
  }
  // Live validation
  awardsListEl?.addEventListener('change', e => {
    if (e.target && e.target.matches('input[type="checkbox"]')) validateAtLeastOneAwardSelected();
  });
  selectAllAwardsBtn?.addEventListener('click', () => {
    if (!awardsListEl) return;
    awardsListEl.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = true);
    validateAtLeastOneAwardSelected();
  });
  clearAllAwardsBtn?.addEventListener('click', () => {
    if (!awardsListEl) return;
    awardsListEl.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = false);
    validateAtLeastOneAwardSelected();
  });
  if (awardsListEl && awardsFeedback) awardsListEl.setAttribute('aria-describedby','awardsFeedback');

  function renderAwards(groups, selectedIds = []) {
    if (!awardsListEl) return;
    awardsListEl.innerHTML = '';
    awardsFeedback?.style.setProperty('display','none');
    awardsListEl?.classList.remove('is-invalid-awards');

    const selectedSet = new Set(selectedIds.map(Number));

    if (!groups.length) {
      const empty = document.createElement('div');
      empty.className = 'text-center empty-state py-4';
      empty.innerHTML = '<i class="bi bi-trophy mb-2 fs-3 d-block"></i>No awards are currently available.';
      awardsListEl.appendChild(empty);
      selectAllAwardsBtn?.setAttribute('disabled','disabled');
      clearAllAwardsBtn?.setAttribute('disabled','disabled');
      return;
    }
    selectAllAwardsBtn?.removeAttribute('disabled');
    clearAllAwardsBtn?.removeAttribute('disabled');

    groups.forEach((group, idx) => {
      const list = Array.isArray(group.awards) ? group.awards : [];
      if (!list.length) return;

      const heading = document.createElement('div');
      heading.className = 'category-heading';
      heading.textContent = group.category_name || 'Uncategorized';
      if (idx > 0) heading.classList.add('mt-4');
      awardsListEl.appendChild(heading);

      list.forEach(award => {
        const row = document.createElement('div');
        row.className = 'award-row d-flex align-items-center justify-content-between py-2';

        const text = document.createElement('div');
        text.className = 'flex-grow-1 pe-3';

        const name = document.createElement('div');
        name.className = 'award-name fw-semibold';
        name.textContent = award.question_name || 'Untitled Award';
        text.appendChild(name);

        const right = document.createElement('div');
        right.className = 'flex-shrink-0 ps-2';

        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.className = 'form-check-input m-0';
        checkbox.id = `award-${award.question_id}`;
        checkbox.dataset.questionId = String(award.question_id);
        checkbox.checked = selectedSet.has(Number(award.question_id));

        const label = document.createElement('label');
        label.className = 'stretched-label';
        label.htmlFor = checkbox.id;

        right.appendChild(checkbox);
        row.appendChild(text);
        row.appendChild(right);
        row.appendChild(label);

        awardsListEl.appendChild(row);
      });
    });
  }

  async function fetchAwards() {
    try {
      const data = await api('awards');
      if (data.featureEnabled === false || data.status === 'disabled') { setFeatureDisabled(data.message); return []; }
      if (data.status !== 'success') throw new Error(data.message || 'Unable to load awards.');
      const groups = data.data?.groups;
      return Array.isArray(groups) ? groups : [];
    } catch (e) {
      console.error('Failed to fetch awards', e);
      showToast('Failed to load awards list.','danger');
      return [];
    }
  }
  async function ensureAwardsLoaded() {
    if (awardsLoaded) return;
    if (awardsLoadingState) awardsLoadingState.style.display = 'flex';
    awardsGroups = await fetchAwards();
    awardsLoaded = true;
    if (awardsLoadingState) awardsLoadingState.style.display = 'none';
    renderAwards(awardsGroups, []);
  }

  /* ===========================
   * Table render
   * =========================== */
  function renderTypes(types) {
    if (!tableBody) return;
    if (typesLoadingRow) typesLoadingRow.remove();
    tableBody.innerHTML = '';

    if (!Array.isArray(types) || types.length === 0) {
      typesTableWrapper?.classList.add('d-none');
      typesEmptyState?.classList.remove('d-none');
      return;
    }
    typesTableWrapper?.classList.remove('d-none');
    typesEmptyState?.classList.add('d-none');

    types.forEach(type => {
      const row = document.createElement('tr');

      // Name
      const nameCell = document.createElement('td');
      const title = document.createElement('div');
      title.className = 'fw-semibold';
      title.textContent = type.type_name || 'Untitled Type';
      nameCell.appendChild(title);
      row.appendChild(nameCell);

      // Awards
      const awardsCell = document.createElement('td');
      awardsCell.className = 'awards-list';
      const awards = Array.isArray(type.awards) ? type.awards : [];
      if (!awards.length) {
        const empty = document.createElement('span');
        empty.className = 'text-muted small';
        empty.textContent = 'No awards linked yet.';
        awardsCell.appendChild(empty);
      } else {
        const tags = document.createElement('div');
        tags.className = 'et-awards-tags';
        tags.setAttribute('role', 'list');
        awards.forEach((a) => {
          const tag = document.createElement('span');
          tag.className = 'et-award-tag badge rounded-pill text-bg-light border';
          tag.setAttribute('role', 'listitem');
          tag.textContent = a.question_name || 'Award';
          tags.appendChild(tag);
        });
        awardsCell.appendChild(tags);
      }
      row.appendChild(awardsCell);

      // (Status column removed)

      // Actions
      const actionsCell = document.createElement('td');
      actionsCell.className = 'actions';

      const wrap = document.createElement('div');
      wrap.className = 'admin-table-actions';
      wrap.setAttribute('role', 'group');

      const editBtn = document.createElement('button');
      editBtn.type = 'button';
      editBtn.className = 'btn btn-sm btn-edit';
      editBtn.textContent = 'Edit';
      editBtn.addEventListener('click', () => openEditModal(type.type_id));

      const delBtn = document.createElement('button');
      delBtn.type = 'button';
      delBtn.className = 'btn btn-sm btn-danger';
      delBtn.textContent = 'Delete';
      delBtn.addEventListener('click', () => confirmAndDelete(type.type_id));

      wrap.appendChild(editBtn);
      wrap.appendChild(delBtn);
      actionsCell.appendChild(wrap);
      row.appendChild(actionsCell);

      tableBody.appendChild(row);
    });
  }

  async function loadTypes() {
    try {
      const data = await api('list');
      if (data.featureEnabled === false || data.status === 'disabled') {
        setFeatureDisabled(data.message);
        renderTypes([]);
        return;
      }
      if (data.no_active_event) {
        featureEnabled = false;
        if (featureDisabledAlert) {
          featureDisabledAlert.textContent = 'No active event is set. Activate an event to manage business categories.';
          featureDisabledAlert.classList.add('show');
        }
        typesCard?.classList.remove('d-none');
        addTypeBtn?.setAttribute('disabled', 'disabled');
        renderTypes([]);
        return;
      }
      clearFeatureDisabled();
      if (data.status !== 'success') throw new Error(data.message || 'Unable to load business categories.');
      renderTypes(data.data || []);
    } catch (e) {
      console.error('Failed to load establishment types', e);
      showToast('Unable to load business categories.','danger');
      renderTypes([]);
    }
  }

  /* ===========================
   * CRUD helpers for modal
   * =========================== */
  async function openEditModal(typeId) {
    await ensureAwardsLoaded();
    const data = await api('get', { type_id: typeId });
    if (data.status !== 'success') { showToast(data.message || 'Failed to load type.','danger'); return; }
    const t = data.data;

    modalTitle.textContent = 'Edit Business Category';
    typeForm.dataset.mode = 'edit';
    typeForm.dataset.typeId = String(t.type_id);
    typeNameInput.value = t.type_name ?? '';
    typeNameInput.classList.remove('is-invalid');
    if (awardsFeedback) awardsFeedback.style.display = 'none';

    const selected = (t.awards || []).map(a => a.question_id);
    renderAwards(awardsGroups, selected);

    getBootstrapModal()?.show();
  }

  function confirmAction(options) {
    if (typeof window.adminConfirm === 'function') {
      return window.adminConfirm(options);
    }
    return Promise.resolve(window.confirm(options?.message || 'Are you sure?'));
  }

  async function confirmAndDelete(typeId) {
    const confirmed = await confirmAction({
      title: 'Delete business category',
      message: 'Delete this business category? This cannot be undone.',
      confirmLabel: 'Delete',
      confirmClass: 'btn-danger',
    });
    if (!confirmed) return;
    const data = await api('delete', { type_id: typeId });
    if (data.status !== 'success') { showToast(data.message || 'Delete failed.','danger'); return; }
    showToast('Business category deleted.');
    await loadTypes();
  }

  /* ===========================
   * Modal wiring
   * =========================== */
  addTypeBtn?.addEventListener('click', async () => {
    if (!featureEnabled) return;
    modalTitle.textContent = 'Add Business Category';
    typeForm.dataset.mode = 'create';
    delete typeForm.dataset.typeId;
    resetForm();
    getBootstrapModal()?.show();
    await ensureAwardsLoaded();
  });

  typeNameInput?.addEventListener('input', () => {
    typeNameInput.classList.remove('is-invalid');
    if (typeNameFeedback) typeNameFeedback.textContent = '';
  });

  function setSavingState(isSaving) {
    const saveBtn = document.getElementById('saveTypeBtn');
    if (!saveBtn) return;
    saveBtn.disabled = isSaving;
    saveBtn.innerHTML = isSaving
      ? '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Saving...'
      : 'Save Business Category';
  }
  function resetForm() {
    typeForm?.reset();
    typeNameInput?.classList.remove('is-invalid');
    if (typeNameFeedback) typeNameFeedback.textContent = '';
    awardsFeedback?.style.setProperty('display','none');
    awardsListEl?.classList.remove('is-invalid-awards');
    if (awardsLoaded) renderAwards(awardsGroups, []);
  }

  typeForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!featureEnabled) return;

    const typeName = typeNameInput?.value?.trim() || '';
    if (!typeName) {
      typeNameInput?.classList.add('is-invalid');
      if (typeNameFeedback) typeNameFeedback.textContent = 'Please enter a business category name.';
      return;
    }
    if (!validateAtLeastOneAwardSelected()) return;

    const awardIds = collectSelectedAwardIds();

    const payload = { type_name: typeName, awards: awardIds };
    let action = 'create';
    if (typeForm.dataset.mode === 'edit') {
      action = 'update';
      payload.type_id = Number(typeForm.dataset.typeId);
    }

    setSavingState(true);
    try {
      const data = await api(action, payload);
      if (data.status === 'duplicate') {
        typeNameInput?.classList.add('is-invalid');
        if (typeNameFeedback) typeNameFeedback.textContent = data.message || 'This business category already exists.';
        return;
      }
      if (data.status !== 'success') {
        showToast(data.message || 'Failed to save business category.','danger');
        return;
      }
      getBootstrapModal()?.hide();
      showToast(action === 'create' ? 'Business category added successfully.' : 'Business category updated.');
      await loadTypes();
    } catch (err) {
      console.error('Failed to save establishment type', err);
      showToast('Failed to save business category.','danger');
    } finally {
      setSavingState(false);
    }
  });

  /* ===========================
   * Initial load
   * =========================== */
  loadTypes();
})();
