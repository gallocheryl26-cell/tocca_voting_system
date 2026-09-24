<?php
declare(strict_types=1);

/**
 * Official TWG tally workbook:
 * Judge 1..n tabs (Taste / Innovation / Value) then Overall Score
 * matching the client OVER-ALL TALLY SHEET.
 */

require_once __DIR__ . '/twg_rubric.php';

function twg_sheet_safe_tab_title(string $s, array $used): string
{
    $s = trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    $s = str_replace(['\\', '/', '*', '?', ':', '[', ']'], ' ', $s);
    $s = trim($s);
    if ($s === '') {
        $s = 'Sheet';
    }
    $s = function_exists('mb_substr') ? mb_substr($s, 0, 31) : substr($s, 0, 31);
    $base = $s;
    $n = 2;
    while (isset($used[strtolower($s)])) {
        $suffix = ' ' . $n;
        $keep = 31 - strlen($suffix);
        $s = (function_exists('mb_substr') ? mb_substr($base, 0, $keep) : substr($base, 0, $keep)) . $suffix;
        $n++;
    }
    return $s;
}

/**
 * @return list<int>
 */
function twg_sheet_event_question_ids(mysqli $conn, int $eventId): array
{
    $catActive = function_exists('admin_active_category_sql') ? admin_active_category_sql($conn, 'c') : '1=1';
    $qActive = function_exists('admin_active_question_sql') ? admin_active_question_sql($conn, 'q') : '1=1';
    $st = $conn->prepare(
        "SELECT q.question_id
         FROM tbl_questions q
         INNER JOIN tbl_categories c ON c.category_id = q.category_id
         WHERE c.event_id = ? AND {$catActive} AND {$qActive}
         ORDER BY c.category_name ASC, q.question_name ASC"
    );
    if (!$st) {
        return [];
    }
    $st->bind_param('i', $eventId);
    $st->execute();
    $res = $st->get_result();
    $ids = [];
    while ($row = $res->fetch_assoc()) {
        $ids[] = (int) $row['question_id'];
    }
    $st->close();
    return $ids;
}

/**
 * @return array{members:list<array<string,mixed>>,rubric:list<array<string,mixed>>,awards:list<array<string,mixed>>,has_entries:bool,single_award:bool}
 */
function twg_sheet_rubric_collect(mysqli $conn, int $eventId, ?int $questionId = null, ?int $choiceId = null): array
{
    $members = twg_member_definitions($conn, $eventId);
    $rubric = twg_rubric_for_event($conn, $eventId);
    $questionIds = $questionId && $questionId > 0
        ? [$questionId]
        : twg_sheet_event_question_ids($conn, $eventId);
    $awards = [];
    $hasEntries = false;
    foreach ($questionIds as $qid) {
        $sheet = twg_rubric_award_sheet($conn, $eventId, $qid);
        if (empty($sheet['award'])) {
            continue;
        }
        $rows = is_array($sheet['rows'] ?? null) ? $sheet['rows'] : [];
        if ($choiceId && $choiceId > 0) {
            $rows = array_values(array_filter(
                $rows,
                static fn(array $row): bool => (int) ($row['choice_id'] ?? 0) === $choiceId
            ));
            if ($rows === []) {
                continue;
            }
        }
        foreach ($rows as $row) {
            if (trim((string) ($row['entry_name'] ?? '')) !== '') {
                $hasEntries = true;
                break;
            }
        }
        $awards[] = [
            'question_id' => (int) $sheet['award']['question_id'],
            'question_name' => (string) $sheet['award']['question_name'],
            'category_name' => (string) $sheet['award']['category_name'],
            'rows' => $rows,
        ];
    }
    return [
        'members' => $members,
        'rubric' => $rubric,
        'awards' => $awards,
        'has_entries' => $hasEntries,
        'single_award' => count($awards) === 1,
    ];
}

function twg_sheet_excel_col(int $index): string
{
    return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index);
}

function twg_sheet_formula_ref(string $sheetTitle, string $cell): string
{
    return "'" . str_replace("'", "''", $sheetTitle) . "'!" . $cell;
}

function twg_sheet_item_header(array $item): string
{
    $max = (float) ($item['max'] ?? 0);
    $short = (string) ($item['short'] ?? $item['label'] ?? 'Item');
    return $max > 0
        ? ($short . ' (' . rtrim(rtrim(number_format($max, 1, '.', ''), '0'), '.') . ')')
        : $short;
}

function twg_sheet_tally_header_row(): int
{
    return 9;
}

function twg_sheet_tally_first_data_row(): int
{
    return 10;
}

/**
 * @param list<array<string,mixed>> $members
 * @param list<array<string,mixed>> $rubric
 * @return array<string,mixed>
 */
function twg_sheet_tally_columns(array $members, array $rubric, bool $showGroup, bool $hasEntries, string $kind): array
{
    $col = 1;
    $map = [
        'entry_no' => $col++,
    ];
    if ($showGroup) {
        $map['category'] = $col++;
        $map['award'] = $col++;
    }
    $map['business'] = $col++;
    if ($hasEntries) {
        $map['entry'] = $col++;
    }
    if ($kind === 'overall') {
        $map['judges'] = [];
        foreach ($members as $m) {
            $map['judges'][(string) ($m['key'] ?? '')] = $col++;
        }
        $map['weighted'] = $col++;
        $map['average'] = $col++;
        $map['ranking'] = $col++;
    } else {
        $map['items'] = [];
        foreach ($rubric as $item) {
            $key = strtolower((string) ($item['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $map['items'][$key] = $col++;
        }
        $map['total'] = $col++;
    }
    $map['last_visible'] = $col - 1;
    $map['event_id'] = $col++;
    $map['question_id'] = $col++;
    $map['choice_id'] = $col++;
    $map['ballot_entry_id'] = $col++;
    if ($kind !== 'overall') {
        $map['judge_key'] = $col++;
    }
    $map['last'] = $col - 1;
    return $map;
}

/**
 * @param array<string,mixed> $meta
 */
function twg_sheet_write_tally_banner(
    \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
    array $meta,
    int $lastVisible,
    string $sheetTitle,
    string $category,
    string $awardTitle
): void {
    $last = twg_sheet_excel_col(max(1, $lastVisible));
    $center = \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER;
    $right = \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT;
    $underline = \PhpOffice\PhpSpreadsheet\Style\Font::UNDERLINE_SINGLE;

    $sheet->mergeCells('A1:' . $last . '1');
    $sheet->setCellValue('A1', trim((string) ($meta['banner'] ?? 'TWG VALIDATION')) ?: 'TWG VALIDATION');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal($center);

    $when = trim((string) ($meta['datetime'] ?? $meta['generated_at'] ?? ''));
    $sheet->mergeCells('A2:' . $last . '2');
    $sheet->setCellValue('A2', $when);
    $sheet->getStyle('A2')->getAlignment()->setHorizontal($center);

    $venue = trim((string) ($meta['venue'] ?? $meta['event_name'] ?? ''));
    $sheet->mergeCells('A3:' . $last . '3');
    $sheet->setCellValue('A3', $venue);
    $sheet->getStyle('A3')->getAlignment()->setHorizontal($center);

    $sheet->mergeCells('A5:' . $last . '5');
    $sheet->setCellValue('A5', $sheetTitle);
    $sheet->getStyle('A5')->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('A5')->getAlignment()->setHorizontal($center);

    $split = max(2, (int) floor($lastVisible / 2));
    $sheet->setCellValue('A7', 'CATEGORY: ' . $category);
    $sheet->mergeCells('A7:' . twg_sheet_excel_col($split) . '7');
    $sheet->getStyle('A7')->getFont()->setBold(true)->setUnderline($underline);

    $awardCol = min($lastVisible, $split + 1);
    $sheet->setCellValue(twg_sheet_excel_col($awardCol) . '7', 'AWARD TITLE: ' . $awardTitle);
    $sheet->mergeCells(twg_sheet_excel_col($awardCol) . '7:' . $last . '7');
    $sheet->getStyle(twg_sheet_excel_col($awardCol) . '7')->getFont()->setBold(true)->setUnderline($underline);
    $sheet->getStyle(twg_sheet_excel_col($awardCol) . '7')->getAlignment()->setHorizontal($right);

    $sheet->getRowDimension(1)->setRowHeight(22);
    $sheet->getRowDimension(5)->setRowHeight(20);
    $sheet->getRowDimension(9)->setRowHeight(28);
}

/**
 * @param list<array<string,mixed>> $members
 */
function twg_sheet_write_tally_footer(
    \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
    array $members,
    int $lastVisible,
    int $afterDataRow
): int {
    $center = \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER;
    $last = twg_sheet_excel_col(max(1, $lastVisible));
    $r = $afterDataRow + 1;
    $sheet->mergeCells('A' . $r . ':' . $last . $r);
    $sheet->setCellValue('A' . $r, '*** Nothing Follows ***');
    $sheet->getStyle('A' . $r)->getFont()->setBold(true)->setItalic(true);
    $sheet->getStyle('A' . $r)->getAlignment()->setHorizontal($center);

    $r += 2;
    $sheet->setCellValue('A' . $r, 'CERTIFIED TRUE AND CORRECT:');
    $sheet->getStyle('A' . $r)->getFont()->setBold(true);

    $count = count($members);
    if ($count === 0) {
        return $r;
    }
    $r += 2;
    $nameRow = $r;
    $labelRow = $r + 1;
    $step = $count === 1 ? 0 : (($lastVisible - 1) / ($count - 1));
    $n = 1;
    foreach ($members as $i => $m) {
        $col = $count === 1 ? 1 : ((int) round($i * $step) + 1);
        $col = min($lastVisible, max(1, $col));
        $letter = twg_sheet_excel_col($col);
        $sheet->setCellValue($letter . $nameRow, (string) ($m['label'] ?? $m['short'] ?? ('Judge ' . $n)));
        $sheet->getStyle($letter . $nameRow)->getFont()->setBold(true);
        $sheet->getStyle($letter . $nameRow)->getAlignment()->setHorizontal($center);
        $sheet->getStyle($letter . $nameRow)->getBorders()->getBottom()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        $sheet->setCellValue($letter . $labelRow, 'JUDGE ' . $n);
        $sheet->getStyle($letter . $labelRow)->getAlignment()->setHorizontal($center);
        $n++;
    }
    return $labelRow;
}

/**
 * @param array<string,mixed> $map
 * @param array<int,string> $headers
 */
function twg_sheet_hide_id_columns(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $map, int $headerRow): void
{
    $hidden = ['event_id', 'question_id', 'choice_id', 'ballot_entry_id', 'judge_key'];
    foreach ($hidden as $key) {
        if (!isset($map[$key])) {
            continue;
        }
        $idx = (int) $map[$key];
        $sheet->setCellValue(twg_sheet_excel_col($idx) . $headerRow, $key);
        $sheet->getColumnDimension(twg_sheet_excel_col($idx))->setVisible(false);
        $sheet->getColumnDimension(twg_sheet_excel_col($idx))->setWidth(12);
    }
}

/**
 * @param array<string,mixed> $map
 */
function twg_sheet_apply_tally_table_style(
    \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
    array $map,
    int $headerRow,
    int $firstData,
    int $lastData,
    int $scoreFirst,
    int $scoreLast,
    bool $yellowScores,
    array $itemMaxes
): void {
    $lastVisible = (int) $map['last_visible'];
    $lastCol = twg_sheet_excel_col((int) $map['last']);
    $visLast = twg_sheet_excel_col($lastVisible);
    $thin = \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN;
    $medium = \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM;
    $center = \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER;

    $end = max($lastData, $headerRow);
    $tableRange = 'A8:' . $visLast . $end;
    $sheet->getStyle($tableRange)->getBorders()->getAllBorders()->setBorderStyle($thin)->getColor()->setRGB('000000');
    $sheet->getStyle('A8:' . $visLast . $headerRow)->getBorders()->getOutline()->setBorderStyle($medium);
    $sheet->getStyle('A8:' . $visLast . $headerRow)->getFont()->setBold(true);
    $sheet->getStyle('A8:' . $visLast . $headerRow)->getAlignment()
        ->setHorizontal($center)
        ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER)
        ->setWrapText(true);
    $sheet->getStyle('A8:' . $visLast . $headerRow)->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setRGB('F2F2F2');

    $sheet->getColumnDimension('A')->setWidth(12);
    for ($i = 2; $i <= $lastVisible; $i++) {
        $width = 13;
        if (isset($map['business']) && $i === (int) $map['business']) {
            $width = 36;
        } elseif (isset($map['category']) && $i === (int) $map['category']) {
            $width = 28;
        } elseif (isset($map['award']) && $i === (int) $map['award']) {
            $width = 28;
        } elseif (isset($map['weighted']) && $i === (int) $map['weighted']) {
            $width = 14;
        } elseif (isset($map['average']) && $i === (int) $map['average']) {
            $width = 14;
        } elseif (isset($map['ranking']) && $i === (int) $map['ranking']) {
            $width = 12;
        }
        $sheet->getColumnDimension(twg_sheet_excel_col($i))->setWidth($width);
    }

    if ($lastData >= $firstData && $scoreFirst <= $scoreLast) {
        $sheet->getStyle(twg_sheet_excel_col($scoreFirst) . $firstData . ':' . twg_sheet_excel_col($scoreLast) . $lastData)
            ->getAlignment()->setHorizontal($center);
        $sheet->getStyle(twg_sheet_excel_col($scoreFirst) . $firstData . ':' . twg_sheet_excel_col($scoreLast) . $lastData)
            ->getNumberFormat()->setFormatCode('0.00');
    }
    if (isset($map['average']) && $lastData >= $firstData) {
        $avgCol = twg_sheet_excel_col((int) $map['average']);
        $sheet->getStyle($avgCol . $firstData . ':' . $avgCol . $lastData)->getNumberFormat()->setFormatCode('0.000');
        $sheet->getStyle($avgCol . $firstData . ':' . $avgCol . $lastData)->getFont()->setBold(true);
    }
    if (isset($map['weighted']) && $lastData >= $firstData) {
        $wCol = twg_sheet_excel_col((int) $map['weighted']);
        $sheet->getStyle($wCol . $firstData . ':' . $wCol . $lastData)->getFont()->setBold(true);
    }
    if (isset($map['entry_no']) && $lastData >= $firstData) {
        $sheet->getStyle('A' . $firstData . ':A' . $lastData)->getAlignment()->setHorizontal($center);
    }

    $sheet->freezePane('A' . $firstData);
    $pageSetup = $sheet->getPageSetup();
    $pageSetup->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
    $pageSetup->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);
    $pageSetup->setFitToPage(true);
    $pageSetup->setFitToWidth(1);
    $pageSetup->setFitToHeight(0);
    $pageSetup->setRowsToRepeatAtTopByStartAndEnd(1, $headerRow);
    $sheet->getPageMargins()->setTop(0.5)->setBottom(0.6)->setLeft(0.4)->setRight(0.4);
    $sheet->getHeaderFooter()->setOddFooter('&C&P of &N');

    $sheet->getStyle('A1:' . $lastCol . ($end + 8))->getProtection()
        ->setLocked(\PhpOffice\PhpSpreadsheet\Style\Protection::PROTECTION_PROTECTED);
    if ($yellowScores && $lastData >= $firstData && $scoreFirst <= $scoreLast) {
        for ($r = $firstData; $r <= $lastData; $r++) {
            for ($c = $scoreFirst; $c <= $scoreLast; $c++) {
                $cell = $sheet->getCell(twg_sheet_excel_col($c) . $r);
                $val = $cell->getValue();
                if ($val === null || $val === '') {
                    $sheet->getStyle(twg_sheet_excel_col($c) . $r)->getFill()
                        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                        ->getStartColor()->setRGB('FFF3B0');
                    $sheet->getStyle(twg_sheet_excel_col($c) . $r)->getProtection()
                        ->setLocked(\PhpOffice\PhpSpreadsheet\Style\Protection::PROTECTION_UNPROTECTED);
                    $sheet->setCellValue(twg_sheet_excel_col($c) . $r, 0);
                } else {
                    $sheet->getStyle(twg_sheet_excel_col($c) . $r)->getFill()
                        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                        ->getStartColor()->setRGB('E9EDF2');
                }
            }
        }
        $itemIdx = 0;
        for ($c = $scoreFirst; $c <= $scoreLast; $c++) {
            $max = (float) ($itemMaxes[$itemIdx] ?? 0);
            $itemIdx++;
            if ($max <= 0) {
                continue;
            }
            $validation = $sheet->getCell(twg_sheet_excel_col($c) . $firstData)->getDataValidation();
            $validation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_DECIMAL);
            $validation->setErrorStyle(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP);
            $validation->setAllowBlank(true);
            $validation->setShowInputMessage(true);
            $validation->setShowErrorMessage(true);
            $validation->setOperator(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::OPERATOR_BETWEEN);
            $validation->setFormula1('0');
            $validation->setFormula2((string) $max);
            $validation->setPromptTitle('Score');
            $validation->setPrompt('Enter 0–' . rtrim(rtrim(number_format($max, 2, '.', ''), '0'), '.') . '. Cells start at 0.');
            $validation->setErrorTitle('Invalid score');
            $validation->setError('Score must be from 0 to ' . rtrim(rtrim(number_format($max, 2, '.', ''), '0'), '.') . '.');
            if (method_exists($sheet, 'setDataValidation')) {
                $sheet->setDataValidation(
                    twg_sheet_excel_col($c) . $firstData . ':' . twg_sheet_excel_col($c) . $lastData,
                    clone $validation
                );
            }
        }
    }
    $protection = $sheet->getProtection();
    $protection->setSheet(true);
    $protection->setSort(true);
    $protection->setAutoFilter(true);
    $protection->setInsertRows(false);
    $protection->setDeleteRows(false);
}

/**
 * @param array<string,mixed> $payload
 * @return list<list<string>>
 */
function twg_sheet_rubric_instruction_lines(array $payload, array $meta = []): array
{
    $rubric = is_array($payload['rubric'] ?? null) ? $payload['rubric'] : [];
    $members = is_array($payload['members'] ?? null) ? $payload['members'] : [];
    $itemBits = [];
    $maxSum = 0.0;
    foreach ($rubric as $item) {
        $max = (float) ($item['max'] ?? 0);
        $maxSum += $max;
        $itemBits[] = trim((string) ($item['label'] ?? $item['short'] ?? 'Item'))
            . ' ' . rtrim(rtrim(number_format($max, 2, '.', ''), '0'), '.');
    }
    $judgeNames = [];
    foreach ($members as $i => $m) {
        $judgeNames[] = 'Judge ' . ($i + 1) . ' — ' . (string) ($m['label'] ?? $m['short'] ?? 'Judge');
    }
    return [
        ['TWG VALIDATION — official tally sheet'],
        [trim((string) ($meta['event_name'] ?? ''))],
        [trim((string) ($meta['datetime'] ?? $meta['generated_at'] ?? ''))],
        [''],
        ['Tabs: Judge 1, Judge 2, … then Overall Score.'],
        ['Each judge grades ' . ($itemBits !== [] ? implode(', ', $itemBits) : 'Taste 50, Innovation 20, Value 30')
            . '. The three scores add up to ' . rtrim(rtrim(number_format($maxSum, 2, '.', ''), '0'), '.') . '.'],
        ['Overall Score lists each judge total, Total Weighted Score (the sum), Average Weighted Score (the mean of judges who scored), and Ranking.'],
        ['Unscored Taste / Innovation / Value cells start at 0. Change only the scores that are not zero. A 0 counts in the total.'],
        ['Saved Taste / Innovation / Value scores from the system are already filled in. Yellow cells are still editable; gray cells stay locked on import.'],
        ['Hidden ID columns on the far right must stay as they are so the file can be imported.'],
        ['After the visit, import this same file on Reports → TWG Evaluation. Import updates saved scores unless the business is already on the public ballot.'],
        [''],
        ['Judges'],
        [$judgeNames !== [] ? implode(', ', $judgeNames) : 'Add judges on Reports → TWG Evaluation.'],
    ];
}

/**
 * @param array<string,mixed> $payload
 * @param array<string,mixed> $meta
 */
function twg_sheet_populate_rubric_xlsx(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet, array $payload, array $meta = []): void
{
    $members = is_array($payload['members'] ?? null) ? $payload['members'] : [];
    $rubric = is_array($payload['rubric'] ?? null) ? $payload['rubric'] : [];
    $awards = is_array($payload['awards'] ?? null) ? $payload['awards'] : [];
    $hasEntries = !empty($payload['has_entries']);
    $single = !empty($payload['single_award']);
    $showGroup = !$single;
    $nItems = count($rubric);
    $headerRow = twg_sheet_tally_header_row();
    $firstData = twg_sheet_tally_first_data_row();
    $groupRow = $headerRow - 1;

    $usedTitles = [];
    $judgeTitles = [];
    foreach ($members as $i => $m) {
        $title = twg_sheet_safe_tab_title('Judge ' . ($i + 1), $usedTitles);
        $usedTitles[strtolower($title)] = true;
        $judgeTitles[(string) ($m['key'] ?? '')] = $title;
    }
    $overallTitle = twg_sheet_safe_tab_title('Overall Score', $usedTitles);

    $flatRows = [];
    foreach ($awards as $award) {
        $entryNo = 0;
        foreach ($award['rows'] as $row) {
            $entryNo++;
            $flatRows[] = $row + [
                'category_name' => $award['category_name'],
                'question_name' => $award['question_name'],
                'question_id' => $award['question_id'],
                'entry_no' => $entryNo,
            ];
        }
    }

    $category = $single && isset($awards[0])
        ? (string) $awards[0]['category_name']
        : 'All categories';
    $awardTitle = $single && isset($awards[0])
        ? (string) $awards[0]['question_name']
        : 'All award titles';

    $jMap = twg_sheet_tally_columns($members, $rubric, $showGroup, $hasEntries, 'judge');
    $oMap = twg_sheet_tally_columns($members, $rubric, $showGroup, $hasEntries, 'overall');
    $itemMaxes = array_map(static fn(array $item): float => (float) ($item['max'] ?? 0), $rubric);
    $itemKeys = array_keys($jMap['items'] ?? []);

    $createdJudge = false;
    foreach ($members as $i => $m) {
        $key = (string) ($m['key'] ?? '');
        $sheet = $createdJudge ? $spreadsheet->createSheet() : $spreadsheet->getActiveSheet();
        $createdJudge = true;
        $sheet->setTitle($judgeTitles[$key]);
        if (method_exists($sheet, 'getTabColor')) {
            $sheet->getTabColor()->setRGB('FFC000');
        }
        twg_sheet_write_tally_banner(
            $sheet,
            $meta,
            (int) $jMap['last_visible'],
            'JUDGE ' . ($i + 1) . ' SCORE SHEET',
            $category,
            $awardTitle
        );
        $judgeHead = [
            (int) $jMap['entry_no'] => 'ENTRY NO.',
            (int) $jMap['business'] => 'BUSINESS NAME',
            (int) $jMap['total'] => 'TOTAL SCORE',
        ];
        if ($showGroup) {
            $judgeHead[(int) $jMap['category']] = 'CATEGORY';
            $judgeHead[(int) $jMap['award']] = 'AWARD TITLE';
        }
        if ($hasEntries) {
            $judgeHead[(int) $jMap['entry']] = 'ENTRY';
        }
        foreach ($rubric as $item) {
            $itemKey = strtolower((string) ($item['key'] ?? ''));
            if ($itemKey === '' || !isset($jMap['items'][$itemKey])) {
                continue;
            }
            $judgeHead[(int) $jMap['items'][$itemKey]] = twg_sheet_item_header($item);
        }
        foreach ($judgeHead as $idx => $label) {
            $sheet->setCellValue(twg_sheet_excel_col($idx) . $groupRow, $label);
            $sheet->setCellValue(twg_sheet_excel_col($idx) . $headerRow, $label);
        }
        twg_sheet_hide_id_columns($sheet, $jMap, $headerRow);

        $excelRow = $firstData;
        $lastJudgeRow = $headerRow;
        foreach ($flatRows as $row) {
            $r = $excelRow;
            $lastJudgeRow = $r;
            $block = $row['judges'][$key] ?? ['items' => [], 'total' => null];
            $items = is_array($block['items'] ?? null) ? $block['items'] : [];
            $sheet->setCellValue(twg_sheet_excel_col((int) $jMap['entry_no']) . $r, (int) ($row['entry_no'] ?? 0));
            if ($showGroup) {
                $sheet->setCellValue(twg_sheet_excel_col((int) $jMap['category']) . $r, $row['category_name'] ?? '');
                $sheet->setCellValue(twg_sheet_excel_col((int) $jMap['award']) . $r, $row['question_name'] ?? '');
            }
            $sheet->setCellValue(twg_sheet_excel_col((int) $jMap['business']) . $r, $row['choice_name'] ?? '');
            if ($hasEntries) {
                $sheet->setCellValue(twg_sheet_excel_col((int) $jMap['entry']) . $r, $row['entry_name'] ?? '');
            }
            foreach ($itemKeys as $itemKey) {
                $val = $items[$itemKey] ?? null;
                $cell = twg_sheet_excel_col((int) $jMap['items'][$itemKey]) . $r;
                if ($val !== null && $val !== '') {
                    $sheet->setCellValue($cell, (float) $val);
                }
            }
            $need = max(1, $nItems);
            $sumFirst = twg_sheet_excel_col((int) ($jMap['items'][$itemKeys[0]] ?? $jMap['total'])) . $r;
            $sumLast = twg_sheet_excel_col((int) ($jMap['items'][$itemKeys[count($itemKeys) - 1] ?? $jMap['total']])) . $r;
            $sheet->setCellValue(
                twg_sheet_excel_col((int) $jMap['total']) . $r,
                $itemKeys === []
                    ? ''
                    : ('=IF(COUNT(' . $sumFirst . ':' . $sumLast . ')<' . $need . ',"",ROUND(SUM(' . $sumFirst . ':' . $sumLast . '),2))')
            );
            $sheet->setCellValue(twg_sheet_excel_col((int) $jMap['event_id']) . $r, (int) ($meta['event_id'] ?? 0));
            $sheet->setCellValue(twg_sheet_excel_col((int) $jMap['question_id']) . $r, (int) ($row['question_id'] ?? 0));
            $sheet->setCellValue(twg_sheet_excel_col((int) $jMap['choice_id']) . $r, (int) ($row['choice_id'] ?? 0));
            $sheet->setCellValue(twg_sheet_excel_col((int) $jMap['ballot_entry_id']) . $r, (int) ($row['ballot_entry_id'] ?? 0));
            $sheet->setCellValue(twg_sheet_excel_col((int) $jMap['judge_key']) . $r, $key);
            $excelRow++;
        }
        if ($flatRows === []) {
            $sheet->setCellValue('A' . $firstData, 'No businesses are linked to this award yet.');
            $lastJudgeRow = $firstData;
        }
        $footerRow = twg_sheet_write_tally_footer($sheet, [$m], (int) $jMap['last_visible'], $lastJudgeRow);
        $scoreFirst = (int) ($jMap['items'][$itemKeys[0] ?? ''] ?? $jMap['total']);
        $scoreLast = (int) ($jMap['items'][$itemKeys[count($itemKeys) - 1] ?? ''] ?? $jMap['total']);
        twg_sheet_apply_tally_table_style(
            $sheet,
            $jMap,
            $headerRow,
            $firstData,
            $lastJudgeRow,
            $itemKeys !== [] ? $scoreFirst : 0,
            $itemKeys !== [] ? $scoreLast : -1,
            true,
            $itemMaxes
        );
        $sheet->getPageSetup()->setPrintArea('A1:' . twg_sheet_excel_col((int) $jMap['last_visible']) . max($footerRow, $lastJudgeRow));
    }

    $overall = $createdJudge ? $spreadsheet->createSheet() : $spreadsheet->getActiveSheet();
    $overall->setTitle($overallTitle);
    twg_sheet_write_tally_banner(
        $overall,
        $meta,
        (int) $oMap['last_visible'],
        'OVER-ALL TALLY SHEET',
        $category,
        $awardTitle
    );

    $overall->setCellValue(twg_sheet_excel_col((int) $oMap['entry_no']) . $groupRow, 'ENTRY NO.');
    $overall->mergeCells(twg_sheet_excel_col((int) $oMap['entry_no']) . $groupRow . ':' . twg_sheet_excel_col((int) $oMap['entry_no']) . $headerRow);
    if ($showGroup) {
        $overall->setCellValue(twg_sheet_excel_col((int) $oMap['category']) . $groupRow, 'CATEGORY');
        $overall->mergeCells(twg_sheet_excel_col((int) $oMap['category']) . $groupRow . ':' . twg_sheet_excel_col((int) $oMap['category']) . $headerRow);
        $overall->setCellValue(twg_sheet_excel_col((int) $oMap['award']) . $groupRow, 'AWARD TITLE');
        $overall->mergeCells(twg_sheet_excel_col((int) $oMap['award']) . $groupRow . ':' . twg_sheet_excel_col((int) $oMap['award']) . $headerRow);
    }
    $overall->setCellValue(twg_sheet_excel_col((int) $oMap['business']) . $groupRow, 'BUSINESS NAME');
    $overall->mergeCells(twg_sheet_excel_col((int) $oMap['business']) . $groupRow . ':' . twg_sheet_excel_col((int) $oMap['business']) . $headerRow);
    if ($hasEntries) {
        $overall->setCellValue(twg_sheet_excel_col((int) $oMap['entry']) . $groupRow, 'ENTRY');
        $overall->mergeCells(twg_sheet_excel_col((int) $oMap['entry']) . $groupRow . ':' . twg_sheet_excel_col((int) $oMap['entry']) . $headerRow);
    }

    $judgeCols = is_array($oMap['judges'] ?? null) ? $oMap['judges'] : [];
    $judgeColIdx = array_values($judgeCols);
    $jFirst = $judgeColIdx[0] ?? 0;
    $jLast = $judgeColIdx[count($judgeColIdx) - 1] ?? 0;
    if ($judgeCols !== []) {
        $overall->setCellValue(twg_sheet_excel_col($jFirst) . $groupRow, 'TOTAL SCORE');
        if ($jLast > $jFirst) {
            $overall->mergeCells(twg_sheet_excel_col($jFirst) . $groupRow . ':' . twg_sheet_excel_col($jLast) . $groupRow);
        }
        $overall->getStyle(twg_sheet_excel_col($jFirst) . $groupRow . ':' . twg_sheet_excel_col($jLast) . $groupRow)->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('FFC000');
        $n = 1;
        foreach ($members as $m) {
            $k = (string) ($m['key'] ?? '');
            if (!isset($judgeCols[$k])) {
                continue;
            }
            $overall->setCellValue(twg_sheet_excel_col((int) $judgeCols[$k]) . $headerRow, 'JUDGE NO. ' . $n);
            $n++;
        }
    }
    $overall->setCellValue(twg_sheet_excel_col((int) $oMap['weighted']) . $groupRow, 'TOTAL WEIGHTED SCORE');
    $overall->mergeCells(twg_sheet_excel_col((int) $oMap['weighted']) . $groupRow . ':' . twg_sheet_excel_col((int) $oMap['weighted']) . $headerRow);
    $overall->setCellValue(twg_sheet_excel_col((int) $oMap['average']) . $groupRow, 'AVERAGE WEIGHTED SCORE');
    $overall->mergeCells(twg_sheet_excel_col((int) $oMap['average']) . $groupRow . ':' . twg_sheet_excel_col((int) $oMap['average']) . $headerRow);
    $overall->setCellValue(twg_sheet_excel_col((int) $oMap['ranking']) . $groupRow, 'RANKING');
    $overall->mergeCells(twg_sheet_excel_col((int) $oMap['ranking']) . $groupRow . ':' . twg_sheet_excel_col((int) $oMap['ranking']) . $headerRow);
    twg_sheet_hide_id_columns($overall, $oMap, $headerRow);

    $totalLetter = twg_sheet_excel_col((int) ($jMap['total'] ?? 1));
    $excelRow = $firstData;
    $lastDataRow = $headerRow;
    $rowCount = count($flatRows);
    $avgRange = twg_sheet_excel_col((int) $oMap['average']) . $firstData . ':' . twg_sheet_excel_col((int) $oMap['average']) . ($firstData + max(0, $rowCount - 1));
    $awardColLetter = $showGroup ? twg_sheet_excel_col((int) $oMap['award']) : '';
    foreach ($flatRows as $row) {
        $r = $excelRow;
        $lastDataRow = $r;
        $overall->setCellValue(twg_sheet_excel_col((int) $oMap['entry_no']) . $r, (int) ($row['entry_no'] ?? 0));
        if ($showGroup) {
            $overall->setCellValue(twg_sheet_excel_col((int) $oMap['category']) . $r, $row['category_name'] ?? '');
            $overall->setCellValue(twg_sheet_excel_col((int) $oMap['award']) . $r, $row['question_name'] ?? '');
        }
        $overall->setCellValue(twg_sheet_excel_col((int) $oMap['business']) . $r, $row['choice_name'] ?? '');
        if ($hasEntries) {
            $overall->setCellValue(twg_sheet_excel_col((int) $oMap['entry']) . $r, $row['entry_name'] ?? '');
        }
        foreach ($members as $m) {
            $k = (string) ($m['key'] ?? '');
            if (!isset($judgeCols[$k], $judgeTitles[$k])) {
                continue;
            }
            $cell = twg_sheet_excel_col((int) $judgeCols[$k]) . $r;
            $src = twg_sheet_formula_ref($judgeTitles[$k], $totalLetter . $r);
            $overall->setCellValue($cell, '=IF(' . $src . '="","",' . $src . ')');
        }
        $jFirstLetter = $jFirst > 0 ? twg_sheet_excel_col($jFirst) : 'A';
        $jLastLetter = $jLast > 0 ? twg_sheet_excel_col($jLast) : 'A';
        $wCell = twg_sheet_excel_col((int) $oMap['weighted']) . $r;
        $aCell = twg_sheet_excel_col((int) $oMap['average']) . $r;
        if ($judgeCols !== []) {
            $overall->setCellValue($wCell, '=IF(COUNT(' . $jFirstLetter . $r . ':' . $jLastLetter . $r . ')=0,"",SUM(' . $jFirstLetter . $r . ':' . $jLastLetter . $r . '))');
            $overall->setCellValue($aCell, '=IF(COUNT(' . $jFirstLetter . $r . ':' . $jLastLetter . $r . ')=0,"",ROUND(AVERAGE(' . $jFirstLetter . $r . ':' . $jLastLetter . $r . '),3))');
        }
        if ($showGroup && $awardColLetter !== '') {
            $overall->setCellValue(
                twg_sheet_excel_col((int) $oMap['ranking']) . $r,
                '=IF(' . $aCell . '="","",COUNTIFS(' . $awardColLetter . '$' . $firstData . ':' . $awardColLetter . '$' . ($firstData + max(0, $rowCount - 1)) . ',' . $awardColLetter . $r . ',' . twg_sheet_excel_col((int) $oMap['average']) . '$' . $firstData . ':' . twg_sheet_excel_col((int) $oMap['average']) . '$' . ($firstData + max(0, $rowCount - 1)) . ',">"&' . $aCell . ')+1)'
            );
        } else {
            $overall->setCellValue(
                twg_sheet_excel_col((int) $oMap['ranking']) . $r,
                '=IF(' . $aCell . '="","",RANK(' . $aCell . ',' . $avgRange . ',0))'
            );
        }
        $overall->setCellValue(twg_sheet_excel_col((int) $oMap['event_id']) . $r, (int) ($meta['event_id'] ?? 0));
        $overall->setCellValue(twg_sheet_excel_col((int) $oMap['question_id']) . $r, (int) ($row['question_id'] ?? 0));
        $overall->setCellValue(twg_sheet_excel_col((int) $oMap['choice_id']) . $r, (int) ($row['choice_id'] ?? 0));
        $overall->setCellValue(twg_sheet_excel_col((int) $oMap['ballot_entry_id']) . $r, (int) ($row['ballot_entry_id'] ?? 0));
        $excelRow++;
    }
    if ($flatRows === []) {
        $overall->setCellValue('A' . $firstData, 'No businesses are linked to this award yet.');
        $lastDataRow = $firstData;
    }
    $footerRow = twg_sheet_write_tally_footer($overall, $members, (int) $oMap['last_visible'], $lastDataRow);
    $jScoreFirst = $jFirst > 0 ? $jFirst : 0;
    $jScoreLast = isset($oMap['ranking']) ? (int) $oMap['ranking'] : -1;
    twg_sheet_apply_tally_table_style(
        $overall,
        $oMap,
        $headerRow,
        $firstData,
        $lastDataRow,
        $jScoreFirst,
        $jScoreLast,
        false,
        []
    );
    $overall->getPageSetup()->setPrintArea('A1:' . twg_sheet_excel_col((int) $oMap['last_visible']) . max($footerRow, $lastDataRow));

    $info = $spreadsheet->createSheet();
    $info->setTitle('Instructions');
    $info->fromArray(twg_sheet_rubric_instruction_lines($payload, $meta), null, 'A1');
    $info->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $info->getColumnDimension('A')->setWidth(118);
    $info->getProtection()->setSheet(true);

    $overallIndex = $createdJudge ? count($members) : 0;
    $spreadsheet->setActiveSheetIndex($overallIndex);
}

/**
 * @param array<string,mixed> $payload
 * @return list<list<string|int|float|null>>
 */
function twg_sheet_rubric_csv_lines(array $payload, array $meta = []): array
{
    $members = is_array($payload['members'] ?? null) ? $payload['members'] : [];
    $rubric = is_array($payload['rubric'] ?? null) ? $payload['rubric'] : [];
    $awards = is_array($payload['awards'] ?? null) ? $payload['awards'] : [];
    $hasEntries = !empty($payload['has_entries']);
    $single = !empty($payload['single_award']);
    $lines = [];
    $lines[] = ['TWG VALIDATION'];
    $lines[] = [trim((string) ($meta['datetime'] ?? $meta['generated_at'] ?? ''))];
    $lines[] = [trim((string) ($meta['venue'] ?? $meta['event_name'] ?? ''))];
    $lines[] = ['OVER-ALL TALLY SHEET'];
    $cat = $single && isset($awards[0]) ? (string) $awards[0]['category_name'] : 'All categories';
    $awardTitle = $single && isset($awards[0]) ? (string) $awards[0]['question_name'] : 'All award titles';
    $lines[] = ['CATEGORY: ' . $cat, '', '', 'AWARD TITLE: ' . $awardTitle];
    $lines[] = [];

    $overallHead = ['ENTRY NO.'];
    if (!$single) {
        $overallHead[] = 'CATEGORY';
        $overallHead[] = 'AWARD TITLE';
    }
    $overallHead[] = 'BUSINESS NAME';
    if ($hasEntries) {
        $overallHead[] = 'ENTRY';
    }
    foreach ($members as $i => $m) {
        $overallHead[] = 'JUDGE NO. ' . ($i + 1);
    }
    $overallHead = array_merge($overallHead, [
        'TOTAL WEIGHTED SCORE',
        'AVERAGE WEIGHTED SCORE',
        'RANKING',
        'event_id',
        'question_id',
        'choice_id',
        'ballot_entry_id',
    ]);
    $lines[] = $overallHead;
    foreach ($awards as $award) {
        $entryNo = 0;
        foreach ($award['rows'] as $row) {
            $entryNo++;
            $line = [$entryNo];
            if (!$single) {
                $line[] = $award['category_name'];
                $line[] = $award['question_name'];
            }
            $line[] = $row['choice_name'] ?? '';
            if ($hasEntries) {
                $line[] = $row['entry_name'] ?? '';
            }
            $totals = [];
            foreach ($members as $m) {
                $total = $row['judges'][$m['key']]['total'] ?? null;
                $total = ($total !== '' && $total !== null) ? $total : 0;
                $line[] = $total;
                $totals[] = (float) $total;
            }
            $line[] = $totals === [] ? '' : array_sum($totals);
            $line[] = $totals === [] ? '' : round(array_sum($totals) / count($totals), 3);
            $line[] = $row['rank'] ?? '';
            $line[] = (int) ($meta['event_id'] ?? 0);
            $line[] = (int) $award['question_id'];
            $line[] = (int) ($row['choice_id'] ?? 0);
            $line[] = (int) ($row['ballot_entry_id'] ?? 0);
            $lines[] = $line;
        }
    }
    $lines[] = ['*** Nothing Follows ***'];
    $lines[] = [];
    $lines[] = ['CERTIFIED TRUE AND CORRECT:'];
    $sig = [];
    $labs = [];
    foreach ($members as $i => $m) {
        $sig[] = (string) ($m['label'] ?? $m['short'] ?? '');
        $labs[] = 'JUDGE ' . ($i + 1);
    }
    $lines[] = $sig;
    $lines[] = $labs;

    foreach ($members as $i => $m) {
        $key = (string) $m['key'];
        $lines[] = [];
        $lines[] = ['JUDGE ' . ($i + 1) . ' SCORE SHEET — ' . (string) ($m['label'] ?? $m['short'] ?? 'Judge')];
        $head = ['ENTRY NO.'];
        if (!$single) {
            $head[] = 'CATEGORY';
            $head[] = 'AWARD TITLE';
        }
        $head[] = 'BUSINESS NAME';
        if ($hasEntries) {
            $head[] = 'ENTRY';
        }
        foreach ($rubric as $item) {
            $head[] = twg_sheet_item_header($item);
        }
        $head = array_merge($head, ['TOTAL SCORE', 'event_id', 'question_id', 'choice_id', 'ballot_entry_id', 'judge_key']);
        $lines[] = $head;
        foreach ($awards as $award) {
            $entryNo = 0;
            foreach ($award['rows'] as $row) {
                $entryNo++;
                $block = $row['judges'][$key] ?? ['items' => [], 'total' => null];
                $items = is_array($block['items'] ?? null) ? $block['items'] : [];
                $line = [$entryNo];
                if (!$single) {
                    $line[] = $award['category_name'];
                    $line[] = $award['question_name'];
                }
                $line[] = $row['choice_name'] ?? '';
                if ($hasEntries) {
                    $line[] = $row['entry_name'] ?? '';
                }
                foreach ($rubric as $item) {
                    $itemKey = strtolower((string) ($item['key'] ?? ''));
                    $itemVal = $items[$itemKey] ?? null;
                    $line[] = ($itemVal !== null && $itemVal !== '') ? $itemVal : 0;
                }
                $line[] = ($block['total'] !== null && $block['total'] !== '') ? $block['total'] : 0;
                $line[] = (int) ($meta['event_id'] ?? 0);
                $line[] = (int) $award['question_id'];
                $line[] = (int) ($row['choice_id'] ?? 0);
                $line[] = (int) ($row['ballot_entry_id'] ?? 0);
                $line[] = $key;
                $lines[] = $line;
            }
        }
    }
    return $lines;
}

/**
 * @param list<list<mixed>> $table
 * @return array<string,int>
 */
function twg_sheet_rubric_header_map(array $headerRow, array $rubricItems): array
{
    $aliases = [
        'event_id' => 'event_id',
        'question_id' => 'question_id',
        'award_id' => 'question_id',
        'choice_id' => 'choice_id',
        'business_id' => 'choice_id',
        'ballot_entry_id' => 'ballot_entry_id',
        'entry_id' => 'ballot_entry_id',
        'judge_key' => 'judge_key',
        'member_key' => 'judge_key',
        'judge' => 'judge_key',
    ];
    foreach ($rubricItems as $item) {
        $key = strtolower((string) ($item['key'] ?? ''));
        if ($key === '') {
            continue;
        }
        $aliases[$key] = 'item:' . $key;
        $aliases[twg_sheet_normalize_header((string) ($item['short'] ?? ''))] = 'item:' . $key;
        $aliases[twg_sheet_normalize_header((string) ($item['label'] ?? ''))] = 'item:' . $key;
        $max = (float) ($item['max'] ?? 0);
        if ($max > 0) {
            $aliases[twg_sheet_normalize_header(($item['short'] ?? '') . ' ' . $max)] = 'item:' . $key;
            $aliases[twg_sheet_normalize_header(($item['short'] ?? '') . ' (' . $max . ')')] = 'item:' . $key;
        }
    }
    $map = [];
    foreach ($headerRow as $i => $label) {
        $norm = twg_sheet_normalize_header((string) $label);
        if ($norm === '' || !isset($aliases[$norm])) {
            continue;
        }
        $mapped = $aliases[$norm];
        if (!isset($map[$mapped])) {
            $map[$mapped] = (int) $i;
        }
    }
    return $map;
}

/**
 * @param list<list<mixed>> $table
 * @return list<list<list<mixed>>>
 */
function twg_sheet_rubric_split_tables(array $table, array $rubricItems): array
{
    $blocks = [];
    $current = [];
    foreach ($table as $row) {
        if (!is_array($row)) {
            continue;
        }
        $try = twg_sheet_rubric_header_map($row, $rubricItems);
        $itemCols = 0;
        foreach ($try as $k => $_) {
            if (str_starts_with((string) $k, 'item:')) {
                $itemCols++;
            }
        }
        if ($itemCols > 0 && isset($try['choice_id'], $try['question_id'])) {
            if ($current !== []) {
                $blocks[] = $current;
            }
            $current = [$row];
            continue;
        }
        if ($current !== []) {
            $current[] = $row;
        }
    }
    if ($current !== []) {
        $blocks[] = $current;
    }
    return $blocks !== [] ? $blocks : [$table];
}

/**
 * @param list<list<mixed>> $table
 * @return array{ok:bool,message:string,saved:int,skipped:int,errors:list<string>,matched:bool}
 */
function twg_sheet_import_rubric_table(mysqli $conn, int $eventId, array $table, ?string $sheetJudgeKey = null): array
{
    $empty = ['ok' => false, 'message' => '', 'saved' => 0, 'skipped' => 0, 'errors' => [], 'matched' => false];
    $rubric = twg_rubric_for_event($conn, $eventId);
    $headerIdx = null;
    $map = [];
    foreach ($table as $i => $row) {
        if (!is_array($row)) {
            continue;
        }
        $try = twg_sheet_rubric_header_map($row, $rubric);
        $itemCols = 0;
        foreach ($try as $k => $_) {
            if (str_starts_with((string) $k, 'item:')) {
                $itemCols++;
            }
        }
        if ($itemCols > 0 && isset($try['choice_id'], $try['question_id'])) {
            $headerIdx = $i;
            $map = $try;
            break;
        }
    }
    if ($headerIdx === null) {
        return $empty;
    }
    $cells = [];
    $skipped = 0;
    $errors = [];
    for ($r = $headerIdx + 1, $n = count($table); $r < $n; $r++) {
        $row = $table[$r];
        if (!is_array($row)) {
            continue;
        }
        $qid = (int) ($row[$map['question_id']] ?? 0);
        $cid = (int) ($row[$map['choice_id']] ?? 0);
        if ($qid <= 0 && $cid <= 0) {
            $skipped++;
            continue;
        }
        $judge = strtolower(trim((string) ($sheetJudgeKey ?? '')));
        if (isset($map['judge_key'])) {
            $fromCol = strtolower(trim((string) ($row[$map['judge_key']] ?? '')));
            if ($fromCol !== '') {
                $judge = $fromCol;
            }
        }
        if ($judge === '') {
            $errors[] = 'Row ' . ($r + 1) . ': missing judge_key.';
            continue;
        }
        $eid = isset($map['ballot_entry_id']) ? (int) ($row[$map['ballot_entry_id']] ?? 0) : 0;
        foreach ($map as $mapped => $col) {
            if (!str_starts_with((string) $mapped, 'item:')) {
                continue;
            }
            $itemKey = substr((string) $mapped, 5);
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
            $cells[] = [
                'question_id' => $qid,
                'choice_id' => $cid,
                'ballot_entry_id' => $eid,
                'judge_key' => $judge,
                'item_key' => $itemKey,
                'score' => $raw,
            ];
        }
    }
    if ($cells === []) {
        return [
            'ok' => true,
            'message' => 'No new rubric scores to import. Blank cells are skipped.',
            'saved' => 0,
            'skipped' => $skipped,
            'errors' => $errors,
            'matched' => true,
        ];
    }
    $result = twg_rubric_save_cells($conn, $eventId, $cells);
    return [
        'ok' => !empty($result['ok']),
        'message' => (string) ($result['message'] ?? ''),
        'saved' => (int) ($result['saved'] ?? 0),
        'skipped' => $skipped,
        'errors' => $errors,
        'matched' => true,
    ];
}
