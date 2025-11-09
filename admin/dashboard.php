<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/prayer.php';

require_login();

$activePage = 'prayer';
$pdo = get_pdo();
$errors = [];
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'save_settings':
                $settings = save_prayer_settings($_POST, $pdo);
                $added = refresh_prayer_times($pdo);
                $messages[] = 'Ayarlar kaydedildi ve namaz vakitleri güncellendi (' . $added . ' kayıt).';
                break;
            case 'refresh_times':
                $start = null;
                $end = null;
                $days = (int) ($_POST['range_days'] ?? 14);
                $days = max(1, min(60, $days));
                $settings = get_prayer_settings($pdo);
                $timezone = new DateTimeZone($settings['timezone'] ?? 'UTC');
                $today = new DateTimeImmutable('now', $timezone);
                $start = $today->setTime(0, 0);
                $end = $start->add(new DateInterval('P' . $days . 'D'));
                $count = refresh_prayer_times($pdo, $start, $end);
                $messages[] = 'Namaz vakitleri güncellendi (' . $count . ' kayıt).';
                break;
            case 'upload_audio':
                $key = $_POST['prayer_key'] ?? '';
                update_prayer_audio($key, $_FILES['audio'] ?? [] , $pdo);
                $messages[] = 'Ses dosyası güncellendi.';
                break;
            case 'delete_audio':
                $key = $_POST['prayer_key'] ?? '';
                delete_prayer_audio($key, $pdo);
                $messages[] = 'Ses dosyası kaldırıldı.';
                break;
            default:
                $errors[] = 'Bilinmeyen işlem.';
                break;
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$settings = get_prayer_settings($pdo);
$audioProfiles = get_prayer_audio_profiles($pdo);
$timezone = new DateTimeZone($settings['timezone'] ?? 'UTC');
$today = new DateTimeImmutable('now', $timezone);
$schedule = get_prayer_schedule($today->sub(new DateInterval('P1D')), $today->add(new DateInterval('P3D')), $pdo);

function esc_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Namaz Ayarları | Ezan Saati Paneli</title>
    <link rel="stylesheet" href="/assets/styles.css">
</head>
<body class="admin-body">
<div class="admin-layout">
    <?php include __DIR__ . '/partials/sidebar.php'; ?>
    <main class="admin-content">
        <header class="admin-header">
            <h1>Namaz Vakti Yönetimi</h1>
            <p>Konumunuzu seçin, ezan vakitlerini güncelleyin ve her vakit için farklı ses dosyaları tanımlayın.</p>
        </header>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-success"><?php echo esc_html($message); ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?php echo esc_html($error); ?></div>
        <?php endforeach; ?>

        <section class="card">
            <h2>Konum & Hesaplama Ayarları</h2>
            <form method="post" class="form-grid">
                <input type="hidden" name="action" value="save_settings">
                <div class="form-control">
                    <label for="country">Ülke</label>
                    <input type="text" id="country" name="country" value="<?php echo esc_html($settings['country']); ?>" required>
                </div>
                <div class="form-control">
                    <label for="city">İl</label>
                    <input type="text" id="city" name="city" value="<?php echo esc_html($settings['city']); ?>" required>
                </div>
                <div class="form-control">
                    <label for="district">İlçe (opsiyonel)</label>
                    <input type="text" id="district" name="district" value="<?php echo esc_html($settings['district'] ?? ''); ?>">
                </div>
                <div class="form-control">
                    <label for="calculation_method">Hesaplama Metodu</label>
                    <select id="calculation_method" name="calculation_method">
                        <?php foreach (ALADHAN_METHODS as $methodKey => $label): ?>
                            <option value="<?php echo $methodKey; ?>" <?php echo (int) $settings['calculation_method'] === (int) $methodKey ? 'selected' : ''; ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-control">
                    <label for="madhab">Mezhep</label>
                    <select id="madhab" name="madhab">
                        <option value="hanafi" <?php echo ($settings['madhab'] ?? '') === 'hanafi' ? 'selected' : ''; ?>>Hanefi</option>
                        <option value="shafi" <?php echo ($settings['madhab'] ?? '') === 'shafi' ? 'selected' : ''; ?>>Şafi</option>
                    </select>
                </div>
                <div class="form-control">
                    <label for="jumuah_offset_minutes">Cuma selası ezandan kaç dakika önce?</label>
                    <input type="number" min="0" max="180" id="jumuah_offset_minutes" name="jumuah_offset_minutes" value="<?php echo (int) $settings['jumuah_offset_minutes']; ?>">
                </div>
                <div class="form-control">
                    <label for="auto_refresh_days">Otomatik yenileme süresi (gün)</label>
                    <input type="number" min="1" max="60" id="auto_refresh_days" name="auto_refresh_days" value="<?php echo (int) $settings['auto_refresh_days']; ?>">
                </div>
                <div class="form-actions">
                    <button type="submit" class="button button-primary">Ayarları Kaydet ve Güncelle</button>
                </div>
            </form>
        </section>

        <section class="card">
            <h2>Namaz Vakti Tablosu</h2>
            <p>Şu anki saat dilimi: <strong><?php echo esc_html($settings['timezone']); ?></strong></p>
            <div class="table-responsive">
                <table>
                    <thead>
                    <tr>
                        <th>Tarih</th>
                        <th>İmsak</th>
                        <th>Öğle</th>
                        <th>İkindi</th>
                        <th>Akşam</th>
                        <th>Yatsı</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php
                    $dates = array_keys($schedule);
                    sort($dates);
                    foreach ($dates as $dateKey):
                        $day = $schedule[$dateKey];
                        ?>
                        <tr>
                            <td><?php echo esc_html($dateKey); ?></td>
                            <?php foreach (['fajr','dhuhr','asr','maghrib','isha'] as $key):
                                $row = null;
                                foreach ($day as $item) {
                                    if ($item['key'] === $key) {
                                        $row = $item;
                                        break;
                                    }
                                }
                                ?>
                                <td><?php echo $row ? esc_html($row['time_label']) : '<em>—</em>'; ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <form method="post" class="form-inline">
                <input type="hidden" name="action" value="refresh_times">
                <label for="range_days">Kaç günlük veri yenilensin?</label>
                <input type="number" min="1" max="60" id="range_days" name="range_days" value="14">
                <button type="submit" class="button">Namaz Vakitlerini Yenile</button>
            </form>
        </section>

        <section class="card" id="audio-profiles">
            <h2>Ezan Sesleri</h2>
            <p>Her namaz için farklı bir ses dosyası yükleyebilir veya mevcut dosyaları silebilirsiniz.</p>
            <div class="audio-grid">
                <?php foreach ($audioProfiles as $profile): ?>
                    <div class="audio-card">
                        <h3><?php echo esc_html($profile['label']); ?></h3>
                        <?php if ($profile['file_url']): ?>
                            <p class="audio-meta">Yüklendi: <?php echo esc_html($profile['file_name']); ?></p>
                            <audio controls preload="none" src="<?php echo esc_html($profile['file_url']); ?>"></audio>
                            <form method="post" class="form-inline">
                                <input type="hidden" name="action" value="delete_audio">
                                <input type="hidden" name="prayer_key" value="<?php echo esc_html($profile['key']); ?>">
                                <button type="submit" class="button button-secondary">Ses Dosyasını Sil</button>
                            </form>
                        <?php else: ?>
                            <p class="audio-meta">Henüz bir ses dosyası yüklenmedi.</p>
                        <?php endif; ?>
                        <form method="post" enctype="multipart/form-data" class="form-grid">
                            <input type="hidden" name="action" value="upload_audio">
                            <input type="hidden" name="prayer_key" value="<?php echo esc_html($profile['key']); ?>">
                            <label class="file-input">
                                <span>Ses dosyası seç (MP3, OGG, WAV)</span>
                                <input type="file" name="audio" accept="audio/*" required>
                            </label>
                            <button type="submit" class="button button-primary">Yükle</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
</div>
</body>
</html>
