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

                        <i class="fa-solid fa-list me-1" aria-hidden="true"></i> List view

                    </button>

                    <button type="button" id="submitVoteBtn" class="btn btn-success nav-btn d-none">

                        Review summary <i class="fa-solid fa-arrow-right ms-1" aria-hidden="true"></i>

                    </button>

                </div>

            </div>



            <div id="pageControls" class="voter-page-nav nav-control-group d-flex justify-content-between gap-2 d-none">

                <button type="button" class="btn btn-prev btn-custom nav-btn" id="prevBtn">

                    <i class="fas fa-arrow-left" aria-hidden="true"></i> Previous

                </button>

                <button type="button" class="btn btn-next btn-custom nav-btn" id="nextBtn">

                    Next <i class="fas fa-arrow-right" aria-hidden="true"></i>

                </button>

            </div>

        </section>
