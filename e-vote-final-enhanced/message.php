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

function fmt_vote_dt(?string $s): ?string {
    if (!$s) {
        return null;
    }
    try {
        $d = new DateTime($s, new DateTimeZone('Asia/Manila'));
        return $d->format('F j, Y · g:i A');
    } catch (Throwable $e) {
        return null;
    }
}

$banner = getConfig('voter_header_logo', 'img/tocca2023.jpg');

$voteStart = $voteEnd = null;
if (isset($conn) && $conn instanceof mysqli) {
    if ($r = $conn->query("
        SELECT voting_start, voting_end
        FROM tbl_events
        WHERE is_active = 1 AND is_archived = 0
        ORDER BY created_at DESC
        LIMIT 1
    ")) {
        if ($row = $r->fetch_assoc()) {
            $voteStart = $row['voting_start'] ?? null;
            $voteEnd   = $row['voting_end'] ?? null;
        }
        $r->close();
    }
}

$startFmt = fmt_vote_dt($voteStart);
$endFmt   = fmt_vote_dt($voteEnd);

$tz = new DateTimeZone('Asia/Manila');
$now = new DateTime('now', $tz);
$title = 'Voting is currently closed';
$lead = 'The voting period has ended or has not yet started. Please check back during the official voting schedule announced by the City Government of Ormoc.';
try {
    $startDt = $voteStart ? new DateTime((string) $voteStart, $tz) : null;
    $endDt = $voteEnd ? new DateTime((string) $voteEnd, $tz) : null;
    if ($startDt && $now < $startDt) {
        $title = 'Voting opens soon';
        $lead = 'Official voting has not started yet. Save the dates below and check back during the voting period.';
    } elseif ($endDt && $now > $endDt) {
        $title = 'Voting has ended';
        $lead = 'The official voting period has closed. Thank you for participating in Tatak Ormoc.';
    }
} catch (Throwable $e) {
    // keep default copy
}

$pageTitle = $title . ' | Tatak Ormoc';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php include __DIR__ . '/partials/voter_head.php'; ?>
  <style>
    .date-strip {
      margin-top: 1.5rem;
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 0.75rem;
      text-align: left;
    }
    .date-strip .date-pill {
      background: rgba(1, 0, 102, 0.05);
      border: 1px solid rgba(1, 0, 102, 0.14);
      border-radius: 0.75rem;
      padding: 0.85rem 1rem;
    }
    .date-strip .date-pill .label {
      font-size: 0.75rem;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--tocca-primary, #010066);
      font-weight: 700;
    }
    .date-strip .date-pill .value {
      font-weight: 600;
      margin-top: 0.15rem;
    }
    @media (max-width: 480px) {
      .date-strip { grid-template-columns: 1fr; }
    }
  </style>
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
      <h1 class="voter-status-title"><?php echo htmlspecialchars($title, ENT_QUOTES); ?></h1>
      <p class="voter-status-text"><?php echo htmlspecialchars($lead, ENT_QUOTES); ?></p>
      <?php if ($startFmt || $endFmt): ?>
        <div class="date-strip">
          <div class="date-pill">
            <div class="label"><i class="fa-regular fa-calendar-plus me-1"></i> Starts</div>
            <div class="value"><?php echo $startFmt ? htmlspecialchars($startFmt, ENT_QUOTES) : 'TBA'; ?></div>
          </div>
          <div class="date-pill">
            <div class="label"><i class="fa-regular fa-calendar-minus me-1"></i> Ends</div>
            <div class="value"><?php echo $endFmt ? htmlspecialchars($endFmt, ENT_QUOTES) : 'TBA'; ?></div>
          </div>
        </div>
      <?php endif; ?>
    </section>
  </div>
  <?php include __DIR__ . '/partials/voter_footer.php'; ?>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
