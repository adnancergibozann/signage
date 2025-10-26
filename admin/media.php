<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signage.php';

require_login();

$activePage = 'media';
$user = current_user();
$errors = [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pdo = get_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $title = trim($_POST['title'] ?? '');
        $type = $_POST['type'] ?? 'image';
        $duration = max(3, min(60, (int) ($_POST['duration'] ?? 5)));

        $allowedTypes = ['image', 'video', 'pdf'];
        if (!in_array($type, $allowedTypes, true)) {
            $errors[] = 'Geçersiz medya tipi.';
        }

        if ($title === '') {
            $errors[] = 'Başlık zorunludur.';
        }

        $source = null;

        if ($type === 'video') {
            $source = trim($_POST['video_url'] ?? '');
            if ($source === '') {
                $errors[] = 'Video bağlantısı gereklidir.';
            }
        } else {
            if (!empty($_FILES['file_source']['tmp_name']) && is_uploaded_file($_FILES['file_source']['tmp_name'])) {
                $fileMime = mime_content_type($_FILES['file_source']['tmp_name']);
                $validMime = $type === 'image'
                    ? ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml']
                    : ['application/pdf'];

                if (!in_array($fileMime, $validMime, true)) {
                    $errors[] = 'Dosya formatı desteklenmiyor.';
                } elseif ($_FILES['file_source']['size'] > 5 * 1024 * 1024) {
                    $errors[] = 'Dosya boyutu 5MB sınırını aşmamalıdır.';
                } else {
                    $binary = file_get_contents($_FILES['file_source']['tmp_name']);
                    $source = sprintf('data:%s;base64,%s', $fileMime, base64_encode($binary));
                }
            } else {
                $errors[] = 'Dosya yüklenmedi.';
            }
        }

        if (!$errors && $source) {
            $stmt = $pdo->prepare('INSERT INTO media_items (title, type, source, duration_seconds, position) VALUES (:title, :type, :source, :duration, :position)');
            $stmt->execute([
                'title' => $title,
                'type' => $type,
                'source' => $source,
                'duration' => $duration,
                'position' => 1 + (int) $pdo->query('SELECT COALESCE(MAX(position), 0) FROM media_items')->fetchColumn(),
            ]);

            $_SESSION['flash'] = 'Medya öğesi eklendi.';
            header('Location: ' . route_url('admin/media.php'));
            exit;
        }
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM media_items WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $_SESSION['flash'] = 'Medya silindi.';
            header('Location: ' . route_url('admin/media.php'));
            exit;
        }
    }
}

$mediaItems = $pdo->query('SELECT id, title, type, source, duration_seconds FROM media_items ORDER BY position ASC, id ASC')->fetchAll();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medya Yönetimi | Signage</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/styles.css'), ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body>
<div class="admin-layout">
    <?php include __DIR__ . '/partials/sidebar.php'; ?>
    <main class="content">
        <header style="margin-bottom: 2rem;">
            <h1 style="color: var(--color-primary);">Medya Yönetimi</h1>
            <p>Slider'da görünecek içerikleri düzenle.</p>
        </header>

        <?php if ($errors): ?>
            <div class="alert alert-error">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($flash): ?>
            <div class="alert" style="background: rgba(255,212,0,0.15); border:1px solid rgba(255,212,0,0.5);">
                <?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <section class="card" style="margin-bottom: 2rem;">
            <h2>Yeni Medya</h2>
            <form method="post" enctype="multipart/form-data" class="form-grid">
                <input type="hidden" name="action" value="create">
                <div>
                    <label for="title">Başlık</label>
                    <input type="text" id="title" name="title" required>
                </div>
                <div>
                    <label for="type">Medya Tipi</label>
                    <select id="type" name="type">
                        <option value="image">Görsel</option>
                        <option value="video">Video (YouTube)</option>
                        <option value="pdf">PDF</option>
                    </select>
                </div>
                <div>
                    <label for="duration">Gösterim Süresi (sn)</label>
                    <input type="number" id="duration" name="duration" min="3" max="60" value="8">
                </div>
                <div>
                    <label for="file_source">Dosya (Görsel/PDF)</label>
                    <input type="file" id="file_source" name="file_source" accept="image/*,application/pdf">
                </div>
                <div>
                    <label for="video_url">Video URL</label>
                    <input type="url" id="video_url" name="video_url" placeholder="https://www.youtube.com/embed/...">
                </div>
                <div style="grid-column: 1 / -1;">
                    <button type="submit" class="button button-primary">Kaydet</button>
                </div>
            </form>
        </section>

        <section class="card">
            <h2>Mevcut Medyalar</h2>
            <?php if (!$mediaItems): ?>
                <p>Henüz medya eklenmedi.</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Başlık</th>
                            <th>Tip</th>
                            <th>Süre</th>
                            <th>Önizleme</th>
                            <th>İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($mediaItems as $row): ?>
                        <tr>
                            <td><?php echo (int) $row['id']; ?></td>
                            <td><?php echo htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo strtoupper($row['type']); ?></td>
                            <td><?php echo (int) $row['duration_seconds']; ?> sn</td>
                            <td>
                                <?php if ($row['type'] === 'video'): ?>
                                    <a href="<?php echo htmlspecialchars($row['source'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank">Bağlantı</a>
                                <?php else: ?>
                                    <small>Dosya yüklendi</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="post" onsubmit="return confirm('Bu medya silinsin mi?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                    <button type="submit" class="button button-secondary">Sil</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </main>
</div>
</body>
</html>
