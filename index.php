<?php
require 'config.php';

$user = get_mailgen_user();
if (!$user) { header('Location: login.php'); exit; }

$error = '';

function setup_maildir($email) {
    $out = shell_exec("sudo /usr/local/bin/add_vmailbox.sh " . escapeshellarg($email) . " 2>&1");
    return strpos($out, 'OK') !== false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_email'])) {
    $prefix = trim($_POST['prefix'] ?? '');
    $domain = trim($_POST['domain'] ?? 'familyhosting.my.id');

    if (empty($prefix) || strlen($prefix) < 3) {
        $error = 'Prefix minimal 3 karakter';
    } elseif (!preg_match('/^[a-zA-Z0-9._-]+$/', $prefix)) {
        $error = 'Hanya huruf, angka, titik, dash, underscore';
    } else {
        $email = $prefix . '@' . $domain;
        $stmt = $pdo->prepare('SELECT id FROM temp_emails WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'Email sudah terdaftar!';
        } else {
            // Cek kuota (super_admin unlimited)
            $used = $pdo->prepare('SELECT COUNT(*) FROM temp_emails WHERE user_id = ?');
            $used->execute([$user['id']]);
            $is_super = strtolower($user['role'] ?? '') === 'super_admin';
            if (!$is_super && $used->fetchColumn() >= ($user['quota'] ?? 5)) {
                $error = 'Kuota email habis! Maksimal ' . ($user['quota'] ?? 5) . ' email.';
            } else {
                $pass = bin2hex(random_bytes(8));
                $stmt = $pdo->prepare('INSERT INTO temp_emails (user_id, email, imap_pass, created_at) VALUES (?, ?, ?, NOW())');
                if ($stmt->execute([$user['id'], $email, $pass])) {
                    setup_maildir($email);
                    log_activity($user['email'], 'create_email', "Membuat email: $email | Password SnappyMail: $pass", $_SERVER['REMOTE_ADDR'] ?? 'unknown');
                    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?created=' . urlencode($email));
                    exit;
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_email'])) {
    $eid = intval($_POST['eid'] ?? 0);
    $s = $pdo->prepare('SELECT email FROM temp_emails WHERE id = ? AND user_id = ?');
    $s->execute([$eid, $user['id']]);
    $target_email = $s->fetchColumn();

    if ($target_email) {
        $pdo->prepare('DELETE FROM temp_emails WHERE id = ? AND user_id = ?')->execute([$eid, $user['id']]);
        shell_exec("sudo /usr/local/bin/remove_vmailbox.sh " . escapeshellarg($target_email) . " 2>&1");
        log_activity($user['email'], 'delete_email', "Menghapus email: $target_email", $_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_email'])) {
    $is_super_check = strtolower($user['role'] ?? '') === 'super_admin';
    if (!$is_super_check) {
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    $eid = intval($_POST['eid'] ?? 0);
    $s = $pdo->prepare('SELECT email FROM temp_emails WHERE id = ?');
    $s->execute([$eid]);
    $target_email = $s->fetchColumn();

    if ($target_email) {
        $pdo->prepare('DELETE FROM temp_emails WHERE id = ?')->execute([$eid]);
        shell_exec("sudo /usr/local/bin/remove_vmailbox.sh " . escapeshellarg($target_email) . " 2>&1");
        log_activity($user['email'], 'delete_email', "Menghapus email: $target_email", $_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

$stmt = $pdo->prepare('SELECT id, email, imap_pass, created_at FROM temp_emails WHERE user_id = ? ORDER BY created_at DESC');
$stmt->execute([$user['id']]);
$emails = $stmt->fetchAll();

$stmt2 = $pdo->query('SELECT domain FROM domains WHERE is_active = 1 ORDER BY domain');
$domains = $stmt2 ? $stmt2->fetchAll(PDO::FETCH_COLUMN) : ['familyhosting.my.id'];
if (empty($domains)) $domains = ['familyhosting.my.id'];

$is_super_admin = strtolower($user['role'] ?? '') === 'super_admin';
$quota = $is_super_admin ? PHP_INT_MAX : ($user['quota'] ?? 5);
$used  = count($emails);
$sisa  = $is_super_admin ? '∞' : ($quota - $used);
$role  = strtoupper($user['role'] ?? 'user');
$created_email = $_GET['created'] ?? '';

function count_messages($email) {
    list($local, $domain) = explode('@', $email);
    $count = 0;
    foreach (['new', 'cur'] as $sub) {
        $path = "/var/mail/vmail/$domain/$local/Maildir/$sub";
        if (is_dir($path)) $count += count(array_diff(scandir($path), ['.','..']));
    }
    return $count;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>FamilyMail - Inbox Sementara</title>
<style>
:root{--bg:#0a0a14;--sidebar:#0f0f1e;--card:rgba(255,255,255,0.04);--border:rgba(255,255,255,0.08);--purple:#7c3aed;--pl:#a78bfa;--text:#e2e8f0;--muted:#64748b;--sub:#94a3b8;--green:#10b981;--cyan:#06b6d4}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex}
.sidebar{width:220px;min-height:100vh;background:var(--sidebar);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;left:0;top:0;bottom:0;z-index:100}
.logo{padding:20px 18px;display:flex;align-items:center;gap:10px;border-bottom:1px solid var(--border)}
.logo-icon{width:32px;height:32px;background:linear-gradient(135deg,var(--purple),#4f46e5);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:16px}
.logo-text{font-size:16px;font-weight:700}
.profile{padding:16px 18px;border-bottom:1px solid var(--border);text-align:center}
.avatar{width:44px;height:44px;background:linear-gradient(135deg,var(--purple),#4f46e5);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:700;color:#fff;margin:0 auto 10px}
.uname{font-size:14px;font-weight:600}
.uemail{font-size:11px;color:var(--muted);margin-top:2px;word-break:break-all}
.badge{display:inline-block;margin-top:8px;padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700;background:linear-gradient(135deg,var(--purple),#4f46e5);color:#fff}
.nav{flex:1;padding:12px 0}
.nav a{display:flex;align-items:center;gap:10px;padding:10px 18px;color:var(--sub);text-decoration:none;font-size:13px;font-weight:500;border-left:3px solid transparent;transition:all .2s}
.nav a:hover,.nav a.active{background:rgba(124,58,237,0.12);color:var(--pl);border-left-color:var(--purple)}
.sidebar-foot{padding:12px 0;border-top:1px solid var(--border)}.sidebar-foot a{display:flex;align-items:center;gap:10px;padding:12px 18px;color:#f87171;text-decoration:none;font-size:13px;font-weight:500;border-radius:8px;transition:all .2s;border-left:3px solid transparent}.sidebar-foot a:hover{background:rgba(239,68,68,0.1);border-left-color:#f87171}
.main{margin-left:220px;flex:1;padding:28px}
.ptitle{font-size:24px;font-weight:700;margin-bottom:4px}
.psub{font-size:13px;color:var(--muted);margin-bottom:24px}
.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px}
.stat{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:20px;position:relative;overflow:hidden}
.stat::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--purple),var(--pl))}
.slabel{font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:10px}
.snum{font-size:32px;font-weight:700}
.snum.g{color:var(--green)}.snum.c{color:var(--cyan)}
.ssub{font-size:11px;color:var(--muted);margin-top:6px}
.sbar{height:3px;background:rgba(255,255,255,0.06);border-radius:2px;margin-top:10px}
.sbar-fill{height:100%;background:linear-gradient(90deg,var(--purple),var(--pl));border-radius:2px}
.ccard{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:20px;margin-bottom:20px}
.cheader{font-size:15px;font-weight:600;margin-bottom:16px}
.frow{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.frow input{flex:1;min-width:160px;padding:10px 14px;background:rgba(0,0,0,0.3);border:1px solid var(--border);border-radius:8px;color:var(--text);font-size:13px}
.frow input:focus{outline:none;border-color:var(--purple)}
.at{color:var(--pl);font-weight:700;font-size:16px}
.frow select{padding:10px 14px;background:rgba(0,0,0,0.3);border:1px solid var(--border);border-radius:8px;color:var(--text);font-size:13px;min-width:180px}
.bcreate{padding:10px 20px;background:linear-gradient(135deg,var(--purple),#4f46e5);border:none;border-radius:8px;color:#fff;font-weight:600;font-size:13px;cursor:pointer;white-space:nowrap}
.bcreate:hover{opacity:.9;transform:translateY(-1px)}
.prev{font-size:12px;color:var(--pl);margin-top:8px;padding:7px 12px;background:rgba(124,58,237,0.08);border-radius:6px}
.aerr{padding:10px 14px;background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);border-radius:8px;color:#fca5a5;font-size:13px;margin-bottom:12px}
.elist{background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden}
.elhead{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.eltitle{font-size:14px;font-weight:600;display:flex;align-items:center;gap:8px}
.ecnt{background:rgba(124,58,237,0.2);color:var(--pl);border:1px solid rgba(124,58,237,0.3);padding:2px 10px;border-radius:20px;font-size:11px;font-weight:600}
.erow{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:14px;transition:all .2s}
.erow:last-child{border-bottom:none}
.erow:hover{background:rgba(255,255,255,0.03)}
.eavatar{width:40px;height:40px;background:linear-gradient(135deg,var(--purple),#4f46e5);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}
.einfo{flex:1;min-width:0}
.eaddr{font-size:14px;font-weight:600;color:var(--pl);margin-bottom:5px;word-break:break-all}
.emeta{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.mtag{display:inline-flex;align-items:center;gap:4px;font-size:11px;color:var(--muted);background:rgba(255,255,255,0.04);border:1px solid var(--border);padding:2px 8px;border-radius:4px}
.mtag.g{color:var(--green);background:rgba(16,185,129,0.08);border-color:rgba(16,185,129,0.2)}
.prow{display:flex;align-items:center;gap:6px;margin-top:6px}
.plabel{font-size:11px;color:var(--muted)}
.pval{font-size:11px;font-family:monospace;color:var(--sub);letter-spacing:1px}
.bicon{width:26px;height:26px;border-radius:6px;border:1px solid var(--border);background:rgba(255,255,255,0.04);color:var(--sub);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:12px}
.eact{display:flex;gap:6px;flex-shrink:0}
.binbox{padding:7px 14px;background:rgba(124,58,237,0.15);border:1px solid rgba(124,58,237,0.3);border-radius:8px;color:var(--pl);text-decoration:none;font-size:12px;font-weight:600}
.binbox:hover{background:rgba(124,58,237,0.25)}
.bcopy{padding:7px 10px;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:8px;color:var(--sub);font-size:12px;cursor:pointer}
.bdelete{padding:7px 14px;background:linear-gradient(135deg,rgba(239,68,68,0.12),rgba(220,38,38,0.18));border:1px solid rgba(239,68,68,0.35);border-radius:8px;color:#fca5a5;font-size:12px;font-weight:600;cursor:pointer;transition:all .2s cubic-bezier(.4,0,.2,1);box-shadow:0 0 0 0 rgba(239,68,68,0);position:relative;overflow:hidden}
.bdelete:hover{background:linear-gradient(135deg,#ef4444,#dc2626);border-color:#ef4444;color:#fff;transform:translateY(-1px);box-shadow:0 4px 12px -2px rgba(239,68,68,0.5)}
.bdelete:active{transform:translateY(0);box-shadow:0 2px 6px -2px rgba(239,68,68,0.4)}
.empty{text-align:center;padding:60px 20px;color:var(--muted)}
.toast{position:fixed;top:20px;right:20px;background:rgba(16,185,129,0.15);border:1px solid rgba(16,185,129,0.3);color:#6ee7b7;padding:12px 18px;border-radius:10px;font-size:13px;z-index:9999;animation:si .3s ease}
@keyframes si{from{opacity:0;transform:translateX(20px)}to{opacity:1;transform:translateX(0)}}
@media(max-width:768px){.sidebar{display:none}.main{margin-left:0;padding:16px}.stats{grid-template-columns:repeat(2,1fr)}}
.logout-btn{display:flex;align-items:center;padding:10px 12px;color:#f87171;text-decoration:none;font-size:13px;font-weight:500;border-radius:8px;transition:all .2s;width:100%;border-left:3px solid transparent}.logout-btn:hover{background:rgba(239,68,68,0.1);border-left-color:#f87171}.nav-divider{height:1px;background:rgba(255,255,255,0.06);margin:8px 0}
<?php include __DIR__.'/partials/sidebar_style.php'; ?>
</style>
</head>
<body>
<?php include __DIR__.'/partials/sidebar.php'; ?>

<main class="main">
  <div class="ptitle">Inbox Sementara</div>
  <div class="psub">Kelola email disposable Anda</div>
  <div class="stats">
    <div class="stat">
      <div class="slabel">EMAIL AKTIF</div>
      <div class="snum"><?= $used ?></div>
      <div class="ssub"><?= $is_super_admin ? 'Unlimited' : 'dari '.$quota.' kuota' ?></div>
      <div class="sbar"><div class="sbar-fill" style="width:<?= $is_super_admin ? 100 : ($quota>0?min(100,round($used/$quota*100)):0) ?>%"></div></div>
    </div>
    <div class="stat">
      <div class="slabel">DOMAIN</div>
      <div class="snum c"><?= count($domains) ?></div>
      <div class="ssub">tersedia</div>
    </div>
    <div class="stat">
      <div class="slabel">SISA KUOTA</div>
      <div class="snum g"><?= $sisa ?></div>
      <div class="ssub">bisa dibuat</div>
    </div>
  </div>
  <div class="ccard">
    <div class="cheader">➕ Buat Email Baru</div>
    <?php if($error): ?><div class="aerr">❌ <?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="POST">
      <div class="frow">
        <input type="text" name="prefix" id="prefix" placeholder="prefix (contoh: john)" required autocomplete="off" oninput="updatePreview()">
        <span class="at">@</span>
        <select name="domain" id="domSel" onchange="updatePreview()">
          <?php foreach($domains as $d): ?><option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></option><?php endforeach; ?>
        </select>
        <button type="submit" name="create_email" class="bcreate">✦ Buat Email</button>
      </div>
      <div class="prev" id="prev">📧 prefix@<?= htmlspecialchars($domains[0]??'familyhosting.my.id') ?></div>
    </form>
  </div>
  <div class="elist">
    <div class="elhead">
      <span class="eltitle">📋 Email Anda <span class="ecnt"><?= $used ?> email</span></span>
    </div>
    <?php if(empty($emails)): ?>
      <div class="empty"><div style="font-size:48px;margin-bottom:12px">📭</div><div>Belum ada email. Buat email baru!</div></div>
    <?php else: ?>
      <?php foreach($emails as $e): ?>
        <?php
          $addr = $e['email'];
          $pass = $e['imap_pass'];
          $created = date('d M Y', strtotime($e['created_at']));
          $expires = date('d M Y', strtotime($e['created_at'].' +30 days'));
          $mc = count_messages($addr);
        ?>
        <div class="erow">
          <div class="eavatar">✉️</div>
          <div class="einfo">
            <div class="eaddr"><?= htmlspecialchars($addr) ?></div>
            <div class="emeta">
              <span class="mtag">📅 <?= $created ?></span>
              <span class="mtag">⏳ <?= $expires ?></span>
              <span class="mtag <?= $mc>0?'g':'' ?>"><?= $mc>0?"✅ $mc pesan":'0 pesan' ?></span>
            </div>
            <?php if(in_array($role,['SUPER_ADMIN','ADMIN'])): ?>
            <div class="prow">
              <span class="plabel">🔑 Pass:</span>
              <span class="pval" id="p<?= $e['id'] ?>">••••••••••••</span>
              <button class="bicon" onclick="tp(<?= $e['id'] ?>,'<?= htmlspecialchars($pass,ENT_QUOTES) ?>')">👁</button>
              <button class="bicon" onclick="cp('<?= htmlspecialchars($pass,ENT_QUOTES) ?>')">📋</button>
            </div>
            <?php endif; ?>
          </div>
          <div class="eact">
            <button class="bcopy" onclick="cp('<?= htmlspecialchars($addr,ENT_QUOTES) ?>')">📋 Copy</button>
            <a href="inbox.php?id=<?= $e['id'] ?>" class="binbox">📬 Inbox</a>
            <form method="POST" style="display:inline" onsubmit="return confirm('Hapus email <?= htmlspecialchars($addr,ENT_QUOTES) ?>? Semua pesan di dalamnya juga akan terhapus permanen.')">
              <input type="hidden" name="delete_email" value="1">
              <input type="hidden" name="eid" value="<?= $e['id'] ?>">
              <button type="submit" class="bdelete">🗑 Hapus</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</main>
<?php if($created_email): ?>
<div class="toast" id="toast">✅ <strong><?= htmlspecialchars($created_email) ?></strong> berhasil dibuat!</div>
<script>setTimeout(()=>{const t=document.getElementById('toast');if(t)t.remove();},4000)</script>
<?php endif; ?>
<script>
function updatePreview(){
  const p=document.getElementById('prefix').value||'prefix';
  const d=document.getElementById('domSel').value;
  document.getElementById('prev').textContent='📧 '+p+'@'+d;
}
function cp(t){
  navigator.clipboard.writeText(t).then(()=>{
    const e=document.createElement('div');e.className='toast';e.textContent='📋 Copied: '+t;
    document.body.appendChild(e);setTimeout(()=>e.remove(),2000);
  });
}
function tp(id,pass){
  const el=document.getElementById('p'+id);
  el.textContent=el.textContent.includes('•')?pass:'••••••••••••';
  el.style.color=el.textContent.includes('•')?'':'#a78bfa';
}
document.getElementById('prefix').addEventListener('input',function(){this.value=this.value.replace(/[^a-zA-Z0-9._-]/g,'');});
updatePreview();
</script>
</body>
</html>
