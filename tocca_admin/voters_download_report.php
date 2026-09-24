<?php
declare(strict_types=1);

require_once __DIR__ . '/require_admin_session.php';
require_once __DIR__ . '/audit_log.php';
require_once __DIR__ . '/includes/report_export_helpers.php';
require_once __DIR__ . '/includes/voters_list_data.php';
tocca_admin_require_login(false);

require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/includes/admin_active_event.php';

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

function voters_export_text_error(string $message, int $code = 400): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $message;
    exit;
}

function voters_export_secure_headers(string $contentType, string $disposition): void
{
    header('Content-Type: ' . $contentType);
    header('Content-Disposition: ' . $disposition);
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
}

date_default_timezone_set('Asia/Manila');

$format = isset($_GET['format']) ? strtolower(trim((string) $_GET['format'])) : 'csv';
$eventId = (isset($_GET['event_id']) && $_GET['event_id'] !== '') ? (int) $_GET['event_id'] : null;

$activeEventId = admin_get_active_event_id($conn);
if ($activeEventId === null || $activeEventId <= 0) {
    voters_export_text_error('No active event. Activate an event under File Maintenance → Events before exporting.', 403);
}
if (!$eventId || $eventId <= 0) {
    $eventId = $activeEventId;
}
if ((int) $eventId !== (int) $activeEventId) {
    voters_export_text_error('Exports are limited to the currently active event.', 403);
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
    voters_export_text_error('Unsupported format.', 400);
}

$rows = voters_list_rows_for_event($conn, (int) $eventId);
$totalCount = count($rows);

$eventLabel = '';
$stmt = $conn->prepare('SELECT event_name FROM tbl_events WHERE event_id = ? LIMIT 1');
if ($stmt) {
    $stmt->bind_param('i', $eventId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && ($row = $res->fetch_assoc())) {
        $eventLabel = (string) ($row['event_name'] ?? '');
    }
    $stmt->close();
}

$settings = report_export_get_settings($conn);
$nowDt = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
$generatedText = $nowDt->format('F j, Y g:i A');
$adminName = (string) ($_SESSION['admin_name'] ?? $_SESSION['username'] ?? 'Administrator');
$adminId = (int) ($_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0);
$docRef = sprintf('TOCCA-VL-%s-%s', $nowDt->format('Ymd-His'), $adminId ? ('U' . $adminId) : 'U0');

$pad = static fn (int $n): string => str_pad((string) $n, 2, '0', STR_PAD_LEFT);
$fname = 'TOCCA_VotersList_'
    . $nowDt->format('Y')
    . $pad((int) $nowDt->format('n'))
    . $pad((int) $nowDt->format('j'))
    . '_'
    . $pad((int) $nowDt->format('G'))
    . $pad((int) $nowDt->format('i'));

$titleText = 'Voters Report';
$subtitleText = $eventLabel !== '' ? $eventLabel : 'Active event';

try {
    if (function_exists('audit_log')) {
        audit_log(
            $conn,
            'voters',
            'export',
            'voters_list',
            $eventId,
            [
                'format' => $format,
                'event_id' => $eventId,
                'event_name' => $eventLabel,
                'rows' => $totalCount,
                'doc_ref' => $docRef,
            ]
        );
    }
} catch (Throwable $e) {
    // never block export
}

// ---- CSV -------------------------------------------------------------------
if ($format === 'csv') {
    voters_export_secure_headers('text/csv; charset=UTF-8', 'attachment; filename="' . $fname . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    report_export_write_csv_sections($out, [
        [
            'title' => 'Document',
            'rows' => [
                [$titleText],
                [$subtitleText],
                ['Doc Ref', $docRef],
                ['Generated', $generatedText],
                ['Generated by', $adminName],
            ],
        ],
        [
            'title' => 'Report Context',
            'rows' => [
                ['Event', $subtitleText],
                ['Records', (string) $totalCount],
            ],
        ],
        [
            'title' => 'Voter Records',
            'rows' => array_merge(
                [['Voter ID', 'Phone Number', 'Date Added', 'Vote Status']],
                $totalCount > 0
                    ? array_map(static function (array $r): array {
                        return [
                            $r['voters_id'],
                            $r['mobile_number'],
                            $r['date_verified'],
                            voters_list_status_label($r['status']),
                        ];
                    }, $rows)
                    : [['', 'No voter data found.', '', '']],
                [['', '', 'TOTAL VOTERS', $totalCount]]
            ),
        ],
    ]);
    fclose($out);
    exit;
}

// ---- Excel -----------------------------------------------------------------
if ($format === 'excel') {
    if (!class_exists('\\PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
        $msg = 'Excel export not available (PhpSpreadsheet not installed).';
        if ($autoloadLoaded === null) {
            $msg .= ' Autoloader not found.';
        }
        voters_export_text_error($msg, 501);
    }

    $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sh = $ss->getActiveSheet();
    $sh->setTitle('Voters');

    $ss->getProperties()
        ->setCreator($adminName)
        ->setTitle($titleText)
        ->setSubject('Voters List')
        ->setDescription('Doc Ref: ' . $docRef);

    $sh->mergeCells('A1:D1')->setCellValue('A1', $titleText);
    $sh->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setARGB('FF0D47A1');
    $sh->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    $sh->mergeCells('A2:D2')->setCellValue('A2', $subtitleText);
    $sh->getStyle('A2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    $sh->mergeCells('A3:D3')->setCellValue('A3', 'Doc Ref: ' . $docRef . '   |   Generated: ' . $generatedText);
    $sh->getStyle('A3')->getFont()->setSize(9)->setItalic(true)->getColor()->setARGB('FF777777');
    $sh->getStyle('A3')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

    $contextRow = 5;
    report_export_apply_excel_section_header($sh, $contextRow, 'Report Context');
    $contextRow++;
    report_export_apply_excel_meta_row($sh, $contextRow, 'Event', $subtitleText);
    $contextRow++;
    report_export_apply_excel_meta_row($sh, $contextRow, 'Generated by', $adminName);

    $headerRow = $contextRow + 2;
    report_export_apply_excel_section_header($sh, $headerRow - 1, 'Voter Records');
    $headers = ['Voter ID', 'Phone Number', 'Date Added', 'Vote Status'];
    foreach ($headers as $i => $label) {
        $col = chr(ord('A') + $i);
        $sh->setCellValue($col . $headerRow, $label);
    }
    $sh->getStyle("A{$headerRow}:D{$headerRow}")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
    $sh->getStyle("A{$headerRow}:D{$headerRow}")->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FF0D47A1');
    $sh->getStyle("A{$headerRow}:D{$headerRow}")->getAlignment()
        ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

    $row = $headerRow + 1;
    if ($totalCount === 0) {
        $sh->mergeCells("A{$row}:D{$row}")->setCellValue("A{$row}", 'No voter data found.');
        $row++;
    } else {
        foreach ($rows as $r) {
            $sh->setCellValue("A{$row}", $r['voters_id']);
            $sh->setCellValue("B{$row}", $r['mobile_number']);
            $sh->setCellValue("C{$row}", $r['date_verified']);
            $sh->setCellValue("D{$row}", voters_list_status_label($r['status']));
            $row++;
        }
    }
    $sh->setCellValue("C{$row}", 'TOTAL VOTERS');
    $sh->setCellValue("D{$row}", $totalCount);
    $sh->getStyle("A{$row}:D{$row}")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
    $sh->getStyle("A{$row}:D{$row}")->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FF0D47A1');
    $sh->getStyle("C{$row}")->getAlignment()
        ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
    $sh->getStyle("D{$row}")->getAlignment()
        ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

    $sh->getColumnDimension('A')->setWidth(12);
    $sh->getColumnDimension('B')->setWidth(18);
    $sh->getColumnDimension('C')->setWidth(14);
    $sh->getColumnDimension('D')->setWidth(16);

    if ($settings['excel_protect_enabled'] && $settings['excel_password'] !== '') {
        $editPassword = $settings['excel_password'];
        $ss->getSecurity()->setLockStructure(true);
        $ss->getSecurity()->setLockWindows(true);
        $ss->getSecurity()->setWorkbookPassword($editPassword);
        $sh->getProtection()->setSheet(true);
        $sh->getProtection()->setPassword($editPassword);
    }

    voters_export_secure_headers(
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'attachment; filename="' . $fname . '.xlsx"'
    );
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss);
    $writer->save('php://output');
    exit;
}

// ---- PDF -------------------------------------------------------------------
if (!class_exists('\\Mpdf\\Mpdf')) {
    $msg = 'PDF export not available (mPDF not installed).';
    if ($autoloadLoaded === null) {
        $msg .= ' Autoloader not found.';
    }
    voters_export_text_error($msg, 501);
}

$mpdf = new \Mpdf\Mpdf([
    'mode' => 'utf-8',
    'format' => 'A4',
    'margin_left' => 14,
    'margin_right' => 14,
    'margin_top' => 32,
    'margin_bottom' => 18,
    'margin_header' => 8,
    'margin_footer' => 8,
]);
$mpdf->SetTitle($titleText);
$mpdf->SetAuthor($adminName);
$mpdf->SetCreator('Tatak Ormoc CCA Admin Portal');
$mpdf->SetSubject('Voters List');

$mpdf->SetHTMLHeader('
  <table width="100%" style="font-family:sans-serif;font-size:9pt;color:#222;border-bottom:1.5px solid #0d47a1;">
    <tr>
      <td valign="middle">
        <div style="font-size:11pt;font-weight:700;color:#0d47a1;">' . htmlspecialchars($titleText, ENT_QUOTES) . '</div>
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
      <td>Generated by <strong>' . htmlspecialchars($adminName, ENT_QUOTES) . '</strong> on ' . htmlspecialchars($generatedText, ENT_QUOTES) . ' (Asia/Manila)</td>
      <td align="right">Page {PAGENO} of {nbpg}</td>
    </tr>
  </table>');

$html = report_export_pdf_styles();
$html .= '<div class="title-band"><div class="doc-title">' . htmlspecialchars($titleText, ENT_QUOTES) . '</div>'
    . '<div class="doc-subtitle">' . htmlspecialchars($subtitleText, ENT_QUOTES) . ' · Generated ' . htmlspecialchars($generatedText, ENT_QUOTES) . '</div></div>';
$html .= report_export_render_pdf_filter_table([
    ['Event', $subtitleText],
    ['Generated by', $adminName],
]);
$html .= '<div class="section-label">Voter Records</div>';
$html .= '<table class="data-table"><thead><tr>'
    . '<th class="center" style="width:70px;">Voter ID</th>'
    . '<th>Phone Number</th>'
    . '<th class="center" style="width:100px;">Date Added</th>'
    . '<th class="center" style="width:100px;">Vote Status</th>'
    . '</tr></thead><tbody>';

if ($totalCount === 0) {
    $html .= '<tr><td colspan="4" class="empty">No voter data found.</td></tr>';
} else {
    foreach ($rows as $i => $r) {
        $cls = ($i % 2 === 1) ? ' class="alt"' : '';
        $html .= '<tr' . $cls . '>'
            . '<td class="num">' . (int) $r['voters_id'] . '</td>'
            . '<td>' . htmlspecialchars($r['mobile_number'], ENT_QUOTES) . '</td>'
            . '<td>' . htmlspecialchars($r['date_verified'], ENT_QUOTES) . '</td>'
            . '<td class="center">' . htmlspecialchars(voters_list_status_label($r['status']), ENT_QUOTES) . '</td>'
            . '</tr>';
    }
}
$html .= '<tr class="total-row"><td colspan="3" style="text-align:right;">TOTAL VOTERS</td><td class="center">' . $totalCount . '</td></tr>';
$html .= '</tbody></table>';

$mpdf->WriteHTML($html);

if ($settings['pdf_password_enabled'] && $settings['pdf_password'] !== '') {
    $mpdf->SetProtection(['print'], $settings['pdf_password'], null, 128);
}

voters_export_secure_headers('application/pdf', 'attachment; filename="' . $fname . '.pdf"');
$mpdf->Output($fname . '.pdf', 'D');
exit;
