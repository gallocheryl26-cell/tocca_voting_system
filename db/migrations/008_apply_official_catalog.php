<?php
/**
 * Replace test awards / establishment types with TOCCA AWARDS 2026.xlsx
 * (System Categories sheet). CLI only. Safe to re-run.
 *
 *   php db/migrations/008_apply_official_catalog.php
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/tocca_admin/db_connection.php';
require_once dirname(__DIR__, 2) . '/tocca_admin/includes/establishment_type_event_helpers.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

$excel = 'c:/Users/Rysha/OneDrive - ormoc.sti.ph/Desktop/TOCCA AWARDS 2026.xlsx';
if (!is_file($excel)) {
    fwrite(STDERR, "Missing Excel file: {$excel}\n");
    exit(1);
}

function cat_norm(string $s): string
{
    $s = trim($s);
    $map = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n', 'ç' => 'c',
        'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ñ' => 'n',
    ];
    $s = strtr($s, $map);
    $s = function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s);
    return (string) preg_replace('/[^a-z0-9]+/', '', $s);
}

function nature_type_name(string $raw): ?string
{
    $raw = trim((string) preg_replace('/\s+/', ' ', $raw));
    if ($raw === '') {
        return null;
    }
    $raw = trim((string) preg_replace('/\s*\/\s*/', ' / ', $raw));
    $raw = trim((string) preg_replace('/\s+/', ' ', $raw));

    $foodFull = 'Restaurant / Food Stall / Food Cart / Food Kiosk / Café / Fastfood / Snack House / Eatery';
    $foodShort = 'Restaurant / Food Stall / Food Cart / Food Kiosk / Café / Fastfood / Snack House';
    $norm = cat_norm($raw);
    if ($norm === cat_norm($foodFull) || $norm === cat_norm($foodShort)) {
        return $foodFull;
    }

    return $raw;
}

function parse_natures(string $raw): array
{
    $name = nature_type_name($raw);
    return $name === null ? [] : [$name];
}

$ss = IOFactory::load($excel);
$sheet = $ss->getSheetByName('System Categories');
if (!$sheet) {
    fwrite(STDERR, "Sheet 'System Categories' not found.\n");
    exit(1);
}

$group = '';
$awards = [];
$typeOrder = [];
foreach ($sheet->toArray(null, true, true, true) as $rIdx => $row) {
    if ((int) $rIdx < 3) {
        continue;
    }
    $g = trim((string) ($row['A'] ?? ''));
    $award = trim((string) preg_replace('/\s+/', ' ', (string) ($row['B'] ?? '')));
    $nat = trim((string) ($row['C'] ?? ''));
    if ($g !== '') {
        $group = $g;
    }
    if ($award === '' || $group === '') {
        continue;
    }
    $types = parse_natures($nat);
    $awards[] = [
        'category' => $group,
        'name'     => $award,
        'types'    => $types,
    ];
    foreach ($types as $t) {
        if (!isset($typeOrder[$t])) {
            $typeOrder[$t] = count($typeOrder) + 1;
        }
    }
}

if ($awards === []) {
    fwrite(STDERR, "No awards parsed from System Categories.\n");
    exit(1);
}

$eventId = et_get_active_event_id($conn);
if (!$eventId) {
    fwrite(STDERR, "No active event.\n");
    exit(1);
}

$awardAliases = [
    'besthamburger' => ['bestburger'],
    'bestbreakupfood' => ['bestbreakupfood'],
    'besthangoverrecoveryfood' => ['besthangoverfood', 'bestrecoveryfood', 'bestrecvoerydood'],
    'bestsalon' => ['besthairsalon'],
    'bestcafe' => ['bestcafe'],
];
$typeAliases = [
    'bakeshop' => ['bakerypastryshop', 'bakery'],
    'flowershop' => ['flowershop'],
    'salonparlor' => ['salon', 'parlor', 'hairsalon', 'salonparlor'],
    'cafe' => ['cafe'],
    'mabuhayaccommodation' => ['mabuhayaccommodation'],
    'massageparlorspa' => ['massageparlor', 'spa', 'massageparlorspa'],
    'foodcourierfreightforwardingservices' => ['foodcourier', 'freightforwardingservices', 'foodcourierfreightforwardingservices'],
    cat_norm('Restaurant / Food Stall / Food Cart / Food Kiosk / Café / Fastfood / Snack House / Eatery') => [
        'restaurant',
        'foodstall',
        'foodcart',
        'foodkiosk',
        'fastfood',
        'fastfoodchain',
        'snackhouse',
        'eatery',
        'carinderiaeatery',
        cat_norm('Restaurant / Food Stall / Food Cart / Food Kiosk / Café / Fastfood / Snack House'),
    ],
];

echo "Active event_id={$eventId}\n";
echo 'Official awards=' . count($awards) . ' types=' . count($typeOrder) . "\n";

$conn->begin_transaction();
try {
    $officialCats = [];
    foreach ($awards as $a) {
        $officialCats[$a['category']] = true;
    }

    $catIdByName = [];
    foreach (array_keys($officialCats) as $catName) {
        $st = $conn->prepare('SELECT category_id FROM tbl_categories WHERE event_id = ? AND LOWER(category_name) = LOWER(?) LIMIT 1');
        $st->bind_param('is', $eventId, $catName);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if ($row) {
            $cid = (int) $row['category_id'];
            $u = $conn->prepare('UPDATE tbl_categories SET status = 1, category_name = ? WHERE category_id = ?');
            $u->bind_param('si', $catName, $cid);
            $u->execute();
            $u->close();
        } else {
            $ins = $conn->prepare('INSERT INTO tbl_categories (category_name, event_id, status) VALUES (?, ?, 1)');
            $ins->bind_param('si', $catName, $eventId);
            $ins->execute();
            $cid = (int) $ins->insert_id;
            $ins->close();
        }
        $catIdByName[strtolower($catName)] = $cid;
    }

    $st = $conn->prepare('SELECT category_id, category_name FROM tbl_categories WHERE event_id = ?');
    $st->bind_param('i', $eventId);
    $st->execute();
    $catRows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    foreach ($catRows as $c) {
        if (!isset($officialCats[$c['category_name']]) && !isset($catIdByName[strtolower((string) $c['category_name'])])) {
            $cid = (int) $c['category_id'];
            $u = $conn->prepare('UPDATE tbl_categories SET status = 0 WHERE category_id = ?');
            $u->bind_param('i', $cid);
            $u->execute();
            $u->close();
        }
    }

    $qst = $conn->prepare(
        'SELECT q.question_id, q.question_name, q.category_id
         FROM tbl_questions q
         INNER JOIN tbl_categories c ON c.category_id = q.category_id
         WHERE c.event_id = ?'
    );
    $qst->bind_param('i', $eventId);
    $qst->execute();
    $existingQs = $qst->get_result()->fetch_all(MYSQLI_ASSOC);
    $qst->close();

    $qByNorm = [];
    foreach ($existingQs as $q) {
        $qByNorm[cat_norm((string) $q['question_name'])][] = $q;
    }

    $usedQuestionIds = [];
    $awardIdByNorm = [];
    $choiceType = 1;
    $insertedAwards = 0;
    $updatedAwards = 0;

    foreach ($awards as $a) {
        $wantNorm = cat_norm($a['name']);
        $keys = [$wantNorm];
        foreach ($awardAliases[$wantNorm] ?? [] as $alt) {
            $keys[] = $alt;
        }
        $match = null;
        foreach ($keys as $k) {
            if (!empty($qByNorm[$k])) {
                $match = array_shift($qByNorm[$k]);
                if ($qByNorm[$k] === []) {
                    unset($qByNorm[$k]);
                }
                break;
            }
        }
        $catId = $catIdByName[strtolower($a['category'])];
        if ($match) {
            $qid = (int) $match['question_id'];
            $u = $conn->prepare('UPDATE tbl_questions SET question_name = ?, category_id = ?, choice_type = ? WHERE question_id = ?');
            $u->bind_param('siii', $a['name'], $catId, $choiceType, $qid);
            $u->execute();
            $u->close();
            $updatedAwards++;
        } else {
            $ins = $conn->prepare('INSERT INTO tbl_questions (question_name, category_id, choice_type) VALUES (?, ?, ?)');
            $ins->bind_param('sii', $a['name'], $catId, $choiceType);
            $ins->execute();
            $qid = (int) $ins->insert_id;
            $ins->close();
            $insertedAwards++;
        }
        $usedQuestionIds[$qid] = true;
        $awardIdByNorm[$wantNorm] = $qid;
        $a['question_id'] = $qid;
    }

    $deletedAwards = 0;
    foreach ($existingQs as $q) {
        $qid = (int) $q['question_id'];
        if (!isset($usedQuestionIds[$qid])) {
            $d = $conn->prepare('DELETE FROM tbl_questions WHERE question_id = ?');
            $d->bind_param('i', $qid);
            $d->execute();
            $d->close();
            $deletedAwards++;
        }
    }

    $tst = $conn->query('SELECT type_id, type_name, status FROM tbl_establishment_types');
    $existingTypes = $tst->fetch_all(MYSQLI_ASSOC);
    $typeByNorm = [];
    foreach ($existingTypes as $t) {
        $typeByNorm[cat_norm((string) $t['type_name'])][] = $t;
    }

    $usedTypeIds = [];
    $typeIdByName = [];
    $insertedTypes = 0;
    $updatedTypes = 0;
    $order = 10;
    foreach ($typeOrder as $typeName => $_) {
        $wantNorm = cat_norm($typeName);
        $keys = [$wantNorm];
        foreach ($typeAliases[$wantNorm] ?? [] as $alt) {
            $keys[] = $alt;
        }
        $match = null;
        foreach ($keys as $k) {
            if (!empty($typeByNorm[$k])) {
                $match = array_shift($typeByNorm[$k]);
                if ($typeByNorm[$k] === []) {
                    unset($typeByNorm[$k]);
                }
                break;
            }
        }
        if ($match) {
            $tid = (int) $match['type_id'];
            $taken = $conn->prepare('SELECT type_id FROM tbl_establishment_types WHERE LOWER(type_name) = LOWER(?) AND type_id <> ? LIMIT 1');
            $taken->bind_param('si', $typeName, $tid);
            $taken->execute();
            $clash = $taken->get_result()->fetch_assoc();
            $taken->close();
            if ($clash) {
                $keep = (int) $clash['type_id'];
                $off = $conn->prepare('UPDATE tbl_establishment_types SET status = 0 WHERE type_id = ?');
                $off->bind_param('i', $tid);
                $off->execute();
                $off->close();
                $tid = $keep;
            }
            $u = $conn->prepare('UPDATE tbl_establishment_types SET type_name = ?, status = 1, display_order = ? WHERE type_id = ?');
            $u->bind_param('sii', $typeName, $order, $tid);
            $u->execute();
            $u->close();
            $updatedTypes++;
        } else {
            $ins = $conn->prepare('INSERT INTO tbl_establishment_types (type_name, status, display_order) VALUES (?, 1, ?)');
            $ins->bind_param('si', $typeName, $order);
            $ins->execute();
            $tid = (int) $ins->insert_id;
            $ins->close();
            $insertedTypes++;
        }
        $usedTypeIds[$tid] = true;
        $typeIdByName[$typeName] = $tid;
        $order += 10;
    }

    $normToOfficialId = [];
    foreach ($typeIdByName as $name => $tid) {
        $n = cat_norm($name);
        $normToOfficialId[$n] = $tid;
        foreach ($typeAliases[$n] ?? [] as $alt) {
            $normToOfficialId[$alt] = $tid;
        }
    }

    $remapType = static function (mysqli $conn, int $fromId, int $toId): void {
        if ($fromId <= 0 || $toId <= 0 || $fromId === $toId) {
            return;
        }
        $conn->query(
            'UPDATE IGNORE tbl_nomination_establishment_types SET type_id = ' . $toId
            . ' WHERE type_id = ' . $fromId
        );
        $conn->query('DELETE FROM tbl_nomination_establishment_types WHERE type_id = ' . $fromId);
        $conn->query(
            'UPDATE IGNORE tbl_choice_establishment_types SET type_id = ' . $toId
            . ' WHERE type_id = ' . $fromId
        );
        $conn->query('DELETE FROM tbl_choice_establishment_types WHERE type_id = ' . $fromId);
        $hasNom = $conn->query("SHOW COLUMNS FROM tbl_nominations LIKE 'establishment_type_id'");
        if ($hasNom && $hasNom->num_rows > 0) {
            $st = $conn->prepare('UPDATE tbl_nominations SET establishment_type_id = ? WHERE establishment_type_id = ?');
            $st->bind_param('ii', $toId, $fromId);
            $st->execute();
            $st->close();
        }
        $hasChoice = $conn->query("SHOW COLUMNS FROM tbl_choices LIKE 'establishment_type_id'");
        if ($hasChoice && $hasChoice->num_rows > 0) {
            $st = $conn->prepare('UPDATE tbl_choices SET establishment_type_id = ? WHERE establishment_type_id = ?');
            $st->bind_param('ii', $toId, $fromId);
            $st->execute();
            $st->close();
        }
    };

    $deactivatedTypes = 0;
    foreach ($existingTypes as $t) {
        $tid = (int) $t['type_id'];
        if (isset($usedTypeIds[$tid])) {
            continue;
        }
        $canonical = $normToOfficialId[cat_norm((string) $t['type_name'])] ?? 0;
        if ($canonical > 0) {
            $remapType($conn, $tid, $canonical);
        }
        $u = $conn->prepare('UPDATE tbl_establishment_types SET status = 0 WHERE type_id = ?');
        $u->bind_param('i', $tid);
        $u->execute();
        $u->close();
        $deactivatedTypes++;
    }

    $eventQuestionIds = array_keys($usedQuestionIds);
    if ($eventQuestionIds !== []) {
        $ph = implode(',', array_fill(0, count($eventQuestionIds), '?'));
        $del = $conn->prepare("DELETE FROM tbl_establishment_type_awards WHERE question_id IN ({$ph})");
        $types = str_repeat('i', count($eventQuestionIds));
        $del->bind_param($types, ...$eventQuestionIds);
        $del->execute();
        $del->close();
    }

    $linkIns = $conn->prepare('INSERT IGNORE INTO tbl_establishment_type_awards (type_id, question_id) VALUES (?, ?)');
    $links = 0;
    foreach ($awards as $a) {
        $qid = $awardIdByNorm[cat_norm($a['name'])] ?? 0;
        if ($qid <= 0) {
            continue;
        }
        foreach ($a['types'] as $tName) {
            $tid = $typeIdByName[$tName] ?? 0;
            if ($tid <= 0) {
                continue;
            }
            $linkIns->bind_param('ii', $tid, $qid);
            $linkIns->execute();
            $links++;
        }
    }
    $linkIns->close();

    $conn->commit();

    echo "Categories kept: " . implode(', ', array_keys($officialCats)) . "\n";
    echo "Awards inserted={$insertedAwards} updated={$updatedAwards} deleted={$deletedAwards}\n";
    echo "Types inserted={$insertedTypes} updated={$updatedTypes} deactivated={$deactivatedTypes}\n";
    echo "Type-award links written={$links}\n";
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}

$types = et_fetch_type_options_for_event($conn, $eventId);
echo 'Public Step 1 types now=' . count($types) . "\n";
foreach ($types as $t) {
    echo '  ' . $t['type_name'] . "\n";
}

$qcount = $conn->prepare(
    'SELECT COUNT(*) c FROM tbl_questions q
     JOIN tbl_categories c ON c.category_id = q.category_id
     WHERE c.event_id = ? AND COALESCE(c.status,1)=1'
);
$qcount->bind_param('i', $eventId);
$qcount->execute();
echo 'Active awards now=' . (int) ($qcount->get_result()->fetch_assoc()['c'] ?? 0) . "\n";
$qcount->close();
