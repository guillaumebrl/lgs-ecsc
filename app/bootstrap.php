<?php

declare(strict_types=1);

function load_env(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $value = trim($value, "\"'");
        if (getenv($key) === false) {
            putenv("$key=$value");
        }
    }
}

load_env(dirname(__DIR__) . '/.env');

function env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', env('DB_HOST', 'localhost'), env('DB_PORT', '3306'), env('DB_DATABASE', 'school_app'));
    $pdo = new PDO($dsn, env('DB_USERNAME', 'root'), env('DB_PASSWORD', ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(['httponly' => true, 'secure' => env('APP_ENV') === 'production', 'samesite' => 'Lax']);
    session_start();
}

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function period_status_label(string $status): string
{
    return match($status) {
        'draft' => 'Préparation',
        'open' => 'Ouvert',
        'closed' => 'Fermé',
        'validated' => 'Validé',
        default => $status,
    };
}

function display_decimal(mixed $value, int $precision = 2): string
{
    $formatted = number_format((float)$value, max(0, $precision), '.', '');
    $trimmed = rtrim(rtrim($formatted, '0'), '.');
    return str_replace('.', ',', $trimmed === '' ? '0' : $trimmed);
}

function input_decimal(mixed $value, int $precision = 2): string
{
    $trimmed = rtrim(rtrim(number_format((float)$value, max(0, $precision), '.', ''), '0'), '.');
    return $trimmed === '' ? '0' : $trimmed;
}

function weighted_subject_average(array $summary): ?float
{
    $weightedTotal = 0.0;
    $coefficientTotal = 0.0;
    foreach ($summary as $subject) {
        if ($subject['average'] === null) {
            continue;
        }
        $coefficient = max(0.0, (float)($subject['assignment_coefficient'] ?? 1));
        $weightedTotal += (float)$subject['average'] * $coefficient;
        $coefficientTotal += $coefficient;
    }
    return $coefficientTotal > 0 ? $weightedTotal / $coefficientTotal : null;
}
function csrf(): string
{
    $_SESSION['csrf'] ??= bin2hex(random_bytes(24));
    return $_SESSION['csrf'];
}
function verify_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($_SESSION['csrf'] ?? '', $_POST['_token'] ?? '')) {
        http_response_code(419);
        exit('Session expirée. Rechargez la page.');
    }
}
function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}
function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = compact('message', 'type');
}
function user(): ?array
{
    return $_SESSION['user'] ?? null;
}
function is_admin(): bool
{
    return (user()['role'] ?? null) === 'admin';
}
function has_capability(string $capability): bool
{
    if (is_admin()) {
        return true;
    }
    return !empty(user()[$capability]);
}
function direction_mode_active(): bool
{
    return is_admin() || (has_capability('can_direction') && active_work_mode() === 'direction');
}
function principal_mode_active(): bool
{
    return is_admin() || (has_capability('can_be_principal') && active_work_mode() === 'principal');
}
function active_work_mode(): string
{
    if (is_admin()) {
        return 'admin';
    }
    $requested = $_SESSION['work_mode'] ?? '';
    $allowed = [];
    if (has_capability('can_teach')) {
        $allowed[] = 'teacher';
    }
    if (has_capability('can_be_principal')) {
        $allowed[] = 'principal';
    }
    if (has_capability('can_direction')) {
        $allowed[] = 'direction';
    }
    if (in_array($requested, $allowed, true)) {
        return $requested;
    }
    return $allowed[0] ?? 'teacher';
}
function management_mode_active(): bool
{
    return is_admin() || direction_mode_active();
}
function require_management_mode(): void
{
    require_login();
    if (!management_mode_active()) {
        http_response_code(403);
        exit('Accès interdit');
    }
}
function school_life_mode_active(): bool
{
    return is_admin() || direction_mode_active();
}
function require_school_life_mode(): void
{
    require_login();
    if (!school_life_mode_active()) {
        http_response_code(403);
        exit('Accès interdit');
    }
}
function require_capability(string $capability): void
{
    require_login();
    if (!has_capability($capability)) {
        http_response_code(403);
        exit('Accès interdit');
    }
}
function refresh_session_user(): void
{
    if (!user()) {
        return;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE id=? AND active=1');
    $stmt->execute([user()['id']]);
    $account = $stmt->fetch();
    if (!$account) {
        session_destroy();
        redirect('?page=login');
    }
    unset($account['password']);
    $_SESSION['user'] = $account;
}
function require_login(): void
{
    if (!user()) {
        redirect('?page=login');
    }
}
function require_role(array $roles): void
{
    require_login();
    if (!in_array(user()['role'], $roles, true)) {
        http_response_code(403);
        exit('Accès interdit');
    }
}
function audit(string $action, string $entity, ?int $entityId = null, ?array $before = null, ?array $after = null): void
{
    $stmt = db()->prepare('INSERT INTO audit_logs(user_id, action, entity_type, entity_id, before_json, after_json, ip_address) VALUES(?,?,?,?,?,?,?)');
    $stmt->execute([user()['id'] ?? null, $action, $entity, $entityId, $before ? json_encode($before, JSON_UNESCAPED_UNICODE) : null, $after ? json_encode($after, JSON_UNESCAPED_UNICODE) : null, $_SERVER['REMOTE_ADDR'] ?? null]);
}
