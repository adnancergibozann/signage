<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/signage.php';
require __DIR__ . '/../includes/status.php';

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
    $duration = isset($_POST['duration']) ? (int) $_POST['duration'] : null;
    $note = trim($_POST['note'] ?? '');
    $endsAtInput = $_POST['ends_at'] ?? '';
    $endsAt = null;
    if ($endsAtInput !== '') {
        $parsed = parse_datetime_local($endsAtInput);
        if ($parsed) {
            $endsAt = new DateTimeImmutable($parsed);
        }
    }
    if (!$endsAt && $duration && in_array($status, ['lunch', 'leave'], true)) {
        $endsAt = (new DateTimeImmutable())->modify("+{$duration} minutes");
    }
    try {
        update_manager_status($pdo, $managerId, $status, $endsAt, $note ?: null, $duration);
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
                    <select name="status">
                        <?php foreach (['available','unavailable','meeting','lunch','leave'] as $statusOption): ?>
                            <option value="<?= $statusOption ?>"<?= $manager['status'] === $statusOption ? ' selected' : '' ?>><?= htmlspecialchars(map_status_label($statusOption)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Not</label>
                    <input type="text" name="note" value="<?= htmlspecialchars($manager['note'] ?? '') ?>">
                </div>
                <div>
                    <label>Toplantı/İzin Süresi (dk)</label>
                    <input type="number" name="duration" min="5" step="5" placeholder="ör. 30">
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
