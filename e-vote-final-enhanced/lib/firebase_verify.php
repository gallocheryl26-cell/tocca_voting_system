<?php
declare(strict_types=1);

/**
 * Verify Firebase ID tokens (phone auth) via Identity Toolkit REST API.
 */
function firebase_verify_id_token(string $idToken, ?string $apiKey = null): array
{
    $idToken = trim($idToken);
    if ($idToken === '') {
        throw new InvalidArgumentException('Missing Firebase ID token.');
    }

    $apiKey = $apiKey ?? (string) tocca_config('firebase_web_api_key');
    if ($apiKey === '') {
        throw new RuntimeException('Firebase web API key is not configured.');
    }

    $url = 'https://identitytoolkit.googleapis.com/v1/accounts:lookup?key=' . rawurlencode($apiKey);
    $payload = json_encode(['idToken' => $idToken]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $resp = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        throw new RuntimeException('Firebase verification request failed: ' . $err);
    }

    $json = json_decode($resp, true);
    if ($http >= 400 || !is_array($json)) {
        $msg = is_array($json) ? ($json['error']['message'] ?? 'Invalid token') : 'Invalid token';
        throw new RuntimeException('Firebase rejected the ID token: ' . $msg);
    }

    $users = $json['users'] ?? [];
    if (!is_array($users) || count($users) === 0) {
        throw new RuntimeException('Firebase did not return a user for this token.');
    }

    return $users[0];
}
/**
 * Normalize Firebase phone (+639…) to local 09XXXXXXXXX used in tbl_voters.
 */
function firebase_phone_to_local09(array $firebaseUser): string
{
    $phone = trim((string) ($firebaseUser['phoneNumber'] ?? ''));
    if ($phone === '') {
        return '';
    }

    $digits = preg_replace('/\D+/', '', $phone);
    if ($digits === null) {
        return '';
    }

    if (preg_match('/^639\d{9}$/', $digits)) {
        return '0' . substr($digits, 2);
    }
    if (preg_match('/^09\d{9}$/', $digits)) {
        return $digits;
    }
    if (preg_match('/^9\d{9}$/', $digits)) {
        return '0' . $digits;
    }

    return '';
}
