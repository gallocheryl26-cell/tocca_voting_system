<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/admin_init.php';
admin_apply_nav_from_script(basename(__FILE__));

// award_validation_log.php — Awards Validation Log (no AJAX, server-rendered)
include 'get_logo.php';
require_once 'breadcrumb.php';
date_default_timezone_set('Asia/Manila');

// ---------- Defaults & filters (with unarchived-only logic) ----------
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

// Check if a column exists (so we can support either is_archived or archived_at schemas)
function has_col(mysqli $conn, string $table, string $col): bool {
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS 
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param('ss', $table, $col);
  $stmt->execute();
  $stmt->store_result();
  $ok = $stmt->num_rows > 0;
  $stmt->close();
  return $ok;
}

$hasIsArchived = has_col($conn, 'tbl_events', 'is_archived');
$hasArchivedAt = has_col($conn, 'tbl_events', 'archived_at');
$hasIsActive   = has_col($conn, 'tbl_events', 'is_active');

// Unified condition for “unarchived”
if ($hasIsArchived) {
  $unarchivedWhere = 'e.is_archived = 0';
} elseif ($hasArchivedAt) {
  $unarchivedWhere = 'e.archived_at IS NULL';
} else {
  // Fallback: treat “active” as “unarchived” if no archive column
  $unarchivedWhere = 'e.is_active = 1';
}

/* --------- NEW: build business name expression that works on both schemas --------- */
$hasBizCol = has_col($conn, 'tbl_nominations', 'business_name');
$bizExpr = $hasBizCol
  ? "COALESCE(n.business_name, '')"
  : "(SELECT a2.answer
       FROM tbl_nomination_answers a2
       JOIN tbl_nomination_fields  f2 ON f2.id = a2.field_id
      WHERE a2.nomination_id = n.nomination_id
        AND f2.name IN ('business_name','official_business_name','company','company_name','business')
      ORDER BY a2.id ASC
      LIMIT 1)";

/* ------------------------------------------------------------------------------- */

// Active event only (no event filter in UI)
$selected_event_id = admin_active_event_id($conn);

// Read GET filters
$from  = isset($_GET['from']) && $_GET['from'] !== '' ? $_GET['from'] : '';
$to    = isset($_GET['to'])   && $_GET['to']   !== '' ? $_GET['to']   : '';
$q     = isset($_GET['q'])    ? trim($_GET['q']) : '';
$limit = isset($_GET['limit']) && (int)$_GET['limit'] > 0 ? min((int)$_GET['limit'], 2000) : 200;

// ---------- Query data (only from unarchived events) ----------
$sql = "
  SELECT
    a.audit_id,
    a.changed_at,
    a.action,
    a.nomination_id,
    {$bizExpr} AS business_name,
    COALESCE(c.category_name, 'Uncategorized') AS category_name,
    COALESCE(qs.question_name, 'Unnamed Award') AS question_name
  FROM tbl_nomination_question_audit a
  JOIN tbl_nominations n  ON n.nomination_id = a.nomination_id
  JOIN tbl_events e       ON e.event_id      = n.event_id
  JOIN tbl_questions qs   ON qs.question_id  = a.question_id
  LEFT JOIN tbl_categories c ON c.category_id = qs.category_id
  WHERE $unarchivedWhere
";

$params = [];
$types  = '';

if (!empty($selected_event_id)) {
  $sql .= " AND n.event_id = ?";
  $types .= 'i';
  $params[] = $selected_event_id;
}
if ($from) {
  $sql .= " AND a.changed_at >= ?";
  $types .= 's';
  $params[] = $from . " 00:00:00";
}
if ($to) {
  $sql .= " AND a.changed_at < DATE_ADD(?, INTERVAL 1 DAY)";
  $types .= 's';
  $params[] = $to . " 00:00:00";
}
if ($q !== '') {
  $sql .= " AND (
              {$bizExpr} LIKE ?
              OR qs.question_name LIKE ?
              OR c.category_name LIKE ?
              OR CAST(a.nomination_id AS CHAR) LIKE ?
            )";
  $types .= 'ssss';
  $like = '%'.$q.'%';
  $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}

$sql .= " ORDER BY a.changed_at DESC LIMIT ?";
$types .= 'i';
$params[] = $limit;

$stmt = $conn->prepare($sql);
if ($types !== '') { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
while ($r = $res->fetch_assoc()) { $rows[] = $r; }
$totalRows = count($rows);
?>
<!DOCTYPE html>
<html lang="en">
  <head>
  <script src="js/instant_theme_init.js"></script>
  <meta charset="utf-8" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <meta name="description" content="Awards Validation Log" />
  <title>Awards Validation Log | Tatak Ormoc</title>
  <link rel="icon" type="image/png" href="<?php echo $faviconPath; ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
  <link href="css/styles.css" rel="stylesheet" />
  <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous" defer></script>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <?php include 'inline_style.php'; ?>
  <style>
    :root{ --table-col-max: 420px; }
    html, body, #layoutSidenav_content { overflow-x: hidden; }
    .card .table-responsive { overflow-x: hidden; }
    #tblValidation { table-layout: fixed; width:100%; }
    #tblValidation th, #tblValidation td { white-space: normal; overflow-wrap:anywhere; word-break:break-word; }
  </style>
  <style>
    .notif-badge { min-width: 90px; display: inline-block; text-align: center; }
    .notif-text  { flex: 1; }
  </style>
</head>
<body class="sb-nav-fixed">
<?php include __DIR__ . '/partials/admin_topnav.php'; ?>

<div id="layoutSidenav">
    <!-- Sidebar -->
    <?php include __DIR__ . '/partials/admin_sidebar.php'; ?>

<div id="layoutSidenav_content">
      <main>
        <div class="container-fluid px-4">
                    <div class="admin-page-header mt-4 mb-4">
            <div class="min-w-0">
              <h1 class="admin-page-title mb-2">Awards Validation Log</h1>
              <?php echo render_transactions_breadcrumb([['label' => 'Awards Validation']]); ?>
            </div>
          </div>
          <?php echo render_admin_event_context(); ?>

          <!-- Filters -->
          <div class="card shadow-sm mb-3">
            <div class="card-body">
              <form id="frmFilters" method="get" class="row g-2 align-items-end admin-filter-bar">
                <div class="col-6 col-md-2">
                  <label class="form-label small text-muted mb-1">From</label>
                  <input id="from" name="from" type="date" value="<?= h($from) ?>" class="form-control form-control-sm">
                </div>
                <div class="col-6 col-md-2">
                  <label class="form-label small text-muted mb-1">To</label>
                  <input id="to" name="to" type="date" value="<?= h($to) ?>" class="form-control form-control-sm">
                </div>
                <div class="col-12 col-md-3">
                  <label class="form-label small text-muted mb-1">Search (nominee/category/award)</label>
                  <input id="q" name="q" type="text" value="<?= h($q) ?>" class="form-control form-control-sm" placeholder="e.g. 'Cafe', 'Hospitality'">
                </div>
                <div class="col-6 col-md-1">
                  <label class="form-label small text-muted mb-1">Limit</label>
                  <input id="limit" name="limit" type="number" value="<?= (int)$limit ?>" min="10" step="10" class="form-control form-control-sm">
                </div>
                <div class="col-12 col-md-auto d-flex flex-wrap gap-2 align-items-end">
                  <button id="btnFilter" class="btn btn-primary btn-sm" type="submit">
                    <i class="bi bi-funnel me-1"></i>Filter
                  </button>
                </div>
              </form>
            </div>
          </div>

          <!-- Table (Date, Nominee, Category, Award, Action) -->
          <div class="card shadow-sm border-0 admin-table-card mb-4">
            <div class="card-header bg-transparent d-flex flex-wrap gap-2 align-items-center justify-content-between py-3">
              <span class="fw-semibold mb-0"><i class="bi bi-shield-check me-1"></i>Log</span>
              <div class="d-flex align-items-center gap-3 small">
                <span class="text-success">Total: <strong id="valTotal"><?= (int)$totalRows ?></strong></span>
              </div>
            </div>
            <div class="card-body">
              <div class="table-responsive">
                <table class="table table-striped table-bordered admin-data-table align-middle mb-0 w-100" id="tblValidation" style="width:100%">
                  <thead class="table-light">
                    <tr>
                      <th>Date</th>
                      <th>Nominee</th>
                      <th>Category</th>
                      <th>Award</th>
                      <th>Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($rows as $row): ?>
                      <?php
                        $date = date('m-d-y', strtotime($row['changed_at'])); // show date only
                        $nom  = $row['business_name'];
                        $cat  = $row['category_name'];
                        $awd  = $row['question_name'];
                        $act  = $row['action'];
                      ?>
                      <tr>
                        <td><?= h($date) ?></td>
                        <td><?= h($nom) ?></td>
                        <td><?= h($cat) ?></td>
                        <td><?= h($awd) ?></td>
                        <td><span class="badge text-bg-danger"><?= h($act) ?></span></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

        </div>
      </main>
      <footer class="py-4 bg-light mt-auto">
        <div class="container-fluid px-4">
          <div class="small text-muted">&copy; 2026 Tatak Ormoc Consumers&rsquo; Choice Awards</div>
        </div>
      </footer>
</div>
  </div>

  <!-- JS deps (no AJAX; DataTables used only for nice sorting/paging on rendered rows) -->
  <?php include __DIR__ . '/partials/admin_datatables_scripts.php'; ?>
  <script>
    (function(){
      const table = $('#tblValidation').DataTable({
        searching: false,   // we already filter via the form GET
        pageLength: 25,
        order: [[0,'desc']]
      });
    })();
  </script>

  <?php include __DIR__ . '/partials/admin_legacy_footer.php'; ?>
</body>
</html>
