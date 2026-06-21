<?php
require_once __DIR__ . '/../includes/category_voting_profile.php';
$titles = ['Best Break Up Song', 'Best Date Place', 'Best Restaurant', 'Random Award'];
foreach ($titles as $t) {
    $l = category_voting_profile_labels_for_award('mixed', $t);
    echo $t . ' => ' . ($l['inferred_profile'] ?? '?') . ' / ' . $l['field1_label'] . ' + ' . $l['field2_label'] . PHP_EOL;
}
