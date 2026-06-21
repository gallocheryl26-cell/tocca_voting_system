<?php
require_once __DIR__ . '/require_admin_api.php';

if (!isset($_GET['voters_id']) || !is_numeric($_GET['voters_id'])) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Invalid or missing voter ID.']);
    exit;
}

$voters_id = (int)$_GET['voters_id'];

// ✅ Check if the voter exists
$stmt = $conn->prepare("SELECT * FROM tbl_voters WHERE voters_id = ?");
$stmt->bind_param("i", $voters_id);
$stmt->execute();
$result = $stmt->get_result();

if (!$result || $result->num_rows === 0) {
    die("Voter not found.");
}

header('Content-Type: text/csv; charset=utf-8');
header("Content-Disposition: attachment; filename=voter_{$voters_id}_activity.csv");

$output = fopen('php://output', 'w');
fputs($output, "\xEF\xBB\xBF"); // Excel UTF-8 BOM
fputcsv($output, ['Question', 'Answer Type', 'Answer', 'Voted At']);

$hasData = false;

// ✅ Dropdown votes (DATE only)
$sqlChoice = "
    SELECT q.question_name, 'Dropdown' AS answer_type, c.choice_name AS answer, DATE(pc.vote_at) AS vote_date
    FROM tbl_poll_choice pc
    JOIN tbl_questions q ON pc.question_id = q.question_id
    JOIN tbl_choices c ON pc.choice_id = c.choice_id
    WHERE pc.voters_id = ?
";

$stmt1 = $conn->prepare($sqlChoice);
$stmt1->bind_param("i", $voters_id);
$stmt1->execute();
$result1 = $stmt1->get_result();
while ($row = $result1->fetch_assoc()) {
    fputcsv($output, [$row['question_name'], $row['answer_type'], $row['answer'], $row['vote_date']]);
    $hasData = true;
}

// ✅ Freetext votes (DATE only)
$sqlFreetext = "
    SELECT q.question_name, 'Freetext' AS answer_type, pf.freetext AS answer, DATE(pf.vote_at) AS vote_date
    FROM tbl_poll_freetext pf
    JOIN tbl_questions q ON pf.question_id = q.question_id
    WHERE pf.voters_id = ?
";

$stmt2 = $conn->prepare($sqlFreetext);
$stmt2->bind_param("i", $voters_id);
$stmt2->execute();
$result2 = $stmt2->get_result();
while ($row = $result2->fetch_assoc()) {
    fputcsv($output, [$row['question_name'], $row['answer_type'], $row['answer'], $row['vote_date']]);
    $hasData = true;
}

// ✅ No answers fallback
if (!$hasData) {
    fputcsv($output, ['No activity found', '', '', '']);
}

fclose($output);
exit;
?>
