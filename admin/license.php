<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/license.php';

require_super_admin();

$activePage = 'license';
$user = current_user();

$errors = [];
$successMessage = null;

$settings = get_license_settings();
$licenseStatus = get_license_status();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $licenseType = $_POST['license_type'] ?? ($settings['license_type'] ?? 'monthly');
    $startDateInput = trim($_POST['start_date'] ?? '');
    $endDateInput = trim($_POST['end_date'] ?? '');
    $isActiveInput = isset($_POST['is_active']) ? 1 : 0;
    $notesInput = trim($_POST['notes'] ?? '');

    if (!in_array($licenseType, ['monthly', 'yearly'], true)) {
        $errors[] = 'Lisans tipi geçerli değil.';
        $licenseType = $settings['license_type'] ?? 'monthly';
    }

    $startDate = null;
    if ($startDateInput === '') {
        $errors[] = 'Başlangıç tarihi zorunludur.';
    } else {
        $startDate = DateTimeImmutable::createFromFormat('Y-m-d', $startDateInput);
        if (!$startDate) {
            $errors[] = 'Başlangıç tarihi formatı geçerli değil.';
        }
    }

    $endDate = null;
    if ($endDateInput === '') {
        $errors[] = 'Bitiş tarihi zorunludur.';
    } else {
        $endDate = DateTimeImmutable::createFromFormat('Y-m-d', $endDateInput);
        if (!$endDate) {
            $errors[] = 'Bitiş tarihi formatı geçerli değil.';
        }
    }

    if ($startDate && $endDate && $endDate < $startDate) {
        $errors[] = 'Bitiş tarihi başlangıç tarihinden önce olamaz.';
    }

    if (!$errors && $startDate && $endDate) {
        save_license_settings([
            'license_type' => $licenseType,
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'is_active' => $isActiveInput ? 1 : 0,
            'notes' => $notesInput !== '' ? $notesInput : null,
        ]);

        $successMessage = 'Lisans bilgileri başarıyla güncellendi.';
        $settings = get_license_settings();
        $licenseStatus = get_license_status();
    } else {
        $settings['license_type'] = $licenseType;
        $settings['start_date'] = $startDateInput;
        $settings['end_date'] = $endDateInput;
        $settings['is_active'] = $isActiveInput;
        $settings['notes'] = $notesInput;
    }
}

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

$statusClass = 'badge-warning';
if (($licenseStatus['status_code'] ?? '') === 'active') {
    $statusClass = 'badge-success';
} elseif (($licenseStatus['status_code'] ?? '') === 'expired') {
    $statusClass = 'badge-danger';
}

$licenseTypeLabel = ($licenseStatus['license_type'] ?? 'monthly') === 'yearly' ? 'Yıllık Lisans' : 'Aylık Lisans';

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Süper Admin | Lisans Yönetimi</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/styles.css'), ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body>
<div class="admin-layout">
    <?php include __DIR__ . '/partials/sidebar.php'; ?>
    <main class="content">
        <header style="margin-bottom: 2rem;">
            <h1 style="color: var(--color-primary);">Lisans Yönetimi</h1>
            <p>Kurumun lisans süresini aylık ya da yıllık olarak yapılandır.</p>
        </header>

        <?php if ($successMessage): ?>
            <div class="alert" style="background: rgba(46, 204, 113, 0.15); border: 1px solid rgba(46, 204, 113, 0.4);">
                <?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="alert alert-error">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <section class="card" style="margin-bottom: 2rem;">
            <h2>Aktif Lisans Özeti</h2>
            <p>Sistemde kayıtlı mevcut lisans bilgileri.</p>
            <div class="flex" style="justify-content: space-between; align-items: center;">
                <span class="badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($licenseStatus['status_label'] ?? 'Bilinmiyor', ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="badge" style="background: rgba(255, 212, 0, 0.1); color: var(--color-primary);">
                    <?php echo htmlspecialchars($licenseTypeLabel, ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </div>
            <dl class="data-grid" style="margin-top: 1rem;">
                <div>
                    <dt>Başlangıç</dt>
                    <dd><?php echo htmlspecialchars($formatDate($licenseStatus['start_date'] ?? null), ENT_QUOTES, 'UTF-8'); ?></dd>
                </div>
                <div>
                    <dt>Bitiş</dt>
                    <dd><?php echo htmlspecialchars($formatDate($licenseStatus['end_date'] ?? null), ENT_QUOTES, 'UTF-8'); ?></dd>
                </div>
                <div>
                    <dt>Kalan Süre</dt>
                    <dd><?php echo htmlspecialchars($licenseStatus['remaining_text'] ?? 'Süre doldu', ENT_QUOTES, 'UTF-8'); ?></dd>
                </div>
                <div>
                    <dt>Son Güncelleme</dt>
                    <dd><?php echo htmlspecialchars($formatDate($licenseStatus['updated_at'] ?? null, 'd.m.Y H:i'), ENT_QUOTES, 'UTF-8'); ?></dd>
                </div>
            </dl>
        </section>

        <section class="card">
            <h2>Lisans Ayarları</h2>
            <p>Yeni dönem tanımlamak için formu doldur ve kaydet.</p>
            <form method="post" class="form-grid" autocomplete="off">
                <div>
                    <label for="license_type">Lisans Tipi</label>
                    <select id="license_type" name="license_type">
                        <option value="monthly" <?php echo (($settings['license_type'] ?? '') === 'monthly') ? 'selected' : ''; ?>>Aylık</option>
                        <option value="yearly" <?php echo (($settings['license_type'] ?? '') === 'yearly') ? 'selected' : ''; ?>>Yıllık</option>
                    </select>
                </div>
                <div>
                    <label for="start_date">Başlangıç Tarihi</label>
                    <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($settings['start_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div>
                    <label for="end_date">Bitiş Tarihi</label>
                    <div class="input-with-action">
                        <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($settings['end_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                        <button type="button" class="button button-secondary" data-role="auto-end">Otomatik Hesapla</button>
                    </div>
                    <small>Başlangıç tarihine göre otomatik hesaplamak için butona bas.</small>
                </div>
                <div>
                    <label for="notes">Not</label>
                    <textarea id="notes" name="notes" rows="3" placeholder="Örn: 2024 yılı yenileme faturası kesildi."><?php echo htmlspecialchars($settings['notes'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
                <div>
                    <label class="checkbox-inline">
                        <input type="checkbox" name="is_active" value="1" <?php echo !empty($settings['is_active']) ? 'checked' : ''; ?>>
                        Lisansı hemen aktif et
                    </label>
                </div>
                <div style="grid-column: 1 / -1;">
                    <button type="submit" class="button button-primary">Lisansı Kaydet</button>
                </div>
            </form>
        </section>
    </main>
</div>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const startInput = document.getElementById('start_date');
        const endInput = document.getElementById('end_date');
        const typeInput = document.getElementById('license_type');
        const autoButton = document.querySelector('[data-role="auto-end"]');

        const computeEndDate = () => {
            if (!startInput || !endInput || !typeInput) {
                return;
            }

            const startValue = startInput.value;
            if (!startValue) {
                return;
            }

            const startDate = new Date(`${startValue}T00:00:00`);
            if (Number.isNaN(startDate.getTime())) {
                return;
            }

            const type = typeInput.value;
            const endDate = new Date(startDate);

            if (type === 'yearly') {
                endDate.setFullYear(endDate.getFullYear() + 1);
            } else {
                endDate.setMonth(endDate.getMonth() + 1);
            }

            endDate.setDate(endDate.getDate() - 1);

            const year = endDate.getFullYear();
            const month = String(endDate.getMonth() + 1).padStart(2, '0');
            const day = String(endDate.getDate()).padStart(2, '0');
            endInput.value = `${year}-${month}-${day}`;
        };

        if (autoButton) {
            autoButton.addEventListener('click', (event) => {
                event.preventDefault();
                computeEndDate();
            });
        }

        if (startInput) {
            startInput.addEventListener('change', () => {
                if (!endInput.value) {
                    computeEndDate();
                }
            });
        }

        if (typeInput) {
            typeInput.addEventListener('change', () => {
                computeEndDate();
            });
        }
    });
</script>
</body>
</html>
