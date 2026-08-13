<?php
header('X-Robots-Tag: noindex, nofollow', true);
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/checks.php';

um_require_login();

$pdo = um_db();
$errors = [];
$notice = '';

/**
 * Validates the add/edit site form. Returns a clean data array on success,
 * or throws via $errors on failure (caller checks empty($errors) before saving).
 */
function um_validate_site_input(array $input): array {
    global $errors;

    $name = trim((string)($input['name'] ?? ''));
    $url = trim((string)($input['url'] ?? ''));
    $interval = (int)($input['check_interval_seconds'] ?? 300);
    $expectedCode = (int)($input['expected_status_code'] ?? 200);
    $timeout = (int)($input['timeout_seconds'] ?? 15);
    $isActive = !empty($input['is_active']) ? 1 : 0;
    $monitorSsl = !empty($input['monitor_ssl']) ? 1 : 0;
    $monitorDomain = !empty($input['monitor_domain']) ? 1 : 0;
    $agency = trim((string)($input['agency'] ?? ''));

    if ($name === '' || mb_strlen($name) > 255) {
        $errors[] = 'Name is required and must be under 255 characters.';
    }

    if (!in_array($agency, um_agencies(), true)) {
        $errors[] = 'Please select a valid agency.';
    }

    if ($url === '' || mb_strlen($url) > 500 || !filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
        $errors[] = 'A valid http(s):// URL is required.';
    }

    if ($interval < 30 || $interval > 86400) {
        $errors[] = 'Check interval must be between 30 and 86400 seconds.';
    }

    if ($expectedCode < 100 || $expectedCode > 599) {
        $errors[] = 'Expected status code must be a valid HTTP status code.';
    }

    if ($timeout < 1 || $timeout > 60) {
        $errors[] = 'Timeout must be between 1 and 60 seconds.';
    }

    return [
        'name' => $name,
        'url' => $url,
        'check_interval_seconds' => $interval,
        'expected_status_code' => $expectedCode,
        'timeout_seconds' => $timeout,
        'is_active' => $isActive,
        'monitor_ssl' => $monitorSsl,
        'monitor_domain' => $monitorDomain,
        'agency' => $agency,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!um_verify_csrf()) {
        $errors[] = 'Session expired — please try again.';
    } else {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'add') {
            $data = um_validate_site_input($_POST);
            if (empty($errors)) {
                $pdo->prepare(
                    "INSERT INTO sites (name, url, check_interval_seconds, expected_status_code, timeout_seconds, is_active, monitor_ssl, monitor_domain, agency)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                )->execute([
                    $data['name'], $data['url'], $data['check_interval_seconds'],
                    $data['expected_status_code'], $data['timeout_seconds'], $data['is_active'],
                    $data['monitor_ssl'], $data['monitor_domain'], $data['agency'],
                ]);
                $notice = 'Site added.';
            }
        }

        if ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $data = um_validate_site_input($_POST);
            if (empty($errors) && $id > 0) {
                $pdo->prepare(
                    "UPDATE sites SET name = ?, url = ?, check_interval_seconds = ?, expected_status_code = ?, timeout_seconds = ?, is_active = ?, monitor_ssl = ?, monitor_domain = ?, agency = ?
                     WHERE id = ?"
                )->execute([
                    $data['name'], $data['url'], $data['check_interval_seconds'],
                    $data['expected_status_code'], $data['timeout_seconds'], $data['is_active'],
                    $data['monitor_ssl'], $data['monitor_domain'], $data['agency'], $id,
                ]);
                $notice = 'Site updated.';
            }
        }

        if ($action === 'toggle_active') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare("UPDATE sites SET is_active = 1 - is_active WHERE id = ?")->execute([$id]);
                $notice = 'Site status changed.';
            }
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare("DELETE FROM sites WHERE id = ?")->execute([$id]);
                $notice = 'Site deleted.';
            }
        }

        if ($action === 'check_now') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $status = um_check_site_now($pdo, $id);
                $notice = $status ? "Checked — now {$status}." : 'Site not found.';
            }
        }

        if ($action === 'check_expiry_now') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $ok = um_check_site_expiry_now($pdo, $id);
                $notice = $ok ? 'SSL/domain expiry refreshed.' : 'Site not found.';
            }
        }
    }
}

$editSite = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM sites WHERE id = ?");
    $stmt->execute([(int)$_GET['edit']]);
    $editSite = $stmt->fetch();
}

$agencyFilter = (string)($_GET['agency'] ?? '');
if ($agencyFilter !== '' && in_array($agencyFilter, um_agencies(), true)) {
    $stmt = $pdo->prepare("SELECT * FROM sites WHERE agency = ? ORDER BY name");
    $stmt->execute([$agencyFilter]);
    $sites = $stmt->fetchAll();
} else {
    $agencyFilter = '';
    $sites = $pdo->query("SELECT * FROM sites ORDER BY name")->fetchAll();
}
$csrfToken = um_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="robots" content="noindex, nofollow">
<title>Manage sites — Bonsai Uptime Monitor</title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<script>(function(){var t=localStorage.getItem('um-theme')||(window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');document.documentElement.setAttribute('data-theme',t);})();</script>
<link rel="stylesheet" href="assets/style.css">
<script src="assets/theme.js" defer></script>
</head>
<body>

<header class="site-header">
    <h1>Manage sites</h1>
    <div class="header-actions">
        <a href="index.php">Dashboard</a>
        &nbsp;|&nbsp;
        <a href="settings.php">Settings</a>
        &nbsp;|&nbsp;
        <a href="logout.php">Log out</a>
        <button type="button" id="theme-toggle" class="theme-toggle" aria-label="Toggle dark mode" title="Toggle dark mode">🌙</button>
    </div>
</header>

<div class="container">

    <a class="back-link" href="index.php">&larr; All sites</a>

    <?php if ($notice): ?>
        <p class="form-notice"><?= htmlspecialchars($notice) ?></p>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="form-error">
            <ul>
                <?php foreach ($errors as $err): ?>
                    <li><?= htmlspecialchars($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="admin-card">
        <h2><?= $editSite ? 'Edit site' : 'Add a site' ?></h2>
        <form method="post" action="admin.php<?= $editSite ? '?edit=' . (int)$editSite['id'] : '' ?>" class="site-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="<?= $editSite ? 'update' : 'add' ?>">
            <?php if ($editSite): ?>
                <input type="hidden" name="id" value="<?= (int)$editSite['id'] ?>">
            <?php endif; ?>

            <label for="name">Name</label>
            <input type="text" id="name" name="name" maxlength="255" required
                   value="<?= htmlspecialchars($editSite['name'] ?? ($_POST['name'] ?? '')) ?>">

            <label for="url">URL</label>
            <input type="url" id="url" name="url" maxlength="500" placeholder="https://clientsite.com" required
                   value="<?= htmlspecialchars($editSite['url'] ?? ($_POST['url'] ?? '')) ?>">

            <?php $selectedAgency = $editSite['agency'] ?? ($_POST['agency'] ?? ''); ?>
            <label for="agency">Agency</label>
            <select id="agency" name="agency" required>
                <option value="" disabled <?= $selectedAgency === '' ? 'selected' : '' ?>>Select an agency&hellip;</option>
                <?php foreach (um_agencies() as $agencyOption): ?>
                    <option value="<?= htmlspecialchars($agencyOption) ?>" <?= $selectedAgency === $agencyOption ? 'selected' : '' ?>><?= htmlspecialchars($agencyOption) ?></option>
                <?php endforeach; ?>
            </select>

            <div class="form-row">
                <div>
                    <label for="check_interval_seconds">Check interval (seconds)</label>
                    <input type="number" id="check_interval_seconds" name="check_interval_seconds" min="30" max="86400"
                           value="<?= (int)($editSite['check_interval_seconds'] ?? ($_POST['check_interval_seconds'] ?? 300)) ?>">
                </div>
                <div>
                    <label for="expected_status_code">Expected status code</label>
                    <input type="number" id="expected_status_code" name="expected_status_code" min="100" max="599"
                           value="<?= (int)($editSite['expected_status_code'] ?? ($_POST['expected_status_code'] ?? 200)) ?>">
                </div>
                <div>
                    <label for="timeout_seconds">Timeout (seconds)</label>
                    <input type="number" id="timeout_seconds" name="timeout_seconds" min="1" max="60"
                           value="<?= (int)($editSite['timeout_seconds'] ?? ($_POST['timeout_seconds'] ?? 15)) ?>">
                </div>
            </div>

            <label class="checkbox-label">
                <input type="checkbox" name="is_active" value="1"
                    <?= ($editSite['is_active'] ?? ($_POST['is_active'] ?? 1)) ? 'checked' : '' ?>>
                Active (included in the cron check cycle)
            </label>

            <label class="checkbox-label">
                <input type="checkbox" name="monitor_ssl" value="1"
                    <?= ($editSite['monitor_ssl'] ?? ($_POST['monitor_ssl'] ?? 1)) ? 'checked' : '' ?>>
                Monitor SSL certificate expiry
            </label>

            <label class="checkbox-label">
                <input type="checkbox" name="monitor_domain" value="1"
                    <?= ($editSite['monitor_domain'] ?? ($_POST['monitor_domain'] ?? 1)) ? 'checked' : '' ?>>
                Monitor domain (WHOIS) expiry
            </label>

            <div class="form-actions">
                <button type="submit"><?= $editSite ? 'Save changes' : 'Add site' ?></button>
                <?php if ($editSite): ?>
                    <a href="admin.php" class="cancel-link">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <h2>All sites</h2>

    <div class="agency-tabs" role="group" aria-label="Filter by agency">
        <a href="admin.php" class="agency-tab <?= $agencyFilter === '' ? 'active' : '' ?>">All</a>
        <?php foreach (um_agencies() as $agencyOption): ?>
            <a href="admin.php?agency=<?= urlencode($agencyOption) ?>" class="agency-tab <?= $agencyFilter === $agencyOption ? 'active' : '' ?>"><?= htmlspecialchars($agencyOption) ?></a>
        <?php endforeach; ?>
    </div>

    <table class="admin-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Agency</th>
                <th>URL</th>
                <th>Status</th>
                <th>Interval</th>
                <th>Active</th>
                <th>Last checked</th>
                <th>SSL expires</th>
                <th>Domain expires</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($sites as $site):
                $sslDays = um_days_until($site['ssl_expires_at']);
                $domainDays = um_days_until($site['domain_expires_at']);
            ?>
                <tr>
                    <td><?= htmlspecialchars($site['name']) ?></td>
                    <td><?= htmlspecialchars($site['agency']) ?></td>
                    <td><a href="<?= htmlspecialchars($site['url']) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($site['url']) ?></a></td>
                    <td><span class="status-pill <?= htmlspecialchars($site['current_status']) ?>"><?= htmlspecialchars($site['current_status']) ?></span></td>
                    <td><?= (int)$site['check_interval_seconds'] ?>s</td>
                    <td><?= $site['is_active'] ? 'Yes' : 'No' ?></td>
                    <td><?= $site['last_checked_at'] ? htmlspecialchars($site['last_checked_at']) : 'Never' ?></td>
                    <td>
                        <?php if (!$site['monitor_ssl']): ?>
                            <span class="expiry-badge unknown">off</span>
                        <?php elseif ($sslDays === null): ?>
                            <span class="expiry-badge unknown">unknown</span>
                        <?php else: ?>
                            <span class="expiry-badge <?= um_expiry_status($sslDays) ?>"><?= $sslDays ?>d</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!$site['monitor_domain']): ?>
                            <span class="expiry-badge unknown">off</span>
                        <?php elseif ($domainDays === null): ?>
                            <span class="expiry-badge unknown">unknown</span>
                        <?php else: ?>
                            <span class="expiry-badge <?= um_expiry_status($domainDays) ?>"><?= $domainDays ?>d</span>
                        <?php endif; ?>
                    </td>
                    <td class="admin-row-actions">
                        <a href="admin.php?edit=<?= (int)$site['id'] ?>">Edit</a>

                        <form method="post" action="admin.php" class="inline-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="check_now">
                            <input type="hidden" name="id" value="<?= (int)$site['id'] ?>">
                            <button type="submit" class="link-button">Check now</button>
                        </form>

                        <form method="post" action="admin.php" class="inline-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="check_expiry_now">
                            <input type="hidden" name="id" value="<?= (int)$site['id'] ?>">
                            <button type="submit" class="link-button">Refresh expiry</button>
                        </form>

                        <form method="post" action="admin.php" class="inline-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="toggle_active">
                            <input type="hidden" name="id" value="<?= (int)$site['id'] ?>">
                            <button type="submit" class="link-button"><?= $site['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                        </form>

                        <form method="post" action="admin.php" class="inline-form"
                              onsubmit="return confirm('Delete <?= htmlspecialchars(addslashes($site['name'])) ?> and all its check history? This cannot be undone.');">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$site['id'] ?>">
                            <button type="submit" class="link-button link-button-danger">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($sites)): ?>
                <tr><td colspan="10"><?= $agencyFilter !== '' ? 'No sites for this agency yet.' : 'No sites yet — add one above.' ?></td></tr>
            <?php endif; ?>
        </tbody>
    </table>

</div>

</body>
</html>
