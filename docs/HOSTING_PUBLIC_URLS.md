# Hosting & public URL configuration

**Audience:** admins and developers  
**Production host example:** `https://tatakormocawards.com`

## Edit public URLs

| What | Where |
|------|--------|
| Site root + `/vote/` `/register/` `/track/` | **Customizations → Public Share Links** |
| Per-business vote link + QR | **File Maintenance → Businesses** (Vote Link Copy = QR payload) |

After changing the site root, open Businesses → **Regenerate All QR Codes**.

---

## Which value wins

1. `config.local.php` → `public_site_url` (if not empty). This **locks** the admin field. The page will not show a successful save while locked.
2. Database (`tbl_config` keys `voting_qr_base_url` / `nomination_qr_base_url`) — what **Save site root** writes.
3. Auto-detect from the **current admin address** (localhost when you are on XAMPP). Auto-detect never overrides a saved database value.

Do not set `public_site_url` to `http://127.0.0.1/...` on Hostinger. Either leave it empty so Public Share Links can control the domain, or set it to `https://tatakormocawards.com`.

---

## Public path scheme

| Purpose | URL | Browser address stays as |
|---------|-----|---------------------------|
| Voting | `https://tatakormocawards.com/vote/` | `/vote/` |
| Categories | `https://tatakormocawards.com/vote/categories` | `/vote/categories` |
| Ballot | `https://tatakormocawards.com/vote/ballot` | `/vote/ballot` |
| Summary | `https://tatakormocawards.com/vote/summary` | `/vote/summary` |
| Thank you | `https://tatakormocawards.com/vote/thanks` | `/vote/thanks` |
| Registration | `https://tatakormocawards.com/register/` | `/register/` |
| Tracking | `https://tatakormocawards.com/track/` | `/track/` |
| One business | `https://tatakormocawards.com/vote/gemma-s-store/` | `/vote/gemma-s-store/` |

Routed by `.htaccess` → `public_router.php` (serves the real page with a `<base href>` so CSS/JS still load).

---

## Hosting checklist

1. Document root = app folder containing `.htaccess` and `public_router.php`
2. Apache `mod_rewrite` + `AllowOverride All`
3. On domain root, leave `RewriteBase` commented out
4. On the **live** admin (not XAMPP), open **Public Share Links**
5. If the page says the site root is locked, edit `config.local.php` on the server: set `public_site_url` to `https://tatakormocawards.com` or remove that key
6. Save site root as `https://tatakormocawards.com` and confirm **Currently used** matches
7. Regenerate QRs after changing the site root

Local XAMPP subdirectory: uncomment `RewriteBase /TOCCA_RECENT_NEWEST_2/` in `.htaccess`. Saving the live domain on XAMPP only updates the **local** database. Repeat the save on Hostinger.

---

## Code helpers (developers)

- `qr_vote_portal_url()` → `/vote/`
- `qr_nomination_form_url()` → `/register/`
- `qr_tracking_url()` → `/track/`
- `qr_vote_url_for_choice()` → `/vote/{business-slug}/`
- `tocca_voter_public_url('thankyou.php')` → `/vote/thanks` (also categories, ballot, summary, privacy, terms)

Long `/e-vote-final-enhanced/*.php` addresses 302 to the short `/vote/...` paths. APIs (`save_draft.php`, `submit_vote.php`, `load_*.php`) stay as real files so voting can still save.

Map file: this document. In-admin editor: **Public Share Links**.
