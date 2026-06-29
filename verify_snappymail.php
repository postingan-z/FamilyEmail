<?php
require 'config.php';

echo "<h1>SnapPyMail Verification</h1>";
echo "<h2>1. SnapPyMail Path:</h2>";
$snap_path = SNAPPYMAIL_DATA_PATH;
if (is_dir($snap_path)) {
    echo "✅ Path ada: $snap_path<br>";
} else {
    echo "❌ Path tidak ada: $snap_path<br>";
}

echo "<h2>2. Test Accounts:</h2>";
$stmt = $pdo->query('SELECT id, email, created_at FROM temp_emails ORDER BY created_at DESC LIMIT 5');
$emails = $stmt->fetchAll();
if ($emails) {
    echo "<ul>";
    foreach ($emails as $email) {
        echo "<li>" . htmlspecialchars($email['email']) . " - <a href='open_snappymail.php?id=" . $email['id'] . "' target='_blank'>Buka</a></li>";
    }
    echo "</ul>";
}
?>
