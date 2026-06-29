<?php
$db_host = '127.0.0.1';
$db_port = '3306';
$db_user = 'YOUR_DB_USER';
$db_pass = 'YOUR_DB_PASSWORD';
$db_name = 'YOUR_DB_NAME';

try {
    $pdo = new PDO("mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('DB Error: ' . $e->getMessage());
}

function get_domains() {
    global $pdo;
    return $pdo->query("SELECT * FROM domains WHERE is_active=1 ORDER BY domain")->fetchAll();
}

function create_mailbox($email, $password) {
    // Insert ke postfixadmin
    $pdo2 = new PDO("mysql:host=127.0.0.1;port=3306;dbname=postfixadmin;charset=utf8mb4", 'YOUR_DB_USER', 'YOUR_DB_PASSWORD');
    list($local, $domain) = explode('@', $email);
    $hash = crypt($password, '$1$saltsalt$');
    try {
        $stmt = $pdo2->prepare("INSERT INTO mailbox (username, password, name, maildir, quota, local_part, domain, created, modified, active) VALUES (?, ?, ?, ?, 0, ?, ?, NOW(), NOW(), 1)");
        $stmt->execute([$email, $hash, $local, $domain.'/'.$local.'/', $local, $domain]);
        $stmt2 = $pdo2->prepare("INSERT INTO alias (address, goto, domain, created, modified, active) VALUES (?, ?, ?, NOW(), NOW(), 1)");
        $stmt2->execute([$email, $email, $domain]);
        // Buat maildir
        $maildir = '/var/mail/vmail/'.$domain.'/'.$local;
        @mkdir($maildir.'/new', 0755, true);
        @mkdir($maildir.'/cur', 0755, true);
        @mkdir($maildir.'/tmp', 0755, true);
        @chown($maildir, 'www-data');
        return true;
    } catch (Exception $e) {
        error_log('Mailbox error: '.$e->getMessage());
        return false;
    }
}

function fetch_imap_emails($temp_email_id, $email, $password) {
    global $pdo;
    $imap_host = '{127.0.0.1:143/imap/notls/norsh}INBOX';
    $mbox = @imap_open($imap_host, $email, $password);
    if (!$mbox) return 0;
    $count = imap_num_msg($mbox);
    $saved = 0;
    for ($i = 1; $i <= $count; $i++) {
        $header = imap_headerinfo($mbox, $i);
        $msg_id = isset($header->message_id) ? $header->message_id : md5($email.$i);
        $check = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE temp_email_id=? AND message_id=?");
        $check->execute([$temp_email_id, $msg_id]);
        if ($check->fetchColumn() > 0) continue;
        $subject = isset($header->subject) ? imap_utf8($header->subject) : '(No Subject)';
        $from = isset($header->from[0]) ? $header->from[0]->mailbox.'@'.$header->from[0]->host : 'unknown';
        $body = '';
        $structure = imap_fetchstructure($mbox, $i);
        if ($structure->type == 0) {
            $body = imap_fetchbody($mbox, $i, 1);
            if ($structure->encoding == 3) $body = base64_decode($body);
            elseif ($structure->encoding == 4) $body = quoted_printable_decode($body);
        } else {
            $body = imap_fetchbody($mbox, $i, 1);
            $body = quoted_printable_decode($body);
        }
        $stmt = $pdo->prepare("INSERT INTO messages (temp_email_id, sender_email, subject, body, message_id, received_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$temp_email_id, $from, $subject, $body, $msg_id]);
        $saved++;
    }
    imap_close($mbox);
    return $saved;
}

function create_session($email) {
    global $pdo;
    $token = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare('INSERT INTO sessions (email, token, created_at, expires_at) VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY))');
    $stmt->execute([$email, $token]);
    $pdo->prepare('UPDATE users SET last_login=NOW() WHERE email=?')->execute([$email]);
    return $token;
}

function get_mailgen_user() {
    global $pdo;
    if (!isset($_COOKIE['mailgen_session'])) return null;
    $token = $_COOKIE['mailgen_session'];
    $stmt = $pdo->prepare('SELECT u.id, u.email, u.verified, u.is_banned, u.role, u.quota FROM users u JOIN sessions s ON u.email = s.email WHERE s.token = ? AND s.expires_at > NOW()');
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    if (!$user) { setcookie('mailgen_session', '', time()-3600, '/'); return null; }
    if ($user['is_banned']) { setcookie('mailgen_session', '', time()-3600, '/'); return null; }
    return $user;
}

function get_user($email) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    return $stmt->fetch();
}

function user_exists($email) { return get_user($email) !== false; }

function create_user($email, $password) {
    global $pdo;
    if (user_exists($email)) return ['success' => false, 'message' => 'Email sudah terdaftar'];
    try {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, auth_method, verified, role, created_at) VALUES (?, ?, "email", 1, "user", NOW())');
        $stmt->execute([$email, $hash]);
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Gagal membuat akun'];
    }
}

function generate_temp_email($user_id, $prefix, $domain) {
    global $pdo;
    $count = $pdo->prepare('SELECT COUNT(*) FROM temp_emails WHERE user_id=?');
    $count->execute([$user_id]);
    $quota_stmt = $pdo->prepare('SELECT quota FROM users WHERE id=?');
    $quota_stmt->execute([$user_id]);
    $q = $quota_stmt->fetchColumn();
    if ($count->fetchColumn() >= $q) return ['success' => false, 'message' => 'Quota email habis ('.$q.' max)'];
    $prefix = preg_replace('/[^a-z0-9._-]/', '', strtolower($prefix));
    if (empty($prefix)) return ['success' => false, 'message' => 'Prefix tidak valid'];
    $email = $prefix.'@'.$domain;
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $imap_pass = '';
    for ($i = 0; $i < 20; $i++) $imap_pass .= $chars[random_int(0, strlen($chars)-1)];
    $expires_at = date('Y-m-d H:i:s', strtotime('+30 days'));
    try {
        $stmt = $pdo->prepare('INSERT INTO temp_emails (user_id, email, imap_pass, created_at, expires_at) VALUES (?, ?, ?, NOW(), ?)');
        $stmt->execute([$user_id, $email, $imap_pass, $expires_at]);
        $email_id = $pdo->lastInsertId();
        create_mailbox($email, $imap_pass);
        // Tambah ke vmailbox postfix
        $vmailbox = "/etc/postfix/vmailbox";
        file_put_contents($vmailbox, "$email $domain/".explode('@',$email)[0]."/Maildir/\n", FILE_APPEND);
        shell_exec("postmap $vmailbox && postfix reload");
        return ['success' => true, 'email' => $email, 'id' => $email_id];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Email sudah digunakan'];
    }
}

function get_user_temp_emails($user_id) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT t.*, (SELECT COUNT(*) FROM messages m WHERE m.temp_email_id=t.id) as msg_count FROM temp_emails t WHERE t.user_id=? AND t.expires_at > NOW() ORDER BY t.created_at DESC');
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

function get_email_messages($email_id, $user_id) {
    global $pdo;
    $te = $pdo->prepare('SELECT * FROM temp_emails WHERE id=? AND user_id=?');
    $te->execute([$email_id, $user_id]);
    $temp = $te->fetch();
    if ($temp && isset($temp['imap_pass'])) {
        @fetch_imap_emails($email_id, $temp['email'], $temp['imap_pass']);
    }
    $stmt = $pdo->prepare('SELECT m.* FROM messages m WHERE m.temp_email_id=? ORDER BY m.received_at DESC');
    $stmt->execute([$email_id]);
    return $stmt->fetchAll();
}

function verify_password($email, $password) {
    $user = get_user($email);
    if (!$user) return false;
    return password_verify($password, $user['password_hash']);
}

function log_activity($email, $action, $detail, $ip) {
    global $pdo;
    try {
        $pdo->prepare('INSERT INTO activity_logs (user_email, action, detail, ip, created_at) VALUES (?, ?, ?, ?, NOW())')->execute([$email, $action, $detail, $ip]);
    } catch (Exception $e) {}
}

function is_super_admin($user) { return isset($user['role']) && $user['role'] === 'super_admin'; }

function verify_mailgen_user($email, $password) {
    $user = get_user($email);
    if (!$user) return false;
    if (!password_verify($password, $user['password_hash'])) return false;
    if ($user['is_banned']) return false;
    return $user;
}

function create_session_token($user_id) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT email FROM users WHERE id=?');
    $stmt->execute([$user_id]);
    $email = $stmt->fetchColumn();
    if (!$email) return false;
    return create_session($email);
}

function create_mailgen_user($email, $password) { return create_user($email, $password); }

function update_mailgen_password($user_id, $new_password) {
    global $pdo;
    $hash = password_hash($new_password, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?');
    return $stmt->execute([$hash, $user_id]);
}

function update_user_quota($user_id, $quota) {
    global $pdo;
    $stmt = $pdo->prepare('UPDATE users SET quota=? WHERE id=?');
    return $stmt->execute([$quota, $user_id]);
}

function get_all_mailgen_users() {
    global $pdo;
    return $pdo->query('SELECT id, email, quota, role, is_banned, created_at FROM users ORDER BY created_at DESC')->fetchAll();
}

function count_all_temp_emails() {
    global $pdo;
    return (int) $pdo->query('SELECT COUNT(*) FROM temp_emails')->fetchColumn();
}

// Auto-create mailbox
function auto_create_mailbox($email, $password) {
    list($local, $domain) = explode('@', $email);
    
    // 1. Buat struktur Maildir
    $maildir = "/var/mail/vmail/$domain/$local/Maildir";
    @mkdir("$maildir/new", 0700, true);
    @mkdir("$maildir/cur", 0700, true);
    @mkdir("$maildir/tmp", 0700, true);
    shell_exec("chown -R vmail:vmail /var/mail/vmail/$domain/$local 2>/dev/null");
    shell_exec("chmod -R 700 /var/mail/vmail/$domain/$local 2>/dev/null");
    
    // 2. Tambah ke Postfix vmailbox otomatis
    $vmailbox = '/etc/postfix/vmailbox';
    if (file_exists($vmailbox)) {
        $content = file_get_contents($vmailbox);
        if (strpos($content, $email) === false) {
            file_put_contents($vmailbox, "$email $domain/$local/Maildir/\n", FILE_APPEND);
            shell_exec('postmap /etc/postfix/vmailbox 2>/dev/null');
            shell_exec('postfix reload 2>/dev/null');
        }
    }
    
    return ['success' => true, 'maildir' => $maildir];
}


// Override auto_create_mailbox dengan sudo support
function auto_create_mailbox_v2($email, $password) {
    list($local, $domain) = explode('@', $email);
    
    // Buat Maildir
    $maildir = "/var/mail/vmail/$domain/$local/Maildir";
    @mkdir("$maildir/new", 0700, true);
    @mkdir("$maildir/cur", 0700, true);
    @mkdir("$maildir/tmp", 0700, true);
    shell_exec("chown -R vmail:vmail /var/mail/vmail/$domain/$local 2>/dev/null");
    shell_exec("chmod -R 700 /var/mail/vmail/$domain/$local 2>/dev/null");
    
    // Add ke vmailbox
    $vmailbox = '/etc/postfix/vmailbox';
    if (file_exists($vmailbox)) {
        $content = file_get_contents($vmailbox);
        if (strpos($content, $email) === false) {
            file_put_contents($vmailbox, "$email $domain/$local/Maildir/\n", FILE_APPEND);
            shell_exec('sudo /usr/sbin/postmap /etc/postfix/vmailbox 2>/dev/null');
            shell_exec('sudo /usr/sbin/postfix reload 2>/dev/null');
        }
    }
    
    return ['success' => true];
}
