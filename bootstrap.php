<?php
declare(strict_types=1);

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_set_cookie_params([
    'httponly' => true,
    'secure' => $isHttps,
    'samesite' => 'Lax',
]);
session_start();

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');
header('X-Permitted-Cross-Domain-Policies: none');
header('Cache-Control: no-store, private');
header(
    "Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; " .
    "img-src 'self' data:; media-src 'self' http: https:; object-src 'none'; base-uri 'self'; " .
    "form-action 'self'; frame-ancestors 'none'"
);

function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function requestText(array $source, string $key, string $default = ''): string
{
    $value = $source[$key] ?? $default;
    return is_string($value) ? $value : $default;
}

function loadLocalConfig(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $localConfig = __DIR__ . '/.env';
    if (is_file($localConfig) && is_readable($localConfig)) {
        $lines = file($localConfig, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim(trim($value), "\"'");
            $isSupportedSetting = str_starts_with($key, 'TMS_DB_')
                || in_array($key, ['TMS_AUDIO_ROOT', 'TMS_AUDIO_BASE_URL'], true);
            $currentValue = getenv($key);
            if ($isSupportedSetting && ($currentValue === false || trim($currentValue) === '')) {
                putenv($key . '=' . $value);
            }
        }
    }
}

function configuredAudioRoot(): string|false
{
    loadLocalConfig();
    $audioRoot = trim((string) (getenv('TMS_AUDIO_ROOT') ?: ''));
    if ($audioRoot === '') {
        return false;
    }
    $resolvedRoot = realpath($audioRoot);
    return $resolvedRoot !== false && is_dir($resolvedRoot) ? $resolvedRoot : false;
}

function database(): PDO
{
    static $connection;
    if ($connection instanceof PDO) {
        return $connection;
    }

    loadLocalConfig();
    $host = getenv('TMS_DB_HOST') ?: '';
    $name = getenv('TMS_DB_NAME') ?: '';
    $user = getenv('TMS_DB_USER') ?: '';
    $password = getenv('TMS_DB_PASSWORD') ?: '';
    $port = getenv('TMS_DB_PORT') ?: '3306';

    if ($host === '' || $name === '' || $user === '') {
        throw new RuntimeException('Database settings are missing. Configure the TMS_DB_* environment variables.');
    }

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);
    $connection = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $connection;
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void
{
    $submitted = $_POST['csrf_token'] ?? '';
    if (!is_string($submitted) || !hash_equals(csrfToken(), $submitted)) {
        http_response_code(400);
        exit('Your session has expired. Reload the page and try again.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function takeFlash(): ?array
{
    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($message) ? $message : null;
}

function go(string $page = 'dashboard', array $params = []): never
{
    $query = http_build_query(['page' => $page] + $params);
    header('Location: index.php?' . $query);
    exit;
}

function currentSupervisor(PDO $pdo): ?array
{
    $username = $_SESSION['username'] ?? null;
    if (!is_string($username) || $username === '') {
        return null;
    }

    $statement = $pdo->prepare(
        "SELECT id, username, full_name, prof_pic
         FROM req_acc
         WHERE username = ? AND LOWER(role) = 'supervisor' AND LOWER(status) = 'active'
         LIMIT 1"
    );
    $statement->execute([$username]);
    $supervisor = $statement->fetch();
    return $supervisor ?: null;
}

function requirePost(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST');
        http_response_code(405);
        exit('This action requires a POST request.');
    }
}

function statusLabel(string $status): string
{
    return match (strtolower(trim($status))) {
        'uploaded' => 'Uploaded',
        'assigned' => 'Assigned',
        'pending' => 'Pending',
        'finished' => 'Finished',
        'suspended' => 'Suspended',
        default => ucfirst($status),
    };
}

function statusClass(string $status): string
{
    return match (strtolower(trim($status))) {
        'uploaded' => 'status-uploaded',
        'assigned' => 'status-assigned',
        'pending' => 'status-pending',
        'finished' => 'status-finished',
        'suspended' => 'status-suspended',
        default => 'status-neutral',
    };
}

function csvText(mixed $value): string
{
    $text = (string) $value;
    if ($text !== '' && preg_match('/^[=+\-@\t\r]/', $text)) {
        return "'" . $text;
    }
    return $text;
}

function audioUrl(mixed $filename, ?int $recordId = null): ?string
{
    loadLocalConfig();
    $audioRootSetting = trim((string) (getenv('TMS_AUDIO_ROOT') ?: ''));
    $resolvedAudioRoot = configuredAudioRoot();
    $baseUrl = trim((string) (getenv('TMS_AUDIO_BASE_URL') ?: ''));
    $relativePath = str_replace('\\', '/', trim((string) $filename));
    $segments = explode('/', $relativePath);
    if (
        $relativePath === ''
        || str_starts_with($relativePath, '/')
        || preg_match('/^[A-Za-z]:/', $relativePath)
        || array_filter($segments, static fn(string $segment): bool =>
            $segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, "\0")
        )
    ) {
        return null;
    }
    if ($resolvedAudioRoot !== false && $recordId !== null && $recordId > 0) {
        return 'audio.php?record_id=' . $recordId;
    }
    if ($audioRootSetting !== '' || $baseUrl === '') {
        return null;
    }

    $parts = parse_url($baseUrl);
    if (
        $parts === false
        || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
        || empty($parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])
    ) {
        return null;
    }

    $encodedRelativePath = implode('/', array_map('rawurlencode', $segments));
    return rtrim($baseUrl, '/') . '/' . $encodedRelativePath;
}

function dateValue(mixed $value): string
{
    $date = trim((string) $value);
    if ($date === '' || $date === '0000-00-00') {
        return 'Not set';
    }
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return e($date);
    }
    $isoDate = date('Y-m-d', $timestamp);
    return '<time class="date-value" datetime="' . e($isoDate) . '" data-calendar-date>' . e($isoDate) . '</time>';
}
