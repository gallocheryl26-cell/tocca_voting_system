<?php

require_once __DIR__ . '/require_voter_page.php';

require_once '../tocca_admin/get_logo.php';



$event_id = $_GET['event_id'] ?? 1;

$voterStep = 2;

?>



<!DOCTYPE html>

<html lang="en">

<head>

    <?php $pageTitle = 'Vote | Tatak Ormoc'; include __DIR__ . '/partials/voter_head.php'; ?>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css"/>

    <script>

        try { localStorage.setItem('current_event_id', '<?php echo $event_id; ?>'); } catch (e) {}

    </script>

</head>



<body class="voter-page">

    <div class="voter-shell">

        <header class="voter-header">

            <img id="headerLogo" src="img/tocca2023.jpg" alt="TOCCA Header Image" class="header-logo" />

        </header>



        <?php include __DIR__ . '/partials/voter_steps.php'; ?>



        <?php include __DIR__ . '/partials/voter_ballot_content.php'; ?>

    </div>

    <?php include __DIR__ . '/partials/voter_footer.php'; ?>

    <?php include __DIR__ . '/partials/choice_media_modal.php'; ?>



    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script src="api_fetch.js"></script>

    <script src="https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js"></script>

    <script src="choice_media_viewer.js"></script>

    <?php
    $voteJsV = (int) (@filemtime(__DIR__ . '/selected-category.js') ?: time());
    $bootJsV = (int) (@filemtime(__DIR__ . '/vote_ballot_boot.js') ?: time());
    include __DIR__ . '/partials/voter_js_importmap.php';
    ?>
    <script src="vote_ballot_boot.js?v=<?php echo $bootJsV; ?>"></script>
    <script type="module" src="selected-category.js?v=<?php echo $voteJsV; ?>"></script>

    <script src="apply_voter_style.js"></script>
    <script src="js/voter_modal_stack.js"></script>
    <script src="toast.js"></script>

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

