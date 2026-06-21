<?php
declare(strict_types=1);

header('Content-Type: application/json');
require_once '../tocca_admin/db_connection.php';
require_once __DIR__ . '/lib/choice_logo_helpers.php';
require_once __DIR__ . '/../tocca_admin/includes/category_voting_profile.php';

category_voting_profile_ensure_schema($conn);
if (!isset($_GET['category_id']) || !is_numeric($_GET['category_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Missing or invalid category_id']);
    exit;
}
$category_id = (int)$_GET['category_id'];

$categoryProfile = 'business';
$catStmt = $conn->prepare('SELECT voting_profile FROM tbl_categories WHERE category_id = ? LIMIT 1');
if ($catStmt) {
    $catStmt->bind_param('i', $category_id);
    if ($catStmt->execute()) {
        $catRow = $catStmt->get_result()->fetch_assoc();
        if ($catRow) {
            $categoryProfile = category_voting_profile_from_row($catRow);
        }
    }
    $catStmt->close();
}
$fieldLabels = category_voting_profile_labels($categoryProfile);

// Detect whether the choice media table exists yet so we can decorate each
// choice with a `has_media` flag without breaking installations that haven't
// uploaded any media.
$hasMediaTable = false;
if ($res = $conn->query("SHOW TABLES LIKE 'tbl_choice_media'")) {
    $hasMediaTable = $res->num_rows > 0;
    $res->close();
}

try {
    $select = "
        SELECT q.question_id, q.question_name, q.category_id, q.choice_type,
               c.choice_id, c.choice_name";
    $join = "";
    if ($hasMediaTable) {
        $select .= ",
               (SELECT COUNT(*) FROM tbl_choice_media m WHERE m.choice_id = c.choice_id) AS media_count";
    }
    $sql = $select . "
        FROM tbl_questions q
        LEFT JOIN tbl_question_choices qc ON q.question_id = qc.question_id
        LEFT JOIN tbl_choices c ON qc.choice_id = c.choice_id AND c.status = 1
        $join
        WHERE q.category_id = ?
        ORDER BY q.question_id ASC, c.choice_name ASC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $category_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $questions = [];
    $allChoiceIds = [];
    while ($row = $result->fetch_assoc()) {
        $qid = (int)$row['question_id'];
        if (!isset($questions[$qid])) {
            $questions[$qid] = [
                'question_id'   => $qid,
                'question_name' => $row['question_name'],
                'category_id'   => (int)$row['category_id'],
                'choice_type'   => (int)$row['choice_type'],
                'choices'       => []
            ];
        }
        if ($row['choice_id'] !== null) {
            $cid = (int) $row['choice_id'];
            $allChoiceIds[] = $cid;
            $choice = [
                'choice_id'   => $cid,
                'choice_name' => $row['choice_name'],
                'has_media'   => $hasMediaTable ? ((int)($row['media_count'] ?? 0) > 0) : false,
                'logo_url'    => '',
            ];
            $questions[$qid]['choices'][] = $choice;
        }
    }

    $logoUrls = choice_logo_urls_for_ids($conn, $allChoiceIds);
    $perAwardLabels = category_voting_profile_uses_per_award_labels($categoryProfile);
    foreach ($questions as &$question) {
        if ($perAwardLabels) {
            $question['field_labels'] = category_voting_profile_labels_for_award(
                $categoryProfile,
                (string) ($question['question_name'] ?? '')
            );
        }
        foreach ($question['choices'] as &$choice) {
            $cid = (int) ($choice['choice_id'] ?? 0);
            if ($cid > 0 && isset($logoUrls[$cid])) {
                $choice['logo_url'] = $logoUrls[$cid];
            }
        }
        unset($choice);
    }
    unset($question);

    $questions = array_values($questions);
    echo json_encode([
        'status' => 'success',
        'voting_profile' => $categoryProfile,
        'field_labels' => $fieldLabels,
        'questions' => $questions,
    ]);
} catch (Exception $e) {
    error_log('Error in load_questions_with_choices.php: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
}
exit;
