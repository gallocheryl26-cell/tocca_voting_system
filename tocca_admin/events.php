<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/admin_init.php';
require_once __DIR__ . '/includes/events_table.php';
admin_apply_nav_from_script('events.php');

$eventsList = ($conn instanceof mysqli) ? events_fetch_all($conn) : [];

$pageTitle = 'Events';
$pageScripts = ['js/admin_confirm.js', 'events.js'];
$extraHead = <<<'HTML'
<style>
  #eventsTable th.actions-col,
  #eventsTable td.actions {
    width: 15.5rem;
    min-width: 15.5rem;
    max-width: 15.5rem;
    vertical-align: middle;
  }
  #eventsTable td.actions .event-table-actions {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.35rem;
    width: 100%;
    flex-wrap: unset;
  }
  #eventsTable td.actions .event-table-actions .btn {
    width: 100%;
    margin: 0;
    padding-left: 0.4rem;
    padding-right: 0.4rem;
    text-align: center;
    flex: unset;
  }
  /* Active events (3 buttons): Archive on its own full-width row */
  #eventsTable td.actions .event-table-actions > .archive-event-btn:last-child:nth-child(3) {
    grid-column: 1 / -1;
  }
  .hint-text { font-size: .8rem; opacity: .8; margin-top: .25rem; }
  .row.gy-3 .form-label { margin-bottom: .25rem; }
  .event-schedule { font-size: 0.80rem; line-height: 1.15; }
</style>
HTML;

ob_start();
?>
  <!-- Add Event Modal -->
  <div class="modal fade" id="addEventModal" tabindex="-1" aria-labelledby="addEventModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="addEventModalLabel">Add Event</h5>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label" for="event_name">Event Name</label>
            <input type="text" class="form-control" id="event_name" autocomplete="off" required>
            <div class="hint-text">This event includes the registration and voting process.</div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="event_description">Description <span class="text-muted fw-normal">(optional)</span></label>
            <textarea class="form-control" id="event_description" rows="3" placeholder="Brief description of the event"></textarea>
          </div>
          <hr class="modal-form-divider">
          <p class="modal-section-title"><i class="bi bi-calendar2-range" aria-hidden="true"></i> Schedules</p>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="nom_start">Registration Start</label>
              <input type="datetime-local" class="form-control" id="nom_start" step="60">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="nom_end">Registration End</label>
              <input type="datetime-local" class="form-control" id="nom_end" step="60">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="vote_start">Voting Start</label>
              <input type="datetime-local" class="form-control" id="vote_start" step="60">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="vote_end">Voting End</label>
              <input type="datetime-local" class="form-control" id="vote_end" step="60">
            </div>
          </div>
          <div class="form-check form-switch mt-3">
            <input class="form-check-input" type="checkbox" id="event_active" checked>
            <label class="form-check-label" for="event_active">Active</label>
          </div>
        </div>
        <div class="modal-footer">
          <div class="modal-footer-actions">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary" id="createEventBtn">Create Event</button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Edit Event Modal -->
  <div class="modal fade" id="editEventModal" tabindex="-1" aria-labelledby="editEventModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="editEventModalLabel">Edit Event</h5>
        </div>
        <div class="modal-body">
          <input type="hidden" id="edit_event_id">
          <div class="mb-3">
            <label class="form-label" for="edit_event_name">Event Name</label>
            <input type="text" class="form-control" id="edit_event_name" autocomplete="off" required>
            <div class="hint-text">This event includes the registration and voting process.</div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="edit_event_description">Description</label>
            <textarea class="form-control" id="edit_event_description" rows="3" placeholder="Brief description of the event"></textarea>
          </div>
          <hr class="modal-form-divider">
          <p class="modal-section-title"><i class="bi bi-calendar2-range" aria-hidden="true"></i> Schedules</p>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="edit_nom_start">Registration Start</label>
              <input type="datetime-local" class="form-control" id="edit_nom_start" step="60">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="edit_nom_end">Registration End</label>
              <input type="datetime-local" class="form-control" id="edit_nom_end" step="60">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="edit_vote_start">Voting Start</label>
              <input type="datetime-local" class="form-control" id="edit_vote_start" step="60">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="edit_vote_end">Voting End</label>
              <input type="datetime-local" class="form-control" id="edit_vote_end" step="60">
            </div>
          </div>
          <div class="form-check form-switch mt-3">
            <input class="form-check-input" type="checkbox" id="edit_event_active">
            <label class="form-check-label" for="edit_event_active">Active</label>
          </div>
        </div>
        <div class="modal-footer">
          <div class="modal-footer-actions">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-success" id="saveEditEventBtn">Save Changes</button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Registration QR Modal -->
  <div class="modal fade" id="nominationQrModal" tabindex="-1" aria-labelledby="nominationQrTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="nominationQrTitle">Public links</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="row g-4 align-items-stretch">
            <div class="col-lg-5">
              <p class="text-muted mb-3">Share these short links on social media. The QR opens registration.</p>
              <label class="form-label" for="nominationQrLink">Registration</label>
              <div class="input-group input-group-sm mb-3">
                <a id="nominationQrLink" href="#" target="_blank" rel="noopener noreferrer" class="form-control text-break border">&nbsp;</a>
                <button type="button" class="btn btn-outline-secondary" id="copyNominationLinkBtn" title="Copy registration link">
                  <i class="bi bi-clipboard" aria-hidden="true"></i>
                </button>
              </div>
              <label class="form-label" for="eventVoteLink">Voting page</label>
              <div class="input-group input-group-sm mb-3">
                <a id="eventVoteLink" href="#" target="_blank" rel="noopener noreferrer" class="form-control text-break border">&nbsp;</a>
              </div>
              <label class="form-label" for="eventTrackLink">Tracking</label>
              <div class="input-group input-group-sm mb-3">
                <a id="eventTrackLink" href="#" target="_blank" rel="noopener noreferrer" class="form-control text-break border">&nbsp;</a>
              </div>
              <div class="d-flex gap-2 flex-wrap">
                <a class="btn btn-outline-primary btn-sm" id="downloadNominationQrBtn" href="#" download>
                  <i class="bi bi-download" aria-hidden="true"></i> Download PNG
                </a>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Done</button>
              </div>
            </div>
            <div class="col-lg-7">
              <div class="qr-preview-panel text-center p-3 rounded border h-100 d-flex align-items-center justify-content-center" style="background:#eef3fb;">
                <img id="nominationQrImage" src="" alt="Registration QR" class="img-fluid rounded shadow-sm" style="max-height:420px;">
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer justify-content-between align-items-center flex-wrap gap-2">
          <div class="small text-muted">
            <span class="d-block">Edit site root &amp; copy all public links:
              <a href="public_url_config.php">Customizations → Public Share Links</a>.</span>
            <span class="d-block">QR refreshes automatically to avoid caching issues.</span>
            <span class="d-block">Center logo and colors: <a href="admin_settings.php#qr-frame-settings">Admin Settings → QR Codes</a>.</span>
          </div>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Delete Confirm Modal -->
  <div class="modal fade" id="deleteEventModal" tabindex="-1" aria-labelledby="deleteEventModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="deleteEventModalLabel">Delete Event</h5>
        </div>
        <div class="modal-body">
          <p class="mb-0">Are you sure you want to delete this event? This cannot be undone.</p>
        </div>
        <div class="modal-footer">
          <div class="modal-footer-actions">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-danger" id="confirmDeleteEventBtn">Delete</button>
          </div>
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
          $pageHeading = 'Events';
          $breadcrumbItems = [
              ['label' => 'File Maintenance', 'url' => 'events.php'],
              ['label' => 'Events'],
          ];
          // Event table already lists active status, schedules, and phase — skip duplicate banner.
          $showAdminEventContext = false;
          include __DIR__ . '/partials/admin_page_header.php';
          ?>

          <div class="mb-3 d-flex flex-wrap align-items-center gap-2">
            <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#addEventModal">
              <i class="bi bi-plus-lg me-1"></i>Add Event
            </button>
            <a class="btn btn-outline-secondary btn-sm" href="archives.php">
              <i class="bi bi-archive me-1"></i>View archived events
            </a>
          </div>

          <div class="card shadow-sm border-0 admin-table-card mb-4">
            <div class="card-header bg-transparent d-flex align-items-center gap-2 py-3">
              <i class="bi bi-table"></i>
              <span class="fw-semibold mb-0">Event Table</span>
            </div>
            <div class="card-body">
              <div class="table-responsive">
                <table class="table table-striped table-bordered admin-data-table align-middle mb-0 w-100" id="eventsTable">
                  <thead class="table-light">
                    <tr>
                      <th>Event</th>
                      <th>Description</th>
                      <th style="width:120px">Status</th>
                      <th class="actions-col">Actions</th>
                    </tr>
                  </thead>
                  <tbody id="eventsTableBody" data-ssr="1">
                    <?php events_render_table_rows($eventsList); ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
<?php include __DIR__ . '/partials/admin_layout_end.php'; ?>
