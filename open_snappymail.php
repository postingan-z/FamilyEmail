<?php
require 'config.php';

$email_id = $_GET['id'] ?? 0;
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : (isset($_COOKIE['user_id']) ? $_COOKIE['user_id'] : 0);

if (!$email_id || !$user_id) {
    die('Invalid request');
}

$stmt = $pdo->prepare('SELECT email, imap_pass FROM temp_emails WHERE id = ? AND user_id = ?');
$stmt->execute([$email_id, $user_id]);
$email_info = $stmt->fetch();

if (!$email_info) {
    die('Email not found');
}

$snappymail_url = SNAPPYMAIL_URL . '?email=' . urlencode($email_info['email']);
header('Location: ' . $snappymail_url);
exit;
?>
