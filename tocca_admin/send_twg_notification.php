<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Manila');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
@ob_start();
@set_time_limit(600);

require_once __DIR__ . '/require_admin_api.php';
require_once __DIR__ . '/comm.php';
require_once __DIR__ . '/audit_log.php';

function jerr(string $m, int $c = 400, array $x = []): void {
    if (ob_get_length()) @ob_clean();
    http_response_code($c);
    echo json_encode(['status' => 'error', 'message' => $m] + $x, JSON_UNESCAPED_UNICODE);
    exit;
}
function jok(array $p = []): void {
    if (ob_get_length()) @ob_clean();
    echo json_encode(['status' => 'success'] + $p, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jerr('POST only', 405);

$raw = file_get_contents('php://input') ?: '';
$in  = json_decode($raw, true);
if (!is_array($in)) $in = [];

$action = trim((string)($in['action'] ?? ''));

if (!isset($conn) || !($conn instanceof mysqli)) jerr('DB not available', 500);
$conn->set_charset('utf8mb4');

$PDF_PATH = __DIR__ . '/attachments/TOCCA-2026.pdf';
$PDF_NAME = 'TOCCA-2026.pdf';

$SUBJECT = '2026 Tatak Ormoc Consumers\' Choice Awards – Registration Verified';

function twg_notification_html(string $businessName): string {
    $safe = htmlspecialchars($businessName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $inner = '
<p style="margin:0 0 14px;">Thank you for registering for the <strong>2026 Tatak Ormoc Consumers\' Choice Awards (TOCCA)</strong>.</p>

<p style="margin:0 0 14px;">We are pleased to inform you that your registration has successfully passed the <strong>Registration and Verification Phase</strong>. Your entry has been confirmed as qualified and will proceed to the next stage of the 2026 TOCCA Awards Process.</p>

<h2 style="margin:22px 0 10px;font-size:18px;font-weight:700;color:#111;">What Happens Next?</h2>

<p style="margin:0 0 6px;font-size:15px;line-height:1.6;">
&#128269; <strong>STEP 2: TWG Validation and Identification of the Top Five (5)</strong><br>
&#128197; <strong>September 9&ndash;15, 2026</strong>
</p>

<p style="margin:0 0 14px;">Your entry will undergo validation and evaluation by the Technical Working Group (TWG).</p>

<p style="margin:0 0 14px;">The validation process will include inspection and scoring based on the applicable evaluation criteria and corresponding weights for your specific award category. The TWG scores will then be consolidated to determine the rankings of all qualified entries.</p>

<p style="margin:0 0 14px;">The <strong>Top Five (5)</strong> highest-ranking entries in each award category will proceed to the Public Voting Stage.</p>

<p style="margin:0 0 14px;">The TWG Validation and Inspection will account for <strong>40%</strong> of the Final Award Score.</p>

<p style="margin:0 0 14px;">Please wait for further announcements and official communication regarding the results of the TWG Validation and the next stage of the Awards Process.</p>

<p style="margin:0 0 14px;">Thank you once again for being part of the <strong>2026 Tatak Ormoc Consumers\' Choice Awards</strong>. We appreciate your participation and wish you the best of luck in the next stage!</p>

<p style="margin:18px 0 4px;">Sincerely,<br>
<strong>Tatak Ormoc Consumers\' Choice Awards (TOCCA) Organizing Committee</strong><br>
City Government of Ormoc</p>
';

    return tocca_branded_status_email(
        'Registration Verified – TOCCA 2026',
        $safe,
        'Registration Verified',
        $inner,
        '',
        '',
        false,
        false
    );
}

$EMAIL_ALIASES = ['email', 'contact_email'];
$BIZ_ALIASES   = ['business_name', 'official_business_name', 'company', 'company_name', 'business'];
$NAME_ALIASES  = ['contact_person', 'owner_name', 'full_name', 'name', 'name_of_ownder_president_general_manager'];

function norm_key(string $s): string {
    return strtolower(trim(preg_replace('/[\s_-]+/', '_', $s) ?? $s));
}

function find_answer(mysqli $conn, int $nomId, array $aliases): string {
    $want = array_map('norm_key', $aliases);
    $map  = array_flip($want);
    $sql  = "SELECT COALESCE(f.name,'') AS fname, COALESCE(f.label,'') AS flabel, a.answer
             FROM tbl_nomination_answers a
             LEFT JOIN tbl_nomination_fields f ON f.id = a.field_id
             WHERE a.nomination_id = ?";
    $st = $conn->prepare($sql);
    if (!$st) return '';
    $st->bind_param('i', $nomId);
    $st->execute();
    $rs = $st->get_result();
    while ($row = $rs->fetch_assoc()) {
        $fname  = norm_key((string)$row['fname']);
        $flabel = norm_key((string)$row['flabel']);
        if ((isset($map[$fname]) || isset($map[$flabel])) && trim((string)$row['answer']) !== '') {
            $st->close();
            return (string)$row['answer'];
        }
    }
    $st->close();
    return '';
}

/* ======== ACTION: preview (list recipients) ======== */
if ($action === 'preview') {
    $rows = $conn->query("
        SELECT n.nomination_id, n.event_id
        FROM tbl_nominations n
        WHERE n.status = 'approved'
        ORDER BY n.nomination_id
    ");
    $list = [];
    while ($r = $rows->fetch_assoc()) {
        $nid   = (int)$r['nomination_id'];
        $email = find_answer($conn, $nid, $EMAIL_ALIASES);
        $biz   = find_answer($conn, $nid, $BIZ_ALIASES);
        $name  = find_answer($conn, $nid, $NAME_ALIASES);
        $list[] = [
            'nomination_id' => $nid,
            'email'         => $email,
            'business_name' => $biz,
            'contact'       => $name,
            'valid'         => filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
        ];
    }
    $pdfExists = is_file($PDF_PATH);
    jok(['recipients' => $list, 'total' => count($list), 'pdf_attached' => $pdfExists]);
}

/* ======== ACTION: send ======== */
if ($action === 'send') {
    $ids = $in['nomination_ids'] ?? null;
    if (!is_array($ids) || empty($ids)) jerr('No recipients selected', 422);
    $ids = array_values(array_map('intval', array_filter($ids)));
    if (empty($ids)) jerr('No valid IDs', 422);

    $activeEvent = null;
    $evRes = $conn->query("SELECT event_id FROM tbl_events WHERE is_active=1 ORDER BY event_id DESC LIMIT 1");
    if ($evRes && $evRes->num_rows) $activeEvent = (int)$evRes->fetch_assoc()['event_id'];

    $pdfPath = is_file($PDF_PATH) ? $PDF_PATH : null;
    $ok   = [];
    $fail = [];

    foreach ($ids as $nid) {
        $st = $conn->prepare("SELECT nomination_id, event_id FROM tbl_nominations WHERE nomination_id = ? AND status = 'approved' LIMIT 1");
        $st->bind_param('i', $nid);
        $st->execute();
        $nom = $st->get_result()->fetch_assoc();
        $st->close();

        if (!$nom) {
            $fail[] = ['nomination_id' => $nid, 'reason' => 'Not found or not approved'];
            continue;
        }

        $email = find_answer($conn, $nid, $EMAIL_ALIASES);
        $biz   = find_answer($conn, $nid, $BIZ_ALIASES);
        $name  = find_answer($conn, $nid, $NAME_ALIASES);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $fail[] = ['nomination_id' => $nid, 'business' => $biz, 'reason' => 'Invalid email: ' . $email];
            continue;
        }

        $html = twg_notification_html($biz !== '' ? $biz : ($name !== '' ? $name : 'Sir/Madam'));

        $eventId = $activeEvent ?: (int)($nom['event_id'] ?? 0) ?: null;

        $res = queue_email($conn, [
            'event_id'        => $eventId,
            'type'            => 'twg_notification',
            'recipient_name'  => $biz !== '' ? $biz : $name,
            'recipient_email' => $email,
            'subject'         => $SUBJECT,
            'html'            => $html,
            'attach_path'     => $pdfPath,
            'attach_name'     => $PDF_NAME,
        ]);

        if (($res['status'] ?? '') === 'sent') {
            $ok[] = ['nomination_id' => $nid, 'email' => $email, 'business' => $biz, 'log_id' => $res['id']];
        } else {
            $fail[] = ['nomination_id' => $nid, 'email' => $email, 'business' => $biz, 'reason' => $res['error'] ?? 'Send failed', 'log_id' => $res['id'] ?? 0];
        }
    }

    audit_log($conn, 'communications', 'send_email_bulk', 'nomination', 0, [
        'type'       => 'twg_notification',
        'ok_count'   => count($ok),
        'fail_count' => count($fail),
    ]);

    jok([
        'ok_count'   => count($ok),
        'fail_count' => count($fail),
        'ok'         => $ok,
        'fail'       => $fail,
    ]);
}

jerr('Invalid action. Use "preview" or "send".', 422);
