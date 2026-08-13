<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';

/**
 * The fixed list of agencies sites can be attributed to. Kept in one place
 * so the admin form dropdown, input validation, and dashboard filter tabs
 * can't drift out of sync — matches the `agency` ENUM in schema.sql.
 */
function um_agencies(): array {
    return ['Bonsai Digital', 'SPS Marketing', 'Webheads', 'Realityhouse', 'Seedbed'];
}

/**
 * Uptime % for a site over the last N days, based on logged checks.
 */
function um_uptime_percent(int $siteId, int $days): float {
    $pdo = um_db();
    $stmt = $pdo->prepare(
        "SELECT
            SUM(status = 'up') AS up_count,
            COUNT(*) AS total_count
         FROM checks
         WHERE site_id = ? AND checked_at >= (NOW() - INTERVAL ? DAY)"
    );
    $stmt->execute([$siteId, $days]);
    $row = $stmt->fetch();

    if (!$row || (int)$row['total_count'] === 0) {
        return 100.0; // no data yet — assume fine rather than scaring people with 0%
    }

    return round(((int)$row['up_count'] / (int)$row['total_count']) * 100, 3);
}

/**
 * Average response time (ms) over the last N days.
 */
function um_avg_response_time(int $siteId, int $days): ?int {
    $pdo = um_db();
    $stmt = $pdo->prepare(
        "SELECT AVG(response_time_ms) AS avg_ms
         FROM checks
         WHERE site_id = ? AND checked_at >= (NOW() - INTERVAL ? DAY) AND status = 'up'"
    );
    $stmt->execute([$siteId, $days]);
    $row = $stmt->fetch();
    return $row && $row['avg_ms'] !== null ? (int)round($row['avg_ms']) : null;
}

/**
 * Recent checks for the response-time graph.
 */
function um_recent_checks(int $siteId, int $limit = 100): array {
    $pdo = um_db();
    $stmt = $pdo->prepare(
        "SELECT checked_at, status, http_code, response_time_ms
         FROM checks
         WHERE site_id = ?
         ORDER BY checked_at DESC
         LIMIT ?"
    );
    $stmt->bindValue(1, $siteId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return array_reverse($stmt->fetchAll());
}

/**
 * Response-time series for the chart, over a given lookback window.
 * Downsamples to $maxPoints so a 30-day window with frequent checks doesn't
 * ship thousands of points to the browser. Label format switches to include
 * the date once the window is longer than a day.
 */
function um_response_time_series(int $siteId, int $hours, int $maxPoints = 120): array {
    $pdo = um_db();
    $stmt = $pdo->prepare(
        "SELECT checked_at, response_time_ms
         FROM checks
         WHERE site_id = ? AND checked_at >= (NOW() - INTERVAL ? HOUR)
         ORDER BY checked_at ASC"
    );
    $stmt->bindValue(1, $siteId, PDO::PARAM_INT);
    $stmt->bindValue(2, $hours, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $count = count($rows);
    if ($count > $maxPoints) {
        $step = (int)ceil($count / $maxPoints);
        $sampled = [];
        for ($i = 0; $i < $count; $i += $step) {
            $sampled[] = $rows[$i];
        }
        $last = $rows[$count - 1];
        if (end($sampled) !== $last) {
            $sampled[] = $last;
        }
        $rows = $sampled;
    }

    $format = $hours <= 24 ? 'H:i' : 'd M H:i';

    return [
        'labels' => array_map(fn($r) => date($format, strtotime($r['checked_at'])), $rows),
        'data'   => array_map(fn($r) => $r['response_time_ms'], $rows),
    ];
}

function um_incidents(int $siteId, int $limit = 20): array {
    $pdo = um_db();
    $stmt = $pdo->prepare(
        "SELECT * FROM incidents
         WHERE site_id = ?
         ORDER BY started_at DESC
         LIMIT ?"
    );
    $stmt->bindValue(1, $siteId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Whole days remaining until a stored DATETIME string, or null if unknown.
 * Can be negative if the date has already passed.
 */
function um_days_until(?string $datetime): ?int {
    if (!$datetime) {
        return null;
    }
    return (int)floor((strtotime($datetime) - time()) / 86400);
}

/**
 * Maps days-remaining to a CSS status class for expiry badges — 'unknown'
 * when we have no data, 'critical' at/under a week or already expired,
 * 'warn' inside the general warning window, otherwise 'ok'.
 */
function um_expiry_status(?int $daysRemaining, int $warnDays = 30, int $criticalDays = 7): string {
    if ($daysRemaining === null) {
        return 'unknown';
    }
    if ($daysRemaining <= $criticalDays) {
        return 'critical';
    }
    if ($daysRemaining <= $warnDays) {
        return 'warn';
    }
    return 'ok';
}

function um_format_duration(?int $seconds): string {
    if ($seconds === null) return '—';
    if ($seconds < 60) return $seconds . 's';
    if ($seconds < 3600) return round($seconds / 60) . 'm';
    if ($seconds < 86400) return round($seconds / 3600, 1) . 'h';
    return round($seconds / 86400, 1) . 'd';
}

function um_send_slack_alert(string $message): void {
    $url = (string)um_get_setting('slack_webhook_url', '');
    if (empty($url) || str_contains($url, 'XXXX')) return; // not configured yet

    $payload = json_encode(['text' => $message]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

/**
 * Sends a plain-text alert email via PHP's built-in mail(). Fails silently
 * (logged, not thrown) if not configured or if the host's mail() is unavailable —
 * an alert failure should never take the checker cron down.
 */
function um_send_email_alert(string $subject, string $message): void {
    $to = trim((string)um_get_setting('alert_email', ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return; // not configured yet

    if (!function_exists('mail')) {
        error_log('um_send_email_alert: mail() is not available on this host.');
        return;
    }

    $from = trim((string)um_get_setting('alert_email_from', 'alerts@localhost'));
    $headers = "From: {$from}\r\nContent-Type: text/plain; charset=utf-8";

    $sent = @mail($to, $subject, $message, $headers);
    if (!$sent) {
        error_log("um_send_email_alert: mail() failed sending to {$to}.");
    }
}
