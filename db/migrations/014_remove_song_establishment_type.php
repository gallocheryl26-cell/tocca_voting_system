<?php
/**
 * Remove "Song" from registration Nature of Business (voting-only).
 * Safe to re-run.
 *
 *   php db/migrations/014_remove_song_establishment_type.php
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/tocca_admin/db_connection.php';
require_once dirname(__DIR__, 2) . '/tocca_admin/includes/establishment_type_event_helpers.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');
et_ensure_m2m_schema($conn);

$stmt = $conn->prepare(
    "SELECT type_id FROM tbl_establishment_types WHERE LOWER(type_name) = 'song' LIMIT 1"
);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    echo "No Song establishment type found — nothing to do.\n";
    exit(0);
}

$songId = (int) $row['type_id'];
echo "Removing Song (type_id={$songId}) from registration…\n";

$conn->begin_transaction();
try {
    $stmt = $conn->prepare('UPDATE tbl_establishment_types SET status = 0 WHERE type_id = ?');
    $stmt->bind_param('i', $songId);
    $stmt->execute();
    $stmt->close();

    $conn->query('DELETE FROM tbl_establishment_type_awards WHERE type_id = ' . $songId);
    $conn->query('DELETE FROM tbl_nomination_establishment_types WHERE type_id = ' . $songId);
    $conn->query('DELETE FROM tbl_choice_establishment_types WHERE type_id = ' . $songId);

    if ($r = $conn->query("SHOW COLUMNS FROM tbl_nominations LIKE 'establishment_type_id'")) {
        if ($r->num_rows > 0) {
            $stmt = $conn->prepare(
                'UPDATE tbl_nominations SET establishment_type_id = NULL WHERE establishment_type_id = ?'
            );
            $stmt->bind_param('i', $songId);
            $stmt->execute();
            $stmt->close();
        }
        $r->free();
    }

    if ($r = $conn->query("SHOW COLUMNS FROM tbl_choices LIKE 'establishment_type_id'")) {
        if ($r->num_rows > 0) {
            $stmt = $conn->prepare(
                'UPDATE tbl_choices SET establishment_type_id = NULL WHERE establishment_type_id = ?'
            );
            $stmt->bind_param('i', $songId);
            $stmt->execute();
            $stmt->close();
        }
        $r->free();
    }

    $stmt = $conn->prepare(
        "UPDATE tbl_nomination_fields
         SET label = 'Website/Facebook Page Link',
             placeholder = 'https://example.com or Facebook page URL',
             updated_at = NOW()
         WHERE LOWER(name) = 'website' OR LOWER(label) = 'website'"
    );
    $stmt->execute();
    $updatedFields = $stmt->affected_rows;
    $stmt->close();

    $conn->commit();
    echo "Done. Website field rows updated: {$updatedFields}\n";
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . "\n");
    exit(1);
}
