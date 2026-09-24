<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/admin_init.php';
require_once __DIR__ . '/includes/admin_active_event.php';
admin_apply_nav_from_script(basename(__FILE__));

$activeEventId = ($conn instanceof mysqli) ? admin_active_event_id($conn) : null;
$activeEventLabel = ($conn instanceof mysqli && $activeEventId)
    ? admin_get_active_event_label($conn, $activeEventId)
    : '';

$pageTitle = 'Registration';
$useDataTables = true;
$pageScripts = ['nomination_reports.js'];

ob_start();
?>
  <div class="modal fade" id="downloadResultsModal" tabindex="-1" aria-labelledby="downloadResultsModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="downloadResultsModalLabel">
            <i class="fas fa-file-download me-2"></i>Download Registration List
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label for="downloadScope" class="form-label fw-semibold">Scope</label>
            <select id="downloadScope" class="form-select">
              <option value="all">All Categories</option>
              <option value="current">Selected Category &amp; Award</option>
            </select>
          </div>
          <div id="downloadScopeCurrentWrap" class="mb-3 d-none">
            <label for="download_category_id" class="form-label fw-semibold">Category <span class="text-danger">*</span></label>
            <select id="download_category_id" class="form-select" aria-describedby="downloadScopeFeedback">
              <option value="">Select category…</option>
            </select>
            <label for="download_question_id" class="form-label fw-semibold mt-2">Award <span class="text-danger">*</span></label>
            <select id="download_question_id" class="form-select" disabled aria-describedby="downloadScopeFeedback">
              <option value="">Select award…</option>
            </select>
            <div id="downloadScopeFeedback" class="form-text"></div>
          </div>
          <div class="mb-3">
            <label for="downloadStatus" class="form-label fw-semibold">Status</label>
            <select id="downloadStatus" class="form-select">
              <option value="">All statuses</option>
              <option value="pending">Pending</option>
              <option value="in_review">In Review</option>
              <option value="needs_info">Needs Information</option>
              <option value="approved">Approved</option>
              <option value="rejected">Rejected</option>
            </select>
          </div>
          <div class="mb-3">
            <label for="downloadFormat" class="form-label fw-semibold">File Format</label>
            <select id="downloadFormat" class="form-select">
              <option value="csv">CSV</option>
              <option value="excel">Excel</option>
              <option value="pdf">PDF</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-primary" id="confirmDownloadResults">
            <i class="fas fa-download me-1"></i> Download
          </button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="exportCredentialsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content border-warning">
        <div class="modal-header bg-warning-subtle">
          <h5 class="modal-title"><i class="bi bi-shield-lock me-1"></i>Export password required</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="small text-muted mb-3">
            Copy the password below before downloading. It is shown once here so you can share it with authorized recipients.
          </p>
          <div id="exportCredentialsBody"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="button" class="btn btn-primary" id="confirmExportWithCredentials">Download file</button>
        </div>
      </div>
    </div>
  </div>

  <div class="toast-container position-fixed top-0 start-50 translate-middle-x p-3" style="z-index:1080">
    <div id="toastMsg" class="toast align-items-center text-bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="4000">
      <div class="d-flex">
        <div class="toast-body" id="toastBody">Done</div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
      </div>
    </div>
  </div>
<?php
$extraFoot = ob_get_clean();

include __DIR__ . '/partials/admin_layout_start.php';
?>
        <div class="container-fluid px-4">
          <div class="admin-page-header mt-4 mb-4">
            <div class="min-w-0">
              <h1 class="admin-page-title mb-2">Registration</h1>
              <?php echo render_reports_breadcrumb([['label' => 'Registration']]); ?>
            </div>
          </div>

          <?php echo render_admin_event_context(); ?>

          <?php if (!$activeEventId): ?>
          <div class="alert alert-warning" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            No active event is set. Activate an event under <strong>File Maintenance → Events</strong> to view registration reports.
          </div>
          <?php else: ?>
          <p class="text-muted small mb-3">
            Showing registrations for the active event only:
            <strong><?php echo h($activeEventLabel); ?></strong>
          </p>
          <?php endif; ?>

          <div class="card shadow-sm border-0 mb-3">
            <div class="card-body">
              <form id="frmFilters" class="row g-3 align-items-end"<?php echo $activeEventId ? '' : ' aria-disabled="true"'; ?>>
                <div class="col-md-3">
                  <label for="status_filter" class="form-label">Status</label>
                  <select id="status_filter" class="form-select">
                    <option value="">All statuses</option>
                    <option value="pending">Pending</option>
                    <option value="in_review">In Review</option>
                    <option value="needs_info">Needs Information</option>
                    <option value="approved">Approved</option>
                    <option value="rejected">Rejected</option>
                  </select>
                </div>
                <div class="col-md-3">
                  <label for="category_id" class="form-label">Category</label>
                  <select id="category_id" class="form-select">
                    <option value="">All categories</option>
                  </select>
                </div>
                <div class="col-md-3">
                  <label for="question_id" class="form-label">Award</label>
                  <select id="question_id" class="form-select">
                    <option value="">All awards</option>
                  </select>
                </div>
                <div class="col-md-3">
                  <button type="submit" class="btn btn-primary w-100"<?php echo $activeEventId ? '' : ' disabled'; ?>>Apply filters</button>
                </div>
              </form>
              <p class="small text-muted mt-2 mb-0" id="rowCount">0 rows</p>
            </div>
          </div>

          <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-transparent d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
              <span class="fw-semibold mb-0"><i class="fas fa-table me-1"></i> Registration list</span>
              <button type="button" class="btn btn-success btn-sm shrink-0" data-bs-toggle="modal" data-bs-target="#downloadResultsModal"<?php echo $activeEventId ? '' : ' disabled'; ?>>
                <i class="fas fa-download me-1"></i> Download
              </button>
            </div>
            <div class="card-body">
              <div class="table-responsive">
                <table id="tblEstabs" class="table table-striped table-bordered admin-data-table w-100">
                  <thead class="table-light">
                    <tr>
                      <th>Business</th>
                      <th>Email</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody></tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
<script>
  window.TOCCA_NOMINATION_REPORT_EVENT_ID = <?php echo json_encode($activeEventId); ?>;
  window.TOCCA_NOMINATION_REPORT_EVENT_LABEL = <?php echo json_encode($activeEventLabel); ?>;
</script>
<?php include __DIR__ . '/partials/admin_layout_end.php'; ?>
