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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $city = trim($_POST['city'] ?? '');
    $temperature = (float) ($_POST['temperature'] ?? 0);
    $feelsLike = $_POST['feels_like'] !== '' ? (float) $_POST['feels_like'] : null;
    $humidity = $_POST['humidity'] !== '' ? (int) $_POST['humidity'] : null;
    $wind = $_POST['wind_speed'] !== '' ? (float) $_POST['wind_speed'] : null;
    $condition = trim($_POST['condition'] ?? '');
    $icon = trim($_POST['icon'] ?? '');

    if ($city === '') {
        $errors[] = 'Şehir adı zorunludur.';
    }
    if ($condition === '') {
        $errors[] = 'Hava durumu açıklaması zorunludur.';
    }

    if (!$errors) {
        $pdo->beginTransaction();
        $pdo->exec('UPDATE weather_snapshots SET is_active = 0');
        $stmt = $pdo->prepare('INSERT INTO weather_snapshots (city, temperature, feels_like, humidity, wind_speed, condition_label, condition_icon, fetched_at, is_active) VALUES (:city, :temp, :feels, :humidity, :wind, :label, :icon, :fetched_at, 1)');
        $stmt->execute([
            'city' => $city,
            'temp' => $temperature,
            'feels' => $feelsLike,
            'humidity' => $humidity,
            'wind' => $wind,
            'label' => $condition,
            'icon' => $icon,
            'fetched_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
        ]);
        $pdo->commit();

        $_SESSION['flash'] = 'Hava durumu güncellendi.';
        header('Location: /admin/weather.php');
        exit;
    }

    $current = fetch_latest_weather();
}

$history = $pdo->query('SELECT city, temperature, condition_label, fetched_at FROM weather_snapshots ORDER BY fetched_at DESC LIMIT 10')->fetchAll();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hava Durumu | Signage</title>
    <link rel="stylesheet" href="/assets/styles.css">
</head>
<body>
<div class="admin-layout">
    <?php include __DIR__ . '/partials/sidebar.php'; ?>
    <main class="content">
        <header style="margin-bottom: 2rem;">
            <h1 style="color: var(--color-primary);">Hava Durumu</h1>
            <p>Ekranda gösterilecek hava bilgilerini güncelle.</p>
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
            <form method="post" class="form-grid">
                <div>
                    <label for="city">Şehir</label>
                    <input type="text" id="city" name="city" value="<?php echo htmlspecialchars($current['city'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div>
                    <label for="temperature">Sıcaklık (°C)</label>
                    <input type="number" step="0.1" id="temperature" name="temperature" value="<?php echo htmlspecialchars($current['temperature'] ?? '0', ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div>
                    <label for="feels_like">Hissedilen (°C)</label>
                    <input type="number" step="0.1" id="feels_like" name="feels_like" value="<?php echo htmlspecialchars($current['feels_like'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div>
                    <label for="humidity">Nem (%)</label>
                    <input type="number" id="humidity" name="humidity" value="<?php echo htmlspecialchars($current['humidity'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div>
                    <label for="wind_speed">Rüzgar (km/sa)</label>
                    <input type="number" step="0.1" id="wind_speed" name="wind_speed" value="<?php echo htmlspecialchars($current['wind_speed'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div>
                    <label for="condition">Durum</label>
                    <input type="text" id="condition" name="condition" value="<?php echo htmlspecialchars($current['condition'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div>
                    <label for="icon">İkon (emoji)</label>
                    <input type="text" id="icon" name="icon" maxlength="4" value="<?php echo htmlspecialchars($current['icon'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div style="grid-column: 1 / -1;">
                    <button type="submit" class="button button-primary">Kaydet</button>
                </div>
            </form>
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
