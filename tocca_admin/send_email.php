<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/* -------- Robust autoload path (parent or local) -------- */
$autoload1 = __DIR__ . '/../vendor/autoload.php';
$autoload2 = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload1)) {
    require $autoload1;
} elseif (file_exists($autoload2)) {
    require $autoload2;
} else {
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'Composer autoload not found. Expected vendor/autoload.php.']);
    exit;
}

require_once __DIR__ . '/require_admin_api.php';
require_once __DIR__ . '/qr_utils.php';
require_once __DIR__ . '/includes/qr_email_body.php';
require_once __DIR__ . '/includes/qr_mailer.php';
require_once __DIR__ . '/includes/ballot_status.php';

/* ---------------- Helpers ---------------- */
function respond(array $payload, int $code = 200): void {
    if (ob_get_length()) { @ob_clean(); }
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload);
    exit;
}
function respond_error(string $message, int $code = 400, array $extra = []): void {
    respond(['status' => 'error', 'message' => $message] + $extra, $code);
}
function logFailureLocal(string $name, string $email, string $reason): void {
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents(__DIR__ . '/qr_email_failures.log', "[$timestamp] $name <$email> - $reason\n", FILE_APPEND);
}

/* -------- Hardening -------- */
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
@ob_start();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond_error('Method not allowed.', 405);
    }

    // Parse JSON or form body
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) { $data = []; }
    if (empty($data) && !empty($_POST)) { $data = $_POST; }

    $choice_id     = isset($data['choice_id']) ? (int)$data['choice_id'] : 0;
    $name          = trim((string)($data['name'] ?? ''));
    $qrFilename    = (string)($data['qr_path'] ?? ''); // filename only
    $logFailureFlg = filter_var(($data['log_failure'] ?? false), FILTER_VALIDATE_BOOLEAN);
    $customMessage = trim((string)($data['message'] ?? ''));
    $subject       = trim((string)($data['subject'] ?? 'Your Voting QR Code'));

    // Accept multiple possible email keys (fallback only)
    $email = '';
    foreach (['email','user_email','recipient_email'] as $k) {
        if (array_key_exists($k, $data) && $data[$k] !== null) {
            $email = trim((string)$data[$k]);
            break;
        }
    }

    // If choice_id is given, load recipient + event from DB and prefer those
    $eventId = null;
    if ($choice_id > 0) {
        if (!$conn) respond_error('DB connection not available.', 500);
        $stmt = $conn->prepare("SELECT choice_name, email, event_id FROM tbl_choices WHERE choice_id = ? LIMIT 1");
        if (!$stmt) respond_error('Failed to prepare DB statement (choices).', 500);
        $stmt->bind_param("i", $choice_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if ($row) {
            if (trim((string)$row['choice_name']) !== '') $name = trim((string)$row['choice_name']);
            if (trim((string)$row['email']) !== '')      $email = trim((string)$row['email']);
            if (isset($row['event_id']))                 $eventId = (int)$row['event_id'];
        } else {
            respond_error('Business record not found for given choice_id.', 404);
        }
        if (ballot_status_flag($conn, $choice_id) === false) {
            respond_error('This business is still under evaluation. Confirm it for public voting before sending the QR email.', 409);
        }
    }
    if ($name === '')  $name  = 'Business';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msg = 'Invalid or missing email address.';
        if ($logFailureFlg) { logFailureLocal($name, $email, $msg); }
        respond_error($msg, 422);
    }

    /* ============ QR: use existing PNG when present; generate only if missing ============ */
    $qrPath = '';
    $qrBasename = '';
    if ($choice_id > 0) {
        try {
            $qrPath = ensure_qr_png_for_choice($choice_id, false);
            $qrBasename = basename($qrPath);
        } catch (Throwable $e) {
            $msg = 'QR code could not be generated: ' . $e->getMessage();
            if ($logFailureFlg) { logFailureLocal($name, $email, $msg); }
            respond_error($msg, 422, ['error_code' => 'QR_NOT_FOUND']);
        }
    } else {
        $slug = qr_slug_from_choice_name($name);
        if ($slug === '') {
            $msg = 'No QR filename could be derived. Please generate the QR code first.';
            if ($logFailureFlg) { logFailureLocal($name, $email, $msg); }
            respond_error($msg, 422, ['error_code' => 'QR_NOT_FOUND']);
        }
        $qrPath = __DIR__ . '/qrcodes/' . $slug . '.png';
        if (!is_file($qrPath)) {
            $msg = sprintf("QR code not generated yet for %s. Expected: qrcodes/%s.png", $name, $slug);
            if ($logFailureFlg) { logFailureLocal($name, $email, $msg); }
            respond_error($msg, 422, ['error_code' => 'QR_NOT_FOUND']);
        }
        $qrBasename = $slug . '.png';
    }

    /* ============ Compose email HTML (reuse for logging) ============ */
    $finalSubject = $subject !== '' ? $subject : 'Your Voting QR Code';

    $voteUrl = '';
    if (function_exists('qr_vote_portal_url')) {
        try {
            $voteUrl = qr_vote_portal_url($conn);
        } catch (Throwable $e) {
            $voteUrl = '';
        }
    }
    $businessVoteUrl = '';
    if ($choice_id > 0) {
        try {
            $businessVoteUrl = make_qr_url_for_choice($choice_id);
        } catch (Throwable $e) {
            $businessVoteUrl = '';
        }
    }

    $plainBody = $customMessage !== '' ? $customMessage : qr_email_default_plain_message();
    $finalHtml = qr_email_build_html($name, $plainBody, $voteUrl, $finalSubject, $businessVoteUrl);
    if ($choice_id > 0 && $qrBasename !== '') {
        $finalHtml = qr_email_append_attachment_meta($finalHtml, $choice_id, $qrBasename);
    }

    /* ============ Send ============ */
    $mail = qr_mailer_create();
    try {
        qr_mailer_send_with_attachment($mail, $email, $name, $finalSubject, $finalHtml, $qrPath);

        // Mark as sent (tbl_choices)
        if ($choice_id > 0) {
            if ($stmt = $conn->prepare("UPDATE tbl_choices SET qr_sent = 1 WHERE choice_id = ?")) {
                $stmt->bind_param("i", $choice_id);
                $stmt->execute();
                $stmt->close();
            }
        }

        // LOG into tbl_comm_messages (so Communications page can see it)
        $stmt = $conn->prepare("
          INSERT INTO tbl_comm_messages
            (event_id, type, recipient_email, recipient_name, subject, body_html, status, error_text, retries, created_at, scheduled_at, sent_at)
          VALUES (?, 'qr_email', ?, ?, ?, ?, 'sent', NULL, 0, NOW(), NULL, NOW())
        ");
        if ($stmt) {
            $stmt->bind_param('issss', $eventId, $email, $name, $finalSubject, $finalHtml);
            $stmt->execute();
            $stmt->close();
        }

        respond(['status' => 'success']);
    } catch (Exception $e) {
        qr_mailer_close($mail);
        $msg = 'Mailer Error: ' . ($mail->ErrorInfo ?: $e->getMessage());
        if ($logFailureFlg) { logFailureLocal($name, $email, $msg); }
        error_log('send_email.php PHPMailer exception: ' . $e->getMessage());

        // LOG failure to tbl_comm_messages
        $stmt = $conn->prepare("
          INSERT INTO tbl_comm_messages
            (event_id, type, recipient_email, recipient_name, subject, body_html, status, error_text, retries, created_at, scheduled_at, sent_at)
          VALUES (?, 'qr_email', ?, ?, ?, ?, 'failed', ?, 1, NOW(), NULL, NULL)
        ");
        if ($stmt) {
            $stmt->bind_param('isssss', $eventId, $email, $name, $finalSubject, $finalHtml, $msg);
            $stmt->execute();
            $stmt->close();
        }

        respond_error($msg, 500);
    } finally {
        if (isset($mail)) {
            qr_mailer_close($mail);
        }
    }

} catch (Throwable $e) {
    error_log('send_email.php fatal: ' . $e->getMessage());
    respond_error('Server error in send_email.php', 500, ['detail' => $e->getMessage()]);
}
