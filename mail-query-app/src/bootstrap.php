<?php

declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';
const APP_DATA = APP_ROOT . '/data';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Shanghai');

function env_value(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function app_url(string $path = ''): string
{
    $base = rtrim(env_value('APP_BASE_URL', ''), '/');
    return $base . $path;
}

function redirect_to(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string) $token)) {
        http_response_code(422);
        exit('Invalid CSRF token');
    }
}

function flash_set(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_get(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function require_admin(): void
{
    if (empty($_SESSION['admin_authenticated'])) {
        redirect_to('/admin/login');
    }
}

function admin_password(): string
{
    return env_value('APP_ADMIN_PASSWORD', 'change-me-now');
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!is_dir(APP_DATA)) {
        mkdir(APP_DATA, 0775, true);
    }

    $pdo = new PDO('sqlite:' . APP_DATA . '/app.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    initialize_schema($pdo);
    return $pdo;
}

function initialize_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS access_links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL,
            token TEXT NOT NULL UNIQUE,
            expires_at TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "active",
            note TEXT NOT NULL DEFAULT "",
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS query_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            link_id INTEGER,
            token TEXT NOT NULL,
            email TEXT NOT NULL,
            ip_address TEXT NOT NULL,
            user_agent TEXT NOT NULL,
            result_count INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL
        )'
    );

    foreach (default_settings() as $key => $value) {
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO settings(key, value) VALUES(:key, :value)');
        $stmt->execute([':key' => $key, ':value' => (string) $value]);
    }
}

function default_settings(): array
{
    return [
        'imap_host' => env_value('APP_IMAP_HOST', 'imap.qq.com'),
        'imap_port' => env_value('APP_IMAP_PORT', '993'),
        'imap_encryption' => env_value('APP_IMAP_ENCRYPTION', 'ssl'),
        'imap_mailbox' => env_value('APP_IMAP_MAILBOX', 'INBOX'),
        'imap_email' => env_value('APP_IMAP_EMAIL', ''),
        'imap_password' => env_value('APP_IMAP_PASSWORD', ''),
        'sender_domains' => env_value('APP_SENDER_DOMAINS', 'netflix.com,account.netflix.com,mailer.netflix.com'),
        'subject_includes' => env_value('APP_SUBJECT_INCLUDES', ''),
        'subject_excludes' => env_value('APP_SUBJECT_EXCLUDES', ''),
        'recent_hours' => env_value('APP_RECENT_HOURS', '24'),
        'max_results' => env_value('APP_MAX_RESULTS', '10'),
        'default_expiry_hours' => env_value('APP_DEFAULT_EXPIRY_HOURS', '72'),
    ];
}

function get_settings(): array
{
    $rows = db()->query('SELECT key, value FROM settings')->fetchAll();
    $settings = default_settings();
    foreach ($rows as $row) {
        $settings[$row['key']] = $row['value'];
    }
    return $settings;
}

function save_settings(array $input): void
{
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO settings(key, value) VALUES(:key, :value) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    foreach ($input as $key => $value) {
        $stmt->execute([':key' => $key, ':value' => (string) $value]);
    }
}

function to_lines(string $value): array
{
    $parts = preg_split('/[\r\n,;，；]+/u', $value);
    $items = [];
    foreach ($parts as $part) {
        $part = trim((string) $part);
        if ($part !== '') {
            $items[] = mb_strtolower($part, 'UTF-8');
        }
    }
    return array_values(array_unique($items));
}

function now_string(): string
{
    return date('Y-m-d H:i:s');
}
