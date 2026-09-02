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
        'establishment',
        'category',
        'award',
        'lgu_1',
        'lgu_2',
        'bplo',
        'ledipo',
        'orcham',
        'average',
        'event_id',
        'question_id',
        'choice_id',
    ];
}

function twg_sheet_column_labels(): array
{
    return [
        'establishment' => 'Establishment',
        'category' => 'Category',
        'award' => 'Award',
        'lgu_1' => 'LGU Head 1',
        'lgu_2' => 'LGU Head 2',
        'bplo' => 'BPLO',
        'ledipo' => 'LEDIPO',
        'orcham' => 'ORCHAM',
        'average' => 'Average',
        'event_id' => 'event_id',
        'question_id' => 'question_id',
        'choice_id' => 'choice_id',
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
    foreach ($linked as $row) {
        $qid = (int) ($row['question_id'] ?? 0);
        $cid = (int) ($row['choice_id'] ?? 0);
        $scores = [];
        foreach ($members as $key) {
            $scores[$key] = null;
        }
        $out[] = [
            'event_id' => (int) ($row['event_id'] ?? $event_id),
            'question_id' => $qid,
            'choice_id' => $cid,
            'category' => (string) ($row['category_name'] ?? ''),
            'award' => (string) ($row['question_name'] ?? ''),
            'establishment' => (string) ($row['choice_name'] ?? ''),
            'scores' => $scores,
            'average' => null,
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
        ['This file is a blank scoresheet. Existing system scores are not included.'],
        [''],
        ['How to use'],
        ['1. Download this file before the site visit. Print it, or fill it on a laptop/tablet.'],
        ['2. Rows are grouped by establishment, then category and award.'],
        ['3. Fill only the yellow cells: LGU Head 1, LGU Head 2, BPLO, LEDIPO, and ORCHAM.'],
        ['4. Enter a score from 1 to 10. Leave a cell blank if that member has not scored yet.'],
        ['5. Average is an Excel formula. It updates as you type and ignores blank cells. Do not type over it.'],
        ['6. Hidden columns on the right (event_id, question_id, choice_id) match rows back to the system. Do not change them.'],
        ['7. After the visit, import this same file on Reports → TWG Evaluation.'],
        ['8. Import skips blank cells and will not overwrite scores already saved in the system.'],
        [''],
        ['Members'],
        ['LGU Head 1, LGU Head 2, BPLO, LEDIPO, ORCHAM'],
        [''],
        ['Scoring'],
        ['Each member scores 1–10. The average of entered scores is the TWG 40% on Results.'],
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

    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Score sheet');

    $title = 'TWG Score Sheet';
    if ($eventName !== '') {
        $title .= ' — ' . $eventName;
    }
    $sheet->setCellValue('A1', $title);
    $sheet->mergeCells('A1:I1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A1')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

    $subtitle = 'Blank scoresheet. Fill yellow cells with scores 1–10. Average updates automatically. Hidden ID columns on the right are for import — do not change them.';
    if ($scopeLabel !== '') {
        $subtitle = $scopeLabel . '  ·  ' . $subtitle;
    }
    if ($generatedAt !== '') {
        $subtitle .= '  Generated ' . $generatedAt . '.';
    }
    $sheet->setCellValue('A2', $subtitle);
    $sheet->mergeCells('A2:I2');
    $sheet->getStyle('A2')->getAlignment()->setWrapText(true);
    $sheet->getRowDimension(1)->setRowHeight(24);
    $sheet->getRowDimension(2)->setRowHeight(32);

    foreach ($keys as $i => $key) {
        $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
        $sheet->setCellValue($col . $headerRow, $labels[$key]);
    }
    $headerRange = 'A' . $headerRow . ':L' . $headerRow;
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
                $sheet->setCellValue($cell, null);
                continue;
            }
            if ($key === 'average') {
                $sheet->setCellValue($cell, '=IF(COUNT(D' . $excelRow . ':H' . $excelRow . ')=0,"",ROUND(AVERAGE(D' . $excelRow . ':H' . $excelRow . '),2))');
                continue;
            }
            $sheet->setCellValue($cell, $row[$key] ?? '');
        }
        $excelRow++;
    }

    $lastDataRow = $dataRows !== [] ? max($dataRows) : $headerRow;
    $sheet->freezePane('A' . $firstDataRow);
    $sheet->setAutoFilter('A' . $headerRow . ':L' . $lastDataRow);

    $sheet->getStyle('A' . $firstDataRow . ':C' . $lastDataRow)->getAlignment()
        ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
    $sheet->getStyle('D' . $firstDataRow . ':I' . $lastDataRow)->getAlignment()
        ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
        ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
    $sheet->getStyle('D' . $firstDataRow . ':I' . $lastDataRow)->getNumberFormat()->setFormatCode('0.00');

    if ($dataRows !== []) {
        foreach ($dataRows as $r) {
            $sheet->getStyle('D' . $r . ':H' . $r)->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRGB('FFF3B0');
            $sheet->getStyle('I' . $r)->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRGB('E8EEF5');
            $sheet->getStyle('I' . $r)->getFont()->setBold(true);
            $sheet->getRowDimension($r)->setRowHeight(20);
        }

        $validation = $sheet->getCell('D' . $dataRows[0])->getDataValidation();
        $validation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_DECIMAL);
        $validation->setErrorStyle(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP);
        $validation->setAllowBlank(true);
        $validation->setShowInputMessage(true);
        $validation->setShowErrorMessage(true);
        $validation->setShowDropDown(false);
        $validation->setOperator(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::OPERATOR_BETWEEN);
        $validation->setFormula1('1');
        $validation->setFormula2('10');
        $validation->setPromptTitle('Score');
        $validation->setPrompt('Enter a score from 1 to 10, or leave blank.');
        $validation->setErrorTitle('Invalid score');
        $validation->setError('Score must be from 1 to 10.');
        foreach ($dataRows as $r) {
            $sheet->setDataValidation('D' . $r . ':H' . $r, clone $validation);
        }
    }

    $sheet->getStyle('A' . $headerRow . ':L' . $lastDataRow)->getBorders()->getAllBorders()
        ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
        ->getColor()->setRGB('C5CDD8');

    $widths = [
        'A' => 32, 'B' => 18, 'C' => 28,
        'D' => 13, 'E' => 13, 'F' => 12, 'G' => 12, 'H' => 12, 'I' => 12,
        'J' => 12, 'K' => 14, 'L' => 12,
    ];
    foreach ($widths as $col => $width) {
        $sheet->getColumnDimension($col)->setWidth($width);
    }
    foreach (['J', 'K', 'L'] as $hiddenCol) {
        $sheet->getColumnDimension($hiddenCol)->setVisible(false);
    }

    $sheet->getComment('D' . $headerRow)->getText()->createTextRun('Fill scores 1–10. Leave blank if not yet scored.');
    $sheet->getComment('I' . $headerRow)->getText()->createTextRun('Excel formula. Do not type over this column.');

    $pageSetup = $sheet->getPageSetup();
    $pageSetup->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
    $pageSetup->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);
    $pageSetup->setFitToPage(true);
    $pageSetup->setFitToWidth(1);
    $pageSetup->setFitToHeight(0);
    $pageSetup->setRowsToRepeatAtTopByStartAndEnd($headerRow, $headerRow);
    $sheet->getPageMargins()->setTop(0.5)->setBottom(0.5)->setLeft(0.4)->setRight(0.4);
    $sheet->getHeaderFooter()->setOddHeader('&C&B TWG Score Sheet');
    $sheet->getHeaderFooter()->setOddFooter('&LFill yellow cells 1–10&C&P of &N&RImport this file after the visit');
    $sheet->getPageSetup()->setPrintArea('A1:I' . max($lastDataRow, $headerRow));

    $sheet->getStyle('A1:L' . $lastDataRow)->getProtection()
        ->setLocked(\PhpOffice\PhpSpreadsheet\Style\Protection::PROTECTION_PROTECTED);
    if ($dataRows !== []) {
        foreach ($dataRows as $r) {
            $sheet->getStyle('D' . $r . ':H' . $r)->getProtection()
                ->setLocked(\PhpOffice\PhpSpreadsheet\Style\Protection::PROTECTION_UNPROTECTED);
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
            if ($raw === '' || str_starts_with($raw, '=')) {
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
