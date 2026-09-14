<?php
require_once __DIR__ . '/includes/config.php';
if (isset($_SESSION['session_id']) && isset($conn)) {
    $stmt = $conn->prepare("UPDATE user_sessions SET sign_out = NOW(), duration = CONCAT(FLOOR(TIMESTAMPDIFF(SECOND, sign_in, NOW()) / 3600), 'h ', FLOOR(MOD(TIMESTAMPDIFF(SECOND, sign_in, NOW()), 3600) / 60), 'm') WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['session_id']);
    $stmt->execute();
}
session_unset();
session_destroy();
header('Location: ' . BASE_URL . '/login.php');
exit;
