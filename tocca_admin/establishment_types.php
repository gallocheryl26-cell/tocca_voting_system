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
  <title>Establishment Types | Tatak Ormoc</title>
  <link rel="icon" type="image/png" href="<?php echo $faviconPath; ?>">
  <link href="css/styles.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous" defer></script>
  <?php include 'inline_style.php'; ?>
  <style>
#typeModal #awardsList { overflow-x: hidden; }
#typeModal .category-heading {
  font-weight: 700; font-size: .8rem; color: #6c757d;
  text-transform: uppercase; letter-spacing: .03em; margin-top: .75rem;
}
#typeModal .award-row { position: relative; border-bottom: 1px solid var(--bs-border-color-translucent); }
#typeModal .award-row:last-child { border-bottom: 0; }
#typeModal .stretched-label { position: absolute; inset: 0; cursor: pointer; }
#typeModal .form-check-input { width: 1.1em; height: 1.1em; }
#typeModal .award-name { line-height: 1.2; }
#typeModal .is-invalid-awards { border: 1px solid var(--bs-danger); border-radius: .5rem; }
#typeModal #awardsFeedback { display: none; color: var(--bs-danger); margin-top: .5rem; }

#typesTable tbody tr td { vertical-align: middle; }
#typesTable .awards-list {
  width: 100%;
  max-width: none;
  white-space: normal;
}
#typesTable .et-awards-tags {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.4rem 0.5rem;
}
#typesTable .et-award-tag {
  font-size: 0.8125rem;
  font-weight: 500;
  line-height: 1.25;
  padding: 0.35rem 0.65rem;
  color: var(--bs-body-color);
  background-color: var(--bs-tertiary-bg, #f8f9fa);
  border-color: var(--bs-border-color) !important;
  white-space: nowrap;
}
html.dark-mode #typesTable .et-award-tag {
  color: #e2e8f0;
  background-color: rgba(148, 163, 184, 0.12);
  border-color: rgba(148, 163, 184, 0.25) !important;
}

#awardsList { max-height: 340px; overflow-y: auto; background: rgba(15, 23, 42, 0.02); }
#awardsList .category-heading { font-size: 0.85rem; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; margin-top: 1.25rem; margin-bottom: 0.5rem; color: #6b7280; }
#awardsList .category-heading:first-child { margin-top: 0; }
#awardsList .form-check { padding: 0.25rem 0; }
#awardsList .award-name { font-weight: 500; }
#awardsList .empty-state { color: #6b7280; }

#featureDisabledAlert { display: none; }
#featureDisabledAlert.show { display: block; }

#typesTable th.actions-col,
#typesTable td.actions {
  width: 1%;
  white-space: nowrap;
  vertical-align: middle;
  text-align: left;
}
#typesTable td.actions .admin-table-actions {
  display: inline-flex;
  flex-flow: row nowrap;
  align-items: center;
  justify-content: flex-start;
  gap: 0.5rem;
}
#typesTable td.actions .btn {
  flex: 0 0 auto;
  font-weight: 500;
  min-width: 4.25rem;
}
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
              <h1 class="admin-page-title mb-2">Establishment Types</h1>
              <?php echo render_file_maintenance_breadcrumb([['label' => 'Establishment Types']]); ?>
            </div>
          </div>
          <?php echo render_admin_event_context(); ?>

          <!-- Moved here from the table header -->
          <div class="mb-3">
            <button class="btn btn-primary" id="addTypeBtn">
              <i class="bi bi-plus-lg"></i> Add Establishment Type
            </button>
          </div>

          <div id="featureDisabledAlert" class="alert alert-warning" role="alert"></div>

          <div class="card shadow-sm border-0 admin-table-card mb-4" id="typesCard">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center flex-wrap gap-2 py-3">
              <span class="fw-semibold mb-0"><i class="fas fa-diagram-project me-1"></i>Establishment Types</span>
            </div>
            <div class="card-body">
              <div id="typesTableWrapper" class="table-responsive">
                <table class="table table-striped table-bordered admin-data-table align-middle mb-0" id="typesTable">
                  <thead class="table-light">
                    <tr>
                      <th scope="col" style="width: 220px;">Establishment Types</th>
                      <th scope="col">Awards</th>
                      <th scope="col" class="actions-col">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr class="table-light" id="typesLoadingRow">
                      <td colspan="3" class="text-center py-5">
                        <div class="d-flex align-items-center justify-content-center gap-2 text-muted">
                          <div class="spinner-border spinner-border-sm" role="status"></div>
                          <span>Loading establishment types...</span>
                        </div>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div id="typesEmptyState" class="text-center text-muted py-5 d-none">
                <i class="bi bi-diagram-2 fs-1 mb-3"></i>
                <p class="mb-0">No establishment types have been configured yet.</p>
                <p class="small mb-0">Click the &ldquo;Add Establishment Type&rdquo; button to create one.</p>
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

  <div class="modal fade" id="typeModal" tabindex="-1" aria-labelledby="typeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg"><div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="typeModalLabel">Add Establishment Type</h5>
      </div>
      <form id="typeForm">
        <div class="modal-body">
          <div class="mb-3">
            <label for="typeName" class="form-label">Establishment Type</label>
            <input type="text" class="form-control" id="typeName" name="typeName" required placeholder="e.g. Restaurant" />
            <div class="invalid-feedback" id="typeNameFeedback"></div>
          </div>
          <div class="mb-3">
            <div class="d-flex justify-content-between align-items-center">
              <label class="form-label mb-0">Awards</label>
              <div class="btn-group btn-group-sm" role="group" aria-label="Awards selection helpers">
                <button type="button" class="btn btn-outline-secondary" id="selectAllAwardsBtn">Select All</button>
                <button type="button" class="btn btn-outline-secondary" id="clearAllAwardsBtn">Clear</button>
              </div>
            </div>
            <p class="text-muted small mb-2">Select all awards that should be available to establishments of this type.</p>
            <div id="awardsList" class="border rounded p-3">
              <div class="text-center text-muted py-4" id="awardsLoadingState">
                <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                Loading awards...
              </div>
            </div>
            <div class="invalid-feedback d-block" id="awardsFeedback" style="display:none;"></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" id="saveTypeBtn">Save Establishment Type</button>
        </div>
      </form>
    </div></div>
  </div>

  <div class="position-fixed bottom-0 start-50 translate-middle-x p-3" style="z-index: 1080;">
    <div id="typesToast" class="toast align-items-center text-bg-success border-0" role="alert" data-bs-delay="3000" aria-live="assertive" aria-atomic="true">
      <div class="d-flex">
        <div class="toast-body" id="typesToastBody"></div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
      </div>
    </div>
  </div>

  <?php include __DIR__ . '/partials/admin_confirm_modal.php'; ?>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="js/admin_confirm.js"></script>
  <script src="establishment_type.js"></script>
  
  <?php include __DIR__ . '/partials/admin_legacy_footer.php'; ?>
</body>
</html>
