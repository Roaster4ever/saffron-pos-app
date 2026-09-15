<?php
require_once __DIR__ . '/includes/config.php';
if (isset($_SESSION['session_id']) && isset($conn)) {
    $stmt = $conn->prepare("UPDATE user_sessions SET sign_out = NOW(), duration = CONCAT(FLOOR(TIMESTAMPDIFF(SECOND, sign_in, NOW()) / 3600), 'h ', FLOOR(MOD(TIMESTAMPDIFF(SECOND, sign_in, NOW()), 3600) / 60), 'm') WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['session_id']);
    $stmt->execute();
}
$params = session_get_cookie_params();
setcookie(session_name(), '', time() - 42000,
    $params["path"], $params["domain"],
    $params["secure"], $params["httponly"]
);
session_unset();
session_destroy();
header('Location: ' . BASE_URL . '/login.php');
exit;
