<?php
require_once __DIR__ . '/includes/admin_init.php';
admin_apply_nav_from_script(basename(__FILE__));
require_once 'db_connection.php';

$importFlash = $_SESSION['import_flash'] ?? null;
if (is_array($importFlash)) {
    unset($_SESSION['import_flash']);
}
$pageInlineScripts = '';
if (is_array($importFlash)) {
    ob_start();
    include __DIR__ . '/partials/admin_import_result_modal.php';
    $importModalHtml = ob_get_clean();
    $pageInlineScripts = '<script>document.addEventListener("DOMContentLoaded",function(){var el=document.getElementById("importResultModal");if(el){bootstrap.Modal.getOrCreateInstance(el).show();}});</script>';
} else {
    $importModalHtml = '';
}
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
    <title>System Utilities | Tatak Ormoc</title>
    <link rel="icon" type="image/png" href="<?php echo $faviconPath; ?>">
    <link href="css/styles.css" rel="stylesheet" />
    <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous" defer></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <?php include 'inline_style.php'; ?>
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
              <h1 class="admin-page-title mb-2">System Utilities</h1>
              <?php echo render_utilities_breadcrumb([['label' => 'System Utilities']]); ?>
            </div>
          </div>

            <div class="row">
              <div class="col-lg-6">
                <div class="card h-100 mb-4">
                  <div class="card-header fw-bold">
                    <i class="fas fa-file-export me-1"></i> Import & Export Tools
                  </div>
                  <div class="card-body">
                    <h6 class="fw-bold mb-3">Export Data</h6>

                    <div class="d-flex justify-content-between align-items-center mb-2">
                      <span>Categories</span>
                      <div>
                        <a href="exports.php?type=categories&format=excel" class="btn btn-outline-info btn-sm me-1">Excel</a>
                        <a href="exports.php?type=categories&format=pdf" class="btn btn-outline-danger btn-sm">PDF</a>
                      </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-2">
                      <span>Name of Awards</span>
                      <div>
                        <a href="exports.php?type=questions&format=excel" class="btn btn-outline-info btn-sm me-1">Excel</a>
                        <a href="exports.php?type=questions&format=pdf" class="btn btn-outline-danger btn-sm">PDF</a>
                      </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-2">
                      <span>Business Categories</span>
                      <div>
                        <a href="exports.php?type=establishment_types&format=excel" class="btn btn-outline-info btn-sm me-1">Excel</a>
                        <a href="exports.php?type=establishment_types&format=pdf" class="btn btn-outline-danger btn-sm">PDF</a>
                      </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-3">
                      <span>Businesses</span>
                      <div>
                        <a href="exports.php?type=choices&format=excel" class="btn btn-outline-info btn-sm me-1">Excel</a>
                        <a href="exports.php?type=choices&format=pdf" class="btn btn-outline-danger btn-sm">PDF</a>
                      </div>
                    </div>

                    <hr>

                    <h6 class="fw-bold mt-3 mb-2">Import Data</h6>
                    <p class="small text-muted mb-2">Imports into the <strong>currently active event</strong>. Open the <strong>Instructions</strong> sheet in the template first — it explains every column (<code>status</code>, <code>award_key</code>, etc.). Awards always use Options (businesses). Data sheets: <strong>Categories</strong>, <strong>Awards</strong>, <strong>Business Categories</strong> (Excel tab: Establishment Types), and <strong>Businesses</strong> (Excel tab: Establishments).</p>
                    <p class="small text-muted mb-2"><strong>Tip:</strong> Always fill the <strong>Business Categories</strong> sheet (<code>award_keys</code>) so imported awards are linked automatically. If you import awards only, link them later under File Maintenance → Business Categories.</p>
                    <p class="small mb-2">
                      <a href="uploads/tocca_import_template.xlsx" class="btn btn-outline-secondary btn-sm" download>
                        <i class="fas fa-download me-1"></i>Download import template
                      </a>
                    </p>
                    <form method="POST" action="import_excel.php" enctype="multipart/form-data">
                      <label for="excelFile" class="form-label small">Import categories, awards, business categories, and businesses (Excel)</label>
                      <input type="file" name="excelFile" id="excelFile" class="form-control mb-2" accept=".xlsx" required>
                      <button type="submit" class="btn btn-success w-100">
                        <i class="fas fa-upload me-1"></i>Upload & Import
                      </button>
                    </form>
                  </div>
                </div>
              </div>

              <div class="col-lg-6">
                <div class="card mb-4">
                  <div class="card-header"><i class="fas fa-database me-1"></i> Backup & Restore</div>
                  <div class="card-body">
                    <button class="btn btn-success mb-2">Download Backup</button>
                    <button class="btn btn-danger mb-2">Restore Backup</button>
                  </div>
                </div>
                <div class="card mb-4">
                  <div class="card-header fw-bold">
                    <i class="bi bi-link-45deg me-1"></i> Public URL / Hosting
                  </div>
                  <div class="card-body">
                    <p class="small text-muted mb-3">
                      Set the hosting site root and copy short public links (<code>/vote</code>, <code>/register</code>, <code>/track</code>, <code>/{business}</code>).
                    </p>
                    <a href="public_url_config.php" class="btn btn-outline-primary btn-sm">
                      Open Public Share Links
                    </a>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <?php echo $importModalHtml ?? ''; ?>
        </main>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/2.8.0/Chart.min.js" crossorigin="anonymous"></script>
    <script src="assets/demo/chart-area-demo.js"></script>
    <script src="assets/demo/chart-bar-demo.js"></script>

  <?php include __DIR__ . '/partials/admin_legacy_footer.php'; ?>
  <?php if (!empty($pageInlineScripts)) {
      echo $pageInlineScripts;
  } ?>
</body>
</html>
