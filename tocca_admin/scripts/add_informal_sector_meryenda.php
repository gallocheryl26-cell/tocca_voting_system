<?php
declare(strict_types=1);
/**
 * Add Mayor special ballot items for the active event:
 *  - Category Informal Sector → Most Popular Local Meryenda (fixed snack list)
 *  - Feelings → Best Break Up Song (typed song + singer)
 *
 * Run: php tocca_admin/scripts/add_informal_sector_meryenda.php
 */
require_once dirname(__DIR__) . '/db_connection.php';
require_once dirname(__DIR__) . '/includes/admin_active_event.php';
require_once dirname(__DIR__) . '/includes/category_voting_profile.php';
require_once dirname(__DIR__) . '/includes/award_answer_fields.php';
require_once dirname(__DIR__) . '/includes/ballot_status.php';

if (!($conn instanceof mysqli)) {
    fwrite(STDERR, "Database unavailable.\n");
    exit(1);
}

category_voting_profile_ensure_schema($conn);
award_answer_fields_ensure_schema($conn);
ballot_status_ensure_column($conn);

$eventId = admin_get_active_event_id($conn);
if ($eventId === null || $eventId <= 0) {
    fwrite(STDERR, "No active event.\n");
    exit(1);
}

$hasProfile = false;
$cols = $conn->query('SHOW COLUMNS FROM tbl_categories');
if ($cols) {
    while ($c = $cols->fetch_assoc()) {
        if (strtolower((string) ($c['Field'] ?? '')) === 'voting_profile') {
            $hasProfile = true;
            break;
        }
    }
    $cols->free();
}

$hasOnBallot = ballot_status_ensure_column($conn);
$hasAnswerFields = false;
$qcols = $conn->query('SHOW COLUMNS FROM tbl_questions');
if ($qcols) {
    while ($c = $qcols->fetch_assoc()) {
        if (strtolower((string) ($c['Field'] ?? '')) === 'answer_fields') {
            $hasAnswerFields = true;
            break;
        }
    }
    $qcols->free();
}

function script_find_category(mysqli $conn, int $eventId, string $name): ?int
{
    $st = $conn->prepare(
        'SELECT category_id FROM tbl_categories
         WHERE event_id = ? AND LOWER(TRIM(category_name)) = LOWER(?) LIMIT 1'
    );
    $st->bind_param('is', $eventId, $name);
    $st->execute();
    $id = (int) ($st->get_result()->fetch_assoc()['category_id'] ?? 0);
    $st->close();
    return $id > 0 ? $id : null;
}

function script_find_question(mysqli $conn, int $categoryId, array $names): ?int
{
    foreach ($names as $name) {
        $st = $conn->prepare(
            'SELECT question_id FROM tbl_questions
             WHERE category_id = ? AND LOWER(TRIM(question_name)) = LOWER(?) LIMIT 1'
        );
        $st->bind_param('is', $categoryId, $name);
        $st->execute();
        $id = (int) ($st->get_result()->fetch_assoc()['question_id'] ?? 0);
        $st->close();
        if ($id > 0) {
            return $id;
        }
    }
    $st = $conn->prepare(
        "SELECT question_id FROM tbl_questions
         WHERE category_id = ?
           AND (
             LOWER(question_name) LIKE '%break%up%song%'
             OR LOWER(question_name) LIKE '%breakup%song%'
             OR LOWER(question_name) LIKE '%meryenda%'
           )
         LIMIT 1"
    );
    $st->bind_param('i', $categoryId);
    $st->execute();
    $id = (int) ($st->get_result()->fetch_assoc()['question_id'] ?? 0);
    $st->close();
    return $id > 0 ? $id : null;
}

echo 'Active event #' . $eventId . ' (' . admin_get_active_event_label($conn, $eventId) . ')' . PHP_EOL;

$conn->begin_transaction();
try {
    $informalId = script_find_category($conn, $eventId, 'Informal Sector');
    if ($informalId === null) {
        if ($hasProfile) {
            $st = $conn->prepare(
                "INSERT INTO tbl_categories (category_name, event_id, status, voting_profile)
                 VALUES ('Informal Sector', ?, 1, 'general')"
            );
            $st->bind_param('i', $eventId);
        } else {
            $st = $conn->prepare(
                "INSERT INTO tbl_categories (category_name, event_id, status)
                 VALUES ('Informal Sector', ?, 1)"
            );
            $st->bind_param('i', $eventId);
        }
        $st->execute();
        $informalId = (int) $st->insert_id;
        $st->close();
        echo "Created category Informal Sector (#{$informalId}).\n";
    } else {
        if ($hasProfile) {
            $st = $conn->prepare(
                "UPDATE tbl_categories SET status = 1, voting_profile = 'general'
                 WHERE category_id = ?"
            );
            $st->bind_param('i', $informalId);
            $st->execute();
            $st->close();
        }
        echo "Using existing Informal Sector (#{$informalId}).\n";
    }

    $meryendaId = script_find_question($conn, $informalId, ['Most Popular Local Meryenda']);
    $meryendaName = 'Most Popular Local Meryenda';
    $meryendaFields = 'meryenda';
    $meryendaType = 1;
    if ($meryendaId === null) {
        if ($hasAnswerFields) {
            $st = $conn->prepare(
                'INSERT INTO tbl_questions (question_name, category_id, choice_type, answer_fields)
                 VALUES (?, ?, ?, ?)'
            );
            $st->bind_param('siis', $meryendaName, $informalId, $meryendaType, $meryendaFields);
        } else {
            $st = $conn->prepare(
                'INSERT INTO tbl_questions (question_name, category_id, choice_type)
                 VALUES (?, ?, ?)'
            );
            $st->bind_param('sii', $meryendaName, $informalId, $meryendaType);
        }
        $st->execute();
        $meryendaId = (int) $st->insert_id;
        $st->close();
        echo "Created award Most Popular Local Meryenda (#{$meryendaId}).\n";
    } else {
        if ($hasAnswerFields) {
            $st = $conn->prepare(
                'UPDATE tbl_questions SET question_name = ?, choice_type = ?, answer_fields = ?
                 WHERE question_id = ?'
            );
            $st->bind_param('sisi', $meryendaName, $meryendaType, $meryendaFields, $meryendaId);
            $st->execute();
            $st->close();
        }
        echo "Using existing Most Popular Local Meryenda (#{$meryendaId}).\n";
    }

    $snacks = [
        'Toron',
        'Steamed peanuts (mani)',
        'Fried peanuts',
        'Hotcake with margarine',
        'Ginabot',
        'Homemade leche flan',
        'Banana cue',
        'Camote cue',
        'Fishball',
        'Kikiam',
    ];

    $findChoice = $conn->prepare(
        'SELECT choice_id FROM tbl_choices
         WHERE event_id = ? AND LOWER(TRIM(choice_name)) = LOWER(?) LIMIT 1'
    );
    $insChoiceSql = $hasOnBallot
        ? 'INSERT INTO tbl_choices (choice_name, email, event_id, status, on_ballot) VALUES (?, NULL, ?, 1, 1)'
        : 'INSERT INTO tbl_choices (choice_name, email, event_id, status) VALUES (?, NULL, ?, 1)';
    $insChoice = $conn->prepare($insChoiceSql);
    $onSql = $hasOnBallot
        ? 'UPDATE tbl_choices SET status = 1, on_ballot = 1 WHERE choice_id = ?'
        : 'UPDATE tbl_choices SET status = 1 WHERE choice_id = ?';
    $onChoice = $conn->prepare($onSql);
    $linkChk = $conn->prepare(
        'SELECT 1 FROM tbl_question_choices WHERE question_id = ? AND choice_id = ? LIMIT 1'
    );
    $linkIns = $conn->prepare(
        'INSERT INTO tbl_question_choices (question_id, choice_id) VALUES (?, ?)'
    );
    $hasQcBallot = false;
    $qcCols = $conn->query('SHOW COLUMNS FROM tbl_question_choices');
    if ($qcCols) {
        while ($c = $qcCols->fetch_assoc()) {
            if (strtolower((string) ($c['Field'] ?? '')) === 'on_ballot') {
                $hasQcBallot = true;
                break;
            }
        }
        $qcCols->free();
    }
    $linkOn = $hasQcBallot
        ? $conn->prepare('UPDATE tbl_question_choices SET on_ballot = 1 WHERE question_id = ? AND choice_id = ?')
        : null;

    foreach ($snacks as $snack) {
        $findChoice->bind_param('is', $eventId, $snack);
        $findChoice->execute();
        $cid = (int) ($findChoice->get_result()->fetch_assoc()['choice_id'] ?? 0);
        if ($cid <= 0) {
            $insChoice->bind_param('si', $snack, $eventId);
            $insChoice->execute();
            $cid = (int) $insChoice->insert_id;
            echo "  Added choice: {$snack} (#{$cid})\n";
        } else {
            $onChoice->bind_param('i', $cid);
            $onChoice->execute();
            echo "  Linked existing choice: {$snack} (#{$cid})\n";
        }
        $linkChk->bind_param('ii', $meryendaId, $cid);
        $linkChk->execute();
        if (!$linkChk->get_result()->fetch_assoc()) {
            $linkIns->bind_param('ii', $meryendaId, $cid);
            $linkIns->execute();
        }
        if ($linkOn) {
            $linkOn->bind_param('ii', $meryendaId, $cid);
            $linkOn->execute();
        }
    }
    $findChoice->close();
    $insChoice->close();
    $onChoice->close();
    $linkChk->close();
    $linkIns->close();
    if ($linkOn) {
        $linkOn->close();
    }

    $feelingsId = script_find_category($conn, $eventId, 'Feelings');
    if ($feelingsId === null) {
        echo "Feelings category not found on this event — skipped Best Break Up Song.\n";
    } else {
        $songNames = ['Best Break Up Song', 'Best Breakup Song', 'Best Break-Up Song'];
        $songId = script_find_question($conn, $feelingsId, $songNames);
        $songName = 'Best Break Up Song';
        $songFields = 'song_singer';
        $songType = 0;
        if ($songId === null) {
            if ($hasAnswerFields) {
                $st = $conn->prepare(
                    'INSERT INTO tbl_questions (question_name, category_id, choice_type, answer_fields)
                     VALUES (?, ?, ?, ?)'
                );
                $st->bind_param('siis', $songName, $feelingsId, $songType, $songFields);
            } else {
                $st = $conn->prepare(
                    'INSERT INTO tbl_questions (question_name, category_id, choice_type)
                     VALUES (?, ?, ?)'
                );
                $st->bind_param('sii', $songName, $feelingsId, $songType);
            }
            $st->execute();
            $songId = (int) $st->insert_id;
            $st->close();
            echo "Created Feelings award Best Break Up Song (#{$songId}).\n";
        } else {
            if ($hasAnswerFields) {
                $st = $conn->prepare(
                    'UPDATE tbl_questions SET question_name = ?, choice_type = ?, answer_fields = ?
                     WHERE question_id = ?'
                );
                $st->bind_param('sisi', $songName, $songType, $songFields, $songId);
                $st->execute();
                $st->close();
            } else {
                $st = $conn->prepare(
                    'UPDATE tbl_questions SET question_name = ?, choice_type = ? WHERE question_id = ?'
                );
                $st->bind_param('sii', $songName, $songType, $songId);
                $st->execute();
                $st->close();
            }
            echo "Best Break Up Song already on Feelings (#{$songId}) — kept as typed song + singer.\n";
        }
    }

    $conn->commit();
    echo "Done.\n";
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
