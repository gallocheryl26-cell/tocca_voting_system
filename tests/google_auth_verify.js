'use strict';

const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');
const checks = [];

function check(name, condition) {
  checks.push({ name, ok: Boolean(condition) });
}

const config = read('config.php');
const login = read('e-vote-final-enhanced/google_voter_login.php');
const link = read('e-vote-final-enhanced/link_google_voter.php');
const firebase = read('e-vote-final-enhanced/lib/firebase_verify.php');
const flow = read('e-vote-final-enhanced/lib/voter_flow.php');
const ui = read('e-vote-final-enhanced/js/google_voter_auth.js');
const index = read('e-vote-final-enhanced/index.php');
const qr = read('e-vote-final-enhanced/qr_vote.php');
const sendOtp = read('e-vote-final-enhanced/send_otp.php');
const migration = read('db/migrations/017_google_voter_auth.sql');

check('Google-first mode enabled', config.includes("'voter_auth_mode'    => 'google_with_legacy'"));
check('Google provider is required', firebase.includes("providerId'] ?? '') === 'google.com'"));
check('Verified email is required', firebase.includes('emailVerified'));
check('Token is verified on the server', login.includes('firebase_verify_id_token($idToken)'));
check('Firebase UID is the unique voter identity', login.includes('(mobile_number, firebase_uid, google_email') && login.includes('ON DUPLICATE KEY UPDATE'));
check('New Google voters have no fake mobile number', login.includes('VALUES (NULL, ?, ?'));
check('Google session is marked server-side', login.includes("voter_auth_provider'] = 'google'"));
check('Google session can access the ballot without a PIN', flow.includes("voter_auth_provider'] ?? '') === 'google'"));
check('Legacy voters can link without changing voter id', link.includes('WHERE voters_id = ?'));
check('Linking refuses a Google account owned by another voter', link.includes('already connected to another voter'));
check('Main portal loads Google UI', index.includes("partials/google_auth_modals.php"));
check('QR portal loads Google UI', qr.includes("partials/google_auth_modals.php"));
check('Facebook in-app browser warning exists', ui.includes('FBAN|FBAV|Instagram|Messenger'));
check('Google popup exchanges an ID token with PHP', ui.includes("fetch('google_voter_login.php'"));
check('SMS endpoint is disabled in Google mode', sendOtp.includes('SMS OTP is disabled'));
check('Migration preserves rows and adds UID uniqueness', migration.includes('uq_voters_firebase_uid'));

let passed = 0;
for (const result of checks) {
  process.stdout.write(`[${result.ok ? 'PASS' : 'FAIL'}] ${result.name}\n`);
  if (result.ok) passed += 1;
}
process.stdout.write(`\n${passed}/${checks.length} passed\n`);
process.exitCode = passed === checks.length ? 0 : 1;
