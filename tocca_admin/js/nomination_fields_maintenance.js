/**
 * Registration Form Maintenance — drag reorder, preview iframe, field editor modal.
 */
(function () {
  'use strict';

  const cfg = window.NOM_FIELDS_MAINT || {};
  const csrf = cfg.csrf || '';
  const eventId = cfg.eventId || 0;
  const previewUrl = cfg.previewUrl || 'nomination_form_preview.php';
  const PROFILE_ROLE_PATTERNS = [
    { role: 'logo', re: /\b(logo|brand\s*mark)\b/i },
    { role: 'mayor_permit', re: /\b(mayor|business\s*permit|mayor.?s?\s*permit)\b/i },
    { role: 'website', re: /\b(website|web\s*site|facebook|instagram|social\s*media|url)\b/i },
    { role: 'email', re: /\b(e-?mail|email\s*address)\b/i },
    { role: 'mobile', re: /\b(mobile|cell\s*phone|phone|contact\s*no|telephone|tel)\b/i },
    { role: 'address', re: /\b(address|location|street|barangay|city)\b/i },
    { role: 'owner_name', re: /\b(owner|manager|proprietor|representative|contact\s*person)\b/i },
    { role: 'business_name', re: /\b(business|company|establishment|trade\s*name|store\s*name)\b/i },
  ];

  const tbody = document.querySelector('#fieldsTable tbody');
  const previewFrame = document.getElementById('nomPreviewFrame');
  const fieldModalEl = document.getElementById('fieldModal');
  const fieldForm = document.getElementById('fieldForm');
  const typeEl = document.getElementById('type');
  const optionsWrap = document.getElementById('optionsWrap');

  function adminConfirmOrFallback(options) {
    if (typeof window.adminConfirm === 'function') {
      return window.adminConfirm(options);
    }
    return Promise.resolve(window.confirm(options?.message || 'Are you sure?'));
  }

  function showToast(message, isSuccess) {
    const toastEl = document.getElementById('flashToast');
    const body = document.getElementById('flashToastBody');
    if (!toastEl || !body || typeof bootstrap === 'undefined') return;
    body.textContent = message;
    toastEl.className = 'toast ' + (isSuccess ? 'bg-success text-white' : 'bg-danger text-white');
    bootstrap.Toast.getOrCreateInstance(toastEl, { delay: 2800 }).show();
  }

  function refreshPreview() {
    if (!previewFrame) return;
    const params = new URLSearchParams();
    if (eventId) params.set('event_id', String(eventId));
    params.set('_', String(Date.now()));
    previewFrame.src = previewUrl + '?' + params.toString();
  }

  function syncOptionsWrap() {
    if (!typeEl || !optionsWrap) return;
    const v = typeEl.value;
    optionsWrap.style.display =
      v === 'select' || v === 'checkbox' || v === 'radio' ? '' : 'none';
  }

  function applySuggestedProfileRole() {
    const roleEl = document.getElementById('profile_role');
    const labelInput = document.getElementById('label');
    if (!roleEl || !labelInput || roleEl.value !== 'custom') return;
    const suggested = suggestProfileRoleFromLabel(labelInput.value);
    if (suggested) roleEl.value = suggested;
  }

  function setFormField(name, value, opts) {
    if (!fieldForm) return;
    const el = fieldForm.querySelector('[name="' + name + '"]');
    if (!el) return;
    if (el.type === 'checkbox') {
      el.checked = opts && opts.checked !== undefined ? !!opts.checked : !!value;
    } else if (el.type === 'radio') {
      return;
    } else {
      el.value = value != null ? String(value) : '';
    }
  }

  function setEventScope(scope) {
    const el = document.getElementById('event_scope');
    if (!el) return;
    el.value = scope === 'event' ? 'event' : 'all';
  }

  function suggestProfileRoleFromLabel(label) {
    const t = (label || '').trim();
    if (!t) return null;
    for (let i = 0; i < PROFILE_ROLE_PATTERNS.length; i++) {
      if (PROFILE_ROLE_PATTERNS[i].re.test(t)) {
        return PROFILE_ROLE_PATTERNS[i].role;
      }
    }
    return null;
  }

  function updateLabelPreview() {
    const labelInput = document.getElementById('label');
    const labelPreview = document.getElementById('fieldLabelPreview');
    if (!labelPreview || !labelInput) return;
    const t = (labelInput.value || '').trim();
    labelPreview.textContent = t ? 'Business will see: “' + t + '”' : '';
  }

  function resetFieldForm() {
    const titleEl = document.getElementById('fieldModalTitle');
    if (titleEl) titleEl.textContent = 'Add question';
    setFormField('id', '');
    setFormField('label', '');
    setFormField('type', 'text');
    setFormField('options', '');
    setFormField('placeholder', '');
    setFormField('help_text', '');
    setFormField('field_width', 'half');
    setFormField('profile_role', 'custom');
    setFormField('is_required', '', { checked: false });
    setFormField('is_active', '', { checked: true });
    setEventScope('event');
    setFormField('val_max_length', '');
    setFormField('val_accept', '');
    setFormField('val_min', '');
    setFormField('val_max', '');
    const sortEl = document.getElementById('sort_order');
    if (sortEl) sortEl.value = String(cfg.nextSortOrder || 0);
    syncOptionsWrap();
    updateLabelPreview();
  }

  function populateFieldForm(row) {
    const titleEl = document.getElementById('fieldModalTitle');
    if (titleEl) titleEl.textContent = 'Edit question';
    const optionsText =
      row.options_text ||
      (Array.isArray(row.options_array) ? row.options_array.join('\n') : '');
    setFormField('id', row.id);
    setFormField('label', row.label || '');
    setFormField('type', row.type || 'text');
    setFormField('options', optionsText);
    setFormField('placeholder', row.placeholder || '');
    setFormField('help_text', row.help_text || '');
    setFormField('field_width', row.field_width || 'half');
    setFormField('profile_role', row.profile_role || 'custom');
    setFormField('is_required', '', { checked: !!row.is_required });
    setFormField('is_active', '', { checked: row.is_active == null ? true : !!row.is_active });
    setEventScope(row.event_id && parseInt(row.event_id, 10) > 0 ? 'event' : 'all');
    setFormField('val_max_length', row.val_max_length || '');
    setFormField('val_accept', row.val_accept || '');
    setFormField('val_min', row.val_min || '');
    setFormField('val_max', row.val_max || '');
    const sortEl = document.getElementById('sort_order');
    if (sortEl) sortEl.value = String(row.sort_order != null ? row.sort_order : 0);
    syncOptionsWrap();
    updateLabelPreview();
  }

  async function openFieldEditor(id) {
    if (!fieldModalEl || typeof bootstrap === 'undefined') {
      showToast('Editor is not available. Refresh the page.', false);
      return;
    }
    const fieldId = parseInt(id, 10);
    if (!fieldId) return;
    try {
      const res = await fetch(
        'nomination_field.php?action=get&id=' + encodeURIComponent(String(fieldId)),
        { credentials: 'same-origin', headers: { Accept: 'application/json' } }
      );
      const data = await res.json().catch(function () {
        return { success: false };
      });
      if (!data.success || !data.data) {
        showToast((data && data.message) || 'Could not load question.', false);
        return;
      }
      populateFieldForm(data.data);
      bootstrap.Modal.getOrCreateInstance(fieldModalEl).show();
    } catch (err) {
      console.error('openFieldEditor failed', err);
      showToast('Could not load question.', false);
    }
  }

  if (typeEl) {
    typeEl.addEventListener('change', syncOptionsWrap);
  }

  const labelInput = document.getElementById('label');
  if (labelInput) {
    ['input', 'change'].forEach(function (evt) {
      labelInput.addEventListener(evt, updateLabelPreview);
    });
  }

  if (fieldForm) {
    fieldForm.addEventListener('submit', applySuggestedProfileRole);
  }

  document.querySelectorAll('.js-reset-field-modal').forEach(function (btn) {
    btn.addEventListener('click', resetFieldForm);
  });

  document.addEventListener('click', function (e) {
    const editBtn = e.target.closest('.js-edit-field');
    if (editBtn) {
      e.preventDefault();
      const id = parseInt(editBtn.getAttribute('data-field-id') || '0', 10);
      if (id > 0) openFieldEditor(id);
      return;
    }

    const deleteBtn = e.target.closest('.js-delete-field');
    if (deleteBtn) {
      e.preventDefault();
      const fieldId = parseInt(deleteBtn.getAttribute('data-field-id') || '0', 10);
      const fieldLabel = deleteBtn.getAttribute('data-field-label') || 'this question';
      if (!fieldId) return;
      adminConfirmOrFallback({
        title: '',
        message:
          'Remove "' +
          fieldLabel +
          '"? Past registrations may lose this answer in reports.',
        confirmLabel: 'Yes, continue',
        confirmClass: 'btn-danger',
      }).then(function (confirmed) {
        if (!confirmed) return;
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = cfg.postUrl || 'nomination_fields.php';
        [
          ['csrf_token', csrf],
          ['form_action', 'delete'],
          ['id', String(fieldId)],
        ].forEach(function (pair) {
          const inp = document.createElement('input');
          inp.type = 'hidden';
          inp.name = pair[0];
          inp.value = pair[1];
          form.appendChild(inp);
        });
        document.body.appendChild(form);
        form.submit();
      });
    }
  });

  const btnRefreshPreview = document.getElementById('btnRefreshPreview');
  if (btnRefreshPreview) {
    btnRefreshPreview.addEventListener('click', refreshPreview);
  }

  function postReorder(ids) {
    const body = new FormData();
    body.append('csrf_token', csrf);
    body.append('form_action', 'reorder_fields');
    body.append('order_json', JSON.stringify(ids));
    return fetch(cfg.postUrl || 'nomination_fields.php', {
      method: 'POST',
      body: body,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    }).then(function (r) {
      return r.json();
    });
  }

  if (tbody && typeof Sortable !== 'undefined') {
    Sortable.create(tbody, {
      handle: '.drag-handle',
      animation: 150,
      onEnd: function () {
        const ids = Array.from(tbody.querySelectorAll('tr[data-field-id]')).map(function (tr) {
          return parseInt(tr.getAttribute('data-field-id'), 10);
        });
        postReorder(ids)
          .then(function (res) {
            if (!res || !res.success) {
              showToast((res && res.message) || 'Could not save order. Reloading…', false);
              window.setTimeout(function () {
                window.location.reload();
              }, 1200);
              return;
            }
            tbody.querySelectorAll('tr[data-field-id]').forEach(function (tr, idx) {
              const cell = tr.querySelector('.col-order');
              if (cell) cell.textContent = String(idx + 1);
            });
            showToast('Question order updated.', true);
            refreshPreview();
          })
          .catch(function () {
            showToast('Could not save order. Reloading…', false);
            window.setTimeout(function () {
              window.location.reload();
            }, 1200);
          });
      },
    });
  }

  window.nomFieldsRefreshPreview = refreshPreview;
  window.nomOpenFieldEditor = openFieldEditor;
  window.nomFieldsResetForm = resetFieldForm;
  document.addEventListener('DOMContentLoaded', function () {
    refreshPreview();

    const params = new URLSearchParams(window.location.search);
    const urlEditId =
      params.get('action') === 'edit' ? parseInt(params.get('id') || '0', 10) : 0;
    const cfgEditId = parseInt(cfg.editingFieldId || '0', 10);
    const editId = urlEditId > 0 ? urlEditId : cfgEditId;
    if (editId > 0) {
      openFieldEditor(editId);
      if (urlEditId > 0) {
        history.replaceState({}, '', window.location.pathname + (window.location.hash || ''));
      }
    }
  });
})();
