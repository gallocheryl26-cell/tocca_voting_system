<?php
require_once '../tocca_admin/db_connection.php';
require_once '../tocca_admin/get_logo.php';
require_once '../tocca_admin/includes/ballot_status.php';
mysqli_report(MYSQLI_REPORT_OFF);
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function findCompanyLogoForChoice(mysqli $conn, int $choiceId): ?string {
  $choiceCols = ['logo_path','logo','logo_url','image_path'];
  foreach ($choiceCols as $col) {
    try {
      $sql = "SELECT $col AS p FROM tbl_choices WHERE choice_id = ? LIMIT 1";
      if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param('i', $choiceId);
        $stmt->execute();
        $stmt->bind_result($p);
        if ($stmt->fetch() && $p !== null && $p !== '') { $stmt->close(); return (string)$p; }
        $stmt->close();
      }
    } catch (Throwable $e) {
    }
  }
  try {
    $sql = "
      SELECT a.answer
      FROM tbl_nominations n
      JOIN tbl_nomination_answers a ON a.nomination_id = n.nomination_id
      JOIN tbl_nomination_fields  f ON f.id = a.field_id
      WHERE n.merged_choice_id = ?
        AND (
          LOWER(f.name)  IN ('logo','logo_path','business_logo','company_logo')
          OR LOWER(f.label) LIKE '%logo%'
        )
      ORDER BY n.updated_at DESC, a.created_at DESC, a.id DESC
      LIMIT 1
    ";
    if ($stmt = $conn->prepare($sql)) {
      $stmt->bind_param('i', $choiceId);
      $stmt->execute();
      $stmt->bind_result($ans);
      if ($stmt->fetch() && $ans !== null && $ans !== '') { $stmt->close(); return (string)$ans; }
      $stmt->close();
    }
  } catch (Throwable $e) {
  }
  return null;
}
$companyLogoPath = null;
$choice_name = '';
$choiceHasMedia = false;
$choiceId = isset($_GET['choice_id']) ? (int)$_GET['choice_id'] : 0;
if ($choiceId > 0 && ballot_status_flag($conn, $choiceId) === false) {
  header('Location: qr_vote.php?choice_id=' . $choiceId, true, 302);
  exit;
}
if ($choiceId > 0) {
  $companyLogoPath = findCompanyLogoForChoice($conn, $choiceId);
  if ($companyLogoPath) $companyLogoPath = resolveAssetPath($companyLogoPath);

  if ($stmt = $conn->prepare('SELECT choice_name FROM tbl_choices WHERE choice_id = ? LIMIT 1')) {
    $stmt->bind_param('i', $choiceId);
    $stmt->execute();
    if ($row = $stmt->get_result()->fetch_assoc()) {
      $choice_name = (string)$row['choice_name'];
    }
    $stmt->close();
  }

  if ($res = $conn->query("SHOW TABLES LIKE 'tbl_choice_media'")) {
    if ($res->num_rows > 0) {
      if ($mStmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM tbl_choice_media WHERE choice_id = ?')) {
        $mStmt->bind_param('i', $choiceId);
        $mStmt->execute();
        $mStmt->bind_result($mediaCnt);
        if ($mStmt->fetch()) {
          $choiceHasMedia = ((int) $mediaCnt) > 0;
        }
        $mStmt->close();
      }
    }
    $res->close();
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php $pageTitle = 'QR Vote | Tatak Ormoc'; include __DIR__ . '/partials/voter_head.php'; ?>
  <script src="https://www.gstatic.com/firebasejs/10.12.1/firebase-app-compat.js"></script>
  <script src="https://www.gstatic.com/firebasejs/10.12.1/firebase-auth-compat.js"></script>
  <script src="https://www.google.com/recaptcha/api.js" async defer></script>
  <script>
    const firebaseConfig = {
      apiKey: "AIzaSyD3nY0rVvAczMRmbUEdHs2-liek3EOCvuo",
      authDomain: "tatakormoc-a9bc1.firebaseapp.com",
      projectId: "tatakormoc-a9bc1",
      storageBucket: "tatakormoc-a9bc1.appspot.com",
      messagingSenderId: "398460032274",
      appId: "1:398460032274:web:fbd32f43b023789d34cfcb",
      measurementId: "G-KD7X3DQYLP"
    };
    firebase.initializeApp(firebaseConfig);
  </script>
  <link rel="stylesheet" href="css/qr-vote-pages.css" />
</head>
<?php $voterStep = 2; ?>
<body class="voter-page voter-page--qr-establishment" data-page="qr-establishment">
  <div class="voter-shell">
    <header class="voter-header">
      <img id="headerLogo" src="img/tocca2023.jpg" alt="TOCCA Header Image" class="header-logo" />
    </header>

    <?php include __DIR__ . '/partials/voter_steps.php'; ?>

    <section class="voter-hero voter-hero--qr">
      <h1 id="businessName"><?php echo h($choice_name); ?></h1>
      <p class="mb-0">Vote for this business on each award listed below. You can also continue on the main voting site for other categories.</p>
    </section>

    <section class="voter-content voter-content--vote">
      <?php if (!empty($companyLogoPath)): ?>
        <div class="d-flex justify-content-center mb-3">
          <div class="voter-logo-frame">
            <img src="<?php echo h($companyLogoPath); ?>" alt="Business logo" class="voter-logo-frame__img">
          </div>
        </div>
      <?php endif; ?>

      <div id="qrMainSiteNote" class="qr-main-site-banner" role="note">
        <p class="qr-main-site-banner__title mb-1"><i class="fa-solid fa-globe me-1" aria-hidden="true"></i> Other awards</p>
        <p class="qr-main-site-banner__text mb-0">
          This page is only for <strong><?php echo h($choice_name); ?></strong>.
          Use the main voting site for other businesses and categories (same mobile number and access code).
        </p>
      </div>

      <div id="qrCompletePanel" class="qr-complete-panel d-none" role="status">
        <p class="qr-complete-panel__title mb-1"><i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i> All done here</p>
        <p class="qr-complete-panel__text mb-0">You have voted on every award for this business.</p>
      </div>

      <div class="qr-insights" aria-live="polite">
        <div class="qr-insight-chip qr-insight-chip--progress"><span class="text-muted d-block small">Progress</span><strong id="voteCountText">0 of 0 awards voted</strong></div>
        <div id="qrCategoryChip" class="qr-insight-chip qr-insight-chip--categories"><span class="text-muted d-block small">Categories</span><strong id="categoryCountText">0</strong></div>
      </div>
      <div class="qr-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
        <div id="progressBarText" class="qr-progress-fill"></div>
      </div>

      <div class="qr-establishment-toolbar">
        <?php if ($choiceId > 0 && $choiceHasMedia): ?>
          <button id="viewBusinessMediaBtn"
                  type="button"
                  class="btn btn-outline-primary btn-sm view-business-btn"
                  data-choice-id="<?php echo (int)$choiceId; ?>"
                  data-choice-name="<?php echo h($choice_name ?? ''); ?>">
            <i class="fas fa-images me-1" aria-hidden="true"></i>Photos &amp; videos
          </button>
        <?php endif; ?>
        <button type="button" id="signOutBtn" class="btn btn-outline-danger btn-sm ms-auto">
          <i class="fa-solid fa-right-from-bracket me-1" aria-hidden="true"></i>Sign out
        </button>
      </div>

      <div id="summaryContainer" class="qr-category-panel category-panel" role="region" aria-label="Awards for this business"></div>

      <div class="qr-bottom-bar">
        <div class="qr-bottom-bar__inner">
          <button type="button" id="voteAllBtn" class="btn btn-success flex-grow-1">
            <i class="fa-solid fa-check-double me-1" aria-hidden="true"></i>Vote all
          </button>
          <button type="button" id="openLegacyBtn" class="btn btn-outline-primary flex-grow-1">
            <i class="fa-solid fa-arrow-up-right-from-square me-1" aria-hidden="true"></i>Main voting site
          </button>
        </div>
      </div>
    </section>

  </div>

  <?php include __DIR__ . '/partials/voter_footer.php'; ?>
  <?php include __DIR__ . '/partials/choice_media_modal.php'; ?>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="js/voter_modal_stack.js"></script>
  <script src="voter_dialogs.js"></script>
  <script src="progress_helper.js"></script>
  <script src="choice_media_viewer.js"></script>
  <script type="module" src="qr_selected_category.js"></script>
  <script src="apply_voter_style.js"></script>
  <script src="toast.js"></script>
  <script src="signout.js?v=<?php echo (int) (@filemtime(__DIR__ . '/signout.js') ?: time()); ?>"></script>
  <script>
    (function () {
      var btn = document.getElementById('viewBusinessMediaBtn');
      if (!btn) return;
      btn.addEventListener('click', function () {
        var cid = parseInt(btn.getAttribute('data-choice-id') || '0', 10);
        var cname = btn.getAttribute('data-choice-name') || '';
        if (!cid || !window.ChoiceMediaViewer) return;
        window.ChoiceMediaViewer.open(cid, cname);
      });
    })();
  </script>
  <div class="modal fade voter-modal" id="voterConfirmModal" tabindex="-1" aria-labelledby="voterConfirmTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="voterConfirmTitle">Please confirm</h5>
        </div>
        <div class="modal-body" id="voterConfirmMessage"></div>
        <div class="modal-footer flex-column gap-2">
          <button type="button" id="voterConfirmOkBtn" class="btn btn-primary w-100">Confirm</button>
          <button type="button" class="btn btn-secondary w-100" data-voter-confirm-cancel data-bs-dismiss="modal">Cancel</button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade voter-modal" id="confirmSignOutModal" tabindex="-1" aria-labelledby="confirmSignOutLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="confirmSignOutLabel">Before You Sign Out</h5>
        </div>
        <div class="modal-body" id="confirmSignOutMessage"></div>
        <div class="modal-footer flex-column gap-2 border-0 pt-0">
          <button type="button" id="confirmSignOutBtn" class="btn btn-outline-danger w-100">Sign out anyway</button>
          <button type="button" class="btn btn-secondary w-100" data-bs-dismiss="modal">Continue voting here</button>
        </div>
      </div>
    </div>
  </div>
  <div class="toast-container position-fixed top-0 start-50 translate-middle-x p-3">
    <div id="validationToast" class="toast text-bg-info" role="alert" aria-live="assertive" aria-atomic="true">
      <div class="toast-body"></div>
    </div>
  </div>
  <script>
    function DisableBackButton(){ window.history.forward(); }
    DisableBackButton();
    window.onload = DisableBackButton;
    window.onpageshow = function(evt){ if (evt.persisted) DisableBackButton(); }
    window.onunload = function(){};
  </script>
</body>
</html>
