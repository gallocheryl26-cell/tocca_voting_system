<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/lib/otp_delivery.php';
require_once __DIR__ . '/lib/firebase_verify.php';

$provider = otp_resolve_provider();
$smsReady = otp_sms_is_configured() || tocca_config('app_debug');
$apiKey = (string) tocca_config('firebase_web_api_key');
$firebaseSmsReady = $apiKey !== '' ? firebase_sms_billing_ready($apiKey) : false;

echo json_encode([
    'provider' => $provider,
    'sms_ready' => $smsReady,
    'app_debug' => (bool) tocca_config('app_debug'),
    'can_fallback_server' => $smsReady,
    'firebase_sms_ready' => $firebaseSmsReady,
    'recaptcha_site_key' => (string) tocca_config('firebase_recaptcha_site_key'),
]);
