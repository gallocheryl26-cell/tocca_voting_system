<?php
declare(strict_types=1);

require_once __DIR__ . '/require_admin_session.php';
require_once __DIR__ . '/audit_log.php';
tocca_admin_require_login(false);

require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/includes/admin_active_event.php';
require_once __DIR__ . '/includes/twg_sheet_io.php';

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

$rows = twg_sheet_fetch_rows(
    $conn,
    $eventId,
    $questionId > 0 ? $questionId : null,
    $choiceId > 0 ? $choiceId : null
);
$table = twg_sheet_to_table($rows);

$stamp = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Ymd_His');
$scope = 'all_businesses';
if ($choiceId > 0 && isset($rows[0]['establishment'])) {
    $scope = twg_sheet_safe_filename((string) $rows[0]['establishment']);
} elseif ($questionId > 0 && isset($rows[0]['award'])) {
    $scope = twg_sheet_safe_filename((string) $rows[0]['award']);
}
$base = 'TWG_scoresheet_' . $scope . '_' . $stamp;
$eventLabel = function_exists('admin_get_active_event_label')
    ? admin_get_active_event_label($conn, $eventId)
    : '';
$scopeLabel = $choiceId > 0 && isset($rows[0]['establishment'])
    ? ('Business: ' . (string) $rows[0]['establishment'])
    : ($questionId > 0 && isset($rows[0]['award'])
        ? ('Award: ' . (string) $rows[0]['award'])
        : 'All businesses');
$generatedAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('M j, Y g:i A');

if ($format === 'csv') {
    twg_export_headers('text/csv; charset=UTF-8', $base . '.csv');
    $out = fopen('php://output', 'w');
    if ($out === false) {
        twg_export_fail('Could not write the CSV file.', 500);
    }
    fwrite($out, "\xEF\xBB\xBF");
    foreach ($table as $line) {
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
        'rows' => max(0, count($table) - 1),
    ]);
    exit;
}

if (twg_sheet_autoload() === null) {
    twg_export_fail('Excel export is not available on this server. Download CSV instead.');
}

$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
twg_sheet_populate_xlsx($spreadsheet, $rows, [
    'event_name' => $eventLabel,
    'scope_label' => $scopeLabel,
    'generated_at' => $generatedAt,
]);

$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
twg_export_headers(
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    $base . '.xlsx'
);
$writer->save('php://output');
audit_log($conn, 'twg_evaluation', 'export', 'twg_scoresheet', $choiceId > 0 ? $choiceId : ($questionId > 0 ? $questionId : $eventId), [
    'format' => 'xlsx',
    'question_id' => $questionId,
    'choice_id' => $choiceId,
    'event_id' => $eventId,
    'event_name' => $eventLabel,
    'scope' => $scopeLabel,
    'rows' => max(0, count($table) - 1),
]);
exit;
