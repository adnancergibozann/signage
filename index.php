<?php
require_once __DIR__ . '/includes/prayer.php';

$pdo = get_pdo();
$settings = get_prayer_settings($pdo);
$timezone = new DateTimeZone($settings['timezone'] ?? 'UTC');
$now = new DateTimeImmutable('now', $timezone);
$dateLabel = format_turkish_date($now, $settings['timezone'] ?? 'UTC');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ezan Saati Ekranı</title>
    <link rel="stylesheet" href="/assets/prayer.css">
</head>
<body class="kiosk-body">
<div class="kiosk-app" data-endpoint="/public/api/prayer_times.php">
    <header class="kiosk-header">
        <div class="location">
            <h1><?php echo htmlspecialchars($settings['city'] . ', ' . $settings['country'], ENT_QUOTES, 'UTF-8'); ?></h1>
            <?php if (!empty($settings['district'])): ?>
                <p><?php echo htmlspecialchars($settings['district'], ENT_QUOTES, 'UTF-8'); ?> ilçesi</p>
            <?php endif; ?>
        </div>
        <div class="clock" data-role="clock">
            <div id="current-time" class="clock-time">--:--</div>
            <div id="current-date" class="clock-date"><?php echo htmlspecialchars($dateLabel, ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
    </header>

    <main class="kiosk-main">
        <section class="card prayer-table">
            <div class="card-header">
                <h2>Bugünkü Namaz Vakitleri</h2>
                <span class="timezone" data-role="timezone"><?php echo htmlspecialchars($settings['timezone'], ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <table>
                <thead>
                <tr>
                    <th>Vakit</th>
                    <th>Saat</th>
                    <th>Durum</th>
                </tr>
                </thead>
                <tbody id="prayer-rows">
                <tr>
                    <td colspan="3" class="placeholder">Veriler yükleniyor...</td>
                </tr>
                </tbody>
            </table>
        </section>

        <section class="card next-event" data-role="next-event">
            <h2>Sıradaki Vakit</h2>
            <div class="event-label" data-role="next-label">--</div>
            <div class="event-time" data-role="next-time">--:--</div>
            <div class="countdown" data-role="countdown">--:--:--</div>
        </section>

        <section class="card upcoming" data-role="upcoming-list">
            <h2>Yaklaşan Vakitler</h2>
            <ul id="upcoming-events">
                <li class="placeholder">Veriler yükleniyor...</li>
            </ul>
        </section>
    </main>

    <footer class="kiosk-footer">
        <p>Kiosk modu için tarayıcınızı tam ekrana alın. Sistem ezan vakti geldiğinde tanımlı ses dosyasını otomatik olarak çalar.</p>
    </footer>
</div>
<div id="audio-container" hidden></div>
<script>
    window.PRAYER_KIOSK = {
        endpoint: document.querySelector('.kiosk-app')?.dataset.endpoint || '/public/api/prayer_times.php',
        initialTimezone: <?php echo json_encode($settings['timezone'], JSON_UNESCAPED_UNICODE); ?>,
    };
</script>
<script src="/assets/prayer.js" defer></script>
</body>
</html>
