<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/admin_init.php';
require_once __DIR__ . '/includes/category_voting_profile.php';
admin_apply_nav_from_script('categories.php');

if ($conn instanceof mysqli) {
    category_voting_profile_ensure_schema($conn);
}
$votingProfileOptions = category_voting_profile_admin_options();

$activeEvent = ($conn instanceof mysqli) ? admin_get_active_event($conn) : null;
$activeEventId = $activeEvent ? (int) ($activeEvent['event_id'] ?? 0) : 0;
$activeEventLabel = '';
if ($activeEvent) {
    $activeEventLabel = trim(
        (string) ($activeEvent['event_name'] ?? 'Active event')
        . (($activeEvent['year'] ?? '') !== '' ? ' (' . $activeEvent['year'] . ')' : '')
    );
}

$pageTitle = 'Categories';
$useDataTables = true;
$pageScripts = ['js/admin_confirm.js', 'category.js'];

ob_start();
?>
  <div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="editModalLabel">Add Category</h5>
        </div>
        <div class="modal-body">
          <form id="editForm" novalidate>
            <input type="hidden" id="editCategoryId">
            <div class="mb-3">
              <label for="editName" class="form-label">Category <span class="text-danger">*</span></label>
              <input type="text" id="editName" class="form-control" required autocomplete="off" aria-describedby="editNameFeedback" />
              <div class="invalid-feedback" id="editNameFeedback">Please enter a category name.</div>
            </div>
            <div class="mb-3">
              <label class="form-label" for="activeEventDisplay">Event</label>
              <input type="hidden" id="eventId" name="event_id" value="<?= $activeEventId > 0 ? $activeEventId : '' ?>">
              <div
                id="activeEventDisplay"
                class="form-control bg-body-secondary"
                tabindex="-1"
                aria-readonly="true"
              ><?= $activeEventId > 0 ? h($activeEventLabel) : 'No active event' ?></div>
              <?php if ($activeEventId <= 0): ?>
                <div class="form-text text-danger" id="eventIdFeedback">
                  Activate an event under File Maintenance → Events before adding categories.
                </div>
              <?php else: ?>
                <div class="form-text">Categories are created for the current active event.</div>
              <?php endif; ?>
            </div>
            <div class="mb-0">
              <label for="editVotingProfile" class="form-label">Voter form style</label>
              <select id="editVotingProfile" class="form-select" aria-describedby="editVotingProfileHelp">
                <?php foreach ($votingProfileOptions as $key => $opt): ?>
                  <option value="<?= h($key) ?>"><?= h($opt['label']) ?></option>
                <?php endforeach; ?>
              </select>
              <div id="editVotingProfileHelp" class="form-text">
                Controls the labels voters see when typing answers (e.g. business name vs song title vs place).
              </div>
            </div>
          </form>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-primary" id="saveChangesBtn">Save Category</button>
        </div>
      </div>
    </div>
  </div>

  <?php include __DIR__ . '/partials/admin_confirm_modal.php'; ?>
<?php
$extraFoot = ob_get_clean();

include __DIR__ . '/partials/admin_layout_start.php';
?>
        <div class="container-fluid px-4">
          <?php
          $pageHeading = 'Categories';
          $breadcrumbItems = [
              ['label' => 'File Maintenance', 'url' => 'events.php'],
              ['label' => 'Categories'],
          ];
          include __DIR__ . '/partials/admin_page_header.php';
          ?>

          <div class="mb-3">
            <button class="btn btn-primary" type="button" id="addRowBtn"<?= $activeEventId > 0 ? '' : ' disabled title="No active event"'; ?>>
              <i class="bi bi-plus-lg"></i> Add Category
            </button>
          </div>

          <div class="card shadow-sm border-0 admin-table-card mb-4">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center py-3">
              <span class="fw-semibold mb-0"><i class="fas fa-table me-1"></i>Category Table</span>
            </div>
            <div class="card-body">
              <div class="table-responsive w-100">
                <table id="categoriesTable" class="table table-striped table-bordered admin-data-table w-100">
                  <thead class="table-light">
                    <tr>
                      <th>Category</th>
                      <th>Voter form</th>
                      <th>Status</th>
                      <th>Actions</th>
                    </tr>
                  </thead>
                  <tbody id="tableBody"></tbody>
                </table>
              </div>
              <div class="row mt-3 g-2 align-items-center">
                <div class="col-12 col-md-6 text-center text-md-start">
                  <small id="paginationInfo" class="text-muted d-block"></small>
                </div>
                <div class="col-12 col-md-6">
                  <nav aria-label="Table Pagination">
                    <ul class="pagination justify-content-center justify-content-md-end flex-wrap mb-0" id="paginationContainer"></ul>
                  </nav>
                </div>
              </div>
            </div>
          </div>
        </div>
<?php include __DIR__ . '/partials/admin_layout_end.php'; ?>
