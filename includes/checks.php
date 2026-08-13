<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/expiry.php';

/**
 * Fires HTTP checks for one or more sites in parallel batches using curl_multi.
 */
function um_check_sites_concurrently(array $sites, int $concurrency): array {
    $results = [];
    $batches = array_chunk($sites, max(1, $concurrency));

    foreach ($batches as $batch) {
        $mh = curl_multi_init();
        $handles = [];

        foreach ($batch as $site) {
            $ch = curl_init($site['url']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_NOBODY => true, // HEAD-style, we only need status + timing
                CURLOPT_TIMEOUT => (int)$site['timeout_seconds'],
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT => 'BonsaiUptimeMonitor/1.0',
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$site['id']] = $ch;
        }

        $running = null;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh);
        } while ($running > 0);

        foreach ($handles as $siteId => $ch) {
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
            $error = curl_error($ch);

            $results[$siteId] = [
                'http_code' => $httpCode,
                'response_time_ms' => (int)round($totalTime * 1000),
                'error' => $error ?: null,
            ];

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);
    }

    return $results;
}

/**
 * Logs the check, updates site status, and handles incident open/close + Slack alert.
 */
function um_process_result(PDO $pdo, int $siteId, array $result): void {
    $stmt = $pdo->prepare("SELECT * FROM sites WHERE id = ?");
    $stmt->execute([$siteId]);
    $site = $stmt->fetch();
    if (!$site) return;

    $isUp = $result['http_code'] === (int)$site['expected_status_code'] && empty($result['error']);
    $newStatus = $isUp ? 'up' : 'down';
    $now = date('Y-m-d H:i:s');

    // Log the check
    $pdo->prepare(
        "INSERT INTO checks (site_id, checked_at, status, http_code, response_time_ms, error_message)
         VALUES (?, ?, ?, ?, ?, ?)"
    )->execute([
        $siteId, $now, $newStatus,
        $result['http_code'] ?: null,
        $result['response_time_ms'] ?: null,
        $result['error'],
    ]);

    // Update site's current status + last checked time
    $pdo->prepare("UPDATE sites SET current_status = ?, last_checked_at = ? WHERE id = ?")
        ->execute([$newStatus, $now, $siteId]);

    $previousStatus = $site['current_status'];

    // State change: was up (or unknown), now down — open an incident + alert
    if ($newStatus === 'down' && $previousStatus !== 'down') {
        $pdo->prepare(
            "INSERT INTO incidents (site_id, started_at, cause) VALUES (?, ?, ?)"
        )->execute([$siteId, $now, $result['error'] ?: "HTTP {$result['http_code']}"]);

        um_send_slack_alert(
            ":red_circle: *{$site['name']}* appears to be DOWN\n" .
            "URL: {$site['url']}\n" .
            "Reason: " . ($result['error'] ?: "HTTP {$result['http_code']}") . "\n" .
            "Time: {$now}"
        );

        um_send_email_alert(
            "DOWN: {$site['name']}",
            "{$site['name']} appears to be DOWN\n" .
            "URL: {$site['url']}\n" .
            "Reason: " . ($result['error'] ?: "HTTP {$result['http_code']}") . "\n" .
            "Time: {$now}"
        );
    }

    // State change: was down, now up — close the incident + alert
    if ($newStatus === 'up' && $previousStatus === 'down') {
        $stmt = $pdo->prepare(
            "SELECT * FROM incidents WHERE site_id = ? AND resolved_at IS NULL ORDER BY started_at DESC LIMIT 1"
        );
        $stmt->execute([$siteId]);
        $incident = $stmt->fetch();

        if ($incident) {
            $duration = strtotime($now) - strtotime($incident['started_at']);
            $pdo->prepare(
                "UPDATE incidents SET resolved_at = ?, duration_seconds = ? WHERE id = ?"
            )->execute([$now, $duration, $incident['id']]);

            um_send_slack_alert(
                ":large_green_circle: *{$site['name']}* is back UP\n" .
                "URL: {$site['url']}\n" .
                "Downtime: " . um_format_duration($duration) . "\n" .
                "Time: {$now}"
            );

            um_send_email_alert(
                "UP: {$site['name']}",
                "{$site['name']} is back UP\n" .
                "URL: {$site['url']}\n" .
                "Downtime: " . um_format_duration($duration) . "\n" .
                "Time: {$now}"
            );
        }
    }
}

/**
 * Runs due SSL/domain expiry checks. Unlike uptime checks, these are gated to
 * roughly once a day per site (WHOIS servers rate-limit, and a certificate's
 * expiry date doesn't need re-reading every few minutes).
 */
function um_check_expiries_due(PDO $pdo): void {
    $sites = $pdo->query(
        "SELECT * FROM sites
         WHERE is_active = 1
           AND (
                (monitor_ssl = 1 AND (ssl_checked_at IS NULL OR ssl_checked_at <= (NOW() - INTERVAL 20 HOUR)))
                OR
                (monitor_domain = 1 AND (domain_checked_at IS NULL OR domain_checked_at <= (NOW() - INTERVAL 20 HOUR)))
           )"
    )->fetchAll();

    foreach ($sites as $site) {
        um_check_site_expiry($pdo, $site);
    }
}

/**
 * Refreshes SSL/domain expiry for one site row and fires a one-time alert
 * when either crosses the configured warning threshold.
 */
function um_check_site_expiry(PDO $pdo, array $site): void {
    $host = (string)parse_url($site['url'], PHP_URL_HOST);
    if ($host === '') {
        return;
    }

    $alertDays = (int)um_get_setting('expiry_alert_days', 14);
    $now = date('Y-m-d H:i:s');

    if ($site['monitor_ssl']) {
        $result = um_check_ssl_expiry($host);
        $expiresAt = $result['expires_at'];

        $pdo->prepare("UPDATE sites SET ssl_expires_at = ?, ssl_checked_at = ? WHERE id = ?")
            ->execute([$expiresAt ? $expiresAt->format('Y-m-d H:i:s') : null, $now, $site['id']]);

        um_maybe_alert_expiry($pdo, $site, 'ssl', $expiresAt, $alertDays);
    }

    if ($site['monitor_domain']) {
        $result = um_check_domain_expiry($host);
        $expiresAt = $result['expires_at'];

        $pdo->prepare("UPDATE sites SET domain_expires_at = ?, domain_checked_at = ? WHERE id = ?")
            ->execute([$expiresAt ? $expiresAt->format('Y-m-d H:i:s') : null, $now, $site['id']]);

        um_maybe_alert_expiry($pdo, $site, 'domain', $expiresAt, $alertDays);
    }
}

/**
 * Fires a Slack/email alert the first time an SSL cert or domain crosses the
 * warning threshold, and resets the flag if it's later renewed further out —
 * mirrors the incident open/close pattern so we never alert on the same
 * expiry every single day.
 */
function um_maybe_alert_expiry(PDO $pdo, array $site, string $type, ?DateTime $expiresAt, int $alertDays): void {
    if (!$expiresAt) {
        return;
    }

    $flagColumn = $type === 'ssl' ? 'ssl_alert_sent' : 'domain_alert_sent';
    $label = $type === 'ssl' ? 'SSL certificate' : 'Domain';

    $daysRemaining = (int)floor(($expiresAt->getTimestamp() - time()) / 86400);
    $isNearExpiry = $daysRemaining <= $alertDays;
    $alreadySent = (bool)$site[$flagColumn];

    if ($isNearExpiry && !$alreadySent) {
        $when = $daysRemaining < 0 ? 'has already expired' : "expires in {$daysRemaining} day(s)";
        $message = "{$label} for {$site['name']} {$when} — " . $expiresAt->format('Y-m-d') . "\n" .
            "URL: {$site['url']}";

        um_send_slack_alert(":warning: *{$label} expiring* — {$site['name']}\n" . $message);
        um_send_email_alert("{$label} expiring soon: {$site['name']}", $message);

        $pdo->prepare("UPDATE sites SET {$flagColumn} = 1 WHERE id = ?")->execute([$site['id']]);
    } elseif (!$isNearExpiry && $alreadySent) {
        $pdo->prepare("UPDATE sites SET {$flagColumn} = 0 WHERE id = ?")->execute([$site['id']]);
    }
}

/**
 * Runs a check for a single site right now (used by the admin "check now" action)
 * and returns the resulting status.
 */
function um_check_site_now(PDO $pdo, int $siteId): ?string {
    $stmt = $pdo->prepare("SELECT * FROM sites WHERE id = ?");
    $stmt->execute([$siteId]);
    $site = $stmt->fetch();
    if (!$site) return null;

    $results = um_check_sites_concurrently([$site], 1);
    if (!isset($results[$siteId])) return null;

    um_process_result($pdo, $siteId, $results[$siteId]);

    $stmt->execute([$siteId]);
    $updated = $stmt->fetch();
    return $updated ? $updated['current_status'] : null;
}

/**
 * Forces an immediate SSL/domain expiry refresh for one site, bypassing the
 * usual once-a-day gate (used by the admin "Refresh expiry" action).
 */
function um_check_site_expiry_now(PDO $pdo, int $siteId): bool {
    $stmt = $pdo->prepare("SELECT * FROM sites WHERE id = ?");
    $stmt->execute([$siteId]);
    $site = $stmt->fetch();
    if (!$site) {
        return false;
    }

    um_check_site_expiry($pdo, $site);
    return true;
}
