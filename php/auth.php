<?php
// CareerPath AI - Session-based auth helpers
//
// Two roles: 'administrator' (manages counselor/administrator accounts via
// users.php) and 'counselor' (reviews/approves careers via careers.php).
// Passwords are hashed with PHP's own password_hash()/password_verify(),
// so there's no cross-platform hash-format guesswork.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

/** Returns the logged-in user's session info, or null if not logged in. */
function current_user(): ?array
{
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    return [
        'user_id' => $_SESSION['user_id'],
        'name' => $_SESSION['user_name'],
        'email' => $_SESSION['user_email'],
        'role' => $_SESSION['user_role'],
    ];
}

/** Redirects to login.php (preserving the current URL to return to) if not logged in. */
function require_login(): array
{
    $user = current_user();
    if (!$user) {
        $next = urlencode($_SERVER['REQUEST_URI'] ?? 'careers.php');
        header("Location: login.php?next=$next");
        exit;
    }
    return $user;
}

/**
 * Requires the logged-in user's role to be in $allowedRoles, e.g.
 * require_role(['administrator']) or require_role(['administrator','counselor']).
 * Shows a 403 page (not a silent redirect) so a counselor hitting an
 * administrator-only page understands why, rather than looking logged out.
 */
function require_role(array $allowedRoles): array
{
    $user = require_login();
    if (!in_array($user['role'], $allowedRoles, true)) {
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Access denied</title></head>'
            . '<body style="font-family:Arial,sans-serif;max-width:600px;margin:60px auto;color:#222;">'
            . '<h1>403 — Access denied</h1>'
            . '<p>Your account (<strong>' . htmlspecialchars($user['role']) . '</strong>) doesn\'t have permission to view this page.</p>'
            . '<p><a href="careers.php">Go back to Career Review</a></p>'
            . '</body></html>';
        exit;
    }
    return $user;
}

/** Logs a user in: sets session vars and rotates the session ID (prevents session fixation). */
function login_user(array $userRow): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $userRow['user_id'];
    $_SESSION['user_name'] = $userRow['name'];
    $_SESSION['user_email'] = $userRow['email'];
    $_SESSION['user_role'] = $userRow['role'];
}

/** Logs the current user out and destroys the session. */
function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
