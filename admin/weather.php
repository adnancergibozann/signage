<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signage.php';

require_login();

$activePage = 'weather';
$user = current_user();
$errors = [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pdo = get_pdo();
$current = fetch_latest_weather();
$previewWeather = null;

$turkishCities = [
    'Adana', 'Adıyaman', 'Afyonkarahisar', 'Ağrı', 'Amasya', 'Ankara', 'Antalya', 'Artvin', 'Aydın', 'Balıkesir',
    'Bilecik', 'Bingöl', 'Bitlis', 'Bolu', 'Burdur', 'Bursa', 'Çanakkale', 'Çankırı', 'Çorum', 'Denizli',
    'Diyarbakır', 'Edirne', 'Elazığ', 'Erzincan', 'Erzurum', 'Eskişehir', 'Gaziantep', 'Giresun', 'Gümüşhane', 'Hakkari',
    'Hatay', 'Isparta', 'Mersin', 'İstanbul', 'İzmir', 'Kars', 'Kastamonu', 'Kayseri', 'Kırklareli', 'Kırşehir',
    'Kocaeli', 'Konya', 'Kütahya', 'Malatya', 'Manisa', 'Kahramanmaraş', 'Mardin', 'Muğla', 'Muş', 'Nevşehir',
    'Niğde', 'Ordu', 'Rize', 'Sakarya', 'Samsun', 'Siirt', 'Sinop', 'Sivas', 'Tekirdağ', 'Tokat',
    'Trabzon', 'Tunceli', 'Şanlıurfa', 'Uşak', 'Van', 'Yozgat', 'Zonguldak', 'Aksaray', 'Bayburt', 'Karaman',
    'Kırıkkale', 'Batman', 'Şırnak', 'Bartın', 'Ardahan', 'Iğdır', 'Yalova', 'Karabük', 'Kilis', 'Osmaniye', 'Düzce'
];

$selectedCity = $_POST['city'] ?? ($current['city'] ?? '');

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

function fetch_weather_from_open_meteo(string $city): array
{
    if ($city === '') {
        throw new InvalidArgumentException('Şehir adı boş olamaz.');
    }

    $geoUrl = 'https://geocoding-api.open-meteo.com/v1/search?name=' . urlencode($city) . '&count=1&language=tr&format=json&country=TR';
    $geoData = http_get_json($geoUrl);
    if (empty($geoData['results'][0])) {
        throw new RuntimeException('Şehir bulunamadı.');
    }

    $location = $geoData['results'][0];
    if (!isset($location['latitude'], $location['longitude'])) {
        throw new RuntimeException('Koordinat bilgisi alınamadı.');
    }

    $cityLabel = $location['name'];
    $weatherUrl = sprintf(
        'https://api.open-meteo.com/v1/forecast?latitude=%s&longitude=%s&current=temperature_2m,apparent_temperature,relative_humidity_2m,wind_speed_10m,weather_code&timezone=auto&temperature_unit=celsius&windspeed_unit=kmh',
        rawurlencode((string) $location['latitude']),
        rawurlencode((string) $location['longitude'])
    );

    $weatherData = http_get_json($weatherUrl);
    if (empty($weatherData['current'])) {
        throw new RuntimeException('Hava durumu verisi alınamadı.');
    }

    $current = $weatherData['current'];
    $code = isset($current['weather_code']) ? (int) $current['weather_code'] : null;
    [$conditionLabel, $icon] = map_weather_code($code);

    return [
        'city' => $cityLabel,
        'temperature' => isset($current['temperature_2m']) ? (float) $current['temperature_2m'] : null,
        'feels_like' => isset($current['apparent_temperature']) ? (float) $current['apparent_temperature'] : null,
        'humidity' => isset($current['relative_humidity_2m']) ? (int) $current['relative_humidity_2m'] : null,
        'wind_speed' => isset($current['wind_speed_10m']) ? (float) $current['wind_speed_10m'] : null,
        'condition' => $conditionLabel,
        'icon' => $icon,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $city = trim($_POST['city'] ?? '');
    $selectedCity = $city;

    if ($city === '') {
        $errors[] = 'Şehir seçimi zorunludur.';
    }

    if (!$errors) {
        try {
            $previewWeather = fetch_weather_from_open_meteo($city);
        } catch (Throwable $e) {
            $errors[] = 'API isteği başarısız: ' . $e->getMessage();
        }
    }

    if (!$errors && $previewWeather) {
        $pdo->beginTransaction();
        $pdo->exec('UPDATE weather_snapshots SET is_active = 0');
        $stmt = $pdo->prepare('INSERT INTO weather_snapshots (city, temperature, feels_like, humidity, wind_speed, condition_label, condition_icon, fetched_at, is_active) VALUES (:city, :temp, :feels, :humidity, :wind, :label, :icon, :fetched_at, 1)');
        $stmt->execute([
            'city' => $previewWeather['city'],
            'temp' => $previewWeather['temperature'],
            'feels' => $previewWeather['feels_like'],
            'humidity' => $previewWeather['humidity'],
            'wind' => $previewWeather['wind_speed'],
            'label' => $previewWeather['condition'],
            'icon' => $previewWeather['icon'],
            'fetched_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
        ]);
        $pdo->commit();

        $_SESSION['flash'] = sprintf('%s için hava durumu güncellendi.', $previewWeather['city']);
        header('Location: ' . route_url('admin/weather.php'));
        exit;
    }
}

$history = $pdo->query('SELECT city, temperature, condition_label, fetched_at FROM weather_snapshots ORDER BY fetched_at DESC LIMIT 10')->fetchAll();
$displayWeather = $previewWeather ?: $current;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hava Durumu | Signage</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/styles.css'), ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body>
<div class="admin-layout">
    <?php include __DIR__ . '/partials/sidebar.php'; ?>
    <main class="content">
        <header style="margin-bottom: 2rem;">
            <h1 style="color: var(--color-primary);">Hava Durumu</h1>
            <p>Open-Meteo API üzerinden son verileri getir.</p>
        </header>

        <?php if ($errors): ?>
            <div class="alert alert-error">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($flash): ?>
            <div class="alert" style="background: rgba(255,212,0,0.15); border:1px solid rgba(255,212,0,0.5);">
                <?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <section class="card" style="margin-bottom: 2rem;">
            <h2>Hava Durumu Güncelle</h2>
            <form method="post" class="form-grid" style="align-items: end;">
                <div>
                    <label for="city">Şehir</label>
                    <select name="city" id="city" required>
                        <option value="">Seçiniz</option>
                        <?php foreach ($turkishCities as $cityName): ?>
                            <option value="<?php echo htmlspecialchars($cityName, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $selectedCity === $cityName ? 'selected' : ''; ?>><?php echo htmlspecialchars($cityName, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="grid-column: 1 / -1;">
                    <button type="submit" class="button button-primary">API'den Güncelle</button>
                </div>
            </form>

            <?php if ($displayWeather): ?>
                <div style="margin-top: 1.5rem; display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem;">
                    <div style="padding: 1rem; border-radius: var(--border-radius); background: rgba(255,255,255,0.05); display: flex; flex-direction: column; gap: 0.25rem;">
                        <strong>Şehir</strong>
                        <p><?php echo htmlspecialchars($displayWeather['city'], ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>
                    <?php if ($displayWeather['temperature'] !== null): ?>
                        <div style="padding: 1rem; border-radius: var(--border-radius); background: rgba(255,255,255,0.05); display: flex; flex-direction: column; gap: 0.25rem;">
                            <strong>Sıcaklık</strong>
                            <p><?php echo htmlspecialchars(number_format($displayWeather['temperature'], 1), ENT_QUOTES, 'UTF-8'); ?> °C</p>
                        </div>
                    <?php endif; ?>
                    <?php if ($displayWeather['feels_like'] !== null): ?>
                        <div style="padding: 1rem; border-radius: var(--border-radius); background: rgba(255,255,255,0.05); display: flex; flex-direction: column; gap: 0.25rem;">
                            <strong>Hissedilen</strong>
                            <p><?php echo htmlspecialchars(number_format($displayWeather['feels_like'], 1), ENT_QUOTES, 'UTF-8'); ?> °C</p>
                        </div>
                    <?php endif; ?>
                    <?php if ($displayWeather['humidity'] !== null): ?>
                        <div style="padding: 1rem; border-radius: var(--border-radius); background: rgba(255,255,255,0.05); display: flex; flex-direction: column; gap: 0.25rem;">
                            <strong>Nem</strong>
                            <p>%<?php echo htmlspecialchars($displayWeather['humidity'], ENT_QUOTES, 'UTF-8'); ?></p>
                        </div>
                    <?php endif; ?>
                    <?php if ($displayWeather['wind_speed'] !== null): ?>
                        <div style="padding: 1rem; border-radius: var(--border-radius); background: rgba(255,255,255,0.05); display: flex; flex-direction: column; gap: 0.25rem;">
                            <strong>Rüzgar</strong>
                            <p><?php echo htmlspecialchars(number_format($displayWeather['wind_speed'], 1), ENT_QUOTES, 'UTF-8'); ?> km/sa</p>
                        </div>
                    <?php endif; ?>
                    <div style="padding: 1rem; border-radius: var(--border-radius); background: rgba(255,255,255,0.05); display: flex; flex-direction: column; gap: 0.25rem;">
                        <strong>Durum</strong>
                        <p><?php echo htmlspecialchars($displayWeather['condition'], ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars($displayWeather['icon'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>
                </div>
            <?php else: ?>
                <p style="margin-top:1rem; color: rgba(255,255,255,0.7);">Henüz hava durumu verisi alınmadı.</p>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2>Geçmiş Kayıtlar</h2>
            <?php if (!$history): ?>
                <p>Kayıt bulunmuyor.</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Şehir</th>
                            <th>Sıcaklık</th>
                            <th>Durum</th>
                            <th>Tarih</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($history as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['city'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($row['temperature'], ENT_QUOTES, 'UTF-8'); ?>°C</td>
                            <td><?php echo htmlspecialchars($row['condition_label'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($row['fetched_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </main>
</div>
</body>
</html>
