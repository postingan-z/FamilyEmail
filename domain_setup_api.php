<?php
chdir(__DIR__);
require __DIR__.'/config.php';

header('Content-Type: application/json');

$user = get_mailgen_user();

if (!$user) {
    $env = parse_ini_file(__DIR__.'/.env');
    $token = $_POST['_token'] ?? $_SERVER['HTTP_X_API_TOKEN'] ?? '';
    if (!empty($env['DOMAIN_API_SECRET']) && hash_equals($env['DOMAIN_API_SECRET'], $token)) {
        $user = ['email' => 'api_token', 'role' => 'super_admin'];
    }
}

if (!$user || strtoupper($user['role'] ?? '') !== 'SUPER_ADMIN') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$action = $_POST['action'] ?? '';
$domain = preg_replace('/[^a-z0-9.-]/', '', strtolower($_POST['domain'] ?? ''));

if (!$domain || !$action) {
    http_response_code(400);
    echo json_encode(['error' => 'Domain and action required']);
    exit;
}

$allowed = ['check','setup_dkim','setup_ssl','setup_dovecot','setup_postfix'];
if (!in_array($action, $allowed)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action']);
    exit;
}

$script = '/usr/local/bin/domain_setup.sh';
if (!file_exists($script)) {
    echo json_encode(['ok' => false, 'error' => 'Setup script not found', 'output' => '']);
    exit;
}

$cmd = "sudo $script " . escapeshellarg($domain) . " " . escapeshellarg($action) . " 2>&1";
$out = shell_exec($cmd);

if ($out === null) {
    echo json_encode(['ok' => false, 'error' => 'shell_exec failed or script not executable', 'output' => '']);
    exit;
}

log_activity($user['email'], "domain_$action", "Domain: $domain", $_SERVER['REMOTE_ADDR'] ?? 'cli');
echo json_encode(['ok' => strpos($out, 'OK') !== false, 'output' => $out]);
