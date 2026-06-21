<?php
declare(strict_types=1);
/**
 * Reusable confirmation dialog (replaces window.confirm).
 * Matches standard admin modals: white panel, gray Cancel, colored confirm action.
 */
?>
<div class="modal fade" id="adminConfirmModal" tabindex="-1" aria-labelledby="adminConfirmModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header d-none" id="adminConfirmModalHeader">
        <h5 class="modal-title" id="adminConfirmModalTitle">Please confirm</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="mb-0" id="adminConfirmModalMessage">Are you sure?</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="adminConfirmModalCancel">Cancel</button>
        <button type="button" class="btn btn-primary" id="adminConfirmModalOk">Yes, continue</button>
      </div>
    </div>
  </div>
</div>
