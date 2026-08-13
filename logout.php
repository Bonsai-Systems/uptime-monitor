<?php
require __DIR__ . '/includes/auth.php';
um_logout();
header('Location: login.php');
exit;
