<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/admin_init.php';
admin_apply_nav_from_script(basename(__FILE__));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <script src="js/instant_theme_init.js"></script>
  <meta charset="utf-8" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
  <title>TWG Notification Email | Tatak Ormoc</title>
  <link rel="icon" type="image/png" href="<?php echo $faviconPath; ?>">
  <link href="css/styles.css" rel="stylesheet" />
  <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous" defer></script>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <?php include 'inline_style.php'; ?>
  <style>
    .recipient-row { transition: background .15s; }
    .recipient-row.sent { background: rgba(25,135,84,.08); }
    .recipient-row.failed { background: rgba(220,53,69,.08); }
    .email-preview-frame { border: 1px solid #dee2e6; border-radius: 8px; padding: 24px; background: #fff; max-height: 500px; overflow-y: auto; }
    html.dark-mode .email-preview-frame { background: #1e1e2f; border-color: #444; }
    .progress-bar-sending { transition: width .3s ease; }
  </style>
</head>

<body class="sb-nav-fixed">
  <?php include __DIR__ . '/partials/admin_topnav.php'; ?>
  <div id="layoutSidenav">
    <?php include __DIR__ . '/partials/admin_sidebar.php'; ?>
    <div id="layoutSidenav_content">
      <main>
        <div class="container-fluid px-4">
          <div class="admin-page-header mt-4 mb-4">
            <div class="min-w-0">
              <h1 class="admin-page-title mb-2"><i class="bi bi-envelope-paper me-2"></i>TWG Notification Email</h1>
              <?php echo render_utilities_breadcrumb([['label' => 'Communications'], ['label' => 'TWG Notification']]); ?>
            </div>
          </div>

          <?php echo render_admin_event_context(); ?>

          <!-- Step 1: Preview recipients -->
          <div class="card shadow-sm border-0 mb-4" id="cardPreview">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center py-3">
              <span class="fw-semibold"><i class="bi bi-people me-1"></i> Recipients (Approved Registrations)</span>
              <button class="btn btn-sm btn-outline-primary" id="btnLoadPreview" type="button">
                <i class="bi bi-arrow-clockwise me-1"></i> Load recipients
              </button>
            </div>
            <div class="card-body">
              <p class="text-muted small mb-3">This will send the TWG Validation notification email to all registrations with status <strong>Approved</strong> (Proceeded to Evaluation). A PDF attachment (<code>TOCCA-2026.pdf</code>) will be included.</p>
              <div id="recipientArea" class="d-none">
                <div class="d-flex align-items-center gap-3 mb-3">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="selectAll" checked>
                    <label class="form-check-label fw-semibold" for="selectAll">Select all</label>
                  </div>
                  <span class="badge bg-primary" id="countBadge">0</span>
                  <span class="text-muted small" id="pdfStatus"></span>
                </div>
                <div class="table-responsive" style="max-height:400px;overflow-y:auto;">
                  <table class="table table-sm table-hover mb-0" id="recipientTable">
                    <thead class="table-light sticky-top">
                      <tr>
                        <th style="width:40px;"></th>
                        <th>Business</th>
                        <th>Email</th>
                        <th>Contact</th>
                        <th style="width:80px;">Status</th>
                      </tr>
                    </thead>
                    <tbody id="recipientBody"></tbody>
                  </table>
                </div>
              </div>
              <div id="loadingArea" class="text-center py-4 d-none">
                <div class="spinner-border text-primary" role="status"></div>
                <p class="mt-2 text-muted">Loading recipients&hellip;</p>
              </div>
              <div id="emptyArea" class="d-none">
                <div class="alert alert-info mb-0"><i class="bi bi-info-circle me-1"></i> No approved registrations found.</div>
              </div>
            </div>
          </div>

          <!-- Email preview -->
          <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-transparent py-3">
              <span class="fw-semibold"><i class="bi bi-eye me-1"></i> Email Preview</span>
            </div>
            <div class="card-body">
              <div class="email-preview-frame" id="emailPreviewFrame">
                <p style="color:#888;">Click <strong>Load recipients</strong> above to see the email preview.</p>
              </div>
            </div>
          </div>

          <!-- Send controls -->
          <div class="card shadow-sm border-0 mb-4">
            <div class="card-body">
              <div id="progressArea" class="d-none mb-3">
                <div class="d-flex justify-content-between small text-muted mb-1">
                  <span id="progressLabel">Sending&hellip;</span>
                  <span id="progressCount">0 / 0</span>
                </div>
                <div class="progress" style="height:8px;">
                  <div class="progress-bar progress-bar-sending bg-success" id="progressBar" role="progressbar" style="width:0%"></div>
                </div>
              </div>
              <div id="resultArea" class="d-none mb-3"></div>
              <div class="d-flex gap-2">
                <button class="btn btn-success btn-lg" id="btnSend" type="button" disabled>
                  <i class="bi bi-send me-1"></i> Send TWG Notification Emails
                </button>
              </div>
            </div>
          </div>

        </div>
      </main>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
  <script src="js/scripts.js"></script>
  <script>
  (function() {
    const btnLoad   = document.getElementById('btnLoadPreview');
    const btnSend   = document.getElementById('btnSend');
    const selectAll = document.getElementById('selectAll');
    const tbody     = document.getElementById('recipientBody');
    const countBdg  = document.getElementById('countBadge');
    const pdfStat   = document.getElementById('pdfStatus');
    const progArea  = document.getElementById('progressArea');
    const progBar   = document.getElementById('progressBar');
    const progLabel = document.getElementById('progressLabel');
    const progCount = document.getElementById('progressCount');
    const resultArea = document.getElementById('resultArea');
    const previewFrame = document.getElementById('emailPreviewFrame');

    let allRecipients = [];

    function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    function getSelected() {
      return [...tbody.querySelectorAll('input[type=checkbox]:checked')]
        .map(cb => Number(cb.dataset.id))
        .filter(Boolean);
    }

    function updateCount() {
      const n = getSelected().length;
      countBdg.textContent = n + ' selected';
      btnSend.disabled = n === 0;
    }

    function renderPreview() {
      previewFrame.innerHTML = `
        <div style="background:#f3f4f6;padding:24px 12px;">
          <div style="max-width:600px;margin:auto;font-family:Arial,Helvetica,sans-serif;">
            <div style="height:4px;background:#2563eb;"></div>
            <div style="background:#eee;padding:28px 32px;">
              <div style="color:#111;font-size:11px;letter-spacing:1.6px;font-weight:700;">CITY GOVERNMENT OF ORMOC</div>
              <div style="color:#111;font-size:26px;font-weight:700;margin-top:8px;">Tatak Ormoc</div>
              <div style="color:#6b7280;font-size:14px;margin-top:6px;">Consumers' Choice Awards (TOCCA)</div>
            </div>
            <div style="background:#fff;padding:32px;color:#111;font-size:15px;line-height:1.65;">
              <p>Hello <em>[Business Name]</em>,</p>
              <h1 style="font-size:28px;font-weight:700;margin:0 0 18px;">Registration Verified</h1>
              <p>Thank you for registering for the <strong>2026 Tatak Ormoc Consumers' Choice Awards (TOCCA)</strong>.</p>
              <p>We are pleased to inform you that your registration has successfully passed the <strong>Registration and Verification Phase</strong>. Your entry has been confirmed as qualified and will proceed to the next stage of the 2026 TOCCA Awards Process.</p>
              <h2 style="font-size:18px;font-weight:700;margin:22px 0 10px;">What Happens Next?</h2>
              <p>&#128269; <strong>STEP 2: TWG Validation and Identification of the Top Five (5)</strong><br>
              &#128197; <strong>September 9–15, 2026</strong></p>
              <p>Your entry will undergo validation and evaluation by the Technical Working Group (TWG).</p>
              <p>The validation process will include inspection and scoring based on the applicable evaluation criteria and corresponding weights for your specific award category. The TWG scores will then be consolidated to determine the rankings of all qualified entries.</p>
              <p>The <strong>Top Five (5)</strong> highest-ranking entries in each award category will proceed to the Public Voting Stage.</p>
              <p>The TWG Validation and Inspection will account for <strong>40%</strong> of the Final Award Score.</p>
              <p>Please wait for further announcements and official communication regarding the results of the TWG Validation and the next stage of the Awards Process.</p>
              <p>Thank you once again for being part of the <strong>2026 Tatak Ormoc Consumers' Choice Awards</strong>. We appreciate your participation and wish you the best of luck in the next stage!</p>
              <p>Sincerely,<br><strong>Tatak Ormoc Consumers' Choice Awards (TOCCA) Organizing Committee</strong><br>City Government of Ormoc</p>
            </div>
            <div style="background:#eee;padding:24px 32px;">
              <p style="color:#111;font-size:14px;font-weight:700;">This is an automated message. Please do not reply.</p>
              <p style="color:#6b7280;font-size:12px;">&copy; 2026 Tatak Ormoc Consumers' Choice Awards</p>
            </div>
          </div>
        </div>
        <div class="mt-3 d-flex align-items-center gap-2 small text-muted">
          <i class="bi bi-paperclip"></i> Attachment: <strong>TOCCA-2026.pdf</strong>
        </div>`;
    }

    btnLoad.addEventListener('click', async () => {
      document.getElementById('loadingArea').classList.remove('d-none');
      document.getElementById('recipientArea').classList.add('d-none');
      document.getElementById('emptyArea').classList.add('d-none');
      btnLoad.disabled = true;

      try {
        const res = await fetch('send_twg_notification.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'preview' }),
        });
        const data = await res.json();
        if (data.status !== 'success') throw new Error(data.message || 'Failed');

        allRecipients = data.recipients || [];
        pdfStat.innerHTML = data.pdf_attached
          ? '<i class="bi bi-check-circle text-success me-1"></i>PDF attached'
          : '<i class="bi bi-exclamation-triangle text-warning me-1"></i>PDF not found on server';

        if (allRecipients.length === 0) {
          document.getElementById('emptyArea').classList.remove('d-none');
        } else {
          tbody.innerHTML = allRecipients.map(r => `
            <tr class="recipient-row" data-id="${r.nomination_id}">
              <td><input type="checkbox" class="form-check-input" data-id="${r.nomination_id}" ${r.valid ? 'checked' : 'disabled'}></td>
              <td>${esc(r.business_name || '—')}</td>
              <td>${esc(r.email || '—')} ${!r.valid ? '<span class="badge bg-danger">invalid</span>' : ''}</td>
              <td>${esc(r.contact || '—')}</td>
              <td><span class="badge bg-secondary">pending</span></td>
            </tr>
          `).join('');
          document.getElementById('recipientArea').classList.remove('d-none');
          updateCount();
          renderPreview();
        }
      } catch (err) {
        document.getElementById('emptyArea').innerHTML = `<div class="alert alert-danger mb-0">${esc(err.message)}</div>`;
        document.getElementById('emptyArea').classList.remove('d-none');
      } finally {
        document.getElementById('loadingArea').classList.add('d-none');
        btnLoad.disabled = false;
      }
    });

    selectAll.addEventListener('change', () => {
      tbody.querySelectorAll('input[type=checkbox]:not(:disabled)').forEach(cb => { cb.checked = selectAll.checked; });
      updateCount();
    });
    tbody.addEventListener('change', updateCount);

    btnSend.addEventListener('click', async () => {
      const ids = getSelected();
      if (ids.length === 0) return;

      if (!confirm(`Send the TWG notification email to ${ids.length} recipient(s)?\n\nThis will include the TOCCA-2026.pdf attachment.`)) return;

      btnSend.disabled = true;
      btnLoad.disabled = true;
      progArea.classList.remove('d-none');
      resultArea.classList.add('d-none');

      const BATCH = 5;
      let sent = 0, failed = 0, total = ids.length;
      const failDetails = [];

      function updateProgress() {
        const done = sent + failed;
        const pct = Math.round((done / total) * 100);
        progBar.style.width = pct + '%';
        progCount.textContent = `${done} / ${total}`;
        progLabel.textContent = done < total ? 'Sending…' : 'Done!';
      }

      for (let i = 0; i < ids.length; i += BATCH) {
        const batch = ids.slice(i, i + BATCH);
        try {
          const res = await fetch('send_twg_notification.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'send', nomination_ids: batch }),
          });
          const data = await res.json();
          if (data.status === 'success') {
            sent += (data.ok_count || 0);
            failed += (data.fail_count || 0);
            (data.ok || []).forEach(r => {
              const row = tbody.querySelector(`tr[data-id="${r.nomination_id}"]`);
              if (row) {
                row.classList.add('sent');
                row.querySelector('td:last-child').innerHTML = '<span class="badge bg-success">sent</span>';
              }
            });
            (data.fail || []).forEach(r => {
              failDetails.push(r);
              const row = tbody.querySelector(`tr[data-id="${r.nomination_id}"]`);
              if (row) {
                row.classList.add('failed');
                row.querySelector('td:last-child').innerHTML = '<span class="badge bg-danger">failed</span>';
              }
            });
          } else {
            failed += batch.length;
            batch.forEach(id => failDetails.push({ nomination_id: id, reason: data.message || 'Server error' }));
          }
        } catch (err) {
          failed += batch.length;
          batch.forEach(id => failDetails.push({ nomination_id: id, reason: err.message }));
        }
        updateProgress();
      }

      let html = `<div class="alert alert-${failed === 0 ? 'success' : 'warning'} mb-0">
        <strong>Done!</strong> ${sent} sent, ${failed} failed out of ${total} total.`;
      if (failDetails.length > 0) {
        html += '<ul class="mt-2 mb-0">' + failDetails.map(f =>
          `<li><strong>${esc(f.business || f.nomination_id)}</strong>: ${esc(f.reason)}</li>`
        ).join('') + '</ul>';
      }
      html += '</div>';
      resultArea.innerHTML = html;
      resultArea.classList.remove('d-none');
      btnLoad.disabled = false;
    });

    // Auto-load on page ready
    btnLoad.click();
  })();
  </script>
</body>
</html>
