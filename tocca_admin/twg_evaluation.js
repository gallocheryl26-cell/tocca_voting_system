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

function namedEntryKindLabel(kind) {
  const k = String(kind || '').toLowerCase();
  if (k === 'artist') return 'Artist';
  if (k === 'stylist') return 'Stylist';
  return 'Product';
}

function namedEntryLineHtml(kind, names) {
  const list = (Array.isArray(names) ? names : [names])
    .map((n) => String(n || '').trim())
    .filter(Boolean);
  if (!list.length) return '';
  return `<div class="twg-product-line mt-1"><span class="fw-semibold">${escapeHtml(namedEntryKindLabel(kind))}:</span> ${list.map((n) => escapeHtml(n)).join(', ')}</div>`;
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
  }

  function clearBusinessSheet() {
    lastChoiceId = null;
    setSheetBusinessName(null);
    const cols = Math.max(3, (lastMembers.length || 0) + 3);
    if (body) {
      body.innerHTML = `<tr><td colspan="${cols}" class="text-center text-muted">Search and choose a business to load its score sheet.</td></tr>`;
    }
    setSaveVisible(false);
    document.getElementById('twgSummary')?.classList.add('d-none');
  }

  function fillBusinessSelect(filter, selectedId, autoLoadUnique) {
    if (!businessSelect) return;
    const q = String(filter || '').trim().toLowerCase();
    const keep = selectedId != null ? String(selectedId) : String(businessSelect.value || lastChoiceId || '');
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
      if (keep) clearBusinessSheet();
      return;
    }
    let kept = false;
    matched.forEach((b) => {
      const option = document.createElement('option');
      option.value = String(b.choice_id);
      const awards = Number(b.award_count) || 0;
      option.textContent = awards
        ? `${b.choice_name} (${awards} award${awards === 1 ? '' : 's'})`
        : `${b.choice_name} (no awards yet)`;
      if (keep && keep === String(b.choice_id)) {
        option.selected = true;
        kept = true;
      }
      businessSelect.appendChild(option);
    });
    if (!kept && matched.length === 1) {
      businessSelect.value = String(matched[0].choice_id);
      kept = true;
      if (autoLoadUnique && String(matched[0].choice_id) !== String(lastChoiceId || '')) {
        loadSheet();
      }
    } else if (!kept && keep) {
      clearBusinessSheet();
    }
  }

  if (currentEventId) {
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
  }

  searchEl?.addEventListener('input', () => {
    fillBusinessSelect(searchEl.value, businessSelect.value, true);
  });

  function criteriaRowHtml(row) {
    const id = Number(row?.criterion_id || row?.id || 0);
    const key = String(row?.key || '');
    const label = String(row?.label || '');
    const short = String(row?.short || row?.short_label || '');
    const weight = row?.weight != null && row.weight !== '' ? row.weight : 1;
    return `<tr data-criterion-id="${id}" data-key="${escapeHtml(key)}">
      <td><input class="form-control form-control-sm twg-crit-label" maxlength="80" value="${escapeHtml(label)}" placeholder="e.g. Taste"></td>
      <td><input class="form-control form-control-sm twg-crit-short" maxlength="32" value="${escapeHtml(short)}" placeholder="Short"></td>
      <td><input class="form-control form-control-sm twg-crit-weight" type="number" min="0.001" step="0.1" value="${escapeHtml(weight)}"></td>
      <td class="text-end"><button type="button" class="btn btn-outline-danger btn-sm twg-crit-remove" title="Remove"><i class="bi bi-x-lg"></i></button></td>
    </tr>`;
  }

  function renderCriteriaEditor(criteria) {
    const tbody = document.getElementById('twgCriteriaBody');
    if (!tbody) return;
    const rows = Array.isArray(criteria) && criteria.length ? criteria : [{ label: '', short: '', weight: 1 }];
    tbody.innerHTML = rows.map(criteriaRowHtml).join('');
    if (Array.isArray(criteria) && criteria.length) {
      lastMembers = criteria;
      const completeLabel = document.getElementById('twgStatCompleteLabel');
      if (completeLabel) {
        completeLabel.textContent = `Fully scored (${criteria.length}/${criteria.length})`;
      }
    }
  }

  function collectCriteria() {
    return [...document.querySelectorAll('#twgCriteriaBody tr')].map((tr) => ({
      criterion_id: Number(tr.getAttribute('data-criterion-id') || 0),
      key: tr.getAttribute('data-key') || '',
      label: String(tr.querySelector('.twg-crit-label')?.value || '').trim(),
      short: String(tr.querySelector('.twg-crit-short')?.value || '').trim(),
      weight: Number(tr.querySelector('.twg-crit-weight')?.value || 1),
    })).filter((row) => row.label !== '');
  }

  function loadCriteria() {
    if (!currentEventId) return;
    fetch(`twg_eval.php?action=criteria&event_id=${currentEventId}`, { credentials: 'same-origin' })
      .then((res) => res.json())
      .then((data) => {
        if (data.status === 'success') {
          renderCriteriaEditor(data.criteria || data.members || []);
          renderRubricEditor(data.rubric || []);
        }
      })
      .catch(() => {});
  }

  async function postCriteria(action, extra) {
    const res = await fetch('twg_eval.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        action,
        event_id: Number(currentEventId),
        ...(extra || {}),
      }),
    });
    const data = await res.json();
    if (!res.ok || data.status !== 'success') {
      throw new Error(data.message || 'Could not save criteria.');
    }
    renderCriteriaEditor(data.criteria || data.members || []);
    if (lastChoiceId) {
      loadSheet();
    }
    return data;
  }

  loadCriteria();

  function renderHead(members) {
    lastMembers = members;
    const memberCells = members.map((m) => {
      const w = Number(m.weight);
      const tip = Number.isFinite(w) && w > 0 ? `${m.label} · weight ${w}` : m.label;
      return `<th title="${escapeHtml(tip)}">${escapeHtml(m.short)}</th>`;
    }).join('');
    head.innerHTML = `<tr>
      <th>Award</th>
      ${memberCells}
      <th>Average</th>
      <th>Progress</th>
    </tr>`;
    const completeLabel = document.getElementById('twgStatCompleteLabel');
    if (completeLabel) {
      const n = members.length || 0;
      completeLabel.textContent = n ? `Fully scored (${n}/${n})` : 'Fully scored';
    }
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
    const sheetLocked = !!choice.on_ballot;
    setSheetBusinessName(choice);
    renderHead(members);
    const summary = document.getElementById('twgSummary');
    const complete = awards.filter((n) => Number(n.scored) >= Number(n.member_count || members.length)).length;
    const partial = awards.filter((n) => {
      const scored = Number(n.scored) || 0;
      const total = Number(n.member_count) || members.length;
      return scored > 0 && scored < total;
    }).length;
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
    function scoreInputCells(target, members, extraAttrs) {
      const scores = target.scores || {};
      const labelFor = String(target.entry_name || target.question_name || 'award');
      return members.map((m) => {
        const locked = !!(target.on_ballot || target.scores_locked);
        const saved = scores[m.key] != null && scores[m.key] !== '';
        const val = saved ? scores[m.key] : 0;
        const extra = extraAttrs || '';
        return `<td>
          <input type="number" min="0" max="100" step="0.01" inputmode="decimal" class="form-control form-control-sm twg-score-input${locked ? ' twg-score-locked' : ''}"
            value="${escapeHtml(val)}"
            ${locked ? 'readonly disabled' : ''}
            data-choice-id="${target.choice_id}"
            data-question-id="${target.question_id}"
            data-member-key="${escapeHtml(m.key)}"
            ${extra}
            title="${locked ? 'Scores are locked after this business is confirmed for public voting.' : 'Defaults to 0. Saved scores can still be edited until public voting is confirmed.'}"
            aria-label="${escapeHtml(m.label)} score for ${escapeHtml(labelFor)}">
          <div class="invalid-feedback twg-score-feedback">0–100 only</div>
        </td>`;
      }).join('');
    }

    body.innerHTML = awards.flatMap((n) => {
      const scored = Number(n.scored) || 0;
      const total = Number(n.member_count) || members.length;
      const cat = n.category_name ? `${escapeHtml(n.category_name)} · ` : '';
      const entries = Array.isArray(n.entries) ? n.entries.filter((e) => Number(e.ballot_entry_id) > 0) : [];
      if (!entries.length) {
        return [`<tr class="twg-award-row" data-question-id="${n.question_id}" data-choice-id="${n.choice_id}">
        <td>
          <div class="fw-semibold">${cat}${escapeHtml(n.question_name)}</div>
        </td>
        ${scoreInputCells({ ...n, on_ballot: sheetLocked }, members, '')}
        <td class="twg-avg fw-semibold" data-avg>${formatScore(n.average)}</td>
        <td data-progress><span class="small">${scored}/${total}</span></td>
      </tr>`];
      }
      const kind = String(n.entry_kind || entries[0].entry_kind || 'product');
      const kindLabel = namedEntryKindLabel(kind);
      const emptyMemberCells = members.map(() => '<td class="text-muted small">—</td>').join('');
      const head = `<tr class="twg-award-row twg-award-head" data-question-id="${n.question_id}" data-choice-id="${n.choice_id}">
        <td>
          <div class="fw-semibold">${cat}${escapeHtml(n.question_name)}</div>
          ${namedEntryLineHtml(kind, entries.map((e) => e.entry_name))}
          <div class="small text-muted mt-1">Score each ${escapeHtml(kindLabel.toLowerCase())} below. Award average is the mean of those scores.</div>
        </td>
        ${emptyMemberCells}
        <td class="twg-avg fw-semibold" data-avg>${formatScore(n.average)}</td>
        <td data-progress><span class="small">${scored}/${total}</span></td>
      </tr>`;
      const productRows = entries.map((entry) => {
        const eScored = Number(entry.scored) || 0;
        const eTotal = Number(entry.member_count) || members.length;
        return `<tr class="twg-entry-row" data-question-id="${n.question_id}" data-choice-id="${n.choice_id}" data-ballot-entry-id="${entry.ballot_entry_id}">
          <td>
            ${namedEntryLineHtml(kind, entry.entry_name || '')}
          </td>
          ${scoreInputCells({
            choice_id: n.choice_id,
            question_id: n.question_id,
            question_name: entry.entry_name || n.question_name,
            entry_name: entry.entry_name,
            scores: entry.scores || {},
            on_ballot: sheetLocked,
          }, members, `data-ballot-entry-id="${entry.ballot_entry_id}"`)}
          <td class="twg-avg fw-semibold" data-avg>${formatScore(entry.average)}</td>
          <td data-progress><span class="small">${eScored}/${eTotal}</span></td>
        </tr>`;
      });
      return [head, ...productRows];
    }).join('');
    refreshSaveState();
    body.querySelectorAll('tr.twg-award-row, tr.twg-entry-row').forEach((row) => refreshRowPreview(row));
  }

  function loadSheet() {
    if (!businessSelect) return;
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

  function downloadSheet(format, allScope) {
    const params = new URLSearchParams({ format });
    const awardMode = viewModeEl?.value !== 'business';
    if (!allScope) {
      if (awardMode) {
        const qid = awardQEl?.value;
        if (!qid) {
          toast('Choose an award title first, or download all awards.', false);
          return;
        }
        params.set('question_id', qid);
      } else {
        const choiceId = selectedChoiceId();
        if (!choiceId) {
          toast('Choose a business first, or download all awards.', false);
          return;
        }
        params.set('choice_id', choiceId);
      }
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
      const text = await res.text();
      let data;
      try {
        data = JSON.parse(text);
      } catch (_) {
        toast(
          res.status >= 500
            ? 'Import crashed on the server. Re-upload the latest import files, or try CSV.'
            : 'Import did not return a valid response.',
          false
        );
        return;
      }
      const extra = Array.isArray(data.errors) && data.errors.length
        ? ` ${data.errors.slice(0, 3).join(' ')}`
        : '';
      toast(`${data.message || 'Import finished.'}${extra}`, data.status === 'success');
      if (data.status === 'success') {
        if (viewModeEl?.value !== 'business' && awardQEl?.value) {
          loadAwardSheet();
        } else if (lastChoiceId) {
          loadSheet();
        }
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
        hint.innerHTML = 'This business is on the public ballot. Scores are locked and cannot be changed.';
      }
      return;
    }
    setSaveVisible(true);
    if (hint) {
      const rubricMode = body.querySelector('.twg-rubric-input');
      hint.classList.remove('d-none');
      hint.innerHTML = rubricMode
        ? 'Each item starts at <strong>0</strong>. Change only the scores that are not zero, then click <strong>Save scores</strong>. You can edit again until the business is confirmed for public voting. Feelings still skips Top 5.'
        : 'Each box starts at <strong>0</strong>. Change only the scores that are not zero, then click <strong>Save scores</strong>. You can edit again until the business is confirmed for public voting.';
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
    const rubric = input.classList.contains('twg-rubric-input');
    const min = 0;
    const max = rubric ? (Number(input.getAttribute('max')) || 100) : 100;
    const rangeText = rubric ? `0 to ${max}` : '0 to 100';
    if (!/^\d+(\.\d{1,2})?$/.test(raw)) {
      return { ok: false, empty: false, score: null, message: `Score must be a number from ${rangeText} (up to 2 decimals).` };
    }
    const score = Number(raw);
    if (!Number.isFinite(score)) {
      return { ok: false, empty: false, score: null, message: `Score must be a number from ${rangeText}.` };
    }
    if (score < min || score > max) {
      return { ok: false, empty: false, score: null, message: `Score must be from ${rangeText}.` };
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
      ? (input.disabled ? 'This score is saved and cannot be changed.' : (input.classList.contains('twg-rubric-input') ? `Enter 0–${input.getAttribute('max') || 100}.` : 'Enter a score from 0 to 100.'))
      : parsed.message;
    setScoreFeedback(input, parsed.ok ? (input.classList.contains('twg-rubric-input') ? `0–${input.getAttribute('max') || 100}` : '0–100 only') : parsed.message);
  }

  function confirmAction(options) {
    if (typeof window.adminConfirm === 'function') {
      return window.adminConfirm(options);
    }
    return Promise.resolve(window.confirm(options?.message || 'Are you sure?'));
  }

  function memberWeight(key) {
    const member = lastMembers.find((m) => String(m.key) === String(key));
    const w = Number(member?.weight);
    return Number.isFinite(w) && w > 0 ? w : 1;
  }

  function previewFromInputs(inputs) {
    let vsum = 0;
    let wsum = 0;
    let scored = 0;
    inputs.forEach((input) => {
      const parsed = parseScoreInput(input);
      if (parsed.ok && !parsed.empty) {
        const w = memberWeight(input.getAttribute('data-member-key') || '');
        vsum += parsed.score * w;
        wsum += w;
        scored += 1;
      }
    });
    return {
      avg: wsum > 0 ? vsum / wsum : null,
      scored,
      total: inputs.length,
      complete: scored === inputs.length && inputs.length > 0,
    };
  }

  function refreshRowPreview(row) {
    if (!row) return;
    const inputs = [...row.querySelectorAll('.twg-score-input')];
    if (!inputs.length) return;
    const preview = previewFromInputs(inputs);
    const avgEl = row.querySelector('[data-avg]');
    const progEl = row.querySelector('[data-progress]');
    if (avgEl) avgEl.textContent = preview.avg != null ? formatScore(preview.avg) : '—';
    if (progEl) progEl.innerHTML = `<span class="small">${preview.scored}/${preview.total || lastMembers.length || 0}</span>`;
    const qid = row.getAttribute('data-question-id');
    if (qid && row.classList.contains('twg-entry-row')) {
      refreshAwardHeadPreview(qid);
    }
  }

  function refreshAwardHeadPreview(questionId) {
    const head = body.querySelector(`tr.twg-award-head[data-question-id="${questionId}"]`);
    if (!head) return;
    const productRows = [...body.querySelectorAll(`tr.twg-entry-row[data-question-id="${questionId}"]`)];
    let scored = 0;
    let total = 0;
    let avgSum = 0;
    let avgCount = 0;
    productRows.forEach((row) => {
      const inputs = [...row.querySelectorAll('.twg-score-input')];
      const preview = previewFromInputs(inputs);
      scored += preview.scored;
      total += preview.total;
      if (preview.complete && preview.avg != null) {
        avgSum += preview.avg;
        avgCount += 1;
      }
    });
    const avgEl = head.querySelector('[data-avg]');
    const progEl = head.querySelector('[data-progress]');
    if (avgEl) avgEl.textContent = (avgCount === productRows.length && avgCount) ? formatScore(avgSum / avgCount) : '—';
    if (progEl) progEl.innerHTML = `<span class="small">${scored}/${total || lastMembers.length || 0}</span>`;
  }

  function collectSheetScores() {
    const scores = [];
    let firstBad = null;
    let invalidCount = 0;
    let firstInvalidMessage = '';
    body.querySelectorAll('.twg-score-input').forEach((input) => {
      if (input.disabled) return;
      const parsed = parseScoreInput(input);
      markScoreValidity(input, parsed, true);
      if (parsed.ok && parsed.empty) {
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
        ballot_entry_id: Number(input.getAttribute('data-ballot-entry-id') || 0),
        member_key: input.getAttribute('data-member-key') || '',
        score: parsed.score,
      });
    });
    let error = '';
    if (invalidCount) {
      error = firstInvalidMessage || 'Each score must be from 0 to 100.';
    } else if (!scores.length) {
      error = 'Enter at least one score before saving.';
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
      message: `Save ${count} score${count === 1 ? '' : 's'}? You can still edit them until this business is confirmed for public voting.`,
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
        toast(/missing/i.test(msg) ? 'Enter at least one score before saving.' : msg, false);
        if (/missing/i.test(msg)) {
          collectSheetScores();
        }
        const invalid = data.invalid || {};
        if (invalid.question_id && invalid.member_key) {
          const eid = Number(invalid.ballot_entry_id || 0);
          const sel = eid
            ? `.twg-score-input[data-question-id="${invalid.question_id}"][data-ballot-entry-id="${eid}"][data-member-key="${invalid.member_key}"]`
            : `.twg-score-input[data-question-id="${invalid.question_id}"][data-member-key="${invalid.member_key}"]:not([data-ballot-entry-id])`;
          const bad = body.querySelector(sel);
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
        const qid = row.question_id;
        const eid = Number(row.ballot_entry_id || 0);
        const tr = eid
          ? body.querySelector(`tr.twg-entry-row[data-question-id="${qid}"][data-ballot-entry-id="${eid}"]`)
          : body.querySelector(`tr.twg-award-row[data-question-id="${qid}"]`);
        if (!tr) return;
        const avgEl = tr.querySelector('[data-avg]');
        const progEl = tr.querySelector('[data-progress]');
        if (avgEl) avgEl.textContent = formatScore(row.average);
        if (progEl) progEl.innerHTML = `<span class="small">${Number(row.scored) || 0}/${Number(row.member_count) || lastMembers.length || 0}</span>`;
      });
      const complete = [...body.querySelectorAll('tr.twg-award-row')].filter((tr) => {
        const text = tr.querySelector('[data-progress]')?.textContent || '';
        const parts = text.split('/');
        return parts.length === 2 && Number(parts[0]) >= Number(parts[1]) && Number(parts[1]) > 0;
      }).length;
      const partial = [...body.querySelectorAll('tr.twg-award-row')].filter((tr) => {
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

  function refreshRubricRowTotal(row) {
    if (!row) return;
    const inputs = [...row.querySelectorAll('.twg-rubric-input')];
    if (!inputs.length) return;
    const totalEl = row.querySelector('.twg-avg');
    if (!totalEl) return;
    let sum = 0;
    inputs.forEach((input) => {
      const parsed = parseScoreInput(input);
      if (parsed.ok && !parsed.empty) sum += parsed.score;
    });
    totalEl.textContent = formatScore(sum);
  }

  body.addEventListener('input', (e) => {
    const input = e.target.closest('.twg-score-input');
    if (!input || input.disabled) return;
    markScoreValidity(input, parseScoreInput(input), sheetSubmitAttempted);
    if (input.classList.contains('twg-rubric-input')) {
      refreshRubricRowTotal(input.closest('tr'));
      return;
    }
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

  document.getElementById('twgCriteriaAdd')?.addEventListener('click', () => {
    const tbody = document.getElementById('twgCriteriaBody');
    if (!tbody) return;
    tbody.insertAdjacentHTML('beforeend', criteriaRowHtml({ label: '', short: '', weight: 1 }));
    tbody.querySelector('tr:last-child .twg-crit-label')?.focus();
  });

  document.getElementById('twgCriteriaBody')?.addEventListener('click', (e) => {
    const btn = e.target.closest('.twg-crit-remove');
    if (!btn) return;
    const tbody = document.getElementById('twgCriteriaBody');
    const rows = tbody ? tbody.querySelectorAll('tr') : [];
    if (rows.length <= 1) {
      toast('Keep at least one judge.', false);
      return;
    }
    btn.closest('tr')?.remove();
  });

  document.getElementById('twgCriteriaSave')?.addEventListener('click', async () => {
    const criteria = collectCriteria();
    if (!criteria.length) {
      toast('Add at least one judge.', false);
      return;
    }
    if (criteria.length > 12) {
      toast('Use at most 12 judges so the score sheet stays usable.', false);
      return;
    }
    const btn = document.getElementById('twgCriteriaSave');
    if (btn) btn.disabled = true;
    try {
      const data = await postCriteria('save_criteria', { criteria });
      toast(data.message || 'Criteria saved.', true);
    } catch (err) {
      toast(err.message || 'Could not save criteria.', false);
    } finally {
      if (btn) btn.disabled = false;
    }
  });

  document.getElementById('twgCriteriaRestore')?.addEventListener('click', async () => {
    const ok = await confirmAction({
      title: 'Restore default criteria',
      message: 'Restore the five default judges (LGU 1, LGU 2, BPLO, LEDIPO, ORCHAM) at equal weight? Existing scores on those keys are kept.',
      confirmLabel: 'Restore defaults',
      confirmClass: 'btn-primary',
    });
    if (!ok) return;
    const btn = document.getElementById('twgCriteriaRestore');
    if (btn) btn.disabled = true;
    try {
      const data = await postCriteria('restore_defaults');
      toast(data.message || 'Defaults restored.', true);
    } catch (err) {
      toast(err.message || 'Could not restore defaults.', false);
    } finally {
      if (btn) btn.disabled = false;
    }
  });

  const criteriaPanel = document.getElementById('twgCriteriaPanel');
  const criteriaToggle = document.getElementById('twgCriteriaToggle');
  criteriaPanel?.addEventListener('shown.bs.collapse', () => {
    if (criteriaToggle) criteriaToggle.textContent = 'Hide editor';
  });
  criteriaPanel?.addEventListener('hidden.bs.collapse', () => {
    if (criteriaToggle) criteriaToggle.textContent = 'Edit judges';
  });

  let questionsByCategory = {};
  let lastAwardSheet = null;
  let currentJudgeKey = 'overall';
  const viewModeEl = document.getElementById('twgViewMode');
  const awardCatEl = document.getElementById('twgAwardCategory');
  const awardQEl = document.getElementById('twgAwardQuestion');
  const judgeTabs = document.getElementById('twgJudgeTabs');

  function showAwardEmptyState() {
    lastAwardSheet = null;
    judgeTabs?.classList.add('d-none');
    if (head) {
      head.innerHTML = `<tr>
        <th>Entry</th>
        <th>Business</th>
        <th>Taste (50%)</th>
        <th>Innovation (20%)</th>
        <th>Value (30%)</th>
        <th>Average</th>
        <th>Ranking</th>
      </tr>`;
    }
    if (body) {
      body.innerHTML = '<tr><td colspan="7" class="text-center text-muted">Select a category and award title to load its score sheet.</td></tr>';
    }
    setSaveVisible(false);
    const nameEl = document.getElementById('twgSheetBusinessName');
    if (nameEl) {
      nameEl.className = 'd-block text-truncate mt-1 text-muted';
      nameEl.textContent = 'Choose an award title to load its score sheet.';
    }
  }

  function setViewMode(mode) {
    const award = mode !== 'business';
    document.querySelectorAll('.twg-award-filters').forEach((el) => el.classList.toggle('d-none', !award));
    document.querySelectorAll('.twg-business-filters').forEach((el) => el.classList.toggle('d-none', award));
    if (award) {
      if (awardQEl?.value) loadAwardSheet();
      else showAwardEmptyState();
    } else {
      judgeTabs?.classList.add('d-none');
      if (businessSelect?.value) {
        loadSheet();
      } else {
        if (body) {
          body.innerHTML = '<tr><td colspan="7" class="text-center text-muted">Search and choose a business to load its score sheet.</td></tr>';
        }
        setSaveVisible(false);
      }
    }
    const oneX = document.getElementById('twgExportAwardXlsx');
    const oneC = document.getElementById('twgExportAwardCsv');
    const allX = document.getElementById('twgExportAllXlsx');
    const allC = document.getElementById('twgExportAllCsv');
    if (oneX) oneX.textContent = award ? 'This award (Excel)' : 'This business (Excel)';
    if (oneC) oneC.textContent = award ? 'This award (CSV)' : 'This business (CSV)';
    if (allX) allX.textContent = award ? 'All awards (Excel)' : 'All businesses (Excel)';
    if (allC) allC.textContent = award ? 'All awards (CSV)' : 'All businesses (CSV)';
  }

  function fillAwardQuestions() {
    if (!awardQEl) return;
    const catId = String(awardCatEl?.value || '');
    const prev = awardQEl.value;
    const list = catId ? (questionsByCategory[catId] || questionsByCategory[Number(catId)] || []) : [];
    awardQEl.innerHTML = '<option value="">Select an award…</option>';
    list.forEach((q) => {
      const opt = document.createElement('option');
      opt.value = String(q.question_id);
      opt.textContent = q.question_name;
      awardQEl.appendChild(opt);
    });
    awardQEl.disabled = list.length === 0;
    if (prev && [...awardQEl.options].some((o) => o.value === prev)) awardQEl.value = prev;
  }

  function applyFilterSeed(data) {
    if (!data || !awardCatEl) return;
    questionsByCategory = data.questions_by_category || {};
    const keep = String(awardCatEl.value || '');
    awardCatEl.innerHTML = '<option value="">Select category…</option>';
    (data.categories || []).forEach((cat) => {
      const opt = document.createElement('option');
      opt.value = String(cat.category_id);
      opt.textContent = cat.category_name;
      awardCatEl.appendChild(opt);
    });
    if (keep && [...awardCatEl.options].some((o) => o.value === keep)) {
      awardCatEl.value = keep;
    }
    fillAwardQuestions();
  }

  function renderRubricEditor(items) {
    const tbody = document.getElementById('twgRubricBody');
    if (!tbody) return;
    const rows = Array.isArray(items) && items.length ? items : twg_rubric_fallback();
    tbody.innerHTML = rows.map((row) => {
      const max = row.max != null ? row.max : (row.max_points != null ? row.max_points : 10);
      return `<tr data-key="${escapeHtml(row.key || '')}">
        <td><input class="form-control form-control-sm twg-rubric-label" maxlength="80" value="${escapeHtml(row.label || '')}"></td>
        <td><input class="form-control form-control-sm twg-rubric-short" maxlength="32" value="${escapeHtml(row.short || '')}"></td>
        <td><input class="form-control form-control-sm twg-rubric-max" type="number" min="0.1" step="1" value="${escapeHtml(max)}"></td>
        <td class="text-end"><button type="button" class="btn btn-outline-danger btn-sm twg-rubric-remove" title="Remove"><i class="bi bi-x-lg"></i></button></td>
      </tr>`;
    }).join('');
  }

  function twg_rubric_fallback() {
    return [
      { key: 'taste', label: 'Taste & Quality', short: 'Taste', max: 50 },
      { key: 'innovation', label: 'Innovation', short: 'Innovation', max: 20 },
      { key: 'value', label: 'Portion Size & Value for Money', short: 'Value', max: 30 },
    ];
  }

  function collectRubric() {
    return [...document.querySelectorAll('#twgRubricBody tr')].map((tr) => ({
      key: tr.getAttribute('data-key') || '',
      label: String(tr.querySelector('.twg-rubric-label')?.value || '').trim(),
      short: String(tr.querySelector('.twg-rubric-short')?.value || '').trim(),
      max: Number(tr.querySelector('.twg-rubric-max')?.value || 0),
    })).filter((row) => row.label !== '');
  }

  function renderJudgeTabs(members) {
    if (!judgeTabs) return;
    const list = Array.isArray(members) ? members : [];
    const tabs = list.map((m, i) => ({
      key: m.key,
      label: `Judge ${i + 1}`,
      title: m.label || m.short || '',
    })).concat([{ key: 'overall', label: 'Overall Score' }]);
    if (!tabs.some((t) => t.key === currentJudgeKey)) currentJudgeKey = 'overall';
    judgeTabs.innerHTML = tabs.map((t) => (
      `<li class="nav-item" role="presentation">
        <button class="nav-link${t.key === currentJudgeKey ? ' active' : ''}" type="button" data-judge-key="${escapeHtml(t.key)}" title="${escapeHtml(t.title || t.label)}">${escapeHtml(t.label)}</button>
      </li>`
    )).join('');
    judgeTabs.classList.remove('d-none');
  }

  function renderAwardSheet() {
    const data = lastAwardSheet;
    if (!data || !data.award) {
      body.innerHTML = '<tr><td colspan="8" class="text-center text-muted">Select a category and award title.</td></tr>';
      setSaveVisible(false);
      return;
    }
    const members = data.members || [];
    const rubric = data.rubric || [];
    const rows = data.rows || [];
    lastMembers = members;
    renderJudgeTabs(members);
    const award = data.award;
    const nameEl = document.getElementById('twgSheetBusinessName');
    if (nameEl) {
      nameEl.className = 'd-block text-truncate mt-1';
      nameEl.innerHTML = `<span class="fs-5 fw-semibold">${escapeHtml(award.category_name || '')} · ${escapeHtml(award.question_name || '')}</span>`;
    }
    const summary = document.getElementById('twgSummary');
    if (summary) {
      summary.classList.remove('d-none');
      const complete = rows.filter((r) => r.average != null).length;
      const partial = rows.filter((r) => r.average == null && Object.values(r.judges || {}).some((j) => j && (j.total != null || Object.keys(j.items || {}).length))).length;
      const nEl = document.getElementById('twgStatNominees');
      const cEl = document.getElementById('twgStatComplete');
      const pEl = document.getElementById('twgStatPartial');
      if (nEl) nEl.textContent = String(rows.length);
      if (cEl) cEl.textContent = String(complete);
      if (pEl) pEl.textContent = String(partial);
    }

    if (currentJudgeKey === 'overall') {
      head.innerHTML = `<tr>
        <th>Entry No.</th>
        <th>Business Name</th>
        ${members.map((m, i) => `<th title="${escapeHtml(m.label || m.short || '')}">Judge No. ${i + 1}</th>`).join('')}
        <th>Total Weighted Score</th>
        <th>Average Weighted Score</th>
        <th>Ranking</th>
      </tr>`;
      if (!rows.length) {
        body.innerHTML = `<tr><td colspan="${members.length + 5}" class="text-center text-muted">No businesses are linked to this award yet.</td></tr>`;
        setSaveVisible(false);
        return;
      }
      body.innerHTML = rows.map((row, idx) => {
        const extra = namedEntryLineHtml(row.entry_kind || 'product', row.entry_name);
        const judgeTotals = members.map((m) => {
          const total = row.judges?.[m.key]?.total;
          return total == null || total === '' ? null : Number(total);
        });
        const filled = judgeTotals.filter((n) => n != null);
        const weighted = filled.length ? filled.reduce((sum, n) => sum + n, 0) : null;
        const judgeCells = judgeTotals.map((total) => (
          `<td class="twg-avg">${total == null ? '<span class="text-muted">—</span>' : formatScore(total)}</td>`
        )).join('');
        const rank = row.rank ? `<span class="badge rounded-pill text-bg-secondary">${row.rank}</span>` : '<span class="text-muted">—</span>';
        const top = row.in_top5 ? ' <span class="badge rounded-pill text-bg-warning">Top 5</span>' : '';
        return `<tr>
          <td>${idx + 1}</td>
          <td><div class="fw-semibold">${escapeHtml(row.choice_name || '')}</div>${extra}${top}</td>
          ${judgeCells}
          <td class="fw-semibold">${weighted == null ? '—' : formatScore(weighted)}</td>
          <td class="fw-semibold">${row.average == null ? '—' : formatScore(row.average)}</td>
          <td>${rank}</td>
        </tr>`;
      }).join('');
      setSaveVisible(false);
      return;
    }

    const judge = members.find((m) => String(m.key) === String(currentJudgeKey));
    head.innerHTML = `<tr>
      <th>Entry No.</th>
      <th>Business Name</th>
      ${rubric.map((item) => {
        const max = Number(item.max || 0);
        const pct = max > 0 ? ` (${Math.round(max)})` : '';
        return `<th>${escapeHtml(item.short || item.label)}${pct}</th>`;
      }).join('')}
      <th>Total Score</th>
    </tr>`;
    if (!rows.length) {
      body.innerHTML = `<tr><td colspan="${rubric.length + 3}" class="text-center text-muted">No businesses are linked to this award yet.</td></tr>`;
      setSaveVisible(false);
      return;
    }
    body.innerHTML = rows.map((row, idx) => {
      const extra = namedEntryLineHtml(row.entry_kind || 'product', row.entry_name);
      const block = row.judges?.[currentJudgeKey] || { items: {}, total: null };
      const cells = rubric.map((item) => {
        const val = block.items?.[item.key];
        const locked = !!row.on_ballot;
        const saved = val != null && val !== '';
        const max = Number(item.max || 0);
        return `<td>
          <input type="number" min="0" max="${max}" step="0.01" inputmode="decimal"
            class="form-control form-control-sm twg-score-input twg-rubric-input${locked ? ' twg-score-locked' : ''}"
            value="${escapeHtml(saved ? val : 0)}"
            ${locked ? 'readonly disabled' : ''}
            data-choice-id="${row.choice_id}"
            data-question-id="${award.question_id}"
            data-ballot-entry-id="${row.ballot_entry_id || 0}"
            data-member-key="${escapeHtml(currentJudgeKey)}"
            data-item-key="${escapeHtml(item.key)}"
            title="${locked ? 'Scores are locked after this business is confirmed for public voting.' : `Defaults to 0. Saved scores can still be edited until public voting is confirmed.`}">
        </td>`;
      }).join('');
      const thisTotal = block.total;
      return `<tr>
        <td>${idx + 1}</td>
        <td><div class="fw-semibold">${escapeHtml(row.choice_name || '')}</div>${extra}</td>
        ${cells}
        <td class="fw-semibold twg-avg">${thisTotal == null ? '—' : formatScore(thisTotal)}</td>
      </tr>`;
    }).join('');
    refreshSaveState();
    body.querySelectorAll('tr').forEach((row) => refreshRubricRowTotal(row));
  }

  async function loadAwardSheet() {
    const qid = awardQEl?.value;
    if (!qid) {
      lastAwardSheet = null;
      renderAwardSheet();
      return;
    }
    try {
      const res = await fetch(`twg_eval.php?event_id=${currentEventId}&action=award_sheet&question_id=${encodeURIComponent(qid)}`, { credentials: 'same-origin' });
      const data = await res.json();
      if (data.status !== 'success') {
        toast(data.message || 'Could not load the award sheet.', false);
        return;
      }
      lastAwardSheet = data;
      renderAwardSheet();
    } catch (err) {
      toast(err.message || 'Could not load the award sheet.', false);
    }
  }

  async function saveRubricSheet() {
    const inputs = [...body.querySelectorAll('.twg-rubric-input')].filter((el) => !el.disabled);
    const scores = [];
    let firstBad = null;
    for (const input of inputs) {
      const raw = String(input.value || '').trim();
      if (raw === '') continue;
      const max = Number(input.getAttribute('max') || 0);
      const n = Number(raw);
      if (!Number.isFinite(n) || n < 0 || (max > 0 && n > max)) {
        firstBad = input;
        input.classList.add('is-invalid');
        continue;
      }
      input.classList.remove('is-invalid');
      scores.push({
        question_id: Number(input.getAttribute('data-question-id') || 0),
        choice_id: Number(input.getAttribute('data-choice-id') || 0),
        ballot_entry_id: Number(input.getAttribute('data-ballot-entry-id') || 0),
        judge_key: input.getAttribute('data-member-key') || '',
        item_key: input.getAttribute('data-item-key') || '',
        score: n,
      });
    }
    if (firstBad) {
      toast('Check the highlighted scores.', false);
      firstBad.focus();
      return;
    }
    if (!scores.length) {
      toast('Enter at least one score before saving.', false);
      return;
    }
    const ok = await confirmAction({
      title: 'Save TWG scores',
      message: `Save ${scores.length} score${scores.length === 1 ? '' : 's'}? You can still edit them until the business is confirmed for public voting.`,
      confirmLabel: 'Save scores',
      confirmClass: 'btn-primary',
    });
    if (!ok) return;
    const saveBtn = document.getElementById('twgSaveBtnBottom');
    if (saveBtn) saveBtn.disabled = true;
    try {
      const res = await fetch('twg_eval.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ action: 'save_rubric', event_id: currentEventId, scores }),
      });
      const data = await res.json();
      toast(data.message || 'Saved.', data.status === 'success');
      if (data.status === 'success') loadAwardSheet();
    } catch (err) {
      toast(err.message || 'Could not save scores.', false);
    } finally {
      if (saveBtn) saveBtn.disabled = false;
    }
  }

  document.getElementById('twgSaveBtnBottom')?.addEventListener('click', (e) => {
    if (viewModeEl?.value !== 'business' && currentJudgeKey !== 'overall') {
      e.stopImmediatePropagation();
      saveRubricSheet();
    }
  }, true);

  viewModeEl?.addEventListener('change', () => setViewMode(viewModeEl.value));
  awardCatEl?.addEventListener('change', () => {
    fillAwardQuestions();
    lastAwardSheet = null;
    loadAwardSheet();
  });
  awardQEl?.addEventListener('change', () => {
    currentJudgeKey = 'overall';
    loadAwardSheet();
  });
  judgeTabs?.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-judge-key]');
    if (!btn) return;
    currentJudgeKey = btn.getAttribute('data-judge-key') || 'overall';
    renderAwardSheet();
  });

  document.getElementById('twgRubricAdd')?.addEventListener('click', () => {
    const tbody = document.getElementById('twgRubricBody');
    if (!tbody) return;
    tbody.insertAdjacentHTML('beforeend', `<tr data-key="">
      <td><input class="form-control form-control-sm twg-rubric-label" maxlength="80" value=""></td>
      <td><input class="form-control form-control-sm twg-rubric-short" maxlength="32" value=""></td>
      <td><input class="form-control form-control-sm twg-rubric-max" type="number" min="0.1" step="1" value="10"></td>
      <td class="text-end"><button type="button" class="btn btn-outline-danger btn-sm twg-rubric-remove"><i class="bi bi-x-lg"></i></button></td>
    </tr>`);
  });
  document.getElementById('twgRubricBody')?.addEventListener('click', (e) => {
    const btn = e.target.closest('.twg-rubric-remove');
    if (!btn) return;
    const tbody = document.getElementById('twgRubricBody');
    if ((tbody?.querySelectorAll('tr') || []).length <= 1) {
      toast('Keep at least one scoring item.', false);
      return;
    }
    btn.closest('tr')?.remove();
  });
  document.getElementById('twgRubricSave')?.addEventListener('click', async () => {
    const rubric = collectRubric();
    if (!rubric.length) {
      toast('Add at least one scoring item.', false);
      return;
    }
    const btn = document.getElementById('twgRubricSave');
    if (btn) btn.disabled = true;
    try {
      const res = await fetch('twg_eval.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ action: 'save_rubric_items', event_id: currentEventId, rubric }),
      });
      const data = await res.json();
      toast(data.message || 'Saved.', data.status === 'success');
      if (data.status === 'success') renderRubricEditor(data.rubric || rubric);
    } catch (err) {
      toast(err.message || 'Could not save scoring items.', false);
    } finally {
      if (btn) btn.disabled = false;
    }
  });
  const rubricPanel = document.getElementById('twgRubricPanel');
  const rubricToggle = document.getElementById('twgRubricToggle');
  rubricPanel?.addEventListener('shown.bs.collapse', () => {
    if (rubricToggle) rubricToggle.textContent = 'Hide scoring items';
  });
  rubricPanel?.addEventListener('hidden.bs.collapse', () => {
    if (rubricToggle) rubricToggle.textContent = 'Edit scoring items';
  });

  if (typeof twgFilterSeed === 'object' && twgFilterSeed) {
    applyFilterSeed(twgFilterSeed);
  }
  if (currentEventId) {
    fetch(`twg_eval.php?action=filters&event_id=${currentEventId}`, { credentials: 'same-origin' })
      .then((res) => res.json())
      .then((data) => {
        if (data.status !== 'success') {
          if (!(twgFilterSeed?.categories || []).length) {
            toast(data.message || 'Could not load award titles.', false);
          }
          return;
        }
        applyFilterSeed(data);
      })
      .catch(() => {
        if (!(twgFilterSeed?.categories || []).length) {
          toast('Could not load award titles.', false);
        }
      });
  }

  const presetChoice = new URLSearchParams(window.location.search).get('choice_id');
  if (presetChoice && viewModeEl) viewModeEl.value = 'business';
  setViewMode(viewModeEl?.value || 'award');
});
