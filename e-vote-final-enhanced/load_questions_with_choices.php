<?php
declare(strict_types=1);

header('Content-Type: application/json');
require_once '../tocca_admin/db_connection.php';
require_once __DIR__ . '/lib/choice_logo_helpers.php';
require_once __DIR__ . '/lib/voter_flow.php';
require_once __DIR__ . '/../tocca_admin/includes/category_voting_profile.php';
require_once __DIR__ . '/../tocca_admin/includes/award_answer_fields.php';
require_once __DIR__ . '/../tocca_admin/includes/award_entry_helpers.php';
require_once __DIR__ . '/../tocca_admin/includes/ballot_status.php';

category_voting_profile_ensure_schema($conn);
award_answer_fields_ensure_schema($conn);
award_entry_ensure_schema($conn);
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
        SELECT q.question_id, q.question_name, q.category_id, q.choice_type, q.answer_fields,
               c.choice_id, c.choice_name";
    $join = "";
    if ($hasMediaTable) {
        $select .= ",
               (SELECT COUNT(*) FROM tbl_choice_media m WHERE m.choice_id = c.choice_id) AS media_count";
    }
    $onBallotJoin = ballot_status_sql_and($conn, 'c');
    $awardBallotJoin = ballot_award_sql_and($conn, 'qc');
    $votableSql = voter_flow_votable_question_sql($conn, 'q');
    $typedAwardSql = "(LOWER(TRIM(COALESCE(q.answer_fields, ''))) = 'song_singer'
            OR COALESCE(q.choice_type, 1) = 0)";
    $sql = $select . "
        FROM tbl_questions q
        LEFT JOIN tbl_question_choices qc
            ON q.question_id = qc.question_id
           AND NOT {$typedAwardSql}
           {$awardBallotJoin}
        LEFT JOIN tbl_choices c
            ON qc.choice_id = c.choice_id
           AND c.status = 1
           AND NOT {$typedAwardSql}
           {$onBallotJoin}
        $join
        WHERE q.category_id = ?
          AND {$votableSql}
        ORDER BY q.question_id ASC, c.choice_name ASC
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Failed to prepare award query: ' . $conn->error);
    }
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
                'answer_fields' => award_answer_fields_from_row($row, $categoryProfile),
                'choices'       => []
            ];
        }
        if ($row['choice_id'] !== null && !award_answer_fields_uses_open_text($questions[$qid]['answer_fields'] ?? '')) {
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
    $namedQids = [];
    foreach ($questions as $qid => $question) {
        $awardName = (string) ($question['question_name'] ?? '');
        $fields = award_answer_fields_from_row($question, $categoryProfile);
        if (award_answer_fields_uses_ballot_entries($fields, $awardName)) {
            $namedQids[] = (int) $qid;
        }
    }
    $ballotByQuestion = award_entry_fetch_ballot_for_questions($conn, $namedQids);

    foreach ($questions as &$question) {
        $qid = (int) ($question['question_id'] ?? 0);
        $awardName = (string) ($question['question_name'] ?? '');
        $fields = award_answer_fields_from_row($question, $categoryProfile);
        $question['answer_fields'] = $fields;
        $openText = award_answer_fields_uses_open_text($fields);
        $usesBallot = award_answer_fields_uses_ballot_entries($fields, $awardName);
        $question['answer_mode'] = $openText
            ? 'open_text'
            : ($usesBallot ? 'named_entry' : 'list');
        $question['field_labels'] = award_answer_fields_labels($fields, $categoryProfile, $awardName);
        if ($openText) {
            $question['choices'] = [];
            continue;
        }
        if ($usesBallot) {
            $entries = $ballotByQuestion[$qid] ?? [];
            $question['choices'] = [];
            foreach ($entries as $entry) {
                $cid = (int) $entry['choice_id'];
                $question['choices'][] = [
                    'choice_id' => (int) $entry['ballot_entry_id'], // select value = ballot entry
                    'business_choice_id' => $cid,
                    'ballot_entry_id' => (int) $entry['ballot_entry_id'],
                    'entry_name' => (string) $entry['entry_name'],
                    'entry_kind' => (string) $entry['entry_kind'],
                    'choice_name' => (string) $entry['display_name'],
                    'has_media' => false,
                    'logo_url' => $logoUrls[$cid] ?? '',
                    'is_named_entry' => true,
                ];
            }
            continue;
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

    foreach ($questions as $qid => $question) {
        $openText = (($question['answer_mode'] ?? '') === 'open_text');
        $mode = (string) ($question['answer_mode'] ?? 'list');
        $needsList = $mode === 'list' || $mode === 'named_entry';
        if (!$openText && $needsList && empty($question['choices'])) {
            unset($questions[$qid]);
        }
    }

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
