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

function asset_url(string $relativePath): string
{
    return '/' . ltrim($relativePath, '/');
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
