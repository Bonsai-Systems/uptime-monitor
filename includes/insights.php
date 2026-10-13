<?php
require_once __DIR__ . '/checks.php';

/**
 * Site "insights" — slow-cadence, non-alerting data shown on the dashboard
 * cards. Two sources:
 *
 *  - Google PageSpeed Insights: mobile + desktop performance scores, plus the
 *    desktop run's final screenshot (one API covers both). Slow (10–30s per
 *    call) and quota-limited, so refreshed roughly monthly per site, a small
 *    batch per cron run.
 *  - WordPress public REST API: the most recent post/page modified date, as
 *    "last content update". No login needed; no-ops gracefully on non-WP
 *    sites or sites with the REST API blocked.
 *
 * Failures are logged/stored, never thrown — same rule as the alert channels.
 */

const UM_SCREENSHOT_DIR = __DIR__ . '/../screenshots';
const UM_PSI_ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

/**
 * GETs a URL and decodes the JSON body. Returns ['data' => ?array,
 * 'http_code' => int, 'error' => ?string]; data may be set alongside an
 * error (e.g. a 4xx with a JSON error body).
 */
function um_http_get_json(string $url, int $timeoutSeconds): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'BonsaiUptimeMonitor/1.0',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['data' => null, 'http_code' => $code, 'error' => $curlError ?: 'Request failed'];
    }

    $data = json_decode((string)$body, true);
    if (!is_array($data)) {
        return ['data' => null, 'http_code' => $code, 'error' => "HTTP {$code}, response was not JSON"];
    }

    return ['data' => $data, 'http_code' => $code, 'error' => $code >= 400 ? "HTTP {$code}" : null];
}

/**
 * Runs one PageSpeed Insights test. Returns ['score' => ?int (0–100),
 * 'screenshot' => ?string (raw JPEG bytes), 'error' => ?string].
 */
function um_fetch_pagespeed(string $siteUrl, string $strategy, string $apiKey): array {
    $query = [
        'url' => $siteUrl,
        'strategy' => $strategy,
        'category' => 'performance',
    ];
    if ($apiKey !== '') {
        $query['key'] = $apiKey;
    }

    // PSI runs a full Lighthouse audit server-side — 30s+ isn't unusual.
    $response = um_http_get_json(UM_PSI_ENDPOINT . '?' . http_build_query($query), 120);
    $data = $response['data'];

    if ($response['error'] !== null) {
        $apiMessage = is_array($data) ? (string)($data['error']['message'] ?? '') : '';
        return ['score' => null, 'screenshot' => null, 'error' => trim($response['error'] . ' ' . $apiMessage)];
    }

    $rawScore = $data['lighthouseResult']['categories']['performance']['score'] ?? null;
    $score = is_numeric($rawScore) ? (int)round((float)$rawScore * 100) : null;

    // final-screenshot is a data URI: "data:image/jpeg;base64,...."
    $screenshot = null;
    $dataUri = (string)($data['lighthouseResult']['audits']['final-screenshot']['details']['data'] ?? '');
    if (preg_match('#^data:image/jpeg;base64,(.+)$#s', $dataUri, $m)) {
        $decoded = base64_decode($m[1], true);
        $screenshot = $decoded !== false ? $decoded : null;
    }

    if ($score === null) {
        // Lighthouse can "succeed" with a null score when the page errored (e.g. NO_FCP).
        $runtimeError = (string)($data['lighthouseResult']['runtimeError']['message'] ?? 'No performance score returned');
        return ['score' => null, 'screenshot' => $screenshot, 'error' => $runtimeError];
    }

    return ['score' => $score, 'screenshot' => $screenshot, 'error' => null];
}

/**
 * Path on disk for a site's screenshot.
 */
function um_screenshot_file(int $siteId): string {
    return UM_SCREENSHOT_DIR . '/site-' . $siteId . '.jpg';
}

/**
 * Public URL (relative to the app root) for a site's screenshot, cache-busted
 * by file mtime, or null if none has been captured yet.
 */
function um_screenshot_url(int $siteId): ?string {
    $file = um_screenshot_file($siteId);
    if (!is_file($file)) {
        return null;
    }
    return 'screenshots/site-' . $siteId . '.jpg?v=' . filemtime($file);
}

/**
 * Writes screenshot bytes atomically (temp file + rename) so the dashboard
 * never serves a half-written image.
 */
function um_save_screenshot(int $siteId, string $jpegBytes): bool {
    if (!is_dir(UM_SCREENSHOT_DIR)) {
        if (!@mkdir(UM_SCREENSHOT_DIR, 0755, true) && !is_dir(UM_SCREENSHOT_DIR)) {
            error_log('um_save_screenshot: could not create ' . UM_SCREENSHOT_DIR);
            return false;
        }
        // Stop the directory being listed on hosts with Indexes enabled.
        @file_put_contents(UM_SCREENSHOT_DIR . '/index.html', '');
    }

    $target = um_screenshot_file($siteId);
    $tmp = $target . '.tmp';
    if (@file_put_contents($tmp, $jpegBytes) === false || !@rename($tmp, $target)) {
        @unlink($tmp);
        error_log("um_save_screenshot: could not write {$target}");
        return false;
    }
    return true;
}

/**
 * Removes a site's screenshot (used when a site is deleted).
 */
function um_delete_screenshot(int $siteId): void {
    $file = um_screenshot_file($siteId);
    if (is_file($file)) {
        @unlink($file);
    }
}

/**
 * Runs mobile + desktop PageSpeed tests for one site and stores the scores
 * and desktop screenshot. A failed strategy keeps its previous score rather
 * than blanking it. Returns null on full success, or an error summary.
 */
function um_refresh_site_pagespeed(PDO $pdo, array $site): ?string {
    $apiKey = trim((string)um_get_setting('pagespeed_api_key', ''));
    $errors = [];
    $scores = [];

    foreach (['mobile', 'desktop'] as $strategy) {
        $result = um_fetch_pagespeed($site['url'], $strategy, $apiKey);
        if ($result['error'] !== null) {
            $errors[] = "{$strategy}: {$result['error']}";
        }
        $scores[$strategy] = $result['score'];

        if ($strategy === 'desktop' && $result['screenshot'] !== null) {
            um_save_screenshot((int)$site['id'], $result['screenshot']);
        }
    }

    $error = empty($errors) ? null : substr(implode('; ', $errors), 0, 500);
    if ($error !== null) {
        error_log("um_refresh_site_pagespeed: {$site['url']} — {$error}");
    }

    $anySuccess = $scores['mobile'] !== null || $scores['desktop'] !== null;

    // COALESCE keeps the previous score for a strategy that failed this time.
    // psi_checked_at = last run with at least one score; psi_attempted_at
    // drives the retry gate in um_refresh_insights_due().
    $pdo->prepare(
        "UPDATE sites SET
            psi_mobile_score = COALESCE(?, psi_mobile_score),
            psi_desktop_score = COALESCE(?, psi_desktop_score),
            psi_checked_at = " . ($anySuccess ? 'NOW()' : 'psi_checked_at') . ",
            psi_attempted_at = NOW(),
            psi_error = ?
         WHERE id = ?"
    )->execute([$scores['mobile'], $scores['desktop'], $error, $site['id']]);

    return $error;
}

/**
 * Most recent modified date across WordPress posts and pages via the public
 * REST API. Returns ['updated_at' => ?DateTime (server timezone), 'error' => ?string].
 */
function um_fetch_last_content_update(string $siteUrl): array {
    $latest = null;
    $errors = [];

    foreach (['posts', 'pages'] as $type) {
        $url = um_build_path_url($siteUrl, "/wp-json/wp/v2/{$type}?orderby=modified&order=desc&per_page=1&_fields=modified_gmt");
        $response = um_http_get_json($url, 15);

        if ($response['error'] !== null) {
            $errors[] = "{$type}: {$response['error']}";
            continue;
        }

        $modified = (string)($response['data'][0]['modified_gmt'] ?? '');
        if ($modified === '') {
            continue; // e.g. a site with no posts at all — not an error
        }

        try {
            $date = new DateTime($modified, new DateTimeZone('UTC'));
            $date->setTimezone(new DateTimeZone(date_default_timezone_get()));
        } catch (Exception $e) {
            $errors[] = "{$type}: unreadable date '{$modified}'";
            continue;
        }

        if ($latest === null || $date > $latest) {
            $latest = $date;
        }
    }

    // Only an error if we got nothing usable at all.
    $error = ($latest === null && !empty($errors)) ? implode('; ', $errors) : null;
    return ['updated_at' => $latest, 'error' => $error];
}

/**
 * Refreshes one site's last content update date. Keeps the previous value if
 * the REST API couldn't be reached this time. Returns an error or null.
 */
function um_refresh_site_content_date(PDO $pdo, array $site): ?string {
    $result = um_fetch_last_content_update($site['url']);
    $updatedAt = $result['updated_at'] ? $result['updated_at']->format('Y-m-d H:i:s') : null;

    $pdo->prepare(
        "UPDATE sites SET content_updated_at = COALESCE(?, content_updated_at), content_checked_at = NOW() WHERE id = ?"
    )->execute([$updatedAt, $site['id']]);

    return $result['error'];
}

/**
 * Runs whatever insights are due. Content dates: roughly once a day per site.
 * PageSpeed: every 30 days after a success, next day after a failure, at most
 * $psiBatchSize sites per call so one cron run stays a few minutes long.
 */
function um_refresh_insights_due(PDO $pdo, int $psiBatchSize): void {
    $contentDue = $pdo->query(
        "SELECT * FROM sites
         WHERE is_active = 1
           AND (content_checked_at IS NULL OR content_checked_at <= (NOW() - INTERVAL 20 HOUR))"
    )->fetchAll();

    foreach ($contentDue as $site) {
        um_refresh_site_content_date($pdo, $site);
    }

    // Clamped int inlined into LIMIT — comes from a validated setting, never
    // from request input.
    $limit = max(1, min(20, $psiBatchSize));
    $psiDue = $pdo->query(
        "SELECT * FROM sites
         WHERE is_active = 1
           AND (
                psi_attempted_at IS NULL
                OR (psi_error IS NULL AND psi_attempted_at <= (NOW() - INTERVAL 30 DAY))
                OR (psi_error IS NOT NULL AND psi_attempted_at <= (NOW() - INTERVAL 1 DAY))
           )
         ORDER BY psi_attempted_at IS NOT NULL, psi_attempted_at ASC
         LIMIT {$limit}"
    )->fetchAll();

    foreach ($psiDue as $site) {
        um_refresh_site_pagespeed($pdo, $site);
    }
}

/**
 * Forces an immediate PageSpeed + content refresh for one site, bypassing
 * both gates (admin "Refresh insights" action). Returns null if the site
 * doesn't exist, otherwise a list of error strings (empty on full success).
 */
function um_refresh_site_insights_now(PDO $pdo, int $siteId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM sites WHERE id = ?");
    $stmt->execute([$siteId]);
    $site = $stmt->fetch();
    if (!$site) {
        return null;
    }

    $errors = [];
    if ($error = um_refresh_site_content_date($pdo, $site)) {
        $errors[] = 'Content date — ' . $error;
    }
    if ($error = um_refresh_site_pagespeed($pdo, $site)) {
        $errors[] = 'PageSpeed — ' . $error;
    }
    return $errors;
}
