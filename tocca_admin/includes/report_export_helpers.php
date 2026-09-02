<?php
declare(strict_types=1);

/**
 * Shared helpers for official / confidential registration report exports.
 */

function report_export_column_exists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $stmt->store_result();
    $ok = $stmt->num_rows > 0;
    $stmt->close();
    return $ok;
}

function report_export_ensure_schema(mysqli $conn): void
{
    if (!report_export_column_exists($conn, 'tbl_user', 'signature_path')) {
        @$conn->query(
            'ALTER TABLE tbl_user ADD COLUMN signature_path VARCHAR(255) NULL DEFAULT NULL AFTER email'
        );
    }
}

function report_export_set_config(mysqli $conn, string $key, string $value): bool
{
    $stmt = $conn->prepare(
        'INSERT INTO tbl_config (config_key, config_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $key, $value);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function report_export_get_config(mysqli $conn, string $key, string $default = ''): string
{
    if (function_exists('getConfig')) {
        return (string) getConfig($key, $default);
    }

    $stmt = $conn->prepare('SELECT config_value FROM tbl_config WHERE config_key = ? LIMIT 1');
    if (!$stmt) {
        return $default;
    }
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $stmt->bind_result($val);
    $out = $stmt->fetch() ? (string) $val : $default;
    $stmt->close();
    return $out;
}

function report_export_get_settings(mysqli $conn): array
{
    return [
        'org_line'                  => report_export_get_config(
            $conn,
            'report_org_line',
            "Tatak Ormoc Consumers' Choice Awards"
        ),
        'reviewer_label'            => report_export_get_config($conn, 'report_reviewer_label', 'Awards Secretariat'),
        'approver_label'            => report_export_get_config($conn, 'report_approver_label', 'Awards Committee Chair'),
        'reviewer_signature_path'   => report_export_get_config($conn, 'report_reviewer_signature_path', ''),
        'approver_signature_path'   => report_export_get_config($conn, 'report_approver_signature_path', ''),
        'pdf_password_enabled'      => report_export_get_config($conn, 'report_pdf_password_enabled', '0') === '1',
        'pdf_password'              => report_export_get_config($conn, 'report_pdf_password', ''),
        'excel_protect_enabled'     => report_export_get_config($conn, 'report_excel_protect_enabled', '0') === '1',
        'excel_password'            => report_export_get_config($conn, 'report_excel_password', ''),
    ];
}

function report_export_build_credentials_payload(array $settings, string $format): array
{
    $items = [];

    if ($format === 'pdf' && !empty($settings['pdf_password_enabled']) && ($settings['pdf_password'] ?? '') !== '') {
        $items[] = [
            'type'     => 'pdf_open',
            'label'    => 'PDF open password',
            'password' => (string) $settings['pdf_password'],
            'hint'     => 'Recipients must enter this password to open the PDF file.',
        ];
    }

    if ($format === 'excel' && !empty($settings['excel_protect_enabled']) && ($settings['excel_password'] ?? '') !== '') {
        $items[] = [
            'type'     => 'excel_edit',
            'label'    => 'Excel edit password',
            'password' => (string) $settings['excel_password'],
            'hint'     => 'The workbook opens for viewing; this password is required to edit cells or change structure.',
        ];
    }

    return [
        'show'  => $items !== [],
        'items' => $items,
    ];
}

function report_export_resolve_abs_path(?string $storedPath, string $adminRoot): ?string
{
    if (!$storedPath) {
        return null;
    }

    $storedPath = ltrim(preg_replace('~^https?://[^/]+~', '', trim($storedPath)), '/');
    $candidate  = $adminRoot . '/' . $storedPath;

    return is_file($candidate) ? $candidate : null;
}

function report_export_get_admin_signature_rel(mysqli $conn, string $username): ?string
{
    $username = trim($username);
    if ($username === '') {
        return null;
    }

    report_export_ensure_schema($conn);

    $stmt = $conn->prepare('SELECT signature_path FROM tbl_user WHERE username = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $stmt->bind_result($path);
    $rel = null;
    if ($stmt->fetch()) {
        $rel = trim((string) $path) ?: null;
    }
    $stmt->close();

    return $rel;
}

function report_export_get_admin_signature_abs(mysqli $conn, string $username, string $adminRoot): ?string
{
    $rel = report_export_get_admin_signature_rel($conn, $username);
    return report_export_resolve_abs_path($rel, $adminRoot);
}

function report_export_upload_signature_image(string $inputName, string $adminRoot, string $namePrefix = 'sig'): ?string
{
    if (!isset($_FILES[$inputName]) || $_FILES[$inputName]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    if (!empty($_FILES[$inputName]['size']) && $_FILES[$inputName]['size'] > 2 * 1024 * 1024) {
        return null;
    }

    $targetDirAbs = $adminRoot . '/uploads/signatures/';
    $targetDirRel = 'uploads/signatures/';
    if (!is_dir($targetDirAbs)) {
        @mkdir($targetDirAbs, 0775, true);
    }

    $originalName = basename((string) $_FILES[$inputName]['name']);
    $cleanName    = preg_replace('/[^a-zA-Z0-9\._-]/', '_', $originalName);
    $ext          = strtolower(pathinfo($cleanName, PATHINFO_EXTENSION));
    $allowedExt   = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($ext, $allowedExt, true)) {
        return null;
    }

    $tmp   = $_FILES[$inputName]['tmp_name'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = $finfo ? finfo_buffer($finfo, (string) file_get_contents($tmp)) : '';
    if ($finfo) {
        finfo_close($finfo);
    }
    $allowedMime = ['image/png', 'image/jpeg', 'image/webp'];
    if (!in_array($mime, $allowedMime, true)) {
        return null;
    }

    $prefix         = preg_replace('/[^a-z0-9_]+/i', '_', $namePrefix) ?: 'sig';
    $uniqueFileName = $prefix . '_' . time() . '_' . $cleanName;
    $targetAbs      = $targetDirAbs . $uniqueFileName;
    $targetRel      = $targetDirRel . $uniqueFileName;

    return move_uploaded_file($tmp, $targetAbs) ? $targetRel : null;
}

function report_export_embed_excel_signature(
    \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
    ?string $signatureAbsPath,
    int $row,
    string $column = 'A',
    string $drawingName = 'Signature'
): void {
    if (!$signatureAbsPath) {
        return;
    }

    try {
        $sheet->getRowDimension($row)->setRowHeight(max(52, (int) $sheet->getRowDimension($row)->getRowHeight()));
        $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
        $drawing->setName($drawingName);
        $drawing->setDescription($drawingName);
        $drawing->setPath($signatureAbsPath);
        $drawing->setHeight(46);
        $drawing->setCoordinates($column . $row);
        $drawing->setOffsetX(8);
        $drawing->setOffsetY(2);
        $drawing->setWorksheet($sheet);
    } catch (\Throwable $e) {
        // Signature is optional — never block the export.
    }
}

/** @return list<array{0:string,1:string}> */
function report_export_build_filter_rows(
    ?string $eventLabel,
    ?int $eventYear,
    ?string $categoryLabel,
    ?string $awardLabel,
    string $statusLabel,
    string $scope
): array {
    $eventText = $eventLabel
        ? trim(($eventYear ? $eventYear . ' ' : '') . $eventLabel)
        : 'All unarchived events';

    return [
        ['Event', $eventText],
        ['Category', $categoryLabel ?: 'All categories'],
        ['Award', $awardLabel ?: 'All awards'],
        ['Status', $statusLabel],
        ['Scope', $scope === 'current' ? 'Selected category & award' : 'All filtered rows'],
    ];
}

function report_export_filter_text(array $filterRows): string
{
    $parts = [];
    foreach ($filterRows as [$label, $value]) {
        $parts[] = $label . ': ' . $value;
    }
    return implode('  ·  ', $parts);
}

/** @return list<array{0:string,1:string}> */
function report_export_build_results_filter_rows(
    ?string $eventLabel,
    ?int $eventYear,
    ?string $categoryLabel,
    ?string $awardLabel,
    string $scope,
    int $top
): array {
    $eventText = $eventLabel
        ? trim(($eventYear ? $eventYear . ' ' : '') . $eventLabel)
        : 'Active event';
    $standing = $top > 0 ? 'Top ' . $top : 'All standings';

    return [
        ['Event', $eventText],
        ['Category', $categoryLabel ?: ($scope === 'current' ? '—' : 'All categories')],
        ['Award', $awardLabel ?: ($scope === 'current' ? '—' : 'All awards')],
        ['Scope', $scope === 'current' ? 'Selected category & award' : 'All categories'],
        ['Standings', $standing],
        ['Formula', 'Final = (TWG × 40%) + (Community score × 60%). Community score = vote share × 10.'],
    ];
}

function report_export_render_pdf_filter_table(array $filterRows): string
{
    $rowsHtml = '';
    foreach ($filterRows as [$label, $value]) {
        $rowsHtml .= '<tr>'
            . '<td class="meta-label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td class="meta-value">' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</td>'
            . '</tr>';
    }

    return '<table class="meta-table">'
        . '<tr><td colspan="2" class="meta-head">Report Context</td></tr>'
        . $rowsHtml
        . '</table>';
}

/**
 * @param array<string,array{label:string,tone:string}> $statusKeyMap
 * @param array<string,int> $tally
 */
function report_export_render_pdf_breakdown_table(
    array $statusKeyMap,
    array $tally,
    array $toneHex,
    int $totalCount
): string {
    if ($totalCount <= 0) {
        return '<table class="meta-table breakdown-table">'
            . '<tr><td colspan="3" class="meta-head">Status Breakdown</td></tr>'
            . '<tr><td colspan="3" class="empty">No records to summarize.</td></tr>'
            . '</table>';
    }

    $body = '';
    foreach ($statusKeyMap as $key => $meta) {
        $n = (int) ($tally[$key] ?? 0);
        if ($n === 0) {
            continue;
        }
        $tone = $toneHex[$meta['tone']] ?? $toneHex['secondary'];
        $pct  = round(($n / $totalCount) * 100, 1);
        $body .= '<tr>'
            . '<td><span class="chip" style="background:' . $tone['bg'] . ';color:' . $tone['fg'] . ';">'
            . htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8') . '</span></td>'
            . '<td class="center"><strong>' . $n . '</strong></td>'
            . '<td class="center">' . $pct . '%</td>'
            . '</tr>';
    }

    return '<table class="meta-table breakdown-table">'
        . '<tr><td colspan="3" class="meta-head">Status Breakdown</td></tr>'
        . '<tr class="breakdown-head">'
        . '<td>Status</td><td class="center" style="width:70px;">Count</td><td class="center" style="width:70px;">Share</td>'
        . '</tr>'
        . $body
        . '<tr class="breakdown-total">'
        . '<td><strong>Total</strong></td>'
        . '<td class="center"><strong>' . $totalCount . '</strong></td>'
        . '<td class="center"><strong>100%</strong></td>'
        . '</tr>'
        . '</table>';
}

function report_export_render_pdf_signatures_table(
    string $preparedCell,
    string $reviewerCell,
    string $approverCell
): string {
    return '<table class="sig-table">'
        . '<tr><td colspan="3" class="meta-head">Certification</td></tr>'
        . '<tr>'
        . '<td width="33%">' . $preparedCell . '</td>'
        . '<td width="34%">' . $reviewerCell . '</td>'
        . '<td width="33%">' . $approverCell . '</td>'
        . '</tr>'
        . '</table>';
}

function report_export_pdf_styles(): string
{
    return '
  <style>
    body, table { font-family: DejaVu Sans, sans-serif; color:#111; }
    .title-band { background:#f0f4fa; border:1px solid #d0dced; border-left:4px solid #0d47a1; padding:10px 12px; margin:0 0 12px; text-align:center; }
    .doc-title    { font-size:17pt; font-weight:700; margin:0 0 3px; letter-spacing:.4px; color:#0d47a1; }
    .doc-subtitle { font-size:10pt; margin:0; color:#555; font-style:italic; }
    .section-label { font-size:9pt; font-weight:700; color:#0d47a1; letter-spacing:.3px; margin:14px 0 6px; text-transform:uppercase; }
    .meta-table { width:100%; border-collapse:collapse; font-size:9pt; margin:0 0 12px; }
    .meta-table td { border:0.6pt solid #c8c8c8; padding:5px 8px; vertical-align:top; }
    .meta-head { background:#0d47a1; color:#fff; font-weight:700; font-size:9pt; padding:6px 8px !important; }
    .meta-label { width:22%; background:#f6f8fb; color:#0d47a1; font-weight:700; }
    .meta-value { color:#222; }
    table.data-table { width:100%; border-collapse:collapse; font-size:9.5pt; }
    .data-table th, .data-table td { border:0.6pt solid #888; padding:6px 8px; vertical-align:middle; }
    .data-table thead th { background:#0d47a1; color:#fff; font-weight:700; text-align:left; }
    .data-table thead th.center { text-align:center; }
    .data-table tbody tr.alt td { background:#f8fafc; }
    .data-table td.num { text-align:center; width:32px; color:#555; }
    .data-table td.status { text-align:center; width:108px; }
    .chip { display:inline-block; padding:2px 8px; border-radius:10px; font-size:8.5pt; font-weight:700; white-space:nowrap; }
    .muted { color:#999; font-style:italic; }
    .center { text-align:center; }
    .breakdown-table .breakdown-head td { background:#eef2f7; font-weight:700; font-size:8.5pt; color:#333; }
    .breakdown-table .breakdown-total td { background:#f6f8fb; }
    .total-row td { background:#0d47a1; color:#fff; font-weight:700; }
    .sig-table { width:100%; border-collapse:collapse; margin-top:14px; font-size:9pt; }
    .sig-table > tbody > tr > td { border:0.6pt solid #c8c8c8; padding:10px 8px 8px; vertical-align:bottom; background:#fcfcfd; }
    .sig-img { height:50px; text-align:center; margin-bottom:4px; }
    .sig-img img { max-height:48px; max-width:150px; object-fit:contain; }
    .sig-line { border-top:1px solid #444; margin:0 8px 5px; height:1px; }
    .sig-title { font-size:7.5pt; font-weight:700; color:#0d47a1; text-transform:uppercase; letter-spacing:.4px; text-align:center; }
    .sig-name { font-size:8.5pt; color:#444; text-align:center; margin-top:3px; line-height:1.35; }
    .empty { text-align:center; color:#999; font-style:italic; padding:12px; }
  </style>';
}

function report_export_build_pdf_role_cell_v2(
    string $roleTitle,
    string $roleLabel,
    ?string $signatureAbsPath = null
): string {
    $img = $signatureAbsPath
        ? '<div class="sig-img"><img src="' . htmlspecialchars($signatureAbsPath, ENT_QUOTES) . '" alt=""></div>'
        : '<div class="sig-line"></div>';

    return $img
        . '<div class="sig-title">' . htmlspecialchars($roleTitle, ENT_QUOTES, 'UTF-8') . '</div>'
        . '<div class="sig-name">' . htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') . '</div>';
}

function report_export_build_pdf_role_cell(
    string $roleTitle,
    string $roleLabel,
    ?string $signatureAbsPath = null
): string {
    return report_export_build_pdf_role_cell_v2($roleTitle, $roleLabel, $signatureAbsPath);
}

function report_export_build_pdf_signature_cell(
    ?string $signatureAbsPath,
    string $adminName,
    string $dateLabel
): string {
    return report_export_build_pdf_role_cell_v2(
        'Prepared by',
        $adminName . ' — ' . $dateLabel,
        $signatureAbsPath
    );
}

function report_export_apply_excel_section_header(
    \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
    int $row,
    string $title,
    string $lastCol = 'D'
): void {
    $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
    $sheet->setCellValue("A{$row}", $title);
    $sheet->getStyle("A{$row}")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
    $sheet->getStyle("A{$row}")->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FF0D47A1');
    $sheet->getStyle("A{$row}")->getAlignment()
        ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT)
        ->setIndent(1);
}

function report_export_apply_excel_meta_row(
    \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
    int $row,
    string $label,
    string $value,
    string $lastCol = 'D'
): void {
    $sheet->setCellValue("A{$row}", $label);
    $sheet->mergeCells("B{$row}:{$lastCol}{$row}");
    $sheet->setCellValue("B{$row}", $value);
    $sheet->getStyle("A{$row}")->getFont()->setBold(true)->getColor()->setARGB('FF0D47A1');
    $sheet->getStyle("A{$row}")->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FFF6F8FB');
    $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getBorders()->getAllBorders()
        ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
        ->getColor()->setARGB('FFD0D0D0');
    $sheet->getStyle("B{$row}")->getAlignment()->setWrapText(true);
}

/** @param array<string,array{label:string,tone:string}> $statusKeyMap */
function report_export_write_csv_sections($out, array $sections): void
{
    foreach ($sections as $section) {
        $title = $section['title'] ?? '';
        if ($title !== '') {
            fputcsv($out, ['--- ' . $title . ' ---']);
        }
        foreach ($section['rows'] ?? [] as $row) {
            if (is_array($row)) {
                fputcsv($out, $row);
            }
        }
        fputcsv($out, []);
    }
}

