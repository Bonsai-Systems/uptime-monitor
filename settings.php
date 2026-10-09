<?php
header('X-Robots-Tag: noindex, nofollow', true);
require __DIR__ . '/includes/auth.php';

um_require_login();

$errors = [];
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!um_verify_csrf()) {
        $errors[] = 'Session expired — please try again.';
    } else {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'save_alerts') {
            $slackUrl = trim((string)($_POST['slack_webhook_url'] ?? ''));
            $alertEmail = trim((string)($_POST['alert_email'] ?? ''));
            $alertEmailFrom = trim((string)($_POST['alert_email_from'] ?? ''));
            $concurrency = (int)($_POST['concurrency'] ?? 10);
            $expiryAlertDays = (int)($_POST['expiry_alert_days'] ?? 14);

            if ($slackUrl !== '' && !filter_var($slackUrl, FILTER_VALIDATE_URL)) {
                $errors[] = 'Slack webhook URL must be a valid URL.';
            }
            if ($alertEmail !== '' && !filter_var($alertEmail, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Alert email must be a valid email address.';
            }
            if ($alertEmailFrom !== '' && !filter_var($alertEmailFrom, FILTER_VALIDATE_EMAIL)) {
                $errors[] = '"From" email must be a valid email address.';
            }
            if ($concurrency < 1 || $concurrency > 50) {
                $errors[] = 'Concurrency must be between 1 and 50.';
            }
            if ($expiryAlertDays < 1 || $expiryAlertDays > 90) {
                $errors[] = 'Expiry alert threshold must be between 1 and 90 days.';
            }

            if (empty($errors)) {
                um_set_setting('slack_webhook_url', $slackUrl);
                um_set_setting('alert_email', $alertEmail);
                um_set_setting('alert_email_from', $alertEmailFrom);
                um_set_setting('concurrency', (string)$concurrency);
                um_set_setting('expiry_alert_days', (string)$expiryAlertDays);
                $notice = 'Alert settings saved.';
            }
        }

        if ($action === 'save_ticket_webhook') {
            $ticketUrl = trim((string)($_POST['ticket_webhook_url'] ?? ''));
            $ticketSecret = trim((string)($_POST['ticket_webhook_secret'] ?? ''));

            // Same URL validation as site URLs: this is fetched server-side.
            $scheme = strtolower((string)parse_url($ticketUrl, PHP_URL_SCHEME));
            if ($ticketUrl !== '' && (!filter_var($ticketUrl, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true))) {
                $errors[] = 'Support ticket endpoint URL must be a valid http(s) URL.';
            }
            if ($ticketSecret !== '' && !preg_match('/^[A-Za-z0-9]{16,128}$/', $ticketSecret)) {
                $errors[] = 'Secret must be the letters-and-numbers secret copied from the support site.';
            }
            if (($ticketUrl === '') !== ($ticketSecret === '')) {
                $errors[] = 'Enter both the endpoint URL and the secret, or clear both to switch support tickets off.';
            }

            if (empty($errors)) {
                um_set_setting('ticket_webhook_url', $ticketUrl);
                um_set_setting('ticket_webhook_secret', $ticketSecret);
                $notice = $ticketUrl === '' ? 'Support tickets switched off.' : 'Support ticket settings saved.';
            }
        }

        if ($action === 'test_ticket_webhook') {
            $error = um_send_ticket_webhook('test', ['name' => 'Test from the uptime monitor', 'url' => '']);
            if ($error !== null) {
                $errors[] = 'Test failed: ' . $error;
            } else {
                $notice = 'Test sent. The support site\'s Uptime monitoring settings should show it under "Last alert".';
            }
        }

        if ($action === 'change_password') {
            $current = (string)($_POST['current_password'] ?? '');
            $new = (string)($_POST['new_password'] ?? '');
            $confirm = (string)($_POST['confirm_password'] ?? '');

            if ($new !== $confirm) {
                $errors[] = 'New password and confirmation do not match.';
            } else {
                $result = um_change_password($current, $new);
                if ($result !== null) {
                    $errors[] = $result;
                } else {
                    $notice = 'Password changed.';
                }
            }
        }
    }
}

$slackUrl = (string)um_get_setting('slack_webhook_url', '');
$alertEmail = (string)um_get_setting('alert_email', '');
$alertEmailFrom = (string)um_get_setting('alert_email_from', '');
$concurrency = (int)um_get_setting('concurrency', 10);
$expiryAlertDays = (int)um_get_setting('expiry_alert_days', 14);
$ticketUrl = (string)um_get_setting('ticket_webhook_url', '');
$ticketSecret = (string)um_get_setting('ticket_webhook_secret', '');
$csrfToken = um_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="robots" content="noindex, nofollow">
<title>Settings — Bonsai Uptime Monitor</title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<script>(function(){var t=localStorage.getItem('um-theme')||(window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');document.documentElement.setAttribute('data-theme',t);})();</script>
<link rel="stylesheet" href="assets/style.css">
<script src="assets/theme.js" defer></script>
</head>
<body>

<header class="site-header">
    <h1>Settings</h1>
    <div class="header-actions">
        <a href="admin.php">Manage sites</a>
        &nbsp;|&nbsp;
        <a href="index.php">Dashboard</a>
        &nbsp;|&nbsp;
        <a href="logout.php">Log out</a>
        <button type="button" id="theme-toggle" class="theme-toggle" aria-label="Toggle dark mode" title="Toggle dark mode">🌙</button>
    </div>
</header>

<div class="container">

    <a class="back-link" href="admin.php">&larr; Manage sites</a>

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
        <h2>Alerts &amp; checker</h2>
        <form method="post" action="settings.php" class="site-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="save_alerts">

            <label for="slack_webhook_url">Slack webhook URL</label>
            <input type="text" id="slack_webhook_url" name="slack_webhook_url"
                   placeholder="https://hooks.slack.com/services/..."
                   value="<?= htmlspecialchars($slackUrl) ?>">

            <div class="form-row">
                <div>
                    <label for="alert_email">Alert email (leave blank to disable)</label>
                    <input type="email" id="alert_email" name="alert_email"
                           value="<?= htmlspecialchars($alertEmail) ?>">
                </div>
                <div>
                    <label for="alert_email_from">"From" address</label>
                    <input type="email" id="alert_email_from" name="alert_email_from"
                           value="<?= htmlspecialchars($alertEmailFrom) ?>">
                </div>
                <div>
                    <label for="concurrency">Checker concurrency</label>
                    <input type="number" id="concurrency" name="concurrency" min="1" max="50"
                           value="<?= (int)$concurrency ?>">
                </div>
                <div>
                    <label for="expiry_alert_days">SSL/domain expiry alert (days before)</label>
                    <input type="number" id="expiry_alert_days" name="expiry_alert_days" min="1" max="90"
                           value="<?= (int)$expiryAlertDays ?>">
                </div>
            </div>

            <div class="form-actions">
                <button type="submit">Save alert settings</button>
            </div>
        </form>
    </div>

    <div class="admin-card">
        <h2>Support tickets</h2>
        <p>Open a ticket in Support Desk when a site goes down or its SSL certificate/domain is about to expire, and add a note to it when the site comes back up. In the support site, go to Support &rarr; Settings &rarr; Uptime monitoring, switch on the status monitor, generate a secret and copy both values here. Clear both fields to switch this off.</p>
        <form method="post" action="settings.php" class="site-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="save_ticket_webhook">

            <label for="ticket_webhook_url">Endpoint URL</label>
            <input type="text" id="ticket_webhook_url" name="ticket_webhook_url"
                   placeholder="https://support.example.com/wp-json/bst/v1/monitor/status"
                   value="<?= htmlspecialchars($ticketUrl) ?>">

            <label for="ticket_webhook_secret">Secret</label>
            <input type="text" id="ticket_webhook_secret" name="ticket_webhook_secret" autocomplete="off" spellcheck="false"
                   value="<?= htmlspecialchars($ticketSecret) ?>">

            <div class="form-actions">
                <button type="submit">Save support ticket settings</button>
            </div>
        </form>

        <form method="post" action="settings.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="test_ticket_webhook">
            <div class="form-actions">
                <button type="submit"<?= ($ticketUrl === '' || $ticketSecret === '') ? ' disabled' : '' ?>>Send test</button>
            </div>
        </form>
    </div>

    <div class="admin-card">
        <h2>Change admin password</h2>
        <form method="post" action="settings.php" class="site-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="change_password">

            <label for="current_password">Current password</label>
            <input type="password" id="current_password" name="current_password" required>

            <div class="form-row">
                <div>
                    <label for="new_password">New password</label>
                    <input type="password" id="new_password" name="new_password" minlength="8" required>
                </div>
                <div>
                    <label for="confirm_password">Confirm new password</label>
                    <input type="password" id="confirm_password" name="confirm_password" minlength="8" required>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit">Change password</button>
            </div>
        </form>
    </div>

</div>

</body>
</html>
