<?php
require_once __DIR__ . '/require_admin_api.php';
require_once __DIR__ . '/includes/results_formula.php';

$event_id = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;
if ($event_id <= 0) {
  echo json_encode(['status' => 'error', 'message' => 'Missing event_id']);
  exit;
}

$cast = results_formula_unique_cast_votes($conn, $event_id);

$chartData = [];
foreach ($cast['by_category'] as $category => $totalVotes) {
  $top = $cast['top_by_category'][$category] ?? [];
  $chartData[] = [
    'category_name' => $category,
    'total_votes' => (int) $totalVotes,
    'choice_name' => $top['choice_name'] ?? 'N/A',
    'vote_count' => (int) ($top['vote_count'] ?? 0),
  ];
}

require_once __DIR__ . '/includes/voters_list_data.php';
$voterRows = voters_list_rows_for_event($conn, $event_id);
$registered = count($voterRows);
$finalized = 0;
$notVoted = 0;
foreach ($voterRows as $voterRow) {
  if (($voterRow['status'] ?? '') === 'completed') {
    $finalized++;
  } elseif (($voterRow['status'] ?? '') === 'drafted') {
    $notVoted++;
  }
}

echo json_encode([
  'status' => 'success',
  'results' => $chartData,
  'dashboard' => [
    'registered' => (int) $registered,
    'voted' => (int) $finalized,
    'not_voted' => (int) $notVoted,
    'total_votes' => (int) $cast['total'],
  ],
]);
