<?php
/**
 * Session Configuration
 * Sets the session duration to 6 months
 */
$sessionLifetime = 15552000; // 6 * 30 * 24 * 60 * 60 seconds

// Set the garbage collection max lifetime
ini_set('session.gc_maxlifetime', $sessionLifetime);

// Set the cookie lifetime
ini_set('session.cookie_lifetime', $sessionLifetime);

// Set cookie parameters (compatible with PHP 7.3+)
if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params([
        'lifetime' => $sessionLifetime,
        'path' => '/',
        'domain' => '',
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
} else {
    session_set_cookie_params($sessionLifetime, '/');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
