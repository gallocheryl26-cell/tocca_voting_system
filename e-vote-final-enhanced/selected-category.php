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



        <section class="voter-hero voter-hero--vote">

            <h1 id="selectedCategoryTitle">Category</h1>

            <p class="mb-0">Pick from the list for each award. A photo is optional.</p>

            <div class="category-switcher-wrap">

                <label for="categorySwitcher" class="form-label visually-hidden">Change category</label>

                <select id="categorySwitcher" class="form-select form-select-lg" aria-label="Switch category"></select>

            </div>

        </section>



        <section class="voter-content voter-content--vote">

            <div class="voter-search-panel">

                <label for="questionSearchInput" class="form-label">

                    <i class="fa-solid fa-magnifying-glass me-1" aria-hidden="true"></i> Search award titles in this category

                </label>

                <input type="search" id="questionSearchInput" class="form-control" placeholder="Type to filter award names…" disabled autocomplete="off">

            </div>



            <div class="category-panel" id="questionsContainer" role="region" aria-live="polite" aria-label="Award titles">

                <p class="text-center text-muted py-4 mb-0" id="initialMessage">Loading award titles…</p>

            </div>



            <div id="controls" class="d-none">

                <div class="voter-controls-bar nav-control-group">

                    <a href="<?php echo tocca_voter_href('category.php'); ?>" class="btn btn-outline-secondary nav-btn">

                        <i class="fa-solid fa-grid-2 me-1" aria-hidden="true"></i> Categories

                    </a>

                    <button type="button" class="btn btn-info nav-btn" id="toggleViewBtn">

                        <i class="fa-solid fa-list me-1" aria-hidden="true"></i> Show all awards

                    </button>

                    <button type="button" id="submitVoteBtn" class="btn btn-success nav-btn voter-summary-button" aria-label="Review ballot summary and cast votes">

                        <i class="fa-solid fa-clipboard-check" aria-hidden="true"></i>
                        <span class="voter-summary-button-copy">
                            <span class="voter-summary-button-title">Review ballot summary</span>
                            <span class="voter-summary-button-hint">Go to summary and cast your votes</span>
                        </span>

                    </button>

                </div>

            </div>



            <div id="pageControls" class="voter-page-nav nav-control-group d-none" aria-label="Award navigation">

                <p class="voter-award-nav-label"><i class="fa-solid fa-arrows-left-right" aria-hidden="true"></i> Award navigation <span>Continue answering awards in this category</span></p>

                <button type="button" class="btn btn-prev btn-custom nav-btn" id="prevBtn">

                    <i class="fas fa-arrow-left" aria-hidden="true"></i> Previous award

                </button>

                <button type="button" class="btn btn-next btn-custom nav-btn" id="nextBtn">

                    Next award <i class="fas fa-arrow-right" aria-hidden="true"></i>

                </button>

            </div>

        </section>

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

