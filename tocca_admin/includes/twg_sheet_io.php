<?php
declare(strict_types=1);

require_once __DIR__ . '/results_formula.php';
require_once __DIR__ . '/admin_schema.php';
require_once __DIR__ . '/twg_sheet_rubric_io.php';

/**
 * Round-trip TWG scoresheet for onsite (paper) scoring.
 * Export includes scores already saved in the system. Yellow cells are still
 * to score; filled cells stay locked. Empty cells on import are skipped so
 * partial sheets do not wipe scores.
 */

function twg_sheet_members(): array
{
    $conn = (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) ? $GLOBALS['conn'] : null;
    return twg_member_definitions($conn);
}

function twg_sheet_column_keys(): array
{
    $scoreKeys = array_map(static fn(array $m): string => (string) $m['key'], twg_sheet_members());
    return array_merge(
        ['establishment', 'category', 'award'],
        $scoreKeys,
        ['average', 'event_id', 'question_id', 'choice_id', 'ballot_entry_id']
    );
}

function twg_sheet_column_labels(): array
{
    $labels = [
        'establishment' => 'Establishment',
        'category' => 'Category',
        'award' => 'Award',
        'average' => 'Average',
        'event_id' => 'event_id',
        'question_id' => 'question_id',
        'choice_id' => 'choice_id',
        'ballot_entry_id' => 'ballot_entry_id',
    ];
    foreach (twg_sheet_members() as $m) {
        $labels[$m['key']] = (string) ($m['label'] !== '' ? $m['label'] : $m['short']);
    }
    return $labels;
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
        'ballot_entry_id' => 'ballot_entry_id',
        'entry_id' => 'ballot_entry_id',
        'product_id' => 'ballot_entry_id',
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
    foreach (twg_sheet_members() as $m) {
        $key = (string) $m['key'];
        $aliases[$key] = $key;
        $aliases[twg_sheet_normalize_header((string) $m['label'])] = $key;
        $aliases[twg_sheet_normalize_header((string) $m['short'])] = $key;
    }
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
    $sql .= ' ORDER BY ch.choice_name ASC, cat.category_name ASC, q.question_name ASC';

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

    $out = [];
    $helpers = __DIR__ . '/award_entry_helpers.php';
    $reasons = __DIR__ . '/award_removal_reasons.php';
    if (is_file($helpers)) {
        require_once $helpers;
    }
    if (is_file($reasons)) {
        require_once $reasons;
    }
    $entryCache = [];
    foreach ($linked as $row) {
        $qid = (int) ($row['question_id'] ?? 0);
        $cid = (int) ($row['choice_id'] ?? 0);
        $scores = [];
        foreach ($members as $key) {
            $scores[$key] = null;
        }
        $base = [
            'event_id' => (int) ($row['event_id'] ?? $event_id),
            'question_id' => $qid,
            'choice_id' => $cid,
            'ballot_entry_id' => 0,
            'category' => (string) ($row['category_name'] ?? ''),
            'award' => (string) ($row['question_name'] ?? ''),
            'establishment' => (string) ($row['choice_name'] ?? ''),
            'scores' => $scores,
            'average' => null,
        ];
        $entries = [];
        if ($cid > 0 && function_exists('award_entry_score_rows_for_choice')) {
            if (!isset($entryCache[$cid])) {
                $entryCache[$cid] = award_entry_score_rows_for_choice($conn, $cid);
            }
            $entries = is_array($entryCache[$cid][$qid] ?? null) ? $entryCache[$cid][$qid] : [];
        }
        if ($entries === []) {
            $out[] = $base;
            continue;
        }
        foreach ($entries as $entry) {
            $eid = (int) ($entry['ballot_entry_id'] ?? 0);
            $name = trim((string) ($entry['entry_name'] ?? ''));
            $kindLabel = twg_entry_kind_label((string) ($entry['entry_kind'] ?? 'product'));
            $line = $base;
            $line['ballot_entry_id'] = $eid;
            $line['award'] = trim((string) $base['award'])
                . ($name !== '' ? ("\n" . $kindLabel . ': ' . $name) : '');
            $out[] = $line;
        }
    }
    return twg_sheet_hydrate_saved_scores($conn, $out, $choice_id);
}

/**
 * Fill each row's score cells from tbl_twg_member_scores / entry scores
 * so a downloaded sheet shows what is already saved.
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function twg_sheet_hydrate_saved_scores(mysqli $conn, array $rows, ?int $choice_id = null): array
{
    $members = twg_member_keys();
    $questionIds = [];
    foreach ($rows as $row) {
        $qid = (int) ($row['question_id'] ?? 0);
        if ($qid > 0) {
            $questionIds[$qid] = $qid;
        }
    }
    $questionIds = array_values($questionIds);
    if ($questionIds === [] || $members === []) {
        return $rows;
    }

    $titleMap = [];
    $ph = implode(',', array_fill(0, count($questionIds), '?'));
    $sql = "SELECT question_id, choice_id, member_key, score
            FROM tbl_twg_member_scores
            WHERE question_id IN ($ph)";
    $types = str_repeat('i', count($questionIds));
    $params = $questionIds;
    if ($choice_id !== null && $choice_id > 0) {
        $sql .= ' AND choice_id = ?';
        $types .= 'i';
        $params[] = $choice_id;
    }
    $st = $conn->prepare($sql);
    if ($st) {
        $st->bind_param($types, ...$params);
        $st->execute();
        $res = $st->get_result();
        while ($row = $res->fetch_assoc()) {
            $qid = (int) ($row['question_id'] ?? 0);
            $cid = (int) ($row['choice_id'] ?? 0);
            $key = strtolower(trim((string) ($row['member_key'] ?? '')));
            if ($qid <= 0 || $cid <= 0 || $key === '') {
                continue;
            }
            $titleMap[$qid][$cid][$key] = function_exists('results_formula_round')
                ? results_formula_round((float) $row['score'], 2)
                : round((float) $row['score'], 2);
        }
        $st->close();
    }

    $entryMap = function_exists('twg_fetch_entry_member_score_map')
        ? twg_fetch_entry_member_score_map($conn, $questionIds, $choice_id)
        : [];

    foreach ($rows as &$row) {
        $qid = (int) ($row['question_id'] ?? 0);
        $cid = (int) ($row['choice_id'] ?? 0);
        $eid = (int) ($row['ballot_entry_id'] ?? 0);
        $src = $eid > 0
            ? ($entryMap[$qid][$cid][$eid] ?? [])
            : ($titleMap[$qid][$cid] ?? []);
        foreach ($members as $key) {
            if (isset($src[$key]) && $src[$key] !== null && $src[$key] !== '') {
                $row['scores'][$key] = (float) $src[$key];
            }
        }
    }
    unset($row);
    return $rows;
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
    $members = twg_sheet_members();
    $names = array_map(static fn(array $m): string => (string) $m['label'], $members);
    $nameLine = $names !== [] ? implode(', ', $names) : 'the current TWG criteria';
    $weightBits = [];
    foreach ($members as $m) {
        $weightBits[] = $m['label'] . ' ' . rtrim(rtrim(number_format((float) $m['weight'], 3, '.', ''), '0'), '.');
    }
    return [
        ['TWG onsite scoresheet'],
        [''],
        ['Saved scores from the system are filled in. Yellow cells start at 0 and are still editable. Gray cells are already saved and stay locked on import.'],
        [''],
        ['How to use'],
        ['1. Download this file before or during the site visit. Print it, or fill it on a laptop/tablet.'],
        ['2. Rows are grouped by establishment, then category and award. Awards with remaining products have one row per product.'],
        ['3. Change only the yellow cells that are not zero: ' . $nameLine . '. Gray cells are already saved — leave them as they are.'],
        ['4. Each score starts at 0. Enter 0 to 100. A 0 counts in the average.'],
        ['5. Average is an Excel formula. It updates as you type. Do not type over it.'],
        ['6. Hidden columns on the right (event_id, question_id, choice_id, ballot_entry_id) match rows back to the system. Do not change them.'],
        ['7. After the visit, import this same file on Reports → TWG Evaluation.'],
        ['8. Import updates saved scores unless the business is already confirmed for public voting.'],
        [''],
        ['Criteria (edit these on Reports → TWG Evaluation)'],
        [$nameLine],
        ['Weights (relative): ' . implode(', ', $weightBits)],
        [''],
        ['Scoring'],
        ['Each judge total is graded 0–100. Product awards are scored per product; the award TWG average is the mean of those product averages. That award average is the TWG 40% on Results.'],
    ];
}

/**
 * @param list<array<string, mixed>> $rows
 * @param array{event_name?:string,scope_label?:string,generated_at?:string} $meta
 */
function twg_sheet_populate_xlsx(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet, array $rows, array $meta = []): void
{
    $eventName = trim((string) ($meta['event_name'] ?? 'TWG Evaluation'));
    $scopeLabel = trim((string) ($meta['scope_label'] ?? ''));
    $generatedAt = trim((string) ($meta['generated_at'] ?? ''));
    $labels = twg_sheet_column_labels();
    $keys = twg_sheet_column_keys();
    $memberKeys = twg_member_keys();
    $headerRow = 4;
    $firstDataRow = 5;
    $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($keys));

    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Score sheet');

    $title = 'TWG Score Sheet';
    if ($eventName !== '') {
        $title .= ' — ' . $eventName;
    }
    $sheet->setCellValue('A1', $title);
    $sheet->mergeCells('A1:' . $lastCol . '1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A1')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

    $subtitle = 'Saved scores are filled in. Yellow cells start at 0 — change only the scores that are not zero. Gray cells are already saved. Average updates automatically. Hidden ID columns on the right are for import — do not change them.';
    if ($scopeLabel !== '') {
        $subtitle = $scopeLabel . '  ·  ' . $subtitle;
    }
    if ($generatedAt !== '') {
        $subtitle .= '  Generated ' . $generatedAt . '.';
    }
    $sheet->setCellValue('A2', $subtitle);
    $sheet->mergeCells('A2:' . $lastCol . '2');
    $sheet->getStyle('A2')->getAlignment()->setWrapText(true);
    $sheet->getRowDimension(1)->setRowHeight(24);
    $sheet->getRowDimension(2)->setRowHeight(32);

    $members = twg_sheet_members();
    $scoreFirstIdx = null;
    $scoreLastIdx = null;
    $avgIdx = null;
    foreach ($keys as $i => $key) {
        $colNum = $i + 1;
        if (in_array($key, $memberKeys, true)) {
            if ($scoreFirstIdx === null) {
                $scoreFirstIdx = $colNum;
            }
            $scoreLastIdx = $colNum;
        }
        if ($key === 'average') {
            $avgIdx = $colNum;
        }
    }
    $scoreFirstIdx = $scoreFirstIdx ?? 4;
    $scoreLastIdx = $scoreLastIdx ?? $scoreFirstIdx;
    $avgIdx = $avgIdx ?? ($scoreLastIdx + 1);
    $scoreFirst = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($scoreFirstIdx);
    $scoreLast = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($scoreLastIdx);
    $avgCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($avgIdx);
    $weights = array_map(static fn(array $m): float => (float) ($m['weight'] ?? 1), $members);
    $weightsEqual = $weights === [] || (max($weights) - min($weights) < 0.0001);
    $weightConst = '{' . implode(',', array_map(static fn(float $w): string => rtrim(rtrim(number_format($w, 3, '.', ''), '0'), '.'), $weights)) . '}';
    $avgFormula = static function (int $r) use ($scoreFirst, $scoreLast, $weightsEqual, $weightConst): string {
        $range = $scoreFirst . $r . ':' . $scoreLast . $r;
        if ($weightsEqual) {
            return '=IF(COUNT(' . $range . ')=0,"",ROUND(AVERAGE(' . $range . '),2))';
        }
        return '=IFERROR(ROUND(SUMPRODUCT((' . $range . ')*' . $weightConst . ')/SUMPRODUCT((' . $range . '<>"")*' . $weightConst . '),2),"")';
    };

    foreach ($keys as $i => $key) {
        $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
        $sheet->setCellValue($col . $headerRow, $labels[$key]);
    }
    $headerRange = 'A' . $headerRow . ':' . $lastCol . $headerRow;
    $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $sheet->getStyle($headerRange)->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setRGB('1F4E79');
    $sheet->getStyle($headerRange)->getAlignment()
        ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
        ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER)
        ->setWrapText(true);
    $sheet->getRowDimension($headerRow)->setRowHeight(22);

    $excelRow = $firstDataRow;
    $lastEstablishment = null;
    $dataRows = [];
    foreach ($rows as $row) {
        $establishment = (string) ($row['establishment'] ?? '');
        if ($lastEstablishment !== null && $establishment !== $lastEstablishment) {
            $excelRow++;
        }
        $lastEstablishment = $establishment;
        $dataRows[] = $excelRow;

        foreach ($keys as $i => $key) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $cell = $col . $excelRow;
            if (in_array($key, $memberKeys, true)) {
                $val = $row['scores'][$key] ?? null;
                if ($val !== null && $val !== '') {
                    $sheet->setCellValue($cell, (float) $val);
                } else {
                    $sheet->setCellValue($cell, null);
                }
                continue;
            }
            if ($key === 'average') {
                $sheet->setCellValue($cell, $avgFormula($excelRow));
                continue;
            }
            $sheet->setCellValue($cell, $row[$key] ?? '');
        }
        $excelRow++;
    }

    $lastDataRow = $dataRows !== [] ? max($dataRows) : $headerRow;
    $sheet->freezePane('A' . $firstDataRow);
    $sheet->setAutoFilter('A' . $headerRow . ':' . $lastCol . $lastDataRow);

    $sheet->getStyle('A' . $firstDataRow . ':C' . $lastDataRow)->getAlignment()
        ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER)
        ->setWrapText(true);
    $sheet->getStyle($scoreFirst . $firstDataRow . ':' . $avgCol . $lastDataRow)->getAlignment()
        ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
        ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
    $sheet->getStyle($scoreFirst . $firstDataRow . ':' . $avgCol . $lastDataRow)->getNumberFormat()->setFormatCode('0.00');

    if ($dataRows !== []) {
        foreach ($dataRows as $r) {
            for ($c = $scoreFirstIdx; $c <= $scoreLastIdx; $c++) {
                $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
                $val = $sheet->getCell($col . $r)->getValue();
                $filled = $val !== null && $val !== '';
                $sheet->getStyle($col . $r)->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setRGB($filled ? 'E9EDF2' : 'FFF3B0');
                if (!$filled) {
                    $sheet->setCellValue($col . $r, 0);
                }
            }
            $sheet->getStyle($avgCol . $r)->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRGB('E8EEF5');
            $sheet->getStyle($avgCol . $r)->getFont()->setBold(true);
            $awardText = (string) ($sheet->getCell('C' . $r)->getValue() ?? '');
            $sheet->getRowDimension($r)->setRowHeight(str_contains($awardText, "\n") ? 36 : 20);
        }

        $validation = $sheet->getCell($scoreFirst . $dataRows[0])->getDataValidation();
        $validation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_DECIMAL);
        $validation->setErrorStyle(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP);
        $validation->setAllowBlank(true);
        $validation->setShowInputMessage(true);
        $validation->setShowErrorMessage(true);
        $validation->setShowDropDown(false);
        $validation->setOperator(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::OPERATOR_BETWEEN);
        $validation->setFormula1('0');
        $validation->setFormula2('100');
        $validation->setPromptTitle('Score');
        $validation->setPrompt('Enter a score from 0 to 100. Cells start at 0.');
        $validation->setErrorTitle('Invalid score');
        $validation->setError('Score must be from 0 to 100.');
        foreach ($dataRows as $r) {
            $sheet->setDataValidation($scoreFirst . $r . ':' . $scoreLast . $r, clone $validation);
        }
    }

    $sheet->getStyle('A' . $headerRow . ':' . $lastCol . $lastDataRow)->getBorders()->getAllBorders()
        ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
        ->getColor()->setRGB('C5CDD8');

    $sheet->getColumnDimension('A')->setWidth(32);
    $sheet->getColumnDimension('B')->setWidth(18);
    $sheet->getColumnDimension('C')->setWidth(32);
    for ($i = $scoreFirstIdx; $i <= $avgIdx; $i++) {
        $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i))->setWidth(13);
    }
    $idStart = $avgIdx + 1;
    for ($i = $idStart; $i <= count($keys); $i++) {
        $hiddenCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
        $sheet->getColumnDimension($hiddenCol)->setWidth(12);
        $sheet->getColumnDimension($hiddenCol)->setVisible(false);
    }

    $sheet->getComment($scoreFirst . $headerRow)->getText()->createTextRun('Yellow cells start at 0. Change only the scores that are not zero. Gray cells are already saved.');
    $sheet->getComment($avgCol . $headerRow)->getText()->createTextRun('Excel formula. Do not type over this column.');

    $pageSetup = $sheet->getPageSetup();
    $pageSetup->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
    $pageSetup->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);
    $pageSetup->setFitToPage(true);
    $pageSetup->setFitToWidth(1);
    $pageSetup->setFitToHeight(0);
    $pageSetup->setRowsToRepeatAtTopByStartAndEnd($headerRow, $headerRow);
    $sheet->getPageMargins()->setTop(0.5)->setBottom(0.5)->setLeft(0.4)->setRight(0.4);
    $sheet->getHeaderFooter()->setOddHeader('&C&B TWG Score Sheet');
    $sheet->getHeaderFooter()->setOddFooter('&LYellow = still to score · gray = already saved&C&P of &N&RImport this file after the visit');
    $sheet->getPageSetup()->setPrintArea('A1:' . $avgCol . max($lastDataRow, $headerRow));

    $sheet->getStyle('A1:' . $lastCol . $lastDataRow)->getProtection()
        ->setLocked(\PhpOffice\PhpSpreadsheet\Style\Protection::PROTECTION_PROTECTED);
    if ($dataRows !== []) {
        foreach ($dataRows as $r) {
            for ($c = $scoreFirstIdx; $c <= $scoreLastIdx; $c++) {
                $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
                $val = $sheet->getCell($col . $r)->getValue();
                if ($val === null || $val === '') {
                    $sheet->getStyle($col . $r)->getProtection()
                        ->setLocked(\PhpOffice\PhpSpreadsheet\Style\Protection::PROTECTION_UNPROTECTED);
                }
            }
        }
    }
    $protection = $sheet->getProtection();
    $protection->setSheet(true);
    $protection->setSort(true);
    $protection->setAutoFilter(true);
    $protection->setInsertRows(false);
    $protection->setDeleteRows(false);

    $info = $spreadsheet->createSheet();
    $info->setTitle('Instructions');
    $info->fromArray(twg_sheet_instruction_lines(), null, 'A1');
    $info->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $info->getColumnDimension('A')->setWidth(110);
    $info->getProtection()->setSheet(true);
    $spreadsheet->setActiveSheetIndex(0);
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
        $hasScoreCol = false;
        foreach (twg_member_keys() as $memberKey) {
            if (isset($map[$memberKey])) {
                $hasScoreCol = true;
                break;
            }
        }
        if (isset($map['choice_id'], $map['question_id']) || (isset($map['choice_id']) && $hasScoreCol)) {
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
            'message' => 'The file has no TWG criterion score columns.',
            'saved' => 0,
            'skipped' => 0,
            'errors' => [],
        ];
    }

    $saved = 0;
    $skipped = 0;
    $errors = [];
    $maxErrors = 40;
    $entryCache = [];
    $helpers = __DIR__ . '/award_entry_helpers.php';
    if (is_file($helpers)) {
        require_once $helpers;
    }

    for ($r = $headerIdx + 1, $n = count($table); $r < $n; $r++) {
        $row = $table[$r];
        if (!is_array($row)) {
            continue;
        }
        $displayRow = $r + 1;
        $qid = (int) ($row[$map['question_id']] ?? 0);
        $cid = (int) ($row[$map['choice_id']] ?? 0);
        $ballotEntryId = isset($map['ballot_entry_id']) ? (int) ($row[$map['ballot_entry_id']] ?? 0) : 0;
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

        if ($cid > 0 && function_exists('award_entry_score_rows_for_choice') && !isset($entryCache[$cid])) {
            $entryCache[$cid] = award_entry_score_rows_for_choice($conn, $cid);
        }
        $productEntries = is_array($entryCache[$cid][$qid] ?? null) ? $entryCache[$cid][$qid] : [];
        if ($productEntries !== [] && $ballotEntryId <= 0) {
            $errors[] = "Row {$displayRow}: this award is scored per product. Download a fresh scoresheet and fill each product row.";
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
            if ($raw === '' || str_starts_with($raw, '=')) {
                $skipped++;
                continue;
            }
            if (!is_numeric($raw)) {
                $errors[] = "Row {$displayRow} ({$memberKey}): score must be a number from 0 to 100.";
                continue;
            }
            $score = (float) $raw;
            if (function_exists('twg_choice_scores_locked') && twg_choice_scores_locked($conn, $cid)) {
                $skipped++;
                continue;
            }
            if ($ballotEntryId > 0) {
                $result = twg_save_entry_member_score($conn, $qid, $cid, $ballotEntryId, $memberKey, $score);
            } else {
                $result = twg_save_member_score($conn, $qid, $cid, $memberKey, $score);
            }
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
