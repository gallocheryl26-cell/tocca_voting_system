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
    'db_password' => 'Falaman@426153123456',
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
     * Public voter authentication mode.
     *
     * - google_with_legacy: Google for new voters; existing mobile + access-code login remains.
     * - phone: legacy Firebase/server SMS OTP flow (rollback only).
     */
    'voter_auth_mode'    => 'google_with_legacy',
    /**
     * OTP provider: "firebase" (default) or "server" (PHP + Twilio SMS).
     * Firebase Phone Auth requires Blaze billing on the Firebase project.
     */
    'otp_provider'       => 'firebase',
    /** Firebase Web API key (same project as e-vote index.php). Required for server-side token verification. */
    // Firebase Web API keys are public project identifiers; restrict this key in Google Cloud.
    'firebase_web_api_key' => 'AIzaSyBWSN9I0gH2YrF-y53hgRiwzLKkMcGOKCg',
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
    /**
     * Optional public site root for short links / QRs (no trailing slash).
     * Example: 'https://tatakormocawards.com'
     * If non-empty, this file value wins over Public Share Links (tbl_config).
     * Leave empty so admins can set the live domain in Customizations → Public Share Links.
     * Precedence and full map: docs/HOSTING_PUBLIC_URLS.md
     * In-admin map: tocca_admin/public_url_config.php
     */
    'public_site_url' => '',
    /**
     * Temporary public voting pause (landing + QR entry). Set false to resume.
     */
    'voting_on_hold' => true,
    /**
     * SMTP for registration status, receipt, and QR emails.
     * Leave empty here. Set real values in config.local.php (not committed).
     * Production should use the Tatak Ormoc Gmail (or org mailbox), not a personal account.
     */
    'smtp_host'       => 'smtp.gmail.com',
    'smtp_port'       => 587,
    'smtp_secure'     => 'tls',
    'smtp_user'       => '',
    'smtp_password'   => '',
    'smtp_from_email' => '',
    'smtp_from_name'  => 'Tatak Ormoc',
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

if (!function_exists('tocca_smtp_config')) {
    /**
     * SMTP settings for PHPMailer. Secrets belong in config.local.php.
     *
     * @return array{host:string,port:int,secure:string,user:string,pass:string,from_email:string,from_name:string}
     */
    function tocca_smtp_config(): array
    {
        $user = trim((string) (tocca_config('smtp_user') ?? ''));
        $from = trim((string) (tocca_config('smtp_from_email') ?? ''));
        if ($from === '') {
            $from = $user;
        }
        $secure = strtolower(trim((string) (tocca_config('smtp_secure') ?? 'tls')));
        if ($secure === '') {
            $secure = 'tls';
        }
        $port = (int) (tocca_config('smtp_port') ?? 587);
        if ($port <= 0) {
            $port = 587;
        }

        return [
            'host'       => (string) (tocca_config('smtp_host') ?: 'smtp.gmail.com'),
            'port'       => $port,
            'secure'     => $secure,
            'user'       => $user,
            'pass'       => (string) (tocca_config('smtp_password') ?? ''),
            'from_email' => $from,
            'from_name'  => (string) (tocca_config('smtp_from_name') ?: 'Tatak Ormoc'),
        ];
    }
}

date_default_timezone_set((string) tocca_config('timezone'));
