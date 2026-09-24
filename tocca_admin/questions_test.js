let rowToEdit = null;
let answerFieldsTouched = false;
const categoriesById = {};

const ANSWER_FIELD_LABELS = {
  business_photo: 'Business name + photo',
  product_business: 'Business - product (one dropdown)',
  song_singer: 'Song title + singer',
};

const ANSWER_FIELD_HELP = {
  business_photo: 'Voters pick a business from the list. A photo is optional. Use for Food and Service.',
  product_business: 'Voters pick from one dropdown (business - product). Use for Feelings food and makeup/hairstylist awards. Place awards such as Best Date Place should use Business name + photo.',
  song_singer: 'Voters type both answers. No business list and no photo. Use for Best Break Up Song.',
};

function answerFieldsLabel(value) {
  return ANSWER_FIELD_LABELS[value] || ANSWER_FIELD_LABELS.business_photo;
}

function looksLikeArtistAward(name = '') {
  const n = String(name).trim().toLowerCase();
  if (!n) return false;
  return ['make-up artist', 'makeup artist', 'make up artist', 'hairstylist', 'hair stylist']
    .some((phrase) => n.includes(phrase));
}

function looksLikePlaceAward(name = '') {
  const n = String(name).trim().toLowerCase();
  if (!n) return false;
  return ['date place', 'hangout spot', 'viewing spot', 'tourist spot']
    .some((phrase) => n.includes(phrase));
}

function looksLikeSongAward(name = '') {
  const n = String(name).trim().toLowerCase();
  if (!n) return false;
  if (looksLikeArtistAward(n) || looksLikePlaceAward(n)) {
    return false;
  }
  return ['break up song', 'breakup song', 'love song', 'theme song', 'music video', 'song', 'music', 'anthem', 'opm', 'band', 'album', 'tune', 'lyric', 'singer', 'dj']
    .some((needle) => n.includes(needle));
}

function defaultAnswerFields(categoryId, awardName = '') {
  if (looksLikePlaceAward(awardName) || looksLikeArtistAward(awardName)) {
    return 'product_business';
  }
  if (looksLikeSongAward(awardName)) {
    return 'song_singer';
  }
  const cat = categoriesById[String(categoryId)] || {};
  const profile = String(cat.voting_profile || '').toLowerCase();
  const catName = String(cat.category_name || '').toLowerCase();
  if (profile === 'media') return 'song_singer';
  if (profile === 'mixed' || catName.includes('feeling')) return 'product_business';
  return 'business_photo';
}

function setAnswerFieldsHelp(value) {
  const help = document.getElementById('editAnswerFieldsHelp');
  if (help) {
    help.textContent = ANSWER_FIELD_HELP[value] || ANSWER_FIELD_HELP.business_photo;
  }
}

function applyAnswerFieldsDefault() {
  if (answerFieldsTouched || rowToEdit) {
    return;
  }
  const categoryId = document.getElementById('editCategory')?.value;
  const awardName = document.getElementById('editName')?.value || '';
  const fieldsEl = document.getElementById('editAnswerFields');
  if (!fieldsEl) return;
  const next = defaultAnswerFields(categoryId, awardName);
  fieldsEl.value = next;
  setAnswerFieldsHelp(next);
}

function notify(message, isSuccess = true) {
  if (typeof window.showToast === 'function') {
    window.showToast(message, isSuccess ? 'success' : 'danger');
    return;
  }
  const toastEl = document.getElementById('toast');
  const toastBody = document.getElementById('toastBody');
  if (toastEl && toastBody) {
    toastEl.className = `toast text-bg-${isSuccess ? 'success' : 'danger'} border-0`;
    toastBody.textContent = message;
    bootstrap.Toast.getOrCreateInstance(toastEl, { delay: 4000 }).show();
    return;
  }
  alert(message);
}

function destroyQuestionsDataTable() {
  if (typeof window.jQuery === 'undefined' || !$.fn.DataTable) {
    return;
  }
  if ($.fn.DataTable.isDataTable('#questionsTable')) {
    $('#questionsTable').DataTable().destroy();
  }
}

function loadCategoryDropdown() {
  const dropdown = document.getElementById('editCategory');
  const filterDropdown = document.getElementById('categoryDropdown');

  if (!dropdown) {
    return Promise.resolve();
  }

  dropdown.innerHTML = '<option value="">-- Select Category --</option>';
  if (filterDropdown) {
    filterDropdown.innerHTML = '<option value="all">All categories</option>';
  }

  return fetch('get_categories.php')
    .then(response => response.json())
    .then(result => {
      if (result.status !== 'success' || !Array.isArray(result.data)) {
        return;
      }
      result.data.forEach(category => {
        categoriesById[String(category.category_id)] = category;
        const option = document.createElement('option');
        option.value = category.category_id;
        option.textContent = category.category_name;
        dropdown.appendChild(option);

        if (filterDropdown) {
          const filterOption = option.cloneNode(true);
          filterDropdown.appendChild(filterOption);
        }
      });
    })
    .catch(() => {
      notify('Could not load categories.', false);
    });
}

function initializeDataTable() {
  if (typeof window.jQuery === 'undefined' || !$.fn.DataTable) {
    return;
  }
  setTimeout(() => {
    destroyQuestionsDataTable();
    $('#questionsTable').DataTable({
      pageLength: 5,
      lengthMenu: [5, 10, 25, 50],
      lengthChange: true,
      searching: true,
      ordering: false,
      info: true,
      responsive: true,
      language: {
        emptyTable: 'No awards found for this event.',
      },
    });
  }, 50);
}

function loadQuestionsByCategory(categoryId = 'all') {
  const payload = categoryId === 'all'
    ? { action: 'loadAll' }
    : { action: 'filterByCategory', category_id: parseInt(categoryId, 10) };

  destroyQuestionsDataTable();

  return fetch('question.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
    .then(async (res) => {
      const text = await res.text();
      let result;
      try {
        result = JSON.parse(text);
      } catch (err) {
        throw new Error('Invalid response from server.');
      }
      if (!res.ok) {
        throw new Error(result.message || `Request failed (${res.status})`);
      }
      return result;
    })
    .then((result) => {
      const tableBody = document.getElementById('tableBody');
      if (!tableBody) {
        return;
      }
      tableBody.innerHTML = '';

      if (result.status !== 'success') {
        notify(result.message || 'Could not load awards.', false);
        initializeDataTable();
        return;
      }

      const questions = (Array.isArray(result.data) ? result.data : []).sort((a, b) =>
        String(a.question_name || '').localeCompare(String(b.question_name || ''))
      );

      questions.forEach((question) => {
        const row = document.createElement('tr');
        row.setAttribute('data-id', question.question_id);
        row.setAttribute('data-name', question.question_name);
        row.innerHTML = `
            <td>${renderAwardNameCell(question)}</td>
            <td>${escapeHtml(question.category_name || '—')}</td>
            <td>${renderAnswerFieldsSelect(question)}</td>
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
    .catch((err) => {
      notify(err.message || 'Failed to load awards.', false);
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

function renderAwardNameCell(question) {
  return escapeHtml(question.question_name);
}

function renderAnswerFieldsSelect(question) {
  const current = question.answer_fields || 'business_photo';
  const options = Object.entries(ANSWER_FIELD_LABELS)
    .map(([value, label]) => {
      const selected = value === current ? ' selected' : '';
      return `<option value="${escapeHtml(value)}"${selected}>${escapeHtml(label)}</option>`;
    })
    .join('');
  const help = ANSWER_FIELD_HELP[current] || ANSWER_FIELD_HELP.business_photo;
  return `<select class="form-select form-select-sm answer-fields-select" title="${escapeHtml(help)}" aria-label="Answer fields for ${escapeHtml(question.question_name)}">${options}</select>`;
}

function saveAnswerFields(questionId, value, selectEl) {
  selectEl.disabled = true;
  fetch('question.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      action: 'updateAnswerFields',
      question_id: parseInt(questionId, 10),
      answer_fields: value,
    }),
  })
    .then((res) => res.json())
    .then((result) => {
      if (result.status === 'success') {
        selectEl.title = ANSWER_FIELD_HELP[selectEl.value] || ANSWER_FIELD_HELP.business_photo;
        notify('Answer fields updated. The voter ballot will use this layout.', true);
      } else {
        notify(result.message || 'Could not update answer fields.', false);
      }
    })
    .catch(() => notify('Could not update answer fields.', false))
    .finally(() => {
      selectEl.disabled = false;
    });
}

document.addEventListener('DOMContentLoaded', function () {
  loadCategoryDropdown().then(() => loadQuestionsByCategory('all'));

  const filterDropdown = document.getElementById('categoryDropdown');
  filterDropdown?.addEventListener('change', () => {
    loadQuestionsByCategory(filterDropdown.value || 'all');
  });

  document.getElementById('editCategory')?.addEventListener('change', () => {
    answerFieldsTouched = false;
    applyAnswerFieldsDefault();
  });
  document.getElementById('editName')?.addEventListener('input', () => {
    applyAnswerFieldsDefault();
  });
  document.getElementById('editAnswerFields')?.addEventListener('change', (e) => {
    answerFieldsTouched = true;
    setAnswerFieldsHelp(e.target.value);
  });

  document.getElementById('addRowBtn')?.addEventListener('click', function () {
    const editForm = document.getElementById('editForm');
    if (editForm) {
      editForm.reset();
    }
    document.getElementById('editModalLabel').textContent = 'Add Award';
    const categorySelect = document.getElementById('editCategory');
    if (categorySelect) {
      categorySelect.value = '';
    }
    answerFieldsTouched = false;
    const fieldsEl = document.getElementById('editAnswerFields');
    if (fieldsEl) {
      fieldsEl.value = 'business_photo';
      setAnswerFieldsHelp('business_photo');
    }
    rowToEdit = null;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('editModal')).show();
  });

  document.getElementById('saveChangesBtn')?.addEventListener('click', function () {
    const questionInput = document.getElementById('editName');
    const questionText = questionInput.value.trim();
    const categorySelect = document.getElementById('editCategory');
    const categoryId = parseInt(categorySelect.value, 10);

    questionInput.classList.remove('is-invalid');
    categorySelect.classList.remove('is-invalid');

    let valid = true;
    if (!questionText) {
      questionInput.classList.add('is-invalid');
      document.getElementById('editNameFeedback').textContent = 'Please enter an award name.';
      questionInput.focus();
      valid = false;
    }

    if (Number.isNaN(categoryId) || categoryId <= 0) {
      categorySelect.classList.add('is-invalid');
      document.getElementById('editCategoryFeedback').textContent = 'Please select a category.';
      if (valid) {
        categorySelect.focus();
      }
      valid = false;
    }

    if (!valid) {
      return;
    }

    const isEdit = !!rowToEdit;

    const payload = {
      action: isEdit ? 'update' : 'create',
      question_name: questionText,
      category_id: categoryId,
      answer_fields: document.getElementById('editAnswerFields')?.value || 'business_photo',
    };

    if (isEdit) {
      payload.question_id = rowToEdit.question_id;
    }

    fetch('question.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })
      .then((res) => res.json())
      .then((result) => {
        if (result.status === 'success') {
          const filterVal = document.getElementById('categoryDropdown')?.value || 'all';
          loadQuestionsByCategory(filterVal);
          bootstrap.Modal.getInstance(document.getElementById('editModal'))?.hide();
          rowToEdit = null;
          notify(isEdit ? 'Award updated successfully.' : 'Award added successfully.', true);
        } else if (result.status === 'duplicate') {
          notify('This award already exists in the selected category.', false);
        } else {
          notify(result.message || 'Error saving award.', false);
        }
      })
      .catch(() => notify('Error saving award.', false));
  });

  document.getElementById('tableBody')?.addEventListener('change', function (e) {
    const select = e.target.closest('.answer-fields-select');
    if (!select) {
      return;
    }
    const row = select.closest('tr');
    const id = row?.getAttribute('data-id');
    if (!id) {
      return;
    }
    saveAnswerFields(id, select.value, select);
  });
  document.getElementById('tableBody')?.addEventListener('click', function (e) {
    if (e.target.closest('.answer-fields-select')) {
      e.stopPropagation();
    }
    const row = e.target.closest('tr');
    if (!row) {
      return;
    }

    if (e.target.classList.contains('editBtn')) {
      const questionId = row.getAttribute('data-id');

      fetch('question.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'getSingle', question_id: parseInt(questionId, 10) }),
      })
        .then((res) => res.json())
        .then((result) => {
          if (result.status === 'success') {
            const q = result.data;
            document.getElementById('editName').value = q.question_name;
            document.getElementById('editModalLabel').textContent = 'Edit Award';
            const categorySelect = document.getElementById('editCategory');
            if (categorySelect) {
              const categoryValue = String(q.category_id);
              const hasOption = Array.from(categorySelect.options).some((option) => option.value === categoryValue);
              if (!hasOption) {
                loadCategoryDropdown().then(() => {
                  document.getElementById('editCategory').value = categoryValue;
                });
              } else {
                categorySelect.value = categoryValue;
              }
            }
            const fieldsEl = document.getElementById('editAnswerFields');
            if (fieldsEl) {
              const stored = q.answer_fields || defaultAnswerFields(q.category_id, q.question_name);
              fieldsEl.value = stored;
              setAnswerFieldsHelp(stored);
            }
            answerFieldsTouched = true;
            rowToEdit = { question_id: q.question_id };
            bootstrap.Modal.getOrCreateInstance(document.getElementById('editModal')).show();
          }
        });
    }

    if (e.target.classList.contains('deleteBtn')) {
      const id = row.getAttribute('data-id');
      const questionName = row.getAttribute('data-name') || 'this award';

      const runDelete = () => {
        fetch('question.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'delete', ids: [parseInt(id, 10)] }),
        })
          .then((res) => res.json())
          .then((result) => {
            if (result.status === 'success') {
              const filterVal = document.getElementById('categoryDropdown')?.value || 'all';
              loadQuestionsByCategory(filterVal);
              notify(`"${questionName}" deleted successfully.`, true);
            } else {
              notify(result.message || 'Failed to delete award.', false);
            }
          })
          .catch(() => notify('Failed to delete award.', false));
      };

      if (typeof window.adminConfirm === 'function') {
        window
          .adminConfirm({
            title: 'Delete award',
            message: `Are you sure you want to delete "${questionName}"? This action cannot be undone.`,
            confirmLabel: 'Delete',
            confirmClass: 'btn-danger',
          })
          .then((confirmed) => {
            if (confirmed) {
              runDelete();
            }
          });
      } else if (window.confirm(`Delete "${questionName}"?`)) {
        runDelete();
      }
    }
  });
});
