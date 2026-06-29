<?php
/**
 * Domain Management Handler
 * Uses password_hash field from users table
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

// Get authenticated user
$user = get_mailgen_user();

if (!$user || !isset($user['id'])) {
    http_response_code(401);
    die(json_encode(['success' => false, 'error' => 'Please login first']));
}

$user_id = $user['id'];
$action = $_POST['action'] ?? '';
$domain_id = (int)($_POST['id'] ?? 0);
$domain_name = $_POST['domain'] ?? '';

try {
    // Get admin password hash dari database - gunakan password_hash field!
    $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$user_id]);
    $admin_user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$admin_user) {
        die(json_encode(['success' => false, 'error' => 'User not found']));
    }
    
    $admin_pwd_hash = $admin_user['password_hash'];
} catch (Exception $e) {
    die(json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]));
}

// ============ TOGGLE DOMAIN ============
if ($action === 'toggle_domain' && $domain_id > 0) {
    $pwd_new = $_POST['_pwd_new'] ?? '';
    $pwd_login = $_POST['_pwd_login'] ?? '';
    
    try {
        $stmt = $pdo->prepare('SELECT id, is_active FROM domains WHERE id = ? LIMIT 1');
        $stmt->execute([$domain_id]);
        $domain = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$domain) {
            die(json_encode(['success' => false, 'error' => 'Domain tidak ditemukan']));
        }
        
        // NONAKTIFKAN - set new password
        if ($domain['is_active']) {
            if (empty($pwd_new) || strlen($pwd_new) < 6) {
                die(json_encode(['success' => false, 'error' => 'Password minimal 6 karakter']));
            }
            
            $pwd_hash = password_hash($pwd_new, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare('UPDATE domains SET is_active=0, disable_password=?, disabled_at=NOW() WHERE id=?');
            $stmt->execute([$pwd_hash, $domain_id]);
            
            die(json_encode(['success' => true, 'message' => '✓ Domain berhasil dinonaktifkan']));
        } 
        // AKTIFKAN - verify unlock password
        else {
            if (empty($pwd_login)) {
                die(json_encode(['success' => false, 'error' => 'Password diperlukan']));
            }
            
            $stmt = $pdo->prepare('SELECT disable_password FROM domains WHERE id=?');
            $stmt->execute([$domain_id]);
            $dom = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$dom || !$dom['disable_password']) {
                die(json_encode(['success' => false, 'error' => 'Domain tidak punya password']));
            }
            
            if (!password_verify($pwd_login, $dom['disable_password'])) {
                die(json_encode(['success' => false, 'error' => 'Password unlock salah']));
            }
            
            $stmt = $pdo->prepare('UPDATE domains SET is_active=1, disable_password=NULL, disabled_at=NULL WHERE id=?');
            $stmt->execute([$domain_id]);
            
            die(json_encode(['success' => true, 'message' => '✓ Domain berhasil diaktifkan']));
        }
        
    } catch (Exception $e) {
        die(json_encode(['success' => false, 'error' => $e->getMessage()]));
    }
}

// ============ DELETE DOMAIN ============
else if ($action === 'delete_domain' && $domain_id > 0) {
    $pwd_new = $_POST['_pwd_new'] ?? '';
    
    if (empty($pwd_new)) {
        die(json_encode(['success' => false, 'error' => 'Password diperlukan']));
    }
    
    // Verify with password_hash field!
    if (!password_verify($pwd_new, $admin_pwd_hash)) {
        die(json_encode(['success' => false, 'error' => 'Password admin salah']));
    }
    
    try {
        $stmt = $pdo->prepare('DELETE FROM domains WHERE id=?');
        $stmt->execute([$domain_id]);
        
        $stmt = $pdo->prepare('DELETE FROM temp_emails WHERE domain_id=?');
        $stmt->execute([$domain_id]);
        
        die(json_encode(['success' => true, 'message' => '✓ Domain berhasil dihapus']));
        
    } catch (Exception $e) {
        die(json_encode(['success' => false, 'error' => $e->getMessage()]));
    }
}

die(json_encode(['success' => false, 'error' => 'Invalid action']));
?>
