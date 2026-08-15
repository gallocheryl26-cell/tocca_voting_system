# Tatak Ormoc Consumers’ Choice Awards (TOCCA)

PHP application for the **Tatak Ormoc Consumers’ Choice Awards**: business registration, admin review, and public voting.

## What’s included

- **Admin** (`tocca_admin/`) — events, awards, businesses, registrations, communications, and reports
- **Registration** (`nomination/`) — public registration form and status tracking
- **Voting** (`e-vote-final-enhanced/`) — mobile voting portal

## Public links

After you set the site root in **Customizations → Public Share Links**:

| Page | Path |
|------|------|
| Register | `/register` |
| Track registration | `/track` |
| Vote | `/vote` |

See `docs/HOSTING_PUBLIC_URLS.md` and `docs/USER_MANUAL.md`.

## Local setup

1. PHP, MySQL, and Apache (for example XAMPP)
2. Copy `config.local.php.example` to `config.local.php` and set database credentials
3. Point the document root at this project folder
4. Open the admin panel and activate an event
