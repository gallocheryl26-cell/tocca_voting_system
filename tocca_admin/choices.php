<?php
require_once __DIR__ . '/includes/admin_init.php';
admin_apply_nav_from_script(basename(__FILE__));

if (!function_exists('render_data_attributes')) {
  function render_data_attributes(array $dataset): string {
    $out = '';
    foreach ($dataset as $key => $value) {
      if ($value === null || $value === '') continue;
      $attr = 'data-' . strtolower(preg_replace('/([A-Z])/', '-$1', $key));
      $out .= ' ' . $attr . '="' . htmlspecialchars((string)$value, ENT_QUOTES) . '"';
    }
    return $out;
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
  <meta name="description" content="" />
  <meta name="author" content="" />
  <title>Businesses | Tatak Ormoc</title>
  <link rel="icon" type="image/png" href="<?php echo $faviconPath; ?>">
  <link href="css/styles.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
  <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous" defer></script>
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <?php include 'inline_style.php'; ?>
    <style>
      .table-sm td, .table-sm th { padding: .5rem .75rem; }
      #datatablesSimple .vote-cell { min-width: 5.5rem; }
      #datatablesSimple th.actions-col,
      #datatablesSimple td.actions {
        width: 24rem;
        min-width: 24rem;
        vertical-align: middle;
      }
      #datatablesSimple td.actions .admin-table-actions {
        flex-wrap: nowrap;
      }
      .dropdown-item .form-check-label:hover {
        color: #fff;          /* or inherit a light color variable */
      }
      /* Ensure muted text remains readable on row hover */
      .table-hover tbody tr:hover .text-muted {
        color: inherit !important;
      }
    </style>
  <style>
    /* Make the Message textarea auto-expand and hide its own scrollbar */
    #emailMessage {
      overflow-y: hidden;
      resize: none;
      min-height: 120px;
      max-height: 60vh;
      line-height: 1.4;
    }

    #allEmailMessage {
  overflow: hidden;       /* Hide scrollbars */
  resize: none;           /* Prevent manual resizing */
  box-sizing: border-box; /* Ensure padding is included in height */
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
              <h1 class="admin-page-title mb-2">Businesses</h1>
              <?php echo render_file_maintenance_breadcrumb([['label' => 'Businesses']]); ?>
            </div>
          </div>
          <?php echo render_admin_event_context(); ?>

        <p class="text-muted small mb-2">
          This list shows businesses confirmed for public voting. Businesses still under evaluation stay in Registration and TWG Evaluation until you confirm them.
          Customize QR poster frames and colors in
          <a href="admin_settings.php#qr-frame-settings">Admin Settings &rarr; QR Codes &rarr; QR poster design</a>.
          Use <strong>Generate QR</strong> and <strong>Download</strong> for walk-in handouts.
          Use <strong>Vote link &rarr; Copy</strong> for social media (share with voters, not the business portal).
        </p>

         <div class="d-flex flex-wrap gap-2 mb-3">
          <button class="btn btn-primary" id="addRowBtn">
            <i class="bi bi-plus-lg"></i> Add Business
          </button>
          <button class="btn btn-success" id="sendAllEmailsBtn">
            <i class="fas fa-paper-plane"></i> Send All QR Emails
          </button>
          <button class="btn btn-outline-success" id="generateAllQRBtn"<?php echo render_data_attributes($qrFrameDefaultsForJs ?? []); ?>>
            <i class="bi bi-qr-code"></i> Generate All QR Codes
          </button>
          <button class="btn btn-outline-danger" id="regenerateAllQRBtn"<?php echo render_data_attributes($qrFrameDefaultsForJs ?? []); ?>>
            <i class="bi bi-arrow-clockwise"></i> Regenerate All QR Codes
          </button>
          <button class="btn btn-outline-primary" id="copyAllVoteLinksBtn" type="button" title="Copy every establishment voting URL (for social media, SMS to voters)">
            <i class="bi bi-link-45deg"></i> Copy All Vote Links
          </button>
        </div>


          <div class="card shadow-sm border-0 admin-table-card mb-4">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center py-3">
              <span class="fw-semibold mb-0"><i class="fas fa-table me-1"></i>Businesses Table</span>
            </div>

            <div class="card-body">
              <div class="table-responsive w-100">
                  <table id="datatablesSimple" class="table table-striped table-bordered admin-data-table w-100" style="width: 100%;">
                    <thead class="table-light">
                      <tr>
                        <th>Businesses</th>
                        <th>Email</th>
                        <th>Vote link</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th class="actions-col">Actions</th>
                      </tr>
                    </thead>
                    <tbody id="tableBody">
                    </tbody>
                  </table>
                </div>
            
            
              <!-- Pagination Section -->
              <div class="row mt-3 g-2 align-items-center">
                <div class="col-12 col-md-6 text-center text-md-start">
                  <small id="paginationInfo" class="text-muted d-block"></small>
                </div>
                <div class="col-12 col-md-6">
                  <nav aria-label="Table Pagination">
                    <ul class="pagination justify-content-center justify-content-md-end flex-wrap mb-0" id="paginationContainer">
                      <!-- Pagination items -->
                    </ul>
                  </nav>
                </div>
              </div>              
            </div>            
          </div>
          

          <!-- Modal -->
        <div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
          <div class="modal-dialog modal-lg">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title" id="editModalLabel">Add Business</h5>
              </div>
              <div class="modal-body">
                <form id="editForm">
                  <!-- Choice Name -->
                  <div class="mb-3">
                    <label for="editName" class="form-label">Business Name</label>
                    <input type="text" class="form-control" id="editName" required />
                    <div class="invalid-feedback" id="editNameFeedback"></div>
                  </div>

                  <!-- Business Email -->
                  <div class="mb-3">
                    <label for="editEmail" class="form-label">Business Email</label>
                    <div class="input-group">
                      <span class="input-group-text"><i class="bi bi-envelope-fill"></i></span>
                      <input type="email" class="form-control" id="editEmail" placeholder="example@domain.com" required />
                    </div>
                    <div class="invalid-feedback" id="editEmailFeedback"></div>
                  </div>


                  <!-- Establishment Type (many-to-many) -->
                  <div class="mb-3" id="establishmentTypeGroup">
                    <span class="form-label d-block">Nature of Business <span class="text-danger">*</span></span>
                    <div id="establishmentTypeCheckboxes" class="border rounded p-2" style="max-height: 180px; overflow:auto;">
                      <div class="text-muted small">Loading types…</div>
                    </div>
                    <div class="form-text" id="establishmentTypeNotice">
                      Select all that apply. Awards shown below are the combined list for the selected types.
                    </div>
                    <div class="invalid-feedback d-block d-none" id="establishmentTypeFeedback">Please select at least one nature of business.</div>
                  </div>

                    <!-- Awards checklist (no category filter dropdown) -->
                    <div class="mb-3">
                      <label class="form-label">Link to Awards:</label>
                      <div class="table-responsive">
                        <table class="table table-bordered table-striped w-100 mb-0">
                          <thead>
                            <tr>
                              <th style="width: 50px;">Select</th>
                              <th>Award Name</th>
                            </tr>
                          </thead>
                          <tbody id="questionCheckboxContainer">
                            <!-- rows rendered by JS; make sure each award row has data-question-id -->
                          </tbody>
                        </table>
                      </div>
                    </div>
                </form>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveChangesBtn">Save Business</button>
              </div>
            </div>
          </div>
        </div>

                <!-- QR Code Modal -->
        <div class="modal fade" id="qrModal" tabindex="-1" aria-labelledby="qrModalLabel" aria-hidden="true">
          <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title" id="qrModalLabel">Business QR poster</h5>
              </div>
              <div class="modal-body">
                <div class="alert alert-info py-2 small mb-3" role="status">
                  <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                  Poster layout (frame, colors, positioning) is set in
                  <a href="admin_settings.php#qr-frame-settings" class="alert-link fw-semibold">Admin Settings &rarr; QR Codes &rarr; QR poster design</a>.
                  Use this screen to preview, regenerate, download, or email this establishment&rsquo;s QR.
                </div>
                <div class="row g-3 align-items-stretch">
                  <div class="col-12 col-lg-7">
                    <div id="qrPreviewWrap" class="border rounded p-2 bg-light-subtle text-center h-100 d-flex align-items-center justify-content-center position-relative">
                      <div id="qrPreviewBusyOverlay" class="position-absolute top-0 start-0 w-100 h-100 d-none align-items-center justify-content-center rounded bg-dark bg-opacity-50" style="z-index: 2;" aria-hidden="true">
                        <div class="spinner-border text-light" role="status" aria-label="Loading preview"></div>
                      </div>
                      <img id="qrImg" src="" alt="QR poster preview" class="img-fluid" style="max-height: 420px;" />
                    </div>
                  </div>
                  <div class="col-12 col-lg-5 d-flex flex-column">
                    <h6 class="text-muted text-uppercase small mb-2">Actions</h6>
                    <div class="d-grid gap-2">
                      <button type="button" class="btn btn-edit" id="regenerateQRBtn">
                        <i class="bi bi-arrow-clockwise me-1"></i> Regenerate QR
                      </button>
                      <button class="btn btn-success saveQRBtn" id="saveQRBtn" data-id="" data-name="">
                        <i class="bi bi-download me-1"></i> Download QR
                      </button>
                    </div>
                    <p id="qrActionHint" class="form-text small mb-0 mt-2">
                      <strong>Regenerate</strong> overwrites the saved poster file for this establishment.
                    </p>
                  </div>
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
              </div>
            </div>
          </div>
        </div>

<!-- Manage Business Media Modal -->
<div class="modal fade" id="mediaManagerModal" tabindex="-1" aria-labelledby="mediaManagerLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="mediaManagerLabel">Business Media</h5>
      </div>
      <div class="modal-body">
        <div class="alert alert-info py-2 small mb-3">
          Upload photos or short videos so voters can preview this establishment before selecting it.
          <br>
          <strong>Images</strong>: PNG, JPG, WEBP, GIF (up to 10&nbsp;MB).
          <strong>Videos</strong>: MP4, WebM, OGG, MOV (up to 100&nbsp;MB).
        </div>

        <form id="mediaUploadForm" class="border rounded p-3 mb-3 bg-light-subtle" enctype="multipart/form-data">
          <input type="hidden" id="mediaChoiceId" value="">
          <div class="row g-2 align-items-end">
            <div class="col-12 col-md-6">
              <label for="mediaFileInput" class="form-label mb-1">Choose image or video</label>
              <input type="file" id="mediaFileInput" class="form-control" accept="image/*,video/*" required>
            </div>
            <div class="col-12 col-md-4">
              <label for="mediaCaptionInput" class="form-label mb-1">Caption (optional)</label>
              <input type="text" id="mediaCaptionInput" class="form-control" maxlength="255" placeholder="e.g. Storefront photo">
            </div>
            <div class="col-12 col-md-2 d-grid">
              <button type="submit" class="btn btn-primary" id="mediaUploadBtn">
                <i class="bi bi-cloud-upload"></i> Upload
              </button>
            </div>
          </div>
          <div class="progress mt-2 d-none" id="mediaUploadProgress" role="progressbar" aria-valuemin="0" aria-valuemax="100" style="height: 6px;">
            <div class="progress-bar" id="mediaUploadProgressBar" style="width: 0%;"></div>
          </div>
        </form>

        <h6 class="text-muted text-uppercase small mb-2">Existing media</h6>
        <div id="mediaGalleryList" class="row g-3">
          <div class="col-12 text-center text-muted small py-3">Loading...</div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </div>
  </div>
</div>

<!-- Send QR Email Modal -->
<div class="modal fade" id="sendEmailModal" tabindex="-1" aria-labelledby="sendEmailModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title" id="sendEmailModalLabel">Send QR Code via Email</h5>
      </div>
      <div class="modal-body">
        <form id="customEmailForm">
          <input type="hidden" id="emailChoiceId">
          <div class="mb-3">
            <label for="emailTo" class="form-label">Recipient Email</label>
            <input type="email" class="form-control" id="emailTo" readonly>
          </div>
          <div class="mb-3">
            <label for="emailSubject" class="form-label">Subject</label>
            <input type="text" class="form-control" id="emailSubject" value="Your QR Code for Tatak Ormoc Voting">
          </div>
          <div class="mb-3">
            <label for="emailMessage" class="form-label">Message</label>
            <textarea class="form-control" id="emailMessage" rows="6">Thank you for participating in the Tatak Ormoc Consumers' Choice Awards.

Your QR poster is attached. Use the links below when you promote voting.

Best regards,
TOCCA Team</textarea>
            <details class="mt-3" open>
              <summary class="mb-2">Preview</summary>
              <div id="emailPreview" class="border rounded overflow-auto bg-light small" style="max-height:420px;"></div>
            </details>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary" id="sendEmailSubmitBtn" form="customEmailForm">Send Email</button>
      </div>
    </div>
  </div>
</div>

<!-- Send All QR Emails Modal -->
<div class="modal fade" id="sendAllEmailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content shadow">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title">Send QR Code Emails to All Participants</h5>
      </div>
      <div class="modal-body">
        <form id="sendAllEmailForm">
          <div class="mb-3">
            <label for="allEmailSubject" class="form-label">Email Subject</label>
            <input type="text" id="allEmailSubject" class="form-control" value="Your TOCCA 2024 QR Code is Here!" />
          </div>
          <div class="mb-3">
            <label for="allEmailMessage" class="form-label">Message</label>
            <textarea id="allEmailMessage" class="form-control" rows="6">Thank you for participating in the Tatak Ormoc Consumers' Choice Awards.

Your QR poster is attached. Use the links below when you promote voting.

Best regards,
TOCCA Team</textarea>
            <details class="mt-3">
              <summary class="mb-2">Preview</summary>
              <div id="allEmailPreview" class="border rounded overflow-auto bg-light small" style="max-height:360px;"></div>
            </details>
          </div>
          <div id="sendAllProgressWrap" class="d-none mt-3" aria-live="polite">
            <div class="d-flex justify-content-between small text-muted mb-1">
              <span id="sendAllProgressLabel">Preparing…</span>
              <span id="sendAllProgressCount">0 / 0</span>
            </div>
            <div class="progress" style="height: 1.25rem;">
              <div id="sendAllProgressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-success" role="progressbar" style="width: 0%;" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">0%</div>
            </div>
            <p class="small text-muted mb-0 mt-2">Emails are sent in small batches to stay fast and avoid timeouts.</p>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button id="confirmSendAllEmailsBtn" class="btn btn-success">Send Emails</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </div>
  </div>
</div>


<!-- Bulk QR generate / regenerate progress -->
<div class="modal fade" id="bulkQrProgressModal" tabindex="-1" aria-labelledby="bulkQrProgressTitle" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content shadow">
      <div class="modal-header">
        <h5 class="modal-title" id="bulkQrProgressTitle">QR codes</h5>
      </div>
      <div class="modal-body">
        <p id="bulkQrProgressHint" class="small text-muted mb-3">Keep this window open until the job finishes. You can stop after the current establishment completes.</p>
        <div class="d-flex justify-content-between small mb-1">
          <span id="bulkQrProgressLabel" class="fw-medium">Starting…</span>
          <span id="bulkQrProgressCount" class="text-muted">0 / 0</span>
        </div>
        <div class="progress mb-2" style="height: 1.35rem;">
          <div id="bulkQrProgressBar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%;" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">0%</div>
        </div>
        <p id="bulkQrProgressStats" class="small text-muted mb-0" aria-live="polite">—</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-danger" id="bulkQrCancelBtn">Stop</button>
        <button type="button" class="btn btn-secondary d-none" id="bulkQrCloseBtn" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

        <?php include __DIR__ . '/partials/admin_confirm_modal.php'; ?>

        <div class="position-fixed bottom-0 start-50 translate-middle-x p-3" style="z-index: 9999">
          <div id="toastSuccess" class="toast align-items-center text-bg-success border-0" role="alert" data-bs-autohide="true" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
              <div id="toastSuccessBody" class="toast-body"></div>
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

    <script>
    window.qrFrameDefaults = <?php echo json_encode($qrFrameDefaultsForJs ?? [], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.qrFrameConfig = <?php echo $qrFrameConfigJson ? $qrFrameConfigJson : 'null'; ?>;
    <?php
      if (!function_exists('qr_vote_portal_url')) {
          require_once __DIR__ . '/qr_url.php';
      }
      $votePortalUrl = function_exists('qr_vote_portal_url') ? qr_vote_portal_url($conn) : '';
      $trackUrl = function_exists('qr_tracking_url') ? qr_tracking_url($conn) : '';
    ?>
    window.toccaVotePortalUrl = <?php echo json_encode($votePortalUrl, JSON_UNESCAPED_SLASHES); ?>;
    window.toccaTrackUrl = <?php echo json_encode($trackUrl, JSON_UNESCAPED_SLASHES); ?>;
  </script>
  <?php include __DIR__ . '/partials/admin_datatables_scripts.php'; ?>
  <?php include __DIR__ . '/partials/admin_legacy_footer.php'; ?>
  <script src="js/admin_confirm.js"></script>
  <script src="js/branded_email_preview.js"></script>
  <script src="choice.js?v=<?php echo (int) (@filemtime(__DIR__ . '/choice.js') ?: time()); ?>"></script>
</body>

</html>
