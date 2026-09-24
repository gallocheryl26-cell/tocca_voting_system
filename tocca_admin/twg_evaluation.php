<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/admin_init.php';
admin_apply_nav_from_script();

$twgEventId = ($conn instanceof mysqli) ? admin_active_event_id($conn) : null;
$twgCategories = [];
$twgQuestionsByCategory = [];
if ($conn instanceof mysqli && $twgEventId) {
  $catActive = function_exists('admin_active_category_sql') ? admin_active_category_sql($conn, 'c') : '1=1';
  $qActive = function_exists('admin_active_question_sql') ? admin_active_question_sql($conn, 'q') : '1=1';
  $catStmt = $conn->prepare(
      "SELECT c.category_id, c.category_name
       FROM tbl_categories c
       WHERE c.event_id = ? AND {$catActive}
         AND EXISTS (
           SELECT 1 FROM tbl_questions q
           WHERE q.category_id = c.category_id AND {$qActive}
         )
       ORDER BY c.category_name ASC"
  );
  if ($catStmt) {
    $catStmt->bind_param('i', $twgEventId);
    $catStmt->execute();
    $catRes = $catStmt->get_result();
    while ($row = $catRes->fetch_assoc()) {
      $twgCategories[] = [
        'category_id' => (int) $row['category_id'],
        'category_name' => (string) $row['category_name'],
      ];
    }
    $catStmt->close();
  }
  $qStmt = $conn->prepare(
      "SELECT q.question_id, q.question_name, q.category_id
       FROM tbl_questions q
       INNER JOIN tbl_categories c ON q.category_id = c.category_id
       WHERE c.event_id = ? AND {$catActive} AND {$qActive}
       ORDER BY c.category_name ASC, q.question_name ASC"
  );
  if ($qStmt) {
    $qStmt->bind_param('i', $twgEventId);
    $qStmt->execute();
    $qRes = $qStmt->get_result();
    while ($row = $qRes->fetch_assoc()) {
      $cid = (string) ((int) $row['category_id']);
      $twgQuestionsByCategory[$cid][] = [
        'question_id' => (int) $row['question_id'],
        'question_name' => (string) $row['question_name'],
      ];
    }
    $qStmt->close();
  }
}
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
      .twg-score-input.is-invalid { border-color: var(--bs-form-invalid-border-color, #dc3545); }
      .twg-score-input:disabled,
      .twg-score-input[readonly] { opacity: 0.8; cursor: not-allowed; }
      .twg-score-feedback { font-size: .7rem; margin-top: .2rem; }
      .twg-award-head { background: rgba(15, 23, 42, 0.03); }
      .twg-entry-row td:first-child { padding-left: 1.75rem; }
      .twg-product-line { font-size: .875rem; }
      .twg-judge-tabs .nav-link { border: 0; font-weight: 600; }
      .twg-judge-tabs .nav-link.active { background: #1d4ed8; }
      .twg-crit-weight { max-width: 6.5rem; }
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
                <p class="mb-1 fw-semibold">Score like the official OVER-ALL TALLY SHEET: each judge grades Taste, Innovation, and Value. Totals add to 100. Overall lists each judge total, the weighted sum, the average of judges who scored, and the ranking.</p>
                <p class="mb-0 small text-muted">
                  Use <strong>By award</strong> to match the Excel. <strong>By business</strong> is one total per judge from <strong>0 to 100</strong>. Empty boxes start at <strong>0</strong> — change only the scores that are not zero. Saved scores stay editable until the business is confirmed for public voting; then they lock. A saved 0 counts in the average, including Feelings. Feelings still skips Top 5. The award average becomes the <strong>TWG 40%</strong> on Reports → Results.
                </p>
              </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
              <div class="card-header bg-transparent d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
                <div class="min-w-0">
                  <span class="fw-semibold mb-0 d-block"><i class="bi bi-people me-1"></i> Judges</span>
                  <span class="d-block small text-muted mt-1">Add a column for each judge (the number can differ by award). Each judge then scores Taste, Innovation, and Value. Equal judge weights mean a simple average of their totals.</span>
                </div>
                <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#twgCriteriaPanel" aria-expanded="false" aria-controls="twgCriteriaPanel" id="twgCriteriaToggle">
                  Edit judges
                </button>
              </div>
              <div class="collapse" id="twgCriteriaPanel">
                <div class="card-body pt-0">
                  <div class="table-responsive">
                    <table class="table table-sm align-middle mb-2" id="twgCriteriaTable">
                      <thead class="table-light">
                        <tr>
                          <th>Judge</th>
                          <th style="width:9rem">Short label</th>
                          <th style="width:7.5rem">Weight</th>
                          <th class="text-end" style="width:4rem"></th>
                        </tr>
                      </thead>
                      <tbody id="twgCriteriaBody"></tbody>
                    </table>
                  </div>
                  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <button class="btn btn-outline-secondary btn-sm" type="button" id="twgCriteriaAdd">
                      <i class="bi bi-plus-lg me-1"></i> Add judge
                    </button>
                    <div class="d-flex flex-wrap gap-2">
                      <button class="btn btn-outline-secondary btn-sm" type="button" id="twgCriteriaRestore">Restore defaults</button>
                      <button class="btn btn-primary btn-sm" type="button" id="twgCriteriaSave">Save criteria</button>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
              <div class="card-header bg-transparent d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
                <div class="min-w-0">
                  <span class="fw-semibold mb-0 d-block"><i class="bi bi-list-check me-1"></i> Scoring items</span>
                  <span class="d-block small text-muted mt-1">These are the Excel columns: Taste &amp; Quality 50, Innovation 20, Portion size &amp; value 30. Each judge’s three scores add up to 100.</span>
                </div>
                <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#twgRubricPanel" aria-expanded="false" aria-controls="twgRubricPanel" id="twgRubricToggle">
                  Edit scoring items
                </button>
              </div>
              <div class="collapse" id="twgRubricPanel">
                <div class="card-body pt-0">
                  <div class="table-responsive">
                    <table class="table table-sm align-middle mb-2" id="twgRubricTable">
                      <thead class="table-light">
                        <tr>
                          <th>Item</th>
                          <th style="width:9rem">Short label</th>
                          <th style="width:7.5rem">Max points</th>
                          <th class="text-end" style="width:4rem"></th>
                        </tr>
                      </thead>
                      <tbody id="twgRubricBody"></tbody>
                    </table>
                  </div>
                  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <button class="btn btn-outline-secondary btn-sm" type="button" id="twgRubricAdd">
                      <i class="bi bi-plus-lg me-1"></i> Add item
                    </button>
                    <button class="btn btn-primary btn-sm" type="button" id="twgRubricSave">Save scoring items</button>
                  </div>
                </div>
              </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
              <div class="card-body">
                <div class="row g-3 align-items-end">
                  <div class="col-md-4">
                    <label class="form-label small text-muted mb-1" for="twgViewMode">Score sheet view</label>
                    <select class="form-select" id="twgViewMode">
                      <option value="award" selected>By award (Excel layout)</option>
                      <option value="business">By business</option>
                    </select>
                  </div>
                  <div class="col-md-4 twg-award-filters">
                    <label class="form-label small text-muted mb-1" for="twgAwardCategory">Category</label>
                    <select class="form-select" id="twgAwardCategory">
                      <option value="" selected>Select category…</option>
                      <?php foreach ($twgCategories as $cat): ?>
                      <option value="<?php echo (int) $cat['category_id']; ?>"><?php echo h($cat['category_name']); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-4 twg-award-filters">
                    <label class="form-label small text-muted mb-1" for="twgAwardQuestion">Award title</label>
                    <select class="form-select" id="twgAwardQuestion" disabled>
                      <option value="" selected>Select an award…</option>
                    </select>
                  </div>
                  <div class="col-md-6 twg-business-filters d-none">
                    <label class="form-label small text-muted mb-1" for="twgBusinessSearch">Search business</label>
                    <input class="form-control" type="search" id="twgBusinessSearch" placeholder="Type a business name…" autocomplete="off">
                  </div>
                  <div class="col-md-6 twg-business-filters d-none">
                    <label class="form-label small text-muted mb-1" for="twgBusinessSelect">Business</label>
                    <select class="form-select" id="twgBusinessSelect">
                      <option value="" selected disabled>Choose a business</option>
                    </select>
                  </div>
                </div>
              </div>
            </div>

            <div id="twgSummary" class="row g-3 mb-3 d-none">
              <div class="col-sm-6 col-xl-4">
                <div class="card border-0 shadow-sm h-100">
                  <div class="card-body py-3">
                    <div class="small text-muted text-uppercase">Award titles</div>
                    <div class="fs-4 fw-semibold" id="twgStatNominees">0</div>
                  </div>
                </div>
              </div>
              <div class="col-sm-6 col-xl-4">
                <div class="card border-0 shadow-sm h-100">
                  <div class="card-body py-3">
                    <div class="small text-muted text-uppercase" id="twgStatCompleteLabel">Fully scored</div>
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
                <div class="min-w-0">
                  <span class="fw-semibold mb-0 d-block"><i class="bi bi-clipboard-check me-1"></i> Score sheet</span>
                  <span id="twgSheetBusinessName" class="d-block text-truncate mt-1 text-muted">Choose an award title to load its score sheet.</span>
                  <ul class="nav nav-pills flex-wrap gap-1 mt-2 d-none" id="twgJudgeTabs" role="tablist"></ul>
                </div>
                <div class="d-flex flex-wrap gap-2">
                  <div class="btn-group">
                    <button class="btn btn-outline-success btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" id="twgExportMenu">
                      <i class="bi bi-download me-1"></i> Download scoresheet
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
                <p class="small text-muted mb-3 d-none" id="twgSaveHint">Each score starts at <strong>0</strong>. Change only the scores that are not zero, then click <strong>Save scores</strong>. You can edit again until the business is confirmed for public voting.</p>
                <div class="table-responsive">
                  <table id="twgSheetTable" class="table table-striped table-bordered admin-data-table w-100 mb-0">
                    <thead class="table-light" id="twgSheetHead">
                      <tr>
                        <th>Entry</th>
                        <th>Business</th>
                        <th>Taste (50%)</th>
                        <th>Innovation (20%)</th>
                        <th>Value (30%)</th>
                        <th>Average</th>
                        <th>Ranking</th>
                      </tr>
                    </thead>
                    <tbody id="twgSheetBody">
                      <tr>
                        <td colspan="7" class="text-center text-muted">Select a category and award title to load its score sheet.</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
                <div class="d-flex justify-content-end mt-3 d-none" id="twgSaveBar">
                  <button class="btn btn-primary" type="button" id="twgSaveBtnBottom">
                    <i class="bi bi-save me-1"></i> Save scores
                  </button>
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
    <?php include __DIR__ . '/partials/admin_confirm_modal.php'; ?>
    <?php include __DIR__ . '/partials/admin_legacy_footer.php'; ?>
    <script>
      const currentEventId = <?php echo json_encode($twgEventId); ?>;
      const twgFilterSeed = <?php echo json_encode([
        'categories' => $twgCategories,
        'questions_by_category' => $twgQuestionsByCategory,
      ], JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <script src="js/admin_confirm.js"></script>
    <script src="twg_evaluation.js?v=<?php echo (int) (@filemtime(__DIR__ . '/twg_evaluation.js') ?: time()); ?>"></script>
  </body>
</html>
