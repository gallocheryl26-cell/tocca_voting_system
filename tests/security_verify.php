<?php
/**
 * CLI security smoke tests. Run: php tests/security_verify.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

$base = getenv('TOCCA_BASE_URL') ?: 'http://127.0.0.1/TOCCA_RECENT_NEWEST_2';
$cookieJar = sys_get_temp_dir() . '/tocca_verify_cookies_' . getmypid() . '.txt';

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

$r = http_request('GET', $base . '/tocca_admin/voter.php');
$tests['admin_voter_unauth'] = $r['code'] === 401 && str_contains($r['body'], 'Unauthorized');

$r = http_request('POST', $base . '/e-vote-final-enhanced/submit_vote.php', ['voters_id' => 1, 'answers' => []]);
$tests['vote_unauth'] = $r['code'] === 401 && str_contains($r['body'], 'Authentication');

$testMobile = '0917' . str_pad((string)(time() % 10000000), 7, '0', STR_PAD_LEFT);
$r = http_request('POST', $base . '/e-vote-final-enhanced/register_new_voter.php', ['mobile_number' => $testMobile], $cookieJar);
$reg = json_decode($r['body'], true);
$googleMode = strtolower(trim((string) tocca_config('voter_auth_mode'))) === 'google_with_legacy';
if ($googleMode) {
    $tests['sms_registration_disabled'] = $r['code'] === 410
        && is_array($reg)
        && ($reg['status'] ?? '') === 'error';

    $r = http_request('POST', $base . '/e-vote-final-enhanced/google_voter_login.php', ['id_token' => 'not-a-valid-token'], $cookieJar);
    $googleBody = json_decode($r['body'], true);
    $tests['invalid_google_token_rejected'] = in_array($r['code'], [401, 403], true)
        && is_array($googleBody)
        && ($googleBody['status'] ?? '') === 'error';
} else {
    $tests['register_sets_session'] = $r['code'] === 200
        && is_array($reg)
        && ($reg['status'] ?? '') === 'success'
        && !empty($reg['voter_id']);

    $r = http_request('POST', $base . '/e-vote-final-enhanced/submit_vote.php', [
        'voters_id' => $reg['voter_id'] ?? 0,
        'answers' => [],
        'finalized_votes' => [],
    ], $cookieJar);
    $voteBody = json_decode($r['body'], true);
    $tests['vote_with_session'] = $r['code'] === 200
        && is_array($voteBody)
        && ($voteBody['status'] ?? '') === 'success';
}

$r = http_request('GET', $base . '/e-vote-final-enhanced/category.php');
$tests['category_gate_unauth'] = $r['code'] === 302 || str_contains($r['body'], 'index.php')
    || (str_contains($r['body'], 'Location') === false && $r['code'] === 200 && !str_contains($r['body'], 'categoryList'));

$r = http_request('GET', $base . '/e-vote-final-enhanced/category.php', null, $cookieJar);
$tests['category_gate_with_session'] = $r['code'] === 200 && str_contains($r['body'], 'categoryList');

$passed = 0;
foreach ($tests as $name => $ok) {
    echo ($ok ? '[PASS]' : '[FAIL]') . " $name";
    if (!$ok) {
        echo ' (HTTP ' . ($name === 'vote_with_session' ? $r['code'] : '') . ')';
        if ($name === 'vote_with_session') {
            echo ' body=' . substr($r['body'], 0, 200);
        }
    }
    echo "\n";
    if ($ok) {
        $passed++;
    }
}

@unlink($cookieJar);

echo "\n$passed/" . count($tests) . " passed\n";
exit($passed === count($tests) ? 0 : 1);
