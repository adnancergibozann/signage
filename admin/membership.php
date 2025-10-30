<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/license.php';

require_login();

$activePage = 'membership';
$user = current_user();
$isSuperAdmin = is_super_admin($user);
$license = get_license_status();
$settings = $license['settings'] ?? [];

$formatDate = static function (?string $value, string $format = 'd.m.Y') {
    if (!$value) {
        return '-';
    }

    try {
        $date = new DateTimeImmutable($value);
        return $date->format($format);
    } catch (Throwable $e) {
        return $value;
    }
};

$licenseTypeLabel = ($license['license_type'] ?? 'monthly') === 'yearly' ? 'Yıllık Lisans' : 'Aylık Lisans';
$startLabel = $formatDate($license['start_date'] ?? null);
$endLabel = $formatDate($license['end_date'] ?? null);
$updatedLabel = $formatDate($license['updated_at'] ?? null, 'd.m.Y H:i');
$remainingText = $license['remaining_text'] ?? 'Süre doldu';

$statusClass = 'badge-warning';
if (($license['status_code'] ?? '') === 'active') {
    $statusClass = 'badge-success';
} elseif (($license['status_code'] ?? '') === 'expired') {
    $statusClass = 'badge-danger';
}

$notes = trim((string) ($settings['notes'] ?? ''));
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Üyelik Bilgileri | Signage</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/styles.css'), ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body>
<div class="admin-layout">
    <?php include __DIR__ . '/partials/sidebar.php'; ?>
    <main class="content">
        <header style="margin-bottom: 2rem;">
            <h1 style="color: var(--color-primary);">Üyelik Bilgileri</h1>
            <p>Sistemin lisans süresi ve üyelik detaylarına buradan ulaşabilirsin.</p>
        </header>

        <section class="card" style="margin-bottom: 2rem;">
            <div class="flex" style="justify-content: space-between; align-items: flex-start; gap: 1rem;">
                <div>
                    <h2>Lisans Durumu</h2>
                    <p>Kurumunun geçerli lisans planı ve süre bilgileri.</p>
                </div>
                <span class="badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($license['status_label'] ?? 'Bilinmiyor', ENT_QUOTES, 'UTF-8'); ?></span>
            </div>

            <dl class="data-grid">
                <div>
                    <dt>Üyelik Tipi</dt>
                    <dd><?php echo htmlspecialchars($licenseTypeLabel, ENT_QUOTES, 'UTF-8'); ?></dd>
                </div>
                <div>
                    <dt>Başlangıç Tarihi</dt>
                    <dd><?php echo htmlspecialchars($startLabel, ENT_QUOTES, 'UTF-8'); ?></dd>
                </div>
                <div>
                    <dt>Bitiş Tarihi</dt>
                    <dd><?php echo htmlspecialchars($endLabel, ENT_QUOTES, 'UTF-8'); ?></dd>
                </div>
                <div>
                    <dt>Kalan Süre</dt>
                    <dd><?php echo htmlspecialchars($remainingText, ENT_QUOTES, 'UTF-8'); ?></dd>
                </div>
                <div>
                    <dt>Toplam Gün</dt>
                    <dd><?php echo htmlspecialchars((string) ($license['total_days'] ?? 0), ENT_QUOTES, 'UTF-8'); ?></dd>
                </div>
                <div>
                    <dt>Son Güncelleme</dt>
                    <dd><?php echo htmlspecialchars($updatedLabel, ENT_QUOTES, 'UTF-8'); ?></dd>
                </div>
            </dl>

            <?php if ($notes !== ''): ?>
                <div class="alert" style="margin-top: 1rem; background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.08);">
                    <strong>Not:</strong> <?php echo nl2br(htmlspecialchars($notes, ENT_QUOTES, 'UTF-8')); ?>
                </div>
            <?php endif; ?>

            <?php if ($isSuperAdmin): ?>
                <div style="margin-top: 1.5rem;">
                    <a class="button button-primary" href="<?php echo htmlspecialchars(route_url('admin/license.php'), ENT_QUOTES, 'UTF-8'); ?>">Lisans Ayarlarına Git</a>
                </div>
            <?php endif; ?>
        </section>

        <?php if (($license['status_code'] ?? '') !== 'active'): ?>
            <section class="card" style="border: 1px solid rgba(231, 76, 60, 0.35); background: rgba(231, 76, 60, 0.08);">
                <h2>Dikkat!</h2>
                <p>Lisans aktif değil. Signage ekranlarının yeniden çalışması için lisans bilgilerini güncellemen gerekiyor.</p>
                <?php if ($isSuperAdmin): ?>
                    <p style="margin-top: 0.5rem;">Hemen lisans ayarları sayfasına giderek yeni bir dönem tanımlayabilirsin.</p>
                <?php else: ?>
                    <p style="margin-top: 0.5rem;">Lütfen süper yönetici ile iletişime geçerek lisansın güncellenmesini talep et.</p>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
