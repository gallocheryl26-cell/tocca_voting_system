<?php
// Public-facing page for Registration availability
include '../tocca_admin/db_connection.php';
require_once '../tocca_admin/get_logo.php'; // $faviconPath + resolveAssetPath()

if (!function_exists('getConfig')) {
    function getConfig($key, $default = '') {
        global $conn;
        $stmt = $conn->prepare("SELECT config_value FROM tbl_config WHERE config_key = ?");
        $stmt->bind_param("s", $key);
        $stmt->execute();
        $stmt->bind_result($val);
        $out = $default;
        if ($stmt->fetch()) $out = $val;
        $stmt->close();
        return $out;
    }
}

/* Theming */
$bannerRaw = getConfig('nominationBanner', 'img/default-banner.png');
$banner    = resolveAssetPath($bannerRaw);
$bgColor   = getConfig('nominationBgColor', '#f8f9fa');

/* Registration form setup status */
$activeFieldCount = 0;
$fieldsQuery = $conn->query("SELECT COUNT(*) AS cnt FROM tbl_nomination_fields WHERE is_active = 1");
if ($fieldsQuery && $fieldsQuery->num_rows) {
    $row = $fieldsQuery->fetch_assoc();
    $activeFieldCount = (int)($row['cnt'] ?? 0);
    $fieldsQuery->free();
}
$formReady = $activeFieldCount > 0;

/* Pick event: prefer active; else latest non-archived */
$event = null;
$q = $conn->query("
  SELECT event_id, event_name, year, nomination_start, nomination_end
  FROM tbl_events
  WHERE is_active = 1 AND is_archived = 0
  ORDER BY created_at DESC
  LIMIT 1
");
if ($q && $q->num_rows) {
    $event = $q->fetch_assoc();
    $q->free_result();
} else {
    $q2 = $conn->query("
      SELECT event_id, event_name, year, nomination_start, nomination_end
      FROM tbl_events
      WHERE is_archived = 0
      ORDER BY created_at DESC
      LIMIT 1
    ");
    if ($q2 && $q2->num_rows) {
        $event = $q2->fetch_assoc();
        $q2->free_result();
    }
}

/* Helpers */
function parseDateMaybe($s) {
    $s = trim((string)$s);
    if ($s === '') return null;
    try { return new DateTime($s); } catch (Exception $e) { return null; }
}
function human($dt) {
    if (!$dt) return '';
    if (class_exists('IntlDateFormatter')) {
        // Date only (no time)
        $fmt = new IntlDateFormatter(locale_get_default(), IntlDateFormatter::LONG, IntlDateFormatter::NONE);
        return $fmt->format($dt);
    }
    return $dt->format('F j, Y'); // fallback
}

/* Compute status */
$now    = new DateTime('now'); // server TZ
$title  = "Registration Is Currently Unavailable";
$sub    = "The registration schedule is not set. Please check back later.";

if ($event) {
    $ename = trim(($event['event_name'] ?? 'Event') . (isset($event['year']) && $event['year'] ? " {$event['year']}" : ''));
    $start = parseDateMaybe($event['nomination_start'] ?? '');
    $end   = parseDateMaybe($event['nomination_end'] ?? '');
} else {
    $ename = '';
    $start = null;
    $end   = null;
}

if (!$formReady) {
    $title = "Registration Form Under Maintenance";
    if ($ename !== '') {
        $sub = "The registration form for {$ename} is currently being set up. Please check back soon.";
    } else {
        $sub = "The registration form is currently being set up. Please check back soon.";
    }
} elseif ($event) {
    if ($start && $now < $start) {
        $title = "Registration Hasn’t Started Yet";
        $sub   = "Registration for {$ename} starts on " . human($start) . ". Please check back then.";
    } elseif ($end && $now > $end) {
        $title = "Registration Has Ended";
        $sub   = "Registration for {$ename} closed on " . human($end) . ". Thank you for your interest.";
    } elseif (($start && $end && $now >= $start && $now <= $end) || ($end && $now <= $end && !$start) || ($start && !$end && $now >= $start)) {
        $title = "Registration Is Open";
        $sub   = "You can submit registrations now for {$ename}" . ($end ? ". The period ends on " . human($end) . "." : ".");
    } else {
        $title = "Registration Is Currently Unavailable";
        $sub   = "The registration schedule for {$ename} has not been finalized. Please check back later.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Registration Status | Tatak Ormoc</title>
  <link rel="icon" type="image/png" href="<?php echo $faviconPath; ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <style>
    :root { --accent: #fd0d0dff; --muted: #6c757d; }
    body {
      background-color: <?php echo htmlspecialchars($bgColor, ENT_QUOTES); ?>;
      min-height: 100vh; display: flex; flex-direction: column;
    }
    header { display: flex; justify-content: center; padding: 1rem 1rem .5rem; }
    .banner-wrap { width: min(95vw, 1200px); }
    .tocca-banner { display:block; width:100%; height:auto; object-fit:contain; max-height: clamp(120px, 22vw, 360px); }
    main { text-align:center; padding: 1.25rem 1rem 2rem; }
    .message { font-weight:700; color:var(--accent); line-height:1.2; font-size:clamp(1.15rem,2.6vw,1.6rem); margin:.75rem 0 .25rem; }
    .subtext { color:var(--muted); line-height:1.5; font-size:clamp(.95rem,2.1vw,1.1rem); margin:0; }
    @media (max-width:360px){ header{padding-top:.75rem;} .message{margin-top:.5rem;} }
  </style>
</head>
<body>
  <header>
    <div class="banner-wrap">
      <img src="<?php echo htmlspecialchars($banner, ENT_QUOTES); ?>" alt="Tatak Ormoc Registration Banner" class="tocca-banner" />
    </div>
  </header>

  <main>
    <h1 class="message"><?php echo htmlspecialchars($title, ENT_QUOTES); ?></h1>
    <p class="subtext"><?php echo htmlspecialchars($sub, ENT_QUOTES); ?></p>
  </main>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>