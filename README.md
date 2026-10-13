# Bonsai Uptime Monitor

Self-hosted UptimeRobot replacement. PHP + MySQL, no frameworks, no per-check billing.

## Setup

1. Create a MySQL database and import `schema.sql`:
   ```
   mysql -u youruser -p yourdb < schema.sql
   ```
   Upgrading an existing install? `CREATE TABLE IF NOT EXISTS` won't add new columns to a `sites` table that already exists — run the commented `ALTER TABLE` block at the bottom of `schema.sql` once, by hand.
2. Copy `config-sample.php` to `config.php` and fill in your DB credentials. `config.php` is gitignored — never commit your real credentials.
3. Set a bootstrap admin password — generate a hash and paste it into `config.php`:
   ```
   php -r "echo password_hash('yourpassword', PASSWORD_DEFAULT);"
   ```
   Replace `admin_password_hash` with the output. Until this is set, `admin.php`/`settings.php` refuse all logins. This is a one-time bootstrap — log in once, then set a real password via the Settings screen (step 5) and the database copy takes over from `config.php`.
4. Add your sites via the web UI — log in at `login.php`, then use `admin.php` ("Manage sites" link on the dashboard) to add, edit, deactivate, delete, or manually re-check a site. (Direct SQL still works too if you prefer:
   ```sql
   INSERT INTO sites (name, url, check_interval_seconds) VALUES
   ('Client Name', 'https://clientsite.com', 300);
   ```
   )
5. Configure alerts and the checker via `settings.php` ("Settings" link from Manage sites) — no code edits or redeploys needed for these afterwards:
   - Slack webhook URL (Slack → your #site-alerts app → Incoming Webhooks)
   - Alert email (leave blank to disable) and its "From" address — uses PHP's built-in `mail()`, so check with your host that outbound mail is enabled if alerts don't arrive
   - Checker concurrency
   - SSL/domain expiry alert threshold (days before expiry to notify — default 14)
   - Admin password (requires the current password to change)
   - PageSpeed Insights API key and sites per run (see "Dashboard cards" below)
6. Upload the whole folder to a subdomain, e.g. `status.bonsaidigital.co.uk`.
7. Add the cron job (every minute — each site respects its own `check_interval_seconds` so this doesn't mean every site is hit every minute):
   ```
   * * * * * /usr/bin/php /home/yourusername/uptime-monitor/checker.php >> /home/yourusername/uptime-monitor/cron.log 2>&1
   ```
   And a second, hourly one for PageSpeed scores, screenshots and content dates (CLI only, refuses web requests):
   ```
   0 * * * * /usr/bin/php /home/yourusername/uptime-monitor/insights.php >> /home/yourusername/uptime-monitor/cron.log 2>&1
   ```
   The `screenshots/` folder is created automatically and must be writable by the cron user.
8. Visit `index.php` for the dashboard, click through to `site.php?id=X` for per-site stats, `admin.php` to manage sites, or `settings.php` to change alert/checker/password settings.

## Admin panel

- `login.php` / `logout.php` — session-based login, single shared password (see setup step 3 above).
- `admin.php` — add, edit, activate/deactivate, delete, or manually trigger a check for any site. All forms are CSRF-protected and require login.
- `settings.php` — edit the Slack webhook, alert email, Support Desk ticket webhook, checker concurrency, and admin password from the browser. Values are stored in the `settings` table and take precedence over `config.php` once saved.
- The dashboard (`index.php`) shows a "Manage sites" link when logged in, or "Log in" otherwise. `site.php` (per-site detail) stays public, matching current behaviour.

## Looker Studio

MySQL is the source of truth for both the dashboard and reporting, so you don't need to double-log anywhere.

1. In Looker Studio, add a new data source → MySQL connector.
2. Point it at the same database, and either:
   - Query the `checks` table directly for raw response-time trends, or
   - Build a small view for cleaner reporting, e.g.:
     ```sql
     CREATE VIEW daily_uptime AS
     SELECT
         site_id,
         DATE(checked_at) AS day,
         ROUND(SUM(status = 'up') / COUNT(*) * 100, 2) AS uptime_pct,
         ROUND(AVG(response_time_ms), 0) AS avg_response_ms
     FROM checks
     GROUP BY site_id, DATE(checked_at);
     ```
   Point Looker Studio at `daily_uptime` for a clean per-day, per-site uptime chart — good for a client-facing report page.
3. Your MySQL server needs to be reachable from Google's IP ranges (or use Cloud SQL Proxy / an SSH tunnel if it's locked down) — check with your host if remote MySQL access is currently open.

## Check paths

- On a site's edit screen in `admin.php`, add extra URL paths to check alongside the main URL — e.g. `/wp-login.php` or `/posts?per_page=1` — each with its own expected status code and optional label.
- Checked on the same cron cycle as the main URL, with the same retry behaviour.
- If the main URL or any active path fails, the whole site is marked down and a single Slack/email alert fires, naming which path caused it.
- Per-path status is shown on the site's edit screen and on `site.php`; per-path check history is logged but doesn't affect the site's own uptime%/response-time stats (those stay about the main URL).

## Dashboard cards

Each card on `index.php` shows a homepage screenshot, name/URL/status, 30-day uptime, average response time, PageSpeed mobile/desktop performance scores, and when the site's WordPress content was last updated. Everything except uptime/response comes from `insights.php` (`includes/insights.php`):

- **PageSpeed scores + screenshot** — Google PageSpeed Insights API, one mobile and one desktop run per site. The screenshot is the desktop run's final frame, saved to `screenshots/site-{id}.jpg`. Refreshed every 30 days per site; a failed run retries the next day and keeps the previous scores. `Sites per hourly run` (Settings, default 4) caps how many sites one run tests, so 50 sites take about half a day to fill in from scratch. Score underline colours follow Google's bands: 90+ green, 50–89 amber, under 50 red.
  - **Needs an API key** (Settings → PageSpeed & screenshots). Keyless requests share a Google-wide quota that's usually exhausted. Free key: Google Cloud Console → enable "PageSpeed Insights API" → Credentials → API key. Quota is 25,000/day, far more than this uses.
- **Content updated** — latest `modified` date across posts and pages from the public WP REST API (`/wp-json/wp/v2/posts|pages?orderby=modified`). Checked about once a day. Non-WordPress sites, or sites blocking the REST API, show "—".
- **Refresh insights** on the admin table re-runs both for one site immediately (takes up to a minute).

## SSL & domain expiry

- Each site has independent `Monitor SSL certificate expiry` / `Monitor domain (WHOIS) expiry` toggles on the add/edit form (both on by default).
- Checked once a day per site (not on every cron run) via `checker.php` — see `um_check_expiries_due()` in `includes/checks.php`.
- SSL expiry is read directly off the live TLS certificate — reliable, no external dependency.
- Domain expiry is a best-effort raw WHOIS lookup (`includes/expiry.php`): queries `whois.iana.org` for a referral, then the registry (and registrar, for thin registries like `.com`/`.net`), and parses whichever expiry field that WHOIS server happens to use.
  - `.uk`/`.co.uk` domains work too — Nominet (the `.uk` registry) reports this under a "Renewal date:" field rather than "Expiry Date:", which the parser also checks for. IANA's WHOIS referral for ccTLDs like `.uk` comes back as a `whois:` field rather than the `refer:` field gTLDs (`.com`/`.net`/...) use — the lookup checks for both.
- A one-time Slack/email alert fires when either crosses the `expiry_alert_days` threshold (Settings), and resets automatically if the cert/domain is renewed further out — so you won't get the same alert every day.
- "Refresh expiry" on the admin table forces an immediate re-check for one site, bypassing the daily gate.

## Support Desk tickets

Optionally open a ticket in a Support Desk site whenever a site goes down, and add a note to it when it recovers:

1. In the support site: Support → Settings → Uptime monitoring → switch on **Status monitor**, save, **Generate secret**.
2. Here: Settings → **Support tickets** → paste the endpoint URL and secret, save, **Send test**.

What's sent: `down` (with the cause and incident id), `up` (with the downtime in seconds), and `ssl_expiry` / `domain_expiry` (days left and date), on the same state changes that fire Slack/email. Each request is JSON, signed with `X-Monitor-Timestamp` and `X-Monitor-Signature: sha256=HMAC-SHA256(timestamp + "." + body, secret)`. Like the other channels it's fire-and-forget: if the support site is slow or down, the failure goes to the PHP error log and the checker carries on. Clear both fields to switch it off.

## Notes

- Alerts only fire on state change (up→down, down→up), so you won't get spammed every cycle.
- Slack, email and Support Desk ticket alerts fire independently — configure any, all, or none in `settings.php`.
- `checker.php` uses `curl_multi` so checks run in parallel — 50 sites finishes in roughly the time of your slowest single site, not 50x that.
- Response check is HEAD-style (`CURLOPT_NOBODY`) — fast and cheap. If a site needs a full page load to catch app-level errors (not just server-up), swap that off for specific sites.
