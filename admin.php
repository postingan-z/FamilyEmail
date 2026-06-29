<?php
require_once __DIR__ . '/session_config.php';
require 'config.php';

// ── FUNGSI SETUP DOMAIN OTOMATIS ────────────────────────────────────────
function setup_domain($domain) {
    global $pdo;
    $errors = [];
    $success = [];

    // 1. Folder vmail
    $vmail_path = "/var/mail/vmail/$domain";
    if (!is_dir($vmail_path)) {
        @mkdir($vmail_path, 0755, true);
        shell_exec("chown -R vmail:vmail " . escapeshellarg($vmail_path) . " 2>/dev/null");
        shell_exec("chmod 755 " . escapeshellarg($vmail_path) . " 2>/dev/null");
        $success[] = "✓ Folder vmail dibuat";
    } else {
        $success[] = "✓ Folder vmail sudah ada";
    }

    // 2. Postfix main.cf
    $mainconf = '/etc/postfix/main.cf';
    if (file_exists($mainconf)) {
        $cf = file_get_contents($mainconf);
        if (strpos($cf, 'virtual_mailbox_domains') !== false) {
            if (strpos($cf, $domain) === false) {
                $cf = preg_replace(
                    '/^(virtual_mailbox_domains\s*=\s*)(.+)$/m',
                    "$1$2, $domain",
                    $cf
                );
                file_put_contents($mainconf, $cf);
                $success[] = "✓ Postfix main.cf: domain ditambahkan";
            } else {
                $success[] = "✓ Postfix main.cf: domain sudah ada";
            }
        } else {
            $cf .= "
virtual_mailbox_base = /var/mail/vmail
";
            $cf .= "virtual_mailbox_domains = $domain
";
            $cf .= "virtual_mailbox_maps = hash:/etc/postfix/vmailbox
";
            $cf .= "virtual_uid_maps = static:5000
";
            $cf .= "virtual_gid_maps = static:5000
";
            file_put_contents($mainconf, $cf);
            $success[] = "✓ Postfix main.cf: initialized";
        }
    }

    // 3. vmailbox file
    $vmailbox_file = '/etc/postfix/vmailbox';
    if (!file_exists($vmailbox_file)) {
        file_put_contents($vmailbox_file, "# Virtual mailbox mapping
");
    }
    shell_exec("postmap $vmailbox_file 2>/dev/null");
    $success[] = "✓ vmailbox siap";

    // 4. Postfix reload
    $reload = shell_exec("postfix reload 2>&1");
    if (strpos($reload, 'error') === false && strpos($reload, 'fatal') === false) {
        $success[] = "✓ Postfix reloaded";
    } else {
        $errors[] = "⚠ Postfix: " . trim($reload);
    }

    // 5. Postfixadmin DB
    try {
        $pdo2 = new PDO(
            "mysql:host=127.0.0.1;port=3306;dbname=postfixadmin;charset=utf8mb4",
            "mailgen_user", "mailgen_secure_pass_2024"
        );
        $s = $pdo2->prepare("INSERT IGNORE INTO domain (domain, description, aliases, mailboxes, maxquota, quota, transport, backupmx, created, modified, active) VALUES (?, 'Auto setup', 0, 0, 0, 0, 'virtual', 0, NOW(), NOW(), 1)");
        $s->execute([$domain]);
        $success[] = "✓ Postfixadmin DB: domain added";
    } catch (Exception $e) {
        $errors[] = "⚠ Postfixadmin DB: " . $e->getMessage();
    }

    // 6. mailgen_db
    try {
        $s = $pdo->prepare("INSERT IGNORE INTO domains (domain, is_active) VALUES (?, 1)");
        $s->execute([$domain]);
        $pdo->prepare("UPDATE domains SET is_active=1 WHERE domain=?")->execute([$domain]);
        $success[] = "✓ mailgen_db: domain aktif";
    } catch (Exception $e) {
        $errors[] = "⚠ mailgen_db: " . $e->getMessage();
    }

    return ['success' => $success, 'errors' => $errors];
}

$user = get_mailgen_user();
if (!$user) { header('Location: login.php'); exit; }

$role = strtoupper($user['role'] ?? 'user');
if (!in_array($role, ['SUPER_ADMIN', 'ADMIN'])) { header('Location: index.php'); exit; }

$msg = ''; $err = '';

if ($_POST['action'] ?? '' === 'add_domain') {
    $domain = strtolower(trim($_POST['domain'] ?? ''));
    $domain = preg_replace('/[^a-z0-9.\-]/', '', $domain);
    if (empty($domain) || strlen($domain) < 4 || !str_contains($domain, '.')) {
        $err = 'Format domain tidak valid';
    } else {
        $chk = $pdo->prepare('SELECT id FROM domains WHERE domain=?');
        $chk->execute([$domain]);
        if ($chk->fetch()) {
            $err = "Domain $domain sudah ada";
        } else {
            // 1. Setup postfix + DB
            $result = setup_domain($domain);

            // 2. Auto-generate DKIM key
            $dkim_key = '';
            $dkim_out = shell_exec("sudo /usr/local/bin/domain_setup.sh " . escapeshellarg($domain) . " dkim 2>&1");
            if ($dkim_out && preg_match('/DKIM_KEY=(.+)/', $dkim_out, $m)) {
                $dkim_key = trim($m[1]);
                $result['success'][] = "✓ DKIM key generated";
            } else {
                $result['errors'][] = "⚠ DKIM: gagal generate key";
            }

            // 3. Simpan DKIM key ke DB untuk ditampilkan lagi nanti
            try {
                $pdo->prepare("UPDATE domains SET dkim_key=? WHERE domain=?")->execute([$dkim_key, $domain]);
            } catch (Exception $e) { /* kolom mungkin belum ada */ }

            $ok_count = count($result['success']);
            $err_count = count($result['errors']);
            log_activity($user['email'], 'add_domain', "Domain: $domain | OK: $ok_count | Err: $err_count", $_SERVER['REMOTE_ADDR'] ?? 'unknown');

            // 4. Build DNS instructions
            $server_ip = $_SERVER['SERVER_ADDR'] ?? shell_exec("curl -s ifconfig.me 2>/dev/null") ?? '38.103.170.47';
            $server_ip = trim($server_ip);
            $_SESSION['new_domain_dns'] = [
                'domain'   => $domain,
                'ip'       => $server_ip,
                'dkim_key' => $dkim_key,
            ];

            if ($err_count === 0) {
                $msg = "✅ Domain <b>$domain</b> berhasil ditambahkan!<br>" . implode('<br>', $result['success']);
            } else {
                $msg = "⚠️ Domain <b>$domain</b> ditambahkan dengan $err_count peringatan:<br>" . implode('<br>', $result['success']);
                $err = implode('<br>', $result['errors']);
            }
        }
    }
}

// toggle_domain ditangani via AJAX ke admin_handlers.php (dengan password)

if ($_POST['action'] ?? '' === 'delete_domain') {
    $id = intval($_POST['id']);
    $s = $pdo->prepare('SELECT domain FROM domains WHERE id=?'); $s->execute([$id]);
    $d = $s->fetchColumn();
    if ($d) {
        // Hapus messages dulu
        $pdo->prepare('DELETE m FROM messages m JOIN temp_emails te ON m.temp_email_id = te.id WHERE te.email LIKE ?')->execute(['%@'.$d]);
        // Hapus temp_emails
        $pdo->prepare('DELETE FROM temp_emails WHERE email LIKE ?')->execute(['%@'.$d]);
        // Hapus domain
        $pdo->prepare('DELETE FROM domains WHERE id=?')->execute([$id]);
        log_activity($user['email'], 'delete_domain', "Menghapus domain: $d beserta semua email & pesan", $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $msg = "Domain $d dihapus beserta semua datanya";
    }
}

if ($_POST['action'] ?? '' === 'add_user') {
    $uemail = strtolower(trim($_POST['uemail'] ?? ''));
    $upass  = trim($_POST['upass'] ?? '');
    $urole  = in_array($_POST['urole'], ['user','admin','super_admin']) ? $_POST['urole'] : 'user';
    $uquota = max(1, intval($_POST['uquota'] ?? 10));
    if (!filter_var($uemail, FILTER_VALIDATE_EMAIL)) { $err = 'Email tidak valid'; }
    elseif (strlen($upass) < 6) { $err = 'Password minimal 6 karakter'; }
    else {
        // super_admin otomatis unlimited (999999)
        $final_quota = ($urole === 'super_admin') ? 999999 : $uquota;
        $s = $pdo->prepare('INSERT IGNORE INTO users (email, password_hash, role, quota, verified, auth_method) VALUES (?, ?, ?, ?, 1, "password")');
        $s->execute([$uemail, password_hash($upass, PASSWORD_DEFAULT), $urole, $final_quota]);
        if ($s->rowCount()) {
            log_activity($user['email'], 'add_user', "Membuat user: $uemail (role: $urole, kuota: $uquota)", $_SERVER['REMOTE_ADDR'] ?? 'unknown');
            $msg = "User $uemail berhasil dibuat!";
        } else { $err = 'Email sudah terdaftar'; }
    }
}

if ($_POST['action'] ?? '' === 'edit_user') {
    $uid    = intval($_POST['uid']);
    $urole  = in_array($_POST['urole'], ['user','admin','super_admin']) ? $_POST['urole'] : 'user';
    $uquota = max(1, intval($_POST['uquota'] ?? 10));
    $uban   = isset($_POST['uban']) ? 1 : 0;
    $s = $pdo->prepare('SELECT email FROM users WHERE id=?'); $s->execute([$uid]);
    $temail = $s->fetchColumn();
    $final_quota = ($urole === 'super_admin') ? 999999 : $uquota;
    $pdo->prepare('UPDATE users SET role=?, quota=?, is_banned=? WHERE id=?')->execute([$urole, $final_quota, $uban, $uid]);
    if (!empty($_POST['upass']) && strlen($_POST['upass']) >= 6) {
        $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($_POST['upass'], PASSWORD_DEFAULT), $uid]);
    }
    log_activity($user['email'], 'edit_user', "Edit user: $temail (role: $urole, kuota: $uquota, banned: $uban)", $_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $msg = 'User diupdate';
}

if ($_POST['action'] ?? '' === 'delete_user') {
    $uid = intval($_POST['uid']);
    if ($uid === $user['id']) { $err = 'Tidak bisa hapus diri sendiri'; }
    else {
        $s = $pdo->prepare('SELECT email FROM users WHERE id=?'); $s->execute([$uid]);
        $temail = $s->fetchColumn();
        // Hapus messages dulu sebelum hapus temp_emails
        $pdo->prepare('DELETE m FROM messages m JOIN temp_emails te ON m.temp_email_id = te.id WHERE te.user_id = ?')->execute([$uid]);
        $pdo->prepare('DELETE FROM temp_emails WHERE user_id=?')->execute([$uid]);
        $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);
        log_activity($user['email'], 'delete_user', "Menghapus user: $temail beserta semua emailnya", $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $msg = 'User dihapus';
    }
}

if ($_POST['action'] ?? '' === 'delete_email' && $role === 'SUPER_ADMIN') {
    $eid = intval($_POST['eid']);
    $s = $pdo->prepare('SELECT email FROM temp_emails WHERE id=?');
    $s->execute([$eid]);
    $target_email = $s->fetchColumn();

    if ($target_email) {
        list($t_local, $t_domain) = explode('@', $target_email);
        $t_maildir = "/var/mail/vmail/$t_domain/$t_local";

        // 1. Hapus baris dari database
        $pdo->prepare('DELETE FROM temp_emails WHERE id=?')->execute([$eid]);

        // 2. Hapus folder Maildir di disk (recursive)
        if (is_dir($t_maildir)) {
            shell_exec("rm -rf " . escapeshellarg($t_maildir) . " 2>/dev/null");
        }

        // 3. Hapus baris dari /etc/postfix/vmailbox agar Postfix tidak terima mail lagi
        $vmailbox_file = '/etc/postfix/vmailbox';
        if (file_exists($vmailbox_file)) {
            $lines = file($vmailbox_file, FILE_IGNORE_NEW_LINES);
            $kept = array_filter($lines, function($l) use ($target_email) {
                return strpos($l, $target_email . ' ') !== 0;
            });
            file_put_contents($vmailbox_file, implode("\n", $kept) . "\n");
            shell_exec("postmap $vmailbox_file 2>/dev/null");
        }

        log_activity($user['email'], 'delete_email', "Menghapus email: $target_email (beserta Maildir & vmailbox)", $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $msg = 'Email dihapus beserta semua datanya';
    } else {
        $err = 'Email tidak ditemukan';
    }
}

if ($_POST['action'] ?? '' === 'clear_logs' && $role === 'SUPER_ADMIN') {
    $pdo->exec('DELETE FROM activity_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)');
    log_activity($user['email'], 'clear_logs', 'Membersihkan log lebih dari 30 hari', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $msg = 'Log lama dihapus';
}

// Redirect setelah POST untuk hindari resubmit & pesan ganda
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($msg || $err)) {
    $tab = 'domains';
    if (in_array($_POST['action'] ?? '', ['add_user','edit_user','delete_user'])) $tab = 'users';
    if (in_array($_POST['action'] ?? '', ['delete_email'])) $tab = 'emails';
    if (in_array($_POST['action'] ?? '', ['clear_logs'])) $tab = 'logs';
    $status = $msg ? urlencode('ok:'.$msg) : urlencode('err:'.$err);
    header("Location: admin.php?tab=$tab&status=$status");
    exit;
}

// Ambil notif dari URL
$msg = ''; $err = '';
if (!empty($_GET['status'])) {
    $s = urldecode($_GET['status']);
    if (strpos($s, 'ok:') === 0) $msg = substr($s, 3);
    elseif (strpos($s, 'err:') === 0) $err = substr($s, 4);
}

$domains = $pdo->query('SELECT * FROM domains ORDER BY created_at DESC')->fetchAll();
$users   = $pdo->query('SELECT u.*, (SELECT COUNT(*) FROM temp_emails WHERE user_id=u.id) as email_count FROM users u ORDER BY created_at DESC')->fetchAll();
$logs    = $pdo->query('SELECT * FROM activity_logs ORDER BY created_at DESC LIMIT 200')->fetchAll();
$all_emails = $pdo->query('SELECT te.*, u.email as owner_email, u.role as owner_role, (SELECT COUNT(*) FROM messages m WHERE m.temp_email_id = te.id) as msg_count FROM temp_emails te JOIN users u ON te.user_id = u.id ORDER BY te.created_at DESC')->fetchAll();
$stats   = [
    'users'   => $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'emails'  => $pdo->query('SELECT COUNT(*) FROM temp_emails')->fetchColumn(),
    'domains' => $pdo->query('SELECT COUNT(*) FROM domains WHERE is_active=1')->fetchColumn(),
    'logs'    => $pdo->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn(),
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin Panel - FamilyMail</title>
<style>
:root{--bg:#0a0a14;--sidebar:#0f0f1e;--card:rgba(255,255,255,0.04);--border:rgba(255,255,255,0.08);--purple:#7c3aed;--pl:#a78bfa;--text:#e2e8f0;--muted:#64748b;--sub:#94a3b8;--green:#10b981;--red:#ef4444;--cyan:#06b6d4;--yellow:#f59e0b}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex}
.sidebar{width:220px;min-height:100vh;background:var(--sidebar);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;left:0;top:0;bottom:0;z-index:100}
.logo{padding:20px 18px;display:flex;align-items:center;gap:10px;border-bottom:1px solid var(--border)}
.logo-icon{width:32px;height:32px;background:linear-gradient(135deg,var(--purple),#4f46e5);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:16px}
.logo-text{font-size:16px;font-weight:700}
.nav{flex:1;padding:12px 0}
.nav a{display:flex;align-items:center;gap:10px;padding:10px 18px;color:var(--sub);text-decoration:none;font-size:13px;font-weight:500;border-left:3px solid transparent;transition:all .2s}
.nav a:hover,.nav a.active{background:rgba(124,58,237,0.12);color:var(--pl);border-left-color:var(--purple)}
.sidebar-foot{padding:12px 0;border-top:1px solid var(--border)}
.main{margin-left:220px;flex:1;padding:28px}
.ptitle{font-size:24px;font-weight:700;margin-bottom:4px}
.psub{font-size:13px;color:var(--muted);margin-bottom:24px}
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:28px}
.stat{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:18px;position:relative;overflow:hidden}
.stat::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--purple),var(--pl))}
.slabel{font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:8px}
.snum{font-size:28px;font-weight:700}
.tabs{display:flex;gap:4px;margin-bottom:20px;background:var(--card);border:1px solid var(--border);border-radius:10px;padding:4px;width:fit-content}
.tab{padding:8px 18px;border-radius:8px;cursor:pointer;font-size:13px;font-weight:500;color:var(--muted);transition:all .2s}
.tab.active{background:linear-gradient(135deg,var(--purple),#4f46e5);color:#fff}
.section{display:none}.section.active{display:block}
.card{background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:20px}
.card-head{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.card-title{font-size:14px;font-weight:600}
.form-row{display:flex;gap:8px;flex-wrap:wrap;padding:16px 20px;border-bottom:1px solid var(--border)}
.inp{padding:9px 14px;background:rgba(0,0,0,0.3);border:1px solid var(--border);border-radius:8px;color:var(--text);font-size:13px;min-width:160px}
.inp:focus{outline:none;border-color:var(--purple)}
select.inp{cursor:pointer}
.btn{padding:9px 18px;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;white-space:nowrap}
.btn-primary{background:linear-gradient(135deg,var(--purple),#4f46e5);color:#fff}.btn-primary:hover{opacity:.9}
.btn-danger{background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.3);color:#f87171}
.btn-warn{background:rgba(245,158,11,0.15);border:1px solid rgba(245,158,11,0.3);color:#fbbf24}
.btn-sm{padding:5px 12px;font-size:11px}
table{width:100%;border-collapse:collapse}
th{padding:10px 16px;text-align:left;font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;border-bottom:1px solid var(--border)}
td{padding:11px 16px;font-size:13px;border-bottom:1px solid var(--border)}
tr:last-child td{border-bottom:none}
tr:hover td{background:rgba(255,255,255,0.02)}
.badge{display:inline-block;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:600}
.badge-admin{background:rgba(124,58,237,0.2);color:var(--pl);border:1px solid rgba(124,58,237,0.3)}
.badge-super{background:rgba(6,182,212,0.2);color:var(--cyan);border:1px solid rgba(6,182,212,0.3)}
.badge-user{background:rgba(255,255,255,0.06);color:var(--muted);border:1px solid var(--border)}
.badge-active{background:rgba(16,185,129,0.15);color:var(--green);border:1px solid rgba(16,185,129,0.3)}
.badge-off{background:rgba(239,68,68,0.1);color:#f87171;border:1px solid rgba(239,68,68,0.2)}
.badge-ban{background:rgba(239,68,68,0.15);color:#f87171;border:1px solid rgba(239,68,68,0.3)}
.log-action{display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-family:monospace;background:rgba(124,58,237,0.15);color:var(--pl);border:1px solid rgba(124,58,237,0.2)}
.alert{padding:12px 16px;border-radius:8px;font-size:13px;margin-bottom:16px;display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
.alert div{white-space:pre-wrap;word-wrap:break-word;flex:1}
.alert-ok{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);color:#6ee7b7}
.alert-err{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:#fca5a5}
.alert-close{background:none;border:none;cursor:pointer;font-size:16px;line-height:1;opacity:0.7;color:inherit;padding:0;flex-shrink:0}
.alert-close:hover{opacity:1}
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:999;align-items:center;justify-content:center}
.modal.open{display:flex}
.modal-box{background:#0f0f1e;border:1px solid var(--border);border-radius:16px;padding:24px;width:420px;max-width:95vw}
.modal-title{font-size:16px;font-weight:700;margin-bottom:20px}
.modal-row{margin-bottom:12px}
.modal-row label{display:block;font-size:12px;color:var(--muted);margin-bottom:4px}
.modal-row .inp{width:100%}
.modal-foot{display:flex;gap:8px;justify-content:flex-end;margin-top:20px}
.log-filter{padding:12px 20px;border-bottom:1px solid var(--border);display:flex;gap:8px;flex-wrap:wrap}
details{transition:all 0.3s}details[open]{background:rgba(124,58,237,0.08)!important;border-color:rgba(124,58,237,0.5)!important}summary{outline:none}summary::-webkit-details-marker{color:var(--pl)}@media(max-width:768px){.sidebar{display:none}.main{margin-left:0;padding:16px}.stats{grid-template-columns:1fr 1fr}}
<?php include __DIR__.'/partials/sidebar_style.php'; ?>
</style>
<style>
.notif-container {
  position: fixed;
  top: 20px;
  right: 20px;
  z-index: 99999;
}

.notif {
  margin-bottom: 10px;
  padding: 14px 18px;
  border-radius: 10px;
  animation: slideIn 0.4s ease-out;
  box-shadow: 0 8px 30px rgba(0,0,0,0.3);
  font-size: 13px;
  font-weight: 600;
  color: white;
}

@keyframes slideIn {
  from { transform: translateX(420px); opacity: 0; }
  to { transform: translateX(0); opacity: 1; }
}

@keyframes slideOut {
  from { transform: translateX(0); opacity: 1; }
  to { transform: translateX(420px); opacity: 0; }
}

.notif.out { animation: slideOut 0.4s ease-in forwards; }
.notif.ok { background: linear-gradient(135deg, #10b981, #059669); }
.notif.err { background: linear-gradient(135deg, #ef4444, #dc2626); }
.notif.warn { background: linear-gradient(135deg, #f59e0b, #d97706); }
</style></head>
<body>
<div class="notif-container" id="notifContainer"></div>
<?php include __DIR__.'/partials/sidebar.php'; ?>

<main class="main">
  <div class="ptitle">🛡️ Admin Panel</div>
  <div class="psub">Kelola domain, user, dan pantau semua aktivitas</div>

  <?php if($msg): ?><div class="alert alert-ok" id="alert-msg"><div>✅ <?= nl2br(htmlspecialchars($msg)) ?></div><button class="alert-close" onclick="this.parentElement.remove()">✕</button></div><?php endif; ?>
  <?php if($err): ?><div class="alert alert-err" id="alert-err"><div>❌ <?= nl2br(htmlspecialchars($err)) ?></div><button class="alert-close" onclick="this.parentElement.remove()">✕</button></div><?php endif; ?>

  <div class="stats">
    <div class="stat"><div class="slabel">Total User</div><div class="snum"><?= $stats['users'] ?></div></div>
    <div class="stat"><div class="slabel">Total Email</div><div class="snum" style="color:var(--pl)"><?= $stats['emails'] ?></div></div>
    <div class="stat"><div class="slabel">Domain Aktif</div><div class="snum" style="color:var(--cyan)"><?= $stats['domains'] ?></div></div>
    <div class="stat"><div class="slabel">Total Log</div><div class="snum" style="color:var(--yellow)"><?= $stats['logs'] ?></div></div>
  </div>

  
  <div class="tabs">
    <div class="tab active" onclick="switchTab('domains',this)">🌐 Domain</div>
    <div class="tab" onclick="switchTab('users',this)">👥 Users</div>
    <?php if($role==='SUPER_ADMIN'): ?><div class="tab" onclick="switchTab('logs',this)">📋 Activity Logs</div><?php endif; ?>
    <?php if($role==='SUPER_ADMIN'): ?><div class="tab" onclick="switchTab('emails',this)">📧 Email & Password</div><?php endif; ?>
    <?php if($role==='SUPER_ADMIN'): ?><div class="tab" onclick="switchTab('dns',this)">🔧 DNS Setup</div><?php endif; ?>
  </div>

  <!-- DOMAINS -->
  <div class="section active" id="tab-domains">
    <div class="card">
      <div class="card-head"><span class="card-title">🌐 Setup Domain Baru</span></div>
      <form method="POST">
        <input type="hidden" name="action" value="add_domain">
        <div class="form-row">
          <input class="inp" name="domain" placeholder="Contoh: example.com" style="flex:1;min-width:250px" required pattern="^[a-z0-9.-]+\.[a-z]{2,}$">
          <button class="btn btn-primary" type="submit">➕ Setup Domain</button>
        </div>
        <div style="padding:0 20px;color:var(--muted);font-size:12px;margin-top:8px;line-height:1.6">
          💡 Sistem akan otomatis setup:<br>
          ✓ Buat folder /var/mail/vmail/domain<br>
          ✓ Setup virtual_mailbox_domains<br>
          ✓ Update Postfix config & reload
        </div>
      </form>
    </div>
    <div class="card">
      <div class="card-head"><span class="card-title">📋 Daftar Domain (<?= count($domains) ?>)</span></div>
      <table>
        <thead><tr><th>Domain</th><th>Status</th><th>Ditambahkan</th><th>Aksi</th></tr></thead>
        <tbody>
        <?php foreach($domains as $d): ?>
          <tr>
            <td style="font-weight:600;color:var(--pl)"><?= htmlspecialchars($d['domain']) ?></td>
            <td><span class="badge <?= $d['is_active']?'badge-active':'badge-off' ?>"><?= $d['is_active']?'Aktif':'Nonaktif' ?></span></td>
            <td style="color:var(--muted)"><?= date('d M Y', strtotime($d['created_at'])) ?></td>
            <td>
              <button type="button" class="btn btn-warn btn-sm" data-toggle-domain="<?= $d['id'] ?>" data-domain="<?= htmlspecialchars($d['domain'], ENT_QUOTES) ?>" data-active="<?= $d['is_active'] ? 1 : 0 ?>">
                <?= $d['is_active']?'Nonaktifkan':'Aktifkan' ?>
              </button>
              <button type="button" class="btn btn-danger btn-sm" data-delete-domain="<?= $d['id'] ?>" data-domain="<?= htmlspecialchars($d['domain'], ENT_QUOTES) ?>">🗑</button>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- USERS -->
  <div class="section" id="tab-users">
    <div class="card">
      <div class="card-head"><span class="card-title">👤 Tambah User Baru</span></div>
      <form method="POST">
        <input type="hidden" name="action" value="add_user">
        <div class="form-row">
          <input class="inp" name="uemail" type="email" placeholder="Email user" style="flex:2;min-width:200px">
          <input class="inp" name="upass" type="password" placeholder="Password (min 6)" style="flex:1;min-width:140px">
          <select class="inp" name="urole">
            <option value="user">User</option>
            <option value="admin">Admin</option>
            <?php if($role==='SUPER_ADMIN'): ?><option value="super_admin">Super Admin</option><?php endif; ?>
          </select>
          <input class="inp" name="uquota" type="number" value="10" min="1" max="999999" placeholder="Kuota" style="width:80px" title="Kosongkan/isi 0 untuk unlimited (super_admin otomatis unlimited)">
          <button class="btn btn-primary" type="submit">➕ Tambah User</button>
        </div>
      </form>
    </div>
    <div class="card">
      <div class="card-head"><span class="card-title">👥 Daftar User (<?= count($users) ?>)</span></div>
      <table>
        <thead><tr><th>Email</th><th>Role</th><th>Kuota</th><th>Email</th><th>Login Terakhir</th><th>Status</th><th>Aksi</th></tr></thead>
        <tbody>
        <?php foreach($users as $u): ?>
          <?php
            $r = strtolower($u['role'] ?? 'user');
            if ($r === 'super_admin') $rc = 'badge-super';
            elseif ($r === 'admin') $rc = 'badge-admin';
            else $rc = 'badge-user';
          ?>
          <tr>
            <td style="font-weight:500"><?= htmlspecialchars($u['email']) ?></td>
            <td><span class="badge <?= $rc ?>"><?= strtoupper($u['role']??'USER') ?></span></td>
            <td><?= $u['email_count'] ?>/<?= strtolower($u['role']??'')==='super_admin' ? '∞' : $u['quota'] ?></td>
            <td style="color:var(--muted)"><?= $u['email_count'] ?> email</td>
            <td style="color:var(--muted);font-size:12px"><?= $u['last_login'] ? date('d M Y H:i', strtotime($u['last_login'])) : 'Belum pernah' ?></td>
            <td>
              <?php if($u['is_banned']): ?>
                <span class="badge badge-ban">BANNED</span>
              <?php else: ?>
                <span class="badge badge-active">Aktif</span>
              <?php endif; ?>
            </td>
            <td>
              <button class="btn btn-warn btn-sm" onclick="openEdit(<?= htmlspecialchars(json_encode($u),ENT_QUOTES) ?>)">✏️ Edit</button>
              <?php if($u['id'] !== $user['id']): ?>
              <form method="POST" style="display:inline" onsubmit="return confirm('Hapus user ini beserta semua emailnya?')">
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="uid" value="<?= $u['id'] ?>">
                <button class="btn btn-danger btn-sm" type="submit">🗑</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- EMAILS -->
  <div class="section" id="tab-emails">
    <div class="card">
      <div class="card-head"><span class="card-title">📧 Semua Email Dibuat (<?= count($all_emails) ?>)</span></div>
      <table>
        <thead><tr><th>Email</th><th>Dibuat Oleh</th><th>Password SnappyMail</th><th>Pesan</th><th>Dibuat</th><th>Aksi</th></tr></thead>
        <tbody>
        <?php foreach($all_emails as $e): ?>
          <tr>
            <td style="font-weight:500"><?= htmlspecialchars($e['email']) ?></td>
            <td style="color:var(--muted)"><?= htmlspecialchars($e['owner_email'] ?? '(user dihapus)') ?></td>
            <td>
              <span id="pw<?= $e['id'] ?>" data-pwd="<?= htmlspecialchars($e['imap_pass'],ENT_QUOTES) ?>" style="font-family:monospace;font-size:12px;letter-spacing:1px;color:var(--sub)">••••••••••••</span>
              <button class="btn btn-warn btn-sm" id="eye<?= $e['id'] ?>" onclick="togglePw(<?= $e['id'] ?>)" style="margin-left:6px">👁</button>
              <button class="btn btn-sm" id="cp<?= $e['id'] ?>" onclick="cpText(<?= $e['id'] ?>)" style="background:rgba(255,255,255,0.05);border:1px solid var(--border);color:var(--sub);margin-left:4px">📋</button>
            </td>
            <td style="color:var(--muted)"><?= $e['msg_count'] ?></td>
            <td style="color:var(--muted);font-size:12px"><?= date('d M Y H:i', strtotime($e['created_at'])) ?></td>
            <td>
              <form method="POST" style="display:inline" onsubmit="return confirm('Hapus email <?= htmlspecialchars($e['email'],ENT_QUOTES) ?> beserta semua pesan & Maildir-nya? Tindakan ini tidak bisa dibatalkan.')">
                <input type="hidden" name="action" value="delete_email">
                <input type="hidden" name="eid" value="<?= $e['id'] ?>">
                <button class="btn btn-danger btn-sm" type="submit">🗑 Hapus</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($all_emails)): ?>
          <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:24px">Belum ada email dibuat</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- LOGS -->
  <div class="section" id="tab-logs">
    <div class="card">
      <div class="card-head">
        <span class="card-title">📋 Activity Logs</span>
        <?php if($role==='SUPER_ADMIN'): ?>
        <form method="POST" onsubmit="return confirm('Hapus log lebih dari 30 hari?')">
          <input type="hidden" name="action" value="clear_logs">
          <button class="btn btn-danger btn-sm" type="submit">🗑 Hapus Log Lama</button>
        </form>
        <?php endif; ?>
      </div>
      <div class="log-filter">
        <input class="inp" id="logSearch" placeholder="🔍 Cari email, aksi, detail..." oninput="filterLogs()" style="flex:1;min-width:200px">
        <select class="inp" id="logAction" onchange="filterLogs()" style="min-width:150px">
          <option value="">Semua Aksi</option>
          <option value="create_email">Buat Email</option>
          <option value="add_user">Tambah User</option>
          <option value="edit_user">Edit User</option>
          <option value="delete_user">Hapus User</option>
          <option value="add_domain">Tambah Domain</option>
          <option value="login">Login</option>
        </select>
      </div>
      <table>
        <thead><tr><th>Waktu</th><th>User</th><th>Aksi</th><th>Detail</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach($logs as $l): ?>
          <tr class="log-row"
              data-email="<?= strtolower(htmlspecialchars($l['user_email'])) ?>"
              data-action="<?= strtolower(htmlspecialchars($l['action'])) ?>"
              data-detail="<?= strtolower(htmlspecialchars($l['detail'])) ?>">
            <td style="color:var(--muted);font-size:12px;white-space:nowrap"><?= date('d M Y H:i:s', strtotime($l['created_at'])) ?></td>
            <td style="font-size:12px"><?= htmlspecialchars($l['user_email']) ?></td>
            <td><span class="log-action"><?= htmlspecialchars($l['action']) ?></span></td>
            <td style="font-size:12px;color:var(--sub)"><?= htmlspecialchars($l['detail']) ?></td>
            <td style="font-size:11px;color:var(--muted)"><?= htmlspecialchars($l['ip']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="section" id="tab-dns">
    <div class="card">
      <div class="card-head"><span class="card-title">🔧 Domain Setup Otomatis</span></div>
      <div style="padding:16px 20px;border-bottom:1px solid var(--border)">
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
          <select class="inp" id="dns_domain" onchange="loadDomainStatus()" style="min-width:220px">
            <?php foreach($domains as $d): ?>
            <option value="<?= htmlspecialchars($d['domain']) ?>"><?= htmlspecialchars($d['domain']) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-primary" onclick="loadDomainStatus()">🔍 Cek Status</button>
        </div>
      </div>
      <div id="dns_status" style="padding:20px">
        <div style="color:var(--muted);text-align:center">Pilih domain dan klik Cek Status</div>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><span class="card-title">🔒 Setup SSL Certificate</span></div>
      <div style="padding:20px">
        <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:16px;margin-bottom:16px">
          <div style="background:rgba(6,182,212,0.08);border:1px solid rgba(6,182,212,0.2);border-radius:10px;padding:16px">
            <div style="font-size:13px;font-weight:600;color:var(--cyan);margin-bottom:12px">📋 Syarat Sebelum Setup SSL</div>
            <div style="font-size:12px;color:var(--sub);line-height:2">
              <div>✅ 1. Domain sudah pointing ke IP <strong style="color:var(--yellow)">IP_SERVER_KAMU</strong></div>
              <div>✅ 2. A record: <code style="background:rgba(0,0,0,0.3);padding:2px 6px;border-radius:4px">mail.DOMAIN_KAMU → IP_SERVER_KAMU</code></div>
              <div>✅ 3. Port 80 terbuka (untuk verifikasi Let's Encrypt)</div>
              <div>✅ 4. DNS sudah propagasi (cek di <a href="https://dnschecker.org" target="_blank" style="color:var(--pl)">dnschecker.org</a>)</div>
            </div>
          </div>
          <div style="background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.2);border-radius:10px;padding:16px">
            <div style="font-size:13px;font-weight:600;color:var(--yellow);margin-bottom:12px">⚠️ Jika SSL Gagal</div>
            <div style="font-size:12px;color:var(--sub);line-height:2">
              <div>• Pastikan <code style="background:rgba(0,0,0,0.3);padding:2px 6px;border-radius:4px">dig mail.DOMAIN_KAMU</code> → IP server</div>
              <div>• Tunggu DNS propagasi 5-30 menit</div>
              <div>• Pastikan Nginx/Apache running di port 80</div>
              <div>• Cek firewall: <code style="background:rgba(0,0,0,0.3);padding:2px 6px;border-radius:4px">ufw allow 80</code></div>
              <div>• Rate limit Let's Encrypt: max 5x/domain/minggu</div>
            </div>
          </div>
        </div>
        <div style="background:rgba(16,185,129,0.08);border:1px solid rgba(16,185,129,0.2);border-radius:10px;padding:16px;margin-bottom:16px">
          <div style="font-size:13px;font-weight:600;color:var(--green);margin-bottom:12px">🚀 Cara Setup SSL (Pilih domain di atas dulu)</div>
          <div style="font-size:12px;color:var(--sub);line-height:2.2">
            <div>1️⃣ Pilih domain di dropdown <strong style="color:var(--pl)">"Domain Setup Otomatis"</strong> di atas</div>
            <div>2️⃣ Klik <strong style="color:var(--cyan)">🔍 Cek Status</strong> → pastikan DKIM Key sudah ✅</div>
            <div>3️⃣ Jika DKIM belum ada → klik <strong style="color:var(--pl)">🔑 Setup DKIM</strong> dulu</div>
            <div>4️⃣ Klik <strong style="color:var(--pl)">🔒 Setup SSL</strong> → tunggu 30-60 detik</div>
            <div>5️⃣ Jika berhasil → klik <strong style="color:var(--pl)">📬 Setup Dovecot</strong></div>
            <div>6️⃣ Klik <strong style="color:var(--cyan)">🔄 Refresh</strong> → verifikasi SSL ✅</div>
          </div>
        </div>
        <div style="background:rgba(0,0,0,0.3);border:1px solid var(--border);border-radius:10px;padding:16px">
          <div style="font-size:12px;font-weight:600;color:var(--pl);margin-bottom:10px">🖥️ Manual SSL via Terminal (jika tombol gagal)</div>
          <div style="font-size:11px;color:var(--muted);margin-bottom:6px">Jalankan command ini di server:</div>
          <div id="ssl_manual_cmd" style="font-family:monospace;font-size:11px;color:var(--sub);background:rgba(0,0,0,0.4);padding:12px;border-radius:6px;line-height:1.8">
            <span style="color:#6ee7b7"># 1. Install certbot (jika belum)</span><br>
            apt install certbot python3-certbot-nginx -y<br><br>
            <span style="color:#6ee7b7"># 2. Request SSL (ganti DOMAIN dengan domain kamu)</span><br>
            certbot certonly --webroot -w /var/www/html -d mail.DOMAIN_KAMU --non-interactive --agree-tos --email admin@DOMAIN_KAMU<br><br>
            <span style="color:#6ee7b7"># 3. Cek hasil</span><br>
            ls /etc/letsencrypt/live/mail.DOMAIN_KAMU/<br><br>
            <span style="color:#6ee7b7"># 4. Auto-renew (sudah otomatis via cron)</span><br>
            certbot renew --dry-run
          </div>
          <button class="btn btn-sm" onclick="cpSslCmd()" style="margin-top:10px;background:rgba(255,255,255,0.05);border:1px solid var(--border);color:var(--sub)">📋 Copy Command</button>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-head">
        <span class="card-title">📖 Panduan Setup DNS Lengkap</span>
        <span style="font-size:11px;color:var(--muted)">Pilih domain di atas untuk auto-fill records</span>
      </div>
      <div style="padding:20px">

        <!-- WIZARD STEPS -->
        <div style="display:flex;gap:0;margin-bottom:24px;border:1px solid var(--border);border-radius:10px;overflow:hidden">
          <div class="wiz-step wiz-active" id="wiz1" onclick="showWizStep(1)" style="flex:1;padding:12px;text-align:center;cursor:pointer;background:rgba(124,58,237,0.15);border-right:1px solid var(--border);transition:all .2s">
            <div style="font-size:18px">1️⃣</div>
            <div style="font-size:11px;font-weight:600;color:var(--pl);margin-top:4px">Tambah DNS Records</div>
            <div style="font-size:10px;color:var(--muted)">Di panel provider</div>
          </div>
          <div class="wiz-step" id="wiz2" onclick="showWizStep(2)" style="flex:1;padding:12px;text-align:center;cursor:pointer;border-right:1px solid var(--border);transition:all .2s">
            <div style="font-size:18px">2️⃣</div>
            <div style="font-size:11px;font-weight:600;color:var(--sub);margin-top:4px">Setup DKIM</div>
            <div style="font-size:10px;color:var(--muted)">Generate signing key</div>
          </div>
          <div class="wiz-step" id="wiz3" onclick="showWizStep(3)" style="flex:1;padding:12px;text-align:center;cursor:pointer;border-right:1px solid var(--border);transition:all .2s">
            <div style="font-size:18px">3️⃣</div>
            <div style="font-size:11px;font-weight:600;color:var(--sub);margin-top:4px">Setup SSL</div>
            <div style="font-size:10px;color:var(--muted)">Let's Encrypt auto</div>
          </div>
          <div class="wiz-step" id="wiz4" onclick="showWizStep(4)" style="flex:1;padding:12px;text-align:center;cursor:pointer;border-right:1px solid var(--border);transition:all .2s">
            <div style="font-size:18px">4️⃣</div>
            <div style="font-size:11px;font-weight:600;color:var(--sub);margin-top:4px">Setup Dovecot</div>
            <div style="font-size:10px;color:var(--muted)">IMAP config</div>
          </div>
          <div class="wiz-step" id="wiz5" onclick="showWizStep(5)" style="flex:1;padding:12px;text-align:center;cursor:pointer;transition:all .2s">
            <div style="font-size:18px">5️⃣</div>
            <div style="font-size:11px;font-weight:600;color:var(--sub);margin-top:4px">Verifikasi</div>
            <div style="font-size:10px;color:var(--muted)">Cek semua status</div>
          </div>
        </div>

        <!-- WIZARD CONTENT PANELS -->
        <div id="wiz_content_1" class="wiz-content"><!-- DNS Records table already below --></div>

        <div id="wiz_content_2" class="wiz-content" style="display:none">
          <div style="background:rgba(124,58,237,0.08);border:1px solid rgba(124,58,237,0.2);border-radius:10px;padding:20px;margin-bottom:16px">
            <div style="font-size:14px;font-weight:700;color:var(--pl);margin-bottom:16px">🔑 Step 2: Setup DKIM Signing Key</div>
            <div style="font-size:12px;color:var(--sub);line-height:2;margin-bottom:16px">
              DKIM (DomainKeys Identified Mail) adalah tanda tangan digital yang membuktikan email benar-benar dikirim dari server kamu.
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">
              <div style="background:rgba(0,0,0,0.2);padding:12px;border-radius:8px">
                <div style="font-size:11px;font-weight:600;color:var(--cyan);margin-bottom:8px">📋 Yang Akan Dilakukan Otomatis:</div>
                <div style="font-size:11px;color:var(--muted);line-height:2">
                  ✓ Generate RSA 2048-bit key pair<br>
                  ✓ Simpan private key di server<br>
                  ✓ Setup OpenDKIM config<br>
                  ✓ Integrasi dengan Postfix milter<br>
                  ✓ Tampilkan public key untuk DNS
                </div>
              </div>
              <div style="background:rgba(0,0,0,0.2);padding:12px;border-radius:8px">
                <div style="font-size:11px;font-weight:600;color:var(--yellow);margin-bottom:8px">📝 Setelah Setup DKIM:</div>
                <div style="font-size:11px;color:var(--muted);line-height:2">
                  1. Copy public key yang muncul<br>
                  2. Tambah TXT record di DNS:<br>
                  &nbsp;&nbsp;Name: <code style="background:rgba(0,0,0,0.3);padding:1px 4px;border-radius:3px">mail._domainkey</code><br>
                  &nbsp;&nbsp;Value: key yang dicopy<br>
                  3. Tunggu propagasi 5-30 menit
                </div>
              </div>
            </div>
            <div style="display:flex;gap:8px">
              <button class="btn btn-primary" onclick="callDomainAPI(&quot;dkim&quot;,&quot;Setup DKIM&quot;);switchTab('dns',document.querySelector('.tab:last-child'))">🔑 Setup DKIM Sekarang</button>
              <button class="btn btn-warn btn-sm" onclick="showWizStep(1)">← Kembali</button>
              <button class="btn btn-sm" onclick="showWizStep(3)" style="background:rgba(255,255,255,0.05);border:1px solid var(--border);color:var(--sub)">Lanjut → SSL</button>
            </div>
          </div>
        </div>

        <div id="wiz_content_3" class="wiz-content" style="display:none">
          <div style="background:rgba(16,185,129,0.08);border:1px solid rgba(16,185,129,0.2);border-radius:10px;padding:20px;margin-bottom:16px">
            <div style="font-size:14px;font-weight:700;color:var(--green);margin-bottom:16px">🔒 Step 3: Setup SSL Certificate</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">
              <div style="background:rgba(0,0,0,0.2);padding:12px;border-radius:8px">
                <div style="font-size:11px;font-weight:600;color:var(--cyan);margin-bottom:8px">✅ Syarat Sebelum Setup SSL:</div>
                <div style="font-size:11px;color:var(--muted);line-height:2">
                  ✓ Domain sudah pointing ke IP server<br>
                  ✓ A record: <span id="wiz_a_rec" style="color:var(--yellow);font-family:monospace">mail.domain → IP</span><br>
                  ✓ Port 80 terbuka (HTTP)<br>
                  ✓ DNS propagasi selesai<br>
                  ✓ DKIM sudah di-setup (step 2)
                </div>
              </div>
              <div style="background:rgba(0,0,0,0.2);padding:12px;border-radius:8px">
                <div style="font-size:11px;font-weight:600;color:var(--yellow);margin-bottom:8px">⚠️ Jika SSL Gagal:</div>
                <div style="font-size:11px;color:var(--muted);line-height:2">
                  • DNS belum propagasi → tunggu lagi<br>
                  • Port 80 diblok → <code style="background:rgba(0,0,0,0.3);padding:1px 4px;border-radius:3px">ufw allow 80</code><br>
                  • Rate limit certbot (5x/minggu)<br>
                  • Nginx/Apache harus running<br>
                  • cek: <code style="background:rgba(0,0,0,0.3);padding:1px 4px;border-radius:3px">systemctl status nginx</code>
                </div>
              </div>
            </div>
            <div style="display:flex;gap:8px">
              <button class="btn btn-primary" onclick="callDomainAPI(&quot;ssl&quot;,&quot;Setup SSL&quot;)">🔒 Setup SSL Sekarang</button>
              <button class="btn btn-warn btn-sm" onclick="showWizStep(2)">← Kembali</button>
              <button class="btn btn-sm" onclick="showWizStep(4)" style="background:rgba(255,255,255,0.05);border:1px solid var(--border);color:var(--sub)">Lanjut → Dovecot</button>
            </div>
          </div>
        </div>

        <div id="wiz_content_4" class="wiz-content" style="display:none">
          <div style="background:rgba(6,182,212,0.08);border:1px solid rgba(6,182,212,0.2);border-radius:10px;padding:20px;margin-bottom:16px">
            <div style="font-size:14px;font-weight:700;color:var(--cyan);margin-bottom:16px">📬 Step 4: Setup Dovecot IMAP</div>
            <div style="font-size:12px;color:var(--sub);line-height:2;margin-bottom:16px">
              Dovecot adalah IMAP server yang memungkinkan user akses email via SnappyMail/Thunderbird/Outlook.
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">
              <div style="background:rgba(0,0,0,0.2);padding:12px;border-radius:8px">
                <div style="font-size:11px;font-weight:600;color:var(--cyan);margin-bottom:8px">📋 Yang Akan Di-setup:</div>
                <div style="font-size:11px;color:var(--muted);line-height:2">
                  ✓ Maildir location config<br>
                  ✓ Auth via database (imap_pass)<br>
                  ✓ SQL query untuk password lookup<br>
                  ✓ Virtual user mapping<br>
                  ✓ Restart Dovecot service
                </div>
              </div>
              <div style="background:rgba(0,0,0,0.2);padding:12px;border-radius:8px">
                <div style="font-size:11px;font-weight:600;color:var(--yellow);margin-bottom:8px">🔧 Port yang Digunakan:</div>
                <div style="font-size:11px;color:var(--muted);line-height:2">
                  • IMAP: <code style="background:rgba(0,0,0,0.3);padding:1px 4px;border-radius:3px">PORT_IMAP_KAMU</code><br>
                  • IMAPS: <code style="background:rgba(0,0,0,0.3);padding:1px 4px;border-radius:3px">PORT_IMAPS_KAMU</code> (SSL)<br>
                  • Pastikan port terbuka:<br>
                  <code style="background:rgba(0,0,0,0.3);padding:1px 4px;border-radius:3px">ufw allow PORT_IMAP_KAMU && ufw allow PORT_IMAPS_KAMU</code>
                </div>
              </div>
            </div>
            <div style="display:flex;gap:8px">
              <button class="btn btn-primary" onclick="callDomainAPI(&quot;dovecot&quot;,&quot;Setup Dovecot&quot;)">📬 Setup Dovecot Sekarang</button>
              <button class="btn btn-warn btn-sm" onclick="showWizStep(3)">← Kembali</button>
              <button class="btn btn-sm" onclick="showWizStep(5)" style="background:rgba(255,255,255,0.05);border:1px solid var(--border);color:var(--sub)">Lanjut → Verifikasi</button>
            </div>
          </div>
        </div>

        <div id="wiz_content_5" class="wiz-content" style="display:none">
          <div style="background:rgba(16,185,129,0.08);border:1px solid rgba(16,185,129,0.2);border-radius:10px;padding:20px;margin-bottom:16px">
            <div style="font-size:14px;font-weight:700;color:var(--green);margin-bottom:16px">✅ Step 5: Verifikasi Semua Komponen</div>
            <div style="font-size:12px;color:var(--sub);line-height:2;margin-bottom:16px">
              Klik tombol di bawah untuk cek status semua komponen sekaligus.
            </div>
            <div style="background:rgba(0,0,0,0.2);padding:12px;border-radius:8px;margin-bottom:16px">
              <div style="font-size:11px;font-weight:600;color:var(--green);margin-bottom:8px">✅ Checklist Domain Sehat:</div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px;font-size:11px;color:var(--muted);line-height:2">
                <div>✓ A record → IP server benar</div>
                <div>✓ MX record → mail.domain ada</div>
                <div>✓ SPF record → v=spf1 ada</div>
                <div>✓ DKIM → key sudah di DNS</div>
                <div>✓ DMARC → policy quarantine</div>
                <div>✓ SSL → certificate valid</div>
                <div>✓ Dovecot → IMAP running</div>
                <div>✓ PTR → rDNS pointing ke mail.domain</div>
              </div>
            </div>
            <div style="display:flex;gap:8px">
              <button class="btn btn-primary" onclick="loadDomainStatus();switchTab('dns',document.querySelector('[onclick*=dns]'))">🔍 Cek Status Sekarang</button>
              <button class="btn btn-warn btn-sm" onclick="showWizStep(4)">← Kembali</button>
            </div>
          </div>
        </div>

        <!-- DNS RECORDS TABLE - AUTO FILL -->
        <div style="background:rgba(124,58,237,0.08);border:1px solid rgba(124,58,237,0.2);border-radius:10px;padding:16px;margin-bottom:16px">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
            <div style="font-size:13px;font-weight:600;color:var(--pl)">📋 Step 1: DNS Records yang Harus Ditambahkan</div>
            <button class="btn btn-sm" onclick="cpAllDns()" style="background:rgba(124,58,237,0.2);border:1px solid rgba(124,58,237,0.4);color:var(--pl)">📋 Copy Semua</button>
          </div>
          <div style="font-size:11px;color:var(--muted);margin-bottom:12px">
            Login ke <strong>Cloudflare / cPanel / Domainesia / Niagahoster</strong> → DNS Management → Tambah records berikut:
          </div>
          <div style="overflow-x:auto">
          <table style="width:100%;font-size:12px" id="dns_records_table">
            <thead>
              <tr style="border-bottom:1px solid var(--border)">
                <th style="padding:8px;color:var(--pl);text-align:left">Type</th>
                <th style="padding:8px;color:var(--pl);text-align:left">Name/Host</th>
                <th style="padding:8px;color:var(--pl);text-align:left">Value/Content</th>
                <th style="padding:8px;color:var(--pl);text-align:left">TTL</th>
                <th style="padding:8px;color:var(--pl);text-align:left">Prio</th>
                <th style="padding:8px;color:var(--pl);text-align:left">Fungsi</th>
                <th style="padding:8px"></th>
              </tr>
            </thead>
            <tbody id="dns_tbl_body">
              <tr style="border-bottom:1px solid var(--border)">
                <td style="padding:8px"><span class="log-action">A</span></td>
                <td style="padding:8px;font-family:monospace" class="dns-val">mail</td>
                <td style="padding:8px;font-family:monospace;color:var(--yellow)" id="dns_rec_a_val">IP_SERVER_KAMU</td>
                <td style="padding:8px;color:var(--muted)">Auto</td>
                <td style="padding:8px;color:var(--muted)">—</td>
                <td style="padding:8px;color:var(--muted)">Arahkan subdomain mail ke IP server</td>
                <td style="padding:8px"><button class="btn btn-sm" onclick="cpRow(this)" style="background:rgba(255,255,255,0.05);border:1px solid var(--border);color:var(--muted)">📋</button></td>
              </tr>
              <tr style="border-bottom:1px solid var(--border)">
                <td style="padding:8px"><span class="log-action">MX</span></td>
                <td style="padding:8px;font-family:monospace" class="dns-val">@</td>
                <td style="padding:8px;font-family:monospace;color:var(--yellow)" id="dns_rec_mx_val">mail.DOMAIN_KAMU</td>
                <td style="padding:8px;color:var(--muted)">Auto</td>
                <td style="padding:8px;color:var(--cyan)">10</td>
                <td style="padding:8px;color:var(--muted)">Routing email masuk ke mail server</td>
                <td style="padding:8px"><button class="btn btn-sm" onclick="cpRow(this)" style="background:rgba(255,255,255,0.05);border:1px solid var(--border);color:var(--muted)">📋</button></td>
              </tr>
              <tr style="border-bottom:1px solid var(--border)">
                <td style="padding:8px"><span class="log-action">TXT</span></td>
                <td style="padding:8px;font-family:monospace" class="dns-val">@</td>
                <td style="padding:8px;font-family:monospace;color:var(--yellow)" id="dns_rec_spf_val">v=spf1 mx a ip4:IP_SERVER_KAMU ~all</td>
                <td style="padding:8px;color:var(--muted)">Auto</td>
                <td style="padding:8px;color:var(--muted)">—</td>
                <td style="padding:8px;color:var(--muted)">Otorisasi server boleh kirim email</td>
                <td style="padding:8px"><button class="btn btn-sm" onclick="cpRow(this)" style="background:rgba(255,255,255,0.05);border:1px solid var(--border);color:var(--muted)">📋</button></td>
              </tr>
              <tr style="border-bottom:1px solid var(--border)">
                <td style="padding:8px"><span class="log-action">TXT</span></td>
                <td style="padding:8px;font-family:monospace" class="dns-val">_dmarc</td>
                <td style="padding:8px;font-family:monospace;color:var(--yellow)" id="dns_rec_dmarc_val">v=DMARC1; p=quarantine; rua=mailto:postmaster@DOMAIN_KAMU</td>
                <td style="padding:8px;color:var(--muted)">Auto</td>
                <td style="padding:8px;color:var(--muted)">—</td>
                <td style="padding:8px;color:var(--muted)">Policy email gagal autentikasi</td>
                <td style="padding:8px"><button class="btn btn-sm" onclick="cpRow(this)" style="background:rgba(255,255,255,0.05);border:1px solid var(--border);color:var(--muted)">📋</button></td>
              </tr>
              <tr id="dkim_row" style="border-bottom:1px solid var(--border)">
                <td style="padding:8px"><span class="log-action">TXT</span></td>
                <td style="padding:8px;font-family:monospace" class="dns-val">mail._domainkey</td>
                <td style="padding:8px;font-family:monospace;color:var(--yellow)" id="dkim_dns_val">⚠️ Setup DKIM dulu → klik Cek Status untuk dapat key</td>
                <td style="padding:8px;color:var(--muted)">Auto</td>
                <td style="padding:8px;color:var(--muted)">—</td>
                <td style="padding:8px;color:var(--muted)">Tanda tangan digital email (anti-spoofing)</td>
                <td style="padding:8px"><button class="btn btn-sm" onclick="cpRow(this)" style="background:rgba(255,255,255,0.05);border:1px solid var(--border);color:var(--muted)">📋</button></td>
              </tr>
            </tbody>
          </table>
          </div>
          <div style="margin-top:12px;font-size:11px;color:var(--muted);padding:8px;background:rgba(0,0,0,0.2);border-radius:6px">
            💡 <strong>Tips Cloudflare:</strong> Untuk MX & TXT → Proxy status = <span style="color:var(--yellow)">DNS only</span> (awan abu-abu, bukan orange). Untuk A record mail → juga DNS only.
          </div>
        </div>

        <!-- PTR / rDNS -->
        <div style="background:rgba(239,68,68,0.06);border:1px solid rgba(239,68,68,0.2);border-radius:10px;padding:16px;margin-bottom:16px">
          <div style="font-size:13px;font-weight:600;color:#f87171;margin-bottom:10px">🔄 PTR Record (rDNS) — Wajib untuk Email Deliverability</div>
          <div style="font-size:12px;color:var(--sub);line-height:2">
            <div>PTR record <strong>tidak bisa diset di DNS provider biasa</strong> — harus di panel VPS provider kamu.</div>
            <div style="margin-top:8px;display:grid;grid-template-columns:repeat(2,1fr);gap:12px">
              <div style="background:rgba(0,0,0,0.2);padding:10px;border-radius:8px">
                <div style="font-size:11px;font-weight:600;color:var(--cyan);margin-bottom:6px">📍 Cara Set PTR:</div>
                <div style="font-size:11px;line-height:1.8;color:var(--muted)">
                  • <strong>Vultr:</strong> Products → Network → Reverse DNS<br>
                  • <strong>DigitalOcean:</strong> Networking → Reserved IPs → Edit<br>
                  • <strong>Contabo:</strong> Customer Panel → VPS → rDNS<br>
                  • <strong>Hetzner:</strong> Console → Server → IPs → pencil icon<br>
                  • <strong>IDCloudHost:</strong> Panel → VPS → Reverse DNS
                </div>
              </div>
              <div style="background:rgba(0,0,0,0.2);padding:10px;border-radius:8px">
                <div style="font-size:11px;font-weight:600;color:var(--yellow);margin-bottom:6px">📝 Nilai PTR yang Diisi:</div>
                <div style="font-family:monospace;font-size:12px;background:rgba(0,0,0,0.3);padding:8px;border-radius:6px;color:var(--yellow)" id="ptr_val">
                  IP: IP_SERVER_KAMU<br>
                  PTR: mail.DOMAIN
                </div>
                <button class="btn btn-sm" onclick="cpPtr()" style="margin-top:8px;background:rgba(255,255,255,0.05);border:1px solid var(--border);color:var(--muted)">📋 Copy</button>
              </div>
            </div>
          </div>
        </div>

        <!-- CEK PROPAGASI -->
        <div style="background:rgba(6,182,212,0.06);border:1px solid rgba(6,182,212,0.2);border-radius:10px;padding:16px;margin-bottom:16px">
          <div style="font-size:13px;font-weight:600;color:var(--cyan);margin-bottom:10px">🌍 Cek Propagasi DNS</div>
          <div style="font-size:12px;color:var(--sub);line-height:2;margin-bottom:12px">
            Setelah menambahkan DNS records, tunggu 5-30 menit lalu cek propagasi:
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <a id="link_dnschecker" href="https://dnschecker.org/#MX/DOMAIN" target="_blank" class="btn btn-sm" style="background:rgba(6,182,212,0.15);border:1px solid rgba(6,182,212,0.3);color:var(--cyan);text-decoration:none">🌐 DNSChecker MX</a>
            <a id="link_mxtoolbox" href="https://mxtoolbox.com/SuperTool.aspx?action=mx%3aDOMAIN" target="_blank" class="btn btn-sm" style="background:rgba(6,182,212,0.15);border:1px solid rgba(6,182,212,0.3);color:var(--cyan);text-decoration:none">🔧 MXToolbox</a>
            <a id="link_spfcheck" href="https://www.spf-record.com/spf-lookup/DOMAIN" target="_blank" class="btn btn-sm" style="background:rgba(6,182,212,0.15);border:1px solid rgba(6,182,212,0.3);color:var(--cyan);text-decoration:none">✉️ SPF Check</a>
            <a id="link_dkimcheck" href="https://www.mail-tester.com/" target="_blank" class="btn btn-sm" style="background:rgba(6,182,212,0.15);border:1px solid rgba(6,182,212,0.3);color:var(--cyan);text-decoration:none">📊 Mail Tester</a>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:16px">
          <div style="background:rgba(124,58,237,0.08);border:1px solid rgba(124,58,237,0.2);border-radius:10px;padding:16px">
            <div style="font-size:13px;font-weight:600;color:var(--pl);margin-bottom:12px">📋 Step 1: DNS Records</div>
            <div style="font-size:12px;color:var(--sub);line-height:1.8">
              Login ke panel DNS provider Anda<br>
              (Cloudflare, cPanel, Domainesia, dll)<br><br>
              Tambahkan records berikut:
            </div>
            <table style="width:100%;margin-top:12px;font-size:11px">
              <tr style="border-bottom:1px solid var(--border)">
                <td style="padding:6px;color:var(--pl);font-weight:600">Type</td>
                <td style="padding:6px;color:var(--pl);font-weight:600">Name</td>
                <td style="padding:6px;color:var(--pl);font-weight:600">Value</td>
                <td style="padding:6px;color:var(--pl);font-weight:600">Prio</td>
              </tr>
              <tr style="border-bottom:1px solid var(--border)">
                <td style="padding:6px"><span class="log-action">A</span></td>
                <td style="padding:6px;font-family:monospace">mail</td>
                <td style="padding:6px;font-family:monospace">IP_SERVER_KAMU</td>
                <td style="padding:6px;color:var(--muted)">-</td>
              </tr>
              <tr style="border-bottom:1px solid var(--border)">
                <td style="padding:6px"><span class="log-action">MX</span></td>
                <td style="padding:6px;font-family:monospace">@</td>
                <td style="padding:6px;font-family:monospace">mail.DOMAIN</td>
                <td style="padding:6px;color:var(--muted)">10</td>
              </tr>
              <tr style="border-bottom:1px solid var(--border)">
                <td style="padding:6px"><span class="log-action">TXT</span></td>
                <td style="padding:6px;font-family:monospace">@</td>
                <td style="padding:6px;font-family:monospace">v=spf1 mx a ip4:IP_SERVER_KAMU ~all</td>
                <td style="padding:6px;color:var(--muted)">-</td>
              </tr>
              <tr style="border-bottom:1px solid var(--border)">
                <td style="padding:6px"><span class="log-action">TXT</span></td>
                <td style="padding:6px;font-family:monospace">_dmarc</td>
                <td style="padding:6px;font-family:monospace">v=DMARC1; p=quarantine; rua=mailto:postmaster@DOMAIN</td>
                <td style="padding:6px;color:var(--muted)">-</td>
              </tr>
              <tr>
                <td style="padding:6px"><span class="log-action">TXT</span></td>
                <td style="padding:6px;font-family:monospace">mail._domainkey</td>
                <td style="padding:6px;font-family:monospace;color:var(--yellow)">⚠️ Klik Setup DKIM dulu</td>
                <td style="padding:6px;color:var(--muted)">-</td>
              </tr>
            </table>
          </div>

          <div style="background:rgba(6,182,212,0.08);border:1px solid rgba(6,182,212,0.2);border-radius:10px;padding:16px">
            <div style="font-size:13px;font-weight:600;color:var(--cyan);margin-bottom:12px">⚙️ Step 2: Setup Server</div>
            <div style="font-size:12px;color:var(--sub);line-height:2">
              <div>1️⃣ Klik <strong style="color:var(--pl)">Setup DKIM</strong> → Generate key signing</div>
              <div>2️⃣ Copy DKIM key ke DNS provider</div>
              <div>3️⃣ Klik <strong style="color:var(--pl)">Setup SSL</strong> → Let's Encrypt auto</div>
              <div>4️⃣ Klik <strong style="color:var(--pl)">Setup Dovecot</strong> → IMAP config</div>
              <div>5️⃣ Tunggu DNS propagasi (5-30 menit)</div>
              <div>6️⃣ Klik <strong style="color:var(--pl)">Cek Status</strong> → Verifikasi semua ✅</div>
            </div>
          </div>

          <div style="background:rgba(16,185,129,0.08);border:1px solid rgba(16,185,129,0.2);border-radius:10px;padding:16px">
            <div style="font-size:13px;font-weight:600;color:var(--green);margin-bottom:12px">✅ Checklist Domain Sehat</div>
            <div style="font-size:12px;color:var(--sub);line-height:2">
              <div>✓ A record → IP server benar</div>
              <div>✓ MX record → mail.domain ada</div>
              <div>✓ SPF record → v=spf1 ada</div>
              <div>✓ DKIM → key sudah di DNS</div>
              <div>✓ DMARC → policy quarantine</div>
              <div>✓ SSL → certificate valid</div>
              <div>✓ Port 25 terbuka dari luar</div>
            </div>
          </div>

          <div style="background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.2);border-radius:10px;padding:16px">
            <div style="font-size:13px;font-weight:600;color:#f87171;margin-bottom:12px">⚠️ Catatan Penting</div>
            <div style="font-size:12px;color:var(--sub);line-height:2">
              <div>• PTR/rDNS: Set di VPS provider panel</div>
              <div>• SSL butuh domain sudah pointing ke IP</div>
              <div>• DKIM key beda tiap domain</div>
              <div>• DNS propagasi 5-30 menit</div>
              <div>• Port 25 harus tidak diblok VPS</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="card" id="dns_records_card" style="display:none">
      <div class="card-head"><span class="card-title">📋 DNS Records yang Harus Ditambah</span></div>
      <div style="padding:16px 20px">
        <div style="font-size:12px;color:var(--muted);margin-bottom:16px">Copy records berikut ke panel DNS provider Anda (Cloudflare, cPanel, Domainesia, dll)</div>
        <table>
          <thead><tr><th>Type</th><th>Name/Host</th><th>Value</th><th>Priority</th><th></th></tr></thead>
          <tbody id="dns_records_body"></tbody>
        </table>
      </div>
    </div>
  </div>

<div class="modal" id="editModal">
  <div class="modal-box">
    <div class="modal-title">✏️ Edit User</div>
    <form method="POST">
      <input type="hidden" name="action" value="edit_user">
      <input type="hidden" name="uid" id="edit_uid">
      <div class="modal-row"><label>Email</label><input class="inp" id="edit_email" disabled></div>
      <div class="modal-row">
        <label>Role</label>
        <select class="inp" name="urole" id="edit_role">
          <option value="user">User</option>
          <option value="admin">Admin</option>
          <?php if($role==='SUPER_ADMIN'): ?><option value="super_admin">Super Admin</option><?php endif; ?>
        </select>
      </div>
      <div class="modal-row"><label>Kuota Email</label><input class="inp" name="uquota" id="edit_quota" type="number" min="1"></div>
      <div class="modal-row"><label>Password Baru (kosongkan jika tidak diubah)</label><input class="inp" name="upass" type="password" placeholder="Password baru..."></div>
      <div class="modal-row">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" name="uban" id="edit_ban" value="1"> Ban user ini
        </label>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn" style="background:var(--card);border:1px solid var(--border)" onclick="closeEdit()">Batal</button>
        <button type="submit" class="btn btn-primary">💾 Simpan</button>
      </div>
    </form>
  </div>
</div>

<!-- Domain Password Modal -->
<!-- DOMAIN MODAL V2 -->

</div>


</style>

</div>

<div id="domainModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.85);z-index:9999;justify-content:center;align-items:center;padding:20px;box-sizing:border-box;">
  <div style="background:#1a1a2e;border:2px solid #6366f1;border-radius:14px;padding:36px;width:100%;max-width:480px;">
    <h2 id="modalTitle" style="color:#fff;margin:0 0 12px 0;font-size:22px;font-weight:700;">🔒</h2>
    <p id="modalMsg" style="color:#aaa;margin:0 0 28px 0;font-size:13px;line-height:1.6;"></p>
    
    <input type="password" id="pwd1" placeholder="Password" style="width:100%;padding:11px 13px;background:#0f0f1e;border:2px solid #333;border-radius:9px;color:#fff;font-size:13px;margin-bottom:14px;box-sizing:border-box;font-family:inherit;">
    
    <input type="password" id="pwd2" placeholder="Konfirmasi" style="width:100%;padding:11px 13px;background:#0f0f1e;border:2px solid #333;border-radius:9px;color:#fff;font-size:13px;margin-bottom:28px;box-sizing:border-box;font-family:inherit;display:none;">
    
    <div style="display:flex;gap:10px;">
      <button id="btnOK" style="flex:1;padding:11px;background:#6366f1;color:#fff;border:none;border-radius:9px;font-weight:700;cursor:pointer;font-size:14px;transition:all 0.2s;">OK</button>
      <button id="btnCancel" style="flex:1;padding:11px;background:#333;color:#999;border:none;border-radius:9px;font-weight:700;cursor:pointer;font-size:14px;">Batal</button>
    </div>
  </div>
</div>

<script>
function notify(type, title, msg) {
  var cont = document.getElementById('notifContainer');
  var n = document.createElement('div');
  var icons = {ok: '✓', err: '✕', warn: '⚠'};
  n.className = 'notif ' + type;
  n.innerHTML = '<strong>' + title + '</strong><br>' + msg;
  if (icons[type]) {
    n.innerHTML = '<span style="margin-right:8px;font-size:16px;">' + icons[type] + '</span>' + n.innerHTML;
  }
  cont.appendChild(n);
  setTimeout(() => { n.classList.add('out'); setTimeout(() => n.remove(), 400); }, 3500);
}

var state = {action: null, id: null, domain: null, newPwd: false, sending: false};

document.getElementById('btnCancel').onclick = () => {
  document.getElementById('domainModal').style.display = 'none';
};

document.getElementById('btnOK').onclick = async () => {
  var p1 = document.getElementById('pwd1').value.trim();
  var p2 = document.getElementById('pwd2').value.trim();
  
  if (!p1) { notify('warn', 'Peringatan', 'Password tidak boleh kosong'); return; }
  if (state.newPwd && !p2) { notify('warn', 'Peringatan', 'Konfirmasi password diperlukan'); return; }
  if (state.newPwd && p1.length < 6) { notify('warn', 'Peringatan', 'Password minimal 6 karakter'); return; }
  if (state.newPwd && p1 !== p2) { notify('err', 'Tidak Cocok', 'Pastikan kedua password sama'); return; }
  
  if (state.sending) return;
  state.sending = true;
  document.getElementById('btnOK').disabled = true;
  
  var fd = new FormData();
  fd.append('action', state.action);
  fd.append('id', state.id);
  fd.append('domain', state.domain);
  fd.append(state.newPwd ? '_pwd_new' : '_pwd_login', p1);
  
  try {
    console.log('Sending request to handler...', fd);
    var r = await fetch('/mailgen/admin_handlers.php', {method:'POST', body:fd, credentials:'include'});
    console.log('Response status:', r.status);
    var j = await r.json();
    console.log('Response data:', j);
    document.getElementById('domainModal').style.display = 'none';
    if (j.success) {
      notify('ok', 'Berhasil!', j.message);
      setTimeout(() => location.reload(), 1200);
    } else {
      notify('err', 'Gagal', j.error);
      state.sending = false;
      document.getElementById('btnOK').disabled = false;
    }
  } catch(e) {
    notify('err', 'Error', e.message);
    state.sending = false;
    document.getElementById('btnOK').disabled = false;
  }
};

document.addEventListener('DOMContentLoaded', () => {
  // Auto-update DNS guide saat page load
  updateDnsGuide();
  document.querySelectorAll('[data-toggle-domain]').forEach(b => {
    b.onclick = (e) => {
      e.preventDefault();
      state.id = b.dataset.toggleDomain;
      state.domain = b.dataset.domain;
      state.action = 'toggle_domain';
      var active = parseInt(b.dataset.active);
      
      if (active) {
        state.newPwd = true;
        document.getElementById('modalTitle').textContent = '🔒 Nonaktifkan Domain';
        document.getElementById('modalMsg').textContent = 'Domain: ' + state.domain + '\n\nBuat password BARU (min 6 karakter)';
        document.getElementById('pwd2').style.display = 'block';
      } else {
        state.newPwd = false;
        document.getElementById('modalTitle').textContent = '🔓 Aktifkan Domain';
        document.getElementById('modalMsg').textContent = 'Domain: ' + state.domain + '\n\nMasukkan password unlock';
        document.getElementById('pwd2').style.display = 'none';
      }
      
      document.getElementById('pwd1').value = '';
      document.getElementById('pwd2').value = '';
      document.getElementById('domainModal').style.display = 'flex';
      document.getElementById('pwd1').focus();
    };
  });
  
  document.querySelectorAll('[data-delete-domain]').forEach(b => {
    b.onclick = (e) => {
      e.preventDefault();
      if (!confirm('⚠️ HAPUS: ' + b.dataset.domain + '?')) return;
      if (!confirm('Yakin? Ini permanen!')) return;
      
      state.id = b.dataset.deleteDomain;
      state.domain = b.dataset.domain;
      state.action = 'delete_domain';
      state.newPwd = true;
      
      document.getElementById('modalTitle').textContent = '🗑 HAPUS DOMAIN';
      document.getElementById('modalMsg').textContent = 'Domain: ' + state.domain + '\n\nMasukkan password admin 2x';
      document.getElementById('pwd2').style.display = 'block';
      document.getElementById('pwd1').value = '';
      document.getElementById('pwd2').value = '';
      document.getElementById('domainModal').style.display = 'flex';
      document.getElementById('pwd1').focus();
    };
  });
});
</script>

<script>
function switchTab(name, el) {
    // Hide all sections
    document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
    // Deactivate all tabs
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    // Show selected section
    var sec = document.getElementById('tab-' + name);
    if (sec) sec.classList.add('active');
    // Activate clicked tab
    if (el) el.classList.add('active');
}
</script>

<script>
function openEdit(u) {
    document.getElementById('edit_uid').value   = u.id;
    document.getElementById('edit_email').value = u.email;
    document.getElementById('edit_quota').value = u.quota;
    document.getElementById('edit_ban').checked = u.is_banned == 1;

    var sel = document.getElementById('edit_role');
    for (var i = 0; i < sel.options.length; i++) {
        if (sel.options[i].value === u.role) {
            sel.selectedIndex = i;
            break;
        }
    }

    document.getElementById('editModal').classList.add('open');
}

function closeEdit() {
    document.getElementById('editModal').classList.remove('open');
}

// Tutup modal kalau klik backdrop
document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) closeEdit();
});
</script>

<script>
// ── TOGGLE PASSWORD VISIBILITY ──
function togglePw(id) {
    var span = document.getElementById('pw' + id);
    var btn  = document.getElementById('eye' + id);
    var pwd  = span.dataset.pwd;
    if (span.dataset.visible === '1') {
        span.textContent = '••••••••••••';
        span.dataset.visible = '0';
        btn.textContent = '👁';
    } else {
        span.textContent = pwd;
        span.dataset.visible = '1';
        btn.textContent = '🙈';
    }
}

// ── COPY TO CLIPBOARD ──
function cpText(id) {
    var pwd = document.getElementById('pw' + id).dataset.pwd;
    // Gunakan execCommand karena site HTTP (bukan HTTPS)
    var el = document.createElement('textarea');
    el.value = pwd;
    el.style.position = 'fixed';
    el.style.top = '0';
    el.style.left = '0';
    el.style.opacity = '0';
    document.body.appendChild(el);
    el.focus();
    el.select();
    try {
        document.execCommand('copy');
        notify('ok', 'Disalin!', 'Password berhasil disalin ke clipboard');
    } catch(e) {
        notify('err', 'Gagal', 'Tidak bisa copy: ' + e.message);
    }
    document.body.removeChild(el);
}

// ── FILTER LOGS ──
function filterLogs() {
    var search = document.getElementById('logSearch').value.toLowerCase();
    var action = document.getElementById('logAction').value.toLowerCase();
    document.querySelectorAll('.log-row').forEach(function(row) {
        var email  = row.dataset.email  || '';
        var act    = row.dataset.action || '';
        var detail = row.dataset.detail || '';
        var matchSearch = !search || email.includes(search) || act.includes(search) || detail.includes(search);
        var matchAction = !action || act.includes(action);
        row.style.display = (matchSearch && matchAction) ? '' : 'none';
    });
}

// ── LOAD DOMAIN STATUS (DNS) ──
function callDomainAPI(action, label) {
    var domain = document.getElementById('dns_domain').value;
    if (!domain) { notify('warn','Peringatan','Pilih domain dulu'); return; }
    var box = document.getElementById('dns_status');
    box.innerHTML = '<div style="color:var(--muted);text-align:center;padding:20px">⏳ ' + label + '...</div>';

    var fd = new FormData();
    fd.append('action', action);
    fd.append('domain', domain);

    fetch('/mailgen/domain_setup_api.php', {method:'POST', body:fd, credentials:'include'})
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (action === 'check') {
                renderCheckResult(data, domain);
            } else {
                var ok = data.ok;
                var out = data.output || data.error || '';
                box.innerHTML = '<div style="padding:16px">'
                    + '<div style="font-weight:600;margin-bottom:8px;color:' + (ok?'var(--green)':'#f87171') + '">'
                    + (ok ? '✅ ' : '❌ ') + label + (ok ? ' Berhasil!' : ' Gagal') + '</div>'
                    + '<pre style="font-size:11px;color:var(--sub);white-space:pre-wrap;background:rgba(0,0,0,0.3);padding:12px;border-radius:8px;max-height:300px;overflow-y:auto">' + out + '</pre>'
                    + (ok ? '' : '<div style="margin-top:8px;font-size:12px;color:var(--muted)">Cek log untuk detail error</div>')
                    + '</div>';
                if (ok) notify('ok', 'Berhasil!', label + ' selesai');
                else notify('err', 'Gagal', label + ' gagal — lihat output');
            }
        })
        .catch(function(e) {
            box.innerHTML = '<div style="color:#f87171;padding:20px">❌ Error: ' + e.message + '</div>';
            notify('err', 'Error', e.message);
        });
}

function renderCheckResult(data, domain) {
    var out = data.output || '';
    var lines = out.split('\n');
    var parsed = {};
    lines.forEach(function(l) {
        var p = l.indexOf('=');
        if (p > 0) parsed[l.substring(0,p)] = l.substring(p+1);
    });

    var rows = [
        {name:'DKIM Key',       key:'DKIM_STATUS',  dns:'DKIM_DNS'},
        {name:'SSL Certificate',key:'SSL_STATUS',    dns:null},
        {name:'MX Record',      key:'MX_CHECK',      dns:null},
        {name:'SPF Record',     key:'SPF_CHECK',     dns:null},
        {name:'DKIM DNS',       key:'DKIM_DNS',      dns:null},
        {name:'DMARC',          key:'DMARC_CHECK',   dns:null},
    ];

    var html = '<div style="padding:16px">';
    html += '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">';
    html += '<button class="btn btn-primary btn-sm" onclick="callDomainAPI(&quot;dkim&quot;,&quot;Setup DKIM&quot;)">🔑 Setup DKIM</button>';
    html += '<button class="btn btn-primary btn-sm" onclick="callDomainAPI(&quot;ssl&quot;,&quot;Setup SSL&quot;)">🔒 Setup SSL</button>';
    html += '<button class="btn btn-primary btn-sm" onclick="callDomainAPI(&quot;dovecot&quot;,&quot;Setup Dovecot&quot;)">📬 Setup Dovecot</button>';
    html += '<button class="btn btn-warn btn-sm" onclick="loadDomainStatus()">🔄 Refresh</button>';
    html += '</div>';
    html += '<table style="width:100%;font-size:13px">';
    html += '<thead><tr><th>Komponen</th><th>Status</th><th>Detail</th></tr></thead><tbody>';

    rows.forEach(function(r) {
        var val = parsed[r.key] || '';
        var ok = val && val !== 'NOT_SETUP' && val !== '' && !val.includes('FAIL');
        var icon = val ? (ok ? '✅' : '❌') : '⚪';
        var detail = val || 'Belum dicek';
        if (r.key === 'DKIM_STATUS' && parsed['DKIM_KEY']) detail += ' | Key: ' + parsed['DKIM_KEY'].substring(0,30) + '...';
        html += '<tr><td style="padding:8px 16px;font-weight:500">' + r.name + '</td>';
        html += '<td style="padding:8px 16px">' + icon + '</td>';
        html += '<td style="padding:8px 16px;color:var(--muted);font-size:11px;word-break:break-all">' + detail + '</td></tr>';
    });

    html += '</tbody></table>';

    if (parsed['DKIM_KEY']) {
        html += '<div style="margin-top:16px;background:rgba(124,58,237,0.1);border:1px solid rgba(124,58,237,0.3);border-radius:8px;padding:12px">';
        html += '<div style="font-size:12px;font-weight:600;color:var(--pl);margin-bottom:8px">📋 DKIM DNS Record — Copy ke DNS Provider:</div>';
        html += '<div style="font-size:11px;color:var(--muted);margin-bottom:4px">Type: TXT | Name: mail._domainkey.' + domain + '</div>';
        html += '<div style="font-family:monospace;font-size:11px;color:var(--sub);background:rgba(0,0,0,0.3);padding:8px;border-radius:6px;word-break:break-all">v=DKIM1; k=rsa; p=' + parsed['DKIM_KEY'] + '</div>';
        html += '</div>';
    }
    html += '</div>';

    document.getElementById('dns_status').innerHTML = html;

    // Update DKIM key di tabel DNS guide
    if (parsed['DKIM_KEY']) {
        var dkimVal = document.getElementById('dkim_dns_val');
        if (dkimVal) dkimVal.textContent = 'v=DKIM1; k=rsa; p=' + parsed['DKIM_KEY'];
    }
}

// ── WIZARD STEPS ──
function showWizStep(n) {
    // Update tab styling
    for (var i = 1; i <= 5; i++) {
        var tab = document.getElementById('wiz' + i);
        var con = document.getElementById('wiz_content_' + i);
        if (tab) {
            if (i === n) {
                tab.style.background = 'rgba(124,58,237,0.15)';
                tab.querySelector('div:nth-child(2)').style.color = 'var(--pl)';
            } else {
                tab.style.background = '';
                tab.querySelector('div:nth-child(2)').style.color = 'var(--sub)';
            }
        }
        if (con) con.style.display = (i === n) ? 'block' : 'none';
    }
    // Update wiz_a_rec di step 3
    var domain = document.getElementById('dns_domain').value || 'DOMAIN_KAMU';
    var ip = document.getElementById('dns_rec_a_val') ? document.getElementById('dns_rec_a_val').textContent : 'IP_SERVER';
    var wizA = document.getElementById('wiz_a_rec');
    if (wizA) wizA.textContent = 'mail.' + domain + ' → ' + ip;
}

// ── DNS GUIDE FUNCTIONS ──
function updateDnsGuide() {
    var domain = document.getElementById('dns_domain').value || 'DOMAIN_KAMU';
    var ip = 'IP_SERVER_KAMU';

    // Update semua record di tabel DNS
    var records = {
        'dns_rec_a_val':    'IP_SERVER_KAMU',
        'dns_rec_mx_val':   'mail.DOMAIN_KAMU',
        'dns_rec_spf_val':  'v=spf1 mx a ip4:IP_SERVER_KAMU ~all',
        'dns_rec_dmarc_val':'v=DMARC1; p=quarantine; rua=mailto:postmaster@DOMAIN_KAMU',
        'dns_rec_mx_name':  'mail.DOMAIN_KAMU',
    };
    Object.keys(records).forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.textContent = records[id];
    });

    // Update PTR value
    var ptrEl = document.getElementById('ptr_val');
    if (ptrEl) ptrEl.innerHTML = 'IP: IP_SERVER_KAMU<br>PTR: mail.DOMAIN_KAMU';

    // Update SSL cmd domain
    var sslCmd = document.getElementById('ssl_manual_cmd');
    if (sslCmd) {
        sslCmd.innerHTML = sslCmd.innerHTML.replace(/yourdomain\.com/g, domain);
    }

    // Update links propagasi
    var links = {
        'link_dnschecker': 'https://dnschecker.org/#MX/' + domain,
        'link_mxtoolbox':  'https://mxtoolbox.com/SuperTool.aspx?action=mx%3a' + domain,
        'link_spfcheck':   'https://www.spf-record.com/spf-lookup/' + domain,
    };
    Object.keys(links).forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.href = links[id];
    });
}

function cpRow(btn) {
    var row = btn.closest('tr');
    var cells = row.querySelectorAll('td');
    var type = cells[0].textContent.trim();
    var name = cells[1].textContent.trim();
    var val  = cells[2].textContent.trim();
    var txt  = type + ' | ' + name + ' | ' + val;
    var el = document.createElement('textarea');
    el.value = txt;
    el.style.position = 'fixed'; el.style.opacity = '0';
    document.body.appendChild(el); el.select();
    document.execCommand('copy');
    document.body.removeChild(el);
    notify('ok', 'Disalin!', type + ' record berhasil disalin');
}

function cpAllDns() {
    var rows = document.querySelectorAll('#dns_tbl_body tr');
    var lines = [];
    rows.forEach(function(row) {
        var cells = row.querySelectorAll('td');
        if (cells.length >= 3) {
            lines.push(cells[0].textContent.trim() + ' | ' + cells[1].textContent.trim() + ' | ' + cells[2].textContent.trim());
        }
    });
    var el = document.createElement('textarea');
    el.value = lines.join('\n');
    el.style.position = 'fixed'; el.style.opacity = '0';
    document.body.appendChild(el); el.select();
    document.execCommand('copy');
    document.body.removeChild(el);
    notify('ok', 'Semua Disalin!', lines.length + ' DNS records berhasil disalin');
}

function cpPtr() {
    var domain = document.getElementById('dns_domain').value || 'DOMAIN';
    var txt = 'IP_SERVER_KAMU → mail.' + domain;
    var el = document.createElement('textarea');
    el.value = txt;
    el.style.position = 'fixed'; el.style.opacity = '0';
    document.body.appendChild(el); el.select();
    document.execCommand('copy');
    document.body.removeChild(el);
    notify('ok', 'Disalin!', 'PTR record berhasil disalin');
}

function cpSslCmd() {
    var domain = document.getElementById('dns_domain').value || 'DOMAIN';
    var cmd = document.getElementById('ssl_manual_cmd').innerText.replace(/DOMAIN/g, domain);
    var el = document.createElement('textarea');
    el.value = cmd;
    el.style.position = 'fixed';
    el.style.opacity = '0';
    document.body.appendChild(el);
    el.select();
    document.execCommand('copy');
    document.body.removeChild(el);
    notify('ok', 'Disalin!', 'Command SSL berhasil disalin');
}

function loadDomainStatus() {
    updateDnsGuide();
    callDomainAPI('check', 'Cek Status');
}
</script>
</body>
</html>
