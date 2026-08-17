let allBusinesses = [];
let lastMembers = [];
let lastChoiceId = null;
let sheetSubmitAttempted = false;

function toast(msg, ok) {
  const el = document.getElementById('toastMsg');
  const body = document.getElementById('toastBody');
  if (!el || !body) return;
  el.classList.remove('text-bg-success', 'text-bg-danger');
  el.classList.add(ok ? 'text-bg-success' : 'text-bg-danger');
  body.textContent = String(msg || '');
  try {
    bootstrap.Toast.getOrCreateInstance(el).show();
  } catch (_) {}
}

function escapeHtml(s) {
  return String(s ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function formatScore(n) {
  const v = Number(n);
  if (!Number.isFinite(v)) return '—';
  return v.toFixed(2);
}

document.addEventListener('DOMContentLoaded', () => {
  const searchEl = document.getElementById('twgBusinessSearch');
  const businessSelect = document.getElementById('twgBusinessSelect');
  const head = document.getElementById('twgSheetHead');
  const body = document.getElementById('twgSheetBody');

  if (!currentEventId) {
    if (businessSelect) {
      businessSelect.innerHTML = '<option value="" disabled selected>No active event</option>';
    }
    return;
  }

  function fillBusinessSelect(filter, selectedId) {
    const q = String(filter || '').trim().toLowerCase();
    const keep = selectedId != null ? String(selectedId) : String(businessSelect.value || '');
    const matched = allBusinesses.filter((b) => {
      if (!q) return true;
      return String(b.choice_name || '').toLowerCase().includes(q);
    });
    businessSelect.innerHTML = '<option value="" disabled selected>Choose a business</option>';
    if (!matched.length) {
      const empty = document.createElement('option');
      empty.value = '';
      empty.disabled = true;
      empty.textContent = q ? 'No matching businesses' : 'No registered businesses yet';
      businessSelect.appendChild(empty);
      return;
    }
    matched.forEach((b) => {
      const option = document.createElement('option');
      option.value = String(b.choice_id);
      const awards = Number(b.award_count) || 0;
      option.textContent = awards
        ? `${b.choice_name} (${awards} award${awards === 1 ? '' : 's'})`
        : `${b.choice_name} (no awards yet)`;
      if (keep && keep === String(b.choice_id)) option.selected = true;
      businessSelect.appendChild(option);
    });
  }

  fetch(`twg_eval.php?action=businesses&event_id=${currentEventId}`, { credentials: 'same-origin' })
    .then((res) => res.json())
    .then((data) => {
      if (data.status !== 'success') {
        businessSelect.innerHTML = '<option value="" disabled selected>Could not load businesses</option>';
        return;
      }
      allBusinesses = Array.isArray(data.businesses) ? data.businesses : [];
      const preset = new URLSearchParams(window.location.search).get('choice_id');
      fillBusinessSelect('', preset);
      if (preset && businessSelect.value === String(preset)) {
        loadSheet();
      }
    })
    .catch(() => {
      businessSelect.innerHTML = '<option value="" disabled selected>Could not load businesses</option>';
    });

  searchEl?.addEventListener('input', () => {
    fillBusinessSelect(searchEl.value, businessSelect.value);
  });

  function renderHead(members) {
    lastMembers = members;
    const memberCells = members.map((m) => `<th title="${escapeHtml(m.label)}">${escapeHtml(m.short)}</th>`).join('');
    head.innerHTML = `<tr>
      <th>Award</th>
      ${memberCells}
      <th>Average</th>
      <th>Progress</th>
    </tr>`;
  }

  function setSheetBusinessName(choice) {
    const el = document.getElementById('twgSheetBusinessName');
    if (!el) return;
    const name = String(choice?.choice_name || '').trim();
    if (!name) {
      el.className = 'd-block text-truncate mt-1 text-muted';
      el.textContent = 'Choose a business to load its score sheet.';
      return;
    }
    const onBallot = !!choice.on_ballot;
    const badge = onBallot
      ? '<span class="badge rounded-pill text-bg-success ms-2">On ballot</span>'
      : '<span class="badge rounded-pill text-bg-light border text-muted ms-2">Under evaluation</span>';
    el.className = 'd-block text-truncate mt-1';
    el.innerHTML = `<span class="fs-5 fw-semibold">${escapeHtml(name)}</span>${badge}`;
  }

  function renderSheet(payload) {
    const members = payload.members || [];
    const awards = payload.awards || [];
    const choice = payload.choice || {};
    setSheetBusinessName(choice);
    renderHead(members);
    const summary = document.getElementById('twgSummary');
    const complete = awards.filter((n) => Number(n.scored) >= members.length).length;
    const partial = awards.filter((n) => Number(n.scored) > 0 && Number(n.scored) < members.length).length;
    if (summary) {
      summary.classList.remove('d-none');
      const nEl = document.getElementById('twgStatNominees');
      const cEl = document.getElementById('twgStatComplete');
      const pEl = document.getElementById('twgStatPartial');
      if (nEl) nEl.textContent = String(awards.length);
      if (cEl) cEl.textContent = String(complete);
      if (pEl) pEl.textContent = String(partial);
    }
    if (!awards.length) {
      const name = choice.choice_name ? escapeHtml(choice.choice_name) : 'this business';
      body.innerHTML = `<tr><td colspan="${members.length + 3}" class="text-center text-muted">No award titles are linked to ${name} yet.</td></tr>`;
      setSaveVisible(false);
      return;
    }
    body.innerHTML = awards.map((n) => {
      const inputs = members.map((m) => {
        const val = n.scores && n.scores[m.key] != null ? n.scores[m.key] : '';
        const locked = val !== '';
        return `<td>
          <input type="number" min="1" max="10" step="0.01" inputmode="decimal" class="form-control form-control-sm twg-score-input${locked ? ' twg-score-locked' : ''}"
            value="${escapeHtml(val)}"
            ${locked ? 'readonly disabled' : ''}
            data-choice-id="${n.choice_id}"
            data-question-id="${n.question_id}"
            data-member-key="${escapeHtml(m.key)}"
            title="${locked ? 'This score is saved and cannot be changed.' : 'Enter a score from 1 to 10.'}"
            aria-label="${escapeHtml(m.label)} score for ${escapeHtml(n.question_name)}">
          <div class="invalid-feedback twg-score-feedback">1–10 only</div>
        </td>`;
      }).join('');
      const scored = Number(n.scored) || 0;
      const total = Number(n.member_count) || members.length;
      const cat = n.category_name ? `${escapeHtml(n.category_name)} · ` : '';
      return `<tr data-question-id="${n.question_id}" data-choice-id="${n.choice_id}">
        <td>
          <div class="fw-semibold">${cat}${escapeHtml(n.question_name)}</div>
        </td>
        ${inputs}
        <td class="twg-avg fw-semibold" data-avg>${formatScore(n.average)}</td>
        <td data-progress><span class="small">${scored}/${total}</span></td>
      </tr>`;
    }).join('');
    refreshSaveState();
  }

  function loadSheet() {
    const choiceId = businessSelect.value;
    if (!choiceId) {
      toast('Search and choose a business first.', false);
      return;
    }
    lastChoiceId = choiceId;
    sheetSubmitAttempted = false;
    fetch(`twg_eval.php?event_id=${currentEventId}&choice_id=${encodeURIComponent(choiceId)}`, { credentials: 'same-origin' })
      .then(async (res) => {
        const text = await res.text();
        try {
          return JSON.parse(text);
        } catch (_) {
          throw new Error('Could not load the score sheet.');
        }
      })
      .then((data) => {
        if (data.status !== 'success') {
          toast(data.message || 'Could not load the score sheet.', false);
          return;
        }
        renderSheet(data);
      })
      .catch((err) => toast(err.message || 'Could not load the score sheet.', false));
  }

  businessSelect.addEventListener('change', loadSheet);

  function selectedChoiceId() {
    return String(businessSelect.value || lastChoiceId || '');
  }

  function downloadSheet(format, allBusinessesExport) {
    const params = new URLSearchParams({ format });
    if (!allBusinessesExport) {
      const choiceId = selectedChoiceId();
      if (!choiceId) {
        toast('Choose a business first, or download all businesses.', false);
        return;
      }
      params.set('choice_id', choiceId);
    }
    window.location.href = `twg_sheet_export.php?${params.toString()}`;
  }

  document.getElementById('twgExportAwardXlsx')?.addEventListener('click', () => downloadSheet('xlsx', false));
  document.getElementById('twgExportAwardCsv')?.addEventListener('click', () => downloadSheet('csv', false));
  document.getElementById('twgExportAllXlsx')?.addEventListener('click', () => downloadSheet('xlsx', true));
  document.getElementById('twgExportAllCsv')?.addEventListener('click', () => downloadSheet('csv', true));

  const importBtn = document.getElementById('twgImportBtn');
  const importFile = document.getElementById('twgImportFile');
  importBtn?.addEventListener('click', () => importFile?.click());
  importFile?.addEventListener('change', async () => {
    const file = importFile.files && importFile.files[0];
    if (!file) return;
    const bodyData = new FormData();
    bodyData.append('scoresheet', file);
    importBtn.disabled = true;
    try {
      const res = await fetch('twg_sheet_import.php', {
        method: 'POST',
        credentials: 'same-origin',
        body: bodyData,
      });
      const data = await res.json();
      const extra = Array.isArray(data.errors) && data.errors.length
        ? ` ${data.errors.slice(0, 3).join(' ')}`
        : '';
      toast(`${data.message || 'Import finished.'}${extra}`, data.status === 'success');
      if (data.status === 'success' && lastChoiceId) {
        loadSheet();
      }
    } catch (err) {
      toast(err.message || 'Could not import the scoresheet.', false);
    } finally {
      importBtn.disabled = false;
      importFile.value = '';
    }
  });

  function lockScoreInput(input) {
    if (!input) return;
    input.readOnly = true;
    input.disabled = true;
    input.classList.add('twg-score-locked');
    input.classList.remove('is-invalid');
    input.title = 'This score is saved and cannot be changed.';
    input.setAttribute('aria-invalid', 'false');
  }

  function editableScoreInputs() {
    return [...body.querySelectorAll('.twg-score-input')].filter((input) => !input.disabled);
  }

  function refreshSaveState() {
    const editable = editableScoreInputs();
    const hint = document.getElementById('twgSaveHint');
    if (!editable.length) {
      setSaveVisible(false);
      if (hint && body.querySelector('.twg-score-input')) {
        hint.classList.remove('d-none');
        hint.innerHTML = 'All scores on this sheet are saved and locked. They cannot be changed.';
      }
      return;
    }
    setSaveVisible(true);
    if (hint) {
      hint.innerHTML = 'Enter scores from <strong>1 to 10</strong> for every member on this sheet, then click <strong>Save scores</strong>. Saving is blocked while any score is missing. After save, those scores are locked and cannot be changed.';
    }
  }

  function setSaveVisible(show) {
    document.getElementById('twgSaveBar')?.classList.toggle('d-none', !show);
    document.getElementById('twgSaveHint')?.classList.toggle('d-none', !show);
  }

  function parseScoreInput(input) {
    const raw = String(input.value || '').trim();
    if (raw === '') {
      return { ok: true, empty: true, score: null, message: '' };
    }
    if (!/^\d+(\.\d{1,2})?$/.test(raw)) {
      return { ok: false, empty: false, score: null, message: 'Score must be a number from 1 to 10 (up to 2 decimals).' };
    }
    const score = Number(raw);
    if (!Number.isFinite(score)) {
      return { ok: false, empty: false, score: null, message: 'Score must be a number from 1 to 10.' };
    }
    if (score < 1 || score > 10) {
      return { ok: false, empty: false, score: null, message: 'Score must be from 1 to 10.' };
    }
    return { ok: true, empty: false, score: Math.round(score * 100) / 100, message: '' };
  }

  function setScoreFeedback(input, message) {
    const feedback = input.parentElement?.querySelector('.twg-score-feedback');
    if (feedback) feedback.textContent = message;
  }

  function markScoreValidity(input, parsed, requireComplete = false) {
    const missing = requireComplete && parsed.ok && parsed.empty;
    const invalid = !parsed.ok || missing;
    input.classList.toggle('is-invalid', invalid);
    input.setAttribute('aria-invalid', invalid ? 'true' : 'false');
    if (missing) {
      input.title = 'Please complete this score.';
      setScoreFeedback(input, 'Please complete this score.');
      return;
    }
    input.title = parsed.ok
      ? (input.disabled ? 'This score is saved and cannot be changed.' : 'Enter a score from 1 to 10.')
      : parsed.message;
    setScoreFeedback(input, parsed.ok ? '1–10 only' : parsed.message);
  }

  function confirmAction(options) {
    if (typeof window.adminConfirm === 'function') {
      return window.adminConfirm(options);
    }
    return Promise.resolve(window.confirm(options?.message || 'Are you sure?'));
  }

  function refreshRowPreview(row) {
    if (!row) return;
    const inputs = [...row.querySelectorAll('.twg-score-input')];
    let sum = 0;
    let scored = 0;
    inputs.forEach((input) => {
      const parsed = parseScoreInput(input);
      if (parsed.ok && !parsed.empty) {
        sum += parsed.score;
        scored += 1;
      }
    });
    const avgEl = row.querySelector('[data-avg]');
    const progEl = row.querySelector('[data-progress]');
    if (avgEl) avgEl.textContent = scored ? formatScore(sum / scored) : '—';
    if (progEl) progEl.innerHTML = `<span class="small">${scored}/${inputs.length || 5}</span>`;
  }

  function collectSheetScores() {
    const scores = [];
    let firstBad = null;
    let missingCount = 0;
    let invalidCount = 0;
    let firstInvalidMessage = '';
    body.querySelectorAll('.twg-score-input').forEach((input) => {
      if (input.disabled) return;
      const parsed = parseScoreInput(input);
      markScoreValidity(input, parsed, true);
      if (parsed.ok && parsed.empty) {
        missingCount += 1;
        if (!firstBad) firstBad = input;
        return;
      }
      if (!parsed.ok) {
        invalidCount += 1;
        if (!firstBad) firstBad = input;
        if (!firstInvalidMessage) firstInvalidMessage = parsed.message;
        return;
      }
      scores.push({
        question_id: Number(input.getAttribute('data-question-id') || 0),
        choice_id: Number(input.getAttribute('data-choice-id') || lastChoiceId || 0),
        member_key: input.getAttribute('data-member-key') || '',
        score: parsed.score,
      });
    });
    let error = '';
    if (missingCount) {
      error = 'Please complete the scores.';
    } else if (invalidCount) {
      error = firstInvalidMessage || 'Each score must be from 1 to 10.';
    } else if (!scores.length) {
      error = 'Please complete the scores.';
    }
    return { scores, firstBad, error, ok: error === '' && scores.length > 0 };
  }

  async function saveSheet() {
    if (!lastChoiceId) {
      toast('Search and choose a business first.', false);
      return;
    }
    sheetSubmitAttempted = true;
    const collected = collectSheetScores();
    if (!collected.ok) {
      toast(collected.error || 'Please complete the scores.', false);
      (collected.firstBad || editableScoreInputs()[0])?.focus();
      return;
    }

    const count = collected.scores.length;
    const ok = await confirmAction({
      title: 'Save TWG scores',
      message: `Save ${count} score${count === 1 ? '' : 's'}? Saved scores are locked and cannot be changed.`,
      confirmLabel: 'Save scores',
      confirmClass: 'btn-primary',
    });
    if (!ok) return;

    const saveBtns = [document.getElementById('twgSaveBtnBottom')];
    saveBtns.forEach((btn) => {
      if (btn) btn.disabled = true;
    });
    try {
      const res = await fetch('twg_eval.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
          action: 'save_sheet',
          choice_id: Number(lastChoiceId),
          scores: collected.scores,
        }),
      });
      const data = await res.json();
      if (!res.ok || data.status !== 'success') {
        const msg = data.message || 'Could not save scores.';
        toast(/missing/i.test(msg) ? 'Please complete the scores.' : msg, false);
        if (/missing/i.test(msg)) {
          collectSheetScores();
        }
        const invalid = data.invalid || {};
        if (invalid.question_id && invalid.member_key) {
          const bad = body.querySelector(
            `.twg-score-input[data-question-id="${invalid.question_id}"][data-member-key="${invalid.member_key}"]`
          );
          if (bad) {
            bad.classList.add('is-invalid');
            bad.focus();
          }
        } else {
          editableScoreInputs().find((input) => input.classList.contains('is-invalid'))?.focus();
        }
        return;
      }
      (Array.isArray(data.rows) ? data.rows : []).forEach((row) => {
        const tr = body.querySelector(`tr[data-question-id="${row.question_id}"]`);
        if (!tr) return;
        const avgEl = tr.querySelector('[data-avg]');
        const progEl = tr.querySelector('[data-progress]');
        if (avgEl) avgEl.textContent = formatScore(row.average);
        if (progEl) progEl.innerHTML = `<span class="small">${Number(row.scored) || 0}/${Number(row.member_count) || 5}</span>`;
      });
      const complete = [...body.querySelectorAll('tr[data-question-id]')].filter((tr) => {
        const text = tr.querySelector('[data-progress]')?.textContent || '';
        const parts = text.split('/');
        return parts.length === 2 && Number(parts[0]) >= Number(parts[1]);
      }).length;
      const partial = [...body.querySelectorAll('tr[data-question-id]')].filter((tr) => {
        const text = tr.querySelector('[data-progress]')?.textContent || '';
        const parts = text.split('/');
        const scored = Number(parts[0]);
        const total = Number(parts[1]);
        return scored > 0 && scored < total;
      }).length;
      const cEl = document.getElementById('twgStatComplete');
      const pEl = document.getElementById('twgStatPartial');
      if (cEl) cEl.textContent = String(complete);
      if (pEl) pEl.textContent = String(partial);
      toast(data.message || 'Scores saved.', true);
      sheetSubmitAttempted = false;
      collected.scores.forEach((cell) => {
        const input = body.querySelector(
          `.twg-score-input[data-question-id="${cell.question_id}"][data-member-key="${cell.member_key}"]`
        );
        lockScoreInput(input);
      });
      refreshSaveState();
    } catch (err) {
      toast(err.message || 'Could not save scores.', false);
    } finally {
      saveBtns.forEach((btn) => {
        if (btn) btn.disabled = false;
      });
      refreshSaveState();
    }
  }

  body.addEventListener('input', (e) => {
    const input = e.target.closest('.twg-score-input');
    if (!input || input.disabled) return;
    markScoreValidity(input, parseScoreInput(input), sheetSubmitAttempted);
    refreshRowPreview(input.closest('tr'));
  });

  body.addEventListener('blur', (e) => {
    const input = e.target.closest('.twg-score-input');
    if (!input || input.disabled) return;
    const parsed = parseScoreInput(input);
    markScoreValidity(input, parsed, sheetSubmitAttempted);
    if (!parsed.ok) {
      toast(parsed.message, false);
    }
  }, true);

  document.getElementById('twgSaveBtnBottom')?.addEventListener('click', saveSheet);
});
