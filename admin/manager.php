<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/signage.php';
require_once __DIR__ . '/../includes/status.php';

require_login();
require_role(manager_role_keys());

$pageTitle = 'Durum Yönetimim';
$activePage = 'manager';
$pdo = get_pdo();
$settings = load_all_settings();
$user = current_user();
$statusOptions = status_options_for_role($user['role']);
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $status = $_POST['status'] ?? 'available';
        if (!in_array($status, $statusOptions, true)) {
            throw new RuntimeException('Geçersiz durum seçildi.');
        }
        $durationInput = isset($_POST['duration']) ? (int) $_POST['duration'] : 0;
        $duration = $durationInput > 0 ? $durationInput : null;
        $note = trim($_POST['note'] ?? '');
        update_manager_status($pdo, $user['id'], $status, null, $note ?: null, $duration);
        record_syslog('status.self_update', sprintf('%s durumu %s olarak güncellendi.', $user['full_name'], map_status_label($status)), (int) $user['id'], [
            'status' => $status,
            'durationMinutes' => $duration,
        ]);
        $message = 'Durum güncellendi.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$managerData = null;
foreach (fetch_managers_with_status($pdo, new DateTimeImmutable(), $settings) as $manager) {
    if ($manager['id'] === $user['id']) {
        $managerData = $manager;
        break;
    }
}

include __DIR__ . '/partials/header.php';
?>
<?php if ($message): ?><div class="alert success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if ($managerData): ?>
<section class="card">
    <h3>Mevcut Durum</h3>
    <p><strong>Durum:</strong> <?= htmlspecialchars($managerData['statusLabel']) ?></p>
    <p><strong>Not:</strong> <?= htmlspecialchars($managerData['note'] ?? '-') ?></p>
    <?php if ($managerData['remainingSeconds'] !== null): ?>
        <p><strong>Geri Sayım:</strong> <?= format_duration((int) $managerData['remainingSeconds']) ?> sonra</p>
    <?php elseif ($managerData['endsAt']): ?>
        <p><strong>Durum Bitişi:</strong> <?= format_datetime($managerData['endsAt'], 'd.m.Y H:i') ?></p>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="card">
    <h3>Durumumu Güncelle</h3>
    <form method="post" autocomplete="off">
        <div class="form-grid">
            <div>
                <label>Durum Seçimi</label>
                <?php $currentStatus = $managerData['status'] ?? ($statusOptions[0] ?? 'available'); ?>
                <select name="status" required>
                    <?php foreach ($statusOptions as $statusOption): ?>
                        <option value="<?= htmlspecialchars($statusOption) ?>"<?= $currentStatus === $statusOption ? ' selected' : '' ?>><?= htmlspecialchars(map_status_label($statusOption)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Süre (dk)</label>
                <?php $defaultDuration = isset($managerData['remainingSeconds']) && $managerData['remainingSeconds'] !== null
                    ? (int) ceil($managerData['remainingSeconds'] / 60)
                    : null; ?>
                <input type="number" name="duration" min="0" step="5" placeholder="Opsiyonel" value="<?= $defaultDuration ? htmlspecialchars((string) $defaultDuration) : '' ?>">
            </div>
            <div>
                <label>Not</label>
                <input type="text" name="note" value="<?= htmlspecialchars($managerData['note'] ?? '') ?>" placeholder="Kısa not">
            </div>
        </div>
        <p class="form-help">Süre girilirse durum belirtilen dakika sonunda otomatik olarak tamamlanır.</p>
        <div class="actions">
            <button class="button" type="submit">Durumu Kaydet</button>
        </div>
    </form>
</section>
<?php
include __DIR__ . '/partials/footer.php';
