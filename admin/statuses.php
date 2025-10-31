<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/signage.php';
require_once __DIR__ . '/../includes/status.php';

require_login();
require_role('super_admin');

$pageTitle = 'Durum Yönetimi';
$activePage = 'statuses';
$pdo = get_pdo();
$settings = load_all_settings();
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $managerId = (int) ($_POST['manager_id'] ?? 0);
    $status = $_POST['status'] ?? 'available';
    $duration = isset($_POST['duration']) ? max(0, (int) $_POST['duration']) : null;
    $note = trim($_POST['note'] ?? '');
    $endsAtInput = $_POST['ends_at'] ?? '';
    $endsAt = null;
    if ($endsAtInput !== '') {
        $parsed = parse_datetime_local($endsAtInput);
        if ($parsed) {
            $endsAt = new DateTimeImmutable($parsed);
        }
    }
    try {
        $userStmt = $pdo->prepare('SELECT full_name, role FROM users WHERE id = :id');
        $userStmt->execute(['id' => $managerId]);
        $managerRow = $userStmt->fetch();
        if (!$managerRow || !is_manager_role($managerRow['role'])) {
            throw new RuntimeException('Geçersiz yönetici seçimi.');
        }
        $availableStatuses = status_options_for_role($managerRow['role']);
        if (!in_array($status, $availableStatuses, true)) {
            throw new RuntimeException('Bu rol için desteklenmeyen durum seçildi.');
        }
        if ($duration !== null && $duration <= 0) {
            $duration = null;
        }
        update_manager_status($pdo, $managerId, $status, $endsAt, $note ?: null, $duration);
        record_syslog('status.override', sprintf('%s için durum %s olarak güncellendi.', $managerRow['full_name'], map_status_label($status)), null, [
            'targetUserId' => $managerId,
            'status' => $status,
            'durationMinutes' => $duration,
        ]);
        $message = 'Durum güncellendi.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$managers = fetch_managers_with_status($pdo, new DateTimeImmutable(), $settings);

include __DIR__ . '/partials/header.php';
?>
<?php if ($message): ?><div class="alert success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<section class="card">
    <h3>Satınalma Müdürleri</h3>
    <?php foreach ($managers as $manager): ?>
        <form method="post" class="card" style="background: rgba(16,21,44,0.75);">
            <input type="hidden" name="manager_id" value="<?= (int) $manager['id'] ?>">
            <h4><?= htmlspecialchars($manager['name']) ?> <small>(<?= htmlspecialchars($manager['statusLabel']) ?>)</small></h4>
            <div class="form-grid">
                <div>
                    <label>Durum</label>
                    <?php $options = status_options_for_role($manager['role']); ?>
                    <select name="status">
                        <?php foreach ($options as $statusOption): ?>
                            <option value="<?= htmlspecialchars($statusOption) ?>"<?= $manager['status'] === $statusOption ? ' selected' : '' ?>><?= htmlspecialchars(map_status_label($statusOption)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Not</label>
                    <input type="text" name="note" value="<?= htmlspecialchars($manager['note'] ?? '') ?>">
                </div>
                <div>
                    <label>Süre (dk)</label>
                    <?php $managerDuration = $manager['remainingSeconds'] !== null ? (int) ceil($manager['remainingSeconds'] / 60) : null; ?>
                    <input type="number" name="duration" min="0" step="5" placeholder="ör. 30" value="<?= $managerDuration ? htmlspecialchars((string) $managerDuration) : '' ?>">
                </div>
                <div>
                    <label>Bitiş Tarihi-Saati</label>
                    <input type="datetime-local" name="ends_at" value="<?= $manager['endsAt'] ? (new DateTime($manager['endsAt']))->format('Y-m-d\TH:i') : '' ?>">
                </div>
            </div>
            <div class="actions">
                <button class="button" type="submit">Kaydet</button>
            </div>
        </form>
    <?php endforeach; ?>
</section>
<?php
include __DIR__ . '/partials/footer.php';
