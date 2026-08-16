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
      .results-top10-row { background-color: rgba(255, 193, 7, 0.08); }
      html.dark-mode .results-top10-row { background-color: rgba(255, 193, 7, 0.12); }
      #resultsTable td,
      #twgResultsTable td { vertical-align: middle; }
      .results-tabs {
        border: 0;
        background: rgba(15, 23, 42, 0.04);
        padding: .35rem;
        border-radius: 14px;
        gap: .35rem;
        display: inline-flex;
        flex-wrap: wrap;
      }
      .results-tabs .nav-link {
        border: 0;
        border-radius: 10px;
        font-weight: 600;
        color: #475569;
        padding: .55rem 1rem;
        display: inline-flex;
        align-items: center;
        gap: .5rem;
      }
      .results-tabs .nav-link.active {
        background: linear-gradient(135deg, #1d4ed8, #2563eb);
        color: #fff;
        box-shadow: 0 6px 16px -8px rgba(37, 99, 235, .55);
      }
      html.dark-mode .results-tabs { background: rgba(148, 163, 184, 0.12); }
      html.dark-mode .results-tabs .nav-link { color: #cbd5e1; }
      html.dark-mode .results-tabs .nav-link.active { color: #fff; }
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

                        <div class="card border-0 shadow-sm mb-3">
                          <div class="card-body py-3">
                            <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
                              <div>
                                <div class="text-uppercase small text-muted fw-semibold mb-1">Official formula</div>
                                <p class="mb-1 fw-semibold">Final score = (TWG × 30%) + (Community polling × 70%)</p>
                                <p class="mb-0 small text-muted">
                                  Community score (out of 10) = vote share × 10.
                                  Vote share = this business’s valid votes ÷ total valid votes for the selected award.
                                  TWG 30% uses the average of the five member scores from Reports → TWG Evaluation. Blank TWG counts as 0.
                                </p>
                              </div>
                              <div class="d-flex flex-wrap gap-2 align-items-center">
                                <a class="btn btn-outline-primary btn-sm" href="twg_evaluation.php">TWG Evaluation</a>
                                <span class="badge rounded-pill text-bg-light border">TWG 30%</span>
                                <span class="badge rounded-pill text-bg-light border">Community 70%</span>
                                <span class="badge rounded-pill text-bg-warning">Top 10</span>
                              </div>
                            </div>
                          </div>
                        </div>

                        <div class="card border-0 shadow-sm mb-3">
                          <div class="card-body">
                            <div class="row g-3 align-items-end">
                              <div class="col-md-6 col-xl-3">
                                <label class="form-label small text-muted mb-1" for="resultCategoryDropdown">Category</label>
                                <select class="form-select" id="resultCategoryDropdown">
                                  <option value="" selected>All categories</option>
                                </select>
                              </div>
                              <div class="col-md-6 col-xl-3">
                                <label class="form-label small text-muted mb-1" for="resultQuestionDropdown">Award</label>
                                <select class="form-select" id="resultQuestionDropdown">
                                  <option value="" selected>All awards</option>
                                </select>
                              </div>
                              <div class="col-md-6 col-xl-3">
                                <label class="form-label small text-muted mb-1" for="resultBusinessDropdown">Business</label>
                                <select class="form-select" id="resultBusinessDropdown">
                                  <option value="" selected>All businesses</option>
                                </select>
                              </div>
                              <div class="col-md-6 col-xl-3">
                                <label class="form-label small text-muted mb-1" for="resultBusinessSearch">Search business</label>
                                <input class="form-control" type="search" id="resultBusinessSearch" placeholder="Type a business name…" autocomplete="off">
                              </div>
                              <div class="col-12 d-flex flex-wrap gap-2">
                                <button class="btn btn-primary" id="viewResultBtn" type="button">View results</button>
                                <div class="form-check form-switch align-self-center ms-1">
                                  <input class="form-check-input" type="checkbox" id="top10OnlyToggle">
                                  <label class="form-check-label small" for="top10OnlyToggle">Show Top 10 only</label>
                                </div>
                              </div>
                            </div>
                          </div>
                        </div>

                        <div id="resultsSummary" class="row g-3 mb-3 d-none">
                          <div class="col-sm-6 col-xl-3">
                            <div class="card border-0 shadow-sm h-100">
                              <div class="card-body py-3">
                                <div class="small text-muted text-uppercase">Total votes</div>
                                <div class="fs-4 fw-semibold" id="statTotalVotes">0</div>
                              </div>
                            </div>
                          </div>
                          <div class="col-sm-6 col-xl-3">
                            <div class="card border-0 shadow-sm h-100">
                              <div class="card-body py-3">
                                <div class="small text-muted text-uppercase">Nominees</div>
                                <div class="fs-4 fw-semibold" id="statNominees">0</div>
                              </div>
                            </div>
                          </div>
                          <div class="col-sm-6 col-xl-3">
                            <div class="card border-0 shadow-sm h-100">
                              <div class="card-body py-3">
                                <div class="small text-muted text-uppercase">Leader</div>
                                <div class="fs-6 fw-semibold text-truncate" id="statLeader">—</div>
                                <div class="small text-muted" id="statLeaderScore"></div>
                              </div>
                            </div>
                          </div>
                          <div class="col-sm-6 col-xl-3">
                            <div class="card border-0 shadow-sm h-100">
                              <div class="card-body py-3">
                                <div class="small text-muted text-uppercase">TWG scores entered</div>
                                <div class="fs-4 fw-semibold" id="statTwgEntered">0</div>
                              </div>
                            </div>
                          </div>
                        </div>

                        <ul class="nav nav-pills results-tabs mb-3" id="resultsTabs" role="tablist">
                          <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="finalScoreTab" data-bs-toggle="tab" data-bs-target="#finalScorePane" type="button" role="tab" aria-controls="finalScorePane" aria-selected="true">
                              <i class="fas fa-trophy"></i> Final score
                            </button>
                          </li>
                          <li class="nav-item" role="presentation">
                            <button class="nav-link" id="twgResultTab" data-bs-toggle="tab" data-bs-target="#twgResultPane" type="button" role="tab" aria-controls="twgResultPane" aria-selected="false">
                              <i class="fas fa-clipboard-check"></i> TWG results
                            </button>
                          </li>
                        </ul>

                        <div class="tab-content mb-4" id="resultsTabContent">
                          <div class="tab-pane fade show active" id="finalScorePane" role="tabpanel" aria-labelledby="finalScoreTab" tabindex="0">
                            <div class="card shadow-sm border-0 admin-table-card">
                              <div class="card-header bg-transparent d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
                                <div>
                                  <span class="fw-semibold mb-0 d-block"><i class="fas fa-trophy me-1"></i> Standing by final score</span>
                                  <span class="small text-muted">Community votes (70%) combined with TWG average (30%).</span>
                                </div>
                                <button type="button" class="btn btn-success btn-sm shrink-0" data-bs-toggle="modal" data-bs-target="#downloadResultsModal">
                                  <i class="fas fa-download me-1"></i> Download
                                </button>
                              </div>
                              <div class="card-body">
                                <div class="table-responsive">
                                  <table id="resultsTable" class="table table-striped table-bordered admin-data-table w-100 mb-0">
                                    <thead class="table-light">
                                      <tr>
                                        <th>Standing</th>
                                        <th>Business</th>
                                        <th>Votes</th>
                                        <th>Share</th>
                                        <th>Community 70%</th>
                                        <th>TWG 30%</th>
                                        <th>Final</th>
                                        <th>Actions</th>
                                      </tr>
                                    </thead>
                                    <tbody id="tableBody">
                                      <tr>
                                        <td colspan="8" class="text-center text-muted">
                                          Select a category and award, then view results.
                                        </td>
                                      </tr>
                                    </tbody>
                                  </table>
                                </div>
                              </div>
                            </div>
                          </div>

                          <div class="tab-pane fade" id="twgResultPane" role="tabpanel" aria-labelledby="twgResultTab" tabindex="0">
                            <div class="card shadow-sm border-0 admin-table-card">
                              <div class="card-header bg-transparent d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
                                <div>
                                  <span class="fw-semibold mb-0 d-block"><i class="fas fa-clipboard-check me-1"></i> Standing by TWG average</span>
                                  <span class="small text-muted">Member scores (1–10) from TWG Evaluation. This list loads automatically for the active event.</span>
                                </div>
                                <a class="btn btn-outline-primary btn-sm shrink-0" href="twg_evaluation.php">Open TWG Evaluation</a>
                              </div>
                              <div class="card-body">
                                <div class="table-responsive">
                                  <table id="twgResultsTable" class="table table-striped table-bordered admin-data-table w-100 mb-0">
                                    <thead class="table-light" id="twgResultsHead">
                                      <tr>
                                        <th>Standing</th>
                                        <th>Business</th>
                                        <th>Award</th>
                                        <th>LGU 1</th>
                                        <th>LGU 2</th>
                                        <th>BPLO</th>
                                        <th>LEDIPO</th>
                                        <th>ORCHAM</th>
                                        <th>TWG average</th>
                                        <th>Scored</th>
                                      </tr>
                                    </thead>
                                    <tbody id="twgTableBody">
                                      <tr>
                                        <td colspan="10" class="text-center text-muted">
                                          Loading TWG scores…
                                        </td>
                                        </td>
                                      </tr>
                                    </tbody>
                                  </table>
                                </div>
                              </div>
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
                                        <th></th>
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
                                  <small class="text-muted">Enter 0 to include all results. Rank uses the official 30/70 final score.</small>
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
        <script src="results.js?v=<?php echo (int) (@filemtime(__DIR__ . '/results.js') ?: time()); ?>"></script>
</body>
</html>
