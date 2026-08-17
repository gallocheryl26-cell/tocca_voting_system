<?php
require_once __DIR__ . '/require_admin_api.php';
require_once __DIR__ . '/includes/results_formula.php';
require_once __DIR__ . '/includes/admin_schema.php';
require_once __DIR__ . '/includes/freetext_vote.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $raw = file_get_contents('php://input');
  $data = json_decode((string) $raw, true);
  if (!is_array($data)) {
    $data = $_POST;
  }
  if (($data['action'] ?? '') === 'save_twg') {
    echo json_encode([
      'status' => 'error',
      'message' => 'Enter TWG scores on Reports → TWG Evaluation. Results only displays the member average.',
    ]);
    exit;
  }
  echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
  exit;
}

if (isset($_GET['choice_id']) && isset($_GET['question_id'])) {
  $choiceId = $_GET['choice_id'];
  $questionId = $_GET['question_id'];

  $stmt = $conn->prepare("
    SELECT DISTINCT v.voters_id, v.mobile_number, DATE(p.vote_at) AS vote_at
    FROM tbl_poll_choice p
    JOIN tbl_voters v ON p.voters_id = v.voters_id
    WHERE p.choice_id = ? AND p.question_id = ?
    ORDER BY vote_at DESC
  ");
  $stmt->bind_param("ii", $choiceId, $questionId);
  $stmt->execute();
  $result = $stmt->get_result();

  $voters = [];
  while ($row = $result->fetch_assoc()) {
    $voters[] = $row;
  }

  echo json_encode(['status' => 'success', 'voters' => $voters]);
  exit;
}

if (isset($_GET['freetext']) && isset($_GET['question_id']) && isset($_GET['event_id'])) {
  $freetext = (string) $_GET['freetext'];
  $questionId = $_GET['question_id'];
  $event_id = $_GET['event_id'];
  $matchKey = freetext_vote_key($freetext);

  $stmt = $conn->prepare("
    SELECT v.voters_id, v.mobile_number, DATE(pf.vote_at) AS vote_at, pf.freetext
    FROM tbl_poll_freetext pf
    JOIN tbl_voters v ON pf.voters_id = v.voters_id
    JOIN tbl_questions q ON pf.question_id = q.question_id
    JOIN tbl_categories c ON q.category_id = c.category_id
    WHERE pf.question_id = ? AND c.event_id = ?
    ORDER BY vote_at DESC
  ");
  $stmt->bind_param("ii", $questionId, $event_id);
  $stmt->execute();
  $result = $stmt->get_result();

  $voters = [];
  $seen = [];
  while ($row = $result->fetch_assoc()) {
    $stored = (string) ($row['freetext'] ?? '');
    $storedKey = freetext_vote_key($stored);
    $matches = $matchKey !== ''
      ? ($storedKey === $matchKey)
      : ($storedKey === '' && trim($stored) === '');
    if (!$matches) {
      continue;
    }
    $vid = (int) ($row['voters_id'] ?? 0);
    if ($vid > 0 && isset($seen[$vid])) {
      continue;
    }
    if ($vid > 0) {
      $seen[$vid] = true;
    }
    unset($row['freetext']);
    $voters[] = $row;
  }

  echo json_encode(['status' => 'success', 'voters' => $voters]);
  exit;
}

$event_id = $_GET['event_id'] ?? null;
if (!$event_id) {
  echo json_encode(['status' => 'error', 'message' => 'Missing event_id']);
  exit;
}

if (isset($_GET['twg_overview'])) {
  $overview = twg_fetch_results_overview($conn, (int) $event_id);
  echo json_encode([
    'status' => 'success',
    'twg_members' => $overview['members'],
    'rows' => $overview['rows'],
  ]);
  exit;
}

if (!isset($_GET['question_id'])) {
  $categories = [];
  $questionsByCategory = [];
  $catActive = admin_active_category_sql($conn, 'c');
  $qActive = admin_active_question_sql($conn, 'q');

  $catStmt = $conn->prepare(
      "SELECT c.category_id, c.category_name
       FROM tbl_categories c
       WHERE c.event_id = ? AND {$catActive}
         AND EXISTS (
           SELECT 1 FROM tbl_questions q
           WHERE q.category_id = c.category_id AND {$qActive}
         )
       ORDER BY c.category_name ASC"
  );
  $catStmt->bind_param("i", $event_id);
  $catStmt->execute();
  $catResult = $catStmt->get_result();

  while ($row = $catResult->fetch_assoc()) {
    $categories[] = $row;
  }

  $qStmt = $conn->prepare("
    SELECT q.question_id, q.question_name, q.category_id 
    FROM tbl_questions q
    INNER JOIN tbl_categories c ON q.category_id = c.category_id
    WHERE c.event_id = ? AND {$catActive} AND {$qActive}
    ORDER BY c.category_name ASC, q.question_name ASC
  ");
  $qStmt->bind_param("i", $event_id);
  $qStmt->execute();
  $qResult = $qStmt->get_result();

  while ($row = $qResult->fetch_assoc()) {
    $questionsByCategory[$row['category_id']][] = [
      'question_id' => $row['question_id'],
      'question_name' => $row['question_name']
    ];
  }

  echo json_encode([
    'status' => 'success',
    'categories' => $categories,
    'questions_by_category' => $questionsByCategory
  ]);
  exit;
}

if (isset($_GET['question_id'])) {
  $questionId = (int) $_GET['question_id'];
  $event_id = (int) $event_id;

  $stmtType = $conn->prepare("SELECT choice_type FROM tbl_questions WHERE question_id = ?");
  $stmtType->bind_param("i", $questionId);
  $stmtType->execute();
  $resType = $stmtType->get_result();
  $questionRow = $resType->fetch_assoc();
  $stmtType->close();

  if (!$questionRow) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid question ID']);
    exit;
  }

  $payload = results_formula_fetch_for_award($conn, $event_id, $questionId);
  echo json_encode([
    'status' => 'success',
    'formula' => [
      'twg_weight' => 0.30,
      'community_weight' => 0.70,
      'community' => 'vote_share × 10, where vote share = votes ÷ total votes in this award',
      'final' => '(TWG × 30%) + (community × 70%)',
    ],
    'total_votes' => $payload['total_votes'],
    'nominee_count' => $payload['nominee_count'],
    'twg_entered' => $payload['twg_entered'],
    'leader' => $payload['leader'],
    'twg_leader' => $payload['twg_leader'] ?? null,
    'twg_members' => $payload['twg_members'] ?? [],
    'results' => $payload['results'],
  ]);
  exit;
}

echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
exit;
