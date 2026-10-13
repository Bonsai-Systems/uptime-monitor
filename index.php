<?php
header('X-Robots-Tag: noindex, nofollow', true);
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/insights.php'; // display helpers + um_screenshot_url()
um_start_session();
$pdo = um_db();

$agencyFilter = (string)($_GET['agency'] ?? '');
if ($agencyFilter !== '' && in_array($agencyFilter, um_agencies(), true)) {
    $stmt = $pdo->prepare("SELECT * FROM sites WHERE is_active = 1 AND agency = ? ORDER BY name");
    $stmt->execute([$agencyFilter]);
    $sites = $stmt->fetchAll();
} else {
    $agencyFilter = '';
    $sites = $pdo->query("SELECT * FROM sites WHERE is_active = 1 ORDER BY name")->fetchAll();
}

$upCount = count(array_filter($sites, fn($s) => $s['current_status'] === 'up'));
$downCount = count(array_filter($sites, fn($s) => $s['current_status'] === 'down'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="robots" content="noindex, nofollow">
<title>Bonsai Uptime Monitor</title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<script>(function(){var t=localStorage.getItem('um-theme')||(window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');document.documentElement.setAttribute('data-theme',t);})();</script>
<link rel="stylesheet" href="assets/style.css">
<script src="assets/theme.js" defer></script>
</head>
<body>

<header class="site-header">
    <h1>Bonsai Uptime Monitor</h1>
    <div class="header-actions">
        <a href="#" onclick="location.reload(); return false;">Refresh</a>
        &nbsp;|&nbsp;
        <?php if (um_is_logged_in()): ?>
            <a href="admin.php">Manage sites</a>
            &nbsp;|&nbsp;
            <a href="settings.php">Settings</a>
            &nbsp;|&nbsp;
            <a href="logout.php">Log out</a>
        <?php else: ?>
            <a href="login.php">Log in</a>
        <?php endif; ?>
        <button type="button" id="theme-toggle" class="theme-toggle" aria-label="Toggle dark mode" title="Toggle dark mode">🌙</button>
    </div>
</header>

<div class="container">

    <div class="summary-bar">
        <div class="summary-card">
            <div class="value"><?= count($sites) ?></div>
            <div class="label">Sites monitored</div>
        </div>
        <div class="summary-card">
            <div class="value" style="color:#2ecc71;"><?= $upCount ?></div>
            <div class="label">Currently up</div>
        </div>
        <div class="summary-card">
            <div class="value" style="color:#ee4367;"><?= $downCount ?></div>
            <div class="label">Currently down</div>
        </div>
    </div>

    <div class="agency-tabs" role="group" aria-label="Filter by agency">
        <a href="index.php" class="agency-tab <?= $agencyFilter === '' ? 'active' : '' ?>">All</a>
        <?php foreach (um_agencies() as $agencyOption): ?>
            <a href="index.php?agency=<?= urlencode($agencyOption) ?>" class="agency-tab <?= $agencyFilter === $agencyOption ? 'active' : '' ?>"><?= htmlspecialchars($agencyOption) ?></a>
        <?php endforeach; ?>
    </div>

    <div class="site-grid">
        <?php foreach ($sites as $site): ?>
            <?php
                $uptime30 = um_uptime_percent($site['id'], 30);
                $avgResp = um_avg_response_time($site['id'], 1);
                $screenshotUrl = um_screenshot_url((int)$site['id']);
                $mobileScore = $site['psi_mobile_score'] !== null ? (int)$site['psi_mobile_score'] : null;
                $desktopScore = $site['psi_desktop_score'] !== null ? (int)$site['psi_desktop_score'] : null;
                $psiTitle = $site['psi_checked_at']
                    ? 'PageSpeed performance score, tested ' . date('j M Y', strtotime($site['psi_checked_at']))
                    : 'PageSpeed performance score — not tested yet';
            ?>
            <a class="site-card <?= htmlspecialchars($site['current_status']) ?>" href="site.php?id=<?= $site['id'] ?>">
                <div class="shot">
                    <?php if ($screenshotUrl): ?>
                        <img src="<?= htmlspecialchars($screenshotUrl) ?>" alt="Screenshot of <?= htmlspecialchars($site['name']) ?> homepage" width="400" height="250" loading="lazy" decoding="async">
                    <?php else: ?>
                        <span class="shot-empty">Screenshot pending</span>
                    <?php endif; ?>
                </div>
                <div class="body">
                    <div class="name"><?= htmlspecialchars($site['name']) ?></div>
                    <div class="url"><?= htmlspecialchars($site['url']) ?></div>
                    <span class="status-pill <?= htmlspecialchars($site['current_status']) ?>"><?= htmlspecialchars($site['current_status']) ?></span>
                    <div class="stats">
                        <div>
                            <strong><?= $uptime30 ?>%</strong>
                            30-day uptime
                        </div>
                        <div>
                            <strong><?= $avgResp !== null ? $avgResp . 'ms' : '—' ?></strong>
                            Avg response
                        </div>
                        <div title="<?= htmlspecialchars($psiTitle) ?>">
                            <strong class="score <?= um_score_status($mobileScore) ?>"><?= $mobileScore ?? '—' ?></strong>
                            Mobile
                        </div>
                        <div title="<?= htmlspecialchars($psiTitle) ?>">
                            <strong class="score <?= um_score_status($desktopScore) ?>"><?= $desktopScore ?? '—' ?></strong>
                            Desktop
                        </div>
                    </div>
                    <div class="meta">
                        Content updated:
                        <?php if ($site['content_updated_at']): ?>
                            <strong><?= htmlspecialchars(um_format_ago($site['content_updated_at'])) ?></strong>
                            (<?= htmlspecialchars(date('j M Y', strtotime($site['content_updated_at']))) ?>)
                        <?php else: ?>
                            <strong>—</strong>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>

        <?php if (empty($sites) && $agencyFilter !== ''): ?>
            <p>No sites for this agency yet.</p>
        <?php elseif (empty($sites)): ?>
            <p>No sites yet — add rows to the <code>sites</code> table to get started.</p>
        <?php endif; ?>
    </div>

</div>

</body>
</html>
