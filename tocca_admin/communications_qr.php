<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/admin_init.php';
admin_apply_nav_from_script(basename(__FILE__));

// communication.php — page shell with your existing navbar + sidebar
include 'get_logo.php';
require_once 'breadcrumb.php';
date_default_timezone_set('Asia/Manila');

$default_event_id = admin_active_event_id($conn);
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <script src="js/instant_theme_init.js"></script>
  <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <meta name="description" content="Communications Admin" />
    <title>QR Emails Transaction  | Tatak Ormoc</title>
    <link rel="icon" type="image/png" href="<?php echo $faviconPath; ?>">
    <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet"/>
    <link href="css/styles.css" rel="stylesheet" />
    <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous" defer></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <?php include 'inline_style.php'; ?>
    <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <style>
      .notif-badge { min-width: 90px; display: inline-block; text-align: center; }
      .notif-text  { flex: 1; }
    </style>
    <style>
      /* Communications page: remove horizontal scrollbar and keep tables in-bounds */
:root { --table-col-max: 420px; }

html, body, #layoutSidenav_content { overflow-x: hidden; }

.card .table-responsive {
  overflow-x: hidden; /* prevent inner horizontal bar */
}

.dataTables_wrapper .row {
  margin-left: 0 !important;
  margin-right: 0 !important;
}
.dataTables_wrapper .row > [class^="col-"],
.dataTables_wrapper .row > [class*=" col-"] {
  padding-left: 0 !important;
  padding-right: 0 !important;
}

#tblSent, #tblQr {
  table-layout: fixed;
  width: 100%;
}
#tblSent th, #tblSent td,
#tblQr  th, #tblQr  td {
  white-space: normal;
  overflow-wrap: anywhere; /* modern */
  word-break: break-word;  /* fallback */
}

#tblSent td:nth-child(3), /* To */
#tblSent td:nth-child(4), /* Subject */
#tblQr  td:nth-child(3),  /* To */
#tblQr  td:nth-child(4)   /* Subject */ {
  max-width: var(--table-col-max);
}

/* Rendered QR email HTML in view modal */
.comm-email-body-preview {
  overflow-wrap: anywhere;
  word-break: break-word;
}
html.dark-mode .comm-email-body-preview {
  background-color: #0f172a !important;
  border-color: rgba(148, 163, 184, 0.28) !important;
  color: #e2e8f0 !important;
}
html.dark-mode .comm-email-body-preview div,
html.dark-mode .comm-email-body-preview p,
html.dark-mode .comm-email-body-preview span {
  color: #e2e8f0 !important;
}
html.dark-mode .comm-email-body-preview a {
  color: #93c5fd !important;
}
html.dark-mode .comm-email-body-preview a[style*="background"] {
  color: #ffffff !important;
}
html.dark-mode .comm-qr-preview-box {
  background-color: #0f172a !important;
  border-color: rgba(148, 163, 184, 0.28) !important;
}
html.dark-mode .comm-qr-preview-box .text-muted {
  color: #94a3b8 !important;
}

    </style>
  </head>
  <body class="sb-nav-fixed">
    <?php include __DIR__ . '/partials/admin_topnav.php'; ?>

  <div id="layoutSidenav">
      <!-- Sidebar (modules preserved) -->
      <?php include __DIR__ . '/partials/admin_sidebar.php'; ?>

<div id="layoutSidenav_content">
        <main>
          <!-- QR Emails -->
        <div class="container-fluid px-4">
                  <div class="admin-page-header mt-4 mb-4">
            <div class="min-w-0">
              <h1 class="admin-page-title mb-2">QR Emails</h1>
              <?php echo render_transactions_breadcrumb([['label' => 'QR Emails']]); ?>
            </div>
          </div>
          <?php echo render_admin_event_context(); ?>
        <!-- Filters -->
            <div class="card shadow-sm mb-3">
              <div class="card-body">
                <form id="frmFilters" class="row g-2 align-items-end admin-filter-bar">
                  <input type="hidden" id="event_id" name="event_id" value="<?= (int) ($default_event_id ?? 0) ?>">
                  <div class="col-6 col-md-2">
                    <label class="form-label small text-muted mb-1">From</label>
                    <input id="from" type="date" class="form-control form-control-sm">
                  </div>
                  <div class="col-6 col-md-2">
                    <label class="form-label small text-muted mb-1">To</label>
                    <input id="to" type="date" class="form-control form-control-sm">
                  </div>
                  <div class="col-12 col-md-3">
                    <label class="form-label small text-muted mb-1">Search (recipient or subject)</label>
                    <input id="q" type="text" class="form-control form-control-sm" placeholder="e.g. @gmail.com or 'approved'">
                  </div>
                  <div class="col-6 col-md-1">
                    <label class="form-label small text-muted mb-1">Limit</label>
                    <input id="limit" type="number" value="200" min="10" step="10" class="form-control form-control-sm">
                  </div>
                  <div class="col-12 col-md-auto d-flex flex-wrap gap-2 align-items-end">
                    <button id="btnFilter" class="btn btn-primary btn-sm" type="submit"><i class="bi bi-funnel me-1"></i>Filter</button>
                  </div>
                </form>
              </div>
            </div>

            <!-- QR Emails -->
            <div class="card shadow-sm border-0 admin-table-card mb-4">
            <div class="card-header bg-transparent d-flex flex-wrap gap-2 align-items-center justify-content-between py-3">
                <span class="fw-semibold mb-0"><i class="bi bi-qr-code me-1"></i>QR Emails</span>
                <div class="d-flex align-items-center gap-3 small">
                <span class="text-success">Total: <strong id="qrStatSent">0</strong></span>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                <table class="table table-striped table-bordered admin-data-table align-middle mb-0 w-100" id="tblQr">
                    <thead class="table-light">
                    <tr>
                        <th>To</th>
                        <th>Subject</th>
                        <th>Sent</th>
                        <th>View</th>
                    </tr>
                    </thead>
                    <tbody></tbody>
                </table>
                </div>
            </div>
            </div>
        </div>
        </main>
      <footer class="py-4 bg-light mt-auto">
        <div class="container-fluid px-4">
          <div class="small text-muted">&copy; 2026 Tatak Ormoc Consumers&rsquo; Choice Awards</div>
        </div>
      </footer>
</div>
    </div>

<!-- View Modal -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content border-0 shadow">
      <div class="modal-header border-0 pb-0">
        <div>
          <div class="small text-muted">Outbox ID: <span id="vmId">—</span></div>
          <h5 class="modal-title fw-semibold" id="vmSubject">(no subject)</h5>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body pt-2">
        <div class="row g-3">
          <!-- Meta / Left -->
          <div class="col-12 col-lg-4">
            <div class="card rounded-3 shadow-sm h-100">
              <div class="card-body">
                <div class="d-flex align-items-start justify-content-between mb-3">
                  <div class="small text-muted">To</div>
                  <div id="vmType" class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis">—</div>
                </div>
                <div id="vmTo" class="mb-3 fw-medium">—</div>

                <div class="d-flex align-items-center gap-2 mb-3">
                  <div class="small text-muted">Status</div>
                  <div id="vmStatus"><span class="badge text-bg-secondary">—</span></div>
                </div>

                <div class="row gy-2">
                  <div class="col-6">
                    <div class="small text-muted">Sent</div>
                    <div id="vmSent" class="fw-medium">—</div>
                  </div>
                  <div class="col-6">
                    <div class="small text-muted">Retries</div>
                    <div id="vmRetries" class="fw-medium">—</div>
                  </div>
                </div>

                <div class="mt-3">
                  <div class="small text-danger-emphasis">Error</div>
                  <div id="vmError" class="small text-muted"> </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Body / Right -->
          <div class="col-12 col-lg-8">
            <div class="card rounded-3 shadow-sm h-100">
              <div class="card-body">
                <!-- Rendered body -->
                <div class="d-flex align-items-center justify-content-between mb-2">
                  <div class="small text-muted">Rendered Body</div>
                </div>
                <div id="vmBody" class="comm-email-body-preview p-3 border rounded-3 bg-light-subtle" style="min-height:220px;"></div>

                <!-- QR preview BELOW the body (smaller) -->
                <div class="d-flex align-items-center justify-content-between mt-3 mb-2">
                  <div class="small text-muted">QR Preview</div>
                  <a id="vmQrDownload" href="#" class="btn btn-sm btn-outline-secondary d-none" download>
                    Download QR
                  </a>
                </div>
                <div class="comm-qr-preview-box p-2 border rounded-3 bg-white d-flex justify-content-center align-items-center" style="min-height:120px;">
                  <img id="vmQrImg" alt="QR Code" style="max-width:140px; max-height:140px; display:none;">
                  <div id="vmQrPlaceholder" class="text-muted small">No QR preview</div>
                </div>
              </div>
            </div>
          </div>

        </div> <!-- /.row -->
      </div> <!-- /.modal-body -->

      <div class="modal-footer border-0">
        <button class="btn btn-primary" data-bs-dismiss="modal">Close</button>
      </div>
    </div> <!-- /.modal-content -->
  </div>   <!-- /.modal-dialog -->
</div>     <!-- /.modal -->


    <!-- Toast -->
    <div class="position-fixed bottom-0 start-50 translate-middle-x p-3" style="z-index:1100">
      <div id="toastMsg" class="toast align-items-center text-bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="3000">
        <div class="d-flex">
          <div class="toast-body" id="toastBody">Done.</div>
          <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <?php include __DIR__ . '/partials/admin_datatables_scripts.php'; ?>
    <script src="communications_qr.js"></script>
  
  <?php include __DIR__ . '/partials/admin_legacy_footer.php'; ?>
</body>
</html>
