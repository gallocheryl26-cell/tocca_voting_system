<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/lib/otp_delivery.php';

$provider = otp_resolve_provider();
$smsReady = otp_sms_is_configured() || tocca_config('app_debug');

echo json_encode([
    'provider' => $provider,
    'sms_ready' => $smsReady,
    'app_debug' => (bool) tocca_config('app_debug'),
    'can_fallback_server' => $smsReady,
    // Do not probe Firebase's sendVerificationCode endpoint here. This endpoint
    // is called during normal page setup, and a probe consumes verification
    // request capacity even when no user asked for an OTP.
    'firebase_sms_ready' => null,
    'recaptcha_site_key' => (string) tocca_config('firebase_recaptcha_site_key'),
]);
