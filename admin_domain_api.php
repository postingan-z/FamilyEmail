<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'SUPER_ADMIN') {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

header('Content-Type: application/json');
$action = $_POST['action'] ?? '';
$domain_id = $_POST['domain_id'] ?? 0;
$password = $_POST['password'] ?? '';

try {
    $pdo = new PDO('mysql:host=localhost;dbname=mailgen_db', 'mailgen_user', 'mailgen_secure_pass_2024');
} catch (PDOException $e) {
    die(json_encode(['success' => false, 'message' => 'DB Error']));
}

if ($action === 'disable_domain') {
    if (strlen($password) < 6) {
        echo json_encode(['success' => false, 'message' => 'Password min 6 karakter']);
        exit;
    }
    $stmt = $pdo->prepare('UPDATE domains SET is_active=0, disable_password=?, disabled_at=NOW() WHERE id=?');
    if ($stmt->execute([password_hash($password, PASSWORD_DEFAULT), $domain_id])) {
        echo json_encode(['success' => true, 'message' => 'Domain dinonaktifkan']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal update']);
    }
}

elseif ($action === 'enable_domain') {
    $stmt = $pdo->prepare('SELECT disable_password FROM domains WHERE id=?');
    $stmt->execute([$domain_id]);
    $domain = $stmt->fetch();
    
    if (!$domain || !password_verify($password, $domain['disable_password'])) {
        echo json_encode(['success' => false, 'message' => 'Password salah']);
        exit;
    }
    
    $stmt = $pdo->prepare('UPDATE domains SET is_active=1, disable_password=NULL, disabled_at=NULL WHERE id=?');
    if ($stmt->execute([$domain_id])) {
        echo json_encode(['success' => true, 'message' => 'Domain diaktifkan']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal aktifkan']);
    }
}

elseif ($action === 'delete_domain') {
    // Verify login password
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id=?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user || !password_verify($password, $user['password_hash'])) {
        echo json_encode(['success' => false, 'message' => 'Password login salah']);
        exit;
    }
    
    // Delete domain
    $stmt = $pdo->prepare('DELETE FROM domains WHERE id=?');
    if ($stmt->execute([$domain_id])) {
        echo json_encode(['success' => true, 'message' => 'Domain dihapus']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal hapus']);
    }
}

else {
    echo json_encode(['success' => false, 'message' => 'Action tidak dikenal']);
}
