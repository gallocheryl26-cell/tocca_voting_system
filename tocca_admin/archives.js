document.addEventListener("DOMContentLoaded", () => {
  /* ---------- Label map for archive types ---------- */
  const TYPE_LABELS = {
    categories: 'Categories',
    questions: 'Name of Awards',      // renamed
    choices: 'Establishments',        // renamed
    results: 'Results',
    voters: 'Voters',
    nomination_process: 'Nomination Process'
  };

  function getTypeLabel(type) {
    if (!type) return '';
    return TYPE_LABELS[type] || (type.charAt(0).toUpperCase() + type.slice(1));
  }

  /* ---------- Toast helpers ---------- */
  let toastEl, toastBody, toastInst;
  function ensureToast() {
    if (!toastEl) {
      toastEl   = document.getElementById('toastMsg');
      toastBody = document.getElementById('toastBody');
      if (toastEl) toastInst = bootstrap.Toast.getOrCreateInstance(toastEl, { delay: 2800 });
    }
  }
  function showToast(msg, ok = true) {
    ensureToast();
    if (!toastEl || !toastBody || !toastInst) { alert(msg); return; }
    toastEl.className = `toast align-items-center text-bg-${ok ? 'success' : 'danger'} border-0`;
    toastBody.textContent = msg;
    toastInst.show();
  }
  function setBtnBusy(btn, text) {
    if (!btn) return () => {};
    const prev = { html: btn.innerHTML, disabled: btn.disabled };
    btn.disabled = true;
    btn.innerHTML = `<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>${text}`;
    return () => { btn.innerHTML = prev.html; btn.disabled = prev.disabled; };
  }

  // 🔍 View Archived Data (per type)
  document.querySelectorAll('.view-archive-section').forEach(btn => {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      const type = this.dataset.type;
      const eventId = this.dataset.eventId;
      const label = getTypeLabel(type);

      const modalEl = document.getElementById('viewArchiveModal');
      const modalTitle = document.getElementById('viewArchiveModalLabel');
      const modalBody = modalEl.querySelector('.modal-body');

      // ✅ Use friendly label instead of raw type
      modalTitle.textContent = `Archived ${label} - Event ${eventId}`;
      modalBody.innerHTML = `
        <div class="text-center py-4">
          <div class="spinner-border" role="status" aria-hidden="true"></div>
          <div class="mt-2 small">Loading…</div>
        </div>`;

      if (type === 'results') {
        loadArchivedResults(eventId, modalBody);
        const modal = new bootstrap.Modal(modalEl);
        modal.show();
        return;
      }

      fetch('get_archives.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'load_archive', type, event_id: eventId })
      })
      .then(res => res.json())
      .then(data => {
        if (data.status === 'success') {
          modalBody.innerHTML = `
            ${data.html}
            <div class="d-flex justify-content-end mt-3">
              <button class="btn btn-sm btn-outline-success" onclick="exportTableToCSV('archived_${type}_event${eventId}.csv')">
                <i class="fas fa-file-csv me-1"></i>Download CSV
              </button>
            </div>
          `;

          const modal = new bootstrap.Modal(modalEl);
          modal.show();

          // Toast confirmation (use friendly label)
          showToast(`Loaded ${label} for Event ${eventId}`);

          // Initialize DataTable if present
          setTimeout(() => {
            const table = document.querySelector('#archiveDataTable');
            const buttonsContainer = document.querySelector('#archiveDataTableButtons');
            if (table && !$.fn.DataTable.isDataTable(table)) {
              const dt = $(table).DataTable({
                pageLength: 5,
                lengthMenu: [5, 10, 25, 50],
                lengthChange: true,
                searching: true,
                ordering: true,
                info: true,
                responsive: true,
                dom: '<"row mb-2"<"col-md-6"l><"col-md-6 text-end"B>>' +
                     '<"row"<"col-12"f>>' +
                     '<"table-responsive"t>' +
                     '<"row mt-2"<"col-md-6"i><"col-md-6"p>>',
                buttons: type === 'voters' ? [] : ['copy', 'csv', 'excel', 'pdf'],
                language: { emptyTable: "No archive data found" }
              });
              setTimeout(() => {
                const dtButtons = document.querySelector('.dt-buttons');
                if (dtButtons && buttonsContainer) {
                  buttonsContainer.innerHTML = '';
                  if (type !== 'voters') buttonsContainer.appendChild(dtButtons);
                }
              }, 100);
            }
          }, 200);
        } else {
          modalBody.innerHTML = `<div class='alert alert-danger mb-0'>No data found for this archive.</div>`;
          showToast('No data found for this archive.', false);
        }
      })
      .catch(() => {
        modalBody.innerHTML = '<div class="alert alert-danger mb-0">Failed to load archive.</div>';
        showToast('Failed to load archive.', false);
      });
    });
  });

  // ♻️ Restore archived event
  document.querySelectorAll('.restore-archive-btn').forEach(button => {
    button.addEventListener('click', () => {
      const eventId = button.getAttribute('data-event-id');
      if (!confirm("Restore this archive? This will set it as the current active event.")) return;

      const done = setBtnBusy(button, 'Restoring…');

      fetch('archive.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'restore', event_id: eventId })
      })
      .then(res => res.json())
      .then(data => {
        if (data.status === 'success') {
          showToast(data.message || 'Event restored.');
          setTimeout(() => location.reload(), 900);
        } else {
          showToast(data.message || 'Restore failed.', false);
        }
      })
      .catch(err => {
        console.error(err);
        showToast("Failed to restore archive.", false);
      })
      .finally(done);
    });
  });

  // 🗑️ Delete archived event
  document.querySelectorAll('.delete-archive-btn').forEach(button => {
    button.addEventListener('click', () => {
      const eventId = button.getAttribute('data-event-id');
      if (!confirm("Permanently delete this archive? This cannot be undone.")) return;

      const done = setBtnBusy(button, 'Deleting…');

      fetch('archive.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete', event_id: eventId })
      })
      .then(res => res.json())
      .then(data => {
        if (data.status === 'success') {
          showToast(data.message || 'Archived event deleted.');
          const item = button.closest('.accordion-item');
          if (item) item.remove();
        } else {
          showToast(data.message || 'Delete failed.', false);
        }
      })
      .catch(err => {
        console.error(err);
        showToast("Failed to delete archive.", false);
      })
      .finally(done);
    });
  });
});

/* ------- Results loader (with renamed columns) ------- */
function loadArchivedResults(eventId, container) {
  container.innerHTML = `
    <div class="text-center py-4">
      <div class="spinner-border" role="status" aria-hidden="true"></div>
      <div class="mt-2 small">Loading results…</div>
    </div>`;

  fetch(`get_archives_result.php?event_id=${eventId}`)
    .then(res => res.json())
    .then(data => {
      if (data.status !== 'success') {
        container.innerHTML = '<div class="alert alert-danger mb-0">Failed to load results.</div>';
        if (typeof showToast === 'function') showToast('Failed to load results.', false);
        return;
      }

      const archiveData = data.data;
      container.innerHTML = '';

      for (const [catId, catData] of Object.entries(archiveData)) {
        const categorySection = document.createElement("div");
        categorySection.className = "mb-5";

        const catTitle = document.createElement("h5");
        catTitle.textContent = `${catData.category_name}`;
        categorySection.appendChild(catTitle);

        const table = document.createElement("table");
        table.className = "table table-bordered table-sm";
        table.innerHTML = `
          <thead class="table-light">
            <tr>
              <th style="width: 30%;">Award</th>          <!-- renamed -->
              <th style="width: 40%;">Establishment</th>  <!-- renamed -->
              <th style="width: 30%;">Vote Count</th>
            </tr>
          </thead>
          <tbody></tbody>
        `;

        const tbody = table.querySelector("tbody");

        for (const [qId, qData] of Object.entries(catData.questions)) {
          const choices = qData.results || [];
          choices.forEach((res, index) => {
            const row = document.createElement("tr");
            row.innerHTML = `
              <td>${index === 0 ? qData.question_name : ''}</td>
              <td>${res.choice_name}</td>
              <td>${res.vote_count}</td>
            `;
            tbody.appendChild(row);
          });
        }

        categorySection.appendChild(table);
        container.appendChild(categorySection);
      }

      if (typeof showToast === 'function') showToast(`Loaded results for Event ${eventId}`);
    })
    .catch(err => {
      console.error("Error loading archived results:", err);
      container.innerHTML = '<div class="alert alert-danger mb-0">Error loading results.</div>';
      if (typeof showToast === 'function') showToast('Error loading results.', false);
    });
}

/* ------- CSV Export (unchanged) ------- */
function exportTableToCSV(filename) {
  const table = document.querySelector("#archiveDataTable");
  if (!table) return;

  let csv = [];
  const rows = table.querySelectorAll("tr");

  for (let i = 0; i < rows.length; i++) {
    const row = [], cols = rows[i].querySelectorAll("td, th");
    for (let j = 0; j < cols.length; j++) {
      row.push(`"${cols[j].innerText}"`);
    }
    csv.push(row.join(","));
  }

  const csvFile = new Blob([csv.join("\n")], { type: "text/csv" });
  const downloadLink = document.createElement("a");
  downloadLink.download = filename;
  downloadLink.href = window.URL.createObjectURL(csvFile);
  downloadLink.style.display = "none";
  document.body.appendChild(downloadLink);
  downloadLink.click();
  document.body.removeChild(downloadLink);
}
