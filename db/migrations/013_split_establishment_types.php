<?php
/**
 * Split slash-combined nature-of-business rows into individual types.
 * Copies award links to each split type and remaps existing nominations/choices.
 * Safe to re-run (idempotent).
 *
 *   php db/migrations/013_split_establishment_types.php
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/tocca_admin/db_connection.php';
require_once dirname(__DIR__, 2) . '/tocca_admin/includes/establishment_type_event_helpers.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');
et_ensure_m2m_schema($conn);

function split_type_parts(string $name): array
{
    $parts = preg_split('/\s*\/\s*/', trim($name)) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $p = trim((string) preg_replace('/\s+/', ' ', $p));
        if ($p !== '') {
            $out[] = $p;
        }
    }

    return $out;
}

function find_type_by_name(mysqli $conn, string $name): ?int
{
    $stmt = $conn->prepare(
        'SELECT type_id FROM tbl_establishment_types
         WHERE LOWER(type_name) = LOWER(?)
         ORDER BY COALESCE(status, 1) DESC, type_id ASC
         LIMIT 1'
    );
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? (int) $row['type_id'] : null;
}

function ensure_type(mysqli $conn, string $name): int
{
    $existing = find_type_by_name($conn, $name);
    if ($existing !== null) {
        $stmt = $conn->prepare('UPDATE tbl_establishment_types SET status = 1, type_name = ? WHERE type_id = ?');
        $stmt->bind_param('si', $name, $existing);
        $stmt->execute();
        $stmt->close();

        return $existing;
    }

    $stmt = $conn->prepare('INSERT INTO tbl_establishment_types (type_name, status) VALUES (?, 1)');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();

    return $id;
}

function copy_award_links(mysqli $conn, int $fromId, int $toId): int
{
    if ($fromId <= 0 || $toId <= 0 || $fromId === $toId) {
        return 0;
    }
    $stmt = $conn->prepare(
        'INSERT IGNORE INTO tbl_establishment_type_awards (type_id, question_id)
         SELECT ?, question_id FROM tbl_establishment_type_awards WHERE type_id = ?'
    );
    $stmt->bind_param('ii', $toId, $fromId);
    $stmt->execute();
    $n = $stmt->affected_rows;
    $stmt->close();

    return $n;
}

function remap_type_usage(mysqli $conn, int $fromId, array $toIds): void
{
    $toIds = array_values(array_unique(array_filter(array_map('intval', $toIds), static fn(int $id): bool => $id > 0)));
    if ($fromId <= 0 || $toIds === []) {
        return;
    }

    foreach ($toIds as $toId) {
        $stmt = $conn->prepare(
            'INSERT IGNORE INTO tbl_nomination_establishment_types (nomination_id, type_id)
             SELECT nomination_id, ? FROM tbl_nomination_establishment_types WHERE type_id = ?'
        );
        $stmt->bind_param('ii', $toId, $fromId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare(
            'INSERT IGNORE INTO tbl_choice_establishment_types (choice_id, type_id)
             SELECT choice_id, ? FROM tbl_choice_establishment_types WHERE type_id = ?'
        );
        $stmt->bind_param('ii', $toId, $fromId);
        $stmt->execute();
        $stmt->close();
    }

    $conn->query('DELETE FROM tbl_nomination_establishment_types WHERE type_id = ' . $fromId);
    $conn->query('DELETE FROM tbl_choice_establishment_types WHERE type_id = ' . $fromId);

    if ($r = $conn->query("SHOW COLUMNS FROM tbl_nominations LIKE 'establishment_type_id'")) {
        if ($r->num_rows > 0) {
            $primary = $toIds[0];
            $stmt = $conn->prepare('UPDATE tbl_nominations SET establishment_type_id = ? WHERE establishment_type_id = ?');
            $stmt->bind_param('ii', $primary, $fromId);
            $stmt->execute();
            $stmt->close();
        }
        $r->free();
    }

    if ($r = $conn->query("SHOW COLUMNS FROM tbl_choices LIKE 'establishment_type_id'")) {
        if ($r->num_rows > 0) {
            $primary = $toIds[0];
            $stmt = $conn->prepare('UPDATE tbl_choices SET establishment_type_id = ? WHERE establishment_type_id = ?');
            $stmt->bind_param('ii', $primary, $fromId);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare(
                'INSERT IGNORE INTO tbl_choice_establishment_types (choice_id, type_id)
                 SELECT choice_id, ? FROM tbl_choices
                 WHERE establishment_type_id = ? AND choice_id NOT IN (
                   SELECT choice_id FROM (
                     SELECT choice_id FROM tbl_choice_establishment_types WHERE type_id = ?
                   ) AS _x
                 )'
            );
            foreach ($toIds as $toId) {
                $stmt->bind_param('iii', $toId, $primary, $toId);
                $stmt->execute();
            }
            $stmt->close();
        }
        $r->free();
    }
}

$res = $conn->query(
    "SELECT type_id, type_name FROM tbl_establishment_types
     WHERE COALESCE(status, 1) = 1 AND type_name LIKE '%/%'
     ORDER BY type_id"
);
$combined = [];
while ($row = $res->fetch_assoc()) {
    $combined[] = $row;
}
$res->free();

if ($combined === []) {
    echo "No slash-combined active types to split.\n";
    exit(0);
}

echo 'Splitting ' . count($combined) . " combined type(s)...\n";

$conn->begin_transaction();
try {
    foreach ($combined as $row) {
        $fromId = (int) $row['type_id'];
        $fromName = (string) $row['type_name'];
        $parts = split_type_parts($fromName);
        if (count($parts) < 2) {
            echo "  skip type_id={$fromId} (not splittable): {$fromName}\n";
            continue;
        }

        $splitIds = [];
        foreach ($parts as $part) {
            $tid = ensure_type($conn, $part);
            $linked = copy_award_links($conn, $fromId, $tid);
            $splitIds[] = $tid;
            echo "    + {$part} (type_id={$tid}, +{$linked} award link(s))\n";
        }

        remap_type_usage($conn, $fromId, $splitIds);

        $stmt = $conn->prepare('UPDATE tbl_establishment_types SET status = 0 WHERE type_id = ?');
        $stmt->bind_param('i', $fromId);
        $stmt->execute();
        $stmt->close();

        echo "  split type_id={$fromId}: {$fromName}\n";
    }

    $conn->commit();
    echo "Done.\n";
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . "\n");
    exit(1);
}
