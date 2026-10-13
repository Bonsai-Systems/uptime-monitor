<?php
/**
 * Bonsai Uptime Monitor — read-only JSON summary for the desktop dashboard.
 *
 *   GET /api.php
 *   Authorization: Bearer <key>     (or X-Api-Key: <key>)
 *
 * The key is generated on settings.php ("Dashboard API") and only its
 * SHA-256 hash is stored, as `dashboard_api_key_hash`. With no key set the
 * endpoint answers 404 as if it didn't exist. Never changes anything.
 *
 * Times are ISO 8601 UTC. They're read with UNIX_TIMESTAMP() so they're
 * correct whatever time zone MySQL and PHP are each set to.
 */

header('X-Robots-Tag: noindex, nofollow', true);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true);
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/includes/settings.php';
require __DIR__ . '/includes/functions.php';

const UM_API_EXPIRY_DAYS = 30;  // SSL/domain expiring within this many days are listed.
const UM_API_RECENT_HOURS = 24; // Resolved incidents shown from this far back.

/**
 * Send a JSON response and stop.
 */
function um_api_respond(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * The key from the Authorization (Bearer) or X-Api-Key header. Not accepted
 * in the query string, so it never ends up in server access logs.
 */
function um_api_request_key(): string {
    $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) {
        return $m[1];
    }
    return trim((string)($_SERVER['HTTP_X_API_KEY'] ?? ''));
}

function um_api_iso(?string $epoch): ?string {
    return ($epoch === null || $epoch === '') ? null : gmdate('c', (int)$epoch);
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        um_api_respond(405, ['error' => 'Method not allowed.']);
    }

    $storedHash = (string)um_get_setting('dashboard_api_key_hash', '');
    if ($storedHash === '') {
        um_api_respond(404, ['error' => 'Not found.']);
    }

    $key = um_api_request_key();
    if ($key === '' || !hash_equals($storedHash, hash('sha256', $key))) {
        um_api_respond(401, ['error' => 'Invalid or missing API key.']);
    }

    $pdo = um_db();

    $sites = $pdo->query(
        "SELECT id, name, url, agency, current_status,
                UNIX_TIMESTAMP(last_checked_at) AS last_checked,
                monitor_ssl, UNIX_TIMESTAMP(ssl_expires_at) AS ssl_expires,
                monitor_domain, UNIX_TIMESTAMP(domain_expires_at) AS domain_expires
         FROM sites WHERE is_active = 1 ORDER BY name"
    )->fetchAll();

    // Open incident per site (latest), for "down since" and the cause.
    $open = [];
    foreach ($pdo->query(
        "SELECT site_id, UNIX_TIMESTAMP(started_at) AS started, cause
         FROM incidents WHERE resolved_at IS NULL ORDER BY started_at ASC"
    )->fetchAll() as $row) {
        $open[(int)$row['site_id']] = $row;
    }

    $counts = ['up' => 0, 'down' => 0, 'unknown' => 0];
    $down = [];
    $expiring = [];
    $now = time();

    foreach ($sites as $site) {
        $status = $site['current_status'];
        $counts[$status] = ($counts[$status] ?? 0) + 1;

        if ($status === 'down') {
            $incident = $open[(int)$site['id']] ?? null;
            $down[] = [
                'name' => $site['name'],
                'url' => $site['url'],
                'agency' => $site['agency'],
                'down_since' => $incident ? um_api_iso($incident['started']) : null,
                'cause' => $incident['cause'] ?? null,
            ];
        }

        foreach (['ssl' => 'ssl_expires', 'domain' => 'domain_expires'] as $type => $column) {
            if (!(int)$site['monitor_' . $type] || $site[$column] === null) {
                continue;
            }
            $days = (int)floor(((int)$site[$column] - $now) / 86400);
            if ($days <= UM_API_EXPIRY_DAYS) {
                $expiring[] = [
                    'name' => $site['name'],
                    'type' => $type,
                    'expires_at' => um_api_iso($site[$column]),
                    'days' => $days,
                ];
            }
        }
    }

    usort($expiring, fn($a, $b) => $a['days'] <=> $b['days']);
    usort($down, fn($a, $b) => strcmp((string)$a['down_since'], (string)$b['down_since']));

    $stmt = $pdo->prepare(
        "SELECT s.name, UNIX_TIMESTAMP(i.started_at) AS started,
                UNIX_TIMESTAMP(i.resolved_at) AS resolved, i.duration_seconds, i.cause
         FROM incidents i JOIN sites s ON s.id = i.site_id
         WHERE i.resolved_at IS NOT NULL AND i.resolved_at >= (NOW() - INTERVAL ? HOUR)
         ORDER BY i.resolved_at DESC LIMIT 5"
    );
    $stmt->bindValue(1, UM_API_RECENT_HOURS, PDO::PARAM_INT);
    $stmt->execute();
    $recent = array_map(fn($r) => [
        'name' => $r['name'],
        'started_at' => um_api_iso($r['started']),
        'resolved_at' => um_api_iso($r['resolved']),
        'duration_seconds' => $r['duration_seconds'] === null ? null : (int)$r['duration_seconds'],
        'cause' => $r['cause'],
    ], $stmt->fetchAll());

    $checked = array_filter(array_column($sites, 'last_checked'));

    um_api_respond(200, [
        'total' => count($sites),
        'up' => $counts['up'],
        'down' => $counts['down'],
        'unknown' => $counts['unknown'],
        'sites_down' => $down,
        'expiring' => $expiring,
        'recent_incidents' => $recent,
        'last_check_at' => $checked ? um_api_iso((string)max($checked)) : null,
        'generated_at' => gmdate('c'),
    ]);
} catch (Throwable $e) {
    error_log('Dashboard API error: ' . $e->getMessage());
    um_api_respond(500, ['error' => 'Something went wrong reading the monitor data.']);
}
