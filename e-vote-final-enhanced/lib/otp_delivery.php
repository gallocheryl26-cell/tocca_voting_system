<?php
declare(strict_types=1);

/**
 * OTP delivery helpers (Twilio SMS) and provider resolution.
 */
function otp_provider_config(): string
{
    $provider = strtolower(trim((string) tocca_config('otp_provider')));
    return $provider === 'server' ? 'server' : 'firebase';
}

function otp_sms_is_configured(): bool
{
    return trim((string) tocca_config('twilio_account_sid')) !== ''
        && trim((string) tocca_config('twilio_auth_token')) !== ''
        && trim((string) tocca_config('twilio_from_number')) !== '';
}

/**
 * Effective OTP mode for the voter UI.
 */
function otp_resolve_provider(): string
{
    if (otp_provider_config() === 'server') {
        return 'server';
    }
    if (otp_sms_is_configured() && trim((string) tocca_config('firebase_web_api_key')) === '') {
        return 'server';
    }
    return 'firebase';
}

function otp_mobile_to_e164(string $mobile09): string
{
    return '+63' . substr($mobile09, 1);
}

function otp_verify_recaptcha(string $token): bool
{
    $secret = trim((string) tocca_config('recaptcha_secret_key'));
    if ($secret === '') {
        return tocca_config('app_debug') === true;
    }
    if ($token === '') {
        return false;
    }

    $post = http_build_query([
        'secret' => $secret,
        'response' => $token,
    ]);

    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-type: application/x-www-form-urlencoded\r\n",
            'content' => $post,
            'timeout' => 10,
        ],
    ]);

    $raw = @file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $ctx);
    if ($raw === false) {
        error_log('otp_delivery: reCAPTCHA siteverify request failed');
        return false;
    }

    $data = json_decode($raw, true);
    return is_array($data) && !empty($data['success']);
}

function otp_send_sms(string $mobile09, string $otpCode): void
{
    if (!otp_sms_is_configured()) {
        if (tocca_config('app_debug')) {
            error_log("OTP for {$mobile09} (debug, SMS not configured): {$otpCode}");
            return;
        }
        throw new RuntimeException('SMS delivery is not configured. Contact the administrator.');
    }

    $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('Composer autoload not found. Run composer install.');
    }
    require_once $autoload;

    $sid = (string) tocca_config('twilio_account_sid');
    $token = (string) tocca_config('twilio_auth_token');
    $from = (string) tocca_config('twilio_from_number');
    $to = otp_mobile_to_e164($mobile09);

    $eventName = 'TOCCA';
    $message = "Your {$eventName} verification code is {$otpCode}. Valid for "
        . (int) tocca_config('otp_ttl_seconds') / 60
        . ' minutes. Do not share this code.';

    $client = new Twilio\Rest\Client($sid, $token);
    $client->messages->create($to, [
        'from' => $from,
        'body' => $message,
    ]);
}

function otp_rate_limit_seconds(): int
{
    return max(30, (int) tocca_config('otp_resend_seconds'));
}

function otp_check_rate_limit(): ?string
{
    $wait = otp_rate_limit_seconds();
    $last = (int) ($_SESSION['otp_sent_at'] ?? 0);
    if ($last > 0 && (time() - $last) < $wait) {
        $remaining = $wait - (time() - $last);
        return "Please wait {$remaining} seconds before requesting another OTP.";
    }
    return null;
}

function otp_mark_sent(): void
{
    $_SESSION['otp_sent_at'] = time();
}
