<?php
// includes/auth.php — Saffron POS Authentication & Authorization

function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > 28800) {
        session_unset();
        session_destroy();
        header('Location: ' . BASE_URL . '/login.php?error=Session+expired');
        exit;
    }
    $_SESSION['last_activity'] = time();
}

function requireAdmin() {
    requireLogin();
    if ($_SESSION['user_role'] !== 'admin') {
        header('Location: ' . BASE_URL . '/index.php?error=Access+denied');
        exit;
    }
}

function isAdmin() { return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin'; }
function canOverridePrice() { return isAdmin(); }
