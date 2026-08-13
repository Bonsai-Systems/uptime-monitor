<?php
require_once __DIR__ . '/db.php';

/**
 * Settings are DB-backed so they're editable from settings.php without touching
 * code on the server. config.php values are kept as a fallback/bootstrap only —
 * an existing install upgrades cleanly, and the DB value wins once one is saved.
 */
function um_all_settings(bool $forceReload = false): array {
    static $settings = null;
    if ($settings === null || $forceReload) {
        $pdo = um_db();
        $rows = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll();
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    return $settings;
}

function um_get_setting(string $key, $default = null) {
    $settings = um_all_settings();
    if (array_key_exists($key, $settings) && $settings[$key] !== '' && $settings[$key] !== null) {
        return $settings[$key];
    }

    $cfg = require __DIR__ . '/../config.php';
    return $cfg[$key] ?? $default;
}

function um_set_setting(string $key, string $value): void {
    $pdo = um_db();
    $pdo->prepare(
        "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    )->execute([$key, $value]);
    um_all_settings(true); // refresh the in-request cache so subsequent reads see the new value
}
