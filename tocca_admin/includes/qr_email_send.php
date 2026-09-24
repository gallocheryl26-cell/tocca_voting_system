<?php
declare(strict_types=1);

/**
 * Send the voting QR poster plus public vote links for one business.
 * Used by Send Email and by Confirm for public voting.
 */

require_once dirname(__DIR__) . '/audit_log.php';

if (!function_exists('qr_email_send_autoload_mailer')) {
    function qr_email_send_autoload_mailer(): bool
    {
        $candidates = [
            dirname(__DIR__, 2) . '/vendor/autoload.php',
            dirname(__DIR__) . '/vendor/autoload.php',
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                require_once $path;
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('qr_email_send_log_failure')) {
    function qr_email_send_log_failure(string $name, string $email, string $reason): void
    {
        $timestamp = date('Y-m-d H:i:s');
        @file_put_contents(
            dirname(__DIR__) . '/qr_email_failures.log',
            "[$timestamp] $name <$email> - $reason\n",
            FILE_APPEND
        );
    }
}

if (!function_exists('qr_email_compose_for_choice')) {
    /**
     * Build the QR voting email without sending.
     *
     * @param array{
     *   subject?: string,
     *   message?: string,
     *   require_on_ballot?: bool,
     *   allow_missing_email?: bool,
     *   name?: string,
     *   email?: string,
     *   award_html?: string
     * } $opts
     * @return array<string,mixed>
     */
    function qr_email_compose_for_choice(mysqli $conn, int $choice_id, array $opts = []): array
    {
        $requireOnBallot = array_key_exists('require_on_ballot', $opts) ? (bool) $opts['require_on_ballot'] : true;
        $allowMissingEmail = !empty($opts['allow_missing_email']);
        $customSubject = trim((string) ($opts['subject'] ?? ''));
        $customMessage = trim((string) ($opts['message'] ?? ''));

        if ($choice_id <= 0) {
            return ['ok' => false, 'message' => 'Invalid business.', 'http_code' => 400];
        }

        require_once dirname(__DIR__) . '/qr_utils.php';
        require_once __DIR__ . '/qr_email_body.php';
        require_once __DIR__ . '/ballot_status.php';

        $stmt = $conn->prepare('SELECT choice_name, email, event_id, qr_sent FROM tbl_choices WHERE choice_id = ? LIMIT 1');
        if (!$stmt) {
            return ['ok' => false, 'message' => 'Could not load business.', 'http_code' => 500];
        }
        $stmt->bind_param('i', $choice_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return ['ok' => false, 'message' => 'Business record not found.', 'http_code' => 404];
        }

        $name = trim((string) ($row['choice_name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($opts['name'] ?? ''));
        }
        if ($name === '') {
            $name = 'Business';
        }

        $email = trim((string) ($row['email'] ?? ''));
        if ($email === '') {
            $email = trim((string) ($opts['email'] ?? ''));
        }

        $eventId = isset($row['event_id']) ? (int) $row['event_id'] : 0;
        $alreadySent = ((int) ($row['qr_sent'] ?? 0)) === 1;

        if ($requireOnBallot && ballot_status_flag($conn, $choice_id) === false) {
            return [
                'ok' => false,
                'message' => 'This business is still under evaluation. Confirm it for public voting before sending the QR email.',
                'http_code' => 409,
            ];
        }

        $hasEmail = $email !== '' && (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
        if (!$hasEmail && !$allowMissingEmail) {
            return [
                'ok' => false,
                'message' => 'No valid email on file for this business, so the QR and voting link were not sent.',
                'http_code' => 422,
                'name' => $name,
                'to' => $email,
            ];
        }

        try {
            $qrPath = ensure_qr_png_for_choice($choice_id, !empty($opts['force_qr']));
            $qrBasename = basename((string) $qrPath);
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'message' => 'QR code could not be generated: ' . $e->getMessage(),
                'http_code' => 422,
                'error_code' => 'QR_NOT_FOUND',
                'name' => $name,
                'to' => $email,
            ];
        }

        $finalSubject = $customSubject !== '' ? $customSubject : 'Your QR Code for Tatak Ormoc Voting';
        $voteUrl = '';
        if (function_exists('qr_vote_portal_url')) {
            try {
                $voteUrl = qr_vote_portal_url($conn);
            } catch (Throwable $e) {
                $voteUrl = '';
            }
        }
        $businessVoteUrl = '';
        try {
            $businessVoteUrl = make_qr_url_for_choice($choice_id);
        } catch (Throwable $e) {
            $businessVoteUrl = '';
        }

        $plainBody = $customMessage !== '' ? $customMessage : qr_email_default_plain_message();
        $awardHtml = (string) ($opts['award_html'] ?? '');
        $finalHtml = qr_email_build_html($name, $plainBody, $voteUrl, $finalSubject, $businessVoteUrl, $awardHtml);
        if ($qrBasename !== '') {
            $finalHtml = qr_email_append_attachment_meta($finalHtml, $choice_id, $qrBasename);
        }

        $qrUrl = '';
        if ($qrBasename !== '') {
            $mtime = is_file($qrPath) ? (int) filemtime($qrPath) : time();
            $qrUrl = 'qrcodes/' . rawurlencode($qrBasename) . '?t=' . $mtime;
        }
        $previewHtml = $qrUrl !== '' ? str_replace('cid:tocca_qr', htmlspecialchars($qrUrl, ENT_QUOTES, 'UTF-8'), $finalHtml) : $finalHtml;

        return [
            'ok' => true,
            'message' => $hasEmail ? '' : 'No valid email on file for this business.',
            'http_code' => 200,
            'to' => $email,
            'name' => $name,
            'event_id' => $eventId,
            'already_sent' => $alreadySent,
            'has_email' => $hasEmail,
            'subject' => $finalSubject,
            'message_body' => $plainBody,
            'html' => $finalHtml,
            'preview_html' => $previewHtml,
            'qr_path' => $qrPath,
            'qr_url' => $qrUrl,
            'vote_url' => $voteUrl,
            'business_vote_url' => $businessVoteUrl,
        ];
    }
}

if (!function_exists('qr_email_send_acquire_lock')) {
    /**
     * One in-flight QR email per business. A second request while SMTP is still
     * running must not send another copy. The lock is released when this
     * database connection closes if the request ends early.
     *
     * @return 'held'|'busy'|'unavailable'
     */
    function qr_email_send_acquire_lock(mysqli $conn, int $choiceId): string
    {
        if ($choiceId <= 0) {
            return 'unavailable';
        }
        $name = 'tocca_qr_email_' . $choiceId;
        $stmt = $conn->prepare('SELECT GET_LOCK(?, 0) AS got_lock');
        if (!$stmt) {
            return 'unavailable';
        }
        $stmt->bind_param('s', $name);
        $ok = $stmt->execute();
        $row = $ok ? $stmt->get_result()->fetch_assoc() : null;
        $stmt->close();
        if (!$ok || !is_array($row) || !array_key_exists('got_lock', $row) || $row['got_lock'] === null) {
            return 'unavailable';
        }
        return ((int) $row['got_lock'] === 1) ? 'held' : 'busy';
    }
}

if (!function_exists('qr_email_send_release_lock')) {
    function qr_email_send_release_lock(mysqli $conn, int $choiceId): void
    {
        if ($choiceId <= 0) {
            return;
        }
        $name = 'tocca_qr_email_' . $choiceId;
        $stmt = $conn->prepare('SELECT RELEASE_LOCK(?)');
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('qr_email_send_for_choice')) {
    /**
     * @param array{
     *   subject?: string,
     *   message?: string,
     *   require_on_ballot?: bool,
     *   skip_if_already_sent?: bool,
     *   log_failure?: bool,
     *   name?: string,
     *   email?: string,
     *   award_html?: string
     * } $opts
     * @return array{ok:bool, sent:bool, skipped:bool, message:string, http_code:int, error_code?:string}
     */
    function qr_email_send_for_choice(mysqli $conn, int $choice_id, array $opts = []): array
    {
        @ignore_user_abort(true);
        @set_time_limit(120);
        $skipIfSent = !empty($opts['skip_if_already_sent']);
        $logFailure = !empty($opts['log_failure']);

        if ($choice_id <= 0) {
            return ['ok' => false, 'sent' => false, 'skipped' => false, 'message' => 'Invalid business.', 'http_code' => 400];
        }

        if (!qr_email_send_autoload_mailer()) {
            return [
                'ok' => false,
                'sent' => false,
                'skipped' => false,
                'message' => 'Composer autoload not found. Expected vendor/autoload.php.',
                'http_code' => 500,
            ];
        }

        require_once __DIR__ . '/qr_mailer.php';

        $composed = qr_email_compose_for_choice($conn, $choice_id, $opts);
        $name = (string) ($composed['name'] ?? 'Business');
        $email = (string) ($composed['to'] ?? '');

        if (empty($composed['ok'])) {
            if ($logFailure) {
                qr_email_send_log_failure($name, $email, (string) ($composed['message'] ?? 'Could not build QR email.'));
            }
            return [
                'ok' => false,
                'sent' => false,
                'skipped' => false,
                'message' => (string) ($composed['message'] ?? 'Could not build QR email.'),
                'http_code' => (int) ($composed['http_code'] ?? 500),
                'error_code' => $composed['error_code'] ?? null,
            ];
        }

        if ($skipIfSent && !empty($composed['already_sent'])) {
            return [
                'ok' => true,
                'sent' => false,
                'skipped' => true,
                'message' => 'QR email was already sent for this business.',
                'http_code' => 200,
            ];
        }

        $eventId = (int) ($composed['event_id'] ?? 0);
        $finalSubject = (string) ($composed['subject'] ?? 'Your QR Code for Tatak Ormoc Voting');
        $finalHtml = (string) ($composed['html'] ?? '');
        $qrPath = (string) ($composed['qr_path'] ?? '');

        $lockState = qr_email_send_acquire_lock($conn, $choice_id);
        if ($lockState === 'busy') {
            return [
                'ok' => true,
                'sent' => false,
                'skipped' => true,
                'message' => 'A QR email for this business is already being sent. Refresh in a moment and do not send it again.',
                'http_code' => 200,
            ];
        }

        $mail = null;
        try {
            $mail = qr_mailer_create();
            qr_mailer_send_with_attachment(
                $mail,
                $email,
                $name,
                $finalSubject,
                $finalHtml,
                $qrPath,
                qr_email_attachment_filename($name)
            );

            if ($st = $conn->prepare('UPDATE tbl_choices SET qr_sent = 1 WHERE choice_id = ?')) {
                $st->bind_param('i', $choice_id);
                $st->execute();
                $st->close();
            }

            $log = $conn->prepare("
              INSERT INTO tbl_comm_messages
                (event_id, type, recipient_email, recipient_name, subject, body_html, status, error_text, retries, created_at, scheduled_at, sent_at)
              VALUES (?, 'qr_email', ?, ?, ?, ?, 'sent', NULL, 0, NOW(), NULL, NOW())
            ");
            if ($log) {
                $log->bind_param('issss', $eventId, $email, $name, $finalSubject, $finalHtml);
                $log->execute();
                $log->close();
            }

            if (function_exists('audit_log')) {
                audit_log($conn, 'communications', 'send_email', 'choice', $choice_id, [
                    'choice_name' => $name,
                    'recipient_email' => $email,
                    'event_id' => $eventId,
                ]);
            }

            return [
                'ok' => true,
                'sent' => true,
                'skipped' => false,
                'message' => 'QR code and voting link emailed to ' . $email . '.',
                'http_code' => 200,
            ];
        } catch (Throwable $e) {
            $mailError = (is_object($mail) && isset($mail->ErrorInfo)) ? (string) $mail->ErrorInfo : '';
            $msg = 'Mailer Error: ' . ($mailError !== '' ? $mailError : $e->getMessage());
            if ($logFailure) {
                qr_email_send_log_failure($name, $email, $msg);
            }
            error_log('qr_email_send_for_choice: ' . $e->getMessage());

            $log = $conn->prepare("
              INSERT INTO tbl_comm_messages
                (event_id, type, recipient_email, recipient_name, subject, body_html, status, error_text, retries, created_at, scheduled_at, sent_at)
              VALUES (?, 'qr_email', ?, ?, ?, ?, 'failed', ?, 1, NOW(), NULL, NULL)
            ");
            if ($log) {
                $log->bind_param('isssss', $eventId, $email, $name, $finalSubject, $finalHtml, $msg);
                $log->execute();
                $log->close();
            }

            return [
                'ok' => false,
                'sent' => false,
                'skipped' => false,
                'message' => $msg,
                'http_code' => 500,
            ];
        } finally {
            if (is_object($mail)) {
                qr_mailer_close($mail);
            }
            if ($lockState === 'held') {
                qr_email_send_release_lock($conn, $choice_id);
            }
        }
    }
}
