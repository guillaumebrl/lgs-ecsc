<?php
declare(strict_types=1);

function load_env(string $path): void {
    if (!is_file($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $value = trim($value, "\"'");
        if (getenv($key) === false) putenv("$key=$value");
    }
}

load_env(dirname(__DIR__) . '/.env');

function env(string $key, ?string $default = null): ?string {
    $value = getenv($key);
    return $value === false ? $default : $value;
}

function db(): PDO {
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', env('DB_HOST', 'localhost'), env('DB_PORT', '3306'), env('DB_DATABASE', 'school_app'));
    $pdo = new PDO($dsn, env('DB_USERNAME', 'root'), env('DB_PASSWORD', ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => true,
    ]);
    return $pdo;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(['httponly' => true, 'secure' => env('APP_ENV') === 'production', 'samesite' => 'Lax']);
    session_start();
}

function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function csrf(): string { $_SESSION['csrf'] ??= bin2hex(random_bytes(24)); return $_SESSION['csrf']; }
function verify_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($_SESSION['csrf'] ?? '', $_POST['_token'] ?? '')) {
        http_response_code(419); exit('Session expirée. Rechargez la page.');
    }
}
function redirect(string $path): never { header('Location: ' . $path); exit; }
function flash(string $message, string $type = 'success'): void { $_SESSION['flash'] = compact('message', 'type'); }
function user(): ?array { return $_SESSION['user'] ?? null; }
function require_login(): void { if (!user()) redirect('?page=login'); }
function require_role(array $roles): void {
    require_login();
    if (!in_array(user()['role'], $roles, true)) { http_response_code(403); exit('Accès interdit'); }
}
function audit(string $action, string $entity, ?int $entityId = null, ?array $before = null, ?array $after = null): void {
    $stmt = db()->prepare('INSERT INTO audit_logs(user_id, action, entity_type, entity_id, before_json, after_json, ip_address) VALUES(?,?,?,?,?,?,?)');
    $stmt->execute([user()['id'] ?? null, $action, $entity, $entityId, $before ? json_encode($before, JSON_UNESCAPED_UNICODE) : null, $after ? json_encode($after, JSON_UNESCAPED_UNICODE) : null, $_SERVER['REMOTE_ADDR'] ?? null]);
}
