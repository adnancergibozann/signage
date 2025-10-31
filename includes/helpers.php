<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function config_value(string $path, mixed $default = null): mixed
{
    $segments = explode('.', $path);
    $value = $GLOBALS['config'] ?? [];
    foreach ($segments as $segment) {
        if (is_array($value) && array_key_exists($segment, $value)) {
            $value = $value[$segment];
        } else {
            return $default;
        }
    }
    return $value;
}

function ensure_directory(string $path): void
{
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
}

function base_uri(): string
{
    $basePath = config_value('app.base_path', '');
    if ($basePath === null || $basePath === '') {
        return '';
    }

    $normalized = '/' . ltrim($basePath, '/');
    return rtrim($normalized, '/') ?: '';
}

function url_for(string $path = ''): string
{
    if ($path === '') {
        $normalized = '/';
    } else {
        $normalized = '/' . ltrim($path, '/');
    }

    if (preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $path)) {
        return $path;
    }

    $base = base_uri();

    if ($base !== '' && ($normalized === $base || str_starts_with($normalized, $base . '/'))) {
        return $normalized;
    }

    if ($base === '' || $normalized === '/') {
        return $base . $normalized;
    }

    return $base . $normalized;
}

function asset_url(string $relativePath): string
{
    if (preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $relativePath)) {
        return $relativePath;
    }

    $path = ltrim($relativePath, '/');
    return url_for($path);
}

function manager_role_keys(): array
{
    return ['manager', 'finance', 'accounting'];
}

function role_label(?string $role): string
{
    return match ($role) {
        'super_admin' => 'Süper Admin',
        'boss' => 'Patron',
        'manager' => 'Satınalma Müdürü',
        'finance' => 'Finans',
        'accounting' => 'Muhasebe',
        'secretary' => 'Sekreter',
        'viewer' => 'Signage İzleyici',
        null => '',
        default => ucwords(str_replace('_', ' ', (string) $role)),
    };
}

function is_manager_role(?string $role): bool
{
    return $role !== null && in_array($role, manager_role_keys(), true);
}

function public_path(string $relativePath): string
{
    return __DIR__ . '/../public/' . ltrim($relativePath, '/');
}

function upload_file(array $file, string $targetDir, array $allowedMime, array $allowedExtensions): ?string
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $tmpName = $file['tmp_name'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmpName) ?: '';

    if (!in_array($mime, $allowedMime, true)) {
        return null;
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExtensions, true)) {
        return null;
    }

    ensure_directory($targetDir);
    $filename = uniqid('', true) . '.' . $ext;
    $destination = rtrim($targetDir, '/') . '/' . $filename;

    if (!move_uploaded_file($tmpName, $destination)) {
        return null;
    }

    return $filename;
}

function get_setting(string $key, mixed $default = null): mixed
{
    if (!isset($GLOBALS['settings_cache'])) {
        $GLOBALS['settings_cache'] = load_all_settings();
    }

    return $GLOBALS['settings_cache'][$key] ?? $default;
}

function set_setting(string $key, mixed $value): void
{
    $pdo = get_pdo();
    $stmt = $pdo->prepare('INSERT INTO settings (`key`, `value`) VALUES (:key, :value)
        ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
    $stmt->execute([
        'key' => $key,
        'value' => is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value,
    ]);

    $GLOBALS['settings_cache'][$key] = is_array($value)
        ? json_encode($value, JSON_UNESCAPED_UNICODE)
        : (string) $value;
}

function load_all_settings(): array
{
    $pdo = get_pdo();
    $stmt = $pdo->query('SELECT `key`, `value` FROM settings');
    $settings = [];
    foreach ($stmt as $row) {
        $settings[$row['key']] = $row['value'];
    }
    return $settings;
}

function format_datetime(?string $datetime, string $format = 'd.m.Y H:i'): string
{
    if (!$datetime) {
        return '';
    }
    $dt = new DateTime($datetime);
    return $dt->format($format);
}

function format_duration(int $seconds): string
{
    $minutes = intdiv($seconds, 60);
    $seconds = $seconds % 60;
    return sprintf('%02d:%02d', $minutes, $seconds);
}

function parse_datetime_local(string $value): ?string
{
    if ($value === '') {
        return null;
    }
    try {
        $dt = new DateTime($value);
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception) {
        return null;
    }
}

function extract_time_component(?string $value): ?string
{
    if ($value === null) {
        return null;
    }
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (preg_match('/^\d{2}:\d{2}$/', $value) === 1) {
        return $value;
    }
    try {
        $dt = new DateTime($value);
        return $dt->format('H:i');
    } catch (Exception) {
        return null;
    }
}

function active_between(?string $startsAt, ?string $endsAt, DateTimeImmutable $now): bool
{
    if ($startsAt && $now < new DateTimeImmutable($startsAt)) {
        return false;
    }
    if ($endsAt && $now > new DateTimeImmutable($endsAt)) {
        return false;
    }
    return true;
}
