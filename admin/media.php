<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

require_login();
require_role('super_admin');

$pageTitle = 'Medya Yayınları';
$activePage = 'media';
$pdo = get_pdo();
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create') {
            handle_create_media($pdo);
            $message = 'Medya öğesi oluşturuldu.';
        } elseif ($action === 'update') {
            handle_update_media($pdo);
            $message = 'Medya öğesi güncellendi.';
        } elseif ($action === 'delete') {
            handle_delete_media($pdo);
            $message = 'Medya öğesi silindi.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$stmt = $pdo->query('SELECT * FROM media_items ORDER BY created_at DESC');
$mediaItems = $stmt->fetchAll();

include __DIR__ . '/partials/header.php';
?>
<?php if ($message): ?><div class="alert success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<section class="card">
    <h3>Yeni Medya</h3>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="create">
        <div class="form-grid">
            <div>
                <label for="title">Başlık</label>
                <input type="text" id="title" name="title" required>
            </div>
            <div>
                <label for="media_file">Dosya</label>
                <input type="file" id="media_file" name="media_file" required accept="image/*,video/mp4">
            </div>
            <div>
                <label for="media_type">Medya Türü</label>
                <select id="media_type" name="media_type">
                    <option value="image">Görsel</option>
                    <option value="video">Video</option>
                </select>
            </div>
            <div>
                <label for="duration_seconds">Süre (sn)</label>
                <input type="number" id="duration_seconds" name="duration_seconds" min="1" value="10">
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
            <button class="button" type="submit">Medya Ekle</button>
        </div>
    </form>
</section>

<section class="card">
    <h3>Mevcut Medya</h3>
    <?php foreach ($mediaItems as $item): ?>
        <div class="card" style="background: rgba(16,21,44,0.75);">
            <h4><?= htmlspecialchars($item['title']) ?> <small>(<?= htmlspecialchars($item['media_type']) ?>)</small></h4>
            <p>
                <a class="button secondary" href="<?= asset_url('public/uploads/media/' . $item['file_path']) ?>" target="_blank">İzle/Gör</a>
            </p>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                <div class="form-grid">
                    <div>
                        <label>Başlık</label>
                        <input type="text" name="title" value="<?= htmlspecialchars($item['title']) ?>" required>
                    </div>
                    <div>
                        <label>Dosya (yenilemek için seçin)</label>
                        <input type="file" name="media_file" accept="image/*,video/mp4">
                    </div>
                    <div>
                        <label>Medya Türü</label>
                        <select name="media_type">
                            <option value="image"<?= $item['media_type'] === 'image' ? ' selected' : '' ?>>Görsel</option>
                            <option value="video"<?= $item['media_type'] === 'video' ? ' selected' : '' ?>>Video</option>
                        </select>
                    </div>
                    <div>
                        <label>Süre (sn)</label>
                        <input type="number" name="duration_seconds" value="<?= (int) $item['duration_seconds'] ?>">
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
            <form method="post" onsubmit="return confirm('Medya silinsin mi?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                <button class="button danger" type="submit">Sil</button>
            </form>
        </div>
    <?php endforeach; ?>
</section>
<?php
include __DIR__ . '/partials/footer.php';

function handle_create_media(PDO $pdo): void
{
    if (empty($_FILES['media_file']['name'])) {
        throw new RuntimeException('Dosya yüklenmelidir.');
    }
    $file = upload_file(
        $_FILES['media_file'],
        public_path('uploads/media'),
        ['image/jpeg','image/png','image/gif','image/webp','video/mp4'],
        ['jpg','jpeg','png','gif','webp','mp4']
    );
    if (!$file) {
        throw new RuntimeException('Dosya yüklenemedi.');
    }
    $stmt = $pdo->prepare('INSERT INTO media_items (title, file_path, media_type, duration_seconds, priority, is_active, starts_at, ends_at)
        VALUES (:title, :file_path, :media_type, :duration_seconds, :priority, :is_active, :starts_at, :ends_at)');
    $stmt->execute([
        'title' => trim($_POST['title'] ?? ''),
        'file_path' => $file,
        'media_type' => $_POST['media_type'] ?? 'image',
        'duration_seconds' => (int) ($_POST['duration_seconds'] ?? 10),
        'priority' => (int) ($_POST['priority'] ?? 1),
        'is_active' => (int) ($_POST['is_active'] ?? 1),
        'starts_at' => parse_datetime_local($_POST['starts_at'] ?? '') ?: null,
        'ends_at' => parse_datetime_local($_POST['ends_at'] ?? '') ?: null,
    ]);
}

function handle_update_media(PDO $pdo): void
{
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException('Geçersiz medya.');
    }
    $stmt = $pdo->prepare('SELECT file_path FROM media_items WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $existing = $stmt->fetch();
    if (!$existing) {
        throw new RuntimeException('Medya bulunamadı.');
    }
    $filePath = $existing['file_path'];
    if (!empty($_FILES['media_file']['name'])) {
        $upload = upload_file(
            $_FILES['media_file'],
            public_path('uploads/media'),
            ['image/jpeg','image/png','image/gif','image/webp','video/mp4'],
            ['jpg','jpeg','png','gif','webp','mp4']
        );
        if (!$upload) {
            throw new RuntimeException('Dosya yüklenemedi.');
        }
        if ($filePath) {
            @unlink(public_path('uploads/media/' . $filePath));
        }
        $filePath = $upload;
    }
    $stmt = $pdo->prepare('UPDATE media_items SET title = :title, file_path = :file_path, media_type = :media_type, duration_seconds = :duration_seconds, priority = :priority, is_active = :is_active, starts_at = :starts_at, ends_at = :ends_at WHERE id = :id');
    $stmt->execute([
        'title' => trim($_POST['title'] ?? ''),
        'file_path' => $filePath,
        'media_type' => $_POST['media_type'] ?? 'image',
        'duration_seconds' => (int) ($_POST['duration_seconds'] ?? 10),
        'priority' => (int) ($_POST['priority'] ?? 1),
        'is_active' => (int) ($_POST['is_active'] ?? 1),
        'starts_at' => parse_datetime_local($_POST['starts_at'] ?? '') ?: null,
        'ends_at' => parse_datetime_local($_POST['ends_at'] ?? '') ?: null,
        'id' => $id,
    ]);
}

function handle_delete_media(PDO $pdo): void
{
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException('Geçersiz medya.');
    }
    $stmt = $pdo->prepare('SELECT file_path FROM media_items WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $existing = $stmt->fetch();
    if ($existing && $existing['file_path']) {
        @unlink(public_path('uploads/media/' . $existing['file_path']));
    }
    $pdo->prepare('DELETE FROM media_items WHERE id = :id')->execute(['id' => $id]);
}
