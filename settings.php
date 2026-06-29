<?php
session_start();
require_once 'config.php';
$user = get_mailgen_user();
if (!$user) { header('Location: /mailgen/login.php'); exit; }
$is_admin = is_super_admin($user);
$msg = ''; $msg_type = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'change_password') {
        $old = $_POST['old_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $cnf = $_POST['confirm_password'] ?? '';
        if (!$old||!$new||!$cnf) { $msg='Harap isi semua field'; $msg_type='error'; }
        elseif (strlen($new)<8) { $msg='Password baru minimal 8 karakter'; $msg_type='error'; }
        elseif ($new!==$cnf) { $msg='Password baru tidak cocok'; $msg_type='error'; }
        elseif (verify_mailgen_user($user['email'],$old)) {
            update_mailgen_password($user['id'],$new);
            $msg='Password berhasil diubah'; $msg_type='success';
        } else { $msg='Password lama salah'; $msg_type='error'; }
    } elseif ($action === 'logout') {
        setcookie('mailgen_session','',time()-3600,'/');
        header('Location: /mailgen/login.php'); exit;
    }
}
$initials = strtoupper(substr($user['email'],0,1));
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pengaturan — FamilyMail</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#0a0a0f;--surface:#111118;--surface2:#18181f;--surface3:#1e1e28;--border:#ffffff0f;--border2:#ffffff18;--p:#7c6fff;--p2:#a78bfa;--p3:#6d5dfc;--cyan:#22d3ee;--green:#10b981;--red:#f43f5e;--t1:#f1f0ff;--t2:#9898b8;--t3:#55556a;--r:14px;--r2:10px}
html{scroll-behavior:smooth}
body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg);color:var(--t1);min-height:100vh;line-height:1.5}
.grid-bg{position:fixed;inset:0;background-image:linear-gradient(var(--border) 1px,transparent 1px),linear-gradient(90deg,var(--border) 1px,transparent 1px);background-size:48px 48px;z-index:0;pointer-events:none}
.grad-orb{position:fixed;width:500px;height:500px;top:-200px;left:-200px;border-radius:50%;background:radial-gradient(circle,rgba(124,111,255,0.08) 0%,transparent 70%);pointer-events:none;z-index:0}
.app{position:relative;z-index:1;display:flex;min-height:100vh}
.sidebar{width:256px;background:rgba(17,17,24,0.8);border-right:1px solid var(--border2);padding:20px 16px;display:flex;flex-direction:column;position:fixed;left:0;top:0;height:100vh;overflow-y:auto;backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px)}
.sidebar-logo{display:flex;align-items:center;gap:10px;padding:8px 12px;margin-bottom:24px}
.logo-icon{width:36px;height:36px;background:linear-gradient(135deg,var(--p),var(--cyan));border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;box-shadow:0 4px 12px rgba(124,111,255,0.3)}
.logo-text{font-size:1rem;font-weight:800;background:linear-gradient(135deg,var(--p2),var(--cyan));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;font-family:'JetBrains Mono',monospace}
.user-block{background:var(--surface2);border:1px solid var(--border2);border-radius:var(--r);padding:14px;margin-bottom:20px}
.user-avatar{width:40px;height:40px;background:linear-gradient(135deg,var(--p3),var(--p2));border-radius:10px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1rem;margin:0 auto 10px}
.user-name{font-size:0.8rem;font-weight:700;text-align:center;margin-bottom:2px}
.user-email-text{font-size:0.7rem;color:var(--t3);text-align:center;word-break:break-all;margin-bottom:8px}
.user-badge{font-size:0.65rem;font-weight:700;padding:3px 10px;border-radius:20px;text-align:center;display:block;letter-spacing:0.5px}
.badge-user{background:rgba(124,111,255,0.15);color:var(--p2);border:1px solid rgba(124,111,255,0.25)}
.badge-admin{background:rgba(244,63,94,0.15);color:#fb7185;border:1px solid rgba(244,63,94,0.25)}
.menu{display:flex;flex-direction:column;gap:2px;flex:1}
.menu a{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:var(--r2);color:var(--t2);font-size:0.875rem;font-weight:500;text-decoration:none;transition:all .15s;position:relative}
.menu a:hover{background:var(--surface3);color:var(--t1)}
.menu a.active{background:rgba(124,111,255,0.12);color:var(--p2);font-weight:600}
.menu a.active::before{content:'';position:absolute;left:0;top:50%;transform:translateY(-50%);width:3px;height:60%;background:var(--p);border-radius:0 3px 3px 0}
.sidebar-footer{margin-top:auto;padding-top:16px;border-top:1px solid var(--border)}
.logout-btn{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:var(--r2);color:var(--red);font-size:0.875rem;font-weight:500;border:1px solid rgba(244,63,94,0.12);background:rgba(244,63,94,0.08);width:100%;cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif;transition:.15s}
.logout-btn:hover{background:rgba(244,63,94,0.15)}
.main{flex:1;margin-left:256px;padding:32px;max-width:calc(100% - 256px)}
.page-header{margin-bottom:28px}
.page-title{font-size:1.6rem;font-weight:800;letter-spacing:-0.5px;margin-bottom:4px}
.page-subtitle{color:var(--t3);font-size:0.875rem}
.alert{padding:12px 16px;border-radius:10px;margin-bottom:20px;font-size:0.875rem;font-weight:500;display:flex;align-items:center;gap:10px}
.alert-success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.2);color:#6ee7b7}
.alert-error{background:rgba(244,63,94,0.1);border:1px solid rgba(244,63,94,0.2);color:#fda4af}
.card{background:var(--surface);border:1px solid var(--border2);border-radius:var(--r);padding:24px;margin-bottom:20px}
.card-header{display:flex;align-items:center;gap:12px;margin-bottom:20px;padding-bottom:16px;border-bottom:1px solid var(--border)}
.card-icon{font-size:1.2rem}
.card-title{font-size:1rem;font-weight:700}
.info-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:4px}
.info-box{background:var(--surface2);border:1px solid var(--border);border-radius:var(--r2);padding:14px}
.info-label{font-size:0.7rem;font-weight:600;color:var(--t3);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px}
.info-value{font-family:'JetBrains Mono',monospace;font-size:0.875rem;font-weight:600;color:var(--p2);word-break:break-all}
.form-group{margin-bottom:16px}
.label{display:block;font-size:0.8rem;font-weight:600;color:var(--t2);margin-bottom:8px;text-transform:uppercase;letter-spacing:0.4px}
.input{width:100%;padding:11px 14px;border:1px solid var(--border2);border-radius:var(--r2);background:var(--surface2);color:var(--t1);font-family:'Plus Jakarta Sans',sans-serif;font-size:0.9rem;outline:none;transition:.15s}
.input:focus{border-color:var(--p);box-shadow:0 0 0 3px rgba(124,111,255,0.1)}
.input::placeholder{color:var(--t3)}
.btn-primary{padding:11px 20px;border:none;border-radius:var(--r2);background:linear-gradient(135deg,var(--p3),var(--p));color:#fff;font-size:0.875rem;font-weight:700;cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif;transition:all .15s;box-shadow:0 4px 12px rgba(124,111,255,0.25)}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(124,111,255,0.35)}
.mobile-bar{display:none;position:fixed;bottom:0;left:0;right:0;background:rgba(17,17,24,0.95);border-top:1px solid var(--border2);padding:8px 16px;z-index:100;backdrop-filter:blur(20px)}
.mobile-nav{display:flex;justify-content:space-around}
.mobile-link{display:flex;flex-direction:column;align-items:center;gap:3px;padding:8px 12px;border-radius:10px;color:var(--t3);text-decoration:none;font-size:0.65rem;font-weight:600;transition:.15s;border:none;background:none;cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif}
.mobile-link.active{color:var(--p2);background:rgba(124,111,255,0.1)}
.mobile-link-icon{font-size:1.1rem}
@media(max-width:900px){.sidebar{display:none}.main{margin-left:0;max-width:100%;padding:20px 16px 90px}.mobile-bar{display:block}}
@media(max-width:560px){.info-row{grid-template-columns:1fr}}
<?php include __DIR__.'/partials/sidebar_style.php'; ?>
</style>
</head>
<body>
<div class="grid-bg"></div>
<div class="grad-orb"></div>
<div class="app">
<?php include __DIR__.'/partials/sidebar.php'; ?>
<main class="main">
  <div class="page-header">
    <h1 class="page-title">⚙️ Pengaturan</h1>
    <p class="page-subtitle">Kelola akun dan keamanan Anda</p>
  </div>
  <?php if($msg): ?><div class="alert alert-<?=$msg_type?>"><?=$msg_type==='success'?'✅':'⚠️'?> <?=htmlspecialchars($msg)?></div><?php endif; ?>
  <div class="card">
    <div class="card-header"><span class="card-icon">📋</span><span class="card-title">Informasi Akun</span></div>
    <div class="info-row">
      <div class="info-box"><div class="info-label">Email</div><div class="info-value"><?=htmlspecialchars($user['email'])?></div></div>
      <div class="info-box"><div class="info-label">Kuota Email</div><div class="info-value"><?=$user['quota']?> email</div></div>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-icon">🔐</span><span class="card-title">Ubah Password</span></div>
    <form method="POST">
      <input type="hidden" name="action" value="change_password">
      <div class="form-group"><label class="label">Password Lama</label><input type="password" name="old_password" class="input" placeholder="••••••••" required></div>
      <div class="form-group"><label class="label">Password Baru</label><input type="password" name="new_password" class="input" placeholder="••••••••" required></div>
      <div class="form-group"><label class="label">Konfirmasi Password Baru</label><input type="password" name="confirm_password" class="input" placeholder="••••••••" required></div>
      <button type="submit" class="btn-primary">Ubah Password</button>
    </form>
  </div>
</main>
</div>
<div class="mobile-bar">
  <nav class="mobile-nav">
    <a href="/mailgen/index.php" class="mobile-link"><span class="mobile-link-icon">📧</span>Inbox</a>
    <a href="/mailgen/settings.php" class="mobile-link active"><span class="mobile-link-icon">⚙️</span>Setelan</a>
    <a href="/mailgen/docs.php" class="mobile-link"><span class="mobile-link-icon">📖</span>Docs</a>
    <?php if($is_admin): ?><a href="/mailgen/admin.php" class="mobile-link"><span class="mobile-link-icon">🛡️</span>Admin</a><?php endif; ?>
  </nav>
</div>
</body>
</html>
