<?php
declare(strict_types=1);
// This endpoint renders shared page layouts, never an authenticated voter page.
// There is no voter session or submission handler in the tutorial.
$screens = ['categories', 'answer', 'proof', 'next-award', 'list', 'review', 'summary', 'cast', 'confirm', 'voted', 'qr-business', 'qr-vote', 'qr-confirm', 'qr-voted', 'qr-main'];
$screen = (string) ($_GET['screen'] ?? '');
if (!in_array($screen, $screens, true)) { http_response_code(404); exit; }
$token = substr((string) ($_GET['token'] ?? ''), 0, 80);
$nonce = base64_encode(random_bytes(18));
header('Cache-Control: no-store');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; font-src 'self' data: https://cdnjs.cloudflare.com; img-src 'self' data:; connect-src 'none'; form-action 'none'; frame-src 'none'; frame-ancestors 'self'; base-uri 'self'");
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/tocca_admin/db_connection.php';
$conn->begin_transaction(MYSQLI_TRANS_START_READ_ONLY);
require_once dirname(__DIR__) . '/tocca_admin/get_logo.php';
require_once __DIR__ . '/lib/voter_flow.php';
require_once dirname(__DIR__) . '/nomination/rich_text_helpers.php';
require_once __DIR__ . '/lib/voter_redirect.php';
$voteTutorialDemo = true;
$payload = voter_flow_public_categories($conn);
$categories = $payload['categories'];
$category = null;
foreach ($categories as $candidate) {
    if (stripos($candidate['name'], 'food') !== false) { $category = $candidate; break; }
}
$category ??= $categories[0] ?? ['id' => 10, 'name' => 'Food', 'voting_profile' => 'business'];
$categoryId = (int) $category['id'];
$eventId = (int) ($payload['event_id'] ?? 1);
$profile = $category['voting_profile'] ?? 'business';
$labels = category_voting_profile_labels($profile);
$questions = [];
$votableSql = voter_flow_votable_question_sql($conn, 'q');
$stmt = $conn->prepare("SELECT q.question_id, q.question_name, q.category_id, q.choice_type, q.answer_fields FROM tbl_questions q WHERE q.category_id = ? AND {$votableSql} ORDER BY q.question_id");
$stmt->bind_param('i', $categoryId);
$stmt->execute();
$rows = $stmt->get_result();
while ($row = $rows->fetch_assoc()) {
    $fields = award_answer_fields_from_row($row, $profile);
    // Use this category's real award titles and field labels, with example entries.
    if (award_answer_fields_uses_open_text($fields)) continue;
    $named = award_answer_fields_uses_ballot_entries($fields, $row['question_name']);
    $row['question_id'] = (int) $row['question_id'];
    $row['category_id'] = (int) $row['category_id'];
    $row['answer_fields'] = $fields;
    $row['answer_mode'] = $named ? 'named_entry' : 'list';
    $row['field_labels'] = award_answer_fields_labels($fields, $profile, $row['question_name']);
    $row['choices'] = [
        ['choice_id' => 900000001, 'choice_name' => $named ? 'Example Cafe — Sample product' : 'Example Cafe', 'is_named_entry' => $named, 'has_media' => false],
        ['choice_id' => 900000002, 'choice_name' => $named ? 'Example Restaurant — Sample product' : 'Example Restaurant', 'is_named_entry' => $named, 'has_media' => false],
    ];
    $questions[] = $row;
}
$stmt->close();
$conn->rollback();
$companyLogoPath = '';
$choiceHasMedia = false;
$choiceId = 900000001;
$choice_name = 'Example Cafe';
$voterStep = $screen === 'categories' ? 1 : (in_array($screen, ['summary', 'cast', 'confirm', 'voted'], true) ? 3 : 2);
$isQr = str_starts_with($screen, 'qr-');
$isBallot = in_array($screen, ['answer', 'proof', 'next-award', 'list', 'review'], true);
$isSummary = in_array($screen, ['summary', 'cast', 'confirm', 'voted'], true);
$demoData = compact('screen', 'token', 'categories', 'questions', 'categoryId', 'eventId', 'labels', 'choice_name');
ob_start(static function (string $html) use ($nonce): string {
    return preg_replace('/<script(?![^>]*\bsrc=)(?![^>]*\bnonce=)/', '<script nonce="' . htmlspecialchars($nonce, ENT_QUOTES) . '"', $html);
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Voting tutorial example</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <?php foreach (['user-style', 'voter-layout', 'voter-components', 'voter-mobile', 'qr-vote-pages', 'voting-tutorial-preview'] as $sheet): ?>
  <link rel="stylesheet" href="css/<?= $sheet ?>.css?v=<?= (int) (@filemtime(__DIR__ . '/css/' . $sheet . '.css') ?: time()) ?>">
  <?php endforeach; ?>
  <script nonce="<?= h($nonce) ?>">window.VOTE_GUIDE_DEMO = <?= json_encode($demoData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
  <script src="js/voting_tutorial_preview.js?v=<?= (int) filemtime(__DIR__ . '/js/voting_tutorial_preview.js') ?>"></script>
</head>
<body class="voter-page<?= $isQr ? ' voter-page--qr-establishment' : '' ?>" data-page="<?= $isQr ? 'qr-establishment' : 'tutorial' ?>">
  <div class="voter-shell">
    <header class="voter-header"><img id="headerLogo" class="header-logo" src="<?= h($headerLogo ?? $voterHeaderLogoPath ?? 'img/tocca2023.jpg') ?>" alt="TOCCA Header Image"></header>
    <?php include __DIR__ . '/partials/voter_steps.php'; ?>
    <?php if ($screen === 'categories'): include __DIR__ . '/partials/voter_category_content.php';
    elseif ($isBallot): include __DIR__ . '/partials/voter_ballot_content.php';
    elseif ($isSummary): include __DIR__ . '/partials/voter_summary_content.php';
    elseif ($isQr): include __DIR__ . '/partials/voter_qr_ballot_content.php';
    endif; ?>
  </div>
  <?php include __DIR__ . '/partials/voter_confirm_modal.php'; ?>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js"></script>
  <script src="voter_dialogs.js"></script>
  <?php ob_start(); include __DIR__ . '/partials/voter_js_importmap.php'; $map = ob_get_clean(); echo str_replace('<script type="importmap">', '<script type="importmap" nonce="' . h($nonce) . '">', $map); ?>
  <?php $controller = $screen === 'categories' ? 'category.js' : ($isBallot ? 'selected-category.js' : ($isSummary ? 'summarypoll.js' : ($isQr ? 'qr_selected_category.js' : ''))); ?>
  <?php if ($controller): ?><script type="module" src="<?= $controller ?>?v=<?= (int) filemtime(__DIR__ . '/' . $controller) ?>"></script><?php endif; ?>
</body>
</html>
