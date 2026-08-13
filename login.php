<?php
header('X-Robots-Tag: noindex, nofollow', true);
require __DIR__ . '/includes/auth.php';

um_start_session();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!um_verify_csrf()) {
        $error = 'Session expired — please try again.';
    } else {
        $password = (string)($_POST['password'] ?? '');
        if (um_login($password)) {
            header('Location: index.php');
            exit;
        }
        $error = 'Incorrect password.';
    }
}

if (um_is_logged_in()) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="robots" content="noindex, nofollow">
<title>Log in — Bonsai Uptime Monitor</title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<script>(function(){var t=localStorage.getItem('um-theme')||(window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');document.documentElement.setAttribute('data-theme',t);})();</script>
<link rel="stylesheet" href="assets/style.css">
<script src="assets/theme.js" defer></script>
</head>
<body>

<div class="login-wrap">
    <button type="button" id="theme-toggle" class="theme-toggle" aria-label="Toggle dark mode" title="Toggle dark mode">🌙</button>
    <form class="login-card" method="post" action="login.php">
        <h1>Bonsai Uptime Monitor</h1>
        <?php if ($error): ?>
            <p class="form-error"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(um_csrf_token()) ?>">
        <label for="password">Admin password</label>
        <input type="password" id="password" name="password" required autofocus>
        <button type="submit">Log in</button>
    </form>
</div>

</body>
</html>
