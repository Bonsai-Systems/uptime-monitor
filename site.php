<?php
header('X-Robots-Tag: noindex, nofollow', true);
// Live status data — never let host/CDN caches (e.g. SiteGround Dynamic Cache) serve a stale copy.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true);
require __DIR__ . '/includes/functions.php';
$pdo = um_db();

$siteId = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM sites WHERE id = ?");
$stmt->execute([$siteId]);
$site = $stmt->fetch();

if (!$site) {
    http_response_code(404);
    echo "Site not found.";
    exit;
}

$uptime24h = um_uptime_percent($siteId, 1);
$uptime7d  = um_uptime_percent($siteId, 7);
$uptime30d = um_uptime_percent($siteId, 30);
$avgResp   = um_avg_response_time($siteId, 7);
$incidents = um_incidents($siteId, 20);
$sitePaths = array_filter(um_site_paths($siteId), fn($p) => $p['is_active']);

$sslDays    = um_days_until($site['ssl_expires_at']);
$domainDays = um_days_until($site['domain_expires_at']);

$psiScores = [
    'Mobile'  => $site['psi_mobile_score'] !== null ? (int)$site['psi_mobile_score'] : null,
    'Desktop' => $site['psi_desktop_score'] !== null ? (int)$site['psi_desktop_score'] : null,
];

$chartRanges = [
    '24h' => um_response_time_series($siteId, 24),
    '7d'  => um_response_time_series($siteId, 24 * 7),
    '30d' => um_response_time_series($siteId, 24 * 30),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="robots" content="noindex, nofollow">
<title><?= htmlspecialchars($site['name']) ?> — Bonsai Uptime Monitor</title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<script>(function(){var t=localStorage.getItem('um-theme')||(window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');document.documentElement.setAttribute('data-theme',t);})();</script>
<link rel="stylesheet" href="assets/style.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.5.0/chart.umd.min.js"></script>
<script src="assets/theme.js" defer></script>
</head>
<body>

<header class="site-header">
    <h1><?= htmlspecialchars($site['name']) ?></h1>
    <div class="header-actions">
        <span class="status-pill <?= htmlspecialchars($site['current_status']) ?>"><?= htmlspecialchars($site['current_status']) ?></span>
        <button type="button" id="theme-toggle" class="theme-toggle" aria-label="Toggle dark mode" title="Toggle dark mode">🌙</button>
    </div>
</header>

<div class="container">

    <a class="back-link" href="index.php">&larr; All sites</a>

    <div class="summary-bar">
        <div class="summary-card">
            <div class="value"><?= $uptime24h ?>%</div>
            <div class="label">Uptime (24h)</div>
        </div>
        <div class="summary-card">
            <div class="value"><?= $uptime7d ?>%</div>
            <div class="label">Uptime (7d)</div>
        </div>
        <div class="summary-card">
            <div class="value"><?= $uptime30d ?>%</div>
            <div class="label">Uptime (30d)</div>
        </div>
        <div class="summary-card">
            <div class="value"><?= $avgResp !== null ? $avgResp . 'ms' : '—' ?></div>
            <div class="label">Avg response (7d)</div>
        </div>
    </div>

    <div class="chart-wrap">
        <div class="chart-range-toggle" role="group" aria-label="Chart time range">
            <button type="button" class="chart-range-btn active" data-range="24h">24h</button>
            <button type="button" class="chart-range-btn" data-range="7d">7d</button>
            <button type="button" class="chart-range-btn" data-range="30d">30d</button>
        </div>
        <canvas id="respChart" height="80"></canvas>
    </div>

    <div class="summary-bar">
        <div class="summary-card">
            <div class="label">SSL certificate</div>
            <?php if (!$site['monitor_ssl']): ?>
                <div class="value expiry-unknown">Not monitored</div>
            <?php elseif ($sslDays === null): ?>
                <div class="value expiry-unknown">Unknown</div>
                <div class="label">Lookup failed or not yet checked</div>
            <?php else: ?>
                <div class="value expiry-<?= um_expiry_status($sslDays) ?>">
                    <?= $sslDays < 0 ? 'Expired' : $sslDays . 'd' ?>
                </div>
                <div class="label">Expires <?= htmlspecialchars(date('d M Y', strtotime($site['ssl_expires_at']))) ?></div>
            <?php endif; ?>
        </div>
        <div class="summary-card">
            <div class="label">Domain registration</div>
            <?php if (!$site['monitor_domain']): ?>
                <div class="value expiry-unknown">Not monitored</div>
            <?php elseif ($domainDays === null): ?>
                <div class="value expiry-unknown">Unknown</div>
                <div class="label">Lookup failed or not yet checked</div>
            <?php else: ?>
                <div class="value expiry-<?= um_expiry_status($domainDays) ?>">
                    <?= $domainDays < 0 ? 'Expired' : $domainDays . 'd' ?>
                </div>
                <div class="label">Expires <?= htmlspecialchars(date('d M Y', strtotime($site['domain_expires_at']))) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="summary-bar">
        <?php foreach ($psiScores as $strategy => $score): ?>
            <div class="summary-card">
                <div class="label">PageSpeed (<?= strtolower($strategy) ?>)</div>
                <div class="value"><span class="score <?= um_score_status($score) ?>"><?= $score ?? '—' ?></span></div>
                <?php if ($site['psi_checked_at']): ?>
                    <div class="label">Tested <?= htmlspecialchars(date('d M Y', strtotime($site['psi_checked_at']))) ?></div>
                <?php else: ?>
                    <div class="label">Not tested yet</div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <div class="summary-card">
            <div class="label">Content updated</div>
            <?php if ($site['content_updated_at']): ?>
                <div class="value"><?= htmlspecialchars(um_format_ago($site['content_updated_at'])) ?></div>
                <div class="label"><?= htmlspecialchars(date('d M Y', strtotime($site['content_updated_at']))) ?></div>
            <?php else: ?>
                <div class="value expiry-unknown">Unknown</div>
                <div class="label">Not WordPress, REST API blocked, or not yet checked</div>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($site['psi_error'] !== null): ?>
        <p class="insight-note">The latest PageSpeed test didn't complete, so these are the last good scores. It will try again within a day.</p>
    <?php endif; ?>

    <?php if (!empty($sitePaths)): ?>
    <h3>Check paths</h3>
    <table class="incidents">
        <thead>
            <tr>
                <th>Path</th>
                <th>Status</th>
                <th>Last checked</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($sitePaths as $path): ?>
                <tr>
                    <td><code><?= htmlspecialchars($path['label'] ?: $path['path']) ?></code></td>
                    <td><span class="status-pill <?= htmlspecialchars($path['current_status']) ?>"><?= htmlspecialchars($path['current_status']) ?></span></td>
                    <td><?= $path['last_checked_at'] ? htmlspecialchars($path['last_checked_at']) : 'Never' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <h3>Recent incidents</h3>
    <table class="incidents">
        <thead>
            <tr>
                <th>Started</th>
                <th>Resolved</th>
                <th>Duration</th>
                <th>Cause</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($incidents as $inc): ?>
                <tr>
                    <td><?= htmlspecialchars($inc['started_at']) ?></td>
                    <td><?= $inc['resolved_at'] ? htmlspecialchars($inc['resolved_at']) : 'Ongoing' ?></td>
                    <td><?= um_format_duration($inc['duration_seconds']) ?></td>
                    <td><?= htmlspecialchars($inc['cause'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($incidents)): ?>
                <tr><td colspan="4">No incidents logged — clean record.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

</div>

<script>
(function () {
    var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    Chart.defaults.color = isDark ? '#9aa3af' : '#374151';
    Chart.defaults.borderColor = isDark ? '#333944' : '#e5e7eb';

    var chartRanges = <?= json_encode($chartRanges) ?>;

    var chart = new Chart(document.getElementById('respChart'), {
        type: 'line',
        data: {
            labels: chartRanges['24h'].labels,
            datasets: [{
                label: 'Response time (ms)',
                data: chartRanges['24h'].data,
                borderColor: '#ee4367',
                backgroundColor: 'rgba(238,67,103,0.08)',
                fill: true,
                tension: 0.25,
                pointRadius: 0,
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, title: { display: true, text: 'ms' } }
            }
        }
    });

    var buttons = document.querySelectorAll('.chart-range-btn');
    buttons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var range = btn.getAttribute('data-range');
            if (!chartRanges[range]) {
                return;
            }
            buttons.forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            chart.data.labels = chartRanges[range].labels;
            chart.data.datasets[0].data = chartRanges[range].data;
            chart.update();
        });
    });
})();
</script>

</body>
</html>
