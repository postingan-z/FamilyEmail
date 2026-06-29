<?php
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Content-Type: application/json');
require 'config.php';
$user = get_mailgen_user();
if (!$user) { echo json_encode(['count'=>0]); exit; }

$email_id = intval($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT email FROM temp_emails WHERE id = ? AND user_id = ?');
$stmt->execute([$email_id, $user['id']]);
$row = $stmt->fetch();
if (!$row) { echo json_encode(['count'=>0]); exit; }

list($local, $domain) = explode('@', $row['email']);
$count = 0;
foreach (['new','cur'] as $f) {
    $path = "/var/mail/vmail/$domain/$local/Maildir/$f";
    if (is_dir($path)) $count += count(array_diff(scandir($path), ['.','..']));
}
echo json_encode(['count' => $count, 'ts' => time()]);
