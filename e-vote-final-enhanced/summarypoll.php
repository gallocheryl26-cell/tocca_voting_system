<?php
require_once __DIR__ . '/require_voter_page.php';
require_once '../tocca_admin/get_logo.php';
$voterStep = 3;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php $pageTitle = 'Summary of Your Votes | Tatak Ormoc'; include __DIR__ . '/partials/voter_head.php'; ?>
</head>
<body class="voter-page">
  <div class="voter-shell">
    <header class="voter-header">
      <img id="headerLogo" src="img/tocca2023.jpg" alt="TOCCA Header Image" class="header-logo" />
    </header>

    <?php include __DIR__ . '/partials/voter_steps.php'; ?>

    <section class="voter-hero">
      <h1>Summary</h1>
      <p>Review your choices below. Cast individual votes or use <strong>Vote All</strong> to submit every answered award title at once.</p>
    </section>

    <section class="voter-content">
      <div class="voter-toolbar">
        <a href="category.php" id="backToCategoriesBtn" class="btn btn-outline-secondary">
          <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i> Categories
        </a>
        <button type="button" id="signOutBtn" class="btn btn-outline-danger" data-bs-toggle="modal">
          <i class="fa-solid fa-right-from-bracket me-1" aria-hidden="true"></i> Sign out
        </button>
      </div>

      <div id="summaryOverview" class="summary-overview" aria-label="Overall voting progress"></div>
      <div id="summaryContainer" class="accordion category-panel mb-0"></div>

      <div class="summary-actions-bar d-flex justify-content-center">
        <button type="button" id="voteAllBtn" class="btn btn-success btn-lg">
          <i class="fa-solid fa-check-double me-2" aria-hidden="true"></i> Vote All
        </button>
      </div>
    </section>

  </div>

  <?php include __DIR__ . '/partials/voter_footer.php'; ?>

  <?php include __DIR__ . '/partials/choice_media_modal.php'; ?>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="js/voter_modal_stack.js"></script>
  <script src="api_fetch.js"></script>
  <script src="choice_media_viewer.js"></script>
  <script src="apply_voter_style.js"></script>
  <script src="toast.js"></script>
  <script src="voter_dialogs.js"></script>
  <script src="progress_helper.js"></script>
  <script src="signout.js"></script>
  <script type="module" src="summary_data.js"></script>
  <script type="module" src="vote_finalization.js"></script>
  <script type="module" src="summary_renderer.js"></script>
  <script type="module" src="summarypoll.js"></script>

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
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="confirmSignOutLabel">Before You Sign Out</h5>
        </div>
        <div class="modal-body" id="confirmSignOutMessage"></div>
        <div class="modal-footer flex-column gap-2">
          <button type="button" id="confirmSignOutBtn" class="btn btn-outline-danger w-100">Sign Out Anyway</button>
          <button type="button" class="btn btn-secondary w-100" data-bs-dismiss="modal">Continue Voting</button>
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
    function DisableBackButton() { window.history.forward(); }
    DisableBackButton();
    window.onload = DisableBackButton;
    window.onpageshow = function (evt) { if (evt.persisted) DisableBackButton(); };
    window.onunload = function () { void 0; };
  </script>
</body>
</html>
