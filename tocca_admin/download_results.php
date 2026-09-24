<?php
declare(strict_types=1);

require_once __DIR__ . '/require_admin_session.php';
require_once __DIR__ . '/audit_log.php';
require_once __DIR__ . '/includes/report_export_helpers.php';
tocca_admin_require_login(false);

require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/includes/admin_active_event.php';
require_once __DIR__ . '/includes/results_formula.php';

$autoloadCandidates = [
    __DIR__ . '/vendor/autoload.php',
    dirname(__DIR__) . '/vendor/autoload.php',
    dirname(__DIR__, 2) . '/vendor/autoload.php',
    ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/vendor/autoload.php',
];
$autoloadLoaded = null;
foreach ($autoloadCandidates as $p) {
    if (is_file($p)) {
        require_once $p;
        $autoloadLoaded = $p;
        break;
    }
}

function results_export_text_error(string $message, int $code = 400): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $message;
    exit;
}

function results_export_secure_headers(string $contentType, string $disposition): void
{
    header('Content-Type: ' . $contentType);
    header('Content-Disposition: ' . $disposition);
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
}

function results_export_safe_filename(string $s): string
{
    $s = trim(preg_replace('/\s+/', ' ', $s));
    $s = preg_replace('/[\\\\\\/:*?"<>|]+/', '_', $s);
    return $s === '' ? 'Voting_Results' : $s;
}

/** @param list<array{category:string,question:string,rank:string,choice_name:string,vote_count:int}> $results */
function results_export_group(array $results): array
{
    $grouped = [];
    foreach ($results as $row) {
        $grouped[$row['category']][$row['question']][] = $row;
    }
    return $grouped;
}

function results_export_fmt($value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    return number_format((float) $value, 2, '.', '');
}

function results_export_numeric($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    return round((float) $value, 2);
}

function results_export_business_label(array $row): string
{
    $name = trim(str_replace(' (manual input)', '', (string) ($row['choice_name'] ?? '')));
    $isFreetext = !empty($row['is_freetext']) || empty($row['choice_id']);
    $products = [];
    if (!empty($row['entry_names']) && is_array($row['entry_names'])) {
        foreach ($row['entry_names'] as $entryName) {
            $entryName = trim((string) $entryName);
            if ($entryName !== '') {
                $products[] = $entryName;
            }
        }
    }
    if ($products === [] && !empty($row['entries']) && is_array($row['entries'])) {
        foreach ($row['entries'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $entryName = trim((string) ($entry['entry_name'] ?? ''));
            if ($entryName !== '') {
                $products[] = $entryName;
            }
        }
    }
    if ($products !== []) {
        $name = $name === ''
            ? implode(', ', $products)
            : ($name . ' - ' . implode(', ', $products));
    }
    if ($isFreetext && $name !== '') {
        return $name . ' (manual input)';
    }
    return $name;
}

/** @param array<string,mixed> $row */
function results_export_map_row(array $row, string $category, string $question): array
{
    return [
        'category' => $category,
        'question' => $question,
        'rank' => (string) ($row['display_rank'] ?? ''),
        'choice_name' => results_export_business_label($row),
        'vote_count' => (int) ($row['vote_count'] ?? 0),
        'vote_share' => $row['vote_share'] ?? null,
        'community_score' => $row['community_score'] ?? null,
        'twg_average' => $row['twg_average'] ?? null,
        'final_score' => $row['final_score'] ?? null,
    ];
}

/** @param list<array<string,mixed>> $results */
function results_export_headers(string $scope): array
{
    $score = ['Standing', 'Business', 'Votes', 'Vote share %', 'Community (0–10)', 'TWG (0–100)', 'Final'];
    return $scope === 'all' ? array_merge(['Category', 'Award'], $score) : $score;
}

function results_export_data_row(array $row, string $scope): array
{
    $twg = results_export_fmt($row['twg_average'] ?? null);
    $score = [
        $row['rank'] !== '' ? $row['rank'] : '—',
        $row['choice_name'],
        (int) $row['vote_count'],
        results_export_fmt($row['vote_share'] ?? null),
        results_export_fmt($row['community_score'] ?? null),
        $twg !== '' ? $twg : '—',
        results_export_fmt($row['final_score'] ?? null),
    ];
    return $scope === 'all'
        ? array_merge([$row['category'], $row['question']], $score)
        : $score;
}

/** @param list<array<string,mixed>> $results */
function results_export_csv_rows(array $results, string $scope): array
{
    $headers = results_export_headers($scope);
    $width = count($headers);
    if ($results === []) {
        $empty = array_fill(0, $width, '');
        $empty[1] = 'No results match the selected filters.';
        return [$headers, $empty];
    }

    $rows = [$headers];
    foreach ($results as $row) {
        $rows[] = results_export_data_row($row, $scope);
    }
    return $rows;
}

function results_export_pdf_score_header(): string
{
    return '<th class="center" style="width:48px;">Standing</th>'
        . '<th>Business</th>'
        . '<th class="center" style="width:52px;">Votes</th>'
        . '<th class="center" style="width:70px;">Vote share %</th>'
        . '<th class="center" style="width:88px;">Community (0–10)</th>'
        . '<th class="center" style="width:88px;">TWG (0–100)</th>'
        . '<th class="center" style="width:52px;">Final</th>';
}

function results_export_pdf_score_cells(array $row): string
{
    $rank = $row['rank'] !== '' ? htmlspecialchars((string) $row['rank'], ENT_QUOTES) : '<span class="muted">—</span>';
    $twg = results_export_fmt($row['twg_average'] ?? null);
    return '<td class="num center">' . $rank . '</td>'
        . '<td>' . htmlspecialchars((string) $row['choice_name'], ENT_QUOTES) . '</td>'
        . '<td class="center">' . (int) $row['vote_count'] . '</td>'
        . '<td class="center">' . htmlspecialchars(results_export_fmt($row['vote_share'] ?? null), ENT_QUOTES) . '</td>'
        . '<td class="center">' . htmlspecialchars(results_export_fmt($row['community_score'] ?? null), ENT_QUOTES) . '</td>'
        . '<td class="center">' . ($twg !== '' ? htmlspecialchars($twg, ENT_QUOTES) : '<span class="muted">—</span>') . '</td>'
        . '<td class="center"><strong>' . htmlspecialchars(results_export_fmt($row['final_score'] ?? null), ENT_QUOTES) . '</strong></td>';
}

/** @param list<array<string,mixed>> $results */
function results_export_render_pdf_tables(array $results, string $scope): string
{
    $html = '<div class="section-label">Standing by final score</div>';

    if ($results === []) {
        return $html . '<table class="data-table"><tbody><tr><td class="empty" colspan="7">No results match the selected filters.</td></tr></tbody></table>';
    }

    if ($scope === 'all') {
        foreach (results_export_group($results) as $category => $questions) {
            $html .= '<div class="section-label" style="margin-top:10px;">' . htmlspecialchars($category, ENT_QUOTES) . '</div>';
            foreach ($questions as $question => $choices) {
                $html .= '<div class="doc-subtitle" style="text-align:left;margin:6px 0;">' . htmlspecialchars($question, ENT_QUOTES) . '</div>';
                $html .= '<table class="data-table"><thead><tr>' . results_export_pdf_score_header() . '</tr></thead><tbody>';
                foreach ($choices as $i => $row) {
                    $cls = ($i % 2 === 1) ? ' class="alt"' : '';
                    $html .= '<tr' . $cls . '>' . results_export_pdf_score_cells($row) . '</tr>';
                }
                $html .= '</tbody></table>';
            }
        }
        return $html;
    }

    $html .= '<table class="data-table"><thead><tr>' . results_export_pdf_score_header() . '</tr></thead><tbody>';
    foreach ($results as $i => $row) {
        $cls = ($i % 2 === 1) ? ' class="alt"' : '';
        $html .= '<tr' . $cls . '>' . results_export_pdf_score_cells($row) . '</tr>';
    }
    $html .= '</tbody></table>';
    return $html;
}

function results_export_write_excel_table(
    \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sh,
    int $row,
    array $choices
): int {
    $headers = ['Standing', 'Business', 'Votes', 'Vote share %', 'Community (0–10)', 'TWG (0–100)', 'Final'];
    $cols = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
    foreach ($headers as $i => $header) {
        $sh->setCellValue($cols[$i] . $row, $header);
    }
    $sh->getStyle("A{$row}:G{$row}")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
    $sh->getStyle("A{$row}:G{$row}")->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FF0D47A1');
    $sh->getStyle("A{$row}:G{$row}")->getAlignment()
        ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    $row++;
    if ($choices === []) {
        $sh->mergeCells("A{$row}:G{$row}")->setCellValue("A{$row}", 'No results match the selected filters.');
        return $row + 1;
    }
    $dataStart = $row;
    foreach ($choices as $r) {
        $twg = results_export_numeric($r['twg_average'] ?? null);
        $values = [
            $r['rank'] !== '' ? $r['rank'] : '—',
            $r['choice_name'],
            (int) $r['vote_count'],
            results_export_numeric($r['vote_share'] ?? null),
            results_export_numeric($r['community_score'] ?? null),
            $twg,
            results_export_numeric($r['final_score'] ?? null),
        ];
        foreach ($values as $i => $value) {
            if ($value === null) {
                $sh->setCellValue($cols[$i] . $row, '—');
            } else {
                $sh->setCellValue($cols[$i] . $row, $value);
            }
        }
        $row++;
    }
    $dataEnd = $row - 1;
    $sh->getStyle("A{$dataStart}:A{$dataEnd}")->getAlignment()
        ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    $sh->getStyle("C{$dataStart}:G{$dataEnd}")->getAlignment()
        ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    $sh->getStyle("C{$dataStart}:C{$dataEnd}")->getNumberFormat()->setFormatCode('#,##0');
    $sh->getStyle("D{$dataStart}:G{$dataEnd}")->getNumberFormat()->setFormatCode('0.00');
    return $row;
}

function results_export_excel_widths(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sh): void
{
    $sh->getColumnDimension('A')->setWidth(10);
    $sh->getColumnDimension('B')->setWidth(36);
    $sh->getColumnDimension('C')->setWidth(10);
    $sh->getColumnDimension('D')->setWidth(14);
    $sh->getColumnDimension('E')->setWidth(18);
    $sh->getColumnDimension('F')->setWidth(14);
    $sh->getColumnDimension('G')->setWidth(12);
}

date_default_timezone_set('Asia/Manila');

$format = isset($_GET['format']) ? strtolower(trim((string) $_GET['format'])) : 'csv';
$scope = isset($_GET['scope']) ? strtolower(trim((string) $_GET['scope'])) : 'all';
$top = max(0, (int) ($_GET['top'] ?? 0));
$eventId = (isset($_GET['event_id']) && $_GET['event_id'] !== '') ? (int) $_GET['event_id'] : null;
$categoryId = (isset($_GET['category_id']) && $_GET['category_id'] !== '') ? (int) $_GET['category_id'] : null;
$questionId = (isset($_GET['question_id']) && $_GET['question_id'] !== '') ? (int) $_GET['question_id'] : null;

$activeEventId = admin_get_active_event_id($conn);
if ($activeEventId === null || $activeEventId <= 0) {
    results_export_text_error('No active event. Activate an event under File Maintenance → Events before exporting.', 403);
}
if (!$eventId || $eventId <= 0) {
    $eventId = $activeEventId;
}
if ((int) $eventId !== (int) $activeEventId) {
    results_export_text_error('Exports are limited to the currently active event.', 403);
}

if (isset($_GET['preflight']) && $_GET['preflight'] === '1') {
    header('Content-Type: application/json; charset=UTF-8');
    if (!in_array($format, ['pdf', 'excel', 'csv'], true)) {
        echo json_encode(['status' => 'error', 'message' => 'Unsupported format.']);
        exit;
    }
    $settings = report_export_get_settings($conn);
    echo json_encode([
        'status' => 'success',
        'format' => $format,
        'credentials' => report_export_build_credentials_payload($settings, $format),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!in_array($format, ['pdf', 'excel', 'csv'], true)) {
    results_export_text_error('Unsupported format.', 400);
}

$results = [];
$categoryLabel = null;
$awardLabel = null;

if ($scope === 'all') {
    $qStmt = $conn->prepare("
        SELECT q.question_id, q.question_name, c.category_name
        FROM tbl_questions q
        JOIN tbl_categories c ON q.category_id = c.category_id
        WHERE c.event_id = ?
        ORDER BY c.category_name ASC, q.question_name ASC
    ");
    $qStmt->bind_param('i', $eventId);
    $qStmt->execute();
    $qRes = $qStmt->get_result();
    while ($qRow = $qRes->fetch_assoc()) {
        $qResults = getResultsForQuestion($conn, $eventId, (int) $qRow['question_id'], $top);
        foreach ($qResults as $r) {
            $results[] = results_export_map_row(
                $r,
                (string) $qRow['category_name'],
                (string) $qRow['question_name']
            );
        }
    }
    $qStmt->close();
} elseif ($scope === 'current' && $categoryId && $questionId) {
    $stmtNames = $conn->prepare("
        SELECT c.category_name, q.question_name
        FROM tbl_questions q
        JOIN tbl_categories c ON q.category_id = c.category_id
        WHERE q.question_id = ? AND c.category_id = ? AND c.event_id = ?
    ");
    $stmtNames->bind_param('iii', $questionId, $categoryId, $eventId);
    $stmtNames->execute();
    $names = $stmtNames->get_result()->fetch_assoc();
    $stmtNames->close();

    if (!$names) {
        results_export_text_error('Invalid category or award for this event.', 400);
    }

    $categoryLabel = (string) $names['category_name'];
    $awardLabel = (string) $names['question_name'];

    $qResults = getResultsForQuestion($conn, $eventId, $questionId, $top);
    foreach ($qResults as $r) {
        $results[] = results_export_map_row($r, $categoryLabel, $awardLabel);
    }
} else {
    results_export_text_error('Invalid scope or missing parameters.', 400);
}

$totalCount = count($results);

$eventLabel = null;
$eventYear = null;
$stmt = $conn->prepare('SELECT event_name, year FROM tbl_events WHERE event_id = ? LIMIT 1');
if ($stmt) {
    $stmt->bind_param('i', $eventId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        $eventLabel = (string) ($row['event_name'] ?? '');
        $eventYear = !empty($row['year']) ? (int) $row['year'] : null;
    }
}

$filterRows = report_export_build_results_filter_rows(
    $eventLabel,
    $eventYear,
    $categoryLabel,
    $awardLabel,
    $scope,
    $top
);

$titleText = "Tatak Ormoc Consumer's Choice Awards";
$subtitleText = 'Voting Results';
$nowDt = new DateTimeImmutable('now');
$generatedText = 'Generated on: ' . $nowDt->format('F j, Y - g:i A');
$adminName = (string) ($_SESSION['admin_name'] ?? $_SESSION['username'] ?? 'Admin');
$adminId = (int) ($_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0);
$docRef = sprintf('TOCCA-VR-%s-%s', $nowDt->format('Ymd-His'), $adminId ? ('U' . $adminId) : 'U0');
$yearForName = $eventYear ?: (int) $nowDt->format('Y');
$fname = results_export_safe_filename(sprintf('TOCCA_%d_VotingResults_%s', $yearForName, $nowDt->format('Ymd_Hi')));

$reportSettings = report_export_get_settings($conn);
$orgLineText = $reportSettings['org_line'];

$logoAbsPath = null;
try {
    require_once __DIR__ . '/get_logo.php';
    $logoCfg = function_exists('getConfig') ? trim((string) getConfig('logo_path', '')) : '';
    if ($logoCfg !== '') {
        $candidate = __DIR__ . '/' . ltrim(preg_replace('~^https?://[^/]+~', '', $logoCfg), '/');
        if (is_file($candidate)) {
            $logoAbsPath = $candidate;
        }
    }
    if (!$logoAbsPath) {
        foreach (['img/default-logo.png', 'img/tatakormoclogo.png', 'img/tocca2023.jpg'] as $cand) {
            $cp = __DIR__ . '/' . $cand;
            if (is_file($cp)) {
                $logoAbsPath = $cp;
                break;
            }
        }
    }
} catch (Throwable $e) {
    $logoAbsPath = null;
}

try {
    if (function_exists('audit_log')) {
        audit_log($conn, 'results', 'export', 'voting_results', $eventId, [
            'format' => $format,
            'scope' => $scope,
            'event_id' => $eventId,
            'event_name' => $eventLabel,
            'category_id' => $categoryId,
            'question_id' => $questionId,
            'top' => $top,
            'rows' => $totalCount,
            'doc_ref' => $docRef,
        ]);
    }
} catch (Throwable $e) {
    // never block export
}

// ---- PDF -------------------------------------------------------------------
if ($format === 'pdf') {
    if (!class_exists('\\Mpdf\\Mpdf')) {
        $msg = 'PDF export not available (mPDF not installed).';
        if ($autoloadLoaded === null) {
            $msg .= ' Autoloader not found.';
        }
        results_export_text_error($msg, 501);
    }

    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4-L',
        'margin_left' => 12,
        'margin_right' => 12,
        'margin_top' => 32,
        'margin_bottom' => 18,
        'margin_header' => 8,
        'margin_footer' => 8,
    ]);

    $mpdf->SetTitle($titleText . ' — Voting Results');
    $mpdf->SetAuthor($adminName);
    $mpdf->SetCreator('Tatak Ormoc CCA Admin Portal');
    $mpdf->SetSubject('Voting Results');

    $logoHtml = $logoAbsPath
        ? '<img src="' . htmlspecialchars($logoAbsPath, ENT_QUOTES) . '" style="width:38px;height:38px;object-fit:contain;">'
        : '<div style="width:38px;height:38px;border:1px solid #888;border-radius:4px;text-align:center;line-height:38px;font-weight:700;color:#666;">T</div>';

    $mpdf->SetHTMLHeader('
  <table width="100%" style="font-family:sans-serif;font-size:9pt;color:#222;border-bottom:1.5px solid #0d47a1;">
    <tr>
      <td width="50" valign="middle">' . $logoHtml . '</td>
      <td valign="middle" style="padding-left:8px;">
        <div style="font-size:11pt;font-weight:700;color:#0d47a1;">' . htmlspecialchars($titleText, ENT_QUOTES) . '</div>
        <div style="font-size:8.5pt;color:#555;">' . htmlspecialchars($orgLineText, ENT_QUOTES) . '</div>
      </td>
      <td align="right" valign="middle">
        <div style="font-size:8pt;color:#666;">Doc Ref: <strong>' . htmlspecialchars($docRef, ENT_QUOTES) . '</strong></div>
        <div style="font-size:8pt;color:#666;">Page {PAGENO} of {nbpg}</div>
      </td>
    </tr>
  </table>');

    $mpdf->SetHTMLFooter('
  <table width="100%" style="font-family:sans-serif;font-size:7.5pt;color:#666;border-top:1px solid #ccc;padding-top:3px;">
    <tr>
      <td>Generated by <strong>' . htmlspecialchars($adminName, ENT_QUOTES) . '</strong> on ' . htmlspecialchars($nowDt->format('F j, Y g:i A'), ENT_QUOTES) . ' (Asia/Manila)</td>
      <td align="right">Page {PAGENO} of {nbpg}</td>
    </tr>
  </table>');

    $html = report_export_pdf_styles();
    $html .= '<div class="title-band"><div class="doc-title">' . htmlspecialchars($subtitleText, ENT_QUOTES) . '</div>'
        . '<div class="doc-subtitle">' . htmlspecialchars($generatedText, ENT_QUOTES) . '</div></div>';
    $html .= report_export_render_pdf_filter_table($filterRows);
    $html .= results_export_render_pdf_tables($results, $scope);
    $html .= '<table class="data-table" style="margin-top:10px;"><tbody><tr class="total-row">'
        . '<td colspan="6" style="text-align:right;">TOTAL RESULT ROWS</td>'
        . '<td class="center">' . $totalCount . '</td></tr></tbody></table>';

    $mpdf->WriteHTML($html);

    if ($reportSettings['pdf_password_enabled'] && $reportSettings['pdf_password'] !== '') {
        $mpdf->SetProtection(['print'], $reportSettings['pdf_password'], null, 128);
    }

    results_export_secure_headers('application/pdf', 'attachment; filename="' . $fname . '.pdf"');
    $mpdf->Output($fname . '.pdf', 'D');
    exit;
}

// ---- Excel -----------------------------------------------------------------
if ($format === 'excel') {
    if (!class_exists('\\PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
        $msg = 'Excel export not available (PhpSpreadsheet not installed).';
        if ($autoloadLoaded === null) {
            $msg .= ' Autoloader not found.';
        }
        results_export_text_error($msg, 501);
    }

    $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $ss->getProperties()
        ->setCreator($adminName)
        ->setTitle($titleText . ' — Voting Results')
        ->setSubject('Voting Results')
        ->setDescription('Doc Ref: ' . $docRef);

    $applyLetterhead = static function (
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sh,
        string $sheetSubtitle,
        int $colCount
    ) use ($titleText, $orgLineText, $docRef, $generatedText, $logoAbsPath): void {
        $lastCol = chr(ord('A') + max(0, $colCount - 1));
        $merge = 'A1:' . $lastCol . '1';
        $sh->mergeCells($merge)->setCellValue('A1', $titleText);
        $sh->getStyle('A1')->getFont()->setBold(true)->setSize(18)->getColor()->setARGB('FF0D47A1');
        $sh->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        if ($logoAbsPath) {
            try {
                $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
                $drawing->setPath($logoAbsPath);
                $drawing->setHeight(48);
                $drawing->setCoordinates('A1');
                $drawing->setWorksheet($sh);
            } catch (Throwable $e) {
                // optional
            }
        }

        $sh->mergeCells('A2:' . $lastCol . '2')->setCellValue('A2', $sheetSubtitle);
        $sh->mergeCells('A3:' . $lastCol . '3')->setCellValue('A3', $orgLineText);
        $sh->mergeCells('A4:' . $lastCol . '4')->setCellValue('A4', 'Doc Ref: ' . $docRef . '   |   ' . $generatedText);
    };

    $writeContext = static function (\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sh, int $startRow, array $filterRows): int {
        $row = $startRow;
        report_export_apply_excel_section_header($sh, $row, 'Report Context', 'G');
        $row++;
        foreach ($filterRows as [$label, $value]) {
            report_export_apply_excel_meta_row($sh, $row, $label, $value, 'G');
            $row++;
        }
        return $row + 1;
    };

    if ($scope === 'all') {
        $grouped = results_export_group($results);
        $sheetIndex = 0;
        foreach ($grouped as $category => $questions) {
            $sh = $sheetIndex === 0 ? $ss->getActiveSheet() : $ss->createSheet($sheetIndex);
            $title = mb_substr(preg_replace('/[\\\\\\/\\?\\*:\\[\\]]+/', ' ', $category), 0, 31);
            $sh->setTitle($title === '' ? 'Results' : $title);
            $applyLetterhead($sh, $category . ' — Results', 7);
            $row = $writeContext($sh, 6, $filterRows);
            report_export_apply_excel_section_header($sh, $row, 'Standing by final score', 'G');
            $row++;

            foreach ($questions as $question => $choices) {
                $sh->mergeCells("A{$row}:G{$row}")->setCellValue("A{$row}", $question);
                $sh->getStyle("A{$row}")->getFont()->setBold(true);
                $row++;
                $row = results_export_write_excel_table($sh, $row, $choices);
                $row++;
            }
            $sh->setCellValue("F{$row}", 'TOTAL RESULT ROWS');
            $sh->setCellValue("G{$row}", array_sum(array_map('count', $questions)));
            $sh->getStyle("F{$row}:G{$row}")->getFont()->setBold(true);
            results_export_excel_widths($sh);
            $sheetIndex++;
        }
        if ($sheetIndex === 0) {
            $sh = $ss->getActiveSheet();
            $sh->setTitle('Results');
            $applyLetterhead($sh, 'Voting Results', 7);
            $row = $writeContext($sh, 6, $filterRows);
            $sh->mergeCells("A{$row}:G{$row}")->setCellValue("A{$row}", 'No results match the selected filters.');
        }
    } else {
        $sh = $ss->getActiveSheet();
        $sh->setTitle(mb_substr($categoryLabel ?: 'Results', 0, 31));
        $applyLetterhead($sh, ($categoryLabel && $awardLabel) ? ($categoryLabel . ' – ' . $awardLabel) : $subtitleText, 7);
        $row = $writeContext($sh, 6, $filterRows);
        report_export_apply_excel_section_header($sh, $row, 'Standing by final score', 'G');
        $row++;
        $row = results_export_write_excel_table($sh, $row, $results);
        $sh->setCellValue("F{$row}", 'TOTAL RESULT ROWS');
        $sh->setCellValue("G{$row}", $totalCount);
        $sh->getStyle("F{$row}:G{$row}")->getFont()->setBold(true);
        results_export_excel_widths($sh);
    }

    if ($reportSettings['excel_protect_enabled'] && $reportSettings['excel_password'] !== '') {
        $editPassword = $reportSettings['excel_password'];
        $ss->getSecurity()->setLockStructure(true);
        $ss->getSecurity()->setLockWindows(true);
        $ss->getSecurity()->setWorkbookPassword($editPassword);
        foreach ($ss->getAllSheets() as $sheet) {
            $sheet->getProtection()->setSheet(true);
            $sheet->getProtection()->setPassword($editPassword);
        }
    }

    results_export_secure_headers(
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'attachment; filename="' . $fname . '.xlsx"'
    );
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save('php://output');
    exit;
}

// ---- CSV -------------------------------------------------------------------
$csvRecordRows = results_export_csv_rows($results, $scope);
$csvRecordRows[] = $scope === 'all'
    ? ['', '', '', 'TOTAL RESULT ROWS', '', '', '', '', $totalCount]
    : ['', 'TOTAL RESULT ROWS', '', '', '', '', $totalCount];

results_export_secure_headers('text/csv; charset=UTF-8', 'attachment; filename="' . $fname . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
report_export_write_csv_sections($out, [
    [
        'title' => 'Document',
        'rows' => [
            [$titleText],
            [$subtitleText],
            [$orgLineText],
            ['Doc Ref', $docRef],
            ['Generated', $generatedText],
            ['Generated by', $adminName],
        ],
    ],
    [
        'title' => 'Report Context',
        'rows' => array_map(static fn ($pair) => [$pair[0], $pair[1]], $filterRows),
    ],
    [
        'title' => 'Standing by final score',
        'rows' => $csvRecordRows,
    ],
]);
fclose($out);
exit;

/**
 * @return list<array{choice_id:?int,choice_name:string,vote_count:int,display_rank:string,rank:int}>
 */
function getResultsForQuestion(mysqli $conn, int $event_id, int $question_id, int $top): array
{
    $payload = results_formula_fetch_for_award($conn, $event_id, $question_id);
    $data = $payload['results'];

    if ($top > 0) {
        $trimmed = [];
        foreach ($data as $row) {
            if ((int) ($row['rank'] ?? 0) > $top) {
                break;
            }
            $trimmed[] = $row;
        }
        $data = $trimmed;
    }

    return $data;
}
