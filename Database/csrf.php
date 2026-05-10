<?php
// ── CSRF protection helper ────────────────────────────────────────────
// Include this file AFTER session_start() on any page that renders a form.
//
// csrf_token()        → hidden <input> for POST forms
// csrf_token_qs()     → raw query-string fragment for GET action links
// csrf_verify()       → validates POST OR GET token, then rotates it
// csrf_token_value()  → raw token string (for JS fetch headers etc.)

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function csrf_token(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token']) . '">';
}

function csrf_token_value(): string {
    return $_SESSION['csrf_token'];
}

/** For appending to GET action links: href="page.php?action=1&<?= csrf_token_qs() ?>" */
function csrf_token_qs(): string {
    return 'csrf_token=' . urlencode($_SESSION['csrf_token']);
}

/**
 * Validates the CSRF token from POST body OR GET query string.
 * Rotates the token after successful verification.
 * Dies with 403 if the token is missing or wrong.
 */
function csrf_verify(): void {
    // Check POST first, fall back to GET (for form method="GET" and link-style actions)
    $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';

    if (empty($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        die('Security check failed. Please <a href="javascript:history.back()">go back</a> and try again.');
    }

    // Rotate token after each verified submission to prevent reuse
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
