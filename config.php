<?php
/**
 * Shared application configuration.
 * Override values in config.local.php (not committed).
 */
declare(strict_types=1);

$toccaConfig = [
    'db_host'     => 'localhost',
    'db_name'     => 'tocca_db',
    'db_user'     => 'root',
    'db_password' => '',
    'timezone'    => 'Asia/Manila',
    'login_lock_seconds' => 300,
    'login_max_attempts' => 3,
    /** Existing voter 4-digit access code: failures before temporary lockout. */
    'voter_access_max_attempts' => 5,
    /** Lock duration (seconds) after max failed access code attempts for one mobile. */
    'voter_access_lock_seconds' => 300,
    'otp_ttl_seconds'    => 300,
    'otp_resend_seconds' => 60,
    'app_debug'          => false,
    /**
     * OTP provider: "firebase" (default) or "server" (PHP + Twilio SMS).
     * Firebase Phone Auth requires Blaze billing on the Firebase project.
     */
    'otp_provider'       => 'firebase',
    /** Firebase Web API key (same project as e-vote index.php). Required for server-side token verification. */
    'firebase_web_api_key' => '',
    /** Twilio SMS (used when otp_provider is "server", or as automatic fallback). */
    'twilio_account_sid' => '',
    'twilio_auth_token'  => '',
    'twilio_from_number' => '',
    /**
     * Optional reCAPTCHA v2 site key. Leave empty so Firebase uses the key provisioned
     * for your project (recommended). Only set if Firebase Console gives a specific web key.
     */
    'firebase_recaptcha_site_key' => '',
    /** reCAPTCHA v2 secret (pairs with the site key; used for server OTP mode). */
    'recaptcha_secret_key' => '',
    'admin_session_name' => 'TOCCA_ADMIN',
    'voter_session_name' => 'TOCCA_VOTER',
];

$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) {
    $overrides = require $localConfig;
    if (is_array($overrides)) {
        $toccaConfig = array_merge($toccaConfig, $overrides);
    }
}

if (!function_exists('tocca_config')) {
    function tocca_config(?string $key = null) {
        global $toccaConfig;
        if ($key === null) {
            return $toccaConfig;
        }
        return $toccaConfig[$key] ?? null;
    }
}

date_default_timezone_set((string) tocca_config('timezone'));
