<?php
// Bonsai Uptime Monitor — config
//
// Only 'db' genuinely has to live here (needed before the database connection
// exists). Everything else below is a one-time bootstrap default — once you
// save a value via the Settings screen (settings.php), the database copy takes
// over and this file is no longer read for that key. Safe to leave as placeholders.

return [
    'db' => [
        'host' => 'localhost',
        'name' => 'uptime_monitor',
        'user' => 'your_db_user',
        'pass' => 'your_db_password',
        'charset' => 'utf8mb4',
    ],

    // Bootstrap only — set the real values via Settings after first login.
    // Slack incoming webhook URL for #site-alerts: https://api.slack.com/messaging/webhooks
    'slack_webhook_url' => 'https://hooks.slack.com/services/XXXX/XXXX/XXXX',
    'alert_email' => '',
    'alert_email_from' => 'alerts@bonsaidigital.co.uk',

    // Bootstrap password hash — lets you log in once to reach the Settings screen
    // and set a real password there. Generate with:
    // php -r "echo password_hash('yourpassword', PASSWORD_DEFAULT);"
    // Leave as REPLACE_WITH_HASH and the admin panel will refuse all logins.
    'admin_password_hash' => '(PASSWORD HASH)',

    // Brand colours, used in the dashboard UI
    'brand' => [
        'primary' => '#ee4367',
        'light'   => '#e2ecf3',
        'dark'    => '#000000',
    ],

    // Bootstrap only — how many concurrent HTTP requests checker.php fires at once
    'concurrency' => 10,

    // Bootstrap only — days before SSL cert / domain expiry to fire an alert
    'expiry_alert_days' => 14,
];
