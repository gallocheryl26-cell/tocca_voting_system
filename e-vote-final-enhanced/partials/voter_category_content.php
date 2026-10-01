    <section class="voter-hero">
      <h1>Category</h1>
      <p>Please select a category to begin voting. You can return here anytime to switch categories.</p>
    </section>

    <section class="voter-content">
      <?php if (empty($voteTutorialDemo)) include __DIR__ . '/voter_tutorial_entry.php'; ?>
      <div class="category-grid" id="categoryList" role="list" aria-live="polite">
        <p class="text-center text-muted py-4 mb-0">Loading categories…</p>
      </div>
    </section>
