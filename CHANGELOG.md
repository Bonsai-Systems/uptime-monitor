# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased] - 2026-10-13

### Added
- Bigger dashboard cards: homepage screenshot, PageSpeed mobile/desktop performance scores (colour-coded to Google's 90/50 bands), and last WordPress content update date, alongside the existing uptime/response stats. Data comes from a new hourly cron entry point, `insights.php` (CLI-only, lock-protected so slow runs can't overlap), backed by `includes/insights.php`: PageSpeed Insights API, refreshed every 30 days per site (next day after a failure, previous scores kept), in batches of `pagespeed_batch_size` sites per run, with the desktop screenshot saved to `screenshots/site-{id}.jpg`; content date from the public WP REST API, about once a day. The scores and content date also appear on `site.php` as a third row of summary cards, with a note when the latest PageSpeed run failed and older scores are shown. New "PageSpeed & screenshots" card on `settings.php` (`pagespeed_api_key`, `pagespeed_batch_size`) and a "Refresh insights" action on `admin.php`. Deleting a site removes its screenshot. **Requires the new `ALTER TABLE` migration at the bottom of `schema.sql` on existing databases.**

### Changed
- Dashboard grid columns widened (280px → 320px minimum) to fit the larger cards; single column with tighter padding under 600px.
- Support Desk tickets — a third alert channel, `um_send_ticket_webhook()` in `includes/functions.php`, called alongside Slack/email on the down and up transitions and on SSL/domain expiry alerts. Posts signed JSON (HMAC-SHA256, timestamped) to a Support Desk site, which opens a ticket on down/expiry and adds a note on recovery. Fire-and-forget: failures are logged, never thrown. Configured from a new "Support tickets" card on `settings.php` (endpoint URL + secret, CSRF-protected, with **Send test**). New settings `ticket_webhook_url` / `ticket_webhook_secret`; no schema change.

### Fixed
- False "down" alerts from transient blips — a failed check now gets up to 2 retries (3s apart) within the same cron run before the site is actually marked down and an incident/alert fires. `um_check_sites_with_retry()` in `includes/checks.php` wraps the existing `um_check_sites_concurrently()`; `checker.php` and the admin "Check now" action (`um_check_site_now()`) both use it now.

### Added
- Multiple check paths per site — a site can now define extra URL paths (e.g. `/wp-login.php`, `/posts?per_page=1`) checked alongside its main URL on every cron cycle, managed from a new "Check paths" panel on the site's edit screen in `admin.php` (add, activate/deactivate, delete; each path has its own expected status code and optional label). Backed by a new `site_paths` table and a nullable `checks.site_path_id` column — **requires the migration at the bottom of `schema.sql` on existing databases**. If the main URL or any active path fails, the whole site is marked down and a single incident/alert fires (see `um_check_sites_with_paths()`/`um_process_site_result()` in `includes/checks.php`), naming which path caused it in the incident cause. Per-path check history is logged to `checks` for future reference but excluded from the site's own uptime%/response-time stats, which continue to reflect the main URL only. Path statuses are shown on `site.php` and in the admin edit panel.
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
sss- `schema.sql` for one-time database setup (`sites`, `checks`, `incidents` tables).
- `config-sample.php` template — `config.php` is gitignored so real credentials never get committed.
- Looker Studio integration notes for client-facing uptime reporting.
