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

@ignore_user_abort(true);
@set_time_limit(60);

$selections = is_array($data['selections']) ? $data['selections'] : [];
award_entry_ensure_schema($conn);

$hasBallotCol = false;
if ($r = $conn->query("SHOW COLUMNS FROM `tbl_draft_choice` LIKE 'ballot_entry_id'")) {
    $hasBallotCol = $r->num_rows > 0;
    $r->free();
}

try {
    $qids = [];
    foreach ($selections as $item) {
        $qid = (int) ($item['question_id'] ?? 0);
        if ($qid > 0) {
            $qids[$qid] = $qid;
        }
    }
    $qids = array_values($qids);
    if ($qids === []) {
        echo json_encode(['status' => 'success', 'message' => 'Draft saved successfully.']);
        exit;
    }

    $fieldsByQ = [];
    $ph = implode(',', array_fill(0, count($qids), '?'));
    $st = $conn->prepare("SELECT question_id, answer_fields FROM tbl_questions WHERE question_id IN ($ph)");
    if ($st) {
        $types = str_repeat('i', count($qids));
        $st->bind_param($types, ...$qids);
        $st->execute();
        $res = $st->get_result();
        while ($row = $res->fetch_assoc()) {
            $fieldsByQ[(int) $row['question_id']] = award_answer_fields_from_row($row);
        }
        $st->close();
    }

    $resolvePairs = [];
    foreach ($selections as $item) {
        $qid = (int) ($item['question_id'] ?? 0);
        if ($qid <= 0) {
            continue;
        }
        $fields = $fieldsByQ[$qid] ?? award_answer_fields_for_question($conn, $qid);
        if (award_answer_fields_uses_open_text($fields)) {
            continue;
        }
        $selectedId = isset($item['choice_id']) && $item['choice_id'] !== '' ? (int) $item['choice_id'] : 0;
        if ($selectedId > 0) {
            $resolvePairs[] = [$qid, $selectedId];
        }
    }
    $resolvedMap = function_exists('voter_resolve_selections_bulk')
        ? voter_resolve_selections_bulk($conn, $resolvePairs)
        : [];

    $conn->begin_transaction();

    $delTypes = 'i' . str_repeat('i', count($qids));
    $delParams = array_merge([$voter_id], $qids);
    $st = $conn->prepare("DELETE FROM tbl_draft_choice WHERE voters_id = ? AND question_id IN ($ph)");
    $st->bind_param($delTypes, ...$delParams);
    $st->execute();
    $st->close();

    $st = $conn->prepare("DELETE FROM tbl_draft_freetext WHERE voters_id = ? AND question_id IN ($ph)");
    $st->bind_param($delTypes, ...$delParams);
    $st->execute();
    $st->close();

    $insChoice = null;
    $insChoiceBallot = null;
    $insText = $conn->prepare(
        'INSERT INTO tbl_draft_freetext (voters_id, question_id, freetext) VALUES (?, ?, ?)'
    );

    foreach ($selections as $item) {
        $question_id = (int) ($item['question_id'] ?? 0);
        if ($question_id <= 0) {
            continue;
        }
        $selectedId = isset($item['choice_id']) && $item['choice_id'] !== '' ? (int) $item['choice_id'] : 0;
        $fields = $fieldsByQ[$question_id] ?? award_answer_fields_for_question($conn, $question_id);
        $rawText = isset($item['freetext']) ? trim((string) $item['freetext']) : '';
        $choice_id = null;
        $ballotEntryId = null;
        $freetext = '';

        if (award_answer_fields_uses_open_text($fields)) {
            $freetext = freetext_vote_canonicalize($rawText);
        } elseif ($fields === 'meryenda' && $selectedId <= 0) {
            $freetext = freetext_vote_canonicalize($rawText);
        } elseif ($selectedId > 0) {
            $resolved = $resolvedMap[$question_id . ':' . $selectedId] ?? null;
            if ($resolved === null && $resolvedMap === []) {
                try {
                    $resolved = voter_resolve_ballot_selection($conn, $question_id, $selectedId);
                } catch (Throwable $e) {
                    error_log('save_draft resolve q' . $question_id . ': ' . $e->getMessage());
                    $resolved = null;
                }
            }
            if (is_array($resolved)) {
                $choice_id = (int) $resolved['choice_id'];
                $ballotEntryId = $resolved['ballot_entry_id'];
            }
            if ($fields === 'meryenda') {
                $freetext = freetext_vote_title_case($rawText);
            }
        }

        if ($choice_id !== null) {
            if ($hasBallotCol && $ballotEntryId) {
                if ($insChoiceBallot === null) {
                    $insChoiceBallot = $conn->prepare(
                        'INSERT INTO tbl_draft_choice (voters_id, question_id, choice_id, ballot_entry_id) VALUES (?, ?, ?, ?)'
                    );
                }
                $insChoiceBallot->bind_param('iiii', $voter_id, $question_id, $choice_id, $ballotEntryId);
                $insChoiceBallot->execute();
            } else {
                if ($insChoice === null) {
                    $insChoice = $conn->prepare(
                        'INSERT INTO tbl_draft_choice (voters_id, question_id, choice_id) VALUES (?, ?, ?)'
                    );
                }
                $insChoice->bind_param('iii', $voter_id, $question_id, $choice_id);
                $insChoice->execute();
            }
        }

        if ($freetext !== '' && $insText) {
            $insText->bind_param('iis', $voter_id, $question_id, $freetext);
            $insText->execute();
        }
    }

    if ($insChoice) {
        $insChoice->close();
    }
    if ($insChoiceBallot) {
        $insChoiceBallot->close();
    }
    if ($insText) {
        $insText->close();
    }

    $conn->commit();
    echo json_encode(['status' => 'success', 'message' => 'Draft saved successfully.']);
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
    }
    error_log('save_draft: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error while saving draft.']);
}
