let questionsByCategory = {};
let lastMembers = [];
let lastQuestionId = null;

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
  const categoryDropdown = document.getElementById('twgCategoryDropdown');
  const questionDropdown = document.getElementById('twgQuestionDropdown');
  const loadBtn = document.getElementById('twgLoadBtn');
  const head = document.getElementById('twgSheetHead');
  const body = document.getElementById('twgSheetBody');

  if (!currentEventId) {
    if (categoryDropdown) {
      categoryDropdown.innerHTML = '<option value="" disabled selected>No active event</option>';
    }
    return;
  }

  fetch(`result.php?event_id=${currentEventId}`, { credentials: 'same-origin' })
    .then((res) => res.json())
    .then((data) => {
      if (data.status !== 'success') return;
      categoryDropdown.innerHTML = '<option value="" disabled selected>Choose a category</option>';
      (data.categories || []).forEach((cat) => {
        const option = document.createElement('option');
        option.value = cat.category_id;
        option.textContent = cat.category_name;
        categoryDropdown.appendChild(option);
      });
      questionsByCategory = data.questions_by_category || {};
      const presetQuestion = new URLSearchParams(window.location.search).get('question_id');
      if (presetQuestion && selectAward(presetQuestion)) {
        loadSheet();
      }
    })
    .catch(() => {
      categoryDropdown.innerHTML = '<option value="" disabled selected>Failed to load categories</option>';
    });

  function fillAwards(categoryId, selectedQuestionId) {
    const related = questionsByCategory[categoryId] || [];
    questionDropdown.innerHTML = '<option value="" disabled selected>Choose an award</option>';
    related.forEach((q) => {
      const option = document.createElement('option');
      option.value = q.question_id;
      option.textContent = q.question_name;
      if (selectedQuestionId && String(q.question_id) === String(selectedQuestionId)) {
        option.selected = true;
      }
      questionDropdown.appendChild(option);
    });
  }

  function selectAward(questionId) {
    const qid = String(questionId || '');
    if (!qid) return false;
    for (const [catId, questions] of Object.entries(questionsByCategory)) {
      const found = (questions || []).some((q) => String(q.question_id) === qid);
      if (!found) continue;
      categoryDropdown.value = catId;
      fillAwards(catId, qid);
      return Boolean(questionDropdown.value);
    }
    return false;
  }

  categoryDropdown.addEventListener('change', () => {
    fillAwards(categoryDropdown.value, '');
  });

  function renderHead(members) {
    lastMembers = members;
    const memberCells = members.map((m) => `<th title="${escapeHtml(m.label)}">${escapeHtml(m.short)}</th>`).join('');
    head.innerHTML = `<tr>
      <th>Business</th>
      ${memberCells}
      <th>Average</th>
      <th>Progress</th>
    </tr>`;
  }

  function renderSheet(payload) {
    const members = payload.members || [];
    const nominees = payload.nominees || [];
    renderHead(members);
    const summary = document.getElementById('twgSummary');
    const complete = nominees.filter((n) => Number(n.scored) >= members.length).length;
    const partial = nominees.filter((n) => Number(n.scored) > 0 && Number(n.scored) < members.length).length;
    if (summary) {
      summary.classList.remove('d-none');
      const nEl = document.getElementById('twgStatNominees');
      const cEl = document.getElementById('twgStatComplete');
      const pEl = document.getElementById('twgStatPartial');
      if (nEl) nEl.textContent = String(nominees.length);
      if (cEl) cEl.textContent = String(complete);
      if (pEl) pEl.textContent = String(partial);
    }
    if (!nominees.length) {
      body.innerHTML = `<tr><td colspan="${members.length + 3}" class="text-center text-muted">No businesses are linked to this award yet.</td></tr>`;
      return;
    }
    body.innerHTML = nominees.map((n) => {
      const inputs = members.map((m) => {
        const val = n.scores && n.scores[m.key] != null ? n.scores[m.key] : '';
        return `<td>
          <input type="number" min="1" max="10" step="0.5" class="form-control form-control-sm twg-score-input"
            value="${escapeHtml(val)}"
            data-choice-id="${n.choice_id}"
            data-member-key="${escapeHtml(m.key)}"
            aria-label="${escapeHtml(m.label)} score for ${escapeHtml(n.choice_name)}">
        </td>`;
      }).join('');
      const scored = Number(n.scored) || 0;
      const total = Number(n.member_count) || members.length;
      const ballot = n.on_ballot
        ? '<span class="badge rounded-pill text-bg-success">On ballot</span>'
        : '<span class="badge rounded-pill text-bg-light border text-muted">Under evaluation</span>';
      return `<tr data-choice-id="${n.choice_id}">
        <td>
          <div class="fw-semibold">${escapeHtml(n.choice_name)}</div>
          <div class="mt-1">${ballot}</div>
        </td>
        ${inputs}
        <td class="twg-avg fw-semibold" data-avg>${formatScore(n.average)}</td>
        <td data-progress><span class="small">${scored}/${total}</span></td>
      </tr>`;
    }).join('');
  }

  function loadSheet() {
    const questionId = questionDropdown.value;
    if (!questionId) {
      toast('Choose a category and award first.', false);
      return;
    }
    lastQuestionId = questionId;
    fetch(`twg_eval.php?event_id=${currentEventId}&question_id=${questionId}`, { credentials: 'same-origin' })
      .then((res) => res.json())
      .then((data) => {
        if (data.status !== 'success') {
          toast(data.message || 'Could not load the score sheet.', false);
          return;
        }
        renderSheet(data);
      })
      .catch((err) => toast(err.message || 'Could not load the score sheet.', false));
  }

  loadBtn.addEventListener('click', loadSheet);

  function selectedQuestionId() {
    return String(questionDropdown.value || lastQuestionId || '');
  }

  function downloadSheet(format, allAwards) {
    const params = new URLSearchParams({ format });
    if (!allAwards) {
      const questionId = selectedQuestionId();
      if (!questionId) {
        toast('Choose an award first, or download all awards.', false);
        return;
      }
      params.set('question_id', questionId);
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
    const body = new FormData();
    body.append('scoresheet', file);
    importBtn.disabled = true;
    try {
      const res = await fetch('twg_sheet_import.php', {
        method: 'POST',
        credentials: 'same-origin',
        body,
      });
      const data = await res.json();
      const extra = Array.isArray(data.errors) && data.errors.length
        ? ` ${data.errors.slice(0, 3).join(' ')}`
        : '';
      toast(`${data.message || 'Import finished.'}${extra}`, data.status === 'success');
      if (data.status === 'success' && lastQuestionId) {
        loadSheet();
      }
    } catch (err) {
      toast(err.message || 'Could not import the scoresheet.', false);
    } finally {
      importBtn.disabled = false;
      importFile.value = '';
    }
  });

  body.addEventListener('change', async (e) => {
    const input = e.target.closest('.twg-score-input');
    if (!input || !lastQuestionId) return;
    const choiceId = Number(input.getAttribute('data-choice-id') || 0);
    const memberKey = input.getAttribute('data-member-key') || '';
    const raw = String(input.value || '').trim();
    let score = null;
    if (raw !== '') {
      score = Number(raw);
      if (!Number.isFinite(score) || score < 1 || score > 10) {
        toast('Score must be from 1 to 10.', false);
        return;
      }
    }
    input.disabled = true;
    try {
      const res = await fetch('twg_eval.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
          action: 'save_member',
          question_id: Number(lastQuestionId),
          choice_id: choiceId,
          member_key: memberKey,
          score,
        }),
      });
      const data = await res.json();
      if (!res.ok || data.status !== 'success') {
        toast(data.message || 'Could not save score.', false);
        return;
      }
      const row = input.closest('tr');
      const avgEl = row?.querySelector('[data-avg]');
      const progEl = row?.querySelector('[data-progress]');
      if (avgEl) avgEl.textContent = formatScore(data.average);
      if (progEl) progEl.innerHTML = `<span class="small">${Number(data.scored) || 0}/${Number(data.member_count) || 5}</span>`;
    } catch (err) {
      toast(err.message || 'Could not save score.', false);
    } finally {
      input.disabled = false;
    }
  });
});
