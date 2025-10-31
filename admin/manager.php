<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/signage.php';
require __DIR__ . '/../includes/status.php';

require_login();
require_role('manager');

$pageTitle = 'Durum Yönetimim';
$activePage = 'manager';
$pdo = get_pdo();
$user = current_user();
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {
            case 'set_status':
                $status = $_POST['status'] ?? 'available';
                $note = trim($_POST['note'] ?? '');
                update_manager_status($pdo, $user['id'], $status, null, $note ?: null);
                $message = 'Durum güncellendi.';
                break;
            case 'start_meeting':
                $duration = (int) ($_POST['duration'] ?? 0);
                if ($duration <= 0) {
                    throw new RuntimeException('Toplantı süresi giriniz.');
                }
                $note = trim($_POST['note'] ?? '');
                update_manager_status($pdo, $user['id'], 'meeting', null, $note ?: null, $duration);
                $message = 'Toplantı başlatıldı.';
                break;
            case 'end_meeting':
                $note = trim($_POST['note'] ?? '');
                update_manager_status($pdo, $user['id'], 'available', null, $note ?: null);
                $message = 'Toplantı sonlandırıldı.';
                break;
            case 'start_lunch':
                $duration = (int) ($_POST['duration'] ?? 30);
                $note = trim($_POST['note'] ?? 'Yemek molasında');
                $endsAt = (new DateTimeImmutable())->modify("+{$duration} minutes");
                update_manager_status($pdo, $user['id'], 'lunch', $endsAt, $note ?: null);
                $message = 'Yemek molası başlatıldı.';
                break;
            case 'end_lunch':
                $note = trim($_POST['note'] ?? '');
                update_manager_status($pdo, $user['id'], 'available', null, $note ?: null);
                $message = 'Yemek molası sonlandırıldı.';
                break;
            case 'set_leave':
                $return = parse_datetime_local($_POST['return_at'] ?? '');
                if (!$return) {
                    throw new RuntimeException('Dönüş tarihini giriniz.');
                }
                $note = trim($_POST['note'] ?? 'İzinli');
                update_manager_status($pdo, $user['id'], 'leave', new DateTimeImmutable($return), $note ?: null);
                $message = 'İzin durumu güncellendi.';
                break;
            case 'return_leave':
                $note = trim($_POST['note'] ?? '');
                update_manager_status($pdo, $user['id'], 'available', null, $note ?: null);
                $message = 'İzin sonlandırıldı.';
                break;
            case 'update_note':
                $note = trim($_POST['note'] ?? '');
                update_manager_note($pdo, $user['id'], $note ?: null);
                $message = 'Not güncellendi.';
                break;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$managerData = null;
foreach (fetch_managers_with_status($pdo, new DateTimeImmutable()) as $manager) {
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
    <?php if ($managerData['status'] === 'meeting' && $managerData['remainingSeconds'] !== null): ?>
        <p><strong>Toplantı Bitiş:</strong> <?= format_duration((int) $managerData['remainingSeconds']) ?> sonra</p>
    <?php elseif ($managerData['endsAt']): ?>
        <p><strong>Durum Bitişi:</strong> <?= format_datetime($managerData['endsAt'], 'd.m.Y H:i') ?></p>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="card">
    <h3>Hızlı Durum</h3>
    <form method="post">
        <input type="hidden" name="action" value="set_status">
        <div class="form-grid">
            <div>
                <label>Durum</label>
                <select name="status">
                    <option value="available">Müsait</option>
                    <option value="unavailable">Müsait Değil</option>
                </select>
            </div>
            <div>
                <label>Not</label>
                <input type="text" name="note" placeholder="Kısa not">
            </div>
        </div>
        <div class="actions">
            <button class="button" type="submit">Güncelle</button>
        </div>
    </form>
</section>

<section class="card">
    <h3>Toplantı</h3>
    <form method="post">
        <input type="hidden" name="action" value="start_meeting">
        <div class="form-grid">
            <div>
                <label>Süre (dk)</label>
                <input type="number" name="duration" min="5" step="5" value="30">
            </div>
            <div>
                <label>Not</label>
                <input type="text" name="note" placeholder="Toplantı notu">
            </div>
        </div>
        <div class="actions">
            <button class="button" type="submit">Toplantıyı Başlat</button>
        </div>
    </form>
    <form method="post" style="margin-top:16px;">
        <input type="hidden" name="action" value="end_meeting">
        <input type="text" name="note" placeholder="Toplantı sonucu notu">
        <div class="actions">
            <button class="button secondary" type="submit">Toplantıyı Bitir</button>
        </div>
    </form>
</section>

<section class="card">
    <h3>Yemek Molası</h3>
    <form method="post">
        <input type="hidden" name="action" value="start_lunch">
        <div class="form-grid">
            <div>
                <label>Süre (dk)</label>
                <input type="number" name="duration" value="45" min="10" step="5">
            </div>
            <div>
                <label>Not</label>
                <input type="text" name="note" value="Yemek molasında">
            </div>
        </div>
        <div class="actions">
            <button class="button" type="submit">Başlat</button>
        </div>
    </form>
    <form method="post" style="margin-top:16px;">
        <input type="hidden" name="action" value="end_lunch">
        <input type="text" name="note" placeholder="Dönüş notu">
        <div class="actions">
            <button class="button secondary" type="submit">Molayı Bitir</button>
        </div>
    </form>
</section>

<section class="card">
    <h3>İzin Durumu</h3>
    <form method="post">
        <input type="hidden" name="action" value="set_leave">
        <div class="form-grid">
            <div>
                <label>Dönüş Tarihi</label>
                <input type="datetime-local" name="return_at" required>
            </div>
            <div>
                <label>Not</label>
                <input type="text" name="note" value="İzinli">
            </div>
        </div>
        <div class="actions">
            <button class="button" type="submit">İzin Başlat</button>
        </div>
    </form>
    <form method="post" style="margin-top:16px;">
        <input type="hidden" name="action" value="return_leave">
        <input type="text" name="note" placeholder="Dönüş notu">
        <div class="actions">
            <button class="button secondary" type="submit">İzinden Dön</button>
        </div>
    </form>
</section>

<section class="card">
    <h3>Serbest Not</h3>
    <form method="post">
        <input type="hidden" name="action" value="update_note">
        <textarea name="note" placeholder="Kısa durum notu"><?= htmlspecialchars($managerData['note'] ?? '') ?></textarea>
        <div class="actions">
            <button class="button" type="submit">Notu Güncelle</button>
        </div>
    </form>
</section>
<?php
include __DIR__ . '/partials/footer.php';
