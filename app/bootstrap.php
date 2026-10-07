<?php
declare(strict_types=1);
define('ROOT', dirname(__DIR__));
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', ROOT . '/var/application.log');
$config = is_file(ROOT . '/config/local.php') ? require ROOT . '/config/local.php' : require ROOT . '/config/example.php';
date_default_timezone_set($config['timezone']);
function db(): PDO {
    static $pdo;
    global $config;
    if (!$pdo) {
        $pdo = new PDO($config['dsn'], $config['db_user'], $config['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}
function query(string $sql, array $params = []): PDOStatement { $s = db()->prepare($sql); $s->execute($params); return $s; }
function one(string $sql, array $params = []): ?array { return query($sql, $params)->fetch() ?: null; }
function rows(string $sql, array $params = []): array { return query($sql, $params)->fetchAll(); }
function scalar(string $sql, array $params = []): mixed { return query($sql, $params)->fetchColumn(); }
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function input(string $key, string $default = ''): string { $v = $_POST[$key] ?? $default; return is_string($v) ? trim($v) : ''; }
function get(string $key, string $default = ''): string { $v = $_GET[$key] ?? $default; return is_string($v) ? trim($v) : ''; }
function url(string $page = 'home', array $args = []): string { return 'index.php?' . http_build_query(['page' => $page] + $args); }
function go(string $page, array $args = []): never { header('Location: ' . url($page, $args), true, 303); exit; }
function flash(string $message, string $kind = 'success'): void { $_SESSION['flash'][] = [$message, $kind]; }
class UserError extends RuntimeException {}
function valid(bool $condition, string $message): void { if (!$condition) throw new UserError($message); }
function field(string $name, int $min, int $max): string {
    $v = input($name); valid(mb_strlen($v) >= $min && mb_strlen($v) <= $max, ucfirst(str_replace('_', ' ', $name)) . " must be {$min}–{$max} characters."); return $v;
}
function csrf(): string { return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">'; }
function action(string $name): string { return csrf() . '<input type="hidden" name="action" value="' . e($name) . '">'; }
function audit(string $action, ?int $id = null): void { query('INSERT INTO audit_log(student_id,action) VALUES (?,?)', [$id, $action]); }
function rate(string $key, int $limit, int $seconds): void {
    global $config;
    $bucket = hash_hmac('sha256', $key, $config['app_key']);
    query('INSERT INTO rate_limits(bucket,attempts,expires_at) VALUES (?,1,DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND)) ON DUPLICATE KEY UPDATE attempts=IF(expires_at<=UTC_TIMESTAMP(),1,attempts+1), expires_at=IF(expires_at<=UTC_TIMESTAMP(),VALUES(expires_at),expires_at)', [$bucket, $seconds]);
    if ((int)scalar('SELECT attempts FROM rate_limits WHERE bucket=?', [$bucket]) > $limit) { http_response_code(429); throw new UserError('Too many attempts. Please wait a few minutes before trying again.'); }
}
function user(): ?array {
    static $loaded = false, $current = null;
    if (!$loaded) {
        $loaded = true;
        if (isset($_SESSION['uid'])) {
            $current = one('SELECT id,name,email,role,bio,organiser_requested,session_version,created_at FROM students WHERE id=?', [$_SESSION['uid']]);
            if (!$current || $current['session_version'] !== ($_SESSION['version'] ?? null)) { unset($_SESSION['uid'], $_SESSION['version']); $current = null; }
        }
    }
    return $current;
}
function require_user(): array { $u = user(); if (!$u) { flash('Please log in to continue.', 'info'); go('login'); } return $u; }
function organiser(): array { $u = require_user(); if (!in_array($u['role'], ['organiser', 'admin'], true)) fail(403, 'Organiser access required', 'Request organiser access from your dashboard.'); return $u; }
function admin(): array { $u = require_user(); if ($u['role'] !== 'admin') fail(403, 'This area is private', 'Only campus administrators can open this page.'); return $u; }
function owned_club(int $id): array { $u = organiser(); $club = one('SELECT * FROM clubs WHERE id=? AND owner_id=?', [$id, $u['id']]); if (!$club) fail(404, 'Club not found', 'This club does not exist or you do not manage it.'); return $club; }
function fail(int $status, string $title, string $message): never { http_response_code($status); render_header($title); echo '<section class="empty panel"><div class="eyebrow">' . $status . '</div><h1>' . e($title) . '</h1><p>' . e($message) . '</p><a class="btn" href="' . e(url()) . '">Back to campus</a></section>'; render_footer(); exit; }
function when(string $date, string $format = 'D, j M · g:ia'): string { global $config; return (new DateTimeImmutable($date, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($config['timezone']))->format($format); }
function initials(string $name): string { $parts = preg_split('/\s+/', trim($name)); return mb_strtoupper(mb_substr($parts[0], 0, 1) . (count($parts)>1 ? mb_substr(end($parts), 0, 1) : '')); }
function selected(string $a, string $b): string { return $a === $b ? ' selected' : ''; }
function old(string $key, string $fallback = ''): string { return e($_SERVER['REQUEST_METHOD'] === 'POST' ? input($key, $fallback) : $fallback); }
function categories(): array { return ['Creative', 'Technology', 'Sport & wellbeing', 'Culture', 'Academic', 'Community']; }
function themes(): array { return ['mint','peach','lavender','yellow','blue','rose']; }

$secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
if ($config['production'] && !$secure && PHP_SAPI !== 'cli') { http_response_code(400); exit('HTTPS is required. Configure TLS before enabling production.'); }
if (PHP_SAPI !== 'cli') {
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
    header('X-Content-Type-Options: nosniff'); header('X-Frame-Options: DENY'); header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()'); header('Cache-Control: no-store');
    if ($secure) header('Strict-Transport-Security: max-age=31536000');
    ini_set('session.use_strict_mode','1'); ini_set('session.use_only_cookies','1');
    if (!is_dir(ROOT . '/var/sessions')) mkdir(ROOT . '/var/sessions', 0700, true);
    session_save_path(ROOT . '/var/sessions');
    session_name('campusconnect_session');
    session_set_cookie_params(['lifetime'=>0,'path'=>rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME'])), '/') . '/', 'secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
    if (!session_start()) { http_response_code(503); exit('Session storage is unavailable. Ensure the application can write to var/sessions.'); }
    if (isset($_SESSION['last']) && (time()-$_SESSION['last']>1800 || time()-($_SESSION['born']??time())>28800)) { $_SESSION=[]; session_regenerate_id(true); $_SESSION['flash'][]=['Your session expired. Please log in again.','info']; }
    $_SESSION['last']=time(); $_SESSION['born']??=time(); $_SESSION['csrf']??=bin2hex(random_bytes(32));
}
