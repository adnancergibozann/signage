<?php

require_once __DIR__ . '/../../includes/weather.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = get_pdo();
    $settings = get_weather_settings($pdo);
    $latest = fetch_latest_weather($pdo);
    $latest = maybe_refresh_weather($settings, $latest, $pdo);

    if (!$latest) {
        echo json_encode([
            'success' => false,
            'error' => 'weather_not_configured',
            'message' => 'Hava durumu verisi bulunamadı.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $settings = get_weather_settings($pdo);

    echo json_encode([
        'success' => true,
        'data' => [
            'city' => $latest['city'],
            'temperature' => $latest['temperature'],
            'feels_like' => $latest['feels_like'],
            'humidity' => $latest['humidity'],
            'wind_speed' => $latest['wind_speed'],
            'condition' => $latest['condition'],
            'icon' => $latest['icon'],
            'fetched_at' => $latest['fetched_at'],
        ],
        'settings' => [
            'city' => $settings['city'],
            'refresh_interval_minutes' => $settings['refresh_interval_minutes'],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'server_error',
        'message' => 'Hava durumu güncellenemedi.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
