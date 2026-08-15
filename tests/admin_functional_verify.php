<?php
declare(strict_types=1);

/**
 * Functional smoke test: exercises admin button/API logic (CRUD + key actions).
 * Creates temporary records where safe, then cleans up.
 */
$base = rtrim(getenv('TOCCA_BASE_URL') ?: 'http://127.0.0.1/TOCCA_RECENT_NEWEST_2', '/');
$user = getenv('TOCCA_ADMIN_USER') ?: 'testuser';
$pass = getenv('TOCCA_ADMIN_PASS') ?: 'Testuser@2025';
$cookieJar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tocca_admin_func_' . getmypid() . '.txt';

$passed = 0;
$failed = 0;
$warnings = 0;
$created = ['event_id' => null, 'category_id' => null, 'question_id' => null, 'type_id' => null, 'choice_id' => null];

function req(string $method, string $url, ?array $payload = null, ?string $cookieJar = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 45,
    ]);
    if ($cookieJar) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    }
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'json' => json_decode($body, true)];
}

function pass(string $name, string $detail = ''): void
{
    global $passed;
    $passed++;
    echo "[PASS] $name" . ($detail !== '' ? " — $detail" : '') . "\n";
}

function fail(string $name, string $detail = ''): void
{
    global $failed;
    $failed++;
    echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n";
}

function warn(string $name, string $detail = ''): void
{
    global $warnings;
    $warnings++;
    echo "[WARN] $name" . ($detail !== '' ? " — $detail" : '') . "\n";
}

function api(string $path, array $payload = [], string $method = 'POST'): array
{
    global $base, $cookieJar;
    return req($method, $base . '/tocca_admin/' . ltrim($path, '/'), $payload, $cookieJar);
}

function expectSuccess(array $r, string $name, ?callable $check = null): bool
{
    $j = $r['json'];
    if (!is_array($j)) {
        fail($name, 'Non-JSON HTTP ' . $r['code'] . ' ' . substr($r['body'], 0, 120));
        return false;
    }
    $ok = ($j['status'] ?? '') === 'success'
        || ($j['status'] ?? '') === 'ok'
        || ($j['success'] ?? false) === true;
    if (!$ok) {
        fail($name, ($j['message'] ?? $j['status'] ?? 'error') . ' HTTP ' . $r['code']);
        return false;
    }
    if ($check && !$check($j)) {
        fail($name, 'Unexpected payload');
        return false;
    }
    pass($name);
    return true;
}

@unlink($cookieJar);

echo "=== TOCCA Admin Functional Verify ===\n\n";

$login = req('POST', $base . '/tocca_admin/index.php', ['username' => $user, 'password' => $pass], $cookieJar);
if (($login['json']['success'] ?? false) !== true) {
    fail('Login', $login['body']);
    exit(1);
}
pass('Login');

// --- Events ---
$r = api('event.php', ['action' => 'load_all']);
if (expectSuccess($r, 'Events: load_all', fn($j) => isset($j['events']) && is_array($j['events']))) {
    $activeEvent = null;
    foreach ($r['json']['events'] as $ev) {
        if ((int) ($ev['is_active'] ?? 0) === 1) {
            $activeEvent = (int) $ev['event_id'];
            break;
        }
    }
    if ($activeEvent) {
        pass('Events: active event found', "event_id=$activeEvent");
    } else {
        warn('Events: active event found', 'none active');
        $activeEvent = (int) ($r['json']['events'][0]['event_id'] ?? 0);
    }

    $testName = 'AUTO_TEST_' . date('Ymd_His');
    $create = api('event.php', [
        'action' => 'add_event',
        'event_name' => $testName,
        'description' => 'Automated functional test event',
        'is_active' => 0,
        'nomination_start' => '',
        'nomination_end' => '',
        'voting_start' => '',
        'voting_end' => '',
    ]);
    if (expectSuccess($create, 'Events: create (inactive)')) {
        $reload = api('event.php', ['action' => 'load_all']);
        $newId = null;
        foreach ($reload['json']['events'] ?? [] as $ev) {
            if (($ev['event_name'] ?? '') === $testName) {
                $newId = (int) $ev['event_id'];
                break;
            }
        }
        if ($newId) {
            $created['event_id'] = $newId;
            $upd = api('event.php', [
                'action' => 'update_event',
                'event_id' => $newId,
                'event_name' => $testName . '_EDIT',
                'description' => 'Updated',
                'is_active' => 0,
                'nomination_start' => '',
                'nomination_end' => '',
                'voting_start' => '',
                'voting_end' => '',
            ]);
            expectSuccess($upd, 'Events: update');
            $arc = api('event.php', ['action' => 'archive', 'event_id' => $newId]);
            expectSuccess($arc, 'Events: archive');
        } else {
            fail('Events: create verify', 'Created event not found in list');
        }
    }
}

$r = api('event.php', ['action' => 'loadDropdown']);
if (is_array($r['json']) && count($r['json']) > 0) {
    pass('Events: loadDropdown');
} else {
    fail('Events: loadDropdown', substr($r['body'], 0, 120));
}

// --- Categories ---
$r = api('category.php', ['loadOnly' => true]);
expectSuccess($r, 'Categories: load', fn($j) => isset($j['data']) && is_array($j['data']));

$activeEventId = 7;
$catName = 'AUTO_CAT_' . time();
$catCreate = api('category.php', ['action' => 'create', 'category_name' => $catName, 'event_id' => $activeEventId]);
if (expectSuccess($catCreate, 'Categories: create')) {
    $r = api('category.php', ['loadOnly' => true]);
    $catId = null;
    foreach ($r['json']['data'] ?? [] as $row) {
        if (($row['category_name'] ?? '') === $catName) {
            $catId = (int) $row['category_id'];
            break;
        }
    }
    if ($catId) {
        $created['category_id'] = $catId;
        expectSuccess(api('category.php', ['action' => 'getSingle', 'category_id' => $catId]), 'Categories: getSingle');
        expectSuccess(api('category.php', ['action' => 'update', 'category_id' => $catId, 'category_name' => $catName . '_E', 'event_id' => $activeEventId]), 'Categories: update');
        expectSuccess(api('category.php', ['action' => 'toggleStatus', 'category_id' => $catId, 'status' => 0]), 'Categories: deactivate');
        expectSuccess(api('category.php', ['action' => 'toggleStatus', 'category_id' => $catId, 'status' => 1]), 'Categories: activate');
    } else {
        fail('Categories: create verify', 'not in list');
    }
}

// --- Questions ---
expectSuccess(api('question.php', ['action' => 'loadAll']), 'Questions: loadAll');
expectSuccess(api('question.php', ['action' => 'loadCategories']), 'Questions: loadCategories');

if ($created['category_id']) {
    $qName = 'AUTO_Q_' . time();
    $qCreate = api('question.php', ['action' => 'create', 'question_name' => $qName, 'category_id' => $created['category_id'], 'choice_type' => 'single']);
    if (expectSuccess($qCreate, 'Questions: create')) {
        $r = api('question.php', ['action' => 'loadAll']);
        $qId = null;
        foreach ($r['json']['data'] ?? [] as $row) {
            if (($row['question_name'] ?? '') === $qName) {
                $qId = (int) $row['question_id'];
                break;
            }
        }
        if ($qId) {
            $created['question_id'] = $qId;
            expectSuccess(api('question.php', ['action' => 'update', 'question_id' => $qId, 'question_name' => $qName . '_E', 'category_id' => $created['category_id'], 'choice_type' => 'single']), 'Questions: update');
        } else {
            fail('Questions: create verify', 'not in list');
        }
    }
}

// --- Establishment types ---
expectSuccess(api('establishment_type.php', ['action' => 'list']), 'Est. Types: list');
$awardForType = $created['question_id'] ?? 63;
$typeName = 'AUTO_TYPE_' . time();
$typeCreate = api('establishment_type.php', [
    'action' => 'create',
    'type_name' => $typeName,
    'awards' => [$awardForType],
]);
if (expectSuccess($typeCreate, 'Est. Types: create')) {
    $r = api('establishment_type.php', ['action' => 'list']);
    $typeId = null;
    foreach ($r['json']['data'] ?? [] as $row) {
        if (($row['type_name'] ?? '') === $typeName || ($row['name'] ?? '') === $typeName) {
            $typeId = (int) ($row['type_id'] ?? $row['id'] ?? 0);
            break;
        }
    }
    if ($typeId) {
        $created['type_id'] = $typeId;
        expectSuccess(api('establishment_type.php', ['action' => 'get', 'type_id' => $typeId]), 'Est. Types: get');
        expectSuccess(api('establishment_type.php', ['action' => 'update', 'type_id' => $typeId, 'type_name' => $typeName . '_E', 'awards' => [$awardForType]]), 'Est. Types: update');
    } else {
        fail('Est. Types: create verify', 'not in list');
    }
}

// --- Choices / Establishments ---
expectSuccess(api('choice.php', ['action' => 'loadAllChoices']), 'Choices: loadAllChoices');
expectSuccess(api('choice.php', ['action' => 'loadEstablishmentTypes']), 'Choices: loadEstablishmentTypes');

if ($created['question_id']) {
    $choiceName = 'AUTO_EST_' . time();
    $payload = [
        'action' => 'create',
        'choice_name' => $choiceName,
        'email' => 'auto_test_' . time() . '@example.com',
        'question_ids' => [$created['question_id']],
        'status' => 1,
    ];
    if ($created['type_id']) {
        $payload['establishment_type_id'] = $created['type_id'];
    } else {
        $payload['establishment_type_id'] = 4;
    }
    $cCreate = api('choice.php', $payload);
    if (expectSuccess($cCreate, 'Choices: create')) {
        $r = api('choice.php', ['action' => 'loadAllChoices']);
        $choiceId = null;
        foreach ($r['json']['data'] ?? [] as $row) {
            if (($row['choice_name'] ?? '') === $choiceName) {
                $choiceId = (int) $row['choice_id'];
                break;
            }
        }
        if ($choiceId) {
            $created['choice_id'] = $choiceId;
            expectSuccess(api('choice.php', ['action' => 'getLinkedQuestions', 'choice_id' => $choiceId]), 'Choices: getLinkedQuestions');
            expectSuccess(api('choice.php', ['action' => 'toggleStatus', 'choice_id' => $choiceId, 'status' => 0]), 'Choices: deactivate');
            expectSuccess(api('choice.php', ['action' => 'toggleStatus', 'choice_id' => $choiceId, 'status' => 1]), 'Choices: activate');
            $qr = req('GET', $base . '/tocca_admin/qr_frame_preview.php?choice_id=' . $choiceId, null, $cookieJar);
            if ($qr['code'] === 200 && is_array($qr['json']) && ($qr['json']['status'] ?? '') === 'success') {
                pass('Choices: QR generate preview');
            } else {
                warn('Choices: QR generate preview', substr($qr['body'], 0, 100));
            }
        } else {
            fail('Choices: create verify', 'not in list');
        }
    }
}

// --- Registration ---
expectSuccess(req('GET', $base . '/tocca_admin/nomination.php?action=list&event_id=' . $activeEventId, null, $cookieJar), 'Registration: list');

// --- Communications ---
expectSuccess(req('GET', $base . '/tocca_admin/communication.php?action=list', null, $cookieJar), 'Communications: list');
expectSuccess(req('GET', $base . '/tocca_admin/communication.php?action=qr_only_stats', null, $cookieJar), 'QR Emails: stats');
expectSuccess(req('GET', $base . '/tocca_admin/communication.php?action=qr_only_list', null, $cookieJar), 'QR Emails: list');
expectSuccess(req('GET', $base . '/tocca_admin/comm_search.php?limit=5', null, $cookieJar), 'Comm search');
expectSuccess(req('GET', $base . '/tocca_admin/comm_search_qr.php?limit=5', null, $cookieJar), 'Comm search QR');

// --- Reports ---
expectSuccess(req('GET', $base . '/tocca_admin/result.php?event_id=' . $activeEventId, null, $cookieJar), 'Results API');
expectSuccess(req('GET', $base . '/tocca_admin/voter.php', null, $cookieJar), 'Voters API');
expectSuccess(req('GET', $base . '/tocca_admin/get_top_votes.php?event_id=' . $activeEventId, null, $cookieJar), 'Top votes');
expectSuccess(req('GET', $base . '/tocca_admin/nominations_metrics.php', null, $cookieJar), 'Registration metrics');
expectSuccess(req('GET', $base . '/tocca_admin/nomination_report.php?action=events', null, $cookieJar), 'Nom report: events');

// --- Registration form fields (legacy JSON API) ---
$r = req('GET', $base . '/tocca_admin/nomination_field.php?action=list', null, $cookieJar);
if (is_array($r['json']) && isset($r['json']['data']) && is_array($r['json']['data'])) {
    pass('Registration fields: list');
} else {
    fail('Registration fields: list', substr($r['body'], 0, 120));
}

// --- Archives ---
$r = api('get_archives.php', ['action' => 'load_archive', 'type' => 'categories', 'event_id' => $activeEventId]);
if (($r['json']['status'] ?? '') === 'success') {
    pass('Archives: load categories');
} else {
    fail('Archives: load categories', ($r['json']['message'] ?? 'error'));
}

// --- Notifications ---
expectSuccess(req('GET', $base . '/tocca_admin/get_notifications.php?limit=5', null, $cookieJar), 'Notifications: list');
expectSuccess(req('GET', $base . '/tocca_admin/admin_notification.php?action=list', null, $cookieJar), 'Admin notifications');

// --- Audit logs (DataTables format) ---
$r = req('GET', $base . '/tocca_admin/admin_audit_logs.php?draw=1&start=0&length=5', null, $cookieJar);
if (is_array($r['json']) && isset($r['json']['data']) && is_array($r['json']['data'])) {
    pass('Audit logs API');
} else {
    fail('Audit logs API', substr($r['body'], 0, 120));
}

// --- Exports ---
$exp = req('GET', $base . '/tocca_admin/exports.php?type=categories&format=excel', null, $cookieJar);
if ($exp['code'] === 200 && str_contains($exp['body'], 'PK')) {
    pass('Exports: categories excel');
} else {
    warn('Exports: categories excel', 'HTTP ' . $exp['code']);
}

// --- Dashboard schedule check ---
expectSuccess(req('GET', $base . '/tocca_admin/save_event_schedule.php?check_status=1', null, $cookieJar), 'Dashboard: schedule status');

// --- Cleanup (reverse order) ---
echo "\n--- Cleanup ---\n";
if ($created['choice_id']) {
    expectSuccess(api('choice.php', ['action' => 'delete', 'ids' => [$created['choice_id']]]), 'Cleanup: delete choice');
}
if ($created['question_id']) {
    expectSuccess(api('question.php', ['action' => 'delete', 'ids' => [$created['question_id']]]), 'Cleanup: delete question');
}
if ($created['type_id']) {
    expectSuccess(api('establishment_type.php', ['action' => 'delete', 'type_id' => $created['type_id']]), 'Cleanup: delete est. type');
}
if ($created['category_id']) {
    expectSuccess(api('category.php', ['action' => 'delete', 'ids' => [$created['category_id']]]), 'Cleanup: delete category');
}

@unlink($cookieJar);

echo "\n=== Summary: {$passed} passed, {$failed} failed, {$warnings} warnings ===\n";
exit($failed > 0 ? 1 : 0);
