<?php
declare(strict_types=1);

require_once __DIR__ . '/require_admin_api.php';
require_once __DIR__ . '/audit_log.php';
require_once __DIR__ . '/includes/admin_active_event.php';
require_once __DIR__ . '/includes/twg_sheet_io.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Use POST to import a scoresheet.']);
    exit;
}

$eventId = admin_get_active_event_id($conn);
if ($eventId === null || $eventId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'No active event.']);
    exit;
}

$file = $_FILES['scoresheet'] ?? null;
if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    echo json_encode(['status' => 'error', 'message' => 'Choose an Excel or CSV scoresheet to import.']);
    exit;
}

$size = (int) ($file['size'] ?? 0);
if ($size <= 0 || $size > 5 * 1024 * 1024) {
    echo json_encode(['status' => 'error', 'message' => 'File must be 5 MB or smaller.']);
    exit;
}

$tmp = (string) ($file['tmp_name'] ?? '');
$orig = (string) ($file['name'] ?? 'scoresheet');
$ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
    echo json_encode(['status' => 'error', 'message' => 'Import an .xlsx, .xls, or .csv file downloaded from this page.']);
    exit;
}

$table = [];
try {
    if ($ext === 'csv') {
        $fh = fopen($tmp, 'r');
        if ($fh === false) {
            throw new RuntimeException('Could not read the CSV file.');
        }
        $first = fgets($fh);
        if ($first !== false) {
            if (str_starts_with($first, "\xEF\xBB\xBF")) {
                $first = substr($first, 3);
            }
            $table[] = str_getcsv($first);
        }
        while (($line = fgetcsv($fh)) !== false) {
            $table[] = $line;
        }
        fclose($fh);
    } else {
        if (twg_sheet_autoload() === null) {
            echo json_encode(['status' => 'error', 'message' => 'Excel import is not available. Save the sheet as CSV and try again.']);
            exit;
        }
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
        $dataSheet = $spreadsheet->getSheetByName('Score sheet')
            ?? $spreadsheet->getSheetByName('TWG Scores')
            ?? $spreadsheet->getSheet(0);
        if (twg_sheet_normalize_header((string) $dataSheet->getTitle()) === 'instructions') {
            foreach ($spreadsheet->getWorksheetIterator() as $ws) {
                if (twg_sheet_normalize_header($ws->getTitle()) !== 'instructions') {
                    $dataSheet = $ws;
                    break;
                }
            }
        }
        $table = $dataSheet->toArray(null, true, true, false);
    }
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => 'Could not read that file. Download a fresh scoresheet and try again.']);
    exit;
}

$result = twg_sheet_import_table($conn, $eventId, $table);
audit_log($conn, 'twg_evaluation', 'import', 'twg_scoresheet', $eventId, [
    'filename' => $orig,
    'event_id' => $eventId,
    'saved' => $result['saved'],
    'skipped' => $result['skipped'],
    'error_count' => count($result['errors']),
]);

echo json_encode([
    'status' => $result['ok'] ? 'success' : 'error',
    'message' => $result['message'],
    'saved' => $result['saved'],
    'skipped' => $result['skipped'],
    'errors' => $result['errors'],
]);
exit;
