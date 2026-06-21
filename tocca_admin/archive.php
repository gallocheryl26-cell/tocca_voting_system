<?php
/**
 * tocca_admin/archive.php
 * JSON API (POST) + HTML details renderer (GET?action=details)
 */
declare(strict_types=1);

$isArchiveDetailsGet = $_SERVER['REQUEST_METHOD'] === 'GET'
    && isset($_GET['action'])
    && $_GET['action'] === 'details';

if ($isArchiveDetailsGet) {
    require_once __DIR__ . '/require_admin_page.php';
    require_once __DIR__ . '/db_connection.php';
} else {
    require_once __DIR__ . '/require_admin_api.php';
}

ini_set('display_errors', 1);
error_reporting(E_ALL);

/* ---------------- Helpers ---------------- */
function json_out(array $d, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode($d);
  exit;
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmt_dt(?string $dt): string {
  if (!$dt || $dt === '0000-00-00 00:00:00') return '—';
  $ts = strtotime($dt);
  return date('F d, Y — h:i A', $ts); // Month Day, Year — 12h time, no seconds
}

/* ---------------- POST: JSON actions ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Accept JSON or form-encoded
  $raw = file_get_contents('php://input');
  $input = json_decode($raw, true);
  if (!is_array($input)) {
    // fall back to form-encoded
    $input = $_POST;
  }

  $action   = $input['action'] ?? null;
  $event_id = isset($input['event_id']) ? (int)$input['event_id'] : null;

  if (!$action) {
    json_out(['status' => 'error', 'message' => 'Missing action.'], 400);
  }
  if ($action !== 'fetch_archives' && !$event_id) {
    json_out(['status' => 'error', 'message' => 'Missing event_id.'], 400);
  }

  if ($action === 'archive_event') {
    $stmt = $conn->prepare("UPDATE tbl_events SET is_archived = 1, is_active = 0, archived_date = NOW() WHERE event_id = ?");
    $stmt->bind_param("i", $event_id);

    if ($stmt->execute()) {
      $stmt->close();

      // Archive voters who participated in this event (uses voters_id consistently)
      $sqlArchiveVoters = "
        UPDATE tbl_voters 
        SET is_archived = 1
        WHERE voters_id IN (
          SELECT DISTINCT pc.voters_id 
          FROM tbl_poll_choice pc
          JOIN tbl_questions q ON pc.question_id = q.question_id
          JOIN tbl_categories c ON q.category_id = c.category_id
          WHERE c.event_id = $event_id
          UNION
          SELECT DISTINCT pf.voters_id 
          FROM tbl_poll_freetext pf
          JOIN tbl_questions q2 ON pf.question_id = q2.question_id
          JOIN tbl_categories c2 ON q2.category_id = c2.category_id
          WHERE c2.event_id = $event_id
        )
      ";
      $conn->query($sqlArchiveVoters);

      json_out(['status' => 'success', 'message' => 'Event and related voters archived successfully.']);
    } else {
      $err = $stmt->error;
      $stmt->close();
      json_out(['status' => 'error', 'message' => 'Failed to archive event.', 'error' => $err], 500);
    }
  }

  if ($action === 'restore') {
    // Deactivate all events, then activate/unarchive selected event
    $conn->query("UPDATE tbl_events SET is_active = 0");
    $conn->query("UPDATE tbl_events SET is_active = 1, is_archived = 0 WHERE event_id = $event_id");

    // Unarchive voters who participated in this event
    $sqlUnarchiveVoters = "
      UPDATE tbl_voters 
      SET is_archived = 0
      WHERE voters_id IN (
        SELECT DISTINCT pc.voters_id 
        FROM tbl_poll_choice pc
        JOIN tbl_questions q ON pc.question_id = q.question_id
        JOIN tbl_categories c ON q.category_id = c.category_id
        WHERE c.event_id = $event_id
        UNION
        SELECT DISTINCT pf.voters_id 
        FROM tbl_poll_freetext pf
        JOIN tbl_questions q2 ON pf.question_id = q2.question_id
        JOIN tbl_categories c2 ON q2.category_id = c2.category_id
        WHERE c2.event_id = $event_id
      )
    ";
    $conn->query($sqlUnarchiveVoters);

    json_out(['status' => 'success', 'message' => 'Archive restored successfully.']);
  }

  if ($action === 'delete') {
    // You may want to guard this (soft-delete only). Keeping as-is per your original.
    $conn->query("DELETE FROM tbl_events WHERE event_id = $event_id");
    json_out(['status' => 'success', 'message' => 'Archive deleted.']);
  }

  if ($action === 'fetch_archives') {
    $result = $conn->query("SELECT * FROM tbl_events WHERE is_archived = 1 ORDER BY archived_date DESC");
    $events = [];
    while ($row = $result->fetch_assoc()) {
      $events[] = $row;
    }
    json_out(['status' => 'success', 'data' => $events]);
  }

  json_out(['status' => 'error', 'message' => 'Unknown action.'], 400);
}

/* ---------------- GET: HTML details renderer ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'details') {
  $event_id = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
  if ($event_id <= 0) {
    header('Content-Type: text/html; charset=UTF-8');
    echo "<div class='alert alert-danger'>Missing or invalid event_id.</div>";
    exit;
  }

  $type = $_GET['type'] ?? ''; // categories|results|voters|nomination_process|...

  // Fetch event (nomination/voting windows displayed in nomination_process)
  $ev = $conn->prepare("SELECT event_id, event_name, year, nomination_start, nomination_end, voting_start, voting_end FROM tbl_events WHERE event_id = ?");
  $ev->bind_param('i', $event_id);
  $ev->execute();
  $resEv = $ev->get_result();
  $event = $resEv && $resEv->num_rows ? $resEv->fetch_assoc() : null;
  $ev->close();

  header('Content-Type: text/html; charset=UTF-8');

  if ($type === 'nomination_process') {
    if (!$event) {
      echo "<div class='alert alert-warning'>Event not found.</div>"; exit;
    }

    // Counts (best-effort if status column exists)
    $counts = [
      'total' => 0,
      'pending' => 0,
      'under_review' => 0,
      'needs_info' => 0,
      'approved' => 0,
      'rejected' => 0,
    ];

    // total nominations for event
    $qTot = $conn->prepare("SELECT COUNT(*) AS c FROM tbl_nominations WHERE event_id = ?");
    $qTot->bind_param('i', $event_id);
    $qTot->execute();
    $rt = $qTot->get_result();
    if ($rt && $rt->num_rows) $counts['total'] = (int)$rt->fetch_assoc()['c'];
    $qTot->close();

    // status buckets (ignore if no status column)
    $statusMap = [
      'pending'      => ['Pending','PENDING','pending'],
      'under_review' => ['Under Review','UNDER REVIEW','UNDER_REVIEW','under_review'],
      'needs_info'   => ['Needs Info','NEEDS INFO','NEEDS_INFO','needs_info'],
      'approved'     => ['Approved','APPROVED','approved'],
      'rejected'     => ['Rejected','REJECTED','rejected'],
    ];
    foreach ($statusMap as $k => $variants) {
      $ph = implode(',', array_fill(0, count($variants), '?'));
      $sql = "SELECT COUNT(*) AS c FROM tbl_nominations WHERE event_id = ? AND status IN ($ph)";
      $types = 'i' . str_repeat('s', count($variants));
      $stmt = $conn->prepare($sql);
      if ($stmt) {
        $params = array_merge([$event_id], $variants);
        $stmt->bind_param($types, ...$params);
        if ($stmt->execute()) {
          $r = $stmt->get_result();
          if ($r && $r->num_rows) $counts[$k] = (int)$r->fetch_assoc()['c'];
        }
        $stmt->close();
      }
    }

    // Reconstruct fields used in this event from answers
    $fields = [];
    $sqlF = "
      SELECT nf.id, nf.label, nf.type, nf.is_required
      FROM tbl_nomination_answers na
      INNER JOIN tbl_nominations n ON n.nomination_id = na.nomination_id
      INNER JOIN tbl_nomination_fields nf ON nf.id = na.field_id
      WHERE n.event_id = ?
      GROUP BY nf.id, nf.label, nf.type, nf.is_required
      ORDER BY nf.sort_order, nf.id
    ";
    $sf = $conn->prepare($sqlF);
    $sf->bind_param('i', $event_id);
    $sf->execute();
    $fr = $sf->get_result();
    while ($row = $fr->fetch_assoc()) $fields[] = $row;
    $sf->close();

    // Render HTML
    ?>
    <div class="container-fluid">
      <div class="mb-3">
        <h4 class="mb-0"><?= h($event['event_name']) ?> (<?= h((string)$event['year']) ?>)</h4>
        <small class="text-muted">Event ID: <?= (int)$event['event_id'] ?></small>
      </div>

      <div class="row g-3">
        <div class="col-12 col-lg-6">
          <div class="card h-100">
            <div class="card-header fw-bold">Nomination Window</div>
            <div class="card-body">
              <div><span class="fw-semibold">Opens:</span> <?= h(fmt_dt($event['nomination_start'] ?? null)) ?></div>
              <div><span class="fw-semibold">Closes:</span> <?= h(fmt_dt($event['nomination_end'] ?? null)) ?></div>
            </div>
          </div>
        </div>

        <div class="col-12 col-lg-6">
          <div class="card h-100">
            <div class="card-header fw-bold">At-a-Glance</div>
            <div class="card-body">
              <div class="row text-center">
                <div class="col-6 col-md-4 mb-3"><div class="fw-bold fs-4"><?= (int)$counts['total'] ?></div><div class="text-muted small">Total Nominations</div></div>
                <div class="col-6 col-md-4 mb-3"><div class="fw-bold"><?= (int)$counts['pending'] ?></div><div class="text-muted small">Pending</div></div>
                <div class="col-6 col-md-4 mb-3"><div class="fw-bold"><?= (int)$counts['under_review'] ?></div><div class="text-muted small">Under Review</div></div>
                <div class="col-6 col-md-4 mb-3"><div class="fw-bold"><?= (int)$counts['needs_info'] ?></div><div class="text-muted small">Needs Info</div></div>
                <div class="col-6 col-md-4 mb-3"><div class="fw-bold"><?= (int)$counts['approved'] ?></div><div class="text-muted small">Approved</div></div>
                <div class="col-6 col-md-4 mb-3"><div class="fw-bold"><?= (int)$counts['rejected'] ?></div><div class="text-muted small">Rejected</div></div>
              </div>
              <div class="text-muted small">*Status tiles appear if your nominations table has a <code>status</code> column.</div>
            </div>
          </div>
        </div>
      </div>

      <div class="card mt-3">
        <div class="card-header fw-bold">How the Nomination Process Worked (<?= h((string)$event['year']) ?>)</div>
        <div class="card-body">
          <ol class="mb-0">
            <li>Nominees completed the online form during the nomination window.</li>
            <li>They provided required business details and supporting information (see list below).</li>
            <li>Submissions were received and placed in <em>Submitted</em> status.</li>
            <li>Admins reviewed entries, requested additional info if needed, and then <em>Approved</em> or <em>Rejected</em>.</li>
          </ol>
        </div>
      </div>

      <div class="card mt-3">
        <div class="card-header fw-bold">Fields Used in This Event’s Nomination Form</div>
        <div class="card-body">
          <?php if (!$fields): ?>
            <div class="text-muted">Couldn’t reconstruct fields from answers (no answers or different schema).</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm align-middle">
                <thead>
                  <tr>
                    <th style="width: 40%">Label</th>
                    <th style="width: 20%">Type</th>
                    <th style="width: 20%">Required</th>
                    <th style="width: 20%">Field ID</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($fields as $f): ?>
                    <tr>
                      <td><?= h($f['label']) ?></td>
                      <td><code><?= h($f['type']) ?></code></td>
                      <td>
                        <?php if ((int)$f['is_required'] === 1): ?>
                          <span class="badge bg-danger">Required</span>
                        <?php else: ?>
                          <span class="badge bg-secondary">Optional</span>
                        <?php endif; ?>
                      </td>
                      <td>#<?= (int)$f['id'] ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <div class="small text-muted">*Derived from actual answers for the archived event—i.e., fields that were truly used.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php
    exit;
  }

  // Default/legacy details: per-category result table (your original behavior)
  // You may also add other types later (categories|questions|choices|results|voters).
  $categories = $conn->prepare("SELECT category_id, category_name FROM tbl_categories WHERE event_id = ?");
  $categories->bind_param('i', $event_id);
  $categories->execute();
  $catRes = $categories->get_result();

  while ($cat = $catRes->fetch_assoc()) {
    echo "<h6>Category: " . h($cat['category_name']) . "</h6>";

    $catId = (int)$cat['category_id'];
    $sqlChoices = "
      SELECT c.choice_name, COUNT(p.choice_id) as vote_count
      FROM tbl_poll_choice p
      JOIN tbl_choices c ON p.choice_id = c.choice_id
      WHERE p.category_id = $catId AND p.event_id = $event_id
      GROUP BY c.choice_id
      ORDER BY vote_count DESC
    ";
    $choices = $conn->query($sqlChoices);

    echo "<table class='table table-bordered table-striped'>
            <thead><tr><th>Choice</th><th>Votes</th><th>Standing</th></tr></thead><tbody>";
    $rank = 1;
    while ($choice = $choices->fetch_assoc()) {
      $badge = $rank === 1 ? "success" : "secondary";
      echo "<tr><td>" . h($choice['choice_name']) . "</td><td>" . (int)$choice['vote_count'] . "</td><td><span class='badge bg-{$badge}'>" . ($rank === 1 ? "Leading" : "Runner-Up") . "</span></td></tr>";
      $rank++;
    }
    echo "</tbody></table>";
  }
  $categories->close();
  exit;
}

/* ---------------- Fallback ---------------- */
json_out(['status' => 'error', 'message' => 'Invalid request.'], 400);
