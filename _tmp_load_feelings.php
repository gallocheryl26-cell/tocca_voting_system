<?php
$_GET['category_id'] = '13';
$_SERVER['REQUEST_URI'] = '/e-vote-final-enhanced/load_questions_with_choices.php';
ob_start();
$start = microtime(true);
try {
  include __DIR__ . '/e-vote-final-enhanced/load_questions_with_choices.php';
} catch (Throwable $e) {
  echo 'THROW: ' . $e->getMessage();
}
$out = ob_get_clean();
$ms = round((microtime(true) - $start) * 1000);
echo "TIME_MS={$ms} LEN=" . strlen($out) . PHP_EOL;
$j = json_decode($out, true);
if (!is_array($j)) {
  echo "NOT JSON\n";
  echo substr($out, 0, 800), PHP_EOL;
  exit;
}
echo "status=" . ($j['status'] ?? '') . " questions=" . count($j['questions'] ?? []) . PHP_EOL;
foreach (($j['questions'] ?? []) as $q) {
  echo $q['question_id'] . ' ' . $q['answer_fields'] . ' ' . $q['answer_mode'] . ' choices=' . count($q['choices'] ?? []) . ' ' . $q['question_name'] . PHP_EOL;
}
