<?php
/**
 * Seed recommended establishment types + award links for the active event.
 * Safe to re-run: updates existing types by name and replaces award links.
 */
declare(strict_types=1);

require __DIR__ . '/../db_connection.php';
require __DIR__ . '/../includes/establishment_type_event_helpers.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

$eventId = et_get_active_event_id($conn);
if (!$eventId) {
    fwrite(STDERR, "No active event found.\n");
    exit(1);
}

echo "Active event_id={$eventId}\n";

// Exact award titles currently in DB (excluding obvious test junk).
$plan = [
    'Café' => [
        'Best Café',
        'Best Cupcake/Muffin',
        'Best Chocolate Cake',
        'Best Date Place',
    ],
    'Restaurant' => [
        'Best Burger',
        'Best Chicken Barbecue',
        'Best Pancit',
        'Best Halo-Halo',
        'Best Catering Services',
        'Best Date Place',
        'Best Break - up Food',
        'Best Hangover Food',
        'Best Recovery Food',
    ],
    'Fast Food Chain' => [
        'Best Burger',
        'Best Chicken Barbecue',
        'Best Hangover Food',
    ],
    'Bakery / Pastry Shop' => [
        'Best Chocolate Cake',
        'Best Cupcake/Muffin',
    ],
    'Carinderia / Eatery' => [
        'Best Pancit',
        'Best Chicken Barbecue',
        'Best Halo-Halo',
        'Best Hangover Food',
        'Best Recovery Food',
        'Best Break - up Food',
    ],
    'Catering Service' => [
        'Best Catering Services',
    ],
    'Grocery / Mini Mart' => [
        'Best Grocery Shop',
    ],
    'Meat Shop' => [
        'Best Meat Shop',
    ],
    'Dried Fish / Pasalubong' => [
        'Best Dried Fish',
    ],
    'Fruit Stand' => [
        'Best Fruit Stand',
    ],
    'Flower Shop' => [
        'Best Flower Shop',
    ],
    'Barbershop' => [
        'Best Barbershop',
    ],
    'Hair Salon' => [
        'Best Hair Salon',
        'Best Hairstylist',
    ],
];

// Also accept legacy spelling from existing UI ("Cafe" without accent).
$aliases = [
    'Cafe' => 'Café',
];

// Load awards for this event: lowercase name => question_id
$awardMap = [];
$awardExact = [];
$stmt = $conn->prepare("
    SELECT q.question_id, q.question_name
    FROM tbl_questions q
    INNER JOIN tbl_categories c ON c.category_id = q.category_id AND c.event_id = ?
");
$stmt->bind_param('i', $eventId);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $name = trim((string) $row['question_name']);
    $id = (int) $row['question_id'];
    $awardExact[$name] = $id;
    $awardMap[mb_strtolower($name)] = $id;
}
$stmt->close();

function resolve_award_id(array $awardExact, array $awardMap, string $wanted): ?int
{
    if (isset($awardExact[$wanted])) {
        return $awardExact[$wanted];
    }
    $key = mb_strtolower($wanted);
    if (isset($awardMap[$key])) {
        return $awardMap[$key];
    }
    // Fuzzy: ignore spaces/punctuation differences
    $norm = preg_replace('/[^a-z0-9]+/i', '', $key);
    foreach ($awardMap as $name => $id) {
        $n = preg_replace('/[^a-z0-9]+/i', '', $name);
        if ($n === $norm) {
            return $id;
        }
    }
    return null;
}

// Load existing types by lowercase name
$existing = [];
$r = $conn->query('SELECT type_id, type_name, COALESCE(status,1) AS status FROM tbl_establishment_types');
while ($row = $r->fetch_assoc()) {
    $existing[mb_strtolower(trim((string) $row['type_name']))] = [
        'type_id'   => (int) $row['type_id'],
        'type_name' => (string) $row['type_name'],
        'status'    => (int) $row['status'],
    ];
}

$conn->begin_transaction();
try {
    $created = 0;
    $updated = 0;
    $missingAwards = [];

    foreach ($plan as $typeName => $awardNames) {
        $lookupKeys = [mb_strtolower($typeName)];
        // If plan key is Café, also find Cafe
        if ($typeName === 'Café') {
            $lookupKeys[] = 'cafe';
        }
        foreach ($aliases as $alias => $canonical) {
            if ($canonical === $typeName) {
                $lookupKeys[] = mb_strtolower($alias);
            }
        }

        $typeId = null;
        $foundKey = null;
        foreach ($lookupKeys as $k) {
            if (isset($existing[$k])) {
                $typeId = $existing[$k]['type_id'];
                $foundKey = $k;
                break;
            }
        }

        if ($typeId === null) {
            $ins = $conn->prepare('INSERT INTO tbl_establishment_types (type_name, status) VALUES (?, 1)');
            $ins->bind_param('s', $typeName);
            $ins->execute();
            $typeId = (int) $conn->insert_id;
            $ins->close();
            $created++;
            echo "+ Created: {$typeName} (id={$typeId})\n";
        } else {
            $upd = $conn->prepare('UPDATE tbl_establishment_types SET type_name = ?, status = 1 WHERE type_id = ?');
            $upd->bind_param('si', $typeName, $typeId);
            $upd->execute();
            $upd->close();
            $updated++;
            echo "~ Updated: {$typeName} (id={$typeId})\n";
        }

        // Replace award links for this type (event-scoped awards only).
        $questionIds = [];
        foreach ($awardNames as $an) {
            $qid = resolve_award_id($awardExact, $awardMap, $an);
            if ($qid === null) {
                $missingAwards[] = "{$typeName} → {$an}";
                continue;
            }
            $questionIds[$qid] = true;
        }
        $questionIds = array_map('intval', array_keys($questionIds));

        // Delete only links to awards that belong to this event (preserve other-event links if any).
        $del = $conn->prepare("
            DELETE x FROM tbl_establishment_type_awards x
            INNER JOIN tbl_questions q ON q.question_id = x.question_id
            INNER JOIN tbl_categories c ON c.category_id = q.category_id AND c.event_id = ?
            WHERE x.type_id = ?
        ");
        $del->bind_param('ii', $eventId, $typeId);
        $del->execute();
        $del->close();

        if ($questionIds !== []) {
            $link = $conn->prepare('INSERT INTO tbl_establishment_type_awards (type_id, question_id) VALUES (?, ?)');
            foreach ($questionIds as $qid) {
                $link->bind_param('ii', $typeId, $qid);
                $link->execute();
            }
            $link->close();
        }

        echo "  linked " . count($questionIds) . " award(s)\n";
    }

    $conn->commit();

    echo "\nDone. created={$created} updated={$updated}\n";
    if ($missingAwards) {
        echo "Missing awards (skipped):\n";
        foreach ($missingAwards as $m) {
            echo "  - {$m}\n";
        }
    }

    // Summary for active event
    echo "\nTypes visible for this event:\n";
    $types = et_fetch_types_for_event($conn, $eventId);
    foreach ($types as $t) {
        $aw = array_map(static fn($a) => $a['question_name'], $t['awards'] ?? []);
        echo sprintf(
            "  • %s [%s] — %s\n",
            $t['type_name'],
            ((int) $t['status'] === 1 ? 'active' : 'inactive'),
            $aw ? implode(', ', $aw) : '(no awards)'
        );
    }
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
