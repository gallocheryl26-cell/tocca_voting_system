<?php
/**
 * nomination_message.php
 *
 * Shown when registrations are not currently accepted. Supports three reasons
 * passed via ?reason= : "closed", "upcoming", "error" (fallback).
 *
 * When possible we also surface the registration window dates so the user knows
 * exactly when to come back.
 */
declare(strict_types=1);

include __DIR__ . '/../tocca_admin/db_connection.php';
require_once __DIR__ . '/../tocca_admin/get_logo.php';
require_once __DIR__ . '/../tocca_admin/qr_url.php';

if (!function_exists('getConfig')) {
    function getConfig(string $key, string $default = ''): string {
        global $conn;
        if (!$conn instanceof mysqli) return $default;
        $stmt = $conn->prepare("SELECT config_value FROM tbl_config WHERE config_key = ?");
        if (!$stmt) return $default;
        $stmt->bind_param("s", $key);
        $stmt->execute();
        $stmt->bind_result($val);
        $out = $default;
        if ($stmt->fetch()) $out = (string)$val;
        $stmt->close();
        return $out;
    }
}

$bannerRaw = getConfig('nominationBanner', 'img/default-banner.png');
$banner    = resolveAssetPath($bannerRaw);
$bgColor   = getConfig('nominationBgColor', '#f8f9fa');
$reason = isset($_GET['reason']) ? strtolower((string)$_GET['reason']) : 'closed';
if (!in_array($reason, ['closed', 'upcoming', 'error'], true)) {
    $reason = 'closed';
}

// Try to surface the registration window dates from the active event so the
// applicant knows when to come back.
$nomStart = $nomEnd = null;
if (isset($conn) && $conn instanceof mysqli) {
    if ($r = $conn->query("
        SELECT nomination_start, nomination_end, event_name
        FROM tbl_events
        WHERE is_active = 1 AND is_archived = 0
        ORDER BY created_at DESC
        LIMIT 1
    ")) {
        if ($row = $r->fetch_assoc()) {
            $nomStart   = $row['nomination_start'] ?? null;
            $nomEnd     = $row['nomination_end']   ?? null;
            $eventName  = $row['event_name']       ?? '';
        }
        $r->close();
    }
}
function fmt_dt(?string $s): ?string {
    if (!$s) return null;
    try {
        $d = new DateTime($s, new DateTimeZone('Asia/Manila'));
        return $d->format('F j, Y · g:i A');
    } catch (Throwable $e) {
        return null;
    }
}
$startFmt = fmt_dt($nomStart);
$endFmt   = fmt_dt($nomEnd);

// Localized per-reason copy.
$trackHref = function_exists('qr_tracking_url') ? qr_tracking_url($conn instanceof mysqli ? $conn : null) : 'nomination_tracking.php';
$registerHref = function_exists('qr_nomination_form_url')
  ? qr_nomination_form_url($conn instanceof mysqli ? $conn : null)
  : 'nomination_form.php';
$copy = [
    'closed' => [
        'icon'  => 'fa-circle-xmark',
        'tone'  => 'closed',
        'title' => 'Registration Is Closed',
        'sub'   => 'The registration window for this event has ended. Thank you to everyone who participated! Please follow our official channels for updates on results and future events.',
        'cta'   => 'Track an existing registration',
        'cta_href' => $trackHref,
    ],
    'upcoming' => [
        'icon'  => 'fa-clock',
        'tone'  => 'upcoming',
        'title' => 'Registration Opens Soon',
        'sub'   => 'We&rsquo;re getting ready. Save the date below and check back during the official registration period.',
        'cta'   => 'Track an existing registration',
        'cta_href' => $trackHref,
    ],
    'error' => [
        'icon'  => 'fa-triangle-exclamation',
        'tone'  => 'error',
        'title' => 'Something Went Wrong',
        'sub'   => 'We couldn&rsquo;t load the registration form right now. Please try again in a few minutes or contact the organizers if the problem continues.',
        'cta'   => 'Retry',
        'cta_href' => $registerHref,
    ],
][$reason];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= htmlspecialchars($copy['title'], ENT_QUOTES) ?> | Tatak Ormoc</title>
  <?php if (function_exists('tocca_emit_asset_base_tag')) { tocca_emit_asset_base_tag(); } ?>
  <link rel="icon" type="image/png" href="<?= htmlspecialchars($faviconPath, ENT_QUOTES) ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
  <link rel="stylesheet" href="nomination_form.css" />
  <style>
    :root { --voter-bg: <?= htmlspecialchars($bgColor, ENT_QUOTES) ?>; }
    body {
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }
    .hero-banner img {
      max-height: clamp(140px, 22vw, 320px);
      object-fit: contain;
    }

    .status-card {
      max-width: 640px;
      margin: 0 auto;
      background: var(--tocca-surface);
      border: 1px solid var(--tocca-border);
      border-radius: var(--tocca-radius);
      padding: clamp(1.5rem, 3vw, 2.5rem);
      box-shadow: var(--tocca-shadow);
      text-align: center;
    }
    .status-icon {
      width: 88px;
      height: 88px;
      border-radius: 50%;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 2.25rem;
      margin-bottom: 1rem;
    }
    .status-icon.tone-closed   { background: rgba(220, 53, 69, 0.10); color: #dc3545; }
    .status-icon.tone-upcoming { background: rgba(13, 110, 253, 0.10); color: var(--tocca-primary); }
    .status-icon.tone-error    { background: rgba(255, 193, 7, 0.15); color: #b58a00; }

    .status-title {
      font-weight: 800;
      letter-spacing: -0.01em;
      color: var(--tocca-navy);
      font-size: clamp(1.5rem, 3.2vw, 2rem);
      margin-bottom: 0.5rem;
    }
    .status-sub {
      color: var(--tocca-text-muted);
      line-height: 1.6;
      font-size: clamp(0.95rem, 2vw, 1.05rem);
      margin: 0 auto;
      max-width: 46ch;
    }
    .date-strip {
      margin-top: 1.5rem;
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 0.75rem;
    }
    .date-strip .date-pill {
      background: rgba(30, 64, 175, 0.06);
      border: 1px solid rgba(30, 64, 175, 0.18);
      border-radius: 0.75rem;
      padding: 0.85rem 1rem;
      text-align: left;
    }
    .date-strip .date-pill .label {
      font-size: 0.75rem;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--tocca-primary);
      font-weight: 700;
    }
    .date-strip .date-pill .value {
      font-weight: 600;
      color: var(--tocca-text);
      font-size: 0.95rem;
      margin-top: 0.15rem;
    }
    @media (max-width: 480px) {
      .date-strip { grid-template-columns: 1fr; }
    }

    .status-actions {
      margin-top: 1.75rem;
      display: flex;
      gap: 0.75rem;
      justify-content: center;
      flex-wrap: wrap;
    }

    header.bg-white { background: transparent !important; }
    .site-footer {
      margin-top: auto;
      border-top: 1px solid var(--tocca-border);
      background: rgba(255, 255, 255, 0.72);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
      padding: 1rem 0 1.35rem;
      box-shadow: 0 -4px 20px rgba(1, 0, 102, 0.04);
    }
    .site-footer-inner {
      max-width: 640px;
      margin: 0 auto;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: space-between;
      gap: 0.85rem;
      text-align: center;
    }
    @media (min-width: 576px) {
      .site-footer-inner {
        flex-direction: row;
        text-align: left;
      }
    }
    .site-footer-copy {
      font-size: 0.8125rem;
      line-height: 1.4;
      color: var(--tocca-text-muted);
      margin: 0;
    }
  </style>
</head>
<body>
  <header class="hero-banner py-3">
    <div class="container text-center">
      <img src="<?= htmlspecialchars($banner, ENT_QUOTES) ?>" alt="Tatak Ormoc Registration Banner" class="img-fluid" />
    </div>
  </header>

  <main class="flex-grow-1 my-4">
    <div class="container">
      <div class="status-card">
        <div class="status-icon tone-<?= htmlspecialchars($copy['tone'], ENT_QUOTES) ?>">
          <i class="fa-solid <?= htmlspecialchars($copy['icon'], ENT_QUOTES) ?>"></i>
        </div>
        <h1 class="status-title"><?= htmlspecialchars($copy['title'], ENT_QUOTES) ?></h1>
        <p class="status-sub"><?= $copy['sub'] /* contains entity refs */ ?></p>

        <?php if ($startFmt || $endFmt): ?>
          <div class="date-strip">
            <div class="date-pill">
              <div class="label"><i class="fa-regular fa-calendar-plus me-1"></i> Opens</div>
              <div class="value"><?= $startFmt ? htmlspecialchars($startFmt, ENT_QUOTES) : 'TBA' ?></div>
            </div>
            <div class="date-pill">
              <div class="label"><i class="fa-regular fa-calendar-minus me-1"></i> Closes</div>
              <div class="value"><?= $endFmt ? htmlspecialchars($endFmt, ENT_QUOTES) : 'TBA' ?></div>
            </div>
          </div>
        <?php endif; ?>

        <div class="status-actions">
          <a class="btn btn-primary" href="<?= htmlspecialchars($copy['cta_href'], ENT_QUOTES) ?>">
            <i class="fa-solid fa-arrow-right me-1"></i> <?= htmlspecialchars($copy['cta'], ENT_QUOTES) ?>
          </a>
          <a class="btn btn-outline-secondary" href="../e-vote-final-enhanced/index.php">
            <i class="fa-solid fa-house me-1"></i> Back to Tatak Ormoc
          </a>
        </div>
      </div>
    </div>
  </main>

  <footer class="site-footer">
    <div class="container">
      <div class="site-footer-inner">
        <p class="site-footer-copy">&copy; <?= date('Y') ?> Tatak Ormoc Consumers&rsquo; Choice Awards</p>
      </div>
    </div>
  </footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
