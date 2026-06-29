<?php
// Normalisasi variabel - aman dipanggil dari file manapun
if (!isset($user) || !$user) { $user = get_mailgen_user(); }
if (!isset($role)) {
    if (isset($is_admin)) {
        $role = $is_admin ? 'SUPER_ADMIN' : 'USER';
    } else {
        $role = strtoupper($user['role'] ?? 'USER');
    }
}
$role = strtoupper($role);
$is_admin_view = in_array($role, ['SUPER_ADMIN', 'ADMIN']);
$initials = strtoupper(substr($user['username'] ?? $user['email'] ?? 'U', 0, 1));
$display_name = $user['username'] ?? (isset($user['email']) ? explode('@', $user['email'])[0] : 'User');
$current = basename($_SERVER['PHP_SELF']);
?>
<aside class="sidebar">
  <div class="logo"><div class="logo-icon">📧</div><span class="logo-text">FamilyMail</span></div>
  <div class="profile">
    <div class="avatar"><?= htmlspecialchars($initials) ?></div>
    <div class="uname"><?= htmlspecialchars($display_name) ?></div>
    <div class="uemail"><?= htmlspecialchars($user['email'] ?? '') ?></div>
    <span class="badge"><?= htmlspecialchars($role) ?></span>
  </div>
  <nav class="nav">
    <a href="index.php" class="<?= $current==='index.php'?'active':'' ?>">📬 Inbox</a>
    <a href="settings.php" class="<?= $current==='settings.php'?'active':'' ?>">⚙️ Pengaturan</a>
    <a href="docs.php" class="<?= $current==='docs.php'?'active':'' ?>">📄 Dokumentasi</a>
  </nav>
  <?php if ($is_admin_view): ?>
  <div class="nav-divider"></div>
  <nav class="nav">
    <a href="admin.php" class="<?= $current==='admin.php'?'active':'' ?>">🛡️ Admin Panel</a>
  </nav>
  <?php endif; ?>
  <div class="sidebar-foot">
    <a href="logout.php" class="logout-btn">
      <span style="display:flex;align-items:center;gap:8px;width:100%">
        <span>🚪</span>
        <span>Logout</span>
      </span>
    </a>
  </div>
</aside>
