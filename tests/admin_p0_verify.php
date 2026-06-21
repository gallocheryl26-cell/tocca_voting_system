<?php
declare(strict_types=1);

/**
 * Verifies P0 admin endpoints return 401 without a session.
 */
$base = getenv('TOCCA_BASE_URL') ?: 'http://127.0.0.1/TOCCA_RECENT_NEWEST_2';

$endpoints = [
    'download_results.php' => 'GET',
    'get_notifications.php' => 'GET',
    'register_api.php' => 'POST',
    'delete_notification.php' => 'POST',
    'archive.php' => 'POST',
    'nomination.php' => 'GET',
    'send_email.php' => 'POST',
    'admin_notification.php' => 'GET',
    'notifications_stream.php' => 'GET',
];

function http_simple(string $method, string $url, ?string $body = null): int
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

$passed = 0;
$total = count($endpoints);

foreach ($endpoints as $path => $method) {
    $url = rtrim($base, '/') . '/tocca_admin/' . $path;
    $body = null;
    if ($method === 'POST') {
        $body = $path === 'register_api.php'
            ? json_encode(['username' => 'x', 'firstName' => 'a', 'lastName' => 'b', 'email' => 'x@test.com', 'password' => 'secret'])
            : ($path === 'delete_notification.php' ? '{}' : '{"action":"archive_event","event_id":1}');
    }
    if ($path === 'nomination.php') {
        $url .= '?action=list';
    }
    if ($path === 'admin_notification.php') {
        $url .= '?action=list';
    }
    $code = http_simple($method, $url, $body);
    // Page guards may redirect (302) instead of JSON 401
    $ok = in_array($code, [401, 302, 303], true);
    echo ($ok ? '[PASS]' : '[FAIL]') . " $path ($code)\n";
    if ($ok) {
        $passed++;
    }
}

echo "\n$passed/$total passed\n";
exit($passed === $total ? 0 : 1);
