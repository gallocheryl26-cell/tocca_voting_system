# TOCCA Security Notes

## Public / hosting URLs

Share only short paths (`/vote/`, `/vote/summary`, `/register/`, `/track/`, `/vote/{business}/`). Do not send voters `e-vote-final-enhanced/*.php`, `nomination/*.php`, or `tocca_admin/` links.

Hiding folder names is **not** encryption. Security comes from short public routes, prepared SQL, sanitized `ref` values, and blocking direct access to config/source folders. Details: [`docs/HOSTING_PUBLIC_URLS.md`](docs/HOSTING_PUBLIC_URLS.md).

## Session cookies (important)

Admin and voter apps use **separate session cookies** so they do not conflict in the same browser:

| App | Cookie name |
|-----|-------------|
| Admin (`tocca_admin/`) | `TOCCA_ADMIN` |
| E-vote (`e-vote-final-enhanced/`) | `TOCCA_VOTER` |

If voting fails after using the admin panel, refresh the voter site or complete OTP/draft login again.

## Admin login

- URL: `http://127.0.0.1/TOCCA_RECENT_NEWEST_2/tocca_admin/index.php`
- Database usernames (examples): `testuser`, `toccaAdmin` — use your real password, not `admin`/`admin`.
- After 3 failed attempts, login is locked for 5 minutes (`login_lock_seconds` in `config.php`).

## Automated checks

```powershell
cd c:\xampp\htdocs\TOCCA_RECENT_NEWEST_2
php tests\security_verify.php
```

Expect `6/6 passed`.

Admin P0 endpoint lockdown (no session → 401):

```powershell
php tests\admin_p0_verify.php
php tests\admin_session_verify.php
```

## Existing voter access code

- After **5** failed attempts for the same mobile (`voter_access_max_attempts` in `config.php`), login is locked for **5 minutes** (`voter_access_lock_seconds`).
- Lockout is enforced server-side in `verify_existing.php` (not only in the browser).
- Legitimate recovery: **Forgot access code?** (Firebase OTP) resets the code.

## Voter OTP

Voters can verify by **Firebase Phone Auth** (default) or **server SMS via Twilio** (`otp_provider` => `server` in `config.local.php`).

### Option A — Firebase (default)

Firebase Phone Auth requires the project to be on the **Blaze (pay-as-you-go) plan** and **Phone** sign-in enabled. Without billing you will see `auth/billing-not-enabled`.

1. [Firebase Console](https://console.firebase.google.com/) → your project → **Upgrade** to Blaze.
2. **Authentication** → **Sign-in method** → enable **Phone**.
3. Copy `config.local.php.example` to `config.local.php` and set `firebase_web_api_key`.

### Option B — Server OTP (Twilio)

Use when Firebase billing is not available:

```php
'otp_provider' => 'server',
'twilio_account_sid' => 'AC...',
'twilio_auth_token' => '...',
'twilio_from_number' => '+1...',
'recaptcha_secret_key' => '...', // v2 secret matching your reCAPTCHA widget
```

The voter UI automatically falls back to server SMS when Firebase returns `auth/billing-not-enabled` and Twilio is configured.

### Local testing without SMS

Set `'app_debug' => true` in `config.local.php`. `send_otp.php` returns `debug_otp` in the JSON response (development only).
