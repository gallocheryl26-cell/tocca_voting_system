<?php
require_once __DIR__ . '/require_voter_page.php';
require_once '../tocca_admin/get_logo.php';
$voterStep = 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php $pageTitle = "Categories | Tatak Ormoc Consumers' Choice Awards"; include __DIR__ . '/partials/voter_head.php'; ?>
</head>
<body class="voter-page">
  <div class="voter-shell">
    <header class="voter-header">
      <img id="headerLogo" src="img/tocca2023.jpg" alt="TOCCA Header Image" class="header-logo" />
    </header>

    <?php include __DIR__ . '/partials/voter_steps.php'; ?>

    <section class="voter-hero">
      <h1>Category</h1>
      <p>Please select a category to begin voting. You can return here anytime to switch categories.</p>
    </section>

    <section class="voter-content">
      <div class="category-grid" id="categoryList" role="list" aria-live="polite">
        <p class="text-center text-muted py-4 mb-0">Loading categories…</p>
      </div>
    </section>

    <?php include __DIR__ . '/partials/voter_footer.php'; ?>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="js/voter_modal_stack.js"></script>
  <script src="api_fetch.js"></script>
  <script type="module" src="category.js"></script>
  <script src="apply_voter_style.js"></script>
  <script src="toast.js"></script>

  <div class="toast-container position-fixed top-0 start-50 translate-middle-x p-3">
    <div id="validationToast" class="toast text-bg-info" role="alert" aria-live="assertive" aria-atomic="true">
      <div class="d-flex">
        <div class="toast-body"></div>
      </div>
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
