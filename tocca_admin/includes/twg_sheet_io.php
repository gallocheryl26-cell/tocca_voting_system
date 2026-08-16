<?php
declare(strict_types=1);

require_once __DIR__ . '/results_formula.php';
require_once __DIR__ . '/admin_schema.php';

/**
 * Round-trip TWG scoresheet for onsite (paper) scoring.
 * Empty member cells on import are skipped so partial sheets do not wipe scores.
 */

function twg_sheet_column_keys(): array
{
    return [
        'event_id',
        'question_id',
        'choice_id',
        'category',
        'award',
        'establishment',
        'lgu_1',
        'lgu_2',
        'bplo',
        'ledipo',
        'orcham',
        'average',
    ];
}

function twg_sheet_column_labels(): array
{
    return [
        'event_id' => 'event_id',
        'question_id' => 'question_id',
        'choice_id' => 'choice_id',
        'category' => 'Category',
        'award' => 'Award',
        'establishment' => 'Establishment',
        'lgu_1' => 'LGU Head 1',
        'lgu_2' => 'LGU Head 2',
        'bplo' => 'BPLO',
        'ledipo' => 'LEDIPO',
        'orcham' => 'ORCHAM',
        'average' => 'Average (system)',
    ];
}

function twg_sheet_safe_filename(string $s): string
{
    $s = trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    $s = preg_replace('/[\\\\\\/:*?"<>|]+/', '_', $s) ?? $s;
    return $s === '' ? 'TWG_scoresheet' : $s;
}

function twg_sheet_normalize_header(string $label): string
{
    $label = strtolower(trim($label));
    $label = preg_replace('/[^a-z0-9]+/', '_', $label) ?? $label;
    return trim($label, '_');
}

/**
 * @return array<string, int> normalized header => column index
 */
function twg_sheet_header_index_map(array $headerRow): array
{
    $aliases = [
        'event_id' => 'event_id',
        'question_id' => 'question_id',
        'award_id' => 'question_id',
        'choice_id' => 'choice_id',
        'business_id' => 'choice_id',
        'establishment_id' => 'choice_id',
        'lgu_head_1' => 'lgu_1',
        'lgu_1' => 'lgu_1',
        'lgu1' => 'lgu_1',
        'lgu_head_2' => 'lgu_2',
        'lgu_2' => 'lgu_2',
        'lgu2' => 'lgu_2',
        'bplo' => 'bplo',
        'ledipo' => 'ledipo',
        'orcham' => 'orcham',
        'average' => 'average',
        'average_system' => 'average',
        'twg_average' => 'average',
    ];
    $map = [];
    foreach ($headerRow as $i => $label) {
        $norm = twg_sheet_normalize_header((string) $label);
        if ($norm === '' || !isset($aliases[$norm])) {
            continue;
        }
        $key = $aliases[$norm];
        if (!isset($map[$key])) {
            $map[$key] = (int) $i;
        }
    }
    return $map;
}

/**
 * @return list<array<string, mixed>>
 */
function twg_sheet_fetch_rows(mysqli $conn, int $event_id, ?int $question_id = null, ?int $choice_id = null): array
{
    twg_member_scores_ensure_schema($conn);
    $members = twg_member_keys();

    $catActive = admin_active_category_sql($conn, 'cat');
    $qActive = admin_active_question_sql($conn, 'q');

    $sql = "
        SELECT cat.event_id,
               cat.category_name,
               q.question_id,
               q.question_name,
               ch.choice_id,
               ch.choice_name
        FROM tbl_questions q
        INNER JOIN tbl_categories cat ON cat.category_id = q.category_id
        INNER JOIN tbl_question_choices qc ON qc.question_id = q.question_id
        INNER JOIN tbl_choices ch ON ch.choice_id = qc.choice_id AND ch.event_id = cat.event_id
        WHERE cat.event_id = ? AND {$catActive} AND {$qActive}
    ";
    $types = 'i';
    $params = [$event_id];
    if ($question_id !== null && $question_id > 0) {
        $sql .= ' AND q.question_id = ?';
        $types .= 'i';
        $params[] = $question_id;
    }
    if ($choice_id !== null && $choice_id > 0) {
        $sql .= ' AND ch.choice_id = ?';
        $types .= 'i';
        $params[] = $choice_id;
    }
    $sql .= ' ORDER BY cat.category_name ASC, q.question_name ASC, ch.choice_name ASC';

    $st = $conn->prepare($sql);
    if (!$st) {
        return [];
    }
    $st->bind_param($types, ...$params);
    $st->execute();
    $res = $st->get_result();
    $linked = [];
    while ($row = $res->fetch_assoc()) {
        $linked[] = $row;
    }
    $st->close();

    $idsByQuestion = [];
    foreach ($linked as $row) {
        $qid = (int) ($row['question_id'] ?? 0);
        $cid = (int) ($row['choice_id'] ?? 0);
        if ($qid > 0 && $cid > 0) {
            $idsByQuestion[$qid][$cid] = true;
        }
    }

    $scoreMap = [];
    foreach ($idsByQuestion as $qid => $choiceSet) {
        $ids = array_map('intval', array_keys($choiceSet));
        if ($ids === []) {
            continue;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $bindTypes = 'i' . str_repeat('i', count($ids));
        $sqlScores = "SELECT choice_id, member_key, score
                      FROM tbl_twg_member_scores
                      WHERE question_id = ? AND choice_id IN ($ph)";
        $ss = $conn->prepare($sqlScores);
        if (!$ss) {
            continue;
        }
        $ss->bind_param($bindTypes, $qid, ...$ids);
        $ss->execute();
        $sres = $ss->get_result();
        while ($r = $sres->fetch_assoc()) {
            $scoreMap[$qid][(int) $r['choice_id']][(string) $r['member_key']] = results_formula_round((float) $r['score'], 2);
        }
        $ss->close();
    }

    $out = [];
    foreach ($linked as $row) {
        $qid = (int) ($row['question_id'] ?? 0);
        $cid = (int) ($row['choice_id'] ?? 0);
        $scores = [];
        $filled = [];
        foreach ($members as $key) {
            $val = $scoreMap[$qid][$cid][$key] ?? null;
            $scores[$key] = $val;
            if ($val !== null) {
                $filled[] = $val;
            }
        }
        $avg = $filled === [] ? null : results_formula_round(array_sum($filled) / count($filled), 2);
        $out[] = [
            'event_id' => (int) ($row['event_id'] ?? $event_id),
            'question_id' => $qid,
            'choice_id' => $cid,
            'category' => (string) ($row['category_name'] ?? ''),
            'award' => (string) ($row['question_name'] ?? ''),
            'establishment' => (string) ($row['choice_name'] ?? ''),
            'scores' => $scores,
            'average' => $avg,
        ];
    }
    return $out;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<list<string|int|float|null>>
 */
function twg_sheet_to_table(array $rows): array
{
    $labels = twg_sheet_column_labels();
    $keys = twg_sheet_column_keys();
    $table = [array_map(static fn(string $k): string => $labels[$k], $keys)];
    foreach ($rows as $row) {
        $line = [];
        foreach ($keys as $key) {
            if (in_array($key, twg_member_keys(), true)) {
                $line[] = $row['scores'][$key] ?? '';
                continue;
            }
            if ($key === 'average') {
                $line[] = $row['average'] ?? '';
                continue;
            }
            $line[] = $row[$key] ?? '';
        }
        $table[] = $line;
    }
    return $table;
}

function twg_sheet_instruction_lines(): array
{
    return [
        ['TWG onsite scoresheet'],
        [''],
        ['How to use'],
        ['1. Download this file before the site visit.'],
        ['2. Do not change event_id, question_id, or choice_id. Those columns match the system.'],
        ['3. Fill LGU Head 1, LGU Head 2, BPLO, LEDIPO, and ORCHAM with scores from 1 to 10.'],
        ['4. Leave a cell blank if that member has not scored yet. Blank cells are skipped on import and will not erase existing scores.'],
        ['5. Average is computed by the system. You do not need to fill it.'],
        ['6. After the visit, import this same file on Reports → TWG Evaluation.'],
        [''],
        ['Members'],
        ['LGU Head 1, LGU Head 2, BPLO, LEDIPO, ORCHAM'],
        [''],
        ['Scoring'],
        ['Each member scores 1–10. The average of entered scores is the TWG 30% on Results.'],
    ];
}

/**
 * @param list<list<mixed>> $table
 * @return array{ok:bool,message:string,saved:int,skipped:int,errors:list<string>}
 */
function twg_sheet_import_table(mysqli $conn, int $event_id, array $table): array
{
    if ($table === []) {
        return ['ok' => false, 'message' => 'The file is empty.', 'saved' => 0, 'skipped' => 0, 'errors' => []];
    }

    $headerIdx = 0;
    $map = [];
    foreach ($table as $i => $row) {
        $map = twg_sheet_header_index_map(is_array($row) ? $row : []);
        if (isset($map['choice_id'], $map['question_id']) || (isset($map['choice_id']) && isset($map['lgu_1']))) {
            $headerIdx = (int) $i;
            break;
        }
        $map = [];
    }
    if ($map === [] || !isset($map['choice_id'])) {
        return [
            'ok' => false,
            'message' => 'Could not find a TWG scoresheet. Use the file downloaded from this page.',
            'saved' => 0,
            'skipped' => 0,
            'errors' => [],
        ];
    }
    if (!isset($map['question_id'])) {
        return [
            'ok' => false,
            'message' => 'The file is missing question_id. Download a fresh scoresheet and try again.',
            'saved' => 0,
            'skipped' => 0,
            'errors' => [],
        ];
    }

    $memberCols = [];
    foreach (twg_member_keys() as $key) {
        if (isset($map[$key])) {
            $memberCols[$key] = $map[$key];
        }
    }
    if ($memberCols === []) {
        return [
            'ok' => false,
            'message' => 'The file has no TWG member score columns.',
            'saved' => 0,
            'skipped' => 0,
            'errors' => [],
        ];
    }

    $saved = 0;
    $skipped = 0;
    $errors = [];
    $maxErrors = 40;

    for ($r = $headerIdx + 1, $n = count($table); $r < $n; $r++) {
        $row = $table[$r];
        if (!is_array($row)) {
            continue;
        }
        $displayRow = $r + 1;
        $qid = (int) ($row[$map['question_id']] ?? 0);
        $cid = (int) ($row[$map['choice_id']] ?? 0);
        if ($qid <= 0 && $cid <= 0) {
            $skipped++;
            continue;
        }
        if ($qid <= 0 || $cid <= 0) {
            $errors[] = "Row {$displayRow}: missing question_id or choice_id.";
            if (count($errors) >= $maxErrors) {
                break;
            }
            continue;
        }
        if (isset($map['event_id'])) {
            $fileEvent = (int) ($row[$map['event_id']] ?? 0);
            if ($fileEvent > 0 && $fileEvent !== $event_id) {
                $errors[] = "Row {$displayRow}: this row belongs to a different event.";
                if (count($errors) >= $maxErrors) {
                    break;
                }
                continue;
            }
        }
        if (!twg_question_in_event($conn, $event_id, $qid)) {
            $errors[] = "Row {$displayRow}: award {$qid} is not in the active event.";
            if (count($errors) >= $maxErrors) {
                break;
            }
            continue;
        }
        if (!twg_choice_linked_to_question($conn, $qid, $cid)) {
            $errors[] = "Row {$displayRow}: business {$cid} is not linked to that award.";
            if (count($errors) >= $maxErrors) {
                break;
            }
            continue;
        }

        $rowSaved = 0;
        foreach ($memberCols as $memberKey => $col) {
            $raw = $row[$col] ?? '';
            if ($raw === null) {
                $skipped++;
                continue;
            }
            $raw = trim((string) $raw);
            if ($raw === '') {
                $skipped++;
                continue;
            }
            if (!is_numeric($raw)) {
                $errors[] = "Row {$displayRow} ({$memberKey}): score must be a number from 1 to 10.";
                continue;
            }
            $score = (float) $raw;
            if (twg_get_member_score($conn, $qid, $cid, $memberKey) !== null) {
                $skipped++;
                continue;
            }
            $result = twg_save_member_score($conn, $qid, $cid, $memberKey, $score);
            if (!$result['ok']) {
                $errors[] = "Row {$displayRow} ({$memberKey}): " . $result['message'];
                continue;
            }
            $rowSaved++;
            $saved++;
        }
        if ($rowSaved === 0 && $errors === []) {
            // all blank member cells
        }
    }

    $ok = $saved > 0 || $errors === [];
    $message = $saved > 0
        ? "Imported {$saved} member score" . ($saved === 1 ? '' : 's') . '.'
        : ($errors === [] ? 'No new scores to import. Blank cells are skipped.' : 'Could not import scores.');
    return [
        'ok' => $ok,
        'message' => $message,
        'saved' => $saved,
        'skipped' => $skipped,
        'errors' => $errors,
    ];
}

function twg_sheet_autoload(): ?string
{
    $candidates = [
        dirname(__DIR__) . '/vendor/autoload.php',
        dirname(__DIR__, 2) . '/vendor/autoload.php',
        dirname(__DIR__, 3) . '/vendor/autoload.php',
        ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/vendor/autoload.php',
    ];
    foreach ($candidates as $p) {
        if (is_file($p)) {
            require_once $p;
            return $p;
        }
    }
    return null;
}
