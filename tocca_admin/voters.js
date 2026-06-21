document.addEventListener("DOMContentLoaded", () => {
  function escapeHtml(value) {
    return String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  const tableId = "#voterListTable";
  const tbody = document.querySelector(`${tableId} tbody`);
  const downloadActivityBtn = document.getElementById("downloadActivityBtn");

  function loadVoters() {
    const params = new URLSearchParams({ ts: String(Date.now()) });
    if (typeof currentEventId === "number" && currentEventId > 0) {
      params.set("event_id", String(currentEventId));
    }

    fetch("voter.php?" + params.toString(), { credentials: "same-origin" })
      .then(res => {
        if (!res.ok) {
          throw new Error("HTTP " + res.status);
        }
        return res.json();
      })
      .then(data => {
        if (data.status === "success" && Array.isArray(data.data)) {
          console.log("Voter data loaded:", data.data);

          if ($.fn.DataTable.isDataTable(tableId)) {
            $(tableId).DataTable().clear().destroy();
          }

          tbody.innerHTML = "";

          if (data.data.length === 0) {
            tbody.innerHTML = `<tr><td colspan="5" class="text-center">No voter data found.</td></tr>`;
            return;
          }

          data.data.forEach(v => {
            const statusRaw = v.status ?? 'unknown';
            const statusText = statusRaw.charAt(0).toUpperCase() + statusRaw.slice(1);
            let badgeClass = 'secondary';
            if (statusRaw === 'completed') badgeClass = 'success';
            else if (statusRaw === 'drafted') badgeClass = 'warning';
            else if (statusRaw === 'not started') badgeClass = 'danger';

            const rowHTML = `
              <tr>
                <td>${v.voters_id}</td>
                <td>${v.mobile_number}</td>
                <td>${v.date_verified}</td>
                <td><span class="badge bg-${badgeClass} text-capitalize">${statusText}</span></td>
                <td>
                  <button class="btn btn-sm btn-primary viewActivityBtn" data-voterid="${v.voters_id}">
                    Details
                  </button>
                </td>
              </tr>`;
            tbody.insertAdjacentHTML("beforeend", rowHTML);
          });

          $(tableId).DataTable({
            pageLength: 10,
            lengthMenu: [5, 10, 25, 50],
            searching: true,
            ordering: true,
            responsive: true,
            info: true
          });
        } else {
          const msg = data.message || "Failed to load voters.";
          tbody.innerHTML = `<tr><td colspan="5" class="text-center text-danger">${msg}</td></tr>`;
        }
      })
      .catch(err => {
        console.error("Error fetching voters:", err);
        tbody.innerHTML = `<tr><td colspan="5" class="text-center text-danger">An error occurred while fetching voters.</td></tr>`;
      });
  }

  loadVoters();

  // ---- Download (matches Nomination Report flow) ----------------------------
  const btnConfirmDownload = document.getElementById("confirmDownloadVoters");
  const ddlFormat = document.getElementById("downloadFormat");
  const downloadModalEl = document.getElementById("downloadVotersModal");
  const credentialsModalEl = document.getElementById("exportCredentialsModal");
  const credentialsBodyEl = document.getElementById("exportCredentialsBody");
  const btnConfirmExportWithCredentials = document.getElementById("confirmExportWithCredentials");
  let pendingDownloadParams = null;

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
    bootstrap.Toast.getOrCreateInstance(el).show();
  }

  function requireActiveEvent() {
    if (typeof currentEventId === "number" && currentEventId > 0) {
      return true;
    }
    toast("No active event. Activate an event under File Maintenance → Events.", false);
    return false;
  }

  function buildDownloadParams(format) {
    const params = new URLSearchParams();
    if (typeof currentEventId === "number" && currentEventId > 0) {
      params.set("event_id", String(currentEventId));
    }
    params.set("format", format || "csv");
    return params;
  }

  function hideDownloadModal() {
    if (!downloadModalEl) return;
    const modal = bootstrap.Modal.getInstance(downloadModalEl) || new bootstrap.Modal(downloadModalEl);
    modal.hide();
  }

  function parseContentDispositionFilename(headerValue) {
    if (!headerValue) return "";
    const match = /filename\*=UTF-8''([^;]+)|filename="([^"]+)"|filename=([^;]+)/i.exec(headerValue);
    const raw = (match && (match[1] || match[2] || match[3])) || "";
    try {
      return decodeURIComponent(raw.replace(/['"]/g, "").trim());
    } catch (_) {
      return raw.replace(/['"]/g, "").trim();
    }
  }

  function defaultExportFilename(format) {
    const stamp = new Date();
    const pad = (n) => String(n).padStart(2, "0");
    const name = "TOCCA_VotersList_"
      + stamp.getFullYear()
      + pad(stamp.getMonth() + 1)
      + pad(stamp.getDate())
      + "_"
      + pad(stamp.getHours())
      + pad(stamp.getMinutes());
    if (format === "pdf") return name + ".pdf";
    if (format === "excel") return name + ".xlsx";
    return name + ".csv";
  }

  function triggerBlobDownload(blob, filename) {
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = filename || "VotersList.dat";
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  function escHtml(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, (ch) => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#39;"
    }[ch]));
  }

  function fetchExportPreflight(params) {
    const preflightParams = new URLSearchParams(params.toString());
    preflightParams.set("preflight", "1");
    return fetch("voters_download_report.php?" + preflightParams.toString(), {
      cache: "no-store",
      credentials: "same-origin"
    })
      .then((res) => res.text())
      .then((text) => {
        let data;
        try { data = JSON.parse(text); }
        catch (_) { throw new Error("Could not read export settings."); }
        if (data.status !== "success") {
          throw new Error(data.message || "Preflight failed");
        }
        return data;
      });
  }

  function triggerFileDownload(params) {
    const url = "voters_download_report.php?" + params.toString();
    return fetch(url, { cache: "no-store", credentials: "same-origin" })
      .then((res) => {
        if (!res.ok) {
          return res.text().then((t) => {
            throw new Error(t || ("Download failed (HTTP " + res.status + ")"));
          });
        }
        const format = params.get("format") || "csv";
        const filename = parseContentDispositionFilename(res.headers.get("Content-Disposition"))
          || defaultExportFilename(format);
        return res.blob().then((blob) => {
          triggerBlobDownload(blob, filename);
          toast("Download started.", true);
        });
      });
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
      return triggerFileDownload(params);
    }
    const modal = bootstrap.Modal.getInstance(credentialsModalEl) || new bootstrap.Modal(credentialsModalEl);
    modal.show();
    return Promise.resolve();
  }

  function handleDownloadClick() {
    if (!ddlFormat) return;
    if (!requireActiveEvent()) return;

    const format = (ddlFormat.value || "csv").trim();
    const params = buildDownloadParams(format);

    hideDownloadModal();
    if (btnConfirmDownload) btnConfirmDownload.disabled = true;

    fetchExportPreflight(params)
      .then((preData) => {
        if (preData.credentials && preData.credentials.show) {
          return showCredentialsModal(preData.credentials, params);
        }
        return triggerFileDownload(params);
      })
      .catch((err) => {
        console.error(err);
        toast(err.message || "Download failed.", false);
      })
      .finally(() => {
        if (btnConfirmDownload) btnConfirmDownload.disabled = false;
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
      btnConfirmExportWithCredentials.disabled = true;
      triggerFileDownload(params)
        .then(() => {
          if (credentialsModalEl) {
            const modal = bootstrap.Modal.getInstance(credentialsModalEl);
            if (modal) modal.hide();
          }
        })
        .catch((err) => {
          console.error(err);
          toast(err.message || "Download failed.", false);
        })
        .finally(() => {
          btnConfirmExportWithCredentials.disabled = false;
        });
    });
  }

  // ✅ Handle modal and dynamic download link
  document.body.addEventListener("click", e => {
    if (e.target.classList.contains("viewActivityBtn")) {
      const voterId = e.target.dataset.voterid;

      console.log("Selected voterId for activity log:", voterId);

      // ✅ Guard: only apply if voterId is valid
      if (voterId && !isNaN(voterId)) {
        const downloadUrl = `download_activity_log.php?voters_id=${voterId}`;
        downloadActivityBtn.setAttribute("href", downloadUrl);
        console.log("Set downloadActivityBtn href:", downloadUrl);
      } else {
        console.warn("Invalid voterId for download.");
      }

      const modalBody = document.querySelector("#voterActivityModal tbody");
      modalBody.innerHTML = "";

      const activityParams = new URLSearchParams({ voter_id: String(voterId) });
      if (typeof currentEventId === "number" && currentEventId > 0) {
        activityParams.set("event_id", String(currentEventId));
      }
      fetch(`get_voter_activity.php?${activityParams.toString()}`, { credentials: "same-origin" })
        .then(res => res.json())
        .then(data => {
          if (data.status === "success" && Array.isArray(data.data)) {
            if (data.data.length === 0) {
              modalBody.innerHTML = `<tr><td colspan="3" class="text-center">No activity found for this voter.</td></tr>`;
            } else {
              const grouped = {};
              data.data.forEach(entry => {
                const date = entry.date.split(" ")[0];
                if (!grouped[date]) grouped[date] = [];
                grouped[date].push(entry);
              });

              for (const date in grouped) {
                modalBody.insertAdjacentHTML("beforeend", `<tr><td colspan="3" class="fw-bold bg-light">${date}</td></tr>`);
                grouped[date].forEach(item => {
                  const answerText = escapeHtml(item.answer || "");
                  const questionText = escapeHtml(item.question_name || "");
                  const manualLabel = item.answer_type === "manual"
                    ? ` <span class="badge text-bg-secondary ms-1">Manually typed</span>`
                    : "";
                  modalBody.insertAdjacentHTML("beforeend", `
                    <tr>
                      <td></td>
                      <td>${questionText}</td>
                      <td>${answerText}${manualLabel}</td>
                    </tr>`);
                });
              }
            }

            new bootstrap.Modal(document.getElementById("voterActivityModal")).show();
          } else {
            modalBody.innerHTML = `<tr><td colspan="3" class="text-center text-danger">Unable to fetch activity.</td></tr>`;
          }
        })
        .catch(err => {
          console.error("Error fetching voter activity:", err);
          modalBody.innerHTML = `<tr><td colspan="3" class="text-center text-danger">An error occurred while loading activity.</td></tr>`;
        });
    }
  });
});