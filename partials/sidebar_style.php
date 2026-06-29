<style>
.sidebar{width:220px;min-height:100vh;background:var(--sidebar,#13131c);border-right:1px solid var(--border,#252533);display:flex;flex-direction:column;position:fixed;left:0;top:0;bottom:0;z-index:100}
.sidebar .logo{padding:20px 18px;display:flex;align-items:center;gap:10px;border-bottom:1px solid var(--border,#252533)}
.sidebar .logo-icon{width:32px;height:32px;background:linear-gradient(135deg,var(--purple,#7c3aed),#4f46e5);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:16px}
.sidebar .logo-text{font-size:16px;font-weight:700}
.sidebar .profile{padding:16px 18px;border-bottom:1px solid var(--border,#252533);text-align:center}
.sidebar .avatar{width:44px;height:44px;background:linear-gradient(135deg,var(--purple,#7c3aed),#4f46e5);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:700;color:#fff;margin:0 auto 10px}
.sidebar .uname{font-size:14px;font-weight:600}
.sidebar .uemail{font-size:11px;color:var(--muted,#9090a0);margin-top:2px;word-break:break-all}
.sidebar .badge{display:inline-block;margin-top:8px;padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700;background:linear-gradient(135deg,var(--purple,#7c3aed),#4f46e5);color:#fff}
.sidebar .nav{flex:0 0 auto;padding:12px 0}
.sidebar .nav a{display:flex;align-items:center;gap:10px;padding:10px 18px;color:var(--sub,#c0c0cc);text-decoration:none;font-size:13px;font-weight:500;border-left:3px solid transparent;transition:all .2s}
.sidebar .nav a:hover,.sidebar .nav a.active{background:rgba(124,58,237,0.12);color:var(--pl,#a78bfa);border-left-color:var(--purple,#7c3aed)}
.sidebar .nav-divider{height:1px;background:rgba(255,255,255,0.06);margin:8px 0}
.sidebar .sidebar-foot{margin-top:auto;padding:12px 0;border-top:1px solid var(--border,#252533)}
.sidebar .sidebar-foot .logout-btn{display:flex;align-items:center;padding:10px 18px;color:#f87171;text-decoration:none;font-size:13px;font-weight:500;border-radius:8px;transition:all .2s;width:100%;border-left:3px solid transparent}
.sidebar .sidebar-foot .logout-btn:hover{background:rgba(239,68,68,0.1);border-left-color:#f87171}
@media(max-width:768px){.sidebar{display:none}}
</style>
