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

function trackSession($conn) {
    if (!isset($_SESSION['user_id'])) return;
    if (!empty($_SESSION['session_id'])) return;
    $uid = intval($_SESSION['user_id']);
    $stmt = $conn->prepare("UPDATE user_sessions SET sign_out = NOW(), duration = CONCAT(FLOOR(TIMESTAMPDIFF(SECOND, sign_in, NOW()) / 3600), 'h ', FLOOR(MOD(TIMESTAMPDIFF(SECOND, sign_in, NOW()), 3600) / 60), 'm') WHERE user_id = ? AND sign_out IS NULL");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt2 = $conn->prepare("INSERT INTO user_sessions (user_id, username, sign_in, ip_address) VALUES (?, ?, NOW(), ?)");
    $stmt2->bind_param("iss", $uid, $_SESSION['user_name'] ?? '', $ip);
    $stmt2->execute();
    $_SESSION['session_id'] = $conn->insert_id;
}
