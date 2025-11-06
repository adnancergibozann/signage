<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

require_login();
require_role('super_admin');

$pageTitle = 'Sistem Ayarları';
$activePage = 'settings';
$pdo = get_pdo();
$message = null;
$error = null;

$currentSettings = load_all_settings();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $updatedKeys = [];
        set_setting('company_name', trim($_POST['company_name'] ?? 'Gapgross'));
        $updatedKeys[] = 'company_name';
        set_setting('theme_primary', $_POST['theme_primary'] ?? '#E3000B');
        $updatedKeys[] = 'theme_primary';
        set_setting('theme_secondary', $_POST['theme_secondary'] ?? '#17007A');
        $updatedKeys[] = 'theme_secondary';
        set_setting('signage_refresh_seconds', (string) (int) ($_POST['refresh_seconds'] ?? 5));
        $updatedKeys[] = 'signage_refresh_seconds';
        set_setting('time_format', $_POST['time_format'] ?? '24h');
        $updatedKeys[] = 'time_format';
        set_setting('ticker_speed', (string) (int) ($_POST['ticker_speed'] ?? 40));
        $updatedKeys[] = 'ticker_speed';

        $lunchStartInput = $_POST['lunch_notice_start'] ?? '';
        $lunchEndInput = $_POST['lunch_notice_end'] ?? '';
        $lunchStart = $lunchStartInput === '' ? null : extract_time_component($lunchStartInput);
        $lunchEnd = $lunchEndInput === '' ? null : extract_time_component($lunchEndInput);
        if ($lunchStartInput !== '' && !$lunchStart) {
            throw new RuntimeException('Yemek uyarısı başlangıç saati geçersiz.');
        }
        if ($lunchEndInput !== '' && !$lunchEnd) {
            throw new RuntimeException('Yemek uyarısı bitiş saati geçersiz.');
        }
        set_setting('lunch_notice_start', $lunchStart ?? '');
        set_setting('lunch_notice_end', $lunchEnd ?? '');
        $updatedKeys[] = 'lunch_notice_start';
        $updatedKeys[] = 'lunch_notice_end';

        if (!empty($_FILES['logo']['name'])) {
            $file = upload_file(
                $_FILES['logo'],
                public_path('uploads/branding'),
                ['image/jpeg','image/png','image/gif','image/webp','image/svg+xml'],
                ['jpg','jpeg','png','gif','webp','svg']
            );
            if (!$file) {
                throw new RuntimeException('Logo yüklenemedi.');
            }
            if (!empty($currentSettings['logo_path'])) {
                @unlink(public_path('uploads/branding/' . $currentSettings['logo_path']));
            }
            set_setting('logo_path', $file);
            $updatedKeys[] = 'logo_path';
        }

        if (!empty($_FILES['organigram']['name'])) {
            $file = upload_file(
                $_FILES['organigram'],
                public_path('uploads/branding'),
                ['image/jpeg','image/png','image/gif','image/webp','image/svg+xml'],
                ['jpg','jpeg','png','gif','webp','svg']
            );
            if (!$file) {
                throw new RuntimeException('Organigram yüklenemedi.');
            }
            if (!empty($currentSettings['organigram_path'])) {
                @unlink(public_path('uploads/branding/' . $currentSettings['organigram_path']));
            }
            set_setting('organigram_path', $file);
            $updatedKeys[] = 'organigram_path';
        }

        $message = 'Ayarlar güncellendi.';
        record_syslog('settings.update', 'Sistem ayarları güncellendi.', null, [
            'updatedKeys' => array_values(array_unique($updatedKeys)),
        ]);
        $currentSettings = load_all_settings();
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

include __DIR__ . '/partials/header.php';
?>
<?php if ($message): ?><div class="alert success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<section class="card">
    <h3>Genel Ayarlar</h3>
    <form method="post" enctype="multipart/form-data">
        <div class="form-grid">
            <div>
                <label for="company_name">Şirket Adı</label>
                <input type="text" id="company_name" name="company_name" value="<?= htmlspecialchars($currentSettings['company_name'] ?? 'Gapgross') ?>">
            </div>
            <div>
                <label for="theme_primary">Birincil Renk</label>
                <input type="color" id="theme_primary" name="theme_primary" value="<?= htmlspecialchars($currentSettings['theme_primary'] ?? '#E3000B') ?>">
            </div>
            <div>
                <label for="theme_secondary">İkincil Renk</label>
                <input type="color" id="theme_secondary" name="theme_secondary" value="<?= htmlspecialchars($currentSettings['theme_secondary'] ?? '#17007A') ?>">
            </div>
            <div>
                <label for="refresh_seconds">Signage Yenileme (sn)</label>
                <input type="number" id="refresh_seconds" name="refresh_seconds" value="<?= (int) ($currentSettings['signage_refresh_seconds'] ?? 5) ?>" min="3">
            </div>
            <div>
                <label for="ticker_speed">Ticker Hızı (sn)</label>
                <input type="number" id="ticker_speed" name="ticker_speed" value="<?= (int) ($currentSettings['ticker_speed'] ?? 40) ?>" min="10">
            </div>
            <div>
                <label for="time_format">Saat Formatı</label>
                <select id="time_format" name="time_format">
                    <option value="24h"<?= ($currentSettings['time_format'] ?? '24h') === '24h' ? ' selected' : '' ?>>24 Saat</option>
                    <option value="12h"<?= ($currentSettings['time_format'] ?? '') === '12h' ? ' selected' : '' ?>>12 Saat</option>
                </select>
            </div>
            <div>
                <label for="lunch_notice_start">Yemek Uyarısı Başlangıç Saati</label>
                <input type="time" id="lunch_notice_start" name="lunch_notice_start" value="<?= htmlspecialchars(extract_time_component($currentSettings['lunch_notice_start'] ?? '') ?? '') ?>">
            </div>
            <div>
                <label for="lunch_notice_end">Yemek Uyarısı Bitiş Saati</label>
                <input type="time" id="lunch_notice_end" name="lunch_notice_end" value="<?= htmlspecialchars(extract_time_component($currentSettings['lunch_notice_end'] ?? '') ?? '') ?>">
            </div>
            <div>
                <label for="logo">Logo</label>
                <input type="file" id="logo" name="logo" accept="image/*">
                <?php if (!empty($currentSettings['logo_path'])): ?>
                    <p><a class="button secondary" href="<?= asset_url('public/uploads/branding/' . $currentSettings['logo_path']) ?>" target="_blank">Mevcut Logoyu Gör</a></p>
                <?php endif; ?>
            </div>
            <div>
                <label for="organigram">Yönetim Şeması Görseli</label>
                <input type="file" id="organigram" name="organigram" accept="image/*">
                <?php if (!empty($currentSettings['organigram_path'])): ?>
                    <p><a class="button secondary" href="<?= asset_url('public/uploads/branding/' . $currentSettings['organigram_path']) ?>" target="_blank">Mevcut Şemayı Gör</a></p>
                <?php endif; ?>
            </div>
        </div>
        <div class="actions">
            <button class="button" type="submit">Kaydet</button>
        </div>
    </form>
</section>
<?php
include __DIR__ . '/partials/footer.php';
