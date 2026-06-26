<?php
/**
 * Shared authentication bridge for ZooTrack.
 *
 * ZooTrack runs at https://vetapp.zootabor.eu/zootrack/ — same host and PHP pool as
 * the main VetApp application, which uses the default PHP session (PHPSESSID, cookie
 * path "/"). Starting the session here therefore reads the SAME login that VetApp's
 * Auth::login() established. No separate login is needed.
 *
 * KEEP IN SYNC with VetApp app/core/Auth.php: if the session cookie params are later
 * hardened there (HttpOnly/Secure/SameSite), mirror the change here.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function zt_user_id() {
    return $_SESSION['user_id'] ?? null;
}

function zt_is_admin() {
    return ($_SESSION['role'] ?? '') === 'admin';
}

/** Gate an HTML page: redirect unauthenticated visitors to the VetApp login page. */
function zt_require_login_page() {
    if (zt_user_id() === null) {
        $host = $_SERVER['HTTP_HOST'] ?? 'vetapp.zootabor.eu';
        header('Location: https://' . $host . '/login');
        exit;
    }
}

/** Gate the JSON API: respond 401 for unauthenticated callers. */
function zt_require_login_api() {
    if (zt_user_id() === null) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'unauthorized — please sign in to VetApp']);
        exit;
    }
}
