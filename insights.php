<?php
/**
 * Bonsai Uptime Monitor — insights refresher (PageSpeed scores, screenshots,
 * last content update)
 *
 * Run this on cron once an hour:
 *   0 * * * * /usr/bin/php /path/to/uptime-monitor/insights.php >> /path/to/uptime-monitor/cron.log 2>&1
 *
 * Each run refreshes content dates that are due (~daily per site) and runs
 * PageSpeed for a small batch of due sites (~monthly per site — see
 * um_refresh_insights_due), so a single run stays a few minutes long.
 * Kept separate from checker.php because PageSpeed calls take 10–30s each
 * and must never delay the every-minute uptime checks.
 */

// CLI only — hitting this over the web would burn PageSpeed API quota.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require __DIR__ . '/includes/insights.php';

// CLI has no time limit by default; this only matters if the host sets one.
// Guarded because some hosts disable set_time_limit() (fatal on PHP 8).
if (function_exists('set_time_limit')) {
    @set_time_limit(0);
}

// Skip this run if the previous one is still going (slow PageSpeed batch).
$lock = fopen(sys_get_temp_dir() . '/bonsai-uptime-insights.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit;
}

$pdo = um_db();
um_refresh_insights_due($pdo, (int)um_get_setting('pagespeed_batch_size', 4));

flock($lock, LOCK_UN);
fclose($lock);
