    <section class="voter-hero">
      <h1>Summary</h1>
      <p>Review your choices below. Cast individual votes or use <strong>Vote All</strong> to submit every answered award title at once.</p>
    </section>

    <section class="voter-content">
      <div class="voter-toolbar">
        <a href="<?php echo tocca_voter_href('category.php'); ?>" id="backToCategoriesBtn" class="btn btn-outline-secondary">
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
