<?php
require_once __DIR__ . '/require_admin_api.php';

$event_id = $_GET['event_id'] ?? null;
if (!$event_id) {
  echo json_encode(['status' => 'error', 'message' => 'Missing event_id']);
  exit;
}

// --- PART 1: Chart Data (Total votes + top choice per category) ---

// 1. Total votes per category
$totalVotesSql = "
  SELECT 
    c.category_id,
    c.category_name,
    COUNT(*) AS total_votes
  FROM (
    SELECT pc.question_id, q.category_id
    FROM tbl_poll_choice pc
    JOIN tbl_questions q ON pc.question_id = q.question_id

    UNION ALL

    SELECT pf.question_id, q.category_id
    FROM tbl_poll_freetext pf
    JOIN tbl_questions q ON pf.question_id = q.question_id
  ) AS combined
  JOIN tbl_categories c ON combined.category_id = c.category_id
  WHERE c.event_id = ?
  GROUP BY c.category_id
";
$totalVotesStmt = $conn->prepare($totalVotesSql);
$totalVotesStmt->bind_param("i", $event_id);
$totalVotesStmt->execute();
$totalVotesResult = $totalVotesStmt->get_result();

$totalVotesMap = [];
while ($row = $totalVotesResult->fetch_assoc()) {
  $totalVotesMap[$row['category_name']] = $row['total_votes'];
}

// 2. Top voted choice per category (dropdown answers only)
$topChoicesSql = "
  SELECT 
    c.category_name,
    ch.choice_name,
    COUNT(*) AS vote_count
  FROM tbl_poll_choice pc
  INNER JOIN tbl_questions q ON pc.question_id = q.question_id
  INNER JOIN tbl_categories c ON q.category_id = c.category_id
  INNER JOIN tbl_choices ch ON pc.choice_id = ch.choice_id
  WHERE c.event_id = ?
  GROUP BY q.category_id, pc.choice_id
";
$topChoicesStmt = $conn->prepare($topChoicesSql);
$topChoicesStmt->bind_param("i", $event_id);
$topChoicesStmt->execute();
$topChoicesResult = $topChoicesStmt->get_result();
$topChoiceMap = [];
while ($row = $topChoicesResult->fetch_assoc()) {
  $cat = $row['category_name'];
  if (!isset($topChoiceMap[$cat]) || $row['vote_count'] > $topChoiceMap[$cat]['vote_count']) {
    $topChoiceMap[$cat] = [
      'choice_name' => $row['choice_name'],
      'vote_count' => $row['vote_count']
    ];
  }
}

$chartData = [];
foreach ($totalVotesMap as $category => $totalVotes) {
  $chartData[] = [
    'category_name' => $category,
    'total_votes' => $totalVotes,
    'choice_name' => $topChoiceMap[$category]['choice_name'] ?? 'N/A',
    'vote_count' => $topChoiceMap[$category]['vote_count'] ?? 0
  ];
}

// --- PART 2: Dashboard Summary Stats ---

// Count registered voters
$registered = $conn->query("SELECT COUNT(*) as count FROM tbl_voters WHERE is_archived = 0")->fetch_assoc()['count'] ?? 0;

// Count finalized voters using has_voted = 1
$finalizedResult = $conn->query("SELECT COUNT(*) AS count FROM tbl_voters WHERE has_voted = 1 AND is_archived = 0");
$finalized = $finalizedResult->fetch_assoc()['count'] ?? 0;

// Get drafted voters: has_voted = 0 AND has at least 1 vote in choice/freetext
$draftedResult = $conn->query("
  SELECT COUNT(DISTINCT v.voters_id) AS count
  FROM tbl_voters v
  LEFT JOIN (
    SELECT voters_id FROM tbl_poll_choice
    UNION
    SELECT voters_id FROM tbl_poll_freetext
  ) AS votes ON v.voters_id = votes.voters_id
  WHERE v.has_voted = 0 AND votes.voters_id IS NOT NULL AND v.is_archived = 0
");
$notVoted = $draftedResult->fetch_assoc()['count'] ?? 0;

// Total votes (choice + freetext)
$totalVotesAllResult = $conn->query("
  SELECT (
    (SELECT COUNT(*) 
     FROM tbl_poll_choice pc
     INNER JOIN tbl_questions q ON pc.question_id = q.question_id
     INNER JOIN tbl_categories c ON q.category_id = c.category_id
     WHERE c.event_id = $event_id
    ) +
    (SELECT COUNT(*) 
     FROM tbl_poll_freetext pf
     INNER JOIN tbl_questions q ON pf.question_id = q.question_id
     INNER JOIN tbl_categories c ON q.category_id = c.category_id
     WHERE c.event_id = $event_id
    )
  ) AS count
");
$totalVotesAll = $totalVotesAllResult->fetch_assoc()['count'] ?? 0;

// --- Final Output ---
echo json_encode([
  'status' => 'success',
  'results' => $chartData,
  'dashboard' => [
    'registered' => (int)$registered,
    'voted' => (int)$finalized,
    'not_voted' => (int)$notVoted,
    'total_votes' => (int)$totalVotesAll
  ]
]);
