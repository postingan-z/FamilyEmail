<?php
require 'config.php';

$user = get_mailgen_user();
if (!$user) { header('Location: login.php'); exit; }

$email_id = intval($_GET['id'] ?? 0);
if ($email_id <= 0) { header('Location: index.php'); exit; }

$stmt = $pdo->prepare('SELECT email FROM temp_emails WHERE id = ? AND user_id = ?');
$stmt->execute([$email_id, $user['id']]);
$email_row = $stmt->fetch();
if (!$email_row) { header('Location: index.php'); exit; }

$email = $email_row['email'];
list($local, $domain) = explode('@', $email);
$maildir = "/var/mail/vmail/$domain/$local/Maildir";

function get_header($raw, $name) {
    // Normalize CRLF
    $raw = str_replace("\r\n", "\n", $raw);
    $raw = str_replace("\r", "\n", $raw);
    // Unfold multi-line headers manually (no regex)
    $lines = explode("\n", $raw);
    $unfolded = [];
    foreach ($lines as $line) {
        if (strlen($line) > 0 && ($line[0] === " " || $line[0] === "\t")) {
            if (!empty($unfolded)) {
                $unfolded[count($unfolded)-1] .= " " . ltrim($line);
            }
        } else {
            $unfolded[] = $line;
        }
    }
    // Get headers only (before blank line)
    $headers = [];
    foreach ($unfolded as $line) {
        if (trim($line) === "") break;
        $headers[] = $line;
    }
    // Find header
    foreach ($headers as $line) {
        if (stripos($line, $name . ":") === 0) {
            $val = trim(substr($line, strlen($name) + 1));
            // Decode MIME encoded-words
            if (strpos($val, "=?") !== false) {
                $val = preg_replace_callback(
                    '/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/',
                    function($m) {
                        $charset = $m[1];
                        $enc = strtoupper($m[2]);
                        $text = $m[3];
                        if ($enc === "B") {
                            $text = base64_decode($text);
                        } else {
                            $text = quoted_printable_decode(str_replace("_", " ", $text));
                        }
                        return @mb_convert_encoding($text, "UTF-8", $charset) ?: $text;
                    },
                    $val
                );
                $val = trim($val);
            }
            return $val;
        }
    }
    return "";
}

function decode_part_body($body, $encoding) {
    if (stripos($encoding, 'quoted-printable') !== false) {
        return quoted_printable_decode($body);
    } elseif (stripos($encoding, 'base64') !== false) {
        return base64_decode($body);
    }
    return $body;
}

// Cari content-type & charset dari sebuah blok header (header satu part atau header utama)
function get_content_type($headers_raw) {
    $ct = get_header($headers_raw . "\n\n", 'Content-Type');
    return $ct;
}

// Ambil charset dari string Content-Type (misal "text/html; charset=ISO-8859-1")
function get_charset_from_ct($ct) {
    if (preg_match('/charset\s*=\s*"?([A-Za-z0-9_-]+)"?/i', $ct, $m)) {
        return trim($m[1]);
    }
    return 'UTF-8';
}

// Parse satu part MIME (header + body), return [type, encoding, body_decoded]
function parse_mime_part($part_raw) {
    $part_raw = ltrim($part_raw, "\n");
    $pos = strpos($part_raw, "\n\n");
    if ($pos === false) {
        return ['text/plain', '', $part_raw];
    }
    $headers_raw = substr($part_raw, 0, $pos);
    $body = substr($part_raw, $pos + 2);
    $ct = get_content_type($headers_raw);
    $encoding = get_header($headers_raw . "\n\n", 'Content-Transfer-Encoding');
    $type = 'text/plain';
    if (stripos($ct, 'text/html') !== false) $type = 'text/html';
    elseif (stripos($ct, 'text/plain') !== false) $type = 'text/plain';
    elseif ($ct) $type = trim(explode(';', $ct)[0]);
    $decoded = decode_part_body($body, $encoding);

    // Konversi ke UTF-8 kalau charset bukan UTF-8 (supaya tidak muncul karakter aneh)
    $charset = get_charset_from_ct($ct);
    if ($charset && strtoupper($charset) !== 'UTF-8' && strtoupper($charset) !== 'US-ASCII') {
        $converted = @mb_convert_encoding($decoded, 'UTF-8', $charset);
        if ($converted !== false && $converted !== '') {
            $decoded = $converted;
        }
    }

    return [$type, $encoding, $decoded];
}

// Ambil semua bagian text/html dan text/plain dari multipart (rekursif utk nested multipart)
function collect_text_parts($raw, $boundary, &$html_parts, &$plain_parts) {
    $marker = '--' . $boundary;
    $sections = explode($marker, $raw);
    foreach ($sections as $section) {
        $section = trim($section);
        if ($section === '' || $section === '--') continue;
        // Cek apakah section ini sendiri multipart (nested)
        $pos = strpos($section, "\n\n");
        $headers_raw = $pos !== false ? substr($section, 0, $pos) : $section;
        $ct = get_content_type($headers_raw);
        if (stripos($ct, 'multipart/') !== false && preg_match('/boundary="?([^";\n]+)"?/i', $ct, $bm)) {
            collect_text_parts($section, trim($bm[1]), $html_parts, $plain_parts);
            continue;
        }
        list($type, $encoding, $decoded) = parse_mime_part($section);
        if ($type === 'text/html') {
            $html_parts[] = $decoded;
        } elseif ($type === 'text/plain') {
            $plain_parts[] = $decoded;
        }
    }
}

function extract_body($raw) {
    $raw = str_replace("\r\n", "\n", $raw);
    $pos = strpos($raw, "\n\n");
    if ($pos === false) return $raw;
    $headers_raw = substr($raw, 0, $pos);
    $body = substr($raw, $pos + 2);
    $ct = get_content_type($headers_raw);

    // Multipart: cari boundary, pisahkan bagian-bagian, prioritaskan HTML
    if (stripos($ct, 'multipart/') !== false && preg_match('/boundary="?([^";\n]+)"?/i', $ct, $bm)) {
        $boundary = trim($bm[1]);
        $html_parts = [];
        $plain_parts = [];
        collect_text_parts($body, $boundary, $html_parts, $plain_parts);
        if (!empty($html_parts)) return implode("\n", $html_parts);
        if (!empty($plain_parts)) return implode("\n", $plain_parts);
        return ''; // multipart tapi tidak ada text/html atau text/plain (misal cuma attachment)
    }

    // Bukan multipart - email simpel, decode langsung dari header utama
    $encoding = get_header($headers_raw . "\n\n", 'Content-Transfer-Encoding');
    return decode_part_body($body, $encoding);
}

function sanitize_email_html($html) {
    $html = preg_replace('/<script[^>]*>.*?<\/script>/ims', '', $html);
    $html = preg_replace('/<iframe[^>]*>.*?<\/iframe>/ims', '', $html);
    $html = preg_replace('/\son\w+\s*=\s*(["\'])[^"\']*\1/i', '', $html);
    $html = preg_replace('/javascript:/i', '', $html);
    return $html;
}

function is_html($body) {
    return stripos($body, '<html') !== false
        || stripos($body, '<!doctype') !== false
        || stripos($body, '<div') !== false
        || stripos($body, '<table') !== false;
}

$files = [];
foreach (['new', 'cur'] as $folder) {
    $path = "$maildir/$folder";
    if (is_dir($path)) {
        $list = array_diff(scandir($path), ['.', '..']);
        foreach ($list as $f) {
            $fp = "$path/$f";
            $fsize = filesize($fp);
            $mtime = filemtime($fp);
            if ($fsize < 100) continue;
            // Tunggu 3 detik agar Postfix selesai tulis file
            if (time() - $mtime < 3) continue;
            $files[$mtime.'_'.$f] = $fp;
        }
    }
}
krsort($files);

$emails_list = [];
foreach ($files as $filename => $filepath) {
    $raw = file_get_contents($filepath);
    $from    = get_header($raw, 'From');
    $subject = get_header($raw, 'Subject');
    $date_str= get_header($raw, 'Date');

    // Skip email tanpa From - jangan tampilkan Unknown
    if (empty($from))    continue;
    if (empty($subject)) $subject = '(No Subject)';

    $date_fmt = '';
    if ($date_str) {
        $ts = strtotime($date_str);
        $date_fmt = $ts ? date('d M Y H:i', $ts) : $date_str;
    }
    if (!$date_fmt) $date_fmt = date('d M Y H:i', filemtime($filepath));

    $emails_list[] = [
        'id'      => $filename,
        'from'    => htmlspecialchars($from),
        'subject' => htmlspecialchars($subject),
        'date'    => $date_fmt,
        'path'    => $filepath,
    ];
}

$message_id  = $_GET['msg'] ?? null;
$current_msg = null;
$msg_body    = '';
$msg_is_html = false;

if ($message_id) {
    foreach ($emails_list as $m) {
        if ($m['id'] === $message_id) {
            $current_msg = $m;
            $raw_full    = file_get_contents($m['path']);
            $msg_body    = extract_body($raw_full);
            $msg_is_html = is_html($msg_body);
            break;
        }
    }
}
?>
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($email) ?> — Inbox</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%;overflow:hidden}
body{
  font-family:-apple-system,BlinkMacSystemFont,'Inter','Segoe UI',sans-serif;
  background:#0d0d1a;color:#e2e8f0;
  display:flex;flex-direction:column;
}

/* TOPBAR */
.topbar{
  height:48px;flex-shrink:0;
  background:rgba(13,13,26,0.97);
  border-bottom:1px solid rgba(255,255,255,0.06);
  display:flex;align-items:center;padding:0 14px;gap:10px;
  backdrop-filter:blur(20px);z-index:30;
}
.btn-back{
  display:inline-flex;align-items:center;gap:5px;
  color:#a78bfa;text-decoration:none;font-size:12px;font-weight:600;
  padding:5px 11px;border-radius:7px;
  border:1px solid rgba(124,58,237,0.3);
  background:rgba(124,58,237,0.1);
  transition:all .15s;white-space:nowrap;flex-shrink:0;
}
.btn-back:hover{background:rgba(124,58,237,0.22);color:#c4b5fd}
.top-addr{
  flex:1;font-size:11px;color:#475569;
  font-family:monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
  padding:0 10px;
}
.top-right{display:flex;align-items:center;gap:7px;flex-shrink:0}
.top-count{
  font-size:11px;font-weight:600;color:#a78bfa;
  background:rgba(124,58,237,0.12);border:1px solid rgba(124,58,237,0.2);
  border-radius:20px;padding:3px 10px;white-space:nowrap;
}
.top-new{
  font-size:10px;font-weight:700;letter-spacing:.4px;
  color:#fff;background:linear-gradient(135deg,#10b981,#059669);
  border-radius:20px;padding:3px 9px;display:none;
  animation:popin .2s ease;
}
@keyframes popin{from{transform:scale(.5);opacity:0}to{transform:scale(1);opacity:1}}
.btn-rf{
  width:30px;height:30px;border-radius:8px;flex-shrink:0;
  border:1px solid rgba(124,58,237,0.25);
  background:rgba(124,58,237,0.08);
  color:#a78bfa;cursor:pointer;
  display:flex;align-items:center;justify-content:center;
  transition:all .15s;position:relative;
}
.btn-rf:hover{background:rgba(124,58,237,0.2);color:#c4b5fd;border-color:#a78bfa}
.btn-rf svg{transition:transform .3s}
.btn-rf:hover svg{transform:rotate(180deg)}
.btn-rf.spinning svg{animation:spin .5s linear}
@keyframes spin{to{transform:rotate(360deg)}}
.rf-arc{
  position:absolute;inset:-3px;border-radius:11px;
  background:conic-gradient(rgba(124,58,237,0.5) var(--arc,100%),transparent 0);
  pointer-events:none;z-index:-1;border-radius:11px;
}

/* LAYOUT */
.layout{display:flex;flex:1;overflow:hidden;height:calc(100vh - 48px)}

/* ── SIDEBAR ── */
.sidebar{
  width:340px;flex-shrink:0;
  border-right:1px solid rgba(255,255,255,0.06);
  display:flex;flex-direction:column;
  background:#0a0a17;
}
.sb-head{
  padding:10px 14px;
  border-bottom:1px solid rgba(255,255,255,0.05);
  display:flex;align-items:center;justify-content:space-between;
  flex-shrink:0;
}
.sb-title{font-size:10px;font-weight:700;letter-spacing:.8px;color:#475569;text-transform:uppercase}
.sb-count{font-size:11px;font-weight:700;color:#7c3aed;
  background:rgba(124,58,237,0.12);border-radius:6px;padding:2px 7px}
.sb-body{flex:1;overflow-y:auto}
.sb-body::-webkit-scrollbar{width:3px}
.sb-body::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.07);border-radius:3px}

/* Email item */
.eitem{
  padding:12px 14px;
  border-bottom:1px solid rgba(255,255,255,0.04);
  cursor:pointer;transition:background .1s;
  display:flex;gap:11px;align-items:flex-start;
  position:relative;
}
.eitem:hover{background:rgba(255,255,255,0.03)}
.eitem.active{background:rgba(109,40,217,0.13)}
.eitem.active::before{
  content:'';position:absolute;left:0;top:8px;bottom:8px;
  width:2.5px;background:linear-gradient(180deg,#a78bfa,#06b6d4);
  border-radius:0 2px 2px 0;
}
.av{
  width:36px;height:36px;border-radius:9px;flex-shrink:0;
  background:linear-gradient(135deg,#7c3aed,#06b6d4);
  display:flex;align-items:center;justify-content:center;
  font-size:14px;font-weight:800;color:#fff;letter-spacing:0;
}
.eitem.active .av{box-shadow:0 0 0 2px rgba(167,139,250,0.35)}
.emeta{flex:1;min-width:0}
.efrom{
  font-size:12px;font-weight:600;color:#a78bfa;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
  margin-bottom:2px;line-height:1.4;
}
.eitem.active .efrom{color:#c4b5fd}
.esubj{
  font-size:11.5px;color:#94a3b8;line-height:1.45;
  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;
  overflow:hidden;margin-bottom:5px;
}
.edate{font-size:10px;color:#334155;display:flex;align-items:center;gap:3px}

/* empty sidebar */
.sb-empty{
  flex:1;display:flex;flex-direction:column;align-items:center;
  justify-content:center;gap:8px;padding:40px 20px;
  color:#334155;text-align:center;
}
.sb-empty .ico{font-size:36px;opacity:.25}
.sb-empty .lbl{font-size:12px;font-weight:600}
.sb-empty .sub{font-size:11px;opacity:.6;margin-top:2px}

/* ── READING PANE ── */
.pane{
  flex:1;overflow-y:auto;
  background:#0d0d1a;
}
.pane::-webkit-scrollbar{width:4px}
.pane::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.07);border-radius:4px}

/* pane empty */
.pane-empty{
  height:100%;min-height:calc(100vh - 48px);
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  gap:10px;color:#334155;
}
.pane-empty .ico{font-size:48px;opacity:.15}
.pane-empty .lbl{font-size:13px;font-weight:600;color:#475569}
.pane-empty .sub{font-size:11px;color:#334155}

/* message */
.msgwrap{padding:24px 28px;max-width:780px;margin:0 auto}

.msg-head{
  background:rgba(255,255,255,0.02);
  border:1px solid rgba(255,255,255,0.07);
  border-radius:14px;padding:20px 22px;margin-bottom:14px;
}
.msg-subj{
  font-size:19px;font-weight:700;color:#f1f5f9;
  line-height:1.35;margin-bottom:14px;
}
.msg-from{display:flex;align-items:center;gap:11px;margin-bottom:14px}
.from-av{
  width:38px;height:38px;border-radius:10px;flex-shrink:0;
  background:linear-gradient(135deg,#7c3aed,#06b6d4);
  display:flex;align-items:center;justify-content:center;
  font-size:15px;font-weight:800;color:#fff;
}
.from-info{flex:1;min-width:0}
.from-name{font-size:13px;font-weight:600;color:#e2e8f0;margin-bottom:1px}
.from-addr{font-size:11px;color:#475569;font-family:monospace;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.msg-meta-row{
  display:flex;align-items:center;gap:14px;flex-wrap:wrap;
  padding-top:12px;border-top:1px solid rgba(255,255,255,0.05);
  font-size:10.5px;color:#475569;
}
.meta-chip{
  display:flex;align-items:center;gap:4px;
  background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06);
  border-radius:6px;padding:3px 8px;
}

.msg-body-wrap{
  background:rgba(255,255,255,0.02);
  border:1px solid rgba(255,255,255,0.07);
  border-radius:14px;overflow:hidden;
}
.msg-body-inner{padding:0}
.msg-body-inner iframe{
  width:100%;border:none;
  background:#fff;min-height:420px;display:block;
}
.msg-body-inner pre{
  padding:22px;
  white-space:pre-wrap;
  font-family:'SF Mono',Monaco,Consolas,monospace;
  font-size:12.5px;line-height:1.8;color:#94a3b8;
}

/* TOAST */
.toast{
  position:fixed;bottom:18px;right:18px;z-index:999;
  background:rgba(13,13,26,0.95);
  border:1px solid rgba(16,185,129,0.3);
  color:#10b981;padding:9px 15px;border-radius:9px;
  font-size:12px;font-weight:500;
  display:flex;align-items:center;gap:7px;
  animation:fup .2s ease;pointer-events:none;
  box-shadow:0 4px 20px rgba(0,0,0,0.4);
}
@keyframes fup{from{opacity:0;transform:translateY(6px)}}

/* MOBILE */
@media(max-width:680px){
  .sidebar{
    width:100%;
    display:<?php echo $message_id ? 'none' : 'flex' ?>;
  }
  .pane{
    display:<?php echo $message_id ? 'flex' : 'none' ?>;
    flex-direction:column;
  }
  .top-addr{display:none}
  .msgwrap{padding:14px}
  .msg-subj{font-size:16px}
}
</style>
</head>
<body>

<div class="topbar">
  <a class="btn-back" href="index.php">
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/></svg>
    Kembali
  </a>
  <div class="top-addr"><?= htmlspecialchars($email) ?></div>
  <div class="top-right">
    <span class="top-new" id="topNew">+0 baru</span>
    <span class="top-count" id="topCount"><?= count($emails_list) ?> email</span>
    <button class="btn-rf" id="btnRf" onclick="doRefresh()" title="Refresh [R]">
      <div class="rf-arc" id="rfArc"></div>
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/>
        <path d="M21 3v5h-5"/>
        <path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/>
        <path d="M8 16H3v5"/>
      </svg>
    </button>
  </div>
</div>

<div class="layout">

  <div class="sidebar">
    <div class="sb-head">
      <span class="sb-title">Inbox</span>
      <span class="sb-count"><?= count($emails_list) ?></span>
    </div>
    <div class="sb-body">
      <?php if(empty($emails_list)): ?>
        <div class="sb-empty">
          <div class="ico">📭</div>
          <div class="lbl">Inbox kosong</div>
          <div class="sub">Menunggu email masuk...</div>
        </div>
      <?php else: foreach($emails_list as $e):
        $init = strtoupper(substr(strip_tags($e['from']),0,1))?:'?';
        $active = $message_id===$e['id'];
      ?>
        <div class="eitem <?= $active?'active':'' ?>"
             onclick="location.href='?id=<?= $email_id ?>&msg=<?= urlencode($e['id']) ?>'">
          <div class="av"><?= htmlspecialchars($init) ?></div>
          <div class="emeta">
            <div class="efrom"><?= $e['from'] ?></div>
            <div class="esubj"><?= mb_strimwidth($e['subject'],0,80,'...') ?></div>
            <div class="edate">
              <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
              <?= $e['date'] ?>
            </div>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <div class="pane">
    <?php if($current_msg): ?>
      <div class="msgwrap">
        <div class="msg-head">
          <div class="msg-subj"><?= $current_msg['subject'] ?></div>
          <div class="msg-from">
            <?php $si=strtoupper(substr(strip_tags($current_msg['from']),0,1))?:'?'; ?>
            <div class="from-av"><?= htmlspecialchars($si) ?></div>
            <div class="from-info">
              <div class="from-name"><?= $current_msg['from'] ?></div>
              <div class="from-addr">kepada: <?= htmlspecialchars($email) ?></div>
            </div>
          </div>
          <div class="msg-meta-row">
            <div class="meta-chip">
              <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
              <?= $current_msg['date'] ?>
            </div>
            <div class="meta-chip">
              <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg>
              <?= $msg_is_html?'HTML Email':'Plain Text' ?>
            </div>
          </div>
        </div>
        <div class="msg-body-wrap">
          <div class="msg-body-inner">
            <?php if($msg_is_html): ?>
              <iframe srcdoc="<?= htmlspecialchars(sanitize_email_html($msg_body)) ?>"
                      onload="this.style.height=this.contentDocument.body.scrollHeight+40+'px'">
              </iframe>
            <?php else: ?>
              <pre><?= htmlspecialchars($msg_body) ?></pre>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php else: ?>
      <div class="pane-empty">
        <div class="ico">✉️</div>
        <div class="lbl">Pilih email untuk membaca</div>
        <div class="sub"><?= count($emails_list) ?> email di inbox</div>
      </div>
    <?php endif; ?>
  </div>

</div>

<script>
(function(){
  var TOTAL = 15, t = TOTAL;
  var eid = <?= $email_id ?>;
  var cnt = <?= count($emails_list) ?>;
  var chk = false;
  var arc = document.getElementById('rfArc');
  var pill = document.getElementById('topNew');
  var badge = document.getElementById('topCount');
  var btn = document.getElementById('btnRf');

  function setArc(pct){
    arc.style.background = 'conic-gradient(rgba(124,58,237,0.45) '+pct+'%,transparent 0)';
  }
  setArc(100);

  setInterval(function(){
    t--;
    setArc(Math.max(0,t/TOTAL*100));
    if(t<=0){location.reload();}
  },1000);

  setInterval(function(){
    if(chk)return;
    chk=true;
    fetch('check_mail.php?id='+eid+'&_='+Date.now())
      .then(function(r){return r.json();})
      .then(function(d){
        chk=false;
        if(d.count>cnt){
          var diff=d.count-cnt;
          cnt=d.count;
          badge.textContent=cnt+' email';
          pill.textContent='+'+diff+' baru';
          pill.style.display='block';
          t=TOTAL;
          toast('📬 '+diff+' email baru!');
        }
      })
      .catch(function(){chk=false;});
  },2000);

  window.doRefresh=function(){
    btn.classList.add('spinning');
    toast('🔄 Refresh...');
    setTimeout(function(){location.reload();},450);
  };

  document.addEventListener('keydown',function(e){
    if((e.key==='r'||e.key==='R')&&document.activeElement.tagName!=='INPUT'){
      doRefresh();
    }
  });

  function toast(msg){
    var el=document.createElement('div');
    el.className='toast';
    el.textContent=msg;
    document.body.appendChild(el);
    setTimeout(function(){el.remove();},2600);
  }
})();
</script>
</body>
</html>
