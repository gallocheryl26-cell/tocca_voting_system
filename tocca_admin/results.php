<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/admin_init.php';
require_once __DIR__ . '/includes/report_export_helpers.php';
admin_apply_nav_from_script(basename(__FILE__));

$resultsEventId = ($conn instanceof mysqli) ? admin_active_event_id($conn) : null;
$resultsExportSettings = ($conn instanceof mysqli) ? report_export_get_settings($conn) : [];
$resultsExportCredentials = [
    'csv'   => report_export_build_credentials_payload($resultsExportSettings, 'csv'),
    'excel' => report_export_build_credentials_payload($resultsExportSettings, 'excel'),
    'pdf'   => report_export_build_credentials_payload($resultsExportSettings, 'pdf'),
];

?>

<!DOCTYPE html>
<html lang="en">
  <head>
        <script src="js/instant_theme_init.js"></script>
  <meta charset="utf-8" />
        <meta http-equiv="X-UA-Compatible" content="IE=edge" />
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
        <meta name="description" content="" />
        <meta name="author" content="" />
        <title>Results | Tatak Ormoc</title>
        <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($faviconPath ?? '', ENT_QUOTES); ?>">
        <link href="css/styles.css" rel="stylesheet" />
        <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous" defer></script>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
        <?php include 'inline_style.php'; ?>
        <style>
      .notif-badge { min-width: 90px; display: inline-block; text-align: center; }
      .notif-text  { flex: 1; }
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
              <h1 class="admin-page-title mb-2">Results</h1>
              <?php echo render_reports_breadcrumb([['label' => 'Results']]); ?>
            </div>
          </div>
          <?php echo render_admin_event_context(); ?>

                        <div class="mb-3">
                            <div class="d-flex flex-wrap gap-3">
                                <select class="form-select w-auto" id="resultCategoryDropdown">
                                <option value="" selected disabled>Choose a category</option>
                                </select>
                                <select class="form-select w-auto" id="resultQuestionDropdown">
                                    <option value="" selected disabled>Choose an award</option>
                                </select>

                                <button class="btn btn-primary" id="viewResultBtn">View Results</button>
                            </div>
                        </div>

                        <div class="card shadow-sm border-0 admin-table-card mb-4">
                            <div class="card-header bg-transparent d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
                                <span class="fw-semibold mb-0"><i class="fas fa-table me-1"></i> Result Table</span>
                                <button type="button" class="btn btn-success btn-sm shrink-0" data-bs-toggle="modal" data-bs-target="#downloadResultsModal">
                                    <i class="fas fa-download me-1"></i> Download
                                </button>
                            </div>

                            <div class="card-body">
                                <div class="table-responsive">
                                    <table id="resultsTable" class="table table-striped table-bordered admin-data-table w-100 mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Establisments</th>
                                                <th>Votes</th>
                                                <th>Standing</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="tableBody">
                                            <tr>
                                                <td colspan="4" class="text-center text-muted">
                                                Select a category and a name of award to see results.
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <div class="modal fade" id="voterModal" tabindex="-1" aria-labelledby="voterModalLabel" aria-hidden="true">
                          <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                              <div class="modal-header">
                                <h5 class="modal-title" id="voterModalLabel">
                                  Voters for: <span id="voterModalChoiceName" class="text-primary fw-semibold">[Choice]</span>
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                              </div>
                              <div class="modal-body">
                                <div class="mb-3">
                                  <strong>Total Voters:</strong> <span id="totalVoterCount" class="text-success">0</span>
                                </div>
                                <div class="table-responsive">
                                  <table class="table table-striped table-bordered table-sm admin-data-table w-100 mb-0">
                                    <thead class="table-light">
                                      <tr>
                                        <th>#</th>
                                        <th>Voter ID</th>
                                        <th>Phone Number</th>
                                        <th>Date Voted</th>
                                      </tr>
                                    </thead>
                                    <tbody id="voterListTable">
                                    </tbody>
                                  </table>
                                </div>
                              </div>
                              <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                                <button type="button" id="downloadVotersCSVBtn" class="btn btn-success"><i class="fas fa-download me-1"></i>Download</button>
                              </div>
                            </div>
                          </div>
                        </div>

                        <div class="modal fade" id="downloadResultsModal" tabindex="-1" aria-labelledby="downloadResultsModalLabel" aria-hidden="true">
                          <div class="modal-dialog">
                            <div class="modal-content">
                              <div class="modal-header">
                                <h5 class="modal-title" id="downloadResultsModalLabel">
                                  <i class="fas fa-file-download me-2"></i>Download Voting Results
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
                                  <label for="downloadTopNumber" class="form-label fw-semibold">Top Standings</label>
                                  <div class="input-group">
                                    <input type="number" id="downloadTopNumber" class="form-control" min="0" placeholder="Enter number (0 for all)">
                                    <span class="input-group-text">entries</span>
                                  </div>
                                  <small class="text-muted">Enter 0 to include all results.</small>
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

                    </div>
                </main>
      <footer class="py-4 bg-light mt-auto">
        <div class="container-fluid px-4">
          <div class="small text-muted">&copy; 2026 Tatak Ormoc Consumers&rsquo; Choice Awards</div>
        </div>
      </footer>
</div>
        </div>
  <?php include __DIR__ . '/partials/admin_legacy_footer.php'; ?>
        <script>
        window.TOCCA_EXPORT_CREDENTIALS = <?php echo json_encode($resultsExportCredentials, JSON_UNESCAPED_UNICODE); ?>;
        const currentEventId = <?php echo json_encode($resultsEventId); ?>;
        </script>
        <script src="results.js"></script>
</body>
</html>
