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

/** @param list<array{category:string,question:string,rank:string,choice_name:string,vote_count:int}> $results */
function results_export_csv_rows(array $results, string $scope): array
{
    $headers = $scope === 'all'
        ? ['Category', 'Award', 'Rank', 'Business', 'Votes', 'Vote share %', 'Community 70%', 'TWG 30%', 'Final score', 'Top 10']
        : ['Rank', 'Business', 'Votes', 'Vote share %', 'Community 70%', 'TWG 30%', 'Final score', 'Top 10'];

    if ($results === []) {
        $empty = $scope === 'all'
            ? ['', 'No results match the selected filters.', '', '', '', '', '', '', '', '']
            : ['', 'No results match the selected filters.', '', '', '', '', '', ''];
        return [$headers, $empty];
    }

    $rows = [$headers];
    foreach ($results as $row) {
        $rank = $row['rank'] !== '' ? $row['rank'] : '—';
        if ($scope === 'all') {
            $rows[] = [
                $row['category'],
                $row['question'],
                $rank,
                $row['choice_name'],
                (int) $row['vote_count'],
                $row['vote_share'] ?? '',
                $row['community_score'] ?? '',
                $row['twg_average'] ?? '',
                $row['final_score'] ?? '',
                !empty($row['top10']) ? 'Yes' : '',
            ];
        } else {
            $rows[] = [
                $rank,
                $row['choice_name'],
                (int) $row['vote_count'],
                $row['vote_share'] ?? '',
                $row['community_score'] ?? '',
                $row['twg_average'] ?? '',
                $row['final_score'] ?? '',
                !empty($row['top10']) ? 'Yes' : '',
            ];
        }
    }
    return $rows;
}

/** @param list<array{category:string,question:string,rank:string,choice_name:string,vote_count:int}> $results */
function results_export_render_pdf_tables(array $results, string $scope): string
{
    $html = '<div class="section-label">Voting Results</div>';

    if ($results === []) {
        return $html . '<table class="data-table"><tbody><tr><td class="empty">No results match the selected filters.</td></tr></tbody></table>';
    }

    if ($scope === 'all') {
        foreach (results_export_group($results) as $category => $questions) {
            $html .= '<div class="section-label" style="margin-top:10px;">' . htmlspecialchars($category, ENT_QUOTES) . '</div>';
            foreach ($questions as $question => $choices) {
                $html .= '<div class="doc-subtitle" style="text-align:left;margin:6px 0;">' . htmlspecialchars($question, ENT_QUOTES) . '</div>';
                $html .= '<table class="data-table"><thead><tr>'
                    . '<th class="center" style="width:48px;">Rank</th>'
                    . '<th>Business</th>'
                    . '<th class="center" style="width:72px;">Votes</th>'
                    . '</tr></thead><tbody>';
                foreach ($choices as $i => $row) {
                    $cls = ($i % 2 === 1) ? ' class="alt"' : '';
                    $rank = $row['rank'] !== '' ? htmlspecialchars((string) $row['rank'], ENT_QUOTES) : '<span class="muted">—</span>';
                    $html .= '<tr' . $cls . '>'
                        . '<td class="num center">' . $rank . '</td>'
                        . '<td>' . htmlspecialchars((string) $row['choice_name'], ENT_QUOTES) . '</td>'
                        . '<td class="center">' . (int) $row['vote_count'] . '</td>'
                        . '</tr>';
                }
                $html .= '</tbody></table>';
            }
        }
        return $html;
    }

    $html .= '<table class="data-table"><thead><tr>'
        . '<th class="center" style="width:48px;">Rank</th>'
        . '<th>Business</th>'
        . '<th class="center" style="width:72px;">Votes</th>'
        . '</tr></thead><tbody>';
    foreach ($results as $i => $row) {
        $cls = ($i % 2 === 1) ? ' class="alt"' : '';
        $rank = $row['rank'] !== '' ? htmlspecialchars((string) $row['rank'], ENT_QUOTES) : '<span class="muted">—</span>';
        $html .= '<tr' . $cls . '>'
            . '<td class="num center">' . $rank . '</td>'
            . '<td>' . htmlspecialchars((string) $row['choice_name'], ENT_QUOTES) . '</td>'
            . '<td class="center">' . (int) $row['vote_count'] . '</td>'
            . '</tr>';
    }
    $html .= '</tbody></table>';
    return $html;
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
            $results[] = [
                'category' => (string) $qRow['category_name'],
                'question' => (string) $qRow['question_name'],
                'rank' => (string) $r['display_rank'],
                'choice_name' => (string) $r['choice_name'],
                'vote_count' => (int) $r['vote_count'],
                'vote_share' => $r['vote_share'] ?? '',
                'community_score' => $r['community_score'] ?? '',
                'twg_average' => $r['twg_average'] ?? '',
                'final_score' => $r['final_score'] ?? '',
                'top10' => !empty($r['top10']),
            ];
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
        $results[] = [
            'category' => $categoryLabel,
            'question' => $awardLabel,
            'rank' => (string) $r['display_rank'],
            'choice_name' => (string) $r['choice_name'],
            'vote_count' => (int) $r['vote_count'],
            'vote_share' => $r['vote_share'] ?? '',
            'community_score' => $r['community_score'] ?? '',
            'twg_average' => $r['twg_average'] ?? '',
            'final_score' => $r['final_score'] ?? '',
            'top10' => !empty($r['top10']),
        ];
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
$subtitleText = 'Official Voting Results';
$nowDt = new DateTimeImmutable('now');
$generatedText = 'Generated on: ' . $nowDt->format('F j, Y - g:i A');
$adminName = (string) ($_SESSION['admin_name'] ?? $_SESSION['username'] ?? 'Admin');
$adminId = (int) ($_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0);
$docRef = sprintf('TOCCA-VR-%s-%s', $nowDt->format('Ymd-His'), $adminId ? ('U' . $adminId) : 'U0');
$yearForName = $eventYear ?: (int) $nowDt->format('Y');
$fname = results_export_safe_filename(sprintf('TOCCA_%d_VotingResults_%s', $yearForName, $nowDt->format('Ymd_Hi')));

$reportSettings = report_export_get_settings($conn);
$orgLineText = $reportSettings['org_line'];
$reviewerLabel = $reportSettings['reviewer_label'];
$approverLabel = $reportSettings['approver_label'];
$adminUsername = (string) ($_SESSION['username'] ?? '');
$signatureAbsPath = report_export_get_admin_signature_abs($conn, $adminUsername, __DIR__);
$reviewerSigAbs = report_export_resolve_abs_path($reportSettings['reviewer_signature_path'] ?? '', __DIR__);
$approverSigAbs = report_export_resolve_abs_path($reportSettings['approver_signature_path'] ?? '', __DIR__);
$signatureDateLabel = $nowDt->format('M j, Y');
$preparedSigCellHtml = report_export_build_pdf_signature_cell($signatureAbsPath, $adminName, $signatureDateLabel);
$reviewerSigCellHtml = report_export_build_pdf_role_cell('Reviewed by', $reviewerLabel, $reviewerSigAbs);
$approverSigCellHtml = report_export_build_pdf_role_cell('Approved by', $approverLabel, $approverSigAbs);

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
        'format' => 'A4',
        'margin_left' => 14,
        'margin_right' => 14,
        'margin_top' => 38,
        'margin_bottom' => 26,
        'margin_header' => 8,
        'margin_footer' => 8,
    ]);

    $mpdf->SetTitle($titleText . ' — Official Voting Results');
    $mpdf->SetAuthor($adminName);
    $mpdf->SetCreator('Tatak Ormoc CCA Admin Portal');
    $mpdf->SetSubject('CONFIDENTIAL — Voting Results');
    $mpdf->SetWatermarkText('CONFIDENTIAL');
    $mpdf->showWatermarkText = true;
    $mpdf->watermarkTextAlpha = 0.04;

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
        <div style="margin-top:3px;display:inline-block;background:#B22222;color:#fff;padding:2px 8px;font-size:7.5pt;font-weight:700;">CONFIDENTIAL</div>
      </td>
    </tr>
  </table>');

    $mpdf->SetHTMLFooter('
  <table width="100%" style="font-family:sans-serif;font-size:7.5pt;color:#666;border-top:1px solid #ccc;padding-top:3px;">
    <tr>
      <td>Generated by <strong>' . htmlspecialchars($adminName, ENT_QUOTES) . '</strong> on ' . htmlspecialchars($nowDt->format('F j, Y g:i A'), ENT_QUOTES) . ' (Asia/Manila)</td>
      <td align="center"><strong style="color:#B22222;">CONFIDENTIAL — For Authorized Review Only</strong></td>
      <td align="right">Page {PAGENO} of {nbpg}</td>
    </tr>
  </table>');

    $html = report_export_pdf_styles();
    $html .= '<div class="title-band"><div class="doc-title">' . htmlspecialchars($subtitleText, ENT_QUOTES) . '</div>'
        . '<div class="doc-subtitle">' . htmlspecialchars($generatedText, ENT_QUOTES) . '</div></div>';
    $html .= report_export_render_pdf_filter_table($filterRows);
    $html .= results_export_render_pdf_tables($results, $scope);
    $html .= '<table class="data-table" style="margin-top:10px;"><tbody><tr class="total-row">'
        . '<td colspan="2" style="text-align:right;">TOTAL RESULT ROWS</td>'
        . '<td class="center">' . $totalCount . '</td></tr></tbody></table>';
    $html .= report_export_render_pdf_signatures_table($preparedSigCellHtml, $reviewerSigCellHtml, $approverSigCellHtml);

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
        ->setTitle($titleText . ' — Official Voting Results')
        ->setSubject('CONFIDENTIAL — Voting Results')
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
        $sh->mergeCells('A4:' . $lastCol . '4')->setCellValue('A4', 'CONFIDENTIAL — For Authorized Review Only');
        $sh->getStyle('A4')->getFont()->setBold(true)->getColor()->setARGB('FFB22222');
        $sh->mergeCells('A5:' . $lastCol . '5')->setCellValue('A5', 'Doc Ref: ' . $docRef . '   |   ' . $generatedText);
    };

    $writeContext = static function (\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sh, int $startRow, array $filterRows): int {
        $row = $startRow;
        report_export_apply_excel_section_header($sh, $row, 'Report Context');
        $row++;
        foreach ($filterRows as [$label, $value]) {
            report_export_apply_excel_meta_row($sh, $row, $label, $value);
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
            $applyLetterhead($sh, $category . ' — Results', 3);
            $row = $writeContext($sh, 7, $filterRows);
            report_export_apply_excel_section_header($sh, $row, 'Voting Results');
            $row++;

            foreach ($questions as $question => $choices) {
                $sh->mergeCells("A{$row}:C{$row}")->setCellValue("A{$row}", $question);
                $sh->getStyle("A{$row}")->getFont()->setBold(true);
                $row++;
                $sh->setCellValue("A{$row}", 'Rank')->setCellValue("B{$row}", 'Business')->setCellValue("C{$row}", 'Votes');
                $sh->getStyle("A{$row}:C{$row}")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
                $sh->getStyle("A{$row}:C{$row}")->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FF0D47A1');
                $row++;
                foreach ($choices as $r) {
                    $sh->setCellValue("A{$row}", $r['rank'] !== '' ? $r['rank'] : '—');
                    $sh->setCellValue("B{$row}", $r['choice_name']);
                    $sh->setCellValue("C{$row}", (int) $r['vote_count']);
                    $row++;
                }
                $row++;
            }
            $sh->getColumnDimension('A')->setWidth(10);
            $sh->getColumnDimension('B')->setWidth(36);
            $sh->getColumnDimension('C')->setWidth(12);
            $sheetIndex++;
        }
        if ($sheetIndex === 0) {
            $sh = $ss->getActiveSheet();
            $sh->setTitle('Results');
            $applyLetterhead($sh, 'Voting Results', 3);
            $row = $writeContext($sh, 7, $filterRows);
            $sh->mergeCells("A{$row}:C{$row}")->setCellValue("A{$row}", 'No results match the selected filters.');
        }
    } else {
        $sh = $ss->getActiveSheet();
        $sh->setTitle(mb_substr($categoryLabel ?: 'Results', 0, 31));
        $applyLetterhead($sh, ($categoryLabel && $awardLabel) ? ($categoryLabel . ' – ' . $awardLabel) : $subtitleText, 3);
        $row = $writeContext($sh, 7, $filterRows);
        report_export_apply_excel_section_header($sh, $row, 'Voting Results');
        $row++;
        $sh->setCellValue("A{$row}", 'Rank')->setCellValue("B{$row}", 'Business')->setCellValue("C{$row}", 'Votes');
        $sh->getStyle("A{$row}:C{$row}")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sh->getStyle("A{$row}:C{$row}")->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FF0D47A1');
        $row++;
        if ($results === []) {
            $sh->mergeCells("A{$row}:C{$row}")->setCellValue("A{$row}", 'No results match the selected filters.');
        } else {
            foreach ($results as $r) {
                $sh->setCellValue("A{$row}", $r['rank'] !== '' ? $r['rank'] : '—');
                $sh->setCellValue("B{$row}", $r['choice_name']);
                $sh->setCellValue("C{$row}", (int) $r['vote_count']);
                $row++;
            }
        }
        $sh->setCellValue("B{$row}", 'TOTAL RESULT ROWS');
        $sh->setCellValue("C{$row}", $totalCount);
        $sh->getStyle("B{$row}:C{$row}")->getFont()->setBold(true);
        $sh->getColumnDimension('A')->setWidth(10);
        $sh->getColumnDimension('B')->setWidth(36);
        $sh->getColumnDimension('C')->setWidth(12);
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
    ? ['', '', '', 'TOTAL RESULT ROWS', $totalCount]
    : ['', 'TOTAL RESULT ROWS', $totalCount];

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
            ['CONFIDENTIAL — For Authorized Review Only'],
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
        'title' => 'Voting Results',
        'rows' => $csvRecordRows,
    ],
    [
        'title' => 'Certification',
        'rows' => [
            ['Prepared by', 'Reviewed by', '', 'Approved by'],
            [$adminName . ' — ' . $signatureDateLabel, $reviewerLabel, '', $approverLabel],
        ],
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
