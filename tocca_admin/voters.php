<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/admin_init.php';
require_once __DIR__ . '/includes/admin_active_event.php';
admin_apply_nav_from_script(basename(__FILE__));

$event_id = ($conn instanceof mysqli) ? admin_active_event_id($conn) : null;

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
        <title>Voters | Tatak Ormoc</title>
        <link rel="icon" type="image/png" href="<?php echo $faviconPath; ?>">
        <link href="css/styles.css" rel="stylesheet" />
        <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous" defer></script>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
        <?php include 'inline_style.php'; ?>
        <script>
      const currentEventId = <?= isset($event_id) ? $event_id : 'null' ?>;
      console.log("✅ currentEventId =", currentEventId);
    </script>
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
              <h1 class="admin-page-title mb-2">Voters</h1>
              <?php echo render_reports_breadcrumb([['label' => 'Voters']]); ?>
            </div>
          </div>
          <?php echo render_admin_event_context(); ?>

                        <div class="modal fade" id="downloadVotersModal" tabindex="-1" aria-labelledby="downloadVotersModalLabel" aria-hidden="true">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="downloadVotersModalLabel">
                                            <i class="fas fa-file-download me-2"></i>Download Voters List
                                        </h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
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
                                        <button type="button" class="btn btn-primary" id="confirmDownloadVoters">
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

                        <div class="card shadow-sm border-0 mb-4">
                            <div class="card-header bg-transparent d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
                                <span class="fw-semibold mb-0"><i class="fas fa-table me-1"></i> List of Voters Table</span>
                                <button type="button" class="btn btn-success btn-sm shrink-0" data-bs-toggle="modal" data-bs-target="#downloadVotersModal"<?php echo ($event_id && (int) $event_id > 0) ? '' : ' disabled'; ?>>
                                    <i class="fas fa-download me-1"></i> Download
                                </button>
                            </div>


                            <div class="card-body">
                                <table id="voterListTable" class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>No.</th>
                                            <th>Voter Identity</th>
                                            <th>Date Added</th>
                                            <th>Vote Status</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tableBody">
                                        <!-- Dynamic data will load here -->
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="modal fade" id="voterActivityModal" tabindex="-1" aria-labelledby="voterActivityModalLabel" aria-hidden="true">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="voterActivityModalLabel">Voter Activity</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <table class="table table-bordered">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Questions</th>
                                                    <th>Choice Selected</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <!-- Activity data will load here -->
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="modal-footer">
                                        <a id="downloadActivityBtn" href="#" class="btn btn-sm btn-primary">
                                            <i class="fas fa-download me-1"></i>Download Activity Log
                                        </a>
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                    </div>
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
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
        <?php include __DIR__ . '/partials/admin_datatables_scripts.php'; ?>
        <script src="voters.js"></script>

  <?php include __DIR__ . '/partials/admin_legacy_footer.php'; ?>
</body>
</html>
