<?php
declare(strict_types=1);

$base = getenv('TOCCA_BASE_URL') ?: 'http://127.0.0.1/TOCCA_RECENT_NEWEST_2';
$cookieJar = sys_get_temp_dir() . '/tocca_admin_verify_' . getmypid() . '.txt';

function http_request(string $method, string $url, ?array $payload = null, ?string $cookieJar = null): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($cookieJar) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    }
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $body];
}

@unlink($cookieJar);

$tests = [];
$details = [];

$r = http_request('GET', $base . '/tocca_admin/result.php?event_id=1');
$tests['result_unauth'] = $r['code'] === 401;
$details['result_unauth'] = ['code' => $r['code'], 'body' => substr($r['body'], 0, 120)];

$rLogin = http_request('POST', $base . '/tocca_admin/index.php', [
    'username' => getenv('TOCCA_ADMIN_USER') ?: 'admin',
    'password' => getenv('TOCCA_ADMIN_PASS') ?: 'admin',
], $cookieJar);
$login = json_decode($rLogin['body'], true);
$tests['admin_login'] = ($login['success'] ?? false) === true;
$details['admin_login'] = ['code' => $rLogin['code'], 'body' => substr($rLogin['body'], 0, 120)];

if ($tests['admin_login']) {
    $r = http_request('GET', $base . '/tocca_admin/result.php?event_id=1', null, $cookieJar);
    $data = json_decode($r['body'], true);
    $tests['result_with_session'] = $r['code'] === 200
        && is_array($data)
        && ($data['status'] ?? '') === 'success';
    $details['result_with_session'] = ['code' => $r['code'], 'status' => $data['status'] ?? null];

    $r = http_request('GET', $base . '/tocca_admin/voter.php', null, $cookieJar);
    $voters = json_decode($r['body'], true);
    $tests['voter_with_session'] = $r['code'] === 200
        && is_array($voters)
        && ($voters['status'] ?? '') === 'success';
    $details['voter_with_session'] = ['code' => $r['code'], 'status' => $voters['status'] ?? null];
} else {
    $tests['result_with_session'] = null;
    $tests['voter_with_session'] = null;
    $details['note'] = 'Skipped authenticated API tests — set TOCCA_ADMIN_USER and TOCCA_ADMIN_PASS env vars with valid credentials.';
}

$required = ['result_unauth'];
$passed = 0;
$total = 0;
foreach ($tests as $name => $ok) {
    if ($ok === null) {
        echo "[SKIP] $name (no admin credentials)\n";
        continue;
    }
    $total++;
    echo ($ok ? '[PASS]' : '[FAIL]') . " $name\n";
    if (!$ok && isset($details[$name])) {
        echo '  ' . json_encode($details[$name]) . "\n";
    }
    if ($ok) {
        $passed++;
    }
}

@unlink($cookieJar);

echo "\n$passed/$total required passed (result_unauth must pass)\n";
exit($tests['result_unauth'] ? 0 : 1);
