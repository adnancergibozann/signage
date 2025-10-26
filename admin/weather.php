<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signage.php';
require_once __DIR__ . '/../includes/weather.php';

require_login();

$activePage = 'weather';
$user = current_user();
$errors = [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pdo = get_pdo();
$settingsRow = get_weather_settings($pdo);
$current = fetch_latest_weather($pdo);
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

$selectedCity = $_POST['city'] ?? ($settingsRow['city'] ?: ($current['city'] ?? ''));
$refreshInterval = isset($_POST['refresh_interval'])
    ? (int) $_POST['refresh_interval']
    : (int) ($settingsRow['refresh_interval_minutes'] ?? 30);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $city = trim($_POST['city'] ?? '');
    $selectedCity = $city;
    $refreshInterval = isset($_POST['refresh_interval']) ? (int) $_POST['refresh_interval'] : $refreshInterval;

    if ($city === '') {
        $errors[] = 'Şehir seçimi zorunludur.';
    }

    if ($refreshInterval < 5 || $refreshInterval > 180) {
        $errors[] = 'Otomatik yenileme aralığı 5 ile 180 dakika arasında olmalıdır.';
    }

    if (!$errors) {
        try {
            $latitude = null;
            $longitude = null;

            if (!empty($settingsRow['city']) && strcasecmp($settingsRow['city'], $city) === 0) {
                $latitude = $settingsRow['latitude'];
                $longitude = $settingsRow['longitude'];
            }

            $previewWeather = fetch_weather_from_open_meteo($city, $latitude, $longitude);
        } catch (Throwable $e) {
            $errors[] = 'API isteği başarısız: ' . $e->getMessage();
        }
    }

    if (!$errors && $previewWeather) {
        store_weather_snapshot($previewWeather, $pdo);
        save_weather_settings([
            'city' => $previewWeather['city'],
            'latitude' => $previewWeather['latitude'],
            'longitude' => $previewWeather['longitude'],
            'refresh_interval_minutes' => $refreshInterval,
        ], $pdo);

        $_SESSION['flash'] = sprintf(
            '%s için hava durumu güncellendi. Otomatik yenileme %d dakikada bir yapılacak.',
            $previewWeather['city'],
            $refreshInterval
        );
        header('Location: ' . route_url('admin/weather.php'));
        exit;
    }
}

$settingsRow = get_weather_settings($pdo);
$refreshInterval = (int) ($settingsRow['refresh_interval_minutes'] ?? $refreshInterval);
$history = $pdo->query('SELECT city, temperature, condition_label, fetched_at FROM weather_snapshots ORDER BY fetched_at DESC LIMIT 10')->fetchAll();
$displayWeather = $previewWeather ?: $current;
$lastUpdatedLabel = null;
if ($displayWeather && !empty($displayWeather['fetched_at'])) {
    try {
        $tz = new DateTimeZone(date_default_timezone_get() ?: 'Europe/Istanbul');
        $lastDate = new DateTimeImmutable($displayWeather['fetched_at'], $tz);
        $lastUpdatedLabel = $lastDate->format('d.m.Y H:i');
    } catch (Throwable $e) {
        $lastUpdatedLabel = $displayWeather['fetched_at'];
    }
}
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
            <p>Open-Meteo API üzerinden verileri çek ve signage ekranını belirlediğin aralıkta otomatik güncelle.</p>
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
            <p style="margin: 0 0 1rem; color: rgba(255,255,255,0.7);">
                Otomatik yenileme aralığı: <strong><?php echo htmlspecialchars($refreshInterval, ENT_QUOTES, 'UTF-8'); ?></strong> dakika.
                <?php if ($lastUpdatedLabel): ?>
                    <span style="margin-left: 0.5rem;">Son güncelleme: <strong><?php echo htmlspecialchars($lastUpdatedLabel, ENT_QUOTES, 'UTF-8'); ?></strong></span>
                <?php endif; ?>
            </p>
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
                <div>
                    <label for="refresh_interval">Otomatik Yenileme (dakika)</label>
                    <input type="number" min="5" max="180" step="1" name="refresh_interval" id="refresh_interval" value="<?php echo htmlspecialchars($refreshInterval, ENT_QUOTES, 'UTF-8'); ?>" required>
                    <p class="form-help">Signage ekranı bu aralıkla Open-Meteo'dan veri alacak.</p>
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
