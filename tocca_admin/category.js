let rowToEdit = null;

function notify(message, isSuccess = true) {
  if (typeof window.showToast === 'function') {
    window.showToast(message, isSuccess ? 'success' : 'danger');
    return;
  }
  const toastEl = document.getElementById('statusToast');
  const toastBody = document.getElementById('statusToastBody');
  if (toastEl && toastBody) {
    toastEl.className = `toast text-bg-${isSuccess ? 'success' : 'danger'} border-0`;
    toastBody.textContent = message;
    bootstrap.Toast.getOrCreateInstance(toastEl, { delay: 4000 }).show();
  }
}

const VOTING_PROFILE_LABELS = {
  business: 'Business',
  media: 'Music & media',
  places: 'Places',
  general: 'General',
  mixed: 'Mixed (auto)',
};

function votingProfileLabel(key) {
  return VOTING_PROFILE_LABELS[String(key || 'business').toLowerCase()] || 'Business';
}

function getActiveEventId() {
  const raw = document.getElementById('eventId')?.value || '';
  const id = parseInt(raw, 10);
  return Number.isFinite(id) && id > 0 ? id : null;
}

function clearCategoryValidation() {
  const form = document.getElementById('editForm');
  form?.classList.remove('was-validated');
  ['editName', 'activeEventDisplay'].forEach((id) => {
    const el = document.getElementById(id);
    if (el) {
      el.classList.remove('is-invalid');
    }
  });
}

function validateCategoryForm() {
  clearCategoryValidation();
  const form = document.getElementById('editForm');
  const nameEl = document.getElementById('editName');
  const eventDisplay = document.getElementById('activeEventDisplay');
  let valid = true;

  const name = nameEl?.value.trim() || '';
  if (!name) {
    nameEl?.classList.add('is-invalid');
    document.getElementById('editNameFeedback').textContent = 'Please enter a category name.';
    valid = false;
  }

  if (!getActiveEventId()) {
    eventDisplay?.classList.add('is-invalid');
    valid = false;
    if (!name) {
      notify('No active event is set. Activate an event before saving a category.', false);
    }
  }

  if (!valid) {
    form?.classList.add('was-validated');
    (name && getActiveEventId() ? nameEl : eventDisplay || nameEl)?.focus();
  }

  return valid;
}

function initializeDataTable() {
  if (typeof window.jQuery === 'undefined' || !$.fn.DataTable) {
    return;
  }
  if ($.fn.DataTable.isDataTable('#categoriesTable')) {
    $('#categoriesTable').DataTable().destroy();
  }

  $('#categoriesTable').DataTable({
    pageLength: 5,
    lengthMenu: [5, 10, 25, 50],
    lengthChange: true,
    searching: true,
    ordering: false,
    info: true,
    responsive: true,
    language: {
      emptyTable: 'No categories found for this event.',
    },
  });
}

function loadCategories() {
  return fetch('category.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ loadOnly: true }),
  })
    .then((res) => res.json())
    .then((result) => {
      if (typeof window.jQuery !== 'undefined' && $.fn.DataTable.isDataTable('#categoriesTable')) {
        $('#categoriesTable').DataTable().clear().destroy();
      }

      const tableBody = document.getElementById('tableBody');
      if (!tableBody) {
        return;
      }
      tableBody.innerHTML = '';

      if (result.status !== 'success') {
        notify(result.message || 'Could not load categories.', false);
        initializeDataTable();
        return;
      }

      const categories = (Array.isArray(result.data) ? result.data : []).sort((a, b) =>
        String(a.category_name || '').localeCompare(String(b.category_name || ''))
      );

      categories.forEach((category) => {
        const row = document.createElement('tr');
        row.setAttribute('data-id', category.category_id);
        const isActive = parseInt(category.status, 10) === 1;

        row.innerHTML = `
            <td>${escapeHtml(category.category_name)}</td>
            <td><span class="badge text-bg-light border">${escapeHtml(votingProfileLabel(category.voting_profile))}</span></td>
            <td>
              <div class="form-check form-switch">
                <input class="form-check-input status-switch" type="checkbox"
                      data-id="${category.category_id}" ${isActive ? 'checked' : ''}>
                <span class="form-check-label status-label">${isActive ? 'Active' : 'Inactive'}</span>
              </div>
            </td>
            <td>
              <div class="admin-table-actions" role="group">
                <button type="button" class="btn btn-sm btn-edit editBtn">Edit</button>
                <button type="button" class="btn btn-sm btn-danger deleteBtn">Delete</button>
              </div>
            </td>`;

        tableBody.appendChild(row);
      });
      initializeDataTable();
    })
    .catch(() => {
      notify('Failed to load categories.', false);
      initializeDataTable();
    });
}

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function formatDeleteSuccessMessage(categoryName, deleteResult) {
  const deleted = Array.isArray(deleteResult?.deleted) ? deleteResult.deleted[0] : null;
  const awardCount = deleted?.award_count ?? 0;
  if (awardCount > 0) {
    const names = (deleted?.award_names || []).slice(0, 3);
    const suffix =
      awardCount > 3 ? ` and ${awardCount - 3} more` : names.length ? `: ${names.join(', ')}` : '';
    return `"${categoryName}" and ${awardCount} linked award${awardCount === 1 ? '' : 's'}${suffix} were deleted.`;
  }
  return `"${categoryName}" deleted successfully.`;
}

function buildCategoryDeleteConfirmHtml(categoryName, impact) {
  const safeName = escapeHtml(categoryName);
  if (!impact || !impact.award_count) {
    return `<p class="mb-2">Delete <strong>${safeName}</strong>?</p><p class="mb-0 text-muted small">This category has no linked awards. This action cannot be undone.</p>`;
  }

  const names = (impact.awards || []).map((a) => a.question_name).filter(Boolean);
  const preview = names.slice(0, 5);
  const remaining = Math.max(0, names.length - preview.length);
  const listItems = preview
    .map((name) => `<li>${escapeHtml(name)}</li>`)
    .join('');
  const more =
    remaining > 0 ? `<li class="text-muted">…and ${remaining} more award${remaining === 1 ? '' : 's'}</li>` : '';

  const relatedLines = [];
  const related = impact.related || {};
  if (related.nominations > 0) {
    relatedLines.push(`${related.nominations} registration selection${related.nominations === 1 ? '' : 's'}`);
  }
  if (related.votes > 0) {
    relatedLines.push(`${related.votes} vote record${related.votes === 1 ? '' : 's'}`);
  }
  if (related.establishment_links > 0) {
    relatedLines.push(`${related.establishment_links} business link${related.establishment_links === 1 ? '' : 's'}`);
  }
  if (related.establishment_type_links > 0) {
    relatedLines.push(`${related.establishment_type_links} nature of business link${related.establishment_type_links === 1 ? '' : 's'}`);
  }

  const relatedHtml = relatedLines.length
    ? `<p class="mb-2 small text-danger"><strong>Also affected:</strong> ${escapeHtml(relatedLines.join(', '))}.</p>`
    : '';

  const warning =
    impact.has_blocking_data
      ? '<p class="mb-2 small text-danger fw-semibold">This category already has registrations or votes. Deleting it will permanently remove that data.</p>'
      : '';

  return `
    <p class="mb-2">Delete <strong>${safeName}</strong> and all linked awards?</p>
    <p class="mb-2 small">The following ${impact.award_count} award${impact.award_count === 1 ? '' : 's'} will be permanently deleted:</p>
    <ul class="small mb-2 ps-3">${listItems}${more}</ul>
    ${relatedHtml}
    ${warning}
    <p class="mb-0 small text-muted">This action cannot be undone.</p>
  `;
}

async function fetchCategoryDeleteImpact(categoryId) {
  const res = await fetch('category.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'getDeleteImpact', category_id: parseInt(categoryId, 10) }),
  });
  const result = await res.json();
  if (result.status === 'success' && result.data) {
    return result.data;
  }
  return null;
}

function openEditModal(mode) {
  clearCategoryValidation();
  const modalEl = document.getElementById('editModal');
  document.getElementById('editModalLabel').textContent = mode === 'edit' ? 'Edit Category' : 'Add Category';
  bootstrap.Modal.getOrCreateInstance(modalEl).show();
}

document.addEventListener('DOMContentLoaded', function () {
  loadCategories();

  ['editName'].forEach((id) => {
    document.getElementById(id)?.addEventListener('input', () => {
      document.getElementById(id)?.classList.remove('is-invalid');
    });
    document.getElementById(id)?.addEventListener('change', () => {
      document.getElementById(id)?.classList.remove('is-invalid');
    });
  });

  document.getElementById('addRowBtn')?.addEventListener('click', function () {
    if (!getActiveEventId()) {
      notify('No active event is set. Activate an event before adding a category.', false);
      return;
    }
    document.getElementById('editName').value = '';
    document.getElementById('editCategoryId').value = '';
    const profileEl = document.getElementById('editVotingProfile');
    if (profileEl) profileEl.value = 'business';
    rowToEdit = null;
    openEditModal('add');
  });

  document.getElementById('saveChangesBtn')?.addEventListener('click', function () {
    if (!validateCategoryForm()) {
      return;
    }

    const name = document.getElementById('editName').value.trim();
    const id = document.getElementById('editCategoryId').value;
    const eventId = getActiveEventId();
    if (!eventId) {
      return;
    }

    const votingProfile = document.getElementById('editVotingProfile')?.value || 'business';
    const payload = id
      ? {
          action: 'update',
          category_name: name,
          category_id: parseInt(id, 10),
          event_id: eventId,
          voting_profile: votingProfile,
        }
      : { action: 'create', category_name: name, event_id: eventId, voting_profile: votingProfile };

    fetch('category.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })
      .then((res) => res.json())
      .then((result) => {
        if (result.status === 'success') {
          loadCategories();
          bootstrap.Modal.getInstance(document.getElementById('editModal'))?.hide();
          rowToEdit = null;
          notify(id ? 'Category updated successfully.' : 'Category added successfully.', true);
        } else if (result.status === 'duplicate') {
          const nameEl = document.getElementById('editName');
          nameEl?.classList.add('is-invalid');
          document.getElementById('editNameFeedback').textContent =
            'This category already exists for the selected event.';
          nameEl?.focus();
        } else {
          notify(result.message || 'Error saving category.', false);
        }
      })
      .catch(() => notify('Error saving category.', false));
  });

  document.getElementById('tableBody')?.addEventListener('click', function (e) {
    const row = e.target.closest('tr');
    if (!row) {
      return;
    }

    if (e.target.classList.contains('editBtn')) {
      rowToEdit = row;
      const categoryId = row.getAttribute('data-id');

      fetch('category.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'getSingle', category_id: categoryId }),
      })
        .then((res) => res.json())
        .then((result) => {
          if (result.status === 'success' && result.data) {
            const cat = result.data;
            document.getElementById('editName').value = cat.category_name;
            document.getElementById('editCategoryId').value = cat.category_id;
            const profileEl = document.getElementById('editVotingProfile');
            if (profileEl) {
              profileEl.value = cat.voting_profile || 'business';
            }
            const eventInput = document.getElementById('eventId');
            if (eventInput && cat.event_id) {
              eventInput.value = String(cat.event_id);
            }
            openEditModal('edit');
          } else {
            notify('Failed to load category data for editing.', false);
          }
        })
        .catch(() => notify('Failed to load category data for editing.', false));
    }

    if (e.target.classList.contains('deleteBtn')) {
      const id = row.getAttribute('data-id');
      const categoryName = row.querySelector('td')?.textContent?.trim() || 'this category';

      const runDelete = () => {
        fetch('category.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'delete', ids: [parseInt(id, 10)] }),
        })
          .then((res) => res.json())
          .then((result) => {
            if (result.status === 'success') {
              loadCategories();
              notify(formatDeleteSuccessMessage(categoryName, result), true);
            } else {
              notify(result.message || `Failed to delete "${categoryName}".`, false);
            }
          })
          .catch(() => notify(`Failed to delete "${categoryName}".`, false));
      };

      fetchCategoryDeleteImpact(id)
        .then((impact) => {
          const confirmHtml = buildCategoryDeleteConfirmHtml(categoryName, impact);
          const fallbackMessage = impact?.award_count
            ? `Delete "${categoryName}" and ${impact.award_count} linked award${impact.award_count === 1 ? '' : 's'}? This cannot be undone.`
            : `Are you sure you want to delete "${categoryName}"? This action cannot be undone.`;

          if (typeof window.adminConfirm === 'function') {
            return window.adminConfirm({
              title: impact?.award_count ? 'Delete category and awards' : 'Delete category',
              message: fallbackMessage,
              html: confirmHtml,
              confirmLabel: 'Delete',
              confirmClass: 'btn-danger',
            });
          }
          return window.confirm(fallbackMessage);
        })
        .then((confirmed) => {
          if (confirmed) {
            runDelete();
          }
        })
        .catch(() => {
          if (window.confirm(`Delete "${categoryName}"? This action cannot be undone.`)) {
            runDelete();
          }
        });
    }
  });

  document.getElementById('tableBody')?.addEventListener('change', function (e) {
    if (!e.target.classList.contains('status-switch')) {
      return;
    }

    const toggle = e.target;
    const id = toggle.getAttribute('data-id');
    const newStatus = toggle.checked ? 1 : 0;
    const labelEl = toggle.closest('.form-switch')?.querySelector('.status-label');
    const row = toggle.closest('tr');
    const name = row?.querySelector('td')?.textContent?.trim() || 'this category';

    const revertToggle = () => {
      toggle.checked = !toggle.checked;
      if (labelEl) {
        labelEl.textContent = toggle.checked ? 'Active' : 'Inactive';
      }
    };

    const applyStatus = () => {
      fetch('category.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'toggleStatus',
          category_id: id,
          status: newStatus,
        }),
      })
        .then((res) => res.json())
        .then((result) => {
          if (result.status === 'success') {
            if (labelEl) {
              labelEl.textContent = newStatus === 1 ? 'Active' : 'Inactive';
            }
            notify(`"${name}" is now ${newStatus === 1 ? 'active' : 'inactive'}.`, true);
          } else {
            revertToggle();
            notify(result.message || `Failed to update status for "${name}".`, false);
          }
        })
        .catch(() => {
          revertToggle();
          notify(`Error occurred while updating "${name}".`, false);
        });
    };

    const message =
      newStatus === 0
        ? `"${name}" will be hidden from voters. Deactivate this category?`
        : `"${name}" will be visible to voters. Activate this category?`;

    if (typeof window.adminConfirm === 'function') {
      window
        .adminConfirm({
          title: newStatus === 0 ? 'Deactivate category' : 'Activate category',
          message,
          confirmLabel: newStatus === 0 ? 'Deactivate' : 'Activate',
          confirmClass: newStatus === 0 ? 'btn-warning' : 'btn-primary',
        })
        .then((confirmed) => {
          if (confirmed) {
            applyStatus();
          } else {
            revertToggle();
          }
        });
    } else {
      if (window.confirm(message)) {
        applyStatus();
      } else {
        revertToggle();
      }
    }
  });
});
