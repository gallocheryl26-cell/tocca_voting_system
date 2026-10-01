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
          Use the main voting site for other businesses and categories with the same Google account.
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
        <?php if (empty($voteTutorialDemo)): ?>
          <button type="button" class="btn btn-outline-primary btn-sm" data-vote-tutorial-open>
            <i class="fa-solid fa-circle-play me-1" aria-hidden="true"></i>How to vote
          </button>
        <?php endif; ?>
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
