<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

require_login();
require_role('super_admin');

$pageTitle = 'Duyuru Yönetimi';
$activePage = 'announcements';
$pdo = get_pdo();
$settings = load_all_settings();
$message = null;
$error = null;

$announcementFontSize = normalize_announcement_font_size((int) ($settings['announcement_font_size'] ?? 22));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create') {
            handle_create_announcement($pdo);
            $message = 'Duyuru oluşturuldu.';
        } elseif ($action === 'update') {
            handle_update_announcement($pdo);
            $message = 'Duyuru güncellendi.';
        } elseif ($action === 'delete') {
            handle_delete_announcement($pdo);
            $message = 'Duyuru silindi.';
        } elseif ($action === 'update_settings') {
            $announcementFontSize = handle_update_announcement_settings();
            $message = 'Duyuru görünümü güncellendi.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$settings = load_all_settings();
$announcementFontSize = normalize_announcement_font_size((int) ($settings['announcement_font_size'] ?? $announcementFontSize));

$stmt = $pdo->query('SELECT * FROM announcements ORDER BY priority DESC, created_at DESC');
$announcements = $stmt->fetchAll();

include __DIR__ . '/partials/header.php';
?>
<?php if ($message): ?><div class="alert success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<section class="card">
    <h3>Duyuru Ayarları</h3>
    <form method="post">
        <input type="hidden" name="action" value="update_settings">
        <div class="form-grid">
            <div>
                <label for="announcement_font_size">Metin Punto (px)</label>
                <input type="number" id="announcement_font_size" name="announcement_font_size" min="14" max="72" value="<?= htmlspecialchars((string) $announcementFontSize) ?>" required>
            </div>
        </div>
        <p class="form-help">Seçtiğiniz punto signage ekranındaki duyuru başlık ve içeriklerinde kullanılacaktır.</p>
        <div class="actions">
            <button class="button" type="submit">Ayarları Kaydet</button>
        </div>
    </form>
</section>

<section class="card">
    <h3>Yeni Duyuru</h3>
    <form method="post">
        <input type="hidden" name="action" value="create">
        <div class="form-grid">
            <div>
                <label for="title">Başlık</label>
                <input type="text" id="title" name="title" required>
            </div>
            <div>
                <label for="body">İçerik</label>
                <textarea id="body" name="body"></textarea>
            </div>
            <div>
                <label for="priority">Öncelik</label>
                <input type="number" id="priority" name="priority" value="1" min="1">
            </div>
            <div>
                <label for="starts_at">Başlangıç</label>
                <input type="datetime-local" id="starts_at" name="starts_at">
            </div>
            <div>
                <label for="ends_at">Bitiş</label>
                <input type="datetime-local" id="ends_at" name="ends_at">
            </div>
            <div>
                <label for="is_active">Aktif</label>
                <select id="is_active" name="is_active">
                    <option value="1">Evet</option>
                    <option value="0">Hayır</option>
                </select>
            </div>
        </div>
        <div class="actions">
            <button class="button" type="submit">Kaydet</button>
        </div>
    </form>
</section>

<section class="card">
    <h3>Mevcut Duyurular</h3>
    <?php foreach ($announcements as $item): ?>
        <form method="post" class="card" style="background: rgba(16,21,44,0.75);">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
            <div class="form-grid">
                <div>
                    <label>Başlık</label>
                    <input type="text" name="title" value="<?= htmlspecialchars($item['title']) ?>" required>
                </div>
                <div>
                    <label>İçerik</label>
                    <textarea name="body"><?= htmlspecialchars($item['body'] ?? '') ?></textarea>
                </div>
                <div>
                    <label>Öncelik</label>
                    <input type="number" name="priority" value="<?= (int) $item['priority'] ?>">
                </div>
                <div>
                    <label>Başlangıç</label>
                    <input type="datetime-local" name="starts_at" value="<?= $item['starts_at'] ? (new DateTime($item['starts_at']))->format('Y-m-d\TH:i') : '' ?>">
                </div>
                <div>
                    <label>Bitiş</label>
                    <input type="datetime-local" name="ends_at" value="<?= $item['ends_at'] ? (new DateTime($item['ends_at']))->format('Y-m-d\TH:i') : '' ?>">
                </div>
                <div>
                    <label>Aktif</label>
                    <select name="is_active">
                        <option value="1"<?= $item['is_active'] ? ' selected' : '' ?>>Evet</option>
                        <option value="0"<?= !$item['is_active'] ? ' selected' : '' ?>>Hayır</option>
                    </select>
                </div>
            </div>
            <div class="actions">
                <button class="button" type="submit">Güncelle</button>
            </div>
        </form>
        <form method="post" onsubmit="return confirm('Duyuru silinsin mi?');">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
            <button class="button danger" type="submit">Sil</button>
        </form>
    <?php endforeach; ?>
</section>
<?php
include __DIR__ . '/partials/footer.php';

function handle_create_announcement(PDO $pdo): void
{
    $stmt = $pdo->prepare('INSERT INTO announcements (title, body, priority, is_active, starts_at, ends_at)
        VALUES (:title, :body, :priority, :is_active, :starts_at, :ends_at)');
    $stmt->execute([
        'title' => trim($_POST['title'] ?? ''),
        'body' => trim($_POST['body'] ?? ''),
        'priority' => (int) ($_POST['priority'] ?? 1),
        'is_active' => (int) ($_POST['is_active'] ?? 1),
        'starts_at' => parse_datetime_local($_POST['starts_at'] ?? '') ?: null,
        'ends_at' => parse_datetime_local($_POST['ends_at'] ?? '') ?: null,
    ]);
}

function handle_update_announcement(PDO $pdo): void
{
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException('Geçersiz duyuru.');
    }
    $stmt = $pdo->prepare('UPDATE announcements SET title = :title, body = :body, priority = :priority, is_active = :is_active, starts_at = :starts_at, ends_at = :ends_at WHERE id = :id');
    $stmt->execute([
        'title' => trim($_POST['title'] ?? ''),
        'body' => trim($_POST['body'] ?? ''),
        'priority' => (int) ($_POST['priority'] ?? 1),
        'is_active' => (int) ($_POST['is_active'] ?? 1),
        'starts_at' => parse_datetime_local($_POST['starts_at'] ?? '') ?: null,
        'ends_at' => parse_datetime_local($_POST['ends_at'] ?? '') ?: null,
        'id' => $id,
    ]);
}

function handle_delete_announcement(PDO $pdo): void
{
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException('Geçersiz duyuru.');
    }
    $pdo->prepare('DELETE FROM announcements WHERE id = :id')->execute(['id' => $id]);
}

function handle_update_announcement_settings(): int
{
    $fontSize = isset($_POST['announcement_font_size']) ? (int) $_POST['announcement_font_size'] : 22;
    $fontSize = normalize_announcement_font_size($fontSize);

    set_setting('announcement_font_size', (string) $fontSize);

    record_syslog('announcements.settings.update', 'Duyuru punto ayarı güncellendi.', current_user()['id'] ?? null, [
        'fontSize' => $fontSize,
    ]);

    return $fontSize;
}

function normalize_announcement_font_size(int $fontSize): int
{
    if ($fontSize <= 0) {
        return 22;
    }

    return max(14, min(72, $fontSize));
}
