<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', '0');

require_once 'connection.php';
require_once 'voter_session.php';
require_once __DIR__ . '/lib/voter_flow.php';
require_once __DIR__ . '/lib/vote_proof_helpers.php';
require_once __DIR__ . '/../tocca_admin/includes/freetext_vote.php';
require_once __DIR__ . '/../tocca_admin/includes/award_answer_fields.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    echo json_encode(['status' => 'error', 'message' => 'Missing or invalid data.']);
    exit;
}

$voters_id = voter_require_authenticated();
voter_flow_json_ballot_denied($conn, $voters_id);
if (isset($data['voters_id'])) {
    $requested = voter_resolve_id($conn, $data['voters_id']) ?? (is_numeric($data['voters_id']) ? (int)$data['voters_id'] : 0);
    if ($requested > 0) {
        voter_assert_matches_session($requested);
    }
}

$answers = $data['answers'] ?? [];
$finalized = $data['finalized_votes'] ?? [];
$draft_code = $data['draft_code'] ?? null;
$event_id = voter_get_active_event_id($conn);

try {
    $conn->begin_transaction();
    $writes = 0;

    $stmt = $conn->prepare('SELECT voters_id, has_voted FROM tbl_voters WHERE voters_id = ?');
    $stmt->bind_param('i', $voters_id);
    $stmt->execute();
    $voter = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$voter) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid voter session.']);
        exit;
    }
    if ((int)$voter['has_voted'] === 1) {
        echo json_encode(['status' => 'error', 'message' => 'You have already submitted your vote.']);
        exit;
    }

    if ($draft_code) {
        $stmt = $conn->prepare('UPDATE tbl_voters SET draft_code = ? WHERE voters_id = ?');
        $stmt->bind_param('si', $draft_code, $voters_id);
        $stmt->execute();
        $stmt->close();
    }

    $stmt = $conn->prepare('
        SELECT question_id FROM tbl_poll_choice WHERE voters_id = ?
        UNION
        SELECT question_id FROM tbl_poll_freetext WHERE voters_id = ?
    ');
    $stmt->bind_param('ii', $voters_id, $voters_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $alreadyFinalized = [];
    while ($row = $result->fetch_assoc()) {
        $alreadyFinalized[] = (int)$row['question_id'];
    }
    $stmt->close();

    $hasBallotCol = false;
    if ($r = $conn->query("SHOW COLUMNS FROM `tbl_poll_choice` LIKE 'ballot_entry_id'")) {
        $hasBallotCol = $r->num_rows > 0;
        $r->free();
    }

    $insertChoice = function (int $question_id, int $selected_id) use ($conn, $voters_id, &$alreadyFinalized, &$writes, $hasBallotCol) {
        if (in_array($question_id, $alreadyFinalized, true)) {
            return;
        }
        $resolved = voter_resolve_ballot_selection($conn, $question_id, $selected_id);
        if ($resolved === null) {
            return;
        }
        $choice_id = (int) $resolved['choice_id'];
        $ballotEntryId = $resolved['ballot_entry_id'];
        $stmt = $conn->prepare('SELECT 1 FROM tbl_poll_choice WHERE voters_id = ? AND question_id = ?');
        $stmt->bind_param('ii', $voters_id, $question_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->num_rows === 0) {
            if ($hasBallotCol && $ballotEntryId) {
                $insert = $conn->prepare(
                    'INSERT INTO tbl_poll_choice (voters_id, question_id, choice_id, ballot_entry_id, vote_at)
                     VALUES (?, ?, ?, ?, NOW())'
                );
                $insert->bind_param('iiii', $voters_id, $question_id, $choice_id, $ballotEntryId);
            } else {
                $insert = $conn->prepare(
                    'INSERT INTO tbl_poll_choice (voters_id, question_id, choice_id, vote_at) VALUES (?, ?, ?, NOW())'
                );
                $insert->bind_param('iii', $voters_id, $question_id, $choice_id);
            }
            $insert->execute();
            $insert->close();
            $writes++;

            try {
                vote_proof_promote_drafts($conn, $voters_id, $question_id);
            } catch (Throwable $e) {
                error_log('submit_vote proof promote: ' . $e->getMessage());
            }

            try {
                $deleteDraftChoice = $conn->prepare('DELETE FROM tbl_draft_choice WHERE voters_id = ? AND question_id = ?');
                $deleteDraftChoice->bind_param('ii', $voters_id, $question_id);
                $deleteDraftChoice->execute();
                $deleteDraftChoice->close();

                $deleteDraftFreetext = $conn->prepare('DELETE FROM tbl_draft_freetext WHERE voters_id = ? AND question_id = ?');
                $deleteDraftFreetext->bind_param('ii', $voters_id, $question_id);
                $deleteDraftFreetext->execute();
                $deleteDraftFreetext->close();
            } catch (Throwable $e) {
                error_log('submit_vote draft cleanup: ' . $e->getMessage());
            }
        }
        $stmt->close();
    };

    $insertFreetext = function (int $question_id, string $freetext) use ($conn, $voters_id, &$alreadyFinalized, &$writes) {
        $freetext = trim($freetext);
        if ($freetext === '' || in_array($question_id, $alreadyFinalized, true)) {
            return;
        }
        $stmt = $conn->prepare('SELECT 1 FROM tbl_poll_freetext WHERE voters_id = ? AND question_id = ?');
        $stmt->bind_param('ii', $voters_id, $question_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->num_rows === 0) {
            $insert = $conn->prepare('INSERT INTO tbl_poll_freetext (voters_id, question_id, freetext, vote_at) VALUES (?, ?, ?, NOW())');
            $insert->bind_param('iis', $voters_id, $question_id, $freetext);
            $insert->execute();
            $insert->close();
            $writes++;
            try {
                $deleteDraftChoice = $conn->prepare('DELETE FROM tbl_draft_choice WHERE voters_id = ? AND question_id = ?');
                $deleteDraftChoice->bind_param('ii', $voters_id, $question_id);
                $deleteDraftChoice->execute();
                $deleteDraftChoice->close();

                $deleteDraftFreetext = $conn->prepare('DELETE FROM tbl_draft_freetext WHERE voters_id = ? AND question_id = ?');
                $deleteDraftFreetext->bind_param('ii', $voters_id, $question_id);
                $deleteDraftFreetext->execute();
                $deleteDraftFreetext->close();
            } catch (Throwable $e) {
                error_log('submit_vote freetext draft cleanup: ' . $e->getMessage());
            }
        }
        $stmt->close();
    };

    $normalizeAnswer = function (int $question_id, ?int $choice_id, string $freetext) use ($conn): array {
        $fields = award_answer_fields_for_question($conn, $question_id);
        if ($fields === 'song_singer') {
            $text = freetext_vote_canonicalize($freetext);
            return [null, $text];
        }
        if ($fields === 'meryenda') {
            if ($choice_id !== null && $choice_id > 0) {
                $where = freetext_vote_title_case($freetext);
                return $where === '' ? [null, ''] : [$choice_id, $where];
            }
            $text = freetext_vote_canonicalize($freetext);
            $parts = freetext_vote_parse($text);
            if ($parts['title'] === '' || $parts['singer'] === '') {
                return [null, ''];
            }
            return [null, $text];
        }
        // product_business is now a named-entry / list dropdown — keep choice_id.
        return [$choice_id, ''];
    };

    foreach ($finalized as $question_id => $detail) {
        $question_id = (int)$question_id;
        $choice_id = isset($detail['choice_id']) && is_numeric($detail['choice_id']) ? (int)$detail['choice_id'] : null;
        $freetext = trim((string)($detail['freetext'] ?? ''));
        [$choice_id, $freetext] = $normalizeAnswer($question_id, $choice_id, $freetext);
        if ($choice_id !== null) {
            $insertChoice($question_id, $choice_id);
        }
        if ($freetext !== '') {
            $insertFreetext($question_id, $freetext);
        }
    }

    foreach ($answers as $answer) {
        $question_id = (int)($answer['question_id'] ?? 0);
        $choice_id = isset($answer['choice_id']) && is_numeric($answer['choice_id']) ? (int)$answer['choice_id'] : null;
        $freetext = trim((string)($answer['freetext'] ?? ''));
        [$choice_id, $freetext] = $normalizeAnswer($question_id, $choice_id, $freetext);
        if ($choice_id !== null) {
            $insertChoice($question_id, $choice_id);
        }
        if ($freetext !== '') {
            $insertFreetext($question_id, $freetext);
        }
    }

    $conn->commit();

    // Post-commit summary is best-effort (never fail a successful write).
    $totalQuestions = 0;
    $finalizedCount = 0;
    try {
        if ($event_id !== null) {
            $totalQuestions = voter_flow_count_required_questions($conn, $event_id);
            $finalizedCount = voter_flow_count_finalized_questions($conn, $voters_id, $event_id);
            voter_flow_sync_has_voted_if_complete($conn, $voters_id);
        }
    } catch (Throwable $e) {
        error_log('submit_vote post-commit summary: ' . $e->getMessage());
    }

    $complete = ($finalizedCount >= $totalQuestions && $totalQuestions > 0);
    if ($complete) {
        $message = 'Your response has been successfully casted.';
    } elseif ($writes > 0) {
        $message = 'Your responses have been saved. You can continue later.';
    } else {
        $message = 'No new votes were recorded.';
    }

    echo json_encode([
        'status' => 'success',
        'message' => $message,
        'complete' => $complete,
        'total_questions' => $totalQuestions,
        'finalized_count' => $finalizedCount,
        'writes' => $writes,
        'skipped_without_proof' => [],
    ]);
} catch (Throwable $e) {
    $conn->rollback();
    error_log('submit_vote: ' . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => 'Vote submission failed.',
    ]);
}
