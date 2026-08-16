<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/admin_init.php';
admin_apply_nav_from_script();

$twgEventId = ($conn instanceof mysqli) ? admin_active_event_id($conn) : null;
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <script src="js/instant_theme_init.js"></script>
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <title>TWG Evaluation | Tatak Ormoc</title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($faviconPath ?? '', ENT_QUOTES); ?>">
    <link href="css/styles.css" rel="stylesheet" />
    <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous" defer></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <?php include 'inline_style.php'; ?>
    <style>
      .twg-score-input { max-width: 4.5rem; }
      #twgSheetTable td, #twgSheetTable th { vertical-align: middle; }
      .twg-avg { font-variant-numeric: tabular-nums; }
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
                <h1 class="admin-page-title mb-2">TWG Evaluation</h1>
                <?php echo render_reports_breadcrumb([['label' => 'TWG Evaluation']]); ?>
              </div>
            </div>
            <?php echo render_admin_event_context(); ?>

            <div class="card border-0 shadow-sm mb-3">
              <div class="card-body py-3">
                <p class="mb-1 fw-semibold">Five TWG members score each registered business 1–10 for the selected award.</p>
                <p class="mb-0 small text-muted">
                  Members: 2 LGU heads, BPLO, LEDIPO, and ORCHAM.
                  Score here, or download the Excel sheet for onsite visits, fill scores 1–10 on paper or in the file, then import it back.
                  The average of entered scores becomes the <strong>TWG weighted average</strong> used as 30% of the final score on Reports → Results.
                </p>
              </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
              <div class="card-body">
                <div class="row g-3 align-items-end">
                  <div class="col-md-4">
                    <label class="form-label small text-muted mb-1" for="twgCategoryDropdown">Category</label>
                    <select class="form-select" id="twgCategoryDropdown">
                      <option value="" selected disabled>Choose a category</option>
                    </select>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label small text-muted mb-1" for="twgQuestionDropdown">Award</label>
                    <select class="form-select" id="twgQuestionDropdown">
                      <option value="" selected disabled>Choose an award</option>
                    </select>
                  </div>
                  <div class="col-md-4">
                    <button class="btn btn-primary" type="button" id="twgLoadBtn">Open score sheet</button>
                  </div>
                </div>
              </div>
            </div>

            <div id="twgSummary" class="row g-3 mb-3 d-none">
              <div class="col-sm-6 col-xl-4">
                <div class="card border-0 shadow-sm h-100">
                  <div class="card-body py-3">
                    <div class="small text-muted text-uppercase">Registered businesses</div>
                    <div class="fs-4 fw-semibold" id="twgStatNominees">0</div>
                  </div>
                </div>
              </div>
              <div class="col-sm-6 col-xl-4">
                <div class="card border-0 shadow-sm h-100">
                  <div class="card-body py-3">
                    <div class="small text-muted text-uppercase">Fully scored (5/5)</div>
                    <div class="fs-4 fw-semibold" id="twgStatComplete">0</div>
                  </div>
                </div>
              </div>
              <div class="col-sm-6 col-xl-4">
                <div class="card border-0 shadow-sm h-100">
                  <div class="card-body py-3">
                    <div class="small text-muted text-uppercase">Partial scores</div>
                    <div class="fs-4 fw-semibold" id="twgStatPartial">0</div>
                  </div>
                </div>
              </div>
            </div>

            <div class="card shadow-sm border-0 admin-table-card mb-4">
              <div class="card-header bg-transparent d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
                <span class="fw-semibold mb-0"><i class="bi bi-clipboard-check me-1"></i> Score sheet</span>
                <div class="d-flex flex-wrap gap-2">
                  <div class="btn-group">
                    <button class="btn btn-outline-success btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" id="twgExportMenu">
                      <i class="bi bi-download me-1"></i> Download
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                      <li><button class="dropdown-item" type="button" id="twgExportAwardXlsx">This award (Excel)</button></li>
                      <li><button class="dropdown-item" type="button" id="twgExportAwardCsv">This award (CSV)</button></li>
                      <li><hr class="dropdown-divider"></li>
                      <li><button class="dropdown-item" type="button" id="twgExportAllXlsx">All awards (Excel)</button></li>
                      <li><button class="dropdown-item" type="button" id="twgExportAllCsv">All awards (CSV)</button></li>
                    </ul>
                  </div>
                  <button class="btn btn-outline-secondary btn-sm" type="button" id="twgImportBtn">
                    <i class="bi bi-upload me-1"></i> Import scores
                  </button>
                  <input class="d-none" type="file" id="twgImportFile" accept=".xlsx,.xls,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv">
                  <a class="btn btn-outline-primary btn-sm" href="results.php">View Results</a>
                </div>
              </div>
              <div class="card-body">
                <div class="table-responsive">
                  <table id="twgSheetTable" class="table table-striped table-bordered admin-data-table w-100 mb-0">
                    <thead class="table-light" id="twgSheetHead">
                      <tr>
                        <th>Business</th>
                        <th colspan="5" class="text-center">TWG scores (1–10)</th>
                        <th>Average</th>
                      </tr>
                    </thead>
                    <tbody id="twgSheetBody">
                      <tr>
                        <td colspan="7" class="text-center text-muted">Select a category and award, then open the score sheet.</td>
                      </tr>
                    </tbody>
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
    <div class="toast-container position-fixed top-0 start-50 translate-middle-x p-3" style="z-index:1080">
      <div id="toastMsg" class="toast align-items-center text-bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="2800">
        <div class="d-flex">
          <div class="toast-body" id="toastBody">Saved</div>
          <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
      </div>
    </div>
    <?php include __DIR__ . '/partials/admin_legacy_footer.php'; ?>
    <script>
      const currentEventId = <?php echo json_encode($twgEventId); ?>;
    </script>
    <script src="twg_evaluation.js?v=<?php echo (int) (@filemtime(__DIR__ . '/twg_evaluation.js') ?: time()); ?>"></script>
  </body>
</html>
