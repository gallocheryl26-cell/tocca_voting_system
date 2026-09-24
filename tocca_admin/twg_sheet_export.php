<?php
declare(strict_types=1);

require_once __DIR__ . '/require_admin_session.php';
require_once __DIR__ . '/audit_log.php';
tocca_admin_require_login(false);

require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/includes/admin_active_event.php';
require_once __DIR__ . '/includes/twg_sheet_io.php';

@ini_set('memory_limit', '256M');
@set_time_limit(120);

function twg_export_fail(string $message, int $code = 400): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $message;
    exit;
}

function twg_export_headers(string $contentType, string $filename): void
{
    header('Content-Type: ' . $contentType);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Content-Type-Options: nosniff');
}

$eventId = admin_get_active_event_id($conn);
if ($eventId === null || $eventId <= 0) {
    twg_export_fail('No active event. Activate an event under File Maintenance → Events.');
}

$questionId = (int) ($_GET['question_id'] ?? 0);
$choiceId = (int) ($_GET['choice_id'] ?? 0);
$format = strtolower(trim((string) ($_GET['format'] ?? 'xlsx')));
if (!in_array($format, ['xlsx', 'csv'], true)) {
    $format = 'xlsx';
}

if ($questionId > 0 && !twg_question_in_event($conn, $eventId, $questionId)) {
    twg_export_fail('That award is not in the active event.');
}
if ($choiceId > 0 && !twg_choice_in_event($conn, $eventId, $choiceId)) {
    twg_export_fail('That business is not in the active event.');
}

$payload = twg_sheet_rubric_collect(
    $conn,
    $eventId,
    $questionId > 0 ? $questionId : null,
    $choiceId > 0 ? $choiceId : null
);

$stamp = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Ymd_His');
$scope = 'all_awards';
if ($choiceId > 0 && isset($payload['awards'][0]['rows'][0]['choice_name'])) {
    $scope = twg_sheet_safe_filename((string) $payload['awards'][0]['rows'][0]['choice_name']);
} elseif ($questionId > 0 && isset($payload['awards'][0]['question_name'])) {
    $scope = twg_sheet_safe_filename((string) $payload['awards'][0]['question_name']);
}
$base = 'TWG_tally_' . $scope . '_' . $stamp;
$eventLabel = function_exists('admin_get_active_event_label')
    ? admin_get_active_event_label($conn, $eventId)
    : '';
$scopeLabel = $choiceId > 0 && isset($payload['awards'][0]['rows'][0]['choice_name'])
    ? ('Business: ' . (string) $payload['awards'][0]['rows'][0]['choice_name'])
    : ($questionId > 0 && isset($payload['awards'][0])
        ? trim($payload['awards'][0]['category_name'] . ' · ' . $payload['awards'][0]['question_name'])
        : 'All awards');
$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
$generatedAt = $now->format('M j, Y g:i A');
$meta = [
    'event_id' => $eventId,
    'event_name' => $eventLabel,
    'scope_label' => $scopeLabel,
    'generated_at' => $generatedAt,
    'banner' => 'TWG VALIDATION',
    'datetime' => $now->format('F d, Y') . ' | ' . strtoupper($now->format('g:iA')),
    'venue' => $eventLabel,
];
$csvLines = twg_sheet_rubric_csv_lines($payload, $meta);
$rowCount = max(0, count($csvLines) - 1);

if ($format === 'csv') {
    twg_export_headers('text/csv; charset=UTF-8', $base . '.csv');
    $out = fopen('php://output', 'w');
    if ($out === false) {
        twg_export_fail('Could not write the CSV file.', 500);
    }
    fwrite($out, "\xEF\xBB\xBF");
    foreach ($csvLines as $line) {
        fputcsv($out, $line);
    }
    fclose($out);
    audit_log($conn, 'twg_evaluation', 'export', 'twg_scoresheet', $choiceId > 0 ? $choiceId : ($questionId > 0 ? $questionId : $eventId), [
        'format' => 'csv',
        'question_id' => $questionId,
        'choice_id' => $choiceId,
        'event_id' => $eventId,
        'event_name' => $eventLabel,
        'scope' => $scopeLabel,
        'rows' => $rowCount,
    ]);
    exit;
}

if (twg_sheet_autoload() === null) {
    twg_export_fail('Excel export is not available on this server. Download CSV instead.');
}

try {
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    twg_sheet_populate_rubric_xlsx($spreadsheet, $payload, $meta);
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    if (method_exists($writer, 'setPreCalculateFormulas')) {
        $writer->setPreCalculateFormulas(false);
    }
    twg_export_headers(
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        $base . '.xlsx'
    );
    $writer->save('php://output');
} catch (Throwable $e) {
    twg_export_fail('Could not build the Excel scoresheet. Download CSV instead.', 500);
}

audit_log($conn, 'twg_evaluation', 'export', 'twg_scoresheet', $choiceId > 0 ? $choiceId : ($questionId > 0 ? $questionId : $eventId), [
    'format' => 'xlsx',
    'question_id' => $questionId,
    'choice_id' => $choiceId,
    'event_id' => $eventId,
    'event_name' => $eventLabel,
    'scope' => $scopeLabel,
    'rows' => $rowCount,
]);
exit;
