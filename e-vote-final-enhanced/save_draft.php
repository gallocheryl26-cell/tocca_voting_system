<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', '0');
require_once 'connection.php';
require_once 'voter_session.php';
require_once __DIR__ . '/lib/voter_flow.php';
require_once __DIR__ . '/../tocca_admin/includes/freetext_vote.php';
require_once __DIR__ . '/../tocca_admin/includes/award_answer_fields.php';
require_once __DIR__ . '/../tocca_admin/includes/award_entry_helpers.php';

$data = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON.']);
    exit;
}

if (!$data || !isset($data['category_id'], $data['category_name'], $data['selections'])) {
    echo json_encode(['status' => 'error', 'message' => 'Incomplete draft data.']);
    exit;
}

$voter_id = voter_require_authenticated();
voter_flow_json_ballot_denied($conn, $voter_id);
if (isset($data['voter_id'])) {
    $requested = voter_resolve_id($conn, $data['voter_id']) ?? (is_numeric($data['voter_id']) ? (int)$data['voter_id'] : 0);
    if ($requested > 0) {
        voter_assert_matches_session($requested);
    }
}
voter_session_release();

$selections = $data['selections'];
award_entry_ensure_schema($conn);

$hasBallotCol = false;
if ($r = $conn->query("SHOW COLUMNS FROM `tbl_draft_choice` LIKE 'ballot_entry_id'")) {
    $hasBallotCol = $r->num_rows > 0;
    $r->free();
}

try {
    $conn->begin_transaction();

    foreach ($selections as $item) {
        $question_id = intval($item['question_id']);
        $selectedId = isset($item['choice_id']) && $item['choice_id'] !== "" ? intval($item['choice_id']) : null;
        $fields = award_answer_fields_for_question($conn, $question_id);
        $rawText = isset($item['freetext']) ? trim((string) $item['freetext']) : '';
        $choice_id = null;
        $ballotEntryId = null;
        $freetext = '';

        if (award_answer_fields_uses_open_text($fields)) {
            $freetext = freetext_vote_canonicalize($rawText);
        } elseif ($selectedId !== null && $selectedId > 0) {
            $resolved = voter_resolve_ballot_selection($conn, $question_id, $selectedId);
            if ($resolved !== null) {
                $choice_id = (int) $resolved['choice_id'];
                $ballotEntryId = $resolved['ballot_entry_id'];
            }
        }

        $stmt = $conn->prepare("DELETE FROM tbl_draft_choice WHERE voters_id = ? AND question_id = ?");
        $stmt->bind_param("ii", $voter_id, $question_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM tbl_draft_freetext WHERE voters_id = ? AND question_id = ?");
        $stmt->bind_param("ii", $voter_id, $question_id);
        $stmt->execute();
        $stmt->close();

        if ($choice_id !== null) {
            if ($hasBallotCol && $ballotEntryId) {
                $stmt = $conn->prepare(
                    "INSERT INTO tbl_draft_choice (voters_id, question_id, choice_id, ballot_entry_id) VALUES (?, ?, ?, ?)"
                );
                $stmt->bind_param("iiii", $voter_id, $question_id, $choice_id, $ballotEntryId);
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO tbl_draft_choice (voters_id, question_id, choice_id) VALUES (?, ?, ?)"
                );
                $stmt->bind_param("iii", $voter_id, $question_id, $choice_id);
            }
            $stmt->execute();
            $stmt->close();
        }

        if ($freetext !== '') {
            $stmt = $conn->prepare("INSERT INTO tbl_draft_freetext (voters_id, question_id, freetext) VALUES (?, ?, ?)");
            $stmt->bind_param("iis", $voter_id, $question_id, $freetext);
            $stmt->execute();
            $stmt->close();
        }
    }

    $conn->commit();
    echo json_encode(['status' => 'success', 'message' => 'Draft saved successfully.']);
} catch (Exception $e) {
    $conn->rollback();
    error_log('save_draft: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error while saving draft.']);
}
