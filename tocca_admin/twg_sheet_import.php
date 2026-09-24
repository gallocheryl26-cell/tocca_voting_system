<?php
declare(strict_types=1);

require_once __DIR__ . '/require_admin_api.php';
require_once __DIR__ . '/audit_log.php';
require_once __DIR__ . '/includes/admin_active_event.php';
require_once __DIR__ . '/includes/twg_sheet_io.php';

@ini_set('memory_limit', '256M');
@set_time_limit(120);

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
$tables = [];
$judgeBySheet = [];
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
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($tmp);
        if (method_exists($reader, 'setReadDataOnly')) {
            $reader->setReadDataOnly(true);
        }
        if (method_exists($reader, 'setReadEmptyCells')) {
            $reader->setReadEmptyCells(false);
        }
        $spreadsheet = $reader->load($tmp);
        $tables = [];
        $judgeBySheet = [];
        foreach ($spreadsheet->getWorksheetIterator() as $ws) {
            $title = twg_sheet_normalize_header((string) $ws->getTitle());
            if ($title === 'instructions' || $title === 'overall' || $title === 'overall_score' || $title === 'over_all_tally_sheet') {
                continue;
            }
            $tables[] = $ws->toArray(null, false, false, false);
            $judgeBySheet[] = (string) $ws->getTitle();
        }
        if ($tables === []) {
            $dataSheet = $spreadsheet->getSheet(0);
            $tables[] = $dataSheet->toArray(null, false, false, false);
            $judgeBySheet[] = (string) $dataSheet->getTitle();
        }
    }
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => 'Could not read that file. Download a fresh scoresheet and try again.']);
    exit;
}

try {
$saved = 0;
$skipped = 0;
$errors = [];
$matchedRubric = false;
if ($ext === 'csv') {
    $rubric = twg_rubric_for_event($conn, $eventId);
    foreach (twg_sheet_rubric_split_tables($table, $rubric) as $block) {
        $rubricTry = twg_sheet_import_rubric_table($conn, $eventId, $block);
        if (!empty($rubricTry['matched'])) {
            $matchedRubric = true;
            $saved += (int) $rubricTry['saved'];
            $skipped += (int) $rubricTry['skipped'];
            $errors = array_merge($errors, $rubricTry['errors']);
        }
    }
} else {
    foreach ($tables as $i => $oneTable) {
        $sheetTitle = $judgeBySheet[$i] ?? '';
        $guessKey = null;
        $titleNorm = twg_sheet_normalize_header($sheetTitle);
        $defs = twg_member_definitions($conn, $eventId);
        foreach ($defs as $m) {
            $short = twg_sheet_normalize_header((string) ($m['short'] ?? ''));
            $label = twg_sheet_normalize_header((string) ($m['label'] ?? ''));
            if ($titleNorm === $short || $titleNorm === $label || $titleNorm === (string) $m['key']) {
                $guessKey = (string) $m['key'];
                break;
            }
        }
        if ($guessKey === null && preg_match('/^judge_(\d+)$/', $titleNorm, $mm)) {
            $idx = ((int) $mm[1]) - 1;
            $listed = array_values($defs);
            if (isset($listed[$idx]['key'])) {
                $guessKey = (string) $listed[$idx]['key'];
            }
        }
        $rubricTry = twg_sheet_import_rubric_table($conn, $eventId, is_array($oneTable) ? $oneTable : [], $guessKey);
        if (!empty($rubricTry['matched'])) {
            $matchedRubric = true;
            $saved += (int) $rubricTry['saved'];
            $skipped += (int) $rubricTry['skipped'];
            $errors = array_merge($errors, $rubricTry['errors']);
        }
    }
}

if ($matchedRubric) {
    $ok = $saved > 0 || $errors === [];
    $message = $saved > 0
        ? ('Imported ' . $saved . ' score' . ($saved === 1 ? '' : 's') . '.')
        : ($errors === [] ? 'No new scores to import. Blank cells are skipped.' : 'Could not import scores.');
    if ($errors !== [] && $saved > 0) {
        $message .= ' Some rows were skipped.';
    }
    $result = [
        'ok' => $ok,
        'message' => $message,
        'saved' => $saved,
        'skipped' => $skipped,
        'errors' => $errors,
    ];
} else {
    if ($ext !== 'csv') {
        $table = $tables[0] ?? [];
    }
    $result = twg_sheet_import_table($conn, $eventId, $table);
}
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
} catch (Throwable $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Import failed on the server. Try CSV, or re-upload the latest TWG import files.',
    ]);
}
exit;
