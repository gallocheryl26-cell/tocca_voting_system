<?php
require_once __DIR__ . '/require_admin_api.php';
require_once __DIR__ . '/includes/freetext_vote.php';
require_once __DIR__ . '/includes/award_answer_fields.php';

// Step 1: Get event_id from query or fallback to active event
$event_id = $_GET['event_id'] ?? null;

if (!$event_id) {
    $fallback = $conn->query("SELECT event_id FROM tbl_events WHERE is_active = 1 LIMIT 1");
    if ($fallback && $fallback->num_rows > 0) {
        $row = $fallback->fetch_assoc();
        $event_id = (int)$row['event_id'];
    }
}

if (!$event_id) {
    echo json_encode(['status' => 'error', 'message' => 'Missing event_id']);
    exit;
}

// --- Return categories and their questions ---
if (!isset($_GET['question_id']) && !isset($_GET['choice_id'])) {
    $categories = [];
    $questionsByCategory = [];

    $catStmt = $conn->prepare("SELECT * FROM tbl_categories WHERE event_id = ? ORDER BY category_name ASC");
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
        WHERE c.event_id = ?
        ORDER BY c.category_id, q.question_name ASC
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

// --- View voters by choice ---
if (isset($_GET['choice_id'])) {
    $choiceId = $_GET['choice_id'];

    $stmt = $conn->prepare("
        SELECT DISTINCT v.voters_id, v.mobile_number, DATE(p.vote_at) AS vote_at
        FROM tbl_poll_choice p
        JOIN tbl_voters v ON p.voters_id = v.voters_id
        JOIN tbl_questions q ON p.question_id = q.question_id
        JOIN tbl_categories c ON q.category_id = c.category_id
        WHERE p.choice_id = ? AND c.event_id = ?
        ORDER BY vote_at DESC
    ");
    $stmt->bind_param("ii", $choiceId, $event_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $voters = [];
    while ($row = $result->fetch_assoc()) {
        $voters[] = $row;
    }

    echo json_encode(['status' => 'success', 'voters' => $voters]);
    exit;
}

// --- Get result data by question ---
if (isset($_GET['question_id'])) {
    $questionId = $_GET['question_id'];

    $stmtType = $conn->prepare("SELECT choice_type, question_name FROM tbl_questions WHERE question_id = ?");
    $stmtType->bind_param("i", $questionId);
    $stmtType->execute();
    $resType = $stmtType->get_result();
    $questionRow = $resType->fetch_assoc();

    if (!$questionRow) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid question ID']);
        exit;
    }

    $results = [];

    // Dropdown votes
    $stmtDropdown = $conn->prepare("
        SELECT 
            p.choice_id, 
            COALESCE(ch.choice_name, 'Unknown/Deleted') AS choice_name, 
            COUNT(DISTINCT p.voters_id) AS vote_count
        FROM tbl_poll_choice p
        LEFT JOIN tbl_choices ch ON p.choice_id = ch.choice_id
        JOIN tbl_questions q ON p.question_id = q.question_id
        JOIN tbl_categories c ON q.category_id = c.category_id
        WHERE p.question_id = ? AND c.event_id = ?
        GROUP BY p.choice_id, choice_name
    ");
    $stmtDropdown->bind_param("ii", $questionId, $event_id);
    $stmtDropdown->execute();
    $dropdownResult = $stmtDropdown->get_result();

    while ($row = $dropdownResult->fetch_assoc()) {
        $results[] = [
            'choice_id' => $row['choice_id'],
            'choice_name' => $row['choice_name'],
            'vote_count' => $row['vote_count']
        ];
    }

    // Freestyle votes
    $stmtFreetext = $conn->prepare("
        SELECT pf.freetext AS choice_name, COUNT(*) AS vote_count
        FROM tbl_poll_freetext pf
        JOIN tbl_questions q ON pf.question_id = q.question_id
        JOIN tbl_categories c ON q.category_id = c.category_id
        WHERE pf.question_id = ? AND c.event_id = ?
        GROUP BY pf.freetext
    ");
    $stmtFreetext->bind_param("ii", $questionId, $event_id);
    $stmtFreetext->execute();
    $freetextResult = $stmtFreetext->get_result();

    $freetextRows = [];
    while ($row = $freetextResult->fetch_assoc()) {
        $freetextRows[] = [
            'choice_id' => null,
            'choice_name' => $row['choice_name'],
            'vote_count' => $row['vote_count']
        ];
    }
    foreach (
        (award_answer_fields_is_place_award((string) ($questionRow['question_name'] ?? ''))
            ? freetext_vote_merge_rows_single($freetextRows)
            : freetext_vote_merge_rows($freetextRows)) as $merged
    ) {
        $results[] = $merged;
    }

    echo json_encode(['status' => 'success', 'results' => $results]);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
exit;
?>
