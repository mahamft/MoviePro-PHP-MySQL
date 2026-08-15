<?php
require_once __DIR__ . '/includes/config.php';

if (isLoggedIn()) {
    try {
        $db = getDB();
        $userId = (int)$_SESSION['user_id'];
        $stmt = $db->prepare("DELETE FROM carts WHERE user_id = ? AND status = 'active'");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable) {
        // Logout must still succeed even if the reservation tables are unavailable.
    }
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}
session_destroy();
header('Location: ' . url('index.php'));
exit;
