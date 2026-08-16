<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/admin_init.php';
admin_apply_nav_from_script(basename(__FILE__));

// nomination_profile.php
include 'get_logo.php';
require_once 'breadcrumb.php';
require_once 'db_connection.php';
require_once 'session_bootstrap.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
  header('location: index.php');
  exit;
}

date_default_timezone_set('Asia/Manila');

$nomination_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$nomination_id) {
  header('location: nominations.php');
  exit;
}

if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf'];

$conn->set_charset('utf8mb4');

require_once __DIR__ . '/includes/nominations_list.php';
require_once __DIR__ . '/includes/nomination_profile_fields.php';
require_once __DIR__ . '/includes/award_removal_reasons.php';

$awardRemovalReasons = award_removal_reason_options();

$returnUrl = admin_nominations_list_url($_GET['return'] ?? null);

$tz  = new DateTimeZone('Asia/Manila');
$now = new DateTime('now', $tz);

$nomStatus = 'pending';
$nomRow = null;
$votingLocked = false;
$selectedVotingStartLabel = '';
$selectedVotingEndLabel = '';

$st = $conn->prepare(
  'SELECT n.*, e.voting_start, e.voting_end
   FROM tbl_nominations n
   LEFT JOIN tbl_events e ON e.event_id = n.event_id
   WHERE n.nomination_id = ?
   LIMIT 1'
);
$st->bind_param('i', $nomination_id);
$st->execute();
$row = $st->get_result()->fetch_assoc();
$st->close();

if ($row) {
  $nomRow = $row;
  $nomStatus = strtolower((string)($row['status'] ?? 'pending'));

  if (!empty($row['voting_start'])) {
    $startDt = new DateTime((string)$row['voting_start'], $tz);
    $selectedVotingStartLabel = $startDt->format('F j, Y g:i A');
  }
  if (!empty($row['voting_end'])) {
    $endDt = new DateTime((string)$row['voting_end'], $tz);
    $selectedVotingEndLabel = $endDt->format('F j, Y g:i A');
  }
  $votingLocked = nominations_is_voting_window_active(
    $row['voting_start'] ?? null,
    $row['voting_end'] ?? null,
    $now
  );
}

$votingLockMessage = 'Voting period has started for this event. Registration actions are disabled.';
if ($selectedVotingStartLabel !== '') {
  $votingLockMessage .= ' Voting began on ' . $selectedVotingStartLabel . '.';
}
if ($selectedVotingEndLabel !== '') {
  $votingLockMessage .= ' Voting ends on ' . $selectedVotingEndLabel . '.';
}

$profileAnswers = nomination_profile_load_answers($conn, $nomination_id);
$profileBusinessName = nomination_profile_business_name($nomRow, $profileAnswers);
$profileLogoPath = nomination_profile_logo_path($nomRow, $profileAnswers);
$profileDetailsHtml = nomination_profile_render_details($profileAnswers);
$profileStatusBadge = nomination_profile_status_badge($nomStatus);
$reviewLockedByStatus = in_array($nomStatus, ['approved', 'rejected', 'merged'], true);
$reviewActionsLocked = $votingLocked || $reviewLockedByStatus;
$reviewActionsHint = '';
if ($votingLocked) {
  $reviewActionsHint = 'Actions are disabled while the voting period is in progress.';
} elseif ($reviewLockedByStatus) {
  $statusLabels = [
    'approved' => 'approved',
    'rejected' => 'rejected',
    'merged'   => 'merged',
  ];
  $reviewActionsHint = 'Actions are disabled because this registration is already '
    . ($statusLabels[$nomStatus] ?? $nomStatus) . '.';
}
$reviewBtnDisabled = $reviewActionsLocked ? ' disabled aria-disabled="true" tabindex="-1"' : '';
$profileHasData = ($nomRow !== null);
$nomProfileJsVersion = @filemtime(__DIR__ . '/nomination_profile.js') ?: time();


// continue with your existing query below
$appliedSql = "
  SELECT nq.nomination_id,
         nq.question_id,
         q.question_name,
         c.category_name
  FROM tbl_nomination_questions nq
  JOIN tbl_questions q       ON q.question_id = nq.question_id
  LEFT JOIN tbl_categories c ON c.category_id = q.category_id
  WHERE nq.nomination_id = ?
  ORDER BY c.category_name, q.question_name
";

$appliedStmt = $conn->prepare($appliedSql);
$appliedStmt->bind_param('i', $nomination_id);
$appliedStmt->execute();
$appliedRows = $appliedStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ----------------------------------------------------------------------------
// Submitted photos & videos for this registration (preview before approve).
// Files live at nomination/uploads/nomination_media/<nomination_id>/<file>;
// from this page (under tocca_admin/) we reach them via ../nomination/<path>.
// ----------------------------------------------------------------------------
$nominationMedia = [];
$hasNominationMediaTable = false;
if ($r = $conn->query("SHOW TABLES LIKE 'tbl_nomination_media'")) {
  $hasNominationMediaTable = $r->num_rows > 0;
  $r->close();
}
if ($hasNominationMediaTable) {
  if ($stmt = $conn->prepare(
    'SELECT id, media_type, file_path, caption, sort_order, uploaded_at
     FROM tbl_nomination_media
     WHERE nomination_id = ?
     ORDER BY sort_order ASC, id ASC'
  )) {
    $stmt->bind_param('i', $nomination_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
      $rel = ltrim((string)$row['file_path'], '/');
      $nominationMedia[] = [
        'id'         => (int)$row['id'],
        'media_type' => (string)$row['media_type'],
        'url'        => '../nomination/' . $rel,
        'caption'    => $row['caption'] !== null ? (string)$row['caption'] : '',
        'sort_order' => (int)$row['sort_order'],
      ];
    }
    $stmt->close();
  }
}
$nominationMediaCounts = ['image' => 0, 'video' => 0];
foreach ($nominationMedia as $m) {
  if (isset($nominationMediaCounts[$m['media_type']])) {
    $nominationMediaCounts[$m['media_type']]++;
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
  <meta name="description" content="Registration Profile" />
  <meta name="csrf" content="<?php echo h($csrf); ?>">
  <title>Registration Profile | Tatak Ormoc</title>
  <script src="js/instant_theme_init.js"></script>
  <link rel="stylesheet" href="css/dark-mode.css">
  <link rel="icon" type="image/png" href="<?php echo $faviconPath; ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="css/styles.css" rel="stylesheet" />
  <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous" defer></script>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.snow.css" rel="stylesheet">

  <?php include 'inline_style.php'; ?>
  </head>
<body class="sb-nav-fixed">
    <?php include __DIR__ . '/partials/admin_topnav.php'; ?>

<div id="layoutSidenav">
  <?php include __DIR__ . '/partials/admin_sidebar.php'; ?>

<div id="layoutSidenav_content">
    <main>
      <div class="container-fluid px-4">
        <div class="admin-page-header d-flex flex-wrap align-items-end justify-content-between gap-3 mt-4 mb-4">
          <div class="min-w-0">
            <h1 class="admin-page-title mb-2">Registration Profile</h1>
            <?php echo render_nominations_breadcrumb([
              ['label' => 'Submissions', 'url' => $returnUrl],
              ['label' => $profileBusinessName !== '' ? $profileBusinessName : ('Registration #' . (int)$nomination_id)],
            ]); ?>
            <p class="text-muted small mb-0 mt-2">Review submission details, media, and awards before taking action.</p>
          </div>
        </div>
        <?php echo render_admin_event_context(); ?>

        <input type="hidden" id="nomination_id" value="<?php echo (int)$nomination_id; ?>">
        <input type="hidden" id="initial_status" value="<?php echo h($nomStatus); ?>">

        <div id="contentWrap" class="<?php echo $profileHasData ? '' : 'd-none'; ?> nom-profile-page"
             data-voting-lock="<?php echo $votingLocked ? '1' : '0'; ?>"
             data-vote-start-label="<?php echo h($selectedVotingStartLabel); ?>"
             data-vote-end-label="<?php echo h($selectedVotingEndLabel); ?>">
          <div class="nom-profile-toolbar d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <a href="<?php echo h($returnUrl); ?>" class="btn btn-outline-primary btn-sm">
              <i class="bi bi-arrow-left me-1"></i> Back to Registration
            </a>
            <span class="small text-muted">Registration #<?php echo (int)$nomination_id; ?></span>
          </div>
          <div class="row g-4">
            <div class="col-lg-8">

              <div class="card mb-4 nom-profile-card">
                <div class="card-body p-0">
                  <div class="nom-profile-hero border-bottom">
                    <div class="nom-profile-logo<?php echo $profileLogoPath === '' ? ' nom-profile-logo--empty' : ''; ?>" id="logoBox">
                      <?php if ($profileLogoPath !== ''): ?>
                        <img src="<?php echo h($profileLogoPath); ?>" alt="Business logo">
                      <?php else: ?>
                        <i class="bi bi-shop fs-2 text-muted" aria-hidden="true"></i>
                      <?php endif; ?>
                    </div>
                    <div class="nom-profile-hero-body">
                      <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
                        <div>
                          <div class="text-muted small text-uppercase fw-semibold mb-1">Business</div>
                          <h2 class="h4 mb-1" id="bizTitle"><?php echo h($profileBusinessName); ?></h2>
                        </div>
                        <div class="d-flex flex-wrap align-items-center gap-2">
                          <div id="statusBadge"><?php echo $profileStatusBadge; ?></div>
                          <div id="ballotBadge"></div>
                          <button type="button" class="btn btn-sm btn-outline-primary<?php echo in_array($nomStatus, ['pending', 'submitted', 'new', ''], true) ? '' : ' d-none'; ?>" id="btnStartReview">
                            <i class="bi bi-play-circle me-1"></i> Start review
                          </button>
                        </div>
                      </div>
                    </div>
                  </div>
                  <div class="p-4">
                    <div id="dynamicDetails"><?php echo $profileDetailsHtml; ?></div>
                  </div>
                  <div class="card-footer small text-muted d-flex flex-wrap gap-3">
                    <span><i class="bi bi-calendar-plus me-1"></i> Created: <span id="created_at"><?php echo nomination_profile_format_datetime($nomRow['created_at'] ?? null); ?></span></span>
                    <span><i class="bi bi-clock-history me-1"></i> Updated: <span id="updated_at"><?php echo nomination_profile_format_datetime($nomRow['updated_at'] ?? null); ?></span></span>
                  </div>
                </div>
              </div>

              <!-- Submitted Photos & Videos (preview before approving) -->
              <div class="card mb-4" id="submittedMediaCard">
                <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                  <span class="fw-bold">
                    <i class="bi bi-images me-1"></i>Submitted Photos &amp; Videos
                  </span>
                  <span class="small text-muted">
                    <?php if (!empty($nominationMedia)): ?>
                      <span class="badge bg-secondary me-1"><?= (int)$nominationMediaCounts['image']; ?> image<?= $nominationMediaCounts['image'] === 1 ? '' : 's'; ?></span>
                      <span class="badge bg-dark"><?= (int)$nominationMediaCounts['video']; ?> video<?= $nominationMediaCounts['video'] === 1 ? '' : 's'; ?></span>
                    <?php endif; ?>
                  </span>
                </div>
                <div class="card-body">
                  <?php if (empty($nominationMedia)): ?>
                    <p class="text-muted small mb-0">
                      <i class="bi bi-info-circle me-1"></i>
                      This business did not upload any photos or videos. Once approved you can still add media later via
                      <strong>Businesses → Media</strong>.
                    </p>
                  <?php else: ?>
                    <p class="text-muted small mb-3">
                      <i class="bi bi-info-circle me-1"></i>
                      Review what the business submitted. These will be automatically copied into the business's
                      voter-facing gallery on <strong>Approve</strong>. Click any tile to view a larger preview.
                    </p>
                    <div class="row g-3" id="submittedMediaGrid">
                      <?php foreach ($nominationMedia as $idx => $m):
                        $isVideo = $m['media_type'] === 'video';
                        $captionAttr = htmlspecialchars($m['caption'], ENT_QUOTES);
                        $urlAttr     = htmlspecialchars($m['url'], ENT_QUOTES);
                      ?>
                        <div class="col-12 col-sm-6 col-md-4 col-xl-3">
                          <button type="button"
                                  class="submitted-media-tile w-100 p-0 border rounded overflow-hidden bg-dark position-relative"
                                  data-index="<?= (int)$idx; ?>"
                                  data-url="<?= $urlAttr; ?>"
                                  data-kind="<?= htmlspecialchars($m['media_type'], ENT_QUOTES); ?>"
                                  data-caption="<?= $captionAttr; ?>"
                                  aria-label="Open <?= $isVideo ? 'video' : 'image' ?> preview">
                            <?php if ($isVideo): ?>
                              <video src="<?= $urlAttr; ?>" preload="metadata" muted playsinline class="w-100" style="aspect-ratio: 4/3; object-fit: cover;"></video>
                              <span class="position-absolute top-50 start-50 translate-middle text-white" style="font-size: 2rem; opacity: .85;">
                                <i class="bi bi-play-circle-fill"></i>
                              </span>
                            <?php else: ?>
                              <img src="<?= $urlAttr; ?>" alt="<?= $captionAttr ?: 'Submitted photo'; ?>" class="w-100" style="aspect-ratio: 4/3; object-fit: cover;">
                            <?php endif; ?>
                            <span class="position-absolute top-0 start-0 m-1 badge bg-<?= $isVideo ? 'dark' : 'secondary'; ?> text-uppercase" style="font-size: .65rem;">
                              <?= $isVideo ? 'Video' : 'Image'; ?>
                            </span>
                          </button>
                          <?php if (!empty($m['caption'])): ?>
                            <div class="small text-muted mt-1 text-truncate" title="<?= $captionAttr; ?>">
                              <?= htmlspecialchars($m['caption']); ?>
                            </div>
                          <?php endif; ?>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>
              </div>

              <!-- Categories / Awards card with Validate button -->
              <div class="card mb-4">
                <div class="card-header d-flex align-items-center justify-content-between">
                  <span class="fw-bold"><i class="bi bi-tags me-1"></i>Categories / Awards</span>
                  <button class="btn btn-sm btn-primary" id="btnValidate">
                    <i class="bi bi-shield-check me-1"></i> Validate
                  </button>
                </div>
                <div class="card-body">
                  <div id="catAwards" class="position-relative">
                    <div id="catLoader" class="text-center py-4 d-none">
                      <div class="spinner-border" role="status" aria-label="Loading awards"></div>
                    </div>
                    <div class="award-tables-row">
                      <div class="award-table-panel">
                        <h6 class="award-table-title">
                          <i class="bi bi-check-circle me-1"></i> Approved award titles
                        </h6>
                        <div class="table-responsive">
                          <table class="table table-sm table-bordered align-middle mb-0" id="approvedAwardsTable">
                            <thead>
                              <tr>
                                <th>Award title</th>
                                <th>Category</th>
                              </tr>
                            </thead>
                            <tbody>
                              <tr><td colspan="2" class="text-muted">Loading…</td></tr>
                            </tbody>
                          </table>
                        </div>
                      </div>
                      <div class="award-table-panel award-table-panel--removed">
                        <h6 class="award-table-title">
                          <i class="bi bi-slash-circle me-1"></i> Removed award titles
                        </h6>
                        <div class="table-responsive">
                          <table class="table table-sm table-bordered align-middle mb-0" id="removedAwardsTable">
                            <thead>
                              <tr>
                                <th>Award title</th>
                                <th>Category</th>
                                <th>Reason</th>
                              </tr>
                            </thead>
                            <tbody>
                              <tr><td colspan="3" class="text-muted">Loading…</td></tr>
                            </tbody>
                          </table>
                        </div>
                      </div>
                    </div>
                  </div>
                  <div id="categoriesWrap" class="d-none"></div>
                </div>
              </div>

            </div>

            <div class="col-lg-4">
              <div class="nom-profile-actions">
                <div id="votingLockNotice" class="alert alert-warning small py-2 <?php echo $votingLocked ? '' : 'd-none'; ?>" role="alert">
                  <i class="bi bi-lock-fill me-1"></i><span id="votingLockMessage"><?php echo h($votingLockMessage); ?></span>
                </div>
                <div class="card">
                  <div class="card-header fw-bold d-flex align-items-center gap-2">
                    <i class="bi bi-lightning-charge"></i> Review Actions
                  </div>
                  <div class="card-body">
                    <p class="text-muted small mb-3">Update status and optionally notify the business by email.</p>
                    <div class="d-grid gap-2" id="actionsRow">
                      <button class="btn btn-success<?php echo $reviewActionsLocked ? ' disabled' : ''; ?>" id="btnApprove" type="button"<?php echo $reviewBtnDisabled; ?>>
                        <i class="bi bi-check-circle me-1"></i> Approve
                      </button>
                      <button class="btn btn-outline-secondary<?php echo $reviewActionsLocked ? ' disabled' : ''; ?>" id="btnNeedsInfo" type="button"<?php echo $reviewBtnDisabled; ?>>
                        <i class="bi bi-question-circle me-1"></i> Mark as Needs Information
                      </button>
                      <button class="btn btn-outline-danger<?php echo $reviewActionsLocked ? ' disabled' : ''; ?>" id="btnReject" type="button"<?php echo $reviewBtnDisabled; ?>>
                        <i class="bi bi-x-circle me-1"></i> Reject
                      </button>
                      <button class="btn btn-primary d-none" id="btnReleaseBallot" type="button">
                        <i class="bi bi-megaphone me-1"></i> Confirm for public voting
                      </button>
                    </div>
                    <div id="ballotStageHint" class="small mt-3 d-none"></div>
                    <div id="actionsHint" class="small mt-3 text-muted<?php echo $reviewActionsHint === '' ? ' d-none' : ''; ?>"><?php echo h($reviewActionsHint); ?></div>
                  </div>
                </div>
              </div>
            </div>

          </div>
        </div>

        <div id="loadingBox" class="text-center text-muted py-5<?php echo $profileHasData ? ' d-none' : ''; ?>">
          <div class="spinner-border mb-3" role="status" aria-hidden="true"></div>
          <div>Loading registration profile…</div>
        </div>
        <div id="errorBox" class="alert alert-danger d-none"></div>
      </div>
    </main>
      <footer class="py-4 bg-light mt-auto">
        <div class="container-fluid px-4">
          <div class="small text-muted">&copy; 2026 Tatak Ormoc Consumers&rsquo; Choice Awards</div>
        </div>
      </footer>
</div>
</div>

<!-- Toast bottom-center -->
<div class="position-fixed bottom-0 start-50 translate-middle-x p-3" style="z-index:1100">
  <div id="toastMsg" class="toast align-items-center text-bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body" id="toastBody">Saved</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
</div>

<!-- Email Composer Modal (kept) -->
<div class="modal fade" id="notifyModal" tabindex="-1" aria-labelledby="notifyModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 id="notifyModalTitle" class="modal-title">Send Notification — <span id="notifyModalStatusLabel">Approved</span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="notifyContactAlert" class="alert small mb-3 d-none" role="status"></div>
        <div class="mb-3">
          <label class="form-label">Subject</label>
          <input id="notifySubject" type="text" class="form-control" placeholder="Email subject">
        </div>
        <div class="mt-3">
          <label class="form-label">Message</label>
          <div id="notifyEditor" style="height: 240px;"></div>
          <div class="mt-2 d-flex flex-wrap gap-2">
            <span class="badge rounded-pill bg-light text-dark border placeholder-chip" data-token="{business_name}">+ Business Name</span>
            <span class="badge rounded-pill bg-light text-dark border placeholder-chip" data-token="{owner_name}">+ Owner Name</span>
            <span class="badge rounded-pill bg-light text-dark border placeholder-chip" data-token="{category_list}">+ Categories</span>
            <span class="badge rounded-pill bg-light text-dark border placeholder-chip" data-token="{event_name}">+ Event</span>
            <span class="badge rounded-pill bg-light text-dark border placeholder-chip d-none" data-approve-only="1" data-token="{vote_url}">+ Voting Link</span>
            <span class="badge rounded-pill bg-light text-dark border placeholder-chip d-none" data-approve-only="1" data-token="{qr_code}">+ QR Code</span>
          </div>
          <details class="mt-3" open><summary class="mb-2">Preview</summary>
            <div id="notifyPreview" class="border rounded overflow-auto bg-light small" style="max-height:420px;"></div>
          </details>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button id="updateStatusOnlyBtn" class="btn btn-outline-primary">Update Status Only</button>
        <button id="sendAndUpdateBtn" class="btn btn-primary">Send Email &amp; Update</button>
      </div>
    </div>
  </div>
</div>

<!-- Permit / inline file preview (Mayor's permit, etc.) -->
<div class="modal fade" id="inlineFilePreviewModal" tabindex="-1" aria-labelledby="inlineFilePreviewModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="inlineFilePreviewModalLabel">Document preview</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center">
        <div id="inlineFilePreviewStage" class="inline-file-preview-stage"></div>
      </div>
      <div class="modal-footer justify-content-between">
        <span class="small text-muted" id="inlineFilePreviewHint">Click the image in Permits &amp; Registration to view full size.</span>
        <a href="#" id="inlineFilePreviewOpen" class="btn btn-outline-primary btn-sm" target="_blank" rel="noopener">
          <i class="bi bi-box-arrow-up-right me-1"></i> Open in new tab
        </a>
      </div>
    </div>
  </div>
</div>

<!-- Submitted Media Lightbox -->
<div class="modal fade" id="submittedMediaModal" tabindex="-1" aria-labelledby="submittedMediaModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="submittedMediaModalLabel">Submitted Media</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center">
        <div id="submittedMediaStage" style="background:#000;border-radius:.375rem;min-height:200px;display:flex;align-items:center;justify-content:center;"></div>
        <div id="submittedMediaCaption" class="text-muted small mt-2"></div>
      </div>
      <div class="modal-footer d-flex justify-content-between">
        <div class="small text-muted">
          <span id="submittedMediaPosition"></span>
        </div>
        <div class="btn-group">
          <button type="button" class="btn btn-outline-secondary" id="submittedMediaPrev"><i class="bi bi-chevron-left"></i> Prev</button>
          <button type="button" class="btn btn-outline-secondary" id="submittedMediaNext">Next <i class="bi bi-chevron-right"></i></button>
        </div>
      </div>
    </div>
  </div>
</div>

<style>
  .submitted-media-tile {
    cursor: pointer;
    transition: transform .15s ease, box-shadow .15s ease;
  }
  .submitted-media-tile:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(0, 0, 0, 0.15);
  }
  #submittedMediaStage img,
  #submittedMediaStage video {
    max-width: 100%;
    max-height: 70vh;
    border-radius: .375rem;
  }
  .strike { text-decoration: line-through; opacity: .65; }
  .val-reason-wrap { max-width: 28rem; cursor: default; }
  #valList .list-group-item { cursor: pointer; }

  .nom-profile-toolbar {
    padding: .75rem 1rem;
    border: 1px solid var(--bs-border-color);
    border-radius: .75rem;
    background: rgba(var(--bs-primary-rgb), .04);
  }
  .nom-profile-hero {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 1.25rem;
    padding: 1.25rem 1.5rem;
  }
  .nom-profile-logo {
    width: 7.5rem;
    height: 7.5rem;
    flex-shrink: 0;
    border: 1px solid var(--bs-border-color);
    border-radius: .75rem;
    background: rgba(var(--bs-secondary-rgb), .08);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
  }
  .nom-profile-logo img {
    width: 100%;
    height: 100%;
    object-fit: contain;
  }
  .nom-profile-logo--empty {
    color: var(--bs-secondary-color);
  }
  .nom-profile-hero-body { flex: 1 1 16rem; min-width: 0; }
  .nom-profile-sections { display: flex; flex-direction: column; gap: 1rem; }
  .nom-profile-section {
    border: 1px solid var(--bs-border-color);
    border-radius: .75rem;
    overflow: hidden;
    background: rgba(var(--bs-body-color-rgb), .02);
  }
  .nom-section-head {
    padding: .65rem 1rem;
    font-size: .8125rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
    border-bottom: 1px solid var(--bs-border-color);
    background: rgba(var(--bs-primary-rgb), .06);
    color: var(--bs-body-color);
  }
  .nom-dl { margin: 0; }
  .nom-dl-row {
    display: grid;
    grid-template-columns: minmax(10.5rem, 36%) 1fr;
    gap: .5rem 1.25rem;
    padding: .75rem 1rem;
    border-bottom: 1px solid var(--bs-border-color);
  }
  .nom-dl-row:last-child { border-bottom: 0; }
  .nom-dl-label {
    margin: 0;
    font-size: .8125rem;
    font-weight: 600;
    color: var(--bs-secondary-color);
  }
  .nom-dl-value {
    margin: 0;
    font-weight: 500;
    word-break: break-word;
  }
  .nom-dl-value a { word-break: break-all; }
  .nom-inline-file-btn {
    position: relative;
    display: inline-block;
    max-width: 100%;
    cursor: zoom-in;
    border-radius: .375rem;
    transition: box-shadow .15s ease, transform .15s ease;
  }
  .nom-inline-file-btn:hover,
  .nom-inline-file-btn:focus-visible {
    box-shadow: 0 0 0 2px rgba(var(--bs-primary-rgb), .45);
    outline: 0;
  }
  .nom-inline-file-btn:hover .nom-inline-file-zoom,
  .nom-inline-file-btn:focus-visible .nom-inline-file-zoom { opacity: 1; }
  .nom-inline-file-btn.is-missing {
    cursor: default;
  }
  .nom-inline-file-btn.is-missing .nom-inline-file-zoom {
    display: none;
  }
  .nom-inline-file-zoom {
    position: absolute;
    right: .25rem;
    bottom: .25rem;
    width: 1.5rem;
    height: 1.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: .25rem;
    background: rgba(0, 0, 0, .65);
    color: #fff;
    font-size: .75rem;
    opacity: .85;
    pointer-events: none;
  }
  .inline-file-preview-stage {
    background: #000;
    border-radius: .375rem;
    min-height: 200px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: .5rem;
  }
  .inline-file-preview-stage img {
    max-width: 100%;
    max-height: 75vh;
    object-fit: contain;
    border-radius: .25rem;
  }
  @media (max-width: 575.98px) {
    .nom-dl-row { grid-template-columns: 1fr; gap: .25rem; }
  }
  @media (min-width: 992px) {
    .nom-profile-actions { position: sticky; top: 5.5rem; }
  }
  html.dark-mode .nom-profile-section { background: rgba(15, 23, 42, .35); }
  html.dark-mode .nom-section-head { background: rgba(59, 130, 246, .12); color: #e2e8f0; }
  html.dark-mode .nom-dl-label { color: #94a3b8 !important; }
  html.dark-mode .nom-dl-value { color: #e5e7eb !important; }
  html.dark-mode .nom-profile-toolbar { background: rgba(59, 130, 246, .08); border-color: #334155; }
  .award-tables-row {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1rem;
  }
  @media (min-width: 992px) {
    .award-tables-row {
      grid-template-columns: 1fr 1fr;
      align-items: start;
    }
  }
  .award-table-panel {
    border: 1px solid var(--bs-border-color);
    border-radius: .625rem;
    padding: .75rem .85rem .85rem;
    background: #fff;
    min-width: 0;
  }
  .award-table-panel--removed {
    background: #fff8f8;
  }
  .award-table-title {
    font-size: .78rem;
    font-weight: 800;
    letter-spacing: .04em;
    text-transform: uppercase;
    color: #334155;
    margin: 0 0 .65rem;
  }
  .award-table-panel table thead th {
    font-size: .75rem;
    text-transform: uppercase;
    letter-spacing: .03em;
    color: #64748b;
    white-space: nowrap;
  }
  html.dark-mode .award-table-panel {
    background: rgba(15, 23, 42, .35);
  }
  html.dark-mode .award-table-panel--removed {
    background: rgba(127, 29, 29, .18);
  }
  html.dark-mode .award-table-title {
    color: #94a3b8;
  }
</style>

<!-- Validate awards modal -->
<div class="modal fade" id="validateModal" tabindex="-1" aria-labelledby="validateModalLabel" aria-hidden="true"
     data-endpoint="remove_nomination_question.php"
     data-csrf="<?php echo h($csrf); ?>"
     data-nomination="<?php echo (int)$nomination_id; ?>">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="validateModalLabel"><i class="bi bi-shield-check me-1"></i> Validate Awards</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small mb-3">
          Select awards to remove from this registration. For each selected award, choose a reason for removal.
        </p>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
          <button type="button" class="btn btn-sm btn-outline-secondary" id="valSelectAll">Select all</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" id="valClearAll">Clear</button>
          <span class="ms-auto small text-muted"><span id="valSelectedCount">0</span> selected</span>
        </div>
        <div id="valList" class="list-group">
          <?php if (empty($appliedRows)): ?>
            <div class="list-group-item text-muted">No awards linked to this registration.</div>
          <?php else: ?>
            <?php foreach ($appliedRows as $ar):
              $qid = (int)($ar['question_id'] ?? 0);
              $catName = trim((string)($ar['category_name'] ?? ''));
              $awardName = trim((string)($ar['question_name'] ?? 'Award'));
              $label = $catName !== '' ? $catName . ': ' . $awardName : $awardName;
            ?>
              <div class="list-group-item list-group-item-action d-flex align-items-start gap-2 py-3" id="val-row-<?php echo $qid; ?>">
                <input class="form-check-input rm-check mt-1 flex-shrink-0" type="checkbox" value="<?php echo $qid; ?>" id="val-check-<?php echo $qid; ?>">
                <div class="flex-grow-1 min-w-0">
                  <label class="fw-semibold d-block mb-0" data-label="name" for="val-check-<?php echo $qid; ?>"><?php echo h($label); ?></label>
                  <?php if ($catName !== ''): ?>
                    <span class="small text-muted"><?php echo h($catName); ?></span>
                  <?php endif; ?>
                  <div class="val-reason-wrap mt-2 d-none">
                    <label class="form-label small mb-1" for="val-reason-<?php echo $qid; ?>">Reason for removal</label>
                    <select class="form-select form-select-sm val-reason" id="val-reason-<?php echo $qid; ?>" disabled>
                      <option value="">Select a reason</option>
                      <?php foreach ($awardRemovalReasons as $reasonKey => $reasonLabel): ?>
                        <option value="<?php echo h($reasonKey); ?>"><?php echo h($reasonLabel); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
                <span data-status class="flex-shrink-0"></span>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <div id="valProgress" class="small text-muted mt-2 d-none">Processing removals…</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="valFinalizeBtn" disabled>Remove selected awards</button>
      </div>
    </div>
  </div>
</div>

  <?php include __DIR__ . '/partials/admin_legacy_footer.php'; ?>

<?php
if (!function_exists('qr_tracking_url')) {
    require_once __DIR__ . '/qr_url.php';
}
$trackUrl = function_exists('qr_tracking_url') ? qr_tracking_url($conn) : '';
?>
<script>window.toccaTrackUrl = <?php echo json_encode($trackUrl, JSON_UNESCAPED_SLASHES); ?>;</script>
<script src="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.min.js"></script>
<script src="js/branded_email_preview.js"></script>
<script src="nomination_profile.js?v=<?php echo (int)$nomProfileJsVersion; ?>"></script>

<!-- Validate flow JS -->
<script>
(function(){
  const validateBtn = document.getElementById('btnValidate');
  const modalEl     = document.getElementById('validateModal');
  if (!validateBtn || !modalEl) return;

  const modal       = new bootstrap.Modal(modalEl);
  const list        = modalEl.querySelector('#valList');
  const selectedLbl = modalEl.querySelector('#valSelectedCount');
  const finalizeBtn = modalEl.querySelector('#valFinalizeBtn');
  const clearAllBtn = modalEl.querySelector('#valClearAll');
  const selectAllBtn= modalEl.querySelector('#valSelectAll');
  const progressLbl = modalEl.querySelector('#valProgress');

  const endpoint    = modalEl.dataset.endpoint; // change if different path
  const csrf        = modalEl.dataset.csrf;
  const nomination  = parseInt(modalEl.dataset.nomination, 10);

  // === TOAST HELPERS (new) ===
  const toastEl = document.getElementById('toastMsg');
  const toastBody = document.getElementById('toastBody');
  const toastInst = toastEl ? bootstrap.Toast.getOrCreateInstance(toastEl, { delay: 2200 }) : null;
  function showToast(msg, ok = true) {
    if (!toastInst) return;
    toastBody.textContent = msg;
    toastEl.className = 'toast align-items-center border-0 ' + (ok ? 'text-bg-success' : 'text-bg-danger');
    toastInst.show();
  }

  function selectedChecks(){
    return Array.from(list?.querySelectorAll('.rm-check:checked') || []);
  }

  function rowReasonSelect(cb){
    return cb.closest('.list-group-item')?.querySelector('.val-reason');
  }

  function allSelectedHaveReason(){
    return selectedChecks().every(cb => {
      const sel = rowReasonSelect(cb);
      return !!(sel && sel.value);
    });
  }

  function setRowSelected(row, checked){
    row?.querySelector('[data-label="name"]')?.classList.toggle('strike', checked);
    const wrap = row?.querySelector('.val-reason-wrap');
    const sel = row?.querySelector('.val-reason');
    if (wrap) wrap.classList.toggle('d-none', !checked);
    if (sel) {
      sel.disabled = !checked;
      if (!checked) sel.value = '';
    }
  }

  function resetValidateRows(){
    if (!list) return;
    list.querySelectorAll('[data-status]').forEach(s => { s.textContent = ''; });
    list.querySelectorAll('.rm-check').forEach(cb => {
      cb.checked = false;
      setRowSelected(cb.closest('.list-group-item'), false);
    });
  }

  function updateSelectedCount(){
    const n = selectedChecks().length;
    if (selectedLbl) selectedLbl.textContent = n;
    if (finalizeBtn) finalizeBtn.disabled = (n === 0 || !allSelectedHaveReason());
  }

  validateBtn.addEventListener('click', () => {
    resetValidateRows();
    updateSelectedCount();
    progressLbl && progressLbl.classList.add('d-none');
    modal.show();
  });

  list?.addEventListener('click', (e) => {
    if (e.target.closest('.val-reason-wrap')) return;
    if (e.target.classList.contains('rm-check')) return;
    if (e.target.closest('label[for]')) return;
    const row = e.target.closest('.list-group-item');
    const cb = row?.querySelector('.rm-check');
    if (!cb || cb.disabled) return;
    cb.checked = !cb.checked;
    cb.dispatchEvent(new Event('change', { bubbles: true }));
  });

  list?.addEventListener('change', (e) => {
    if (e.target.classList.contains('rm-check')) {
      setRowSelected(e.target.closest('.list-group-item'), e.target.checked);
      updateSelectedCount();
    }
    if (e.target.classList.contains('val-reason')) {
      updateSelectedCount();
    }
  });

  clearAllBtn?.addEventListener('click', () => {
    list?.querySelectorAll('.rm-check').forEach(cb => {
      cb.checked = false;
      cb.dispatchEvent(new Event('change', { bubbles: true }));
    });
  });
  selectAllBtn?.addEventListener('click', () => {
    list?.querySelectorAll('.rm-check').forEach(cb => {
      cb.checked = true;
      cb.dispatchEvent(new Event('change', { bubbles: true }));
    });
  });

  async function removeOne(qid, reason){
    const resp = await fetch(endpoint, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ csrf: csrf, nomination_id: nomination, question_id: qid, reason: reason })
    });
    const data = await resp.json().catch(()=>({}));
    if (!resp.ok || data.status !== 'success') throw new Error(data.message || 'Remove failed');
    return true;
  }

  // === FINALIZE: RUN REMOVALS + SHOW TOAST ===
  finalizeBtn?.addEventListener('click', async () => {
    const checks = selectedChecks();
    if (!checks.length) {
      showToast('Select at least one award to remove.', false);
      return;
    }
    if (!allSelectedHaveReason()) {
      showToast('Choose a reason for each selected award.', false);
      return;
    }

    finalizeBtn.disabled = true;
    clearAllBtn && (clearAllBtn.disabled = true);
    selectAllBtn && (selectAllBtn.disabled = true);
    progressLbl && progressLbl.classList.remove('d-none');

    let okCount = 0, failCount = 0;

    for (const cb of checks) {
      const qid = parseInt(cb.value, 10);
      const row = document.getElementById('val-row-' + qid);
      const status = row?.querySelector('[data-status]');
      try {
        if (status) status.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';
        await removeOne(qid, rowReasonSelect(cb)?.value || '');
        okCount++;
        if (status) status.innerHTML = '<i class="bi bi-check-circle text-success"></i>';
        row?.classList.add('opacity-75');
      } catch (err) {
        failCount++;
        if (status) status.innerHTML = '<i class="bi bi-x-circle text-danger" title="' + (err?.message||'Error') + '"></i>';
      }
    }

    // Build result message
    let msg = '';
    let ok = true;
    if (okCount > 0 && failCount === 0) {
      msg = 'Awards validated successfully.';
      ok = true;
    } else if (okCount > 0 && failCount > 0) {
      msg = `Validated ${okCount} award(s), ${failCount} failed.`;
      ok = false;
    } else {
      msg = 'No changes applied.';
      ok = true;
    }

    // Close modal, show toast, then refresh to reflect changes
    modal.hide();
    showToast(msg, ok);

    // Give the toast a moment before reload
    setTimeout(() => { window.location.reload(); }, 900);
  });
})();
</script>
</body>
</html>
