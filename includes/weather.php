<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';

if (!function_exists('ensure_weather_settings_table')) {
    function ensure_weather_settings_table(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS weather_settings (
                id TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
                city VARCHAR(120) NOT NULL DEFAULT \'\',
                latitude DECIMAL(9,6) NULL,
                longitude DECIMAL(9,6) NULL,
                refresh_interval_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}

if (!function_exists('get_weather_settings')) {
    function get_weather_settings(?PDO $pdo = null): array
    {
        $pdo = $pdo ?? get_pdo();
        ensure_weather_settings_table($pdo);

        $stmt = $pdo->prepare('SELECT city, latitude, longitude, refresh_interval_minutes FROM weather_settings WHERE id = 1 LIMIT 1');
        $stmt->execute();
        $row = $stmt->fetch();

        if (!$row) {
            return [
                'city' => '',
                'latitude' => null,
                'longitude' => null,
                'refresh_interval_minutes' => 30,
            ];
        }

        return [
            'city' => $row['city'] ?? '',
            'latitude' => $row['latitude'] !== null ? (float) $row['latitude'] : null,
            'longitude' => $row['longitude'] !== null ? (float) $row['longitude'] : null,
            'refresh_interval_minutes' => (int) ($row['refresh_interval_minutes'] ?? 30),
        ];
    }
}

if (!function_exists('save_weather_settings')) {
    function save_weather_settings(array $settings, ?PDO $pdo = null): void
    {
        $pdo = $pdo ?? get_pdo();
        ensure_weather_settings_table($pdo);

        $stmt = $pdo->prepare('INSERT INTO weather_settings (id, city, latitude, longitude, refresh_interval_minutes) VALUES (1, :city, :lat, :lon, :interval)
            ON DUPLICATE KEY UPDATE city = VALUES(city), latitude = VALUES(latitude), longitude = VALUES(longitude), refresh_interval_minutes = VALUES(refresh_interval_minutes)');
        $stmt->execute([
            'city' => $settings['city'] ?? '',
            'lat' => $settings['latitude'] ?? null,
            'lon' => $settings['longitude'] ?? null,
            'interval' => max(1, (int) ($settings['refresh_interval_minutes'] ?? 30)),
        ]);
    }
}

if (!function_exists('http_get_json')) {
    function http_get_json(string $url): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'header' => "User-Agent: SignageDashboard/1.0\r\nAccept: application/json",
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new RuntimeException('Servise ulaşılamadı.');
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Geçersiz API yanıtı alındı.');
        }

        return $decoded;
    }
}

if (!function_exists('map_weather_code')) {
    function map_weather_code(?int $code): array
    {
        $map = [
            0 => ['Açık', '☀️'],
            1 => ['Güneşli', '🌤️'],
            2 => ['Parçalı Bulutlu', '⛅'],
            3 => ['Bulutlu', '☁️'],
            45 => ['Sisli', '🌫️'],
            48 => ['Sisli', '🌫️'],
            51 => ['Çise', '🌦️'],
            53 => ['Hafif Yağmur', '🌦️'],
            55 => ['Yağmur', '🌧️'],
            56 => ['Sulu Kar', '🌧️'],
            57 => ['Sulu Kar', '🌧️'],
            61 => ['Hafif Yağmur', '🌧️'],
            63 => ['Yağmur', '🌧️'],
            65 => ['Şiddetli Yağmur', '🌧️'],
            66 => ['Karla Karışık Yağmur', '🌨️'],
            67 => ['Karla Karışık Yağmur', '🌨️'],
            71 => ['Hafif Kar', '🌨️'],
            73 => ['Kar Yağışı', '❄️'],
            75 => ['Yoğun Kar', '❄️'],
            77 => ['Kar Taneleri', '❄️'],
            80 => ['Sağanak', '🌦️'],
            81 => ['Şiddetli Sağanak', '🌧️'],
            82 => ['Şiddetli Sağanak', '⛈️'],
            85 => ['Kar Sağanağı', '🌨️'],
            86 => ['Yoğun Kar Sağanağı', '❄️'],
            95 => ['Gök Gürültülü Fırtına', '⛈️'],
            96 => ['Dolu Fırtınası', '⛈️'],
            99 => ['Şiddetli Dolu', '⛈️'],
        ];

        return $map[$code] ?? ['Hava Durumu', 'ℹ️'];
    }
}

if (!function_exists('fetch_weather_from_open_meteo')) {
    function fetch_weather_from_open_meteo(string $city, ?float $latitude = null, ?float $longitude = null): array
    {
        if ($city === '') {
            throw new InvalidArgumentException('Şehir adı boş olamaz.');
        }

        $lat = $latitude;
        $lon = $longitude;
        $cityLabel = $city;

        if ($lat === null || $lon === null) {
            $geoUrl = sprintf(
                'https://geocoding-api.open-meteo.com/v1/search?name=%s&count=1&language=tr&format=json&country=TR',
                urlencode($city)
            );
            $geoData = http_get_json($geoUrl);
            if (empty($geoData['results'][0])) {
                throw new RuntimeException('Şehir bulunamadı.');
            }

            $location = $geoData['results'][0];
            if (!isset($location['latitude'], $location['longitude'])) {
                throw new RuntimeException('Koordinat bilgisi alınamadı.');
            }

            $lat = (float) $location['latitude'];
            $lon = (float) $location['longitude'];
            $cityLabel = $location['name'] ?? $city;
        }

        $weatherUrl = sprintf(
            'https://api.open-meteo.com/v1/forecast?latitude=%s&longitude=%s&current=temperature_2m,apparent_temperature,relative_humidity_2m,wind_speed_10m,weather_code&timezone=auto&temperature_unit=celsius&windspeed_unit=kmh',
            rawurlencode((string) $lat),
            rawurlencode((string) $lon)
        );

        $weatherData = http_get_json($weatherUrl);
        if (empty($weatherData['current'])) {
            throw new RuntimeException('Hava durumu verisi alınamadı.');
        }

        $current = $weatherData['current'];
        $code = isset($current['weather_code']) ? (int) $current['weather_code'] : null;
        [$conditionLabel, $icon] = map_weather_code($code);

        $now = new DateTimeImmutable('now');

        return [
            'city' => $cityLabel,
            'temperature' => isset($current['temperature_2m']) ? (float) $current['temperature_2m'] : null,
            'feels_like' => isset($current['apparent_temperature']) ? (float) $current['apparent_temperature'] : null,
            'humidity' => isset($current['relative_humidity_2m']) ? (int) $current['relative_humidity_2m'] : null,
            'wind_speed' => isset($current['wind_speed_10m']) ? (float) $current['wind_speed_10m'] : null,
            'condition' => $conditionLabel,
            'icon' => $icon,
            'latitude' => $lat,
            'longitude' => $lon,
            'fetched_at' => $now->format('Y-m-d H:i:s'),
        ];
    }
}

if (!function_exists('store_weather_snapshot')) {
    function store_weather_snapshot(array $weather, ?PDO $pdo = null): array
    {
        $pdo = $pdo ?? get_pdo();
        $pdo->beginTransaction();
        $pdo->exec('UPDATE weather_snapshots SET is_active = 0');

        $stmt = $pdo->prepare('INSERT INTO weather_snapshots (city, temperature, feels_like, humidity, wind_speed, condition_label, condition_icon, fetched_at, is_active) VALUES (:city, :temp, :feels, :humidity, :wind, :label, :icon, :fetched_at, 1)');
        $stmt->execute([
            'city' => $weather['city'],
            'temp' => $weather['temperature'],
            'feels' => $weather['feels_like'],
            'humidity' => $weather['humidity'],
            'wind' => $weather['wind_speed'],
            'label' => $weather['condition'],
            'icon' => $weather['icon'],
            'fetched_at' => $weather['fetched_at'] ?? (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
        ]);
        $pdo->commit();

        return [
            'city' => $weather['city'],
            'temperature' => $weather['temperature'],
            'feels_like' => $weather['feels_like'],
            'humidity' => $weather['humidity'],
            'wind_speed' => $weather['wind_speed'],
            'condition' => $weather['condition'],
            'icon' => $weather['icon'],
            'fetched_at' => $weather['fetched_at'] ?? (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
        ];
    }
}

if (!function_exists('fetch_latest_weather')) {
    function fetch_latest_weather(?PDO $pdo = null): ?array
    {
        $pdo = $pdo ?? get_pdo();
        $stmt = $pdo->prepare('SELECT city, temperature, feels_like, humidity, wind_speed, condition_label, condition_icon, fetched_at FROM weather_snapshots WHERE is_active = 1 ORDER BY fetched_at DESC LIMIT 1');
        $stmt->execute();
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return [
            'city' => $row['city'],
            'temperature' => (float) $row['temperature'],
            'feels_like' => $row['feels_like'] !== null ? (float) $row['feels_like'] : null,
            'humidity' => $row['humidity'] !== null ? (int) $row['humidity'] : null,
            'wind_speed' => $row['wind_speed'] !== null ? (float) $row['wind_speed'] : null,
            'condition' => $row['condition_label'],
            'icon' => $row['condition_icon'],
            'fetched_at' => $row['fetched_at'],
        ];
    }
}

if (!function_exists('maybe_refresh_weather')) {
    function maybe_refresh_weather(array $settings, ?array $latest = null, ?PDO $pdo = null): ?array
    {
        $pdo = $pdo ?? get_pdo();
        $latest = $latest ?? fetch_latest_weather($pdo);
        $city = trim((string) ($settings['city'] ?? ''));
        if ($city === '') {
            return $latest;
        }

        $refreshMinutes = max(1, (int) ($settings['refresh_interval_minutes'] ?? 30));
        $now = new DateTimeImmutable('now');
        $needsRefresh = false;

        if (!$latest || empty($latest['fetched_at'])) {
            $needsRefresh = true;
        } else {
            try {
                $lastFetch = new DateTimeImmutable($latest['fetched_at']);
                $diffSeconds = $now->getTimestamp() - $lastFetch->getTimestamp();
                if ($diffSeconds >= $refreshMinutes * 60) {
                    $needsRefresh = true;
                }
            } catch (Throwable $e) {
                $needsRefresh = true;
            }
        }

        if (!$needsRefresh) {
            return $latest;
        }

        try {
            $weather = fetch_weather_from_open_meteo($city, $settings['latitude'] ?? null, $settings['longitude'] ?? null);
            $latest = store_weather_snapshot($weather, $pdo);
            save_weather_settings([
                'city' => $weather['city'],
                'latitude' => $weather['latitude'],
                'longitude' => $weather['longitude'],
                'refresh_interval_minutes' => $refreshMinutes,
            ], $pdo);
        } catch (Throwable $e) {
            // Sessizce yut ve mevcut veriyi kullan
        }

        return $latest;
    }
}
