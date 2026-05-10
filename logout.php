<?php
session_start();

// Expire the session cookie in the browser immediately
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Clear all session data then destroy
$_SESSION = [];
session_destroy();

header("Location: login.php");
exit;
