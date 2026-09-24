<?php
declare(strict_types=1);

/**
 * Hostinger caches JS for 30 days. ES module imports without a query string
 * keep the old Cast/Summary files after uploads. Map specifiers to filemtime.
 */
$evoteDir = dirname(__DIR__);
$jsVer = static function (string $rel) use ($evoteDir): string {
    $path = $evoteDir . '/' . ltrim($rel, '/');
    return (string) ((int) (@filemtime($path) ?: time()));
};

$jsImports = [
    './summary_data.js' => './summary_data.js?v=' . $jsVer('summary_data.js'),
    './vote_finalization.js' => './vote_finalization.js?v=' . $jsVer('vote_finalization.js'),
    './summary_renderer.js' => './summary_renderer.js?v=' . $jsVer('summary_renderer.js'),
    './summarypoll.js' => './summarypoll.js?v=' . $jsVer('summarypoll.js'),
    './js/voting_field_labels.js' => './js/voting_field_labels.js?v=' . $jsVer('js/voting_field_labels.js'),
    './js/voting_field_labels.js?v=cast4' => './js/voting_field_labels.js?v=' . $jsVer('js/voting_field_labels.js'),
    './js/voting_field_labels.js?v=cast3' => './js/voting_field_labels.js?v=' . $jsVer('js/voting_field_labels.js'),
    './js/voting_field_labels.js?v=cast2' => './js/voting_field_labels.js?v=' . $jsVer('js/voting_field_labels.js'),
    './js/voting_field_labels.js?v=plain2' => './js/voting_field_labels.js?v=' . $jsVer('js/voting_field_labels.js'),
    './data_service.js' => './data_service.js?v=' . $jsVer('data_service.js'),
    './data_service.js?v=save2' => './data_service.js?v=' . $jsVer('data_service.js'),
    './question_renderer.js' => './question_renderer.js?v=' . $jsVer('question_renderer.js'),
    './question_renderer.js?v=cast4' => './question_renderer.js?v=' . $jsVer('question_renderer.js'),
    './question_renderer.js?v=cast2' => './question_renderer.js?v=' . $jsVer('question_renderer.js'),
    './selected-category.js' => './selected-category.js?v=' . $jsVer('selected-category.js'),
    './summary_data.js?v=cast4' => './summary_data.js?v=' . $jsVer('summary_data.js'),
    './vote_finalization.js?v=cast4' => './vote_finalization.js?v=' . $jsVer('vote_finalization.js'),
    './summary_renderer.js?v=cast4' => './summary_renderer.js?v=' . $jsVer('summary_renderer.js'),
];
?>
<script type="importmap">
<?php echo json_encode(['imports' => $jsImports], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); ?>
</script>
