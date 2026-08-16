<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/admin_init.php';
admin_apply_nav_from_script(basename(__FILE__));

$eventId = ($conn instanceof mysqli) ? admin_active_event_id($conn) : null;
$prefill = [
    'category_id' => (int) ($_GET['category_id'] ?? 0),
    'question_id' => (int) ($_GET['question_id'] ?? 0),
    'choice_id' => (int) ($_GET['choice_id'] ?? 0),
    'voters_id' => (int) ($_GET['voters_id'] ?? 0),
    'mobile' => trim((string) ($_GET['mobile'] ?? '')),
    'proof' => trim((string) ($_GET['proof'] ?? 'all')),
];
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <script src="js/instant_theme_init.js"></script>
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <title>Proof of Purchase | Tatak Ormoc</title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($faviconPath ?? '', ENT_QUOTES); ?>">
    <link href="css/styles.css" rel="stylesheet" />
    <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous" defer></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <?php include 'inline_style.php'; ?>
    <style>
      .proof-thumb { width: 56px; height: 56px; object-fit: cover; border-radius: 6px; background: #f1f3f5; }
      .proof-thumb--empty { display: inline-flex; align-items: center; justify-content: center; color: #868e96; font-size: 1.1rem; }
      #proofsTable td { vertical-align: middle; }
      .proof-lightbox-img { max-width: 100%; max-height: 70vh; object-fit: contain; }
      .proof-lightbox-caption { font-variant-numeric: tabular-nums; }
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
                <h1 class="admin-page-title mb-2">Proof of Purchase</h1>
                <?php echo render_reports_breadcrumb([['label' => 'Proof of Purchase']]); ?>
              </div>
            </div>
            <?php echo render_admin_event_context(); ?>

            <div class="card border-0 shadow-sm mb-3">
              <div class="card-body py-3">
                <p class="mb-0 small text-muted">
                  Proof of purchase is optional on the ballot. Use this page to review photos voters did upload,
                  grouped by mobile number, award title, and business. Staff caption uses the verified mobile number — voters do not type a name.
                </p>
              </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
              <div class="card-body">
                <form id="proofFilterForm" class="row g-3 align-items-end">
                  <div class="col-md-3">
                    <label class="form-label small text-muted mb-1" for="filterCategory">Category</label>
                    <select class="form-select" id="filterCategory">
                      <option value="">All categories</option>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label small text-muted mb-1" for="filterAward">Award</label>
                    <select class="form-select" id="filterAward">
                      <option value="">All awards</option>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label small text-muted mb-1" for="filterBusiness">Business</label>
                    <select class="form-select" id="filterBusiness">
                      <option value="">All businesses</option>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label small text-muted mb-1" for="filterProof">Proof</label>
                    <select class="form-select" id="filterProof">
                      <option value="all">All votes</option>
                      <option value="with">With proof</option>
                      <option value="without">Without proof</option>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label small text-muted mb-1" for="filterMobile">Mobile</label>
                    <input type="text" class="form-control" id="filterMobile" placeholder="09…" inputmode="numeric">
                  </div>
                  <div class="col-md-3">
                    <label class="form-label small text-muted mb-1" for="filterDateFrom">From</label>
                    <input type="date" class="form-control" id="filterDateFrom">
                  </div>
                  <div class="col-md-3">
                    <label class="form-label small text-muted mb-1" for="filterDateTo">To</label>
                    <input type="date" class="form-control" id="filterDateTo">
                  </div>
                  <div class="col-md-3">
                    <button type="submit" class="btn btn-primary" id="filterApplyBtn">Apply filters</button>
                    <button type="button" class="btn btn-outline-secondary" id="filterResetBtn">Reset</button>
                  </div>
                </form>
              </div>
            </div>

            <div id="proofStats" class="row g-3 mb-3">
              <div class="col-sm-4">
                <div class="card border-0 shadow-sm h-100">
                  <div class="card-body py-3">
                    <div class="small text-muted text-uppercase">Shown</div>
                    <div class="fs-4 fw-semibold" id="statShown">0</div>
                  </div>
                </div>
              </div>
              <div class="col-sm-4">
                <div class="card border-0 shadow-sm h-100">
                  <div class="card-body py-3">
                    <div class="small text-muted text-uppercase">With proof</div>
                    <div class="fs-4 fw-semibold" id="statWith">0</div>
                  </div>
                </div>
              </div>
              <div class="col-sm-4">
                <div class="card border-0 shadow-sm h-100">
                  <div class="card-body py-3">
                    <div class="small text-muted text-uppercase">Without proof</div>
                    <div class="fs-4 fw-semibold" id="statWithout">0</div>
                  </div>
                </div>
              </div>
            </div>

            <div class="card shadow-sm border-0 admin-table-card mb-4">
              <div class="card-header bg-transparent py-3">
                <span class="fw-semibold mb-0"><i class="bi bi-images me-1"></i> Votes</span>
              </div>
              <div class="card-body">
                <div class="table-responsive">
                  <table class="table table-striped table-hover align-middle mb-0" id="proofsTable">
                    <thead>
                      <tr>
                        <th>Photo</th>
                        <th>Mobile</th>
                        <th>Category</th>
                        <th>Award</th>
                        <th>Business</th>
                        <th>Proofs</th>
                        <th>Voted</th>
                        <th></th>
                      </tr>
                    </thead>
                    <tbody id="proofsTableBody">
                      <tr><td colspan="8" class="text-center text-muted">Loading…</td></tr>
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

    <div class="modal fade" id="proofLightbox" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Proof of purchase</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <p class="proof-lightbox-caption small text-muted mb-3" id="proofLightboxCaption"></p>
            <div id="proofLightboxFiles"></div>
          </div>
        </div>
      </div>
    </div>

    <script>
      window.TOCCA_PROOF_PREFILL = <?php echo json_encode($prefill, JSON_UNESCAPED_SLASHES); ?>;
      window.TOCCA_PROOF_HAS_EVENT = <?php echo ($eventId && (int) $eventId > 0) ? 'true' : 'false'; ?>;
    </script>
    <?php include __DIR__ . '/partials/admin_legacy_footer.php'; ?>
    <script src="vote_proofs.js"></script>
  </body>
</html>
