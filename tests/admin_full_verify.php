<?php
declare(strict_types=1);

/**
 * Smoke-test TOCCA admin pages and JSON APIs with an authenticated session.
 */
$base = rtrim(getenv('TOCCA_BASE_URL') ?: 'http://127.0.0.1/TOCCA_RECENT_NEWEST_2', '/');
$user = getenv('TOCCA_ADMIN_USER') ?: 'testuser';
$pass = getenv('TOCCA_ADMIN_PASS') ?: 'Testuser@2025';
$cookieJar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tocca_admin_full_' . getmypid() . '.txt';

function http_req(string $method, string $url, ?array $payload = null, ?string $cookieJar = null, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($cookieJar) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    }
    $hdrs = $headers;
    if ($payload !== null) {
        $hdrs[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    if ($hdrs) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrs);
    }
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [
        'code' => $code,
        'headers' => substr($raw, 0, $headerSize),
        'body' => substr($raw, $headerSize),
    ];
}

function has_fatal(string $body): bool
{
    return (bool) preg_match('/\b(Fatal error|Parse error|Uncaught mysqli|Uncaught Error)\b/i', $body);
}

function print_row(string $status, string $name, string $detail = ''): void
{
    echo sprintf("[%s] %-42s %s\n", $status, $name, $detail);
}

@unlink($cookieJar);

$passed = 0;
$failed = 0;
$warned = 0;

$login = http_req('POST', $base . '/tocca_admin/index.php', [
    'username' => $user,
    'password' => $pass,
], $cookieJar);
$loginData = json_decode($login['body'], true);
if (($loginData['success'] ?? false) !== true) {
    print_row('FAIL', 'Login', $login['body']);
    exit(1);
}
print_row('PASS', 'Login', "HTTP {$login['code']}");
$passed++;

$pages = [
    'Dashboard'              => 'dashboard.php',
    'Registration'            => 'nominations.php',
    'Events'                 => 'events.php',
    'Categories'             => 'categories.php',
    'Questions (Awards)'     => 'questions.php',
    'Nature of Business'     => 'establishment_types.php',
    'Businesses'             => 'choices.php',
    'Registration Form'        => 'nomination_fields.php',
    'Awards Validation'      => 'award_validation_log.php',
    'Registration Emails'      => 'communications.php',
    'QR Emails'              => 'communications_qr.php',
    'Registration Feedbacks'   => 'nomination_feedbacks.php',
    'Voting Feedbacks'       => 'voters_feedbacks.php',
    'Registration Reports'     => 'nomination_reports.php',
    'Voters Report'          => 'voters.php',
    'Results'                => 'results.php',
    'System Utilities'       => 'system_utilities.php',
    'Archives'               => 'archives.php',
    'Audit Logs'             => 'audit_logs.php',
    'QR Frame Settings (legacy redirect)' => 'qr_frame_settings.php',
    'Admin Settings'         => 'admin_settings.php',
    'Voter Portal'           => 'voter_portal_copy.php',
    'Register (admin only)'  => 'register.php',
];

foreach ($pages as $label => $path) {
    $r = http_req('GET', $base . '/tocca_admin/' . $path, null, $cookieJar);
    $legacyQrRedirect = $path === 'qr_frame_settings.php'
        && $r['code'] === 302
        && str_contains($r['headers'], 'admin_settings.php');
    if ($legacyQrRedirect) {
        print_row('PASS', "Page: $label", 'HTTP 302 → admin_settings.php#qr-frame-settings');
        $passed++;
    } elseif ($r['code'] === 200 && !has_fatal($r['body'])) {
        print_row('PASS', "Page: $label", "HTTP 200");
        $passed++;
    } elseif ($r['code'] === 302 && str_contains($r['headers'], 'index.php')) {
        print_row('FAIL', "Page: $label", 'Redirected to login');
        $failed++;
    } elseif (has_fatal($r['body'])) {
        print_row('FAIL', "Page: $label", 'PHP fatal error');
        $failed++;
    } else {
        print_row('WARN', "Page: $label", "HTTP {$r['code']}");
        $warned++;
    }
}

$apis = [
    'Events API load_all' => ['POST', 'event.php', ['action' => 'load_all']],
    'Categories API'      => ['GET',  'get_categories.php', null],
    'Choices loadAll'     => ['POST', 'choice.php', ['action' => 'loadAllChoices']],
    'Registration list'    => ['GET',  'nomination.php?action=list&event_id=7', null],
    'Notifications'       => ['GET',  'get_notifications.php', null],
    'Results API'         => ['GET',  'result.php?event_id=1', null],
    'Voters API'          => ['GET',  'voter.php', null],
    'Top votes'           => ['GET',  'get_top_votes.php', null],
    'Registration metrics' => ['GET',  'nominations_metrics.php', null],
    'Archives list'       => ['GET',  'get_archives.php', null],
    'Comm logs'           => ['GET',  'comm_logs.php?nomination_id=67', null],
    'Admin notifications' => ['GET',  'admin_notification.php?action=list', null],
    'QR frame preview'    => ['GET',  'qr_frame_preview.php?choice_id=64', null],
];

foreach ($apis as $label => [$method, $path, $payload]) {
    $url = $base . '/tocca_admin/' . $path;
    $r = http_req($method, $url, $payload, $cookieJar);
    $json = json_decode($r['body'], true);
    $fatal = has_fatal($r['body']);

    if ($fatal) {
        print_row('FAIL', "API: $label", 'PHP fatal error');
        $failed++;
        continue;
    }

    if ($label === 'QR frame preview') {
        $ok = $r['code'] === 200 && (str_starts_with($r['body'], "\x89PNG") || str_starts_with(trim($r['body']), '{'));
        if ($ok) {
            print_row('PASS', "API: $label", str_starts_with($r['body'], "\x89PNG") ? 'PNG image' : 'JSON fallback');
            $passed++;
        } else {
            print_row('WARN', "API: $label", "HTTP {$r['code']}");
            $warned++;
        }
        continue;
    }

    if ($r['code'] === 401) {
        print_row('FAIL', "API: $label", '401 unauthorized');
        $failed++;
        continue;
    }

    if ($r['code'] >= 200 && $r['code'] < 300 && is_array($json)) {
        print_row('PASS', "API: $label", 'JSON OK');
        $passed++;
    } elseif ($r['code'] >= 200 && $r['code'] < 300) {
        print_row('WARN', "API: $label", 'Non-JSON 2xx');
        $warned++;
    } else {
        print_row('FAIL', "API: $label", "HTTP {$r['code']}");
        $failed++;
    }
}

$unauth = http_req('GET', $base . '/tocca_admin/dashboard.php');
if ($unauth['code'] === 302) {
    print_row('PASS', 'Auth guard (no session)', 'Redirects to login');
    $passed++;
} else {
    print_row('FAIL', 'Auth guard (no session)', "HTTP {$unauth['code']}");
    $failed++;
}

@unlink($cookieJar);

echo "\nSummary: {$passed} passed, {$failed} failed, {$warned} warnings\n";
exit($failed > 0 ? 1 : 0);
