<?php
/**
 * Self-hosted backend for the VANTHOR 8 Ball Pool Android source.
 * Requires PHP 8.1+ and HTTPS. Configure APP_ADMIN_PASSWORD before enabling /admin.
 * Recommended: set APP_DATA_DIR to a writable directory outside the public web root.
 */
declare(strict_types=1);

const STORE_GUARD = "<?php http_response_code(404); exit; ?>\n";

function env_value(string $name, string $default = ''): string {
    $value = getenv($name);
    return ($value === false || $value === '') ? $default : (string)$value;
}

$APP_NAME = env_value('APP_NAME', '8 Ball Pool');
$APP_ID = env_value('APP_ID', 'watan_main');
// Match this to DEFAULT_GAME in the Android source. The supplied source currently sends "pubg".
$APP_GAME_ID = env_value('APP_GAME_ID', 'pubg');
$ADMIN_PASSWORD = env_value('APP_ADMIN_PASSWORD', '');
$dataDir = env_value('APP_DATA_DIR', __DIR__);
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0700, true);
}
$STORE_FILE = rtrim($dataDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.watan_backend_store.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function reply(int $status, array $data): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function utc_now(): DateTimeImmutable {
    return new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

function default_store(): array {
    global $APP_NAME;
    return [
        'settings' => [
            'app_enabled' => true,
            'auto_generate' => false,
            'auto_max_per_device' => 1,
            'auto_duration_hours' => 24,
            'current_version' => '1.0',
            'required_version' => '',
            'force_update' => false,
            'update_url' => '',
            'update_message' => '',
            'update_size' => '',
            'update_changelog' => '',
            'update_sha256' => '',
            'notice_title' => '',
            'notice' => '',
            'maintenance' => false,
            'maintenance_scheduled' => false,
            'maintenance_title' => '',
            'maintenance_message' => '',
            'maintenance_progress' => 0,
            'maintenance_eta_minutes' => -1,
            'maintenance_starts_at' => '',
            'maintenance_ends_at' => '',
            'telegram_channel' => 'https://t.me/WATANEEngine',
            'app_name' => $APP_NAME,
        ],
        'accounts' => [],
        'notifications' => [],
        'rate_limits' => [],
    ];
}

function normalize_store(array $data): array {
    $defaults = default_store();
    if (!isset($data['settings']) || !is_array($data['settings'])) $data['settings'] = [];
    $data['settings'] = array_merge($defaults['settings'], $data['settings']);
    foreach (['accounts', 'notifications', 'rate_limits'] as $key) {
        if (!isset($data[$key]) || !is_array($data[$key])) $data[$key] = [];
    }
    return $data;
}

function with_store(bool $write, callable $callback): mixed {
    global $STORE_FILE;
    $fh = @fopen($STORE_FILE, 'c+');
    if (!$fh) reply(500, ['ok' => false, 'error' => 'Cannot open data storage. Check APP_DATA_DIR permissions.']);
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        reply(500, ['ok' => false, 'error' => 'Could not lock data storage.']);
    }
    rewind($fh);
    $contents = stream_get_contents($fh) ?: '';
    $json = str_starts_with($contents, STORE_GUARD) ? substr($contents, strlen(STORE_GUARD)) : '';
    $data = $json !== '' ? json_decode($json, true) : [];
    if (!is_array($data)) $data = [];
    $data = normalize_store($data);
    $result = $callback($data);
    if ($write) {
        rewind($fh);
        ftruncate($fh, 0);
        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($encoded === false || fwrite($fh, STORE_GUARD . $encoded) === false) {
            flock($fh, LOCK_UN);
            fclose($fh);
            reply(500, ['ok' => false, 'error' => 'Could not save data.']);
        }
        fflush($fh);
        @chmod($STORE_FILE, 0600);
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $result;
}

function request_fields(): array {
    if (isset($_POST) && is_array($_POST) && $_POST !== []) return $_POST;
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') return [];
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) reply(400, ['status' => false, 'ok' => false, 'error' => 'Send form data or a JSON object.']);
    return $decoded;
}

function h(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (!function_exists('mb_substr')) {
    function mb_substr(string $string, int $start, ?int $length = null, ?string $encoding = null): string {
        return $length === null ? substr($string, $start) : substr($string, $start, $length);
    }
}

function make_username(array $store): string {
    $known = [];
    foreach ($store['accounts'] as $account) $known[(string)($account['username'] ?? '')] = true;
    for ($i = 0; $i < 1000; $i++) {
        $candidate = 'VANTHOR' . (string)random_int(1000000, 9999999);
        if (!isset($known[$candidate])) return $candidate;
    }
    throw new RuntimeException('Could not allocate a unique username.');
}

function make_password(): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < 20; $i++) $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return $out;
}

function create_account(array &$store, int $durationHours, string $label, ?string $serialHash, bool $autoCreated): array {
    $username = make_username($store);
    $password = make_password();
    $id = bin2hex(random_bytes(8));
    $store['accounts'][] = [
        'id' => $id,
        'username' => $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'label' => mb_substr($label, 0, 100),
        'active' => true,
        'duration_hours' => max(1, min(8760, $durationHours)),
        'expires_at' => null,
        'serial_hash' => $serialHash,
        'auto_created' => $autoCreated,
        'created_at' => utc_now()->format(DATE_ATOM),
        'last_seen' => null,
    ];
    return ['id' => $id, 'username' => $username, 'password' => $password];
}

function allow_rate(array &$store, string $bucket, string $identity, int $limit, int $windowSeconds): bool {
    $key = hash('sha256', $bucket . '|' . $identity);
    $now = time();
    $recent = array_values(array_filter(
        $store['rate_limits'][$key] ?? [],
        static fn($stamp) => is_int($stamp) && $stamp > $now - $windowSeconds
    ));
    if (count($recent) >= $limit) {
        $store['rate_limits'][$key] = $recent;
        return false;
    }
    $recent[] = $now;
    $store['rate_limits'][$key] = $recent;
    if (count($store['rate_limits']) > 2000) {
        foreach ($store['rate_limits'] as $oldKey => $stamps) {
            if (!is_array($stamps) || $stamps === [] || max($stamps) < $now - 86400) unset($store['rate_limits'][$oldKey]);
        }
    }
    return true;
}

function start_admin_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['csrf'];
}

function redirect_admin(): never {
    header('Location: /admin', true, 303);
    exit;
}

function https_or_empty(string $value): string {
    $value = trim($value);
    if ($value === '') return '';
    if (strlen($value) > 2048 || !filter_var($value, FILTER_VALIDATE_URL) || !str_starts_with($value, 'https://')) {
        throw new InvalidArgumentException('URLs must be valid HTTPS links.');
    }
    return $value;
}

function admin_page(): never {
    global $ADMIN_PASSWORD, $APP_NAME;
    start_admin_session();
    header('Cache-Control: no-store, max-age=0');
    header('Content-Type: text/html; charset=utf-8');
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'login') {
            if ($ADMIN_PASSWORD === '') {
                http_response_code(503);
                echo '<h2>Admin disabled</h2><p>Set APP_ADMIN_PASSWORD in the hosting environment.</p>';
                exit;
            }
            if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
                $error = 'Session expired. Refresh and try again.';
            } elseif (hash_equals($ADMIN_PASSWORD, (string)($_POST['password'] ?? ''))) {
                session_regenerate_id(true);
                $_SESSION['admin'] = true;
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                redirect_admin();
            } else {
                $error = 'Incorrect admin password.';
            }
        } elseif ($action === 'logout') {
            $_SESSION = [];
            session_destroy();
            redirect_admin();
        } else {
            if (empty($_SESSION['admin'])) {
                http_response_code(401);
                echo '<h2>Sign in required</h2><p><a href="/admin">Open admin login</a></p>';
                exit;
            }
            if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
                http_response_code(403);
                echo '<h2>Invalid request token</h2><p>Refresh the admin page and retry.</p>';
                exit;
            }
            try {
                $flash = with_store(true, function (array &$store) use ($action): array {
                    if ($action === 'save_settings') {
                        $s =& $store['settings'];
                        $s['app_enabled'] = isset($_POST['app_enabled']);
                        $s['auto_generate'] = isset($_POST['auto_generate']);
                        $s['auto_max_per_device'] = max(1, min(20, (int)($_POST['auto_max_per_device'] ?? 1)));
                        $s['auto_duration_hours'] = max(1, min(8760, (int)($_POST['auto_duration_hours'] ?? 24)));
                        $s['current_version'] = mb_substr(trim((string)($_POST['current_version'] ?? '1.0')), 0, 32);
                        $s['required_version'] = mb_substr(trim((string)($_POST['required_version'] ?? '')), 0, 32);
                        $s['force_update'] = isset($_POST['force_update']);
                        $s['update_url'] = https_or_empty((string)($_POST['update_url'] ?? ''));
                        $s['update_message'] = mb_substr(trim((string)($_POST['update_message'] ?? '')), 0, 1000);
                        $s['update_size'] = mb_substr(trim((string)($_POST['update_size'] ?? '')), 0, 100);
                        $s['update_changelog'] = mb_substr(trim((string)($_POST['update_changelog'] ?? '')), 0, 3000);
                        $s['update_sha256'] = preg_match('/^[a-fA-F0-9]{0,64}$/', trim((string)($_POST['update_sha256'] ?? ''))) ? trim((string)($_POST['update_sha256'] ?? '')) : '';
                        $s['notice_title'] = mb_substr(trim((string)($_POST['notice_title'] ?? '')), 0, 160);
                        $s['notice'] = mb_substr(trim((string)($_POST['notice'] ?? '')), 0, 3000);
                        $s['maintenance'] = isset($_POST['maintenance']);
                        $s['maintenance_scheduled'] = isset($_POST['maintenance_scheduled']);
                        $s['maintenance_title'] = mb_substr(trim((string)($_POST['maintenance_title'] ?? '')), 0, 160);
                        $s['maintenance_message'] = mb_substr(trim((string)($_POST['maintenance_message'] ?? '')), 0, 2000);
                        $s['maintenance_progress'] = max(0, min(100, (int)($_POST['maintenance_progress'] ?? 0)));
                        $s['maintenance_eta_minutes'] = max(-1, min(10080, (int)($_POST['maintenance_eta_minutes'] ?? -1)));
                        $s['maintenance_starts_at'] = mb_substr(trim((string)($_POST['maintenance_starts_at'] ?? '')), 0, 40);
                        $s['maintenance_ends_at'] = mb_substr(trim((string)($_POST['maintenance_ends_at'] ?? '')), 0, 40);
                        $s['telegram_channel'] = https_or_empty((string)($_POST['telegram_channel'] ?? ''));
                        return ['message' => 'Settings saved.'];
                    }
                    if ($action === 'create_account') {
                        $duration = max(1, min(8760, (int)($_POST['duration_hours'] ?? 24)));
                        $label = trim((string)($_POST['label'] ?? ''));
                        $created = create_account($store, $duration, $label, null, false);
                        return ['message' => 'Account created. Copy these credentials now; the password is not shown again.', 'credentials' => $created];
                    }
                    $id = (string)($_POST['id'] ?? '');
                    foreach ($store['accounts'] as &$account) {
                        if (!hash_equals((string)($account['id'] ?? ''), $id)) continue;
                        if ($action === 'toggle_account') $account['active'] = empty($account['revoked']) && !($account['active'] ?? false);
                        elseif ($action === 'reset_device') { $account['serial_hash'] = null; $account['last_seen'] = null; }
                        elseif ($action === 'delete_account') { $account['active'] = false; $account['revoked'] = true; $account['revoked_at'] = utc_now()->format(DATE_ATOM); }
                        else continue;
                        unset($account);
                        return ['message' => 'Account updated.'];
                    }
                    unset($account);
                    if ($action === 'add_notification') {
                        $title = mb_substr(trim((string)($_POST['notification_title'] ?? '')), 0, 160);
                        $body = mb_substr(trim((string)($_POST['notification_body'] ?? '')), 0, 2000);
                        if ($title === '' && $body === '') throw new InvalidArgumentException('Enter a notification title or message.');
                        $store['notifications'][] = ['id' => random_int(100000, 999999999), 'title' => $title, 'body' => $body, 'type' => 'info'];
                        $store['notifications'] = array_slice($store['notifications'], -10);
                        return ['message' => 'Notification added.'];
                    }
                    if ($action === 'delete_notification') {
                        $store['notifications'] = array_values(array_filter($store['notifications'], static fn($n) => (string)($n['id'] ?? '') !== $id));
                        return ['message' => 'Notification removed.'];
                    }
                    return ['message' => 'No changes made.'];
                });
                $_SESSION['flash'] = $flash;
            } catch (Throwable $e) {
                $_SESSION['flash'] = ['error' => $e->getMessage()];
            }
            redirect_admin();
        }
    }

    if (empty($_SESSION['admin'])) {
        $csrf = h(csrf_token());
        echo '<!doctype html><html><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin login</title><style>body{font:16px system-ui;max-width:420px;margin:12vh auto;padding:20px;background:#101116;color:#eee}input,button{box-sizing:border-box;width:100%;padding:13px;margin:8px 0;border-radius:9px;border:1px solid #454752;background:#202127;color:#fff}button{background:#e9eaed;color:#17181c;font-weight:700}.err{color:#ff9a9a}</style><h2>'.h($APP_NAME).' · Admin</h2>';
        if ($ADMIN_PASSWORD === '') echo '<p class="err">Admin is disabled. Configure APP_ADMIN_PASSWORD on the host first.</p>';
        if ($error !== '') echo '<p class="err">'.h($error).'</p>';
        echo '<form method="post"><input type="hidden" name="action" value="login"><input type="hidden" name="csrf" value="'.$csrf.'"><label>Admin password</label><input type="password" name="password" autocomplete="current-password" required><button>Sign in</button></form></html>';
        exit;
    }

    $store = with_store(false, static fn(array &$s) => $s);
    $settings = $store['settings'];
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    $csrf = h(csrf_token());
    $check = static fn(string $key): string => !empty($settings[$key]) ? ' checked' : '';
    $value = static fn(string $key): string => h($settings[$key] ?? '');
    echo '<!doctype html><html lang="en"><head><meta name="viewport" content="width=device-width,initial-scale=1"><meta charset="utf-8"><title>Backend Admin</title><style>
    *{box-sizing:border-box}body{font:15px/1.45 system-ui,-apple-system,Segoe UI,sans-serif;max-width:1050px;margin:24px auto;padding:0 16px;background:#101116;color:#e8e9ed}h1,h2{line-height:1.2}section{background:#191a20;border:1px solid #33353f;border-radius:14px;padding:18px;margin:16px 0}label{display:block;margin:10px 0 4px;color:#bfc1c9}input,textarea,button{font:inherit;width:100%;padding:10px;border-radius:8px;border:1px solid #454752;background:#22242b;color:#f6f6f7}textarea{min-height:88px}input[type=checkbox]{width:auto;transform:scale(1.15);margin-right:8px}button{width:auto;background:#eceef2;color:#16171b;font-weight:700;cursor:pointer}.danger{background:#9d3030;color:white}.row{display:flex;gap:10px;flex-wrap:wrap}.row>*{flex:1;min-width:180px}.small{color:#a3a5ad;font-size:13px}.notice{padding:12px;background:#243427;border-radius:8px;margin:12px 0}.err{background:#492323}.card{border:1px solid #3b3d47;border-radius:10px;padding:12px;margin:10px 0;overflow-wrap:anywhere}.inline{display:inline-block;width:auto;margin:4px}.top{display:flex;justify-content:space-between;align-items:center;gap:12px}.top form{margin:0}code{overflow-wrap:anywhere}
    </style></head><body><div class="top"><h1>'.h($APP_NAME).' · Admin panel</h1><form method="post"><input type="hidden" name="action" value="logout"><button>Sign out</button></form></div>';
    if (is_array($flash)) {
        if (!empty($flash['error'])) echo '<div class="notice err">'.h($flash['error']).'</div>';
        elseif (!empty($flash['message'])) echo '<div class="notice">'.h($flash['message']).'</div>';
        if (!empty($flash['credentials'])) {
            $c = $flash['credentials'];
            echo '<section><h2>Copy new account credentials now</h2><p>Username: <code>'.h($c['username']).'</code></p><p>Password: <code>'.h($c['password']).'</code></p><p class="small">Password is stored as a hash and cannot be recovered from this panel.</p></section>';
        }
    }
    echo '<p class="small">App ID: '.h(env_value('APP_ID', 'watan_main')).' · Game ID expected by API: '.h(env_value('APP_GAME_ID', 'pubg')).'</p>';

    echo '<section><h2>License accounts</h2><form method="post"><input type="hidden" name="csrf" value="'.$csrf.'"><input type="hidden" name="action" value="create_account"><div class="row"><div><label>Label (optional)</label><input name="label" maxlength="100" placeholder="Customer or note"></div><div><label>License duration, hours</label><input type="number" name="duration_hours" min="1" max="8760" value="24"></div></div><p><button>Create username/password</button></p><p class="small">New credentials are shown once. Expiry begins on first successful login.</p></form>';
    if (!$store['accounts']) echo '<p class="small">No accounts yet.</p>';
    foreach (array_reverse($store['accounts']) as $account) {
        $status = !empty($account['active']) ? 'Active' : 'Disabled';
        $expiry = $account['expires_at'] ?? 'Starts after first login';
        echo '<div class="card"><strong>'.h($account['username'] ?? '').'</strong> — '.h($status).' — '.h($account['label'] ?? '').'<br><span class="small">Expires: '.h($expiry).' · Device linked: '.(!empty($account['serial_hash']) ? 'yes' : 'no').'</span>';
        foreach ([['toggle_account', !empty($account['active']) ? 'Disable' : 'Enable', ''], ['reset_device', 'Reset device link', ''], ['delete_account', 'Revoke', 'danger']] as [$act, $label, $class]) {
            echo '<form class="inline" method="post" onsubmit="return confirm(\''.h($label).' this account?\')"><input type="hidden" name="csrf" value="'.$csrf.'"><input type="hidden" name="action" value="'.h($act).'"><input type="hidden" name="id" value="'.h($account['id'] ?? '').'"><button class="'.h($class).'">'.h($label).'</button></form>';
        }
        echo '</div>';
    }
    echo '</section>';

    echo '<form method="post"><input type="hidden" name="csrf" value="'.$csrf.'"><input type="hidden" name="action" value="save_settings"><section><h2>App and account settings</h2><label><input type="checkbox" name="app_enabled"'.$check('app_enabled').'> App enabled</label><label><input type="checkbox" name="auto_generate"'.$check('auto_generate').'> Allow one-tap account registration</label><div class="row"><div><label>Max auto accounts per device</label><input type="number" name="auto_max_per_device" min="1" max="20" value="'.$value('auto_max_per_device').'"></div><div><label>Auto account duration, hours</label><input type="number" name="auto_duration_hours" min="1" max="8760" value="'.$value('auto_duration_hours').'"></div></div></section>';

    echo '<section><h2>Maintenance</h2><label><input type="checkbox" name="maintenance"'.$check('maintenance').'> Maintenance active now</label><label><input type="checkbox" name="maintenance_scheduled"'.$check('maintenance_scheduled').'> Show scheduled maintenance</label><div class="row"><div><label>Title</label><input name="maintenance_title" value="'.$value('maintenance_title').'" maxlength="160"></div><div><label>Progress percent</label><input type="number" name="maintenance_progress" min="0" max="100" value="'.$value('maintenance_progress').'"></div><div><label>ETA minutes (-1 unknown)</label><input type="number" name="maintenance_eta_minutes" min="-1" max="10080" value="'.$value('maintenance_eta_minutes').'"></div></div><label>Message</label><textarea name="maintenance_message">'.$value('maintenance_message').'</textarea><div class="row"><div><label>Starts at (optional)</label><input name="maintenance_starts_at" value="'.$value('maintenance_starts_at').'" placeholder="2026-11-23 18:00"></div><div><label>Ends at (optional)</label><input name="maintenance_ends_at" value="'.$value('maintenance_ends_at').'" placeholder="2026-11-23 19:00"></div></div></section>';

    echo '<section><h2>Update settings</h2><label><input type="checkbox" name="force_update"'.$check('force_update').'> Force update</label><div class="row"><div><label>Current version</label><input name="current_version" value="'.$value('current_version').'" maxlength="32"></div><div><label>Required version</label><input name="required_version" value="'.$value('required_version').'" maxlength="32"></div></div><label>HTTPS update URL</label><input name="update_url" value="'.$value('update_url').'" placeholder="https://example.com/app.apk"><label>Update message</label><input name="update_message" value="'.$value('update_message').'" maxlength="1000"><div class="row"><div><label>Package size</label><input name="update_size" value="'.$value('update_size').'" maxlength="100"></div><div><label>SHA-256 metadata (client currently does not verify it)</label><input name="update_sha256" value="'.$value('update_sha256').'" maxlength="64"></div></div><label>Changelog</label><textarea name="update_changelog">'.$value('update_changelog').'</textarea></section>';

    echo '<section><h2>Notices and links</h2><label>Notice title</label><input name="notice_title" value="'.$value('notice_title').'" maxlength="160"><label>Notice message</label><textarea name="notice">'.$value('notice').'</textarea><label>Updates channel (HTTPS)</label><input name="telegram_channel" value="'.$value('telegram_channel').'" placeholder="https://t.me/yourchannel"></section><p><button>Save all settings</button></p></form>';

    echo '<section><h2>Notifications</h2><form method="post"><input type="hidden" name="csrf" value="'.$csrf.'"><input type="hidden" name="action" value="add_notification"><label>Title</label><input name="notification_title" maxlength="160"><label>Message</label><textarea name="notification_body"></textarea><p><button>Add public notification</button></p></form>';
    if (!$store['notifications']) echo '<p class="small">No active notifications.</p>';
    foreach ($store['notifications'] as $n) {
        echo '<div class="card"><strong>'.h($n['title'] ?? '').'</strong><br>'.nl2br(h($n['body'] ?? '')).'<form method="post" class="inline"><input type="hidden" name="csrf" value="'.$csrf.'"><input type="hidden" name="action" value="delete_notification"><input type="hidden" name="id" value="'.h($n['id'] ?? '').'"><button class="danger">Remove</button></form></div>';
    }
    echo '</section><section><h2>API routes</h2><p><code>POST /connect</code> · <code>POST /api/license/auto-register</code> · <code>GET /api/remote/settings</code></p><p class="small">Keep this panel behind HTTPS. Do not share the admin password. Use APP_DATA_DIR outside the public web directory where your host allows it.</p></section></body></html>';
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if (($method === 'GET' || $method === 'POST') && ($path === '/admin' || $path === '/admin/')) admin_page();
if ($method === 'GET' && ($path === '/' || $path === '/health')) reply(200, ['ok' => true, 'message' => 'Backend is running']);

if ($method === 'GET' && $path === '/api/remote/settings') {
    $store = with_store(false, static fn(array &$s) => $s);
    $s = $store['settings'];
    $scheduledActive = false;
    if (!empty($s['maintenance_scheduled']) && !empty($s['maintenance_starts_at'])) {
        $startAt = strtotime((string)$s['maintenance_starts_at']);
        $endAt = !empty($s['maintenance_ends_at']) ? strtotime((string)$s['maintenance_ends_at']) : false;
        $now = time();
        $scheduledActive = $startAt !== false && $now >= $startAt && ($endAt === false || $now <= $endAt);
    }
    $maintenanceActive = !empty($s['maintenance']) || $scheduledActive;
    reply(200, [
        'status' => true,
        'data' => [
            'app_enabled' => (bool)$s['app_enabled'],
            'force_update' => (bool)$s['force_update'],
            'app' => ['name' => (string)$s['app_name']],
            'version' => [
                'current' => (string)$s['current_version'],
                'required' => (string)$s['required_version'],
                'update_url' => (string)$s['update_url'],
                'message' => (string)$s['update_message'],
                'size' => (string)$s['update_size'],
                'changelog' => (string)$s['update_changelog'],
                'sha256' => (string)$s['update_sha256'],
            ],
            'notice_title' => (string)$s['notice_title'],
            'notice' => (string)$s['notice'],
            'maintenance' => $maintenanceActive,
            'maintenance_info' => [
                'active' => $maintenanceActive,
                'scheduled' => (bool)$s['maintenance_scheduled'],
                'title' => (string)$s['maintenance_title'],
                'message' => (string)$s['maintenance_message'],
                'progress_percent' => (int)$s['maintenance_progress'],
                'eta_minutes' => (int)$s['maintenance_eta_minutes'],
                'starts_at' => (string)$s['maintenance_starts_at'],
                'ends_at' => (string)$s['maintenance_ends_at'],
            ],
            'links' => ['telegram_channel' => (string)$s['telegram_channel']],
            'notifications' => array_slice($store['notifications'], -10),
        ],
    ]);
}

if ($method === 'POST' && $path === '/api/license/auto-register') {
    global $APP_ID, $APP_GAME_ID;
    $input = request_fields();
    $requestedApp = trim((string)($input['app_id'] ?? ''));
    $game = trim((string)($input['game'] ?? ''));
    $serial = trim((string)($input['serial'] ?? ''));
    if ($requestedApp !== $APP_ID || $game !== $APP_GAME_ID || $serial === '' || strlen($serial) > 256) {
        reply(400, ['status' => false, 'message' => 'Invalid app, game, or device information.']);
    }
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $result = with_store(true, function (array &$store) use ($serial, $ip): array {
        if (!allow_rate($store, 'auto-register', $ip, 5, 3600)) return ['status' => false, 'message' => 'Too many registration attempts. Try again later.', '_http' => 429];
        if (empty($store['settings']['app_enabled'])) return ['status' => false, 'message' => 'Application is disabled.'];
        if (empty($store['settings']['auto_generate'])) return ['status' => false, 'message' => 'Automatic registration is currently unavailable.'];
        $serialHash = hash('sha256', $serial);
        $max = max(1, min(20, (int)$store['settings']['auto_max_per_device']));
        $used = 0;
        foreach ($store['accounts'] as $account) {
            if (!empty($account['auto_created']) && hash_equals((string)($account['serial_hash'] ?? ''), $serialHash)) $used++;
        }
        if ($used >= $max) return ['status' => false, 'message' => 'This device has reached its account limit.'];
        $duration = max(1, min(8760, (int)$store['settings']['auto_duration_hours']));
        $created = create_account($store, $duration, 'Auto registration', $serialHash, true);
        return ['status' => true, 'username' => $created['username'], 'password' => $created['password']];
    });
    $http = (int)($result['_http'] ?? 200);
    unset($result['_http']);
    reply($http, $result);
}

if ($method === 'POST' && $path === '/connect') {
    global $APP_GAME_ID;
    $input = request_fields();
    $game = trim((string)($input['game'] ?? ''));
    $username = trim((string)($input['username'] ?? ''));
    $password = trim((string)($input['password'] ?? ''));
    $serial = trim((string)($input['serial'] ?? ''));
    if ($game !== $APP_GAME_ID || !preg_match('/^VANTHOR[0-9]{7}$/', $username) || strlen($password) !== 20 || $serial === '' || strlen($serial) > 256) {
        reply(200, ['status' => false, 'reason' => 'Invalid login details or app configuration.']);
    }
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $result = with_store(true, function (array &$store) use ($username, $password, $serial, $ip): array {
        if (!allow_rate($store, 'connect', $ip, 20, 300)) return ['_http' => 429, 'body' => ['status' => false, 'reason' => 'Too many login attempts. Try again later.']];
        if (empty($store['settings']['app_enabled'])) return ['_http' => 200, 'body' => ['status' => false, 'reason' => 'Application is disabled.']];
        $serialHash = hash('sha256', $serial);
        foreach ($store['accounts'] as &$account) {
            if (!hash_equals((string)($account['username'] ?? ''), $username)) continue;
            if (empty($account['active']) || !password_verify($password, (string)($account['password_hash'] ?? ''))) break;
            if (!empty($account['expires_at'])) {
                try {
                    if (new DateTimeImmutable((string)$account['expires_at']) <= utc_now()) return ['_http' => 200, 'body' => ['status' => false, 'reason' => 'License has expired.']];
                } catch (Throwable) {
                    return ['_http' => 200, 'body' => ['status' => false, 'reason' => 'License expiry is invalid.']];
                }
            }
            if (!empty($account['serial_hash']) && !hash_equals((string)$account['serial_hash'], $serialHash)) {
                return ['_http' => 200, 'body' => ['status' => false, 'reason' => 'Account is already linked to another device.']];
            }
            if (empty($account['serial_hash'])) $account['serial_hash'] = $serialHash;
            if (empty($account['expires_at'])) {
                $hours = max(1, min(8760, (int)($account['duration_hours'] ?? 24)));
                $account['expires_at'] = utc_now()->modify('+' . $hours . ' hours')->format(DATE_ATOM);
            }
            $account['last_seen'] = utc_now()->format(DATE_ATOM);
            $expiry = (string)$account['expires_at'];
            unset($account);
            return ['_http' => 200, 'body' => [
                'status' => true,
                'EXP' => (string)strtotime($expiry),
                'server_time' => (string)time(),
                'token' => bin2hex(random_bytes(24)),
            ]];
        }
        unset($account);
        return ['_http' => 200, 'body' => ['status' => false, 'reason' => 'Invalid username or password.']];
    });
    reply((int)$result['_http'], $result['body']);
}

reply(404, ['ok' => false, 'error' => 'Endpoint not found']);
