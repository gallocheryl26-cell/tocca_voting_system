<?php

use PHPMailer\PHPMailer\PHPMailer;

use PHPMailer\PHPMailer\Exception;

date_default_timezone_set('Asia/Manila');



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



function make_body_html(string $name, string $customMessage, string $voteUrl = '', string $subject = 'Your QR Code for Tatak Ormoc Voting', string $businessVoteUrl = ''): string {

    $plain = $customMessage !== '' ? $customMessage : qr_email_default_plain_message();

    return qr_email_build_html($name ?: 'Business', $plain, $voteUrl, $subject, $businessVoteUrl);

}



function log_comm(mysqli $conn, ?int $eventId, string $email, string $name, string $subject, string $html, string $status, ?string $error = null): int {

    $stmt = $conn->prepare("

      INSERT INTO tbl_comm_messages

        (event_id, type, recipient_email, recipient_name, subject, body_html, status, error_text, retries, created_at, scheduled_at, sent_at)

      VALUES (?, 'qr_email', ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?)

    ");

    if (!$stmt) return 0;



    $retries = $status === 'failed' ? 1 : 0;

    $now     = date('Y-m-d H:i:s');

    $sent_at = $status === 'sent' ? $now : null;



    $stmt->bind_param(

        'issssssiss',

        $eventId, $email, $name, $subject, $html, $status, $error, $retries, $now, $sent_at

    );

    $stmt->execute();

    $id = (int)$stmt->insert_id;

    $stmt->close();

    return $id;

}



function mark_choice_qr_sent(mysqli $conn, int $choice_id): void {

    $stmt = $conn->prepare("UPDATE tbl_choices SET qr_sent = 1 WHERE choice_id = ?");

    if ($stmt) {

        $stmt->bind_param("i", $choice_id);

        $stmt->execute();

        $stmt->close();

    }

}



ini_set('display_errors', '0');

ini_set('log_errors', '1');

error_reporting(E_ALL);

@ob_start();

@set_time_limit(300);



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



    $raw  = file_get_contents('php://input');

    $data = json_decode($raw, true);

    if (!is_array($data)) {

        $data = [];

    }



    $idsRaw = $data['choice_ids'] ?? null;

    $ids = is_array($idsRaw)

        ? array_values(array_filter(array_map('intval', $idsRaw)))

        : [];

    if ($ids === []) {

        respond_error('No choice_ids provided.', 422);

    }



    $customMessage = trim((string)($data['message'] ?? ''));

    $subject       = trim((string)($data['subject'] ?? 'Your Voting QR Code'));

    $logFailureFlg = filter_var(($data['log_failure'] ?? false), FILTER_VALIDATE_BOOLEAN);

    $finalSubject  = $subject !== '' ? $subject : 'Your Voting QR Code';



    $choicesById = qr_load_choices_batch($conn, $ids);

    $ok = [];

    $fail = [];



    $mail = qr_mailer_create();



    try {

        foreach ($ids as $choice_id) {

            $name = 'Business';

            $email = '';

            $eventId = null;



            try {

                $row = $choicesById[$choice_id] ?? null;

                if (!$row) {

                    $fail[] = ['choice_id' => $choice_id, 'reason' => 'Business record not found.'];

                    continue;

                }

                if (ballot_status_flag($conn, (int) $choice_id) === false) {

                    $fail[] = ['choice_id' => $choice_id, 'reason' => 'Still under evaluation. Confirm for public voting first.'];

                    continue;

                }



                $name    = trim((string)($row['choice_name'] ?? '')) ?: 'Business';

                $email   = trim((string)($row['email'] ?? ''));

                $eventId = isset($row['event_id']) ? (int)$row['event_id'] : null;



                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {

                    $reason = 'Invalid or missing email address.';

                    if ($logFailureFlg) {

                        logFailureLocal($name, $email, $reason);

                    }

                    $log_id = log_comm($conn, $eventId, $email, $name, $finalSubject, make_body_html($name, $customMessage), 'failed', $reason);

                    $fail[] = ['choice_id' => $choice_id, 'email' => $email, 'reason' => $reason, 'log_id' => $log_id];

                    continue;

                }



                try {

                    $qrPath = ensure_qr_png_for_choice((int)$choice_id, false);

                } catch (Throwable $e) {

                    $reason = "QR code could not be prepared for '{$name}': " . $e->getMessage();

                    if ($logFailureFlg) {

                        logFailureLocal($name, $email, $reason);

                    }

                    $log_id = log_comm($conn, $eventId, $email, $name, $finalSubject, make_body_html($name, $customMessage), 'failed', $reason);

                    $fail[] = ['choice_id' => $choice_id, 'email' => $email, 'reason' => $reason, 'log_id' => $log_id];

                    continue;

                }

                $qrBasename = basename($qrPath);



                $voteUrl = '';
                $businessVoteUrl = '';

                try {
                    $voteUrl = function_exists('qr_vote_portal_url') ? qr_vote_portal_url($conn) : '';
                } catch (Throwable $e) {
                    $voteUrl = '';
                }
                try {
                    $businessVoteUrl = make_qr_url_for_choice((int)$choice_id);
                } catch (Throwable $e) {
                    $businessVoteUrl = '';
                }

                $finalHtml = make_body_html($name, $customMessage, $voteUrl, $finalSubject, $businessVoteUrl);

                $finalHtml = qr_email_append_attachment_meta($finalHtml, (int)$choice_id, $qrBasename);



                try {

                    qr_mailer_send_with_attachment($mail, $email, $name, $finalSubject, $finalHtml, $qrPath);

                    mark_choice_qr_sent($conn, (int)$choice_id);

                    $log_id = log_comm($conn, $eventId, $email, $name, $finalSubject, $finalHtml, 'sent', null);

                    $ok[] = ['choice_id' => $choice_id, 'email' => $email, 'log_id' => $log_id];

                } catch (Exception $e) {

                    $reason = 'Mailer Error: ' . ($mail->ErrorInfo ?: $e->getMessage());

                    if ($logFailureFlg) {

                        logFailureLocal($name, $email, $reason);

                    }

                    $log_id = log_comm($conn, $eventId, $email, $name, $finalSubject, $finalHtml, 'failed', $reason);

                    $fail[] = ['choice_id' => $choice_id, 'email' => $email, 'reason' => $reason, 'log_id' => $log_id];

                }

            } catch (Throwable $t) {

                $reason = 'Server error: ' . $t->getMessage();

                $log_id = log_comm(

                    $conn,

                    $eventId,

                    $email,

                    $name,

                    $finalSubject,

                    make_body_html($name, $customMessage),

                    'failed',

                    $reason

                );

                $fail[] = ['choice_id' => $choice_id, 'reason' => $reason, 'log_id' => $log_id];

            }

        }

    } finally {

        qr_mailer_close($mail);

    }



    respond([

        'status'     => 'success',

        'ok_count'   => count($ok),

        'fail_count' => count($fail),

        'ok'         => $ok,

        'fail'       => $fail,

    ]);

} catch (Throwable $e) {

    error_log('send_all_email.php fatal: ' . $e->getMessage());

    respond_error('Server error in send_all_email.php', 500, ['detail' => $e->getMessage()]);

}


