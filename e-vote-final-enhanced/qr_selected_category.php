<?php
require_once __DIR__ . '/../tocca_admin/db_connection.php';
require_once __DIR__ . '/voter_session.php';
require_once __DIR__ . '/lib/voter_flow.php';
require_once __DIR__ . '/lib/voter_redirect.php';

$choiceId = isset($_GET['choice_id']) ? (int) $_GET['choice_id'] : 0;
if ($choiceId <= 0) {
  tocca_voter_redirect('index.php');
}
$qrLoginPage = 'qr_vote.php?choice_id=' . $choiceId;
voter_session_start();
$voterId = (int) ($_SESSION['voter_id'] ?? 0);
// The shared gate accepts verified Google sessions and legacy access codes.
if ($voterId <= 0 || !voter_flow_is_voting_open($conn)
    || !voter_flow_voter_has_access_code($conn, $voterId)) {
  tocca_voter_redirect($qrLoginPage);
}
$event = voter_flow_active_event($conn);
voter_session_release();

require_once __DIR__ . '/../tocca_admin/get_logo.php';
require_once __DIR__ . '/../tocca_admin/includes/ballot_status.php';
mysqli_report(MYSQLI_REPORT_OFF);
function findCompanyLogoForChoice(mysqli $conn, int $choiceId): ?string {
  $choiceCols = ['logo_path','logo','logo_url','image_path'];
  foreach ($choiceCols as $col) {
    try {
      $sql = "SELECT $col AS p FROM tbl_choices WHERE choice_id = ? LIMIT 1";
      if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param('i', $choiceId);
        $stmt->execute();
        $stmt->bind_result($p);
        if ($stmt->fetch() && $p !== null && $p !== '') { $stmt->close(); return (string)$p; }
        $stmt->close();
      }
    } catch (Throwable $e) {
    }
  }
  try {
    $sql = "
      SELECT a.answer
      FROM tbl_nominations n
      JOIN tbl_nomination_answers a ON a.nomination_id = n.nomination_id
      JOIN tbl_nomination_fields  f ON f.id = a.field_id
      WHERE n.merged_choice_id = ?
        AND (
          LOWER(f.name)  IN ('logo','logo_path','business_logo','company_logo')
          OR LOWER(f.label) LIKE '%logo%'
        )
      ORDER BY n.updated_at DESC, a.created_at DESC, a.id DESC
      LIMIT 1
    ";
    if ($stmt = $conn->prepare($sql)) {
      $stmt->bind_param('i', $choiceId);
      $stmt->execute();
      $stmt->bind_result($ans);
      if ($stmt->fetch() && $ans !== null && $ans !== '') { $stmt->close(); return (string)$ans; }
      $stmt->close();
    }
  } catch (Throwable $e) {
  }
  return null;
}
$companyLogoPath = null;
$choice_name = '';
$choiceHasMedia = false;
if ($choiceId > 0 && ballot_status_flag($conn, $choiceId) === false) {
  tocca_voter_redirect($qrLoginPage);
}
if ($choiceId > 0) {
  $companyLogoPath = findCompanyLogoForChoice($conn, $choiceId);
  if ($companyLogoPath) $companyLogoPath = resolveAssetPath($companyLogoPath);

  if ($stmt = $conn->prepare('SELECT choice_name, status FROM tbl_choices WHERE choice_id = ? LIMIT 1')) {
    $stmt->bind_param('i', $choiceId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || (int) ($row['status'] ?? 0) !== 1) {
      tocca_voter_redirect($qrLoginPage);
    }
    $choice_name = (string) $row['choice_name'];
  }

  if ($res = $conn->query("SHOW TABLES LIKE 'tbl_choice_media'")) {
    if ($res->num_rows > 0) {
      if ($mStmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM tbl_choice_media WHERE choice_id = ?')) {
        $mStmt->bind_param('i', $choiceId);
        $mStmt->execute();
        $mStmt->bind_result($mediaCnt);
        if ($mStmt->fetch()) {
          $choiceHasMedia = ((int) $mediaCnt) > 0;
        }
        $mStmt->close();
      }
    }
    $res->close();
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php $pageTitle = 'QR Vote | Tatak Ormoc'; include __DIR__ . '/partials/voter_head.php'; ?>
  <script>
    localStorage.setItem('voter_id', <?php echo json_encode((string) $voterId); ?>);
    localStorage.setItem('current_event_id', <?php echo json_encode((string) ($event['event_id'] ?? '')); ?>);
    localStorage.setItem('qr_choice_id', <?php echo json_encode((string) $choiceId); ?>);
    localStorage.setItem('qr_business', <?php echo json_encode($choice_name, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);
  </script>
  <link rel="stylesheet" href="css/qr-vote-pages.css?v=<?php echo (int) (@filemtime(__DIR__ . '/css/qr-vote-pages.css') ?: time()); ?>" />
</head>
<?php $voterStep = 2; ?>
<body class="voter-page voter-page--qr-establishment" data-page="qr-establishment">
  <div class="voter-shell">
    <header class="voter-header">
      <img id="headerLogo" src="<?php echo h($headerLogo ?? $voterHeaderLogoPath ?? 'img/tocca2023.jpg'); ?>" alt="TOCCA Header Image" class="header-logo" />
    </header>

    <?php include __DIR__ . '/partials/voter_steps.php'; ?>

    <?php include __DIR__ . '/partials/voter_qr_ballot_content.php'; ?>

  </div>

  <?php include __DIR__ . '/partials/voter_footer.php'; ?>
  <?php $voteTutorialFlow = 'qr'; include __DIR__ . '/partials/voter_tutorial.php'; ?>
  <?php include __DIR__ . '/partials/choice_media_modal.php'; ?>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="js/voter_modal_stack.js"></script>
  <script src="voter_dialogs.js"></script>
  <script src="progress_helper.js"></script>
  <script src="choice_media_viewer.js"></script>
  <?php include __DIR__ . '/partials/voter_js_importmap.php'; ?>
  <script type="module" src="qr_selected_category.js?v=<?php echo (int) (@filemtime(__DIR__ . '/qr_selected_category.js') ?: time()); ?>"></script>
  <script src="apply_voter_style.js"></script>
  <script src="toast.js"></script>
  <script src="signout.js"></script>
  <script>
    (function () {
      var btn = document.getElementById('viewBusinessMediaBtn');
      if (!btn) return;
      btn.addEventListener('click', function () {
        var cid = parseInt(btn.getAttribute('data-choice-id') || '0', 10);
        var cname = btn.getAttribute('data-choice-name') || '';
        if (!cid || !window.ChoiceMediaViewer) return;
        window.ChoiceMediaViewer.open(cid, cname);
      });
    })();
  </script>
  <?php include __DIR__ . '/partials/voter_confirm_modal.php'; ?>

  <div class="modal fade voter-modal" id="confirmSignOutModal" tabindex="-1" aria-labelledby="confirmSignOutLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="confirmSignOutLabel">Before You Sign Out</h5>
        </div>
        <div class="modal-body" id="confirmSignOutMessage"></div>
        <div class="modal-footer flex-column gap-2 border-0 pt-0">
          <button type="button" id="confirmSignOutBtn" class="btn btn-outline-danger w-100">Sign out anyway</button>
          <button type="button" class="btn btn-secondary w-100" data-bs-dismiss="modal">Continue voting here</button>
        </div>
      </div>
    </div>
  </div>
  <div class="toast-container position-fixed top-0 start-50 translate-middle-x p-3">
    <div id="validationToast" class="toast text-bg-info" role="alert" aria-live="assertive" aria-atomic="true">
      <div class="toast-body"></div>
    </div>
  </div>
  <script>
    function DisableBackButton(){ window.history.forward(); }
    DisableBackButton();
    window.onload = DisableBackButton;
    window.onpageshow = function(evt){ if (evt.persisted) DisableBackButton(); }
    window.onunload = function(){};
  </script>
</body>
</html>
