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

function renderAwardResults() {
  const tableBody = document.getElementById('tableBody');
  const summary = document.getElementById('resultsSummary');
  const top10Only = document.getElementById('top10OnlyToggle')?.checked === true;
  if (!tableBody) return;

  if (!lastAwardPayload || lastAwardPayload.status !== 'success') {
    tableBody.innerHTML = `<tr><td colspan="8" class="text-center text-muted">Select a category and award, then view results.</td></tr>`;
    summary?.classList.add('d-none');
    return;
  }

  const rows = Array.isArray(lastAwardPayload.results) ? lastAwardPayload.results : [];
  const visible = top10Only ? rows.filter((r) => r.top10 || Number(r.rank) <= 10) : rows;

  const totalVotes = Number(lastAwardPayload.total_votes || 0);
  const nominees = Number(lastAwardPayload.nominee_count || rows.length);
  const twgEntered = Number(lastAwardPayload.twg_entered || 0);
  const leader = lastAwardPayload.leader || rows[0] || null;

  if (summary) {
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

  if (visible.length === 0) {
    tableBody.innerHTML = `<tr><td colspan="8" class="text-center text-danger">No results found.</td></tr>`;
    return;
  }

  tableBody.innerHTML = visible.map((result) => {
    const rank = Number(result.rank) || 0;
    const isFreetext = result.choice_id === null || result.is_freetext;
    const cleanText = String(result.choice_name || '').replace(' (manual input)', '');
    const top10 = rank <= 10;
    const twgHref = `twg_evaluation.php?question_id=${encodeURIComponent(String(lastQuestionId || ''))}`;
    const twgCell = isFreetext
      ? '<span class="text-muted">—</span>'
      : (result.twg_average === null || result.twg_average === undefined
        ? `<span class="text-muted">—</span> <a class="small ms-1" href="${twgHref}">Score</a>`
        : `<a href="${twgHref}" class="text-decoration-none">${formatScore(result.twg_average)}</a>`);
    const ballot = result.on_ballot
      ? '<span class="badge rounded-pill text-bg-success">On ballot</span>'
      : (isFreetext ? '' : '<span class="badge rounded-pill text-bg-light border text-muted">Off ballot</span>');

    return `<tr class="${top10 ? 'results-top10-row' : ''}">
      <td>${getStandingBadge(rank)}</td>
      <td>
        <div class="fw-semibold">${escapeHtml(cleanText)}${isFreetext ? ' <span class="text-muted fst-italic">(manual input)</span>' : ''}</div>
        <div class="d-flex flex-wrap gap-1 mt-1">${ballot}${top10 ? '<span class="badge rounded-pill text-bg-warning">Top 10</span>' : ''}</div>
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

  // Load categories and questions
  if (!currentEventId) {
    if (categoryDropdown) {
      categoryDropdown.innerHTML = '<option value="" disabled selected>No active event</option>';
    }
  } else {
  fetch(`result.php?event_id=${currentEventId}`, { credentials: 'same-origin' })
    .then(res => {
      if (res.status === 401) {
        throw new Error('Session expired. Please log in again.');
      }
      return res.json();
    })
    .then(data => {
      if (data.status === 'success') {
        categoryDropdown.innerHTML = '<option value="" disabled selected>Choose a category</option>';
        data.categories.forEach(cat => {
          const option = document.createElement('option');
          option.value = cat.category_id;
          option.textContent = cat.category_name;
          categoryDropdown.appendChild(option);
        });

        questionsByCategory = data.questions_by_category;
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

    questionDropdown.innerHTML = '<option value="" disabled selected>Choose an award</option>';
    relatedQuestions.forEach(q => {
      const option = document.createElement('option');
      option.value = q.question_id;
      option.textContent = q.question_name;
      questionDropdown.appendChild(option);
    });
  });

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
          renderAwardResults();
        } else {
          tableBody.innerHTML = `<tr><td colspan="8" class="text-center text-danger">Error: ${data.message}</td></tr>`;
        }
      })
      .catch(err => {
        console.error('Error fetching results:', err);
        tableBody.innerHTML = `<tr><td colspan="8" class="text-center text-danger">Error loading results.</td></tr>`;
      });
  });

  top10OnlyToggle?.addEventListener('change', () => {
    if (lastAwardPayload) renderAwardResults();
  });


  // Load voters per choice or freetext
  document.body.addEventListener('click', (e) => {
    if (e.target.classList.contains('viewVotersBtn')) {
      const choiceId = e.target.dataset.choice;
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
        url = `result.php?choice_id=${choiceId}&question_id=${questionId}`;
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