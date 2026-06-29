<?php
session_start();
require_once 'config.php';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pass  = trim($_POST['password'] ?? '');
    if ($email && $pass) {
        $user = verify_mailgen_user($email, $pass);
        if ($user) {
            setcookie('mailgen_session', create_session_token($user['id']), time()+86400*30, '/');
            header('Location: /mailgen/index.php'); exit;
        } else { $error = 'Email atau password salah'; }
    } else { $error = 'Harap isi email dan password'; }
}
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login — FamilyMail</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#0a0a0f;--surface:#111118;--surface2:#18181f;--surface3:#1e1e28;--border:#ffffff0f;--border2:#ffffff18;--p:#7c6fff;--p2:#a78bfa;--p3:#6d5dfc;--cyan:#22d3ee;--green:#10b981;--red:#f43f5e;--t1:#f1f0ff;--t2:#9898b8;--t3:#55556a;--r:16px;--r2:12px}
html,body{min-height:100vh}
body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg);color:var(--t1);display:flex;align-items:center;justify-content:center;padding:20px;position:relative;overflow-x:hidden}
.grid-bg{position:fixed;inset:0;background-image:linear-gradient(var(--border) 1px,transparent 1px),linear-gradient(90deg,var(--border) 1px,transparent 1px);background-size:48px 48px;pointer-events:none}
.grad-orb{position:fixed;border-radius:50%;pointer-events:none}
.grad-orb.tl{width:500px;height:500px;top:-200px;left:-200px;background:radial-gradient(circle,rgba(124,111,255,0.12) 0%,transparent 70%)}
.grad-orb.br{width:400px;height:400px;bottom:-150px;right:-150px;background:radial-gradient(circle,rgba(34,211,238,0.08) 0%,transparent 70%)}
.wrap{position:relative;z-index:1;width:100%;max-width:400px}
.brand{text-align:center;margin-bottom:32px}
.brand-icon{width:56px;height:56px;background:linear-gradient(135deg,var(--p3),var(--p2));border-radius:16px;display:flex;align-items:center;justify-content:center;font-size:1.6rem;margin:0 auto 14px;box-shadow:0 8px 24px rgba(124,111,255,0.3)}
.brand-name{font-size:1.5rem;font-weight:800;background:linear-gradient(135deg,var(--p2),var(--cyan));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;font-family:'JetBrains Mono',monospace;letter-spacing:-0.5px;margin-bottom:6px}
.brand-sub{font-size:0.875rem;color:var(--t3)}
.card{background:rgba(17,17,24,0.8);border:1px solid var(--border2);border-radius:var(--r);padding:32px;backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px)}
.alert{padding:12px 14px;border-radius:10px;margin-bottom:20px;font-size:0.875rem;font-weight:500;background:rgba(244,63,94,0.1);border:1px solid rgba(244,63,94,0.2);color:#fda4af;display:flex;align-items:center;gap:8px}
.alert::before{content:'⚠️'}
.form-group{margin-bottom:18px}
.label{display:block;font-size:0.8rem;font-weight:600;color:var(--t2);margin-bottom:8px;text-transform:uppercase;letter-spacing:0.4px}
.input{width:100%;padding:12px 14px;border:1px solid var(--border2);border-radius:var(--r2);background:var(--surface2);color:var(--t1);font-family:'Plus Jakarta Sans',sans-serif;font-size:0.95rem;outline:none;transition:.15s}
.input:focus{border-color:var(--p);box-shadow:0 0 0 3px rgba(124,111,255,0.12)}
.input::placeholder{color:var(--t3)}
.btn-submit{width:100%;padding:13px;border:none;border-radius:var(--r2);background:linear-gradient(135deg,var(--p3),var(--p));color:#fff;font-size:0.95rem;font-weight:700;cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif;transition:all .15s;box-shadow:0 4px 14px rgba(124,111,255,0.3);margin-top:4px}
.btn-submit:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(124,111,255,0.4)}
.btn-submit:active{transform:translateY(0)}
.divider{height:1px;background:var(--border2);margin:24px 0}
.footer-link{text-align:center;font-size:0.875rem;color:var(--t2)}
.footer-link a{color:var(--p2);text-decoration:none;font-weight:700}
.footer-link a:hover{color:var(--cyan)}
</style>
</head>
<body>
<div class="grid-bg"></div>
<div class="grad-orb tl"></div>
<div class="grad-orb br"></div>
<div class="wrap">
  <div class="brand">
    <div class="brand-icon">✉</div>
    <div class="brand-name">FamilyMail</div>
    <div class="brand-sub">Login untuk akses email sementara Anda</div>
  </div>
  <div class="card">
    <?php if($error): ?><div class="alert"><?=htmlspecialchars($error)?></div><?php endif; ?>
    <form method="POST">
      <div class="form-group">
        <label class="label">Email</label>
        <input type="email" name="email" class="input" placeholder="nama@example.com" required autofocus>
      </div>
      <div class="form-group">
        <label class="label">Password</label>
        <input type="password" name="password" class="input" placeholder="••••••••" required>
      </div>
      <button type="submit" class="btn-submit">Masuk →</button>
    </form>
    <div class="divider"></div>
    <div class="footer-link">Belum punya akun? <a href="/mailgen/register.php">Daftar sekarang</a></div>
  </div>
</div>
</body>
</html>
