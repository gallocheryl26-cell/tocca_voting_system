<?php
declare(strict_types=1);

require_once __DIR__ . '/require_admin_page.php';
require_once __DIR__ . '/db_connection.php';
require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Writer\Pdf\Mpdf;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * exports.php — Unified, polished Excel/PDF exporter for File Maintenance data.
 *
 * Supported types (?type=):
 *   - categories          -> Categories list (with status & award counts)
 *   - questions           -> Name of Awards list (with category & choice type)
 *   - choices             -> Establishments list (with type, status, awards)
 *   - establishment_types -> Establishment Types list (with status & awards)
 *
 * Supported formats (?format=): excel (default) | pdf
 */

$type   = isset($_GET['type'])   ? strtolower(trim((string) $_GET['type']))   : '';
$format = isset($_GET['format']) ? strtolower(trim((string) $_GET['format'])) : 'excel';
if (in_array($format, ['xlsx', 'xls', 'excel'], true)) {
    $format = 'excel';
}

$allowedTypes   = ['categories', 'questions', 'choices', 'establishment_types'];
$allowedFormats = ['excel', 'pdf'];

if (!in_array($type, $allowedTypes, true)) {
    http_response_code(400);
    die('Invalid export type. Allowed: ' . implode(', ', $allowedTypes));
}
if (!in_array($format, $allowedFormats, true)) {
    $format = 'excel';
}

// Active unarchived event only (no fallback to archived/inactive events).
function exports_resolve_active_event_id(mysqli $conn): ?int
{
    if ($res = $conn->query("SELECT event_id FROM tbl_events WHERE is_active = 1 AND COALESCE(is_archived, 0) = 0 ORDER BY year DESC, event_id DESC LIMIT 1")) {
        if ($row = $res->fetch_assoc()) {
            return (int) $row['event_id'];
        }
    }
    return null;
}

function exports_fetch_event_label(mysqli $conn, ?int $eventId): string
{
    if ($eventId === null) return '';
    $stmt = $conn->prepare("SELECT event_name, year FROM tbl_events WHERE event_id = ? LIMIT 1");
    if (!$stmt) return '';
    $stmt->bind_param('i', $eventId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $stmt->close();
        $name = trim((string)($row['event_name'] ?? ''));
        $year = trim((string)($row['year'] ?? ''));
        return $name !== '' && $year !== '' ? "$name ($year)" : ($name ?: $year);
    }
    $stmt->close();
    return '';
}

$eventId    = exports_resolve_active_event_id($conn);
$eventLabel = exports_fetch_event_label($conn, $eventId);

// --- Report definitions (title, headers, data builder) ---

$reportTitles = [
    'categories'          => 'Categories Report',
    'questions'           => 'Name of Awards Report',
    'choices'             => 'Establishments Report',
    'establishment_types' => 'Establishment Types Report',
];

$reportTotalsLabels = [
    'categories'          => 'Total Categories',
    'questions'           => 'Total Awards',
    'choices'             => 'Total Establishments',
    'establishment_types' => 'Total Establishment Types',
];

$reportFilenames = [
    'categories'          => 'tocca_categories_export',
    'questions'           => 'tocca_name_of_awards_export',
    'choices'             => 'tocca_establishments_export',
    'establishment_types' => 'tocca_establishment_types_export',
];

/** @var array{headers: array<int, string>, rows: array<int, array<int, string>>} $report */
$report = ['headers' => [], 'rows' => []];

if ($type === 'categories') {
    $report['headers'] = ['#', 'Category Name', 'Event', 'Status', 'Awards'];
    $sql = "
        SELECT
            c.category_id,
            c.category_name,
            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(e.event_name,''), ' ', COALESCE(e.year,''))), ''), '—') AS event_label,
            COALESCE(c.status, 1) AS status,
            (SELECT COUNT(*) FROM tbl_questions q WHERE q.category_id = c.category_id) AS award_count
        FROM tbl_categories c
        LEFT JOIN tbl_events e ON e.event_id = c.event_id
        " . ($eventId ? 'WHERE c.event_id = ' . (int) $eventId : 'WHERE 0') . "
        ORDER BY c.category_id ASC
    ";
    if ($res = $conn->query($sql)) {
        $i = 0;
        while ($r = $res->fetch_assoc()) {
            $i++;
            $report['rows'][] = [
                (string) $i,
                (string) ($r['category_name'] ?? ''),
                (string) ($r['event_label'] ?? '—'),
                ((int) $r['status'] === 1) ? 'Active' : 'Inactive',
                (string) ($r['award_count'] ?? '0'),
            ];
        }
        $res->free();
    }
} elseif ($type === 'questions') {
    $report['headers'] = ['#', 'Award Name', 'Category', 'Event', 'Choice Type'];
    $sql = "
        SELECT
            q.question_id,
            q.question_name,
            COALESCE(c.category_name, '—') AS category_name,
            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(e.event_name,''), ' ', COALESCE(e.year,''))), ''), '—') AS event_label,
            q.choice_type
        FROM tbl_questions q
        LEFT JOIN tbl_categories c ON c.category_id = q.category_id
        LEFT JOIN tbl_events e     ON e.event_id    = c.event_id
        " . ($eventId ? 'WHERE c.event_id = ' . (int) $eventId : 'WHERE 0') . "
        ORDER BY c.category_name ASC, q.question_name ASC
    ";
    if ($res = $conn->query($sql)) {
        $i = 0;
        while ($r = $res->fetch_assoc()) {
            $i++;
            $report['rows'][] = [
                (string) $i,
                (string) ($r['question_name'] ?? ''),
                (string) ($r['category_name'] ?? '—'),
                (string) ($r['event_label'] ?? '—'),
                ((int) ($r['choice_type'] ?? 0) === 1) ? 'Options' : 'Freeform',
            ];
        }
        $res->free();
    }
} elseif ($type === 'choices') {
    $hasTypesTable = false;
    $hasTypeCol    = false;
    if ($res = $conn->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'tbl_establishment_types' LIMIT 1")) {
        $hasTypesTable = $res->num_rows > 0;
        $res->free();
    }
    if ($res = $conn->query("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'tbl_choices' AND column_name = 'establishment_type_id' LIMIT 1")) {
        $hasTypeCol = $res->num_rows > 0;
        $res->free();
    }

    $report['headers'] = ['#', 'Establishment', 'Email', 'Establishment Type', 'Status', 'Linked Awards'];

    $typeSelect = ($hasTypeCol && $hasTypesTable)
        ? "COALESCE(t.type_name, '—') AS type_name"
        : "'—' AS type_name";
    $typeJoin = ($hasTypeCol && $hasTypesTable)
        ? "LEFT JOIN tbl_establishment_types t ON t.type_id = c.establishment_type_id"
        : '';

    $sql = "
        SELECT
            c.choice_id,
            c.choice_name,
            COALESCE(c.email, '') AS email,
            $typeSelect,
            COALESCE(c.status, 1) AS status,
            COALESCE(GROUP_CONCAT(DISTINCT q.question_name ORDER BY q.question_name SEPARATOR ', '), '') AS linked_awards
        FROM tbl_choices c
        LEFT JOIN tbl_question_choices qc ON qc.choice_id  = c.choice_id
        LEFT JOIN tbl_questions q         ON q.question_id = qc.question_id
        $typeJoin
        " . ($eventId ? 'WHERE c.event_id = ' . (int) $eventId : 'WHERE 0') . "
        GROUP BY c.choice_id
        ORDER BY c.choice_name ASC
    ";
    if ($res = $conn->query($sql)) {
        $i = 0;
        while ($r = $res->fetch_assoc()) {
            $i++;
            $report['rows'][] = [
                (string) $i,
                (string) ($r['choice_name'] ?? ''),
                (string) ($r['email'] ?? ''),
                (string) ($r['type_name'] ?? '—'),
                ((int) $r['status'] === 1) ? 'Active' : 'Inactive',
                (string) ($r['linked_awards'] ?? ''),
            ];
        }
        $res->free();
    }
} elseif ($type === 'establishment_types') {
    $report['headers'] = ['#', 'Establishment Type', 'Status', 'Linked Awards Count', 'Linked Awards'];

    $hasTypesTable = false;
    if ($res = $conn->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'tbl_establishment_types' LIMIT 1")) {
        $hasTypesTable = $res->num_rows > 0;
        $res->free();
    }

    if ($hasTypesTable) {
        $sql = "
            SELECT
                t.type_id,
                t.type_name,
                COALESCE(t.status, 1) AS status,
                COUNT(DISTINCT x.question_id) AS award_count,
                COALESCE(GROUP_CONCAT(DISTINCT q.question_name ORDER BY q.question_name SEPARATOR ', '), '') AS linked_awards
            FROM tbl_establishment_types t
            LEFT JOIN tbl_establishment_type_awards x ON x.type_id = t.type_id
            LEFT JOIN tbl_questions q                 ON q.question_id = x.question_id
            LEFT JOIN tbl_categories cat              ON cat.category_id = q.category_id
            " . ($eventId
                ? 'WHERE t.type_id IN (
                    SELECT DISTINCT x2.type_id
                    FROM tbl_establishment_type_awards x2
                    INNER JOIN tbl_questions q2 ON q2.question_id = x2.question_id
                    INNER JOIN tbl_categories c2 ON c2.category_id = q2.category_id AND c2.event_id = ' . (int) $eventId . '
                  ) OR t.type_id IN (
                    SELECT DISTINCT establishment_type_id FROM tbl_choices WHERE event_id = ' . (int) $eventId . ' AND establishment_type_id IS NOT NULL
                  )'
                : 'WHERE 0') . "
            GROUP BY t.type_id
            ORDER BY t.type_name ASC
        ";
        if ($res = $conn->query($sql)) {
            $i = 0;
            while ($r = $res->fetch_assoc()) {
                $i++;
                $report['rows'][] = [
                    (string) $i,
                    (string) ($r['type_name'] ?? ''),
                    ((int) $r['status'] === 1) ? 'Active' : 'Inactive',
                    (string) ($r['award_count'] ?? '0'),
                    (string) ($r['linked_awards'] ?? ''),
                ];
            }
            $res->free();
        }
    }
}

// --- Build spreadsheet ---

$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator('Tatak Ormoc Admin')
    ->setTitle($reportTitles[$type])
    ->setSubject($reportTitles[$type])
    ->setDescription('Generated by Tatak Ormoc Admin · ' . date('F j, Y g:i A'));

$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle(substr($reportTitles[$type], 0, 28));

$headers     = $report['headers'];
$rows        = $report['rows'];
$colCount    = max(1, count($headers));
$lastColLet  = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colCount);

$dateGenerated = date('F j, Y \\a\\t g:i A');

// Row 1: Brand / Title
$sheet->setCellValue('A1', 'TATAK ORMOC · ' . strtoupper($reportTitles[$type]));
$sheet->mergeCells("A1:{$lastColLet}1");
$sheet->getStyle('A1')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'FFFFFF']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0F172A']],
]);
$sheet->getRowDimension(1)->setRowHeight(34);

// Row 2: Generated stamp
$sheet->setCellValue('A2', 'Generated on ' . $dateGenerated);
$sheet->mergeCells("A2:{$lastColLet}2");
$sheet->getStyle('A2')->applyFromArray([
    'font'      => ['italic' => true, 'size' => 10, 'color' => ['rgb' => '475569']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F1F5F9']],
]);
$sheet->getRowDimension(2)->setRowHeight(20);

// Row 3: Event context (or empty separator)
if ($eventLabel !== '') {
    $sheet->setCellValue('A3', 'Event Context: ' . $eventLabel);
} else {
    $sheet->setCellValue('A3', 'Event Context: No active event');
}
$sheet->mergeCells("A3:{$lastColLet}3");
$sheet->getStyle('A3')->applyFromArray([
    'font'      => ['size' => 10, 'color' => ['rgb' => '0F172A']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E8F0']],
]);
$sheet->getRowDimension(3)->setRowHeight(20);

// Row 4: Column headers
$headerRow = 4;
foreach ($headers as $i => $heading) {
    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
    $sheet->setCellValue("{$colLetter}{$headerRow}", $heading);
}
$sheet->getStyle("A{$headerRow}:{$lastColLet}{$headerRow}")->applyFromArray([
    'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E293B']],
    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CBD5E1']]],
]);
$sheet->getRowDimension($headerRow)->setRowHeight(26);

// Data rows
$dataStartRow = $headerRow + 1;
$dataRow      = $dataStartRow;
$total        = 0;

if (count($rows) === 0) {
    $sheet->setCellValue("A{$dataRow}", 'No records found.');
    $sheet->mergeCells("A{$dataRow}:{$lastColLet}{$dataRow}");
    $sheet->getStyle("A{$dataRow}")->applyFromArray([
        'font'      => ['italic' => true, 'color' => ['rgb' => '64748B']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CBD5E1']]],
    ]);
    $dataRow++;
} else {
    foreach ($rows as $row) {
        foreach ($row as $i => $cellValue) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValueExplicit(
                "{$colLetter}{$dataRow}",
                $cellValue,
                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
            );
        }

        // Zebra striping for readability
        if ($total % 2 === 1) {
            $sheet->getStyle("A{$dataRow}:{$lastColLet}{$dataRow}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F8FAFC']],
            ]);
        }

        $dataRow++;
        $total++;
    }

    $dataEndRow = $dataRow - 1;
    $sheet->getStyle("A{$dataStartRow}:{$lastColLet}{$dataEndRow}")->applyFromArray([
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CBD5E1']]],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_LEFT,
            'vertical'   => Alignment::VERTICAL_CENTER,
            'wrapText'   => true,
        ],
        'font'      => ['size' => 10, 'color' => ['rgb' => '0F172A']],
    ]);

    // Center the # column
    $sheet->getStyle("A{$dataStartRow}:A{$dataEndRow}")->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER);

    // Center the Status column when present
    $statusColIndex = array_search('Status', $headers, true);
    if ($statusColIndex !== false) {
        $statusLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($statusColIndex + 1);
        $sheet->getStyle("{$statusLetter}{$dataStartRow}:{$statusLetter}{$dataEndRow}")
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    foreach (range($dataStartRow, $dataEndRow) as $r) {
        $sheet->getRowDimension($r)->setRowHeight(22);
    }
}

// Totals row
$totalsRow = $dataRow;
$sheet->setCellValue("A{$totalsRow}", $reportTotalsLabels[$type] . ': ' . $total);
$sheet->mergeCells("A{$totalsRow}:{$lastColLet}{$totalsRow}");
$sheet->getStyle("A{$totalsRow}")->applyFromArray([
    'font'      => ['bold' => true, 'size' => 11, 'color' => ['rgb' => '0F172A']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E8F0']],
    'borders'   => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => '1E293B']]],
]);
$sheet->getRowDimension($totalsRow)->setRowHeight(26);

// Column widths
foreach (range('A', $lastColLet) as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}
$sheet->getColumnDimension('A')->setAutoSize(false);
$sheet->getColumnDimension('A')->setWidth(6);

// Freeze the header rows so they remain visible on scroll
$sheet->freezePane("A{$dataStartRow}");

// Default font
$spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);

// --- Output ---

$filename = $reportFilenames[$type] . '_' . date('Ymd_His');

while (ob_get_level() > 0) {
    ob_end_clean();
}

if ($format === 'pdf') {
    \PhpOffice\PhpSpreadsheet\IOFactory::registerWriter('Pdf', Mpdf::class);

    $sheet->getPageSetup()
        ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
        ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)
        ->setFitToWidth(1)
        ->setFitToHeight(0);
    $sheet->getPageMargins()->setTop(0.5)->setBottom(0.5)->setLeft(0.4)->setRight(0.4);

    header('Content-Type: application/pdf');
    header("Content-Disposition: attachment; filename=\"{$filename}.pdf\"");
    header('Cache-Control: max-age=0');
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Pdf\Mpdf($spreadsheet);
} else {
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment; filename=\"{$filename}.xlsx\"");
    header('Cache-Control: max-age=0');
    $writer = new Xlsx($spreadsheet);
}

$writer->save('php://output');
exit;
