# 📬 FamilyMail — Self-Hosted Temporary Email System

> Platform email sementara berbasis PHP + Postfix + Dovecot + OpenDKIM.  
> Generate email disposable, terima email real-time, panel admin lengkap dengan DNS Setup wizard.

![PHP](https://img.shields.io/badge/PHP-8.2-777BB4?style=flat-square&logo=php)
![MySQL](https://img.shields.io/badge/MySQL-8.0-4479A1?style=flat-square&logo=mysql)
![Postfix](https://img.shields.io/badge/Postfix-MTA-red?style=flat-square)
![License](https://img.shields.io/badge/License-MIT-green?style=flat-square)

---

## 🖼️ Fitur Utama

- ✅ Generate email disposable sekali pakai
- ✅ Terima email real-time (polling setiap 2 detik)
- ✅ Panel admin lengkap — kelola domain, user, DNS
- ✅ DNS Setup Wizard (DKIM, SSL, SPF, DMARC otomatis)
- ✅ Multi-domain support
- ✅ Role system: `super_admin`, `admin`, `user`
- ✅ Activity logs
- ✅ Self-hosted — data 100% di server sendiri

---

## 📋 Halaman

| Halaman | Fungsi |
|---------|--------|
| `index.php` | Generate & kelola email |
| `inbox.php` | Baca email masuk, real-time polling |
| `admin.php` | Admin panel — domain, user, DNS, logs |
| `settings.php` | Ganti password akun |
| `docs.php` | Dokumentasi untuk user |

---

## 🧱 Stack Teknologi

| Komponen | Detail |
|----------|--------|
| Backend | PHP 8.2 |
| Database | MySQL 8 — 2 database: `mailgen_db` + `postfixadmin` |
| Mail Server | Postfix (MTA) + Dovecot (IMAP) |
| DKIM Signing | OpenDKIM |
| Mail Storage | Maildir di `/var/mail/vmail/` |
| Web Server | Nginx |
| SSL | Let's Encrypt via Certbot |

---

## ⚡ Quick Install

```bash
# 1. Clone repo
git clone https://github.com/TheGeneral-Meta/FamilyMail-Self-Hosted-Temporary-Email-System.git mailgen
cd mailgen

# 2. Setup config
cp config.example.php config.php
cp .env.example .env
nano config.php   # isi DB credentials
nano .env         # isi secret key

# 3. Install dependensi server
# Lihat INSTALL.md untuk panduan lengkap
```

> 📖 Lihat **[INSTALL.md](INSTALL.md)** untuk panduan instalasi lengkap dari nol.

---

## 📁 Struktur File

```
mailgen/
├── index.php              # Halaman utama
├── inbox.php              # Inbox real-time
├── login.php              # Login user
├── register.php           # Registrasi
├── logout.php             # Logout
├── admin.php              # Admin panel
├── admin_handlers.php     # AJAX handler domain
├── admin_domain_api.php   # API domain management
├── domain_setup_api.php   # API setup domain (SUPER_ADMIN)
├── check_mail.php         # Polling cek email baru
├── settings.php           # Pengaturan akun
├── docs.php               # Dokumentasi
├── session_config.php     # Konfigurasi session
├── config.example.php     # Template config ← COPY ke config.php
├── .env.example           # Template env ← COPY ke .env
├── auth/
│   └── google-callback.php
└── partials/
    ├── sidebar.php
    └── sidebar_style.php
```

---

## 🌐 DNS Records

Tambahkan di panel DNS provider (Cloudflare, cPanel, dll):

| Type | Name | Value | Priority |
|------|------|-------|----------|
| A | mail | IP_SERVER | — |
| MX | @ | mail.DOMAIN | 10 |
| TXT | @ | `v=spf1 mx a ip4:IP_SERVER ~all` | — |
| TXT | _dmarc | `v=DMARC1; p=quarantine; rua=mailto:postmaster@DOMAIN` | — |
| TXT | mail._domainkey | *(generate via admin panel)* | — |

> ⚠️ **Cloudflare:** Set semua record ke **DNS only** (awan abu-abu)

---

## 🔒 Keamanan

File yang **JANGAN** di-commit ke GitHub:
- `config.php` — berisi password database
- `.env` — berisi secret key API

Keduanya sudah ada di `.gitignore`.

---

## 📄 Lisensi

MIT License — bebas digunakan, dimodifikasi, dan didistribusikan.
