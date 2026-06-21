<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/admin_init.php';
require_once __DIR__ . '/includes/nominations_list.php';
admin_apply_nav_from_script('nominations.php');

date_default_timezone_set('Asia/Manila');
$tz  = new DateTimeZone('Asia/Manila');
$now = new DateTime('now', $tz);

$pageTitle = 'Nominations';
$pageScripts = ['nominations.js'];
$extraHead = <<<'HTML'
<style>
  .table-sm td, .table-sm th { padding: .5rem .75rem; }
</style>
HTML;

$allowedStatuses = ['pending', 'in_review', 'needs_info', 'approved', 'rejected', 'merged', 'all'];
$ssrStatus = isset($_GET['status']) ? strtolower(trim((string) $_GET['status'])) : 'all';
if (!in_array($ssrStatus, $allowedStatuses, true)) {
    $ssrStatus = 'all';
}
$ssrPage = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = 10;

$events = ($conn instanceof mysqli) ? nominations_load_unarchived_events($conn, $tz, $now) : [];

$event_id = ($conn instanceof mysqli) ? admin_active_event_id($conn) : null;

$selectedVotingStartIso = '';
$selectedVotingEndIso = '';
$selectedVotingStartLabel = '';
$selectedVotingEndLabel = '';
$votingLocked = false;

foreach ($events as $row) {
    if ($event_id && $event_id === (int) ($row['event_id'] ?? 0)) {
        $selectedVotingStartIso   = (string) ($row['voting_start_iso'] ?? '');
        $selectedVotingEndIso     = (string) ($row['voting_end_iso'] ?? '');
        $selectedVotingStartLabel = (string) ($row['voting_start_label'] ?? '');
        $selectedVotingEndLabel   = (string) ($row['voting_end_label'] ?? '');
        $votingLocked             = !empty($row['voting_locked']);
        break;
    }
}

$votingLockMessage = 'Voting period has started for this event. Nomination management is temporarily disabled.';
if ($selectedVotingStartLabel !== '') {
    $votingLockMessage .= ' Voting began on ' . $selectedVotingStartLabel . '.';
}
if ($selectedVotingEndLabel !== '') {
    $votingLockMessage .= ' Voting ends on ' . $selectedVotingEndLabel . '.';
}

$listResult = ['rows' => [], 'total' => 0, 'page' => $ssrPage, 'page_size' => $pageSize];
if ($conn instanceof mysqli && $event_id) {
    $listResult = nominations_fetch_list($conn, $event_id, $ssrStatus, $ssrPage, $pageSize);
}

$ssrMeta = [
    'total'     => (int) ($listResult['total'] ?? 0),
    'page'      => (int) ($listResult['page'] ?? $ssrPage),
    'page_size' => (int) ($listResult['page_size'] ?? $pageSize),
    'event_id'  => $event_id ? (string) $event_id : '',
    'status'    => $ssrStatus,
];

ob_start();
?>
  <div class="modal fade" id="actionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <form class="modal-content" id="actionForm">
        <div class="modal-header">
          <h5 class="modal-title" id="actionTitle">Action</h5>
          <button class="btn-close" data-bs-dismiss="modal" type="button"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" id="modalNominationId">
          <div class="mb-3" id="mergeTargetWrap" style="display:none;">
            <label class="form-label">Merge into existing Choice ID (optional)</label>
            <input type="number" class="form-control" id="mergeChoiceId" placeholder="Enter existing choice_id">
            <div class="form-text">Leave blank to create a new Choice automatically.</div>
          </div>
          <div class="mb-3" id="adminNoteWrap" style="display:none;">
            <label class="form-label">Admin note</label>
            <textarea class="form-control" id="adminNote" rows="3" placeholder="Explain reason or next steps"></textarea>
          </div>
          <div id="previewInfo" class="small text-muted"></div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button>
          <button class="btn btn-primary" id="actionSubmitBtn" type="submit">Submit</button>
        </div>
      </form>
    </div>
  </div>

  <div class="position-fixed bottom-0 start-50 translate-middle-x p-3" style="z-index:1100">
    <div id="toastMsg" class="toast align-items-center text-bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
      <div class="d-flex">
        <div class="toast-body" id="toastBody">Saved</div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
      </div>
    </div>
  </div>
<?php
$extraFoot = ob_get_clean();

include __DIR__ . '/partials/admin_layout_start.php';
?>
        <div class="container-fluid px-4">
          <?php
          $pageHeading = 'Nominations';
          $breadcrumbItems = [
              ['label' => 'Nominations'],
          ];
          include __DIR__ . '/partials/admin_page_header.php';
          ?>

          <div class="card mb-4" id="nominationModuleCard"
               data-voting-locked="<?= $votingLocked ? '1' : '0'; ?>"
               data-vote-start="<?= h($selectedVotingStartIso); ?>"
               data-vote-end="<?= h($selectedVotingEndIso); ?>"
               data-vote-start-label="<?= h($selectedVotingStartLabel); ?>"
               data-vote-end-label="<?= h($selectedVotingEndLabel); ?>">
            <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
              <span class="fw-bold"><i class="bi bi-person-check me-1"></i>Manage Nominations</span>
              <div class="d-flex flex-wrap gap-2 align-items-center">
                <select id="statusFilter" class="form-select form-select-sm">
                  <option value="pending" <?= $ssrStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
                  <option value="in_review" <?= $ssrStatus === 'in_review' ? 'selected' : '' ?>>In Review</option>
                  <option value="needs_info" <?= $ssrStatus === 'needs_info' ? 'selected' : '' ?>>Needs Info</option>
                  <option value="approved" <?= $ssrStatus === 'approved' ? 'selected' : '' ?>>Approved</option>
                  <option value="rejected" <?= $ssrStatus === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                  <option value="merged" <?= $ssrStatus === 'merged' ? 'selected' : '' ?>>Merged</option>
                  <option value="all" <?= $ssrStatus === 'all' ? 'selected' : '' ?>>All</option>
                </select>
              </div>
            </div>
            <div class="card-body">
              <div class="alert alert-warning mb-3 <?= $votingLocked ? '' : 'd-none'; ?>" id="votingLockNotice" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2" aria-hidden="true"></i>
                <span id="votingLockMessage"><?= h($votingLockMessage); ?></span>
              </div>
              <script type="application/json" id="nomSsrMeta"><?= json_encode($ssrMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
              <div class="table-responsive">
                <table class="table table-hover table-sm align-middle mb-0" id="nomTable">
                  <thead class="table-dark">
                    <tr>
                      <th>Business</th>
                      <th>Contact</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody
                    id="nomTableBody"
                    data-ssr="1"
                    data-event-id="<?= h((string) ($event_id ?? '')); ?>"
                    data-status="<?= h($ssrStatus); ?>"
                    data-page="<?= (int) $ssrPage; ?>"
                  >
                    <?php
                    if (!$event_id) {
                        echo '<tr><td colspan="3" class="text-center text-muted py-4">No unarchived event found. Please restore or create an event.</td></tr>';
                    } else {
                        nominations_render_table_rows($listResult['rows']);
                    }
                    ?>
                  </tbody>
                </table>
              </div>

              <div class="row mt-3 g-2 align-items-center">
                <div class="col-12 col-md-6 text-center text-md-start">
                  <small id="paginationInfo" class="text-muted d-block"><?= h(nominations_pagination_label((int) $listResult['total'], (int) $listResult['page'], (int) $listResult['page_size'])) ?></small>
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
