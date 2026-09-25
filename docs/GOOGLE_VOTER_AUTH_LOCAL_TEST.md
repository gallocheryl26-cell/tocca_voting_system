# Google voter authentication: local test checklist

This branch uses Google Sign-In for new voters and keeps mobile number + access code for existing voters. SMS OTP endpoints return HTTP 410 while `voter_auth_mode` is `google_with_legacy`.

## One-time Firebase setup

1. In Firebase Console, open **Authentication → Sign-in method**.
2. Enable **Google** and select the project support email.
3. Under **Authentication → Settings → Authorized domains**, add the exact local hostname used for testing. Prefer `localhost`; add `127.0.0.1` separately only when using it.
4. Keep `tatakormocawards.com` authorized for the eventual deployment, but do not deploy this branch during local testing.

## Local database

1. Back up the local `tocca_db` database.
2. Apply `db/migrations/017_google_voter_auth.sql` to the local database only.
3. Put the correct local MySQL credentials in the ignored `config.local.php` file.
4. Set `voting_on_hold` to `false` in `config.local.php` while testing.

## Required tests

- A new Google account creates exactly one `tbl_voters` row with `firebase_uid`, `google_email`, and a null mobile number.
- Signing in again with the same Google account returns to the same `voters_id`.
- Signing in from both `index.php` and `qr_vote.php` returns to the same voter.
- A Google voter can open the ballot without setting a four-digit access code.
- A voter who has already completed the ballot is sent to `thankyou.php` and cannot vote again.
- An existing voter can still sign in using mobile number + access code.
- Selecting “Link a Google account” connects the Google UID to that existing `voters_id` without changing drafts or votes.
- Attempting to link one Google account to two voter records is rejected.
- Facebook, Messenger, and Instagram in-app browsers show the external-browser warning.
- Calls to `send_otp.php`, `verify_otp.php`, and `register_new_voter.php` are rejected in Google mode and do not send SMS.

Run the dependency-free static verification with:

```powershell
node tests/google_auth_verify.js
```
