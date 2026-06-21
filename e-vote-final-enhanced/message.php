<?php
include '../tocca_admin/db_connection.php';
require_once '../tocca_admin/get_logo.php';

function getConfig($key, $default = '') {
    global $conn;
    $stmt = $conn->prepare('SELECT config_value FROM tbl_config WHERE config_key = ?');
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $stmt->bind_result($val);
    $out = $default;
    if ($stmt->fetch()) {
        $out = $val;
    }
    $stmt->close();
    return $out;
}

$banner = getConfig('voter_header_logo', 'img/tocca2023.jpg');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php $pageTitle = 'Voting Closed | Tatak Ormoc'; include __DIR__ . '/partials/voter_head.php'; ?>
</head>
<body class="voter-page">
  <div class="voter-shell" style="max-width: 560px;">
    <header class="voter-header">
      <img src="<?php echo htmlspecialchars($banner, ENT_QUOTES); ?>" alt="Tatak Ormoc Banner" class="header-logo" />
    </header>
    <section class="voter-status-page">
      <div class="voter-status-icon" aria-hidden="true">
        <i class="fa-solid fa-lock"></i>
      </div>
      <h1 class="voter-status-title">Voting is currently closed</h1>
      <p class="voter-status-text">The voting period has ended or has not yet started. Please check back during the official voting schedule announced by the City Government of Ormoc.</p>
    </section>
    <?php include __DIR__ . '/partials/voter_footer.php'; ?>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
