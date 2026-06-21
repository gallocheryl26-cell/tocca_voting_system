<?php
declare(strict_types=1);
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../includes/category_voting_profile.php';

category_voting_profile_ensure_schema($conn);

$res = $conn->query('SELECT category_id, category_name, voting_profile FROM tbl_categories ORDER BY category_name');
while ($row = $res->fetch_assoc()) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
