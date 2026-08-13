# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased] - 2026-08-03

### Added
- SSL certificate and domain (WHOIS) expiry tracking per site — new `monitor_ssl`/`monitor_domain` toggles on the add/edit form, colour-coded expiry badges on the admin table and site detail page, a "Refresh expiry" admin action, and a one-time Slack/email alert when either crosses the configurable `expiry_alert_days` threshold (default 14 days). SSL is read directly off the live TLS certificate; domain expiry uses a best-effort raw WHOIS lookup (`includes/expiry.php`) that follows IANA's WHOIS referral (handling both the `refer:` field gTLDs use and the `whois:` field ccTLDs like `.uk` use) and parses Nominet's "Renewal date:" field alongside the more common "Expiry Date:"/"Registry Expiry Date:" fields, so `.uk`/`.co.uk` domains resolve correctly. Checked once daily per site via `checker.php`, independent of the uptime check interval. **Requires an `ALTER TABLE` migration on existing databases — see the commented block at the bottom of `schema.sql`.**
- Light/dark mode toggle, persisted per-browser via `localStorage`, applied before first paint to avoid a flash of the wrong theme.
- Favicon (`assets/favicon.svg`) — Bonsai-branded pulse icon.
- Email alerts on down/up state change, via PHP's built-in `mail()` (`alert_email` / `alert_email_from`). Fires alongside Slack alerts — configure either, both, or neither.
- `settings.php` — browser-based settings screen for Slack webhook, alert email, checker concurrency, expiry alert threshold, and admin password. Backed by a new `settings` table; `config.php` now only strictly needs DB credentials and acts as a one-time bootstrap default for everything else.

### Changed
- `slack_webhook_url`, `alert_email`, `alert_email_from`, `concurrency`, `expiry_alert_days`, and `admin_password_hash` are now DB-backed settings rather than requiring a `config.php` edit + redeploy to change.
- All pages now send `X-Robots-Tag: noindex, nofollow` and a matching `<meta name="robots">` tag, plus a site-wide `robots.txt` disallowing all crawlers — this is an internal monitoring dashboard, not a page that should ever appear in search results.

## [1.0.0] - 2026-08-03

### Added
- Dashboard (`index.php`) listing all active sites with current status, 30-day uptime, and average response time.
- Per-site detail page (`site.php`) with 24h/7d/30d uptime, average response time, response-time chart, and incident history.
- `checker.php` cron script — concurrent HTTP checks via `curl_multi`, per-site check interval, automatic incident open/close, Slack alerts on state change.
- Admin panel (`admin.php`) for adding, editing, activating/deactivating, deleting, and manually re-checking sites — no more direct SQL required.
- Session-based admin login (`login.php` / `logout.php`) with a single shared password, CSRF-protected forms.
- `schema.sql` for one-time database setup (`sites`, `checks`, `incidents` tables).
- `config-sample.php` template — `config.php` is gitignored so real credentials never get committed.
- Looker Studio integration notes for client-facing uptime reporting.
