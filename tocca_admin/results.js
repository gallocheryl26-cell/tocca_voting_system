let questionsByCategory = {};

// Small UI helpers used by download actions
function toast(msg, ok) {
  const el = document.getElementById("toastMsg");
  const body = document.getElementById("toastBody");
  if (!el || !body) {
    try { alert(msg); } catch (_) {}
    return;
  }
  el.classList.remove("text-bg-success", "text-bg-danger");
  el.classList.add(ok ? "text-bg-success" : "text-bg-danger");
  body.textContent = String(msg || "");
  try {
    bootstrap.Toast.getOrCreateInstance(el).show();
  } catch (_) {
    // Fallback if bootstrap toast isn't initialized
    el.style.display = "block";
  }
}

function requireActiveEvent() {
  if (typeof currentEventId === "number" && currentEventId > 0) return true;
  toast("No active event. Activate an event under File Maintenance → Events.", false);
  return false;
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
  return `<div class="mt-1"><span class="fw-semibold">${escapeHtml(namedEntryKindLabel(kind))}:</span> ${list.map((n) => escapeHtml(n)).join(', ')}</div>`;
}

function namedEntryLineFromResult(result) {
  const entries = Array.isArray(result?.entries) ? result.entries : [];
  if (entries.length) {
    const kind = String(result.entry_kind || entries[0].entry_kind || 'product');
    const parts = entries.map((entry) => {
      const name = String(entry.entry_name || '').trim();
      if (!name) return '';
      const avg = entry.average == null || entry.average === '' ? null : Number(entry.average);
      const avgText = avg != null && Number.isFinite(avg) ? ` (${formatScore(avg)})` : '';
      return name + avgText;
    }).filter(Boolean);
    return namedEntryLineHtml(kind, parts);
  }
  const names = Array.isArray(result?.entry_names)
    ? result.entry_names.map((n) => String(n || '').trim()).filter(Boolean)
    : [];
  if (!names.length) return '';
  return namedEntryLineHtml(result.entry_kind || 'product', names);
}

const getOrdinal = (value) => {
  const number = Number(value);
  if (!Number.isFinite(number)) return value;

  const mod100 = number % 100;
  if (mod100 >= 11 && mod100 <= 13) {
    return `${number}th`;
  }

  switch (number % 10) {
    case 1:
      return `${number}st`;
    case 2:
      return `${number}nd`;
    case 3:
      return `${number}rd`;
    default:
      return `${number}th`;
  }
};

const getStandingBadge = (rank) => {
  const label = getOrdinal(rank);

  if (rank === 1) {
    return `<span class="badge badge-status rounded-pill text-bg-warning">${label}</span>`;
  }

  if (rank === 2) {
    return `<span class="badge badge-status rounded-pill text-bg-secondary">${label}</span>`;
  }

  if (rank === 3) {
    return `<span class="badge badge-status rounded-pill text-bg-secondary">${label}</span>`;
  }

  return `<span class="badge badge-status rounded-pill text-bg-secondary">${label}</span>`;
};

let lastAwardPayload = null;
let lastQuestionId = null;
let lastTwgOverview = null;

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
  return `<div class="mt-1"><span class="fw-semibold">${escapeHtml(namedEntryKindLabel(kind))}:</span> ${list.map((n) => escapeHtml(n)).join(', ')}</div>`;
}

function namedEntryLineFromResult(result) {
  const entries = Array.isArray(result?.entries) ? result.entries : [];
  if (entries.length) {
    const kind = String(result.entry_kind || entries[0].entry_kind || 'product');
    const parts = entries.map((entry) => {
      const name = String(entry.entry_name || '').trim();
      if (!name) return '';
      const avg = entry.average == null || entry.average === '' ? null : Number(entry.average);
      const avgText = avg != null && Number.isFinite(avg) ? ` (${formatScore(avg)})` : '';
      return name + avgText;
    }).filter(Boolean);
    return namedEntryLineHtml(kind, parts);
  }
  const names = Array.isArray(result?.entry_names)
    ? result.entry_names.map((n) => String(n || '').trim()).filter(Boolean)
    : [];
  if (!names.length) return '';
  return namedEntryLineHtml(result.entry_kind || 'product', names);
}

function emptyResultsRow(colspan, message, danger) {
  const tone = danger ? 'text-danger' : 'text-muted';
  return `<tr><td colspan="${colspan}" class="text-center ${tone}">${message}</td></tr>`;
}

function isTwgTabActive() {
  return document.getElementById('twgResultTab')?.classList.contains('active') === true;
}

function twgMembersFromPayload() {
  const members = Array.isArray(lastTwgOverview?.twg_members) && lastTwgOverview.twg_members.length
    ? lastTwgOverview.twg_members
    : (Array.isArray(lastAwardPayload?.twg_members) ? lastAwardPayload.twg_members : []);
  if (members.length) return members;
  return [
    { key: 'lgu_1', short: 'LGU 1' },
    { key: 'lgu_2', short: 'LGU 2' },
    { key: 'bplo', short: 'BPLO' },
    { key: 'ledipo', short: 'LEDIPO' },
    { key: 'orcham', short: 'ORCHAM' },
    { key: 'judge_6', short: 'Judge 6' },
    { key: 'judge_7', short: 'Judge 7' },
  ];
}

function selectedResultFilters() {
  const categoryId = document.getElementById('resultCategoryDropdown')?.value || '';
  const questionId = document.getElementById('resultQuestionDropdown')?.value || '';
  const choiceId = document.getElementById('resultBusinessDropdown')?.value || '';
  const businessQuery = String(document.getElementById('resultBusinessSearch')?.value || '').trim().toLowerCase();
  return { categoryId, questionId, choiceId, businessQuery };
}

function businessNameMatches(name, query) {
  if (!query) return true;
  return String(name || '').replace(' (manual input)', '').toLowerCase().includes(query);
}

function applyBusinessFilters(rows) {
  const { choiceId, businessQuery } = selectedResultFilters();
  let next = Array.isArray(rows) ? rows.slice() : [];
  if (choiceId) {
    next = next.filter((r) => String(r.choice_id) === String(choiceId));
  }
  if (businessQuery) {
    next = next.filter((r) => businessNameMatches(r.choice_name, businessQuery));
  }
  return next;
}

function uniqueBusinessOptions(rowSets) {
  const map = new Map();
  rowSets.forEach((rows) => {
    (rows || []).forEach((r) => {
      if (r == null || r.choice_id == null || r.choice_id === '') return;
      const key = String(r.choice_id);
      if (map.has(key)) return;
      const name = String(r.choice_name || '').replace(' (manual input)', '').trim();
      map.set(key, { choice_id: key, choice_name: name || `Business ${key}` });
    });
  });
  return [...map.values()].sort((a, b) => a.choice_name.localeCompare(b.choice_name, undefined, { sensitivity: 'base' }));
}

function refreshBusinessFilterOptions() {
  const select = document.getElementById('resultBusinessDropdown');
  if (!select) return;
  const prev = select.value;
  const businesses = uniqueBusinessOptions([
    lastTwgOverview?.status === 'success' ? lastTwgOverview.rows : [],
    lastAwardPayload?.status === 'success' ? lastAwardPayload.results : [],
  ]);
  select.innerHTML = '<option value="">All businesses</option>';
  businesses.forEach((b) => {
    const option = document.createElement('option');
    option.value = b.choice_id;
    option.textContent = b.choice_name;
    select.appendChild(option);
  });
  if (prev && [...select.options].some((opt) => opt.value === prev)) {
    select.value = prev;
  }
}

function rerenderVisibleResults() {
  if (isTwgTabActive()) {
    renderTwgOverview();
  } else {
    renderAwardResults();
  }
}

function renderTwgHead() {
  const head = document.getElementById('twgResultsHead');
  if (!head) return;
  const members = twgMembersFromPayload();
  const rubric = Array.isArray(lastTwgOverview?.rubric) ? lastTwgOverview.rubric : [];
  const rubricHint = rubric.length
    ? rubric.map((r) => `${r.short || r.label} ${Number(r.max || 0)}`).join(' · ')
    : '';
  head.innerHTML = `<tr>
    <th>Ranking</th>
    <th>Business</th>
    ${members.map((m) => `<th>${escapeHtml(m.short || m.label || m.key)}</th>`).join('')}
    <th>Average</th>
    <th>TWG ( / 100)</th>
  </tr>`;
  const hint = document.getElementById('twgResultScopeHint');
  if (hint) {
    hint.textContent = rubricHint
      ? `Each judge total is Taste + Innovation + Value (max 100). Average is those totals ÷ judges who scored this award. ${rubricHint}. Food and Service Top 5 uses this ranking.`
      : 'Choose a category and award title. Each column is one judge’s total out of 100. Average divides by the judges who scored this award.';
  }
}

function updateTwgSummary(rows) {
  if (!isTwgTabActive()) return;
  const summary = document.getElementById('resultsSummary');
  if (!summary) return;
  const entered = rows.filter((r) => r.twg_entered || r.twg_average != null).length;
  const businesses = new Set(rows.map((r) => r.choice_id).filter(Boolean));
  const leader = rows
    .filter((r) => r.twg_average != null)
    .slice()
    .sort((a, b) => Number(b.twg_average) - Number(a.twg_average))[0] || null;

  summary.classList.remove('d-none');
  const elVotes = document.getElementById('statTotalVotes');
  const elNom = document.getElementById('statNominees');
  const elLeader = document.getElementById('statLeader');
  const elLeaderScore = document.getElementById('statLeaderScore');
  const elTwg = document.getElementById('statTwgEntered');
  if (elVotes) elVotes.textContent = String(rows.length);
  if (elNom) elNom.textContent = String(businesses.size);
  if (elLeader) elLeader.textContent = leader ? (leader.choice_name || '—') : '—';
  if (elLeaderScore) {
    elLeaderScore.textContent = leader ? `TWG avg ${formatScore(leader.twg_average)}` : '';
  }
  if (elTwg) elTwg.textContent = `${entered} / ${rows.length}`;
  const votesLabel = elVotes?.previousElementSibling;
  if (votesLabel) votesLabel.textContent = 'Award rows';
  const nomLabel = elNom?.previousElementSibling;
  if (nomLabel) nomLabel.textContent = 'Businesses';
}

function restoreFinalSummaryLabels() {
  const elVotes = document.getElementById('statTotalVotes');
  const elNom = document.getElementById('statNominees');
  const votesLabel = elVotes?.previousElementSibling;
  const nomLabel = elNom?.previousElementSibling;
  if (votesLabel) votesLabel.textContent = 'Total votes';
  if (nomLabel) nomLabel.textContent = 'Nominees';
}

function syncResultsTabLayout() {
  const filterCard = document.getElementById('finalScoreFilterCard');
  const summary = document.getElementById('resultsSummary');
  const twg = isTwgTabActive();
  if (filterCard) filterCard.classList.toggle('d-none', twg);
  if (summary && twg) {
    /* TWG summary is filled by renderTwgOverview */
  }
}

function populateTwgResultFilters(categories) {
  const catSel = document.getElementById('twgResultCategory');
  if (!catSel) return;
  const prev = catSel.value;
  catSel.innerHTML = '<option value="">Select category…</option>';
  (categories || []).forEach((cat) => {
    const option = document.createElement('option');
    option.value = String(cat.category_id);
    option.textContent = cat.category_name;
    catSel.appendChild(option);
  });
  if (prev && [...catSel.options].some((opt) => opt.value === prev)) {
    catSel.value = prev;
  }
  fillTwgAwardOptions();
}

function fillTwgAwardOptions() {
  const catSel = document.getElementById('twgResultCategory');
  const awardSel = document.getElementById('twgResultAward');
  if (!awardSel) return;
  const categoryId = catSel?.value || '';
  const prev = awardSel.value;
  awardSel.innerHTML = '<option value="">Select an award…</option>';
  const related = categoryId ? (questionsByCategory[categoryId] || []) : [];
  related.forEach((q) => {
    const option = document.createElement('option');
    option.value = String(q.question_id);
    option.textContent = q.question_name;
    awardSel.appendChild(option);
  });
  awardSel.disabled = related.length === 0;
  if (prev && [...awardSel.options].some((opt) => opt.value === prev)) {
    awardSel.value = prev;
  }
}

function renderTwgOverview() {
  const twgBody = document.getElementById('twgTableBody');
  const members = twgMembersFromPayload();
  const twgCols = 4 + members.length;
  renderTwgHead();
  if (!twgBody) return;

  if (!lastTwgOverview || lastTwgOverview.status !== 'success') {
    twgBody.innerHTML = emptyResultsRow(twgCols, lastTwgOverview?.message || 'Could not load TWG scores.', true);
    return;
  }

  const categoryId = document.getElementById('twgResultCategory')?.value || '';
  const questionId = document.getElementById('twgResultAward')?.value || '';
  const top5Only = document.getElementById('twgTop5OnlyToggle')?.checked === true;

  if (!categoryId || !questionId) {
    twgBody.innerHTML = emptyResultsRow(twgCols, 'Select a category and award title.');
    updateTwgSummary([]);
    return;
  }

  let rows = Array.isArray(lastTwgOverview.rows) ? lastTwgOverview.rows.slice() : [];
  rows = rows.filter((r) => String(r.category_id) === String(categoryId) && String(r.question_id) === String(questionId));
  if (top5Only) {
    rows = rows.filter((r) => Number(r.twg_rank) > 0 && Number(r.twg_rank) <= 5);
  }

  updateTwgSummary(rows);

  if (rows.length === 0) {
    twgBody.innerHTML = emptyResultsRow(
      twgCols,
      top5Only ? 'No Top 5 businesses for this award yet.' : 'No businesses are linked to this award yet.'
    );
    return;
  }

  rows.sort((a, b) => {
    const ar = a.twg_rank == null ? 9999 : Number(a.twg_rank);
    const br = b.twg_rank == null ? 9999 : Number(b.twg_rank);
    if (ar !== br) return ar - br;
    return String(a.choice_name || '').localeCompare(String(b.choice_name || ''));
  });

  twgBody.innerHTML = rows.map((result) => {
    const twgRank = result.twg_rank == null ? 0 : Number(result.twg_rank);
    const top5 = twgRank > 0 && twgRank <= 5;
    const twgHref = `twg_evaluation.php?choice_id=${encodeURIComponent(String(result.choice_id || ''))}`;
    const scores100 = result.scores_100 || {};
    const scores = result.scores || result.twg_scores || {};
    const rubricScores = result.rubric_scores || {};
    const memberCells = members.map((m) => {
      const total100 = scores100[m.key] != null && scores100[m.key] !== ''
        ? Number(scores100[m.key])
        : (scores[m.key] != null && scores[m.key] !== '' ? Number(scores[m.key]) : null);
      const bits = [];
      const judgeRubric = rubricScores[m.key] || {};
      Object.keys(judgeRubric).forEach((k) => {
        if (judgeRubric[k] != null && judgeRubric[k] !== '') bits.push(formatScore(judgeRubric[k]));
      });
      const detail = bits.length ? `<div class="small text-muted">${bits.join(' + ')}</div>` : '';
      return `<td>${total100 == null || !Number.isFinite(total100) ? '<span class="text-muted">—</span>' : `${formatScore(total100)}${detail}`}</td>`;
    }).join('');
    const standing = twgRank > 0 ? getStandingBadge(twgRank) : '<span class="text-muted">—</span>';
    const avg10 = result.twg_average == null ? null : Number(result.twg_average);
    const avg100 = result.twg_average_100 != null
      ? Number(result.twg_average_100)
      : (avg10 != null && Number.isFinite(avg10) ? avg10 : null);
    const avgCell = avg100 == null || !Number.isFinite(avg100)
      ? '<span class="text-muted">—</span>'
      : `<a href="${twgHref}" class="text-decoration-none fw-semibold">${formatScore(avg100)}</a>`;
    const tenCell = avg100 == null || !Number.isFinite(avg100)
      ? '<span class="text-muted">—</span>'
      : formatScore(avg100);
    const productsHtml = namedEntryLineFromResult(result);

    return `<tr class="${top5 ? 'results-top10-row' : ''}">
      <td>${standing}</td>
      <td>
        <div class="fw-semibold">${escapeHtml(result.choice_name || '')}</div>
        ${productsHtml}
        ${top5 ? '<div class="mt-1"><span class="badge rounded-pill text-bg-warning">Top 5</span></div>' : ''}
      </td>
      ${memberCells}
      <td>${avgCell}</td>
      <td>${tenCell}</td>
    </tr>`;
  }).join('');
}

function loadTwgOverview() {
  const twgBody = document.getElementById('twgTableBody');
  if (!currentEventId) {
    if (twgBody) twgBody.innerHTML = emptyResultsRow(9, 'No active event.');
    return Promise.resolve();
  }
  if (twgBody) twgBody.innerHTML = emptyResultsRow(9, 'Loading TWG scores…');
  return fetch(`result.php?event_id=${currentEventId}&twg_overview=1`, { credentials: 'same-origin' })
    .then((res) => res.json())
    .then((data) => {
      lastTwgOverview = data;
      refreshBusinessFilterOptions();
      renderTwgOverview();
    })
    .catch((err) => {
      console.error('Error loading TWG results:', err);
      lastTwgOverview = { status: 'error', message: 'Error loading TWG scores.' };
      renderTwgOverview();
    });
}

function updateResultsSummary(rows) {
  if (isTwgTabActive()) {
    renderTwgOverview();
    return;
  }
  restoreFinalSummaryLabels();
  const summary = document.getElementById('resultsSummary');
  if (!summary || !lastAwardPayload || lastAwardPayload.status !== 'success') {
    summary?.classList.add('d-none');
    return;
  }
  const totalVotes = Number(lastAwardPayload.total_votes || 0);
  const nominees = Number(lastAwardPayload.nominee_count || rows.length);
  const twgEntered = Number(lastAwardPayload.twg_entered || 0);
  const leader = lastAwardPayload.leader || rows[0] || null;

  summary.classList.remove('d-none');
  const elVotes = document.getElementById('statTotalVotes');
  const elNom = document.getElementById('statNominees');
  const elLeader = document.getElementById('statLeader');
  const elLeaderScore = document.getElementById('statLeaderScore');
  const elTwg = document.getElementById('statTwgEntered');
  if (elVotes) elVotes.textContent = String(totalVotes);
  if (elNom) elNom.textContent = String(nominees);
  if (elLeader) elLeader.textContent = leader ? (leader.choice_name || '—') : '—';
  if (elLeaderScore) {
    elLeaderScore.textContent = leader ? `Final ${formatScore(leader.final_score)}` : '';
  }
  if (elTwg) elTwg.textContent = `${twgEntered} / ${rows.filter((r) => r.choice_id).length}`;
}

function renderAwardResults() {
  const tableBody = document.getElementById('tableBody');
  const top10Only = document.getElementById('top10OnlyToggle')?.checked === true;
  if (!tableBody) return;

  if (!lastAwardPayload || lastAwardPayload.status !== 'success') {
    tableBody.innerHTML = emptyResultsRow(8, 'Select a category and award, then view results.');
    if (!isTwgTabActive()) {
      document.getElementById('resultsSummary')?.classList.add('d-none');
    }
    return;
  }

  const rows = applyBusinessFilters(Array.isArray(lastAwardPayload.results) ? lastAwardPayload.results : []);
  updateResultsSummary(rows);

  const visibleFinal = top10Only ? rows.filter((r) => r.top10 || Number(r.rank) <= 5) : rows;

  if (visibleFinal.length === 0) {
    const { choiceId, businessQuery } = selectedResultFilters();
    const filteredByBusiness = Boolean(choiceId || businessQuery);
    tableBody.innerHTML = emptyResultsRow(
      8,
      filteredByBusiness ? 'No results match this business filter.' : 'No results found.',
      true
    );
    return;
  }

  tableBody.innerHTML = visibleFinal.map((result) => {
    const rank = Number(result.rank) || 0;
    const isFreetext = result.choice_id === null || result.is_freetext;
    const cleanText = String(result.choice_name || '').replace(' (manual input)', '');
    const twgHref = `twg_evaluation.php?choice_id=${encodeURIComponent(String(result.choice_id || ''))}`;
    const twgCell = isFreetext
      ? '<span class="text-muted">—</span>'
      : (result.twg_average === null || result.twg_average === undefined
        ? `<span class="text-muted">—</span> <a class="small ms-1" href="${twgHref}">Score</a>`
        : `<a href="${twgHref}" class="text-decoration-none">${formatScore(result.twg_average)}</a>`);

    return `<tr>
      <td>${getStandingBadge(rank)}</td>
      <td>
        <div class="fw-semibold">${escapeHtml(cleanText)}${isFreetext ? ' <span class="text-muted fst-italic">(manual input)</span>' : ''}</div>
        ${result.ballot_entry_id ? '' : namedEntryLineFromResult(result)}
      </td>
      <td>${Number(result.vote_count) || 0}</td>
      <td>${formatScore(result.vote_share)}%</td>
      <td>${formatScore(result.community_score)}</td>
      <td>${twgCell}</td>
      <td class="fw-semibold">${formatScore(result.final_score)}</td>
      <td>
        <button
          class="btn btn-sm btn-outline-primary viewVotersBtn"
          data-choice="${isFreetext ? '' : (result.choice_id || '')}"
          data-ballot-entry="${isFreetext ? '' : (result.ballot_entry_id || '')}"
          data-freetext="${isFreetext ? encodeURIComponent(cleanText) : ''}"
          data-question="${lastQuestionId}"
          data-name="${escapeHtml(result.choice_name)}">
          View voters
        </button>
      </td>
    </tr>`;
  }).join('');
}

document.addEventListener('DOMContentLoaded', () => {
  const categoryDropdown = document.getElementById('resultCategoryDropdown');
  const questionDropdown = document.getElementById('resultQuestionDropdown');
  const viewResultBtn = document.getElementById('viewResultBtn');
  const tableBody = document.getElementById('tableBody');
  const voterModalChoiceName = document.getElementById('voterModalChoiceName');
  const voterListTable = document.getElementById('voterListTable');
  const totalVoterCount = document.getElementById('totalVoterCount');
  const btnConfirmDownload = document.getElementById("confirmDownloadResults");
  const ddlFormat = document.getElementById("downloadFormat");
  const downloadModalEl = document.getElementById("downloadResultsModal");
  const credentialsModalEl = document.getElementById("exportCredentialsModal");
  const credentialsBodyEl = document.getElementById("exportCredentialsBody");
  const btnConfirmExportWithCredentials = document.getElementById("confirmExportWithCredentials");
  const ddlScope = document.getElementById("downloadScope");
  const downloadScopeWrap = document.getElementById("downloadScopeCurrentWrap");
  const ddlDownloadCat = document.getElementById("download_category_id");
  const ddlDownloadAwd = document.getElementById("download_question_id");
  const downloadScopeFeedback = document.getElementById("downloadScopeFeedback");
  let pendingDownloadParams = null;

  if (typeof currentEventId === 'undefined' || !currentEventId) {
    console.warn('Results: no active event id — table filters disabled; set an active event to export.');
  }
  syncResultsTabLayout();

  // Load categories and questions
  if (!currentEventId) {
    if (categoryDropdown) {
      categoryDropdown.innerHTML = '<option value="" disabled selected>No active event</option>';
    }
    const businessDropdown = document.getElementById('resultBusinessDropdown');
    if (businessDropdown) {
      businessDropdown.innerHTML = '<option value="" disabled selected>No active event</option>';
    }
    const businessSearch = document.getElementById('resultBusinessSearch');
    if (businessSearch) businessSearch.disabled = true;
  } else {
    loadTwgOverview();
  fetch(`result.php?event_id=${currentEventId}`, { credentials: 'same-origin' })
    .then(res => {
      if (res.status === 401) {
        throw new Error('Session expired. Please log in again.');
      }
      return res.json();
    })
    .then(data => {
      if (data.status === 'success') {
        categoryDropdown.innerHTML = '<option value="">All categories</option>';
        data.categories.forEach(cat => {
          const option = document.createElement('option');
          option.value = cat.category_id;
          option.textContent = cat.category_name;
          categoryDropdown.appendChild(option);
        });

        questionsByCategory = data.questions_by_category;
        populateTwgResultFilters(data.categories || []);
      } else {
        console.error("Failed to load categories/questions.");
      }
    })
    .catch((err) => {
      console.error('Failed to load categories/questions:', err);
      if (categoryDropdown) {
        categoryDropdown.innerHTML = '<option value="" disabled selected>Failed to load categories</option>';
      }
    });
  }

  // When category changes
  categoryDropdown.addEventListener('change', () => {
    const selectedCategoryId = categoryDropdown.value;
    const relatedQuestions = questionsByCategory[selectedCategoryId] || [];

    questionDropdown.innerHTML = '<option value="">All awards</option>';
    relatedQuestions.forEach(q => {
      const option = document.createElement('option');
      option.value = q.question_id;
      option.textContent = q.question_name;
      questionDropdown.appendChild(option);
    });
    if (lastAwardPayload) renderAwardResults();
  });

  questionDropdown.addEventListener('change', () => {
    if (lastAwardPayload) renderAwardResults();
  });

  const businessDropdown = document.getElementById('resultBusinessDropdown');
  const businessSearch = document.getElementById('resultBusinessSearch');
  businessDropdown?.addEventListener('change', rerenderVisibleResults);
  businessSearch?.addEventListener('input', rerenderVisibleResults);

  const top10OnlyToggle = document.getElementById('top10OnlyToggle');

  // View results button
  viewResultBtn.addEventListener('click', () => {
    const selectedQuestionId = questionDropdown.value;
    if (!selectedQuestionId) return;

    fetch(`result.php?question_id=${selectedQuestionId}&event_id=${currentEventId}`, { credentials: 'same-origin' })
      .then(res => res.json())
      .then(data => {
        lastQuestionId = selectedQuestionId;
        lastAwardPayload = data;
        if (data.status === 'success') {
          refreshBusinessFilterOptions();
          renderAwardResults();
        } else {
          lastAwardPayload = null;
          const msg = `Error: ${data.message || 'Could not load results.'}`;
          tableBody.innerHTML = emptyResultsRow(8, escapeHtml(msg), true);
          if (!isTwgTabActive()) {
            document.getElementById('resultsSummary')?.classList.add('d-none');
          }
        }
      })
      .catch(err => {
        console.error('Error fetching results:', err);
        lastAwardPayload = null;
        tableBody.innerHTML = emptyResultsRow(8, 'Error loading results.', true);
        if (!isTwgTabActive()) {
          document.getElementById('resultsSummary')?.classList.add('d-none');
        }
      });
  });

  top10OnlyToggle?.addEventListener('change', () => {
    if (lastAwardPayload) renderAwardResults();
  });

  const twgCategory = document.getElementById('twgResultCategory');
  const twgAward = document.getElementById('twgResultAward');
  twgCategory?.addEventListener('change', () => {
    fillTwgAwardOptions();
    renderTwgOverview();
  });
  twgAward?.addEventListener('change', renderTwgOverview);
  document.getElementById('twgTop5OnlyToggle')?.addEventListener('change', renderTwgOverview);

  document.getElementById('resultsTabs')?.addEventListener('shown.bs.tab', () => {
    syncResultsTabLayout();
    if (isTwgTabActive()) {
      renderTwgOverview();
      return;
    }
    restoreFinalSummaryLabels();
    if (lastAwardPayload) {
      updateResultsSummary(applyBusinessFilters(Array.isArray(lastAwardPayload.results) ? lastAwardPayload.results : []));
    }
  });


  // Load voters per choice or freetext
  document.body.addEventListener('click', (e) => {
    if (e.target.classList.contains('viewVotersBtn')) {
      const choiceId = e.target.dataset.choice;
      const ballotEntryId = e.target.dataset.ballotEntry || '';
      const freetext = decodeURIComponent(e.target.dataset.freetext || '');
      const questionId = e.target.dataset.question;
      const choiceName = e.target.dataset.name;
      voterModalChoiceName.textContent = choiceName;

      voterListTable.innerHTML = '';
      totalVoterCount.textContent = '0';

      let url = '';
      if (!choiceId && freetext) {
        url = `result.php?freetext=${freetext}&question_id=${questionId}&event_id=${currentEventId}`;
      } else {
        url = `result.php?choice_id=${choiceId}&question_id=${questionId}${ballotEntryId ? `&ballot_entry_id=${encodeURIComponent(ballotEntryId)}` : ''}`;
      }

      fetch(url, { credentials: 'same-origin' })
        .then(res => res.json())
        .then(data => {
          if (data.status === 'success' && data.voters.length > 0) {
            data.voters.forEach((v, index) => {
              const proofHref = `vote_proofs.php?voters_id=${encodeURIComponent(v.voters_id)}&question_id=${encodeURIComponent(questionId)}${choiceId ? `&choice_id=${encodeURIComponent(choiceId)}` : ''}`;
              voterListTable.innerHTML += `
                <tr>
                  <td>${index + 1}</td>
                  <td>${v.voters_id}</td>
                  <td>${v.mobile_number}</td>
                  <td>${v.vote_at}</td>
                  <td><a class="btn btn-sm btn-outline-secondary" href="${proofHref}">Proofs</a></td>
                </tr>`;
            });
            totalVoterCount.textContent = data.voters.length;
          } else {
            voterListTable.innerHTML = `<tr><td colspan="5" class="text-center text-muted">No voters found.</td></tr>`;
          }

          new bootstrap.Modal(document.getElementById('voterModal')).show();
        })
        .catch(err => {
          console.error('Error loading voters:', err);
          voterListTable.innerHTML = `<tr><td colspan="5" class="text-center text-danger">Failed to load voters.</td></tr>`;
        });
    }
  });

  function clearDownloadScopeValidation() {
    if (ddlDownloadCat) ddlDownloadCat.classList.remove("is-invalid");
    if (ddlDownloadAwd) ddlDownloadAwd.classList.remove("is-invalid");
    if (downloadScopeFeedback) {
      downloadScopeFeedback.textContent = "";
      downloadScopeFeedback.classList.remove("text-danger");
    }
  }

  function syncDownloadScopeUI() {
    const scope = ddlScope?.value || "all";
    const showCurrent = scope === "current";
    if (downloadScopeWrap) {
      downloadScopeWrap.classList.toggle("d-none", !showCurrent);
    }
    if (!showCurrent) {
      clearDownloadScopeValidation();
    }
  }

  function populateDownloadCategories() {
    if (!ddlDownloadCat) return;
    ddlDownloadCat.innerHTML = '<option value="">Select category…</option>';
    Array.from(categoryDropdown.options).forEach((opt) => {
      if (!opt.value) return;
      const clone = document.createElement("option");
      clone.value = opt.value;
      clone.textContent = opt.textContent;
      ddlDownloadCat.appendChild(clone);
    });
  }

  function loadDownloadAwards() {
    if (!ddlDownloadCat || !ddlDownloadAwd) return;
    const categoryId = ddlDownloadCat.value;
    ddlDownloadAwd.innerHTML = '<option value="">Select award…</option>';
    ddlDownloadAwd.disabled = true;
    if (!categoryId) return;

    const related = questionsByCategory[categoryId] || [];
    related.forEach((q) => {
      const opt = document.createElement("option");
      opt.value = String(q.question_id);
      opt.textContent = q.question_name;
      ddlDownloadAwd.appendChild(opt);
    });
    ddlDownloadAwd.disabled = related.length === 0;
  }

  function syncDownloadModalFromPageFilters() {
    if (!ddlDownloadCat || !ddlDownloadAwd) return;
    if (categoryDropdown.value) {
      ddlDownloadCat.value = categoryDropdown.value;
      loadDownloadAwards();
      if (questionDropdown.value) {
        ddlDownloadAwd.value = questionDropdown.value;
      }
    }
  }

  function buildDownloadParams() {
    const scope = ddlScope?.value || "all";
    const top = parseInt(document.getElementById("downloadTopNumber")?.value, 10) || 0;
    const format = ddlFormat?.value || "csv";
    const params = new URLSearchParams({
      event_id: String(currentEventId),
      scope,
      top: String(top),
      format
    });
    if (scope === "current") {
      const categoryId = ddlDownloadCat?.value || "";
      const questionId = ddlDownloadAwd?.value || "";
      if (!categoryId || !questionId) {
        return null;
      }
      params.set("category_id", categoryId);
      params.set("question_id", questionId);
    }
    return params;
  }

  function hideDownloadModal() {
    if (!downloadModalEl || typeof bootstrap === "undefined") return;
    const modal = bootstrap.Modal.getInstance(downloadModalEl) || new bootstrap.Modal(downloadModalEl);
    modal.hide();
  }

  function escHtml(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, (ch) => ({
      "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"
    }[ch]));
  }

  /** Same-origin file download (works after async preflight; blob+click is often blocked). */
  function navigateFileDownload(params) {
    const url = "download_results.php?" + params.toString();
    const iframe = document.createElement("iframe");
    iframe.setAttribute("aria-hidden", "true");
    iframe.tabIndex = -1;
    iframe.style.cssText = "position:absolute;width:0;height:0;border:0;visibility:hidden";
    iframe.src = url;
    document.body.appendChild(iframe);
    toast("Download started. Check your browser downloads if the file does not appear.", true);
    window.setTimeout(() => {
      try { iframe.remove(); } catch (_) {}
    }, 120000);
    return Promise.resolve();
  }

  function exportCredentialsForFormat(format) {
    const map = window.TOCCA_EXPORT_CREDENTIALS || {};
    return map[format] || map.csv || null;
  }

  function renderCredentialsModal(credentials) {
    if (!credentialsBodyEl) return;
    const items = (credentials && credentials.items) ? credentials.items : [];
    credentialsBodyEl.innerHTML = items.map((item, idx) => {
      const inputId = "exportCredentialPassword" + idx;
      return ""
        + '<div class="mb-3">'
        + '  <label class="form-label fw-semibold" for="' + inputId + '">' + escHtml(item.label || "Password") + '</label>'
        + '  <div class="input-group">'
        + '    <input type="text" class="form-control font-monospace" id="' + inputId + '" readonly value="' + escHtml(item.password || "") + '">'
        + '    <button type="button" class="btn btn-outline-secondary" data-copy-target="' + inputId + '">Copy</button>'
        + '  </div>'
        + (item.hint ? '<small class="text-muted d-block mt-1">' + escHtml(item.hint) + "</small>" : "")
        + "</div>";
    }).join("");

    credentialsBodyEl.querySelectorAll("[data-copy-target]").forEach((btn) => {
      btn.addEventListener("click", () => {
        const target = document.getElementById(btn.getAttribute("data-copy-target"));
        if (!target) return;
        target.select();
        target.setSelectionRange(0, target.value.length);
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(target.value).then(() => toast("Password copied.", true))
            .catch(() => toast("Copied to clipboard selection.", true));
        } else {
          try {
            document.execCommand("copy");
            toast("Password copied.", true);
          } catch (_) {
            toast("Select the password and copy manually.", false);
          }
        }
      });
    });
  }

  function showCredentialsModal(credentials, params) {
    pendingDownloadParams = params;
    renderCredentialsModal(credentials);
    if (!credentialsModalEl) {
      return navigateFileDownload(params);
    }
    if (typeof bootstrap === "undefined") {
      toast("Page still loading — please try again in a moment.", false);
      return Promise.resolve();
    }
    const modal = bootstrap.Modal.getInstance(credentialsModalEl) || new bootstrap.Modal(credentialsModalEl);
    modal.show();
    return Promise.resolve();
  }

  function handleDownloadClick() {
    if (!requireActiveEvent()) return;
    const params = buildDownloadParams();
    if (!params) {
      if (ddlDownloadCat) ddlDownloadCat.classList.add("is-invalid");
      if (ddlDownloadAwd) ddlDownloadAwd.classList.add("is-invalid");
      if (downloadScopeFeedback) {
        downloadScopeFeedback.textContent = "Select a category and an award to download.";
        downloadScopeFeedback.classList.add("text-danger");
      }
      toast('Please select a category and an award, or choose "All Categories".', false);
      return;
    }
    clearDownloadScopeValidation();
    hideDownloadModal();

    const format = params.get("format") || "csv";
    const creds = exportCredentialsForFormat(format);
    if (creds && creds.show) {
      showCredentialsModal(creds, params);
      return;
    }
    navigateFileDownload(params);
  }

  if (ddlScope) {
    ddlScope.addEventListener("change", () => {
      syncDownloadScopeUI();
      clearDownloadScopeValidation();
      if (ddlScope.value === "current") {
        populateDownloadCategories();
        syncDownloadModalFromPageFilters();
      }
    });
  }
  if (ddlDownloadCat) {
    ddlDownloadCat.addEventListener("change", () => {
      clearDownloadScopeValidation();
      loadDownloadAwards();
    });
  }
  if (ddlDownloadAwd) {
    ddlDownloadAwd.addEventListener("change", clearDownloadScopeValidation);
  }
  if (downloadModalEl) {
    downloadModalEl.addEventListener("shown.bs.modal", () => {
      syncDownloadScopeUI();
      populateDownloadCategories();
      if (ddlScope?.value === "current") {
        syncDownloadModalFromPageFilters();
      }
    });
  }

  if (btnConfirmDownload) {
    btnConfirmDownload.addEventListener("click", handleDownloadClick);
  }

  if (btnConfirmExportWithCredentials) {
    btnConfirmExportWithCredentials.addEventListener("click", () => {
      if (!pendingDownloadParams) return;
      const params = pendingDownloadParams;
      pendingDownloadParams = null;
      navigateFileDownload(params);
      if (credentialsModalEl && typeof bootstrap !== "undefined") {
        const modal = bootstrap.Modal.getInstance(credentialsModalEl);
        if (modal) modal.hide();
      }
    });
  }

    //  Modal Voter List Export
  const downloadVotersBtn = document.getElementById("downloadVotersCSVBtn");

  if (downloadVotersBtn) downloadVotersBtn.addEventListener("click", () => {
    const table = document.querySelector("#voterListTable");
    if (!table || table.rows.length === 0) {
      alert("No voters to download.");
      return;
    }

    let csv = [];
    csv.push('"No.","Voter ID","Phone Number","Date Voted"'); // header row

    for (let row of table.rows) {
      const cells = row.cells;
      const rowData = [
        cells[0].innerText.replace(/"/g, '""'),
        cells[1].innerText.replace(/"/g, '""'),
        cells[2].innerText.replace(/"/g, '""'),
        cells[3].innerText.replace(/"/g, '""'),
      ];
      csv.push(rowData.map(val => `"${val}"`).join(","));
    }

    const blob = new Blob([csv.join("\n")], { type: "text/csv;charset=utf-8;" });
    const link = document.createElement("a");

    let choiceName = voterModalChoiceName.textContent || "voters";
    choiceName = choiceName.toLowerCase().replace(/[^a-z0-9]/gi, "_");

    link.href = URL.createObjectURL(blob);
    link.download = `${choiceName}_voters.csv`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  });

}); // closes your main DOMContentLoaded