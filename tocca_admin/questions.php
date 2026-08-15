<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/admin_init.php';
admin_apply_nav_from_script(basename(__FILE__));

$pageTitle = 'Name of Awards';
$useDataTables = true;
$pageScripts = ['js/admin_confirm.js', 'questions_test.js'];
$extraHead = '';

ob_start();
?>
  <div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title mb-0" id="editModalLabel">Add an Award</h5>
        </div>
        <div class="modal-body">
          <form id="editForm" novalidate>
            <div class="mb-3">
              <label for="editCategory" class="form-label">Category <span class="text-danger">*</span></label>
              <select class="form-select" id="editCategory" required aria-describedby="editCategoryFeedback">
                <option value="">-- Select Category --</option>
              </select>
              <div class="invalid-feedback" id="editCategoryFeedback">Please select a category.</div>
            </div>
            <div class="mb-3">
              <label for="editName" class="form-label">Award <span class="text-danger">*</span></label>
              <input type="text" class="form-control" id="editName" required aria-describedby="editNameFeedback" autocomplete="off">
              <div class="invalid-feedback" id="editNameFeedback">Please enter an award name.</div>
            </div>
            <p class="form-text mb-0">
              Voters choose from linked businesses on the voting page.
            </p>
          </form>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-primary" id="saveChangesBtn">Save Award</button>
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
          $pageHeading = 'Name of Awards';
          $breadcrumbItems = [
              ['label' => 'File Maintenance', 'url' => 'events.php'],
              ['label' => 'Name of Awards'],
          ];
          include __DIR__ . '/partials/admin_page_header.php';
          ?>

          <div class="row g-2 align-items-center mb-3">
            <div class="col-md-auto">
              <button class="btn btn-primary" type="button" id="addRowBtn">
                <i class="bi bi-plus-lg"></i> Add Award
              </button>
            </div>
            <div class="col-md-auto ms-md-auto">
              <div class="d-flex flex-nowrap align-items-center gap-2 questions-toolbar-filter">
                <label for="categoryDropdown" class="form-label small text-muted mb-0">Filter by category</label>
                <select class="form-select w-auto" id="categoryDropdown" style="min-width: 11rem;">
                  <option value="all">All categories</option>
                </select>
              </div>
            </div>
          </div>

          <div class="card shadow-sm border-0 admin-table-card mb-4">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center py-3">
              <span class="fw-semibold mb-0"><i class="fas fa-table me-1"></i>Name of Awards Table</span>
            </div>
            <div class="card-body">
              <div class="table-responsive w-100">
                <table id="questionsTable" class="table table-striped table-bordered admin-data-table w-100">
                  <thead class="table-light">
                    <tr>
                      <th>Award</th>
                      <th>Category</th>
                      <th>Actions</th>
                    </tr>
                  </thead>
                  <tbody id="tableBody"></tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
<?php include __DIR__ . '/partials/admin_layout_end.php'; ?>
