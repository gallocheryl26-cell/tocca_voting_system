<?php
declare(strict_types=1);
/**
 * One-off: set Feelings categories to mixed voting profile.
 * Run: php tocca_admin/scripts/set_feelings_mixed_profile.php
 */
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../includes/category_voting_profile.php';

category_voting_profile_ensure_schema($conn);

$stmt = $conn->prepare(
    "UPDATE tbl_categories SET voting_profile = 'mixed' WHERE LOWER(TRIM(category_name)) = 'feelings'"
);
$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

echo "Updated {$affected} Feelings categor(ies) to voting_profile = mixed.\n";
