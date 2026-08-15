# Hosting & public URL configuration

**Audience:** admins and developers  
**Production host example:** `https://tatakormocawards.com`

## Edit public URLs

| What | Where |
|------|--------|
| Site root + `/vote` `/register` `/track` | **Customizations → Public Share Links** |
| Per-business vote link + QR | **File Maintenance → Establishments** (Vote Link Copy = QR payload) |

After changing the site root, open Establishments → **Regenerate All QR Codes**.

---

## Public path scheme

| Purpose | URL | Browser address stays as |
|---------|-----|---------------------------|
| Voting | `https://tatakormocawards.com/vote` | `/vote` |
| Registration | `https://tatakormocawards.com/register` | `/register` |
| Tracking | `https://tatakormocawards.com/track` | `/track` |
| One business | `https://tatakormocawards.com/gemma-s-store` | `/gemma-s-store` |

Routed by `.htaccess` → `public_router.php` (serves the real page with a `<base href>` so CSS/JS still load).

---

## Hosting checklist

1. Document root = app folder containing `.htaccess` and `public_router.php`
2. Apache `mod_rewrite` + `AllowOverride All`
3. On domain root, leave `RewriteBase` commented out
4. Open **Public Share Links**, set site root to `https://tatakormocawards.com`, save
5. Regenerate QRs after changing the site root

Local XAMPP subdirectory: uncomment `RewriteBase /TOCCA_RECENT_NEWEST_2/` in `.htaccess`.

---

## Code helpers (developers)

- `qr_vote_portal_url()` → `/vote`
- `qr_nomination_form_url()` → `/register`
- `qr_tracking_url()` → `/track`
- `qr_vote_url_for_choice()` → `/{business-slug}`

Map file: this document. In-admin editor: **Public Share Links**.
