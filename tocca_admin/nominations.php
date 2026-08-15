<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/admin_init.php';
require_once __DIR__ . '/includes/nominations_list.php';
admin_apply_nav_from_script('nominations.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_status_filter'])) {
    $posted = $_POST['status_filter_keys'] ?? [];
    if (!is_array($posted)) {
        $posted = [];
    }
    $ok = ($conn instanceof mysqli) && nominations_save_filter_status_keys($conn, $posted);
    $_SESSION['flash_toast'] = [
        'message' => $ok ? 'Status filter options saved.' : 'Could not save status filter options.',
        'type'    => $ok ? 'success' : 'danger',
    ];
    session_write_close();
    header('Location: nominations.php');
    exit;
}

date_default_timezone_set('Asia/Manila');
$tz  = new DateTimeZone('Asia/Manila');
$now = new DateTime('now', $tz);

$pageTitle = 'Submissions';
$pageScripts = ['nominations.js'];
$extraHead = <<<'HTML'
<style>
  .nom-search-wrap { position: relative; }
  .nom-search-wrap {
    width: 13rem;
    min-width: 13rem;
    flex: 0 0 13rem;
  }
  .nom-status-filter {
    width: 16rem;
    min-width: 16rem;
    flex: 0 0 16rem;
    display: flex;
    align-items: center;
    gap: .35rem;
  }
  .nom-status-filter #statusFilter { flex: 1 1 auto; min-width: 0; width: auto; }
  .nom-search-wrap .form-control { width: 100%; padding-right: 2rem; }
  .nom-search-wrap input[type="search"]::-webkit-search-cancel-button,
  .nom-search-wrap input[type="search"]::-webkit-search-decoration {
    -webkit-appearance: none;
    appearance: none;
  }
  .nom-search-clear {
    position: absolute; right: .45rem; top: 50%; transform: translateY(-50%);
    border: 0; background: transparent; color: #6c757d; padding: 0; line-height: 1;
  }
  .nom-suggest {
    position: absolute; left: 0; right: 0; top: calc(100% + .25rem); z-index: 20;
    max-height: 14rem; overflow: auto; margin: 0; padding: .25rem 0;
    list-style: none; background: var(--bs-body-bg, #fff);
    border: 1px solid var(--bs-border-color, #dee2e6); border-radius: .5rem;
    box-shadow: 0 8px 24px rgba(15, 23, 42, .12);
  }
  .nom-suggest button {
    display: block; width: 100%; text-align: left; border: 0; background: transparent;
    padding: .45rem .75rem; font-size: .875rem; color: inherit;
  }
  .nom-suggest button:hover, .nom-suggest button.is-active { background: rgba(var(--bs-primary-rgb), .08); }
</style>
HTML;

$filterStatusKeys = nominations_filter_status_keys($conn instanceof mysqli ? $conn : null);
$allowedStatuses = $filterStatusKeys;
$ssrStatus = isset($_GET['status']) ? strtolower(trim((string) $_GET['status'])) : 'all';
if (!in_array($ssrStatus, $allowedStatuses, true)) {
    $ssrStatus = 'all';
}
$ssrQ = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
if (function_exists('mb_substr')) {
    $ssrQ = mb_substr($ssrQ, 0, 80);
} else {
    $ssrQ = substr($ssrQ, 0, 80);
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

$votingLockMessage = 'Voting period has started for this event. Registration management is temporarily disabled.';
if ($selectedVotingStartLabel !== '') {
    $votingLockMessage .= ' Voting began on ' . $selectedVotingStartLabel . '.';
}
if ($selectedVotingEndLabel !== '') {
    $votingLockMessage .= ' Voting ends on ' . $selectedVotingEndLabel . '.';
}

$listResult = ['rows' => [], 'total' => 0, 'page' => $ssrPage, 'page_size' => $pageSize];
if ($conn instanceof mysqli && $event_id) {
    $listResult = nominations_fetch_list($conn, $event_id, $ssrStatus, $ssrPage, $pageSize, $ssrQ);
}

$ssrMeta = [
    'total'     => (int) ($listResult['total'] ?? 0),
    'page'      => (int) ($listResult['page'] ?? $ssrPage),
    'page_size' => (int) ($listResult['page_size'] ?? $pageSize),
    'event_id'  => $event_id ? (string) $event_id : '',
    'status'    => $ssrStatus,
    'q'         => $ssrQ,
];

ob_start();
?>
  <div class="modal fade" id="statusFilterModal" tabindex="-1" aria-labelledby="statusFilterModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <form class="modal-content" method="post">
        <input type="hidden" name="save_status_filter" value="1">
        <div class="modal-header">
          <h5 class="modal-title" id="statusFilterModalLabel">Status filter options</h5>
        </div>
        <div class="modal-body">
          <p class="small text-muted mb-3">Choose which statuses appear in the Submissions dropdown. These are system review statuses — you can hide unused ones, but you cannot add new status types here.</p>
          <?php
          $statusCatalog = nominations_status_catalog();
          foreach ($statusCatalog as $statusKey => $statusLabel):
              if ($statusKey === 'all') {
                  continue;
              }
              $checked = in_array($statusKey, $filterStatusKeys, true);
          ?>
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" name="status_filter_keys[]" value="<?= h($statusKey) ?>" id="statusFilterOpt_<?= h($statusKey) ?>" <?= $checked ? 'checked' : '' ?>>
            <label class="form-check-label" for="statusFilterOpt_<?= h($statusKey) ?>"><?= h($statusLabel) ?></label>
          </div>
          <?php endforeach; ?>
          <div class="form-check mb-0">
            <input class="form-check-input" type="checkbox" checked disabled>
            <label class="form-check-label text-muted">All <span class="small">(always shown)</span></label>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button>
          <button class="btn btn-primary" type="submit">Save options</button>
        </div>
      </form>
    </div>
  </div>

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
          $pageHeading = 'Submissions';
          $breadcrumbItems = [
              ['label' => 'Registration', 'url' => 'nominations.php'],
              ['label' => 'Submissions'],
          ];
          include __DIR__ . '/partials/admin_page_header.php';
          ?>

          <div class="card shadow-sm border-0 admin-table-card mb-4" id="nominationModuleCard"
               data-voting-locked="<?= $votingLocked ? '1' : '0'; ?>"
               data-vote-start="<?= h($selectedVotingStartIso); ?>"
               data-vote-end="<?= h($selectedVotingEndIso); ?>"
               data-vote-start-label="<?= h($selectedVotingStartLabel); ?>"
               data-vote-end-label="<?= h($selectedVotingEndLabel); ?>">
            <div class="card-header bg-transparent d-flex flex-wrap gap-2 align-items-center justify-content-between py-3">
              <span class="fw-semibold mb-0"><i class="bi bi-person-check me-1"></i>Submissions</span>
              <div class="d-flex flex-wrap gap-2 align-items-center">
                <div class="nom-search-wrap">
                  <input type="search" id="nomSearch" class="form-control form-control-sm" placeholder="Search business…" value="<?= h($ssrQ) ?>" autocomplete="off" aria-label="Search business" aria-autocomplete="list" aria-controls="nomSuggest">
                  <button type="button" class="nom-search-clear<?= $ssrQ === '' ? ' d-none' : '' ?>" id="nomSearchClear" aria-label="Clear search"><i class="bi bi-x-lg"></i></button>
                  <ul class="nom-suggest d-none" id="nomSuggest" role="listbox"></ul>
                </div>
                <div class="nom-status-filter">
                  <select id="statusFilter" class="form-select form-select-sm" aria-label="Filter by status">
                    <?php
                    $statusCatalog = nominations_status_catalog();
                    foreach ($filterStatusKeys as $statusKey):
                        if (!isset($statusCatalog[$statusKey])) {
                            continue;
                        }
                    ?>
                    <option value="<?= h($statusKey) ?>" <?= $ssrStatus === $statusKey ? 'selected' : '' ?>><?= h($statusCatalog[$statusKey]) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#statusFilterModal" title="Edit status options" aria-label="Edit status options">
                    <i class="bi bi-gear"></i>
                  </button>
                </div>
              </div>
            </div>
            <div class="card-body">
              <div class="alert alert-warning mb-3 <?= $votingLocked ? '' : 'd-none'; ?>" id="votingLockNotice" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2" aria-hidden="true"></i>
                <span id="votingLockMessage"><?= h($votingLockMessage); ?></span>
              </div>
              <script type="application/json" id="nomSsrMeta"><?= json_encode($ssrMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
              <div class="table-responsive">
                <table class="table table-striped table-bordered admin-data-table align-middle mb-0 w-100" id="nomTable">
                  <thead class="table-light">
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
                    data-q="<?= h($ssrQ); ?>"
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
