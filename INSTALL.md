# 📦 INSTALL.md — Panduan Instalasi FamilyMail

Panduan instalasi lengkap dari nol di Ubuntu 22.04 LTS.

---

## 🖥️ Kebutuhan Server

| Komponen | Minimum |
|----------|---------|
| OS | Ubuntu 22.04 LTS |
| CPU | 1 vCPU |
| RAM | 1 GB |
| Storage | 20 GB SSD |
| Port | 25, 80, 443, 143, 587 harus terbuka |

> ⚠️ **Port 25** adalah port SMTP — beberapa VPS provider membloknya secara default.  
> Konfirmasi ke provider kamu (Vultr, Contabo, Hetzner, IDCloudHost, dll) sebelum mulai.

---

## 1. Install Dependensi

```bash
apt update && apt upgrade -y

apt install -y \
  nginx \
  php8.2 php8.2-fpm php8.2-mysql php8.2-imap \
  php8.2-mbstring php8.2-curl php8.2-xml php8.2-common \
  mysql-server \
  postfix postfix-mysql \
  dovecot-core dovecot-imapd dovecot-mysql \
  opendkim opendkim-tools \
  certbot python3-certbot-nginx \
  git curl dnsutils \
  mailutils
```

> Saat install Postfix, pilih **"Internet Site"** dan isi domain utama kamu.

---

## 2. Setup MySQL — 2 Database Terpisah

```bash
mysql -u root -p
```

```sql
-- Database 1: Aplikasi mailgen
CREATE DATABASE mailgen_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Database 2: Postfixadmin (mail server config)
CREATE DATABASE postfixadmin CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- User database
CREATE USER 'mailgen_user'@'127.0.0.1' IDENTIFIED BY 'GANTI_PASSWORD_KUAT';
GRANT ALL PRIVILEGES ON mailgen_db.*    TO 'mailgen_user'@'127.0.0.1';
GRANT ALL PRIVILEGES ON postfixadmin.* TO 'mailgen_user'@'127.0.0.1';
FLUSH PRIVILEGES;
EXIT;
```

---

## 3. Buat Tabel Database

### mailgen_db

```sql
USE mailgen_db;

CREATE TABLE users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(255) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  auth_method   VARCHAR(50)  DEFAULT 'password',
  role          ENUM('user','admin','super_admin') DEFAULT 'user',
  quota         INT          DEFAULT 10,
  verified      TINYINT(1)   DEFAULT 1,
  is_banned     TINYINT(1)   DEFAULT 0,
  created_at    DATETIME     DEFAULT NOW(),
  last_login    DATETIME
);

CREATE TABLE domains (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  domain           VARCHAR(255) UNIQUE NOT NULL,
  is_active        TINYINT(1)   DEFAULT 1,
  disable_password VARCHAR(255),
  disabled_at      TIMESTAMP    NULL,
  created_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE temp_emails (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT          NOT NULL,
  email      VARCHAR(255) UNIQUE NOT NULL,
  imap_pass  VARCHAR(255) NOT NULL,
  created_at DATETIME     DEFAULT NOW(),
  expires_at DATETIME,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE messages (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  temp_email_id  INT     NOT NULL,
  sender_email   VARCHAR(255),
  subject        TEXT,
  body           LONGTEXT,
  message_id     VARCHAR(500),
  received_at    DATETIME DEFAULT NOW(),
  FOREIGN KEY (temp_email_id) REFERENCES temp_emails(id) ON DELETE CASCADE
);

CREATE TABLE sessions (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  email      VARCHAR(255) NOT NULL,
  token      VARCHAR(255) UNIQUE NOT NULL,
  created_at DATETIME     DEFAULT NOW(),
  expires_at DATETIME     NOT NULL
);

CREATE TABLE activity_logs (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_email VARCHAR(255),
  action     VARCHAR(100),
  detail     TEXT,
  ip         VARCHAR(45),
  created_at DATETIME DEFAULT NOW()
);
```

### postfixadmin

```sql
USE postfixadmin;

CREATE TABLE domain (
  domain      VARCHAR(255) NOT NULL PRIMARY KEY,
  description TEXT,
  aliases     INT          DEFAULT 0,
  mailboxes   INT          DEFAULT 0,
  maxquota    BIGINT       DEFAULT 0,
  quota       BIGINT       DEFAULT 0,
  transport   VARCHAR(255) DEFAULT 'virtual',
  backupmx    TINYINT(1)   DEFAULT 0,
  created     DATETIME     DEFAULT NOW(),
  modified    DATETIME     DEFAULT NOW(),
  active      TINYINT(1)   DEFAULT 1
);

CREATE TABLE mailbox (
  username   VARCHAR(255) NOT NULL PRIMARY KEY,
  password   VARCHAR(255) NOT NULL,
  name       VARCHAR(255),
  maildir    VARCHAR(255),
  quota      BIGINT       DEFAULT 0,
  local_part VARCHAR(255),
  domain     VARCHAR(255),
  created    DATETIME     DEFAULT NOW(),
  modified   DATETIME     DEFAULT NOW(),
  active     TINYINT(1)   DEFAULT 1
);

CREATE TABLE alias (
  address  VARCHAR(255) NOT NULL PRIMARY KEY,
  goto     TEXT         NOT NULL,
  domain   VARCHAR(255),
  created  DATETIME     DEFAULT NOW(),
  modified DATETIME     DEFAULT NOW(),
  active   TINYINT(1)   DEFAULT 1
);
```

---

## 4. Clone & Setup Aplikasi

```bash
cd /www/wwwroot
git clone https://github.com/TheGeneral-Meta/FamilyMail-Self-Hosted-Temporary-Email-System.git mailgen
cd mailgen

# Setup config
cp config.example.php config.php
nano config.php
```

Isi `config.php`:
```php
$db_host = '127.0.0.1';
$db_port = '3306';
$db_user = 'mailgen_user';
$db_pass = 'GANTI_PASSWORD_KUAT';
$db_name = 'mailgen_db';
```

```bash
# Setup .env
cp .env.example .env
nano .env
```

Isi `.env`:
```env
DOMAIN_API_SECRET=ISI_RANDOM_STRING_MINIMAL_32_KARAKTER
```

Generate random string:
```bash
openssl rand -hex 32
```

```bash
# Permission
chown -R www-data:www-data /www/wwwroot/mailgen
chmod -R 755 /www/wwwroot/mailgen
chmod 600 /www/wwwroot/mailgen/.env
chmod 600 /www/wwwroot/mailgen/config.php
```

---

## 5. Setup Maildir & User vmail

```bash
useradd -r -u 996 -g 996 -s /sbin/nologin -d /var/mail/vmail vmail 2>/dev/null || true
groupadd -g 996 vmail 2>/dev/null || true

mkdir -p /var/mail/vmail
chown -R vmail:vmail /var/mail/vmail
chmod 770 /var/mail/vmail

usermod -aG vmail www-data
```

---

## 6. Konfigurasi Postfix

```bash
nano /etc/postfix/main.cf
```

Isi (ganti nilai HURUF BESAR):

```ini
smtpd_banner = $myhostname ESMTP
biff = no
append_dot_mydomain = no
compatibility_level = 2

myhostname = mail.DOMAIN_KAMU
myorigin = DOMAIN_KAMU
mydomain = DOMAIN_KAMU
mydestination = localhost, $myhostname

relayhost =
mynetworks = 127.0.0.0/8

smtpd_tls_cert_file = /etc/ssl/certs/ssl-cert-snakeoil.pem
smtpd_tls_key_file  = /etc/ssl/private/ssl-cert-snakeoil.key
smtpd_tls_security_level = may
smtp_tls_security_level  = may

virtual_mailbox_domains = DOMAIN_KAMU
virtual_mailbox_base    = /var/mail/vmail
virtual_mailbox_maps    = hash:/etc/postfix/vmailbox
virtual_alias_maps      = hash:/etc/postfix/virtual
virtual_minimum_uid     = 100
virtual_uid_maps        = static:996
virtual_gid_maps        = static:996
```

```bash
touch /etc/postfix/vmailbox && postmap /etc/postfix/vmailbox
touch /etc/postfix/virtual  && postmap /etc/postfix/virtual
systemctl restart postfix && systemctl enable postfix
```

---

## 7. Konfigurasi Dovecot

```bash
cat > /etc/dovecot/conf.d/10-mail.conf << 'EOF'
mail_location = maildir:/var/mail/vmail/%d/%n/Maildir
mail_privileged_group = mail
first_valid_uid = 33
EOF

cat > /etc/dovecot/conf.d/10-auth.conf << 'EOF'
disable_plaintext_auth = no
auth_mechanisms = plain login
!include auth-sql.conf.ext
EOF

cat > /etc/dovecot/conf.d/auth-sql.conf.ext << 'EOF'
passdb {
  driver = sql
  args = /etc/dovecot/dovecot-sql.conf.ext
}
userdb {
  driver = static
  args = uid=vmail gid=vmail home=/var/mail/vmail/%d/%n
}
EOF

cat > /etc/dovecot/dovecot-sql.conf.ext << EOF
driver = mysql
connect = host=127.0.0.1 dbname=mailgen_db user=mailgen_user password=GANTI_PASSWORD_KUAT
default_pass_scheme = PLAIN
password_query = SELECT imap_pass AS password FROM temp_emails WHERE email = '%u'
EOF

chmod 600 /etc/dovecot/dovecot-sql.conf.ext
systemctl restart dovecot && systemctl enable dovecot
```

---

## 8. Setup OpenDKIM

```bash
mkdir -p /etc/opendkim/keys
mkdir -p /var/run/opendkim
chown opendkim:opendkim /var/run/opendkim

cat > /etc/opendkim.conf << 'EOF'
Syslog              yes
Canonicalization    relaxed/simple
Mode                sv
SubDomains          no
AutoRestart         yes
Background          yes
SignatureAlgorithm  rsa-sha256
UserID              opendkim:opendkim
Socket              inet:12301@localhost
KeyTable            /etc/opendkim/KeyTable
SigningTable        refile:/etc/opendkim/SigningTable
ExternalIgnoreList  /etc/opendkim/TrustedHosts
InternalHosts       /etc/opendkim/TrustedHosts
EOF

cat > /etc/opendkim/TrustedHosts << EOF
127.0.0.1
localhost
IP_SERVER_KAMU
EOF

touch /etc/opendkim/KeyTable
touch /etc/opendkim/SigningTable

systemctl restart opendkim && systemctl enable opendkim

# Integrasi dengan Postfix
cat >> /etc/postfix/main.cf << 'EOF'

# OpenDKIM Milter
milter_default_action = accept
milter_protocol = 6
smtpd_milters     = inet:localhost:12301
non_smtpd_milters = inet:localhost:12301
EOF

postfix reload
```

---

## 9. Script Helper

```bash
# add_vmailbox.sh
cat > /usr/local/bin/add_vmailbox.sh << 'EOF'
#!/bin/bash
EMAIL=$1
LOCAL=$(echo "$EMAIL" | cut -d@ -f1)
DOMAIN=$(echo "$EMAIL" | cut -d@ -f2)
MAILDIR="/var/mail/vmail/$DOMAIN/$LOCAL/Maildir"
mkdir -p "$MAILDIR/new" "$MAILDIR/cur" "$MAILDIR/tmp"
chown -R vmail:vmail "/var/mail/vmail/$DOMAIN/$LOCAL"
chmod -R 700 "/var/mail/vmail/$DOMAIN/$LOCAL"
VMAILBOX="/etc/postfix/vmailbox"
if ! grep -qF "$EMAIL" "$VMAILBOX" 2>/dev/null; then
  echo "$EMAIL $DOMAIN/$LOCAL/Maildir/" >> "$VMAILBOX"
  postmap "$VMAILBOX"
  postfix reload > /dev/null 2>&1
fi
echo "OK"
EOF

# remove_vmailbox.sh
cat > /usr/local/bin/remove_vmailbox.sh << 'EOF'
#!/bin/bash
EMAIL=$1
LOCAL=$(echo "$EMAIL" | cut -d@ -f1)
DOMAIN=$(echo "$EMAIL" | cut -d@ -f2)
rm -rf "/var/mail/vmail/$DOMAIN/$LOCAL"
VMAILBOX="/etc/postfix/vmailbox"
if [ -f "$VMAILBOX" ]; then
  sed -i "/^$EMAIL /d" "$VMAILBOX"
  postmap "$VMAILBOX"
  postfix reload > /dev/null 2>&1
fi
echo "OK"
EOF

chmod +x /usr/local/bin/add_vmailbox.sh
chmod +x /usr/local/bin/remove_vmailbox.sh
chmod +x /usr/local/bin/domain_setup.sh
```

---

## 10. Konfigurasi Nginx

```bash
cat > /etc/nginx/sites-available/mailgen << 'EOF'
server {
    listen 80;
    server_name DOMAIN_KAMU;
    root /www/wwwroot/mailgen;
    index login.php index.html;

    location ^~ /.well-known/acme-challenge/ {
        root /var/www/html;
    }

    location / {
        try_files $uri $uri/ /login.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/tmp/php-cgi-82.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location ~ /\.(env|git|htaccess) {
        deny all;
    }
}
EOF

ln -s /etc/nginx/sites-available/mailgen /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
```

---

## 11. Izin sudo untuk PHP

```bash
cat > /etc/sudoers.d/mailgen << 'EOF'
www-data ALL=(ALL) NOPASSWD: /usr/local/bin/domain_setup.sh
www-data ALL=(ALL) NOPASSWD: /usr/local/bin/add_vmailbox.sh
www-data ALL=(ALL) NOPASSWD: /usr/local/bin/remove_vmailbox.sh
www-data ALL=(ALL) NOPASSWD: /usr/sbin/postmap
www-data ALL=(ALL) NOPASSWD: /usr/sbin/postfix
EOF

chmod 440 /etc/sudoers.d/mailgen
```

---

## 12. Buat Akun Admin Pertama

Generate hash password:
```bash
php -r "echo password_hash('PASSWORD_ADMIN', PASSWORD_BCRYPT);"
```

Masukkan ke database:
```bash
mysql -u mailgen_user -p mailgen_db
```

```sql
INSERT INTO users (email, password_hash, role, quota, verified, auth_method)
VALUES (
  'admin@DOMAIN_KAMU',
  '$2y$10$HASH_DARI_COMMAND_DIATAS',
  'super_admin',
  999999,
  1,
  'password'
);
```

---

## 13. Setup SSL

```bash
mkdir -p /var/www/html
certbot --nginx -d DOMAIN_KAMU
```

---

## 14. Firewall

```bash
ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw allow 25/tcp
ufw allow 143/tcp
ufw allow 587/tcp
ufw enable
```

---

## 15. Cek Status Semua Service

```bash
systemctl status postfix dovecot nginx mysql opendkim
```

---

## 🌐 DNS Records

| Type | Name | Value | Priority |
|------|------|-------|----------|
| A | mail | IP_SERVER | — |
| MX | @ | mail.DOMAIN | 10 |
| TXT | @ | `v=spf1 mx a ip4:IP_SERVER ~all` | — |
| TXT | _dmarc | `v=DMARC1; p=quarantine; rua=mailto:postmaster@DOMAIN` | — |
| TXT | mail._domainkey | *(generate via admin panel → DNS Setup)* | — |

> PTR/rDNS: Set di panel VPS provider kamu (bukan di DNS biasa)

---

## ❓ Troubleshooting

**Email bounced "unknown user":**
```bash
grep "namauser@domain" /etc/postfix/vmailbox
postmap /etc/postfix/vmailbox && postfix reload
```

**DKIM gagal:**
```bash
systemctl status opendkim
cat /tmp/domain_setup_DOMAIN.log
```

**SSL certbot gagal:**
```bash
curl -I http://mail.DOMAIN/.well-known/acme-challenge/test
dig A mail.DOMAIN
```

**Dovecot tidak bisa auth:**
```bash
systemctl status dovecot
mysql -u mailgen_user -p mailgen_db -e "SELECT email, imap_pass FROM temp_emails LIMIT 5;"
```
