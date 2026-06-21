<?php
declare(strict_types=1);
/** CLI: php tocca_admin/tools/bench_qr_generate.php [choice_id] */
require_once dirname(__DIR__) . '/db_connection.php';
require_once dirname(__DIR__) . '/qr_utils.php';

$choiceId = isset($argv[1]) ? (int) $argv[1] : 0;
if ($choiceId <= 0) {
    $r = $conn->query('SELECT choice_id FROM tbl_choices WHERE status = 1 ORDER BY choice_id ASC LIMIT 1');
    $choiceId = $r && ($row = $r->fetch_assoc()) ? (int) $row['choice_id'] : 0;
}
if ($choiceId <= 0) {
    fwrite(STDERR, "No choice_id\n");
    exit(1);
}

$runs = [];
for ($i = 0; $i < 3; $i++) {
    $t0 = microtime(true);
    $result = generateAndSaveQR($choiceId, false, []);
    $ms = (int) round((microtime(true) - $t0) * 1000);
    $runs[] = ['ms' => $ms, 'status' => $result['status'] ?? 'error', 'mode' => 'reuse_if_exists'];
}

$t0 = microtime(true);
$full = generateAndSaveQR($choiceId, true, ['preview' => true]);
$runs[] = ['ms' => (int) round((microtime(true) - $t0) * 1000), 'status' => $full['status'] ?? 'error', 'mode' => 'full_regen_preview'];

echo json_encode(['choice_id' => $choiceId, 'runs' => $runs], JSON_PRETTY_PRINT) . PHP_EOL;
