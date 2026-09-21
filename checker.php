<?php
/**
 * Bonsai Uptime Monitor — checker
 *
 * Run this on cron every minute:
 *   * * * * * /usr/bin/php /path/to/uptime-monitor/checker.php >> /path/to/uptime-monitor/cron.log 2>&1
 *
 * Each site has its own check_interval_seconds, so this script only actually
 * pings a site if enough time has passed since last_checked_at — meaning you
 * can run the cron every minute but still have 5-minute-interval sites.
 */

require __DIR__ . '/includes/checks.php';

$pdo = um_db();
$concurrency = (int)um_get_setting('concurrency', 10);

$sites = $pdo->query(
    "SELECT * FROM sites
     WHERE is_active = 1
       AND (last_checked_at IS NULL
            OR last_checked_at <= (NOW() - INTERVAL check_interval_seconds SECOND))"
)->fetchAll();

if (!empty($sites)) {
    um_check_sites_with_paths($pdo, $sites, $concurrency);
}

// SSL/domain expiry — gated separately, roughly once a day per site (see
// um_check_expiries_due), so this is cheap to call on every cron run.
um_check_expiries_due($pdo);
