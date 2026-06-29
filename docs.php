<?php
session_start();
require_once 'config.php';
$user = get_mailgen_user();
if (!$user) { header('Location: /mailgen/login.php'); exit; }
$is_admin = is_super_admin($user);
$initials = strtoupper(substr($user['email'],0,1));
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dokumentasi — FamilyMail</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#0a0a0f;--surface:#111118;--surface2:#18181f;--surface3:#1e1e28;--border:#ffffff0f;--border2:#ffffff18;--p:#7c6fff;--p2:#a78bfa;--p3:#6d5dfc;--cyan:#22d3ee;--green:#10b981;--red:#f43f5e;--t1:#f1f0ff;--t2:#9898b8;--t3:#55556a;--r:14px;--r2:10px}
html{scroll-behavior:smooth}
body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg);color:var(--t1);min-height:100vh;line-height:1.6}
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
.user-badge{font-size:0.65rem;font-weight:700;padding:3px 10px;border-radius:20px;text-align:center;display:block}
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
.doc-card{background:var(--surface);border:1px solid var(--border2);border-radius:var(--r);padding:24px;margin-bottom:16px}
.doc-card-title{font-size:1rem;font-weight:700;color:var(--p2);margin-bottom:12px;display:flex;align-items:center;gap:8px}
.doc-card p{color:var(--t2);font-size:0.9rem;margin-bottom:10px;line-height:1.7}
.doc-card p:last-child{margin-bottom:0}
.doc-card strong{color:var(--t1)}
.steps{display:flex;flex-direction:column;gap:12px;margin-top:4px}
.step{display:flex;gap:14px;align-items:flex-start}
.step-num{width:28px;height:28px;background:linear-gradient(135deg,var(--p3),var(--p));border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:0.8rem;font-weight:800;flex-shrink:0;margin-top:1px}
.step-text{color:var(--t2);font-size:0.9rem;line-height:1.6;padding-top:3px}
.feat-list{display:flex;flex-direction:column;gap:8px;margin-top:4px}
.feat-item{display:flex;align-items:center;gap:10px;color:var(--t2);font-size:0.9rem}
.feat-item::before{content:'✓';color:var(--green);font-weight:800;flex-shrink:0}
.faq-item{border-bottom:1px solid var(--border);padding:14px 0}
.faq-item:last-child{border-bottom:none;padding-bottom:0}
.faq-q{font-size:0.9rem;font-weight:700;color:var(--t1);margin-bottom:6px}
.faq-a{font-size:0.875rem;color:var(--t2);line-height:1.6}
.mobile-bar{display:none;position:fixed;bottom:0;left:0;right:0;background:rgba(17,17,24,0.95);border-top:1px solid var(--border2);padding:8px 16px;z-index:100;backdrop-filter:blur(20px)}
.mobile-nav{display:flex;justify-content:space-around}
.mobile-link{display:flex;flex-direction:column;align-items:center;gap:3px;padding:8px 12px;border-radius:10px;color:var(--t3);text-decoration:none;font-size:0.65rem;font-weight:600;transition:.15s;border:none;background:none;cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif}
.mobile-link.active{color:var(--p2);background:rgba(124,111,255,0.1)}
.mobile-link-icon{font-size:1.1rem}
@media(max-width:900px){.sidebar{display:none}.main{margin-left:0;max-width:100%;padding:20px 16px 90px}.mobile-bar{display:block}}
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
    <h1 class="page-title">📖 Dokumentasi</h1>
    <p class="page-subtitle">Panduan lengkap penggunaan FamilyMail</p>
  </div>
  <div class="doc-card">
    <div class="doc-card-title">🎯 Apa itu FamilyMail?</div>
    <p>FamilyMail adalah layanan email sementara <strong>self-hosted</strong> untuk keluarga Anda. Buat email disposable untuk keperluan sign-up, verifikasi, atau aktivitas online apa pun — tanpa risiko spam di email utama.</p>
    <div class="feat-list">
      <div class="feat-item">Email sementara dengan masa berlaku terbatas</div>
      <div class="feat-item">Automatic IMAP fetch — email masuk langsung tampil</div>
      <div class="feat-item">Buat, copy, hapus dengan cepat</div>
      <div class="feat-item">Self-hosted — data 100% di server pribadi Anda</div>
    </div>
  </div>
  <div class="doc-card">
    <div class="doc-card-title">🚀 Cara Membuat Email Sementara</div>
    <div class="steps">
      <div class="step"><div class="step-num">1</div><div class="step-text">Buka halaman <strong>Inbox Sementara</strong> dari sidebar</div></div>
      <div class="step"><div class="step-num">2</div><div class="step-text">Isi <strong>prefix</strong> di kolom kiri (bagian sebelum @), contoh: <em>john</em></div></div>
      <div class="step"><div class="step-num">3</div><div class="step-text">Pilih <strong>domain</strong> dari dropdown yang tersedia</div></div>
      <div class="step"><div class="step-num">4</div><div class="step-text">Klik <strong>Buat Email</strong> — email siap digunakan!</div></div>
    </div>
  </div>
  <div class="doc-card">
    <div class="doc-card-title">📧 Melihat Email Masuk</div>
    <p>Setelah email dibuat, langsung gunakan untuk sign-up di situs mana pun. Email yang masuk otomatis di-fetch via IMAP dan ditampilkan di dashboard.</p>
    <p>Klik tombol <strong>Inbox</strong> pada email yang ingin Anda cek untuk melihat semua pesan — pengirim, subject, tanggal, dan isi lengkap.</p>
  </div>
  <div class="doc-card">
    <div class="doc-card-title">🛡️ Keamanan & Privacy</div>
    <p>Semua data email disimpan di database lokal. <strong>Tidak ada data</strong> yang dikirim ke pihak ketiga. Email sementara otomatis terhapus setelah masa berlaku habis.</p>
    <p>Gunakan password yang kuat dan ubah secara berkala melalui halaman <strong>Pengaturan</strong>.</p>
  </div>
  <div class="doc-card">
    <div class="doc-card-title">❓ FAQ</div>
    <div class="faq-item">
      <div class="faq-q">Berapa lama email tersimpan?</div>
      <div class="faq-a">Default 30 hari sejak dibuat. Admin bisa mengubah durasi sesuai kebutuhan.</div>
    </div>
    <div class="faq-item">
      <div class="faq-q">Bisa membuat berapa email?</div>
      <div class="faq-a">Sesuai kuota yang diberikan admin. Default 50 email per user.</div>
    </div>
    <div class="faq-item">
      <div class="faq-q">Email bisa dipulihkan setelah dihapus?</div>
      <div class="faq-a">Tidak. Penghapusan bersifat permanen — baik dari database maupun mailbox.</div>
    </div>
  </div>
</main>
</div>
<div class="mobile-bar">
  <nav class="mobile-nav">
    <a href="/mailgen/index.php" class="mobile-link"><span class="mobile-link-icon">📧</span>Inbox</a>
    <a href="/mailgen/settings.php" class="mobile-link"><span class="mobile-link-icon">⚙️</span>Setelan</a>
    <a href="/mailgen/docs.php" class="mobile-link active"><span class="mobile-link-icon">📖</span>Docs</a>
    <?php if($is_admin): ?><a href="/mailgen/admin.php" class="mobile-link"><span class="mobile-link-icon">🛡️</span>Admin</a><?php endif; ?>
  </nav>
</div>
</body>
</html>
