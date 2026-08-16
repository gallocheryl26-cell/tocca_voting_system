<?php
/**
 * One-shot: add merged_choice_id, migrate NOM- → REG-, backfill links, allocate slugs.
 * Safe to re-run.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/tocca_admin/db_connection.php';
require_once dirname(__DIR__, 2) . '/tocca_admin/includes/public_slugs.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "No database connection.\n");
    exit(1);
}
$conn->set_charset('utf8mb4');

function m007_has_col(mysqli $conn, string $table, string $col): bool
{
    $st = $conn->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $st->bind_param('ss', $table, $col);
    $st->execute();
    $st->store_result();
    $ok = $st->num_rows > 0;
    $st->close();
    return $ok;
}

function m007_norm(string $s): string
{
    $s = trim($s);
    $lower = function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s);
    return (string) preg_replace('/\s+/', ' ', $lower);
}

$report = [];

if (!m007_has_col($conn, 'tbl_nominations', 'merged_choice_id')) {
    if (!$conn->query(
        'ALTER TABLE tbl_nominations
           ADD COLUMN merged_choice_id INT NULL DEFAULT NULL,
           ADD KEY idx_nom_merged_choice (merged_choice_id)'
    )) {
        fwrite(STDERR, 'ALTER failed: ' . $conn->error . "\n");
        exit(1);
    }
    $report[] = 'Added tbl_nominations.merged_choice_id';
} else {
    $report[] = 'merged_choice_id already present';
}

$migrated = 0;
$skipped = 0;
$rs = $conn->query("SELECT nomination_id, reference_no FROM tbl_nominations WHERE reference_no LIKE 'NOM-%'");
if ($rs) {
    while ($row = $rs->fetch_assoc()) {
        $old = (string) $row['reference_no'];
        $new = 'REG-' . substr($old, 4);
        $chk = $conn->prepare('SELECT nomination_id FROM tbl_nominations WHERE reference_no = ? LIMIT 1');
        $chk->bind_param('s', $new);
        $chk->execute();
        $taken = $chk->get_result()->fetch_assoc();
        $chk->close();
        if ($taken) {
            $skipped++;
            continue;
        }
        $id = (int) $row['nomination_id'];
        $u = $conn->prepare('UPDATE tbl_nominations SET reference_no = ? WHERE nomination_id = ?');
        $u->bind_param('si', $new, $id);
        $u->execute();
        $u->close();
        $migrated++;
    }
    $rs->free();
}
$report[] = "Reference NOM→REG migrated={$migrated} skipped_collision={$skipped}";

$choiceByEventName = [];
$crs = $conn->query('SELECT choice_id, event_id, choice_name FROM tbl_choices');
if ($crs) {
    while ($c = $crs->fetch_assoc()) {
        $eid = (int) $c['event_id'];
        $key = m007_norm((string) $c['choice_name']);
        if ($key === '') {
            continue;
        }
        $choiceByEventName[$eid][$key] = (int) $c['choice_id'];
    }
    $crs->free();
}

$linked = 0;
$nrs = $conn->query(
    "SELECT nomination_id, event_id FROM tbl_nominations
     WHERE status IN ('approved','merged')
       AND (merged_choice_id IS NULL OR merged_choice_id = 0)"
);
if ($nrs) {
    $ans = $conn->prepare(
        "SELECT COALESCE(f.name,'') AS fname, COALESCE(f.label,'') AS flabel, a.answer
         FROM tbl_nomination_answers a
         LEFT JOIN tbl_nomination_fields f ON f.id = a.field_id
         WHERE a.nomination_id = ?"
    );
    while ($n = $nrs->fetch_assoc()) {
        $nid = (int) $n['nomination_id'];
        $eid = (int) $n['event_id'];
        $biz = '';
        $ans->bind_param('i', $nid);
        $ans->execute();
        $ar = $ans->get_result();
        while ($a = $ar->fetch_assoc()) {
            $name = strtolower(trim((string) ($a['fname'] ?? '')));
            $label = strtolower(trim((string) ($a['flabel'] ?? '')));
            $val = trim((string) ($a['answer'] ?? ''));
            if ($val === '') {
                continue;
            }
            if (in_array($name, ['business_name', 'official_business_name', 'company', 'company_name'], true)
                || str_contains($label, 'business name')
                || str_contains($label, 'company name')) {
                $biz = $val;
                break;
            }
        }
        if ($biz === '') {
            continue;
        }
        $key = m007_norm($biz);
        $cid = $choiceByEventName[$eid][$key] ?? 0;
        if ($cid <= 0) {
            continue;
        }
        $u = $conn->prepare('UPDATE tbl_nominations SET merged_choice_id = ? WHERE nomination_id = ?');
        $u->bind_param('ii', $cid, $nid);
        $u->execute();
        $u->close();
        $linked++;
    }
    $ans->close();
    $nrs->free();
}
$report[] = "Backfilled merged_choice_id for {$linked} approved/merged row(s)";

$slugs = public_slug_ensure_missing_choices($conn);
$report[] = "Allocated public_slug for {$slugs} business(es)";

foreach ($report as $line) {
    echo $line, PHP_EOL;
}
