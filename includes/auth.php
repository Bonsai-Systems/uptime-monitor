<?php
/**
 * Bonsai Uptime Monitor — admin auth
 *
 * Simple shared-password gate for the admin pages (add/edit/delete sites).
 * Not per-user — this is an internal tool, one password for whoever manages it.
 */

require_once __DIR__ . '/settings.php';

function um_start_session(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => !empty($_SERVER['HTTPS']),
        ]);
        session_start();
    }
}

function um_is_logged_in(): bool {
    um_start_session();
    return !empty($_SESSION['um_logged_in']);
}

/**
 * Redirects to login.php if not authenticated. Call at the top of any admin page.
 */
function um_require_login(): void {
    if (!um_is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function um_login(string $password): bool {
    $hash = (string)um_get_setting('admin_password_hash', '');

    if (empty($hash) || str_contains($hash, 'REPLACE_WITH')) {
        return false; // not configured yet — refuse rather than allow a blank password through
    }

    if (password_verify($password, $hash)) {
        um_start_session();
        session_regenerate_id(true);
        $_SESSION['um_logged_in'] = true;
        return true;
    }

    return false;
}

/**
 * Changes the admin password, requiring the current one first. Stored in the
 * settings table (DB), which takes precedence over config.php's bootstrap hash.
 */
function um_change_password(string $currentPassword, string $newPassword): ?string {
    $hash = (string)um_get_setting('admin_password_hash', '');

    if (empty($hash) || str_contains($hash, 'REPLACE_WITH') || !password_verify($currentPassword, $hash)) {
        return 'Current password is incorrect.';
    }

    if (mb_strlen($newPassword) < 8) {
        return 'New password must be at least 8 characters.';
    }

    um_set_setting('admin_password_hash', password_hash($newPassword, PASSWORD_DEFAULT));
    return null;
}

function um_logout(): void {
    um_start_session();
    $_SESSION = [];
    session_destroy();
}

/**
 * CSRF token for admin forms — generated once per session, checked on every POST.
 */
function um_csrf_token(): string {
    um_start_session();
    if (empty($_SESSION['um_csrf'])) {
        $_SESSION['um_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['um_csrf'];
}

function um_verify_csrf(): bool {
    um_start_session();
    $token = $_POST['csrf_token'] ?? '';
    return !empty($_SESSION['um_csrf']) && is_string($token) && hash_equals($_SESSION['um_csrf'], $token);
}
