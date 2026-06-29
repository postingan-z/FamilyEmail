<?php
/**
 * Session Configuration
 */

// Set session parameters sebelum session_start()
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_lifetime', '86400'); // 24 hours
ini_set('session.gc_maxlifetime', '86400');

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security: Regenerate ID
if (!isset($_SESSION['_init'])) {
    session_regenerate_id(true);
    $_SESSION['_init'] = true;
}
?>
