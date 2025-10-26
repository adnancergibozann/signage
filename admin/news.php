<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signage.php';

require_login();

$activePage = 'news';
$user = current_user();
$errors = [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pdo = get_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $title = trim($_POST['title'] ?? '');
        $summary = trim($_POST['summary'] ?? '');
        $imageUrl = trim($_POST['image_url'] ?? '');
        $sourceUrl = trim($_POST['source_url'] ?? '');
        $publishedAt = trim($_POST['published_at'] ?? '');

        if ($title === '') {
            $errors[] = 'Başlık zorunludur.';
        }

        $dateValue = null;
        if ($publishedAt !== '') {
            $timestamp = strtotime($publishedAt);
            if ($timestamp === false) {
                $errors[] = 'Geçersiz tarih formatı.';
            } else {
                $dateValue = date('Y-m-d H:i:s', $timestamp);
            }
        }

        if (!$errors) {
            $stmt = $pdo->prepare('INSERT INTO news_items (title, summary, image_url, source_url, published_at, position) VALUES (:title, :summary, :image, :source, :published_at, :position)');
            $stmt->execute([
                'title' => $title,
                'summary' => $summary,
                'image' => $imageUrl ?: null,
                'source' => $sourceUrl ?: null,
                'published_at' => $dateValue,
                'position' => 1 + (int) $pdo->query('SELECT COALESCE(MAX(position), 0) FROM news_items')->fetchColumn(),
            ]);

            $_SESSION['flash'] = 'Haber eklendi.';
            header('Location: /admin/news.php');
            exit;
        }
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM news_items WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $_SESSION['flash'] = 'Haber silindi.';
            header('Location: /admin/news.php');
            exit;
        }
    }
}

$newsItems = $pdo->query('SELECT id, title, summary, published_at FROM news_items ORDER BY COALESCE(published_at, created_at) DESC')->fetchAll();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Haber Yönetimi | Signage</title>
    <link rel="stylesheet" href="/assets/styles.css">
</head>
<body>
<div class="admin-layout">
    <?php include __DIR__ . '/partials/sidebar.php'; ?>
    <main class="content">
        <header style="margin-bottom: 2rem;">
            <h1 style="color: var(--color-primary);">Haber Yönetimi</h1>
            <p>RSS haberleri manuel olarak gir.</p>
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
            <h2>Yeni Haber</h2>
            <form method="post" class="form-grid">
                <input type="hidden" name="action" value="create">
                <div style="grid-column: 1 / -1;">
                    <label for="title">Başlık</label>
                    <input type="text" id="title" name="title" required>
                </div>
                <div style="grid-column: 1 / -1;">
                    <label for="summary">Özet</label>
                    <textarea id="summary" name="summary" rows="3"></textarea>
                </div>
                <div>
                    <label for="image_url">Görsel URL</label>
                    <input type="url" id="image_url" name="image_url">
                </div>
                <div>
                    <label for="source_url">Kaynak URL</label>
                    <input type="url" id="source_url" name="source_url">
                </div>
                <div>
                    <label for="published_at">Yayın Tarihi</label>
                    <input type="datetime-local" id="published_at" name="published_at">
                </div>
                <div style="grid-column: 1 / -1;">
                    <button type="submit" class="button button-primary">Kaydet</button>
                </div>
            </form>
        </section>

        <section class="card">
            <h2>Haberler</h2>
            <?php if (!$newsItems): ?>
                <p>Henüz haber eklenmedi.</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Başlık</th>
                            <th>Özet</th>
                            <th>Yayın Tarihi</th>
                            <th>İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($newsItems as $item): ?>
                        <tr>
                            <td><?php echo (int) $item['id']; ?></td>
                            <td><?php echo htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars(mb_strimwidth($item['summary'] ?? '', 0, 80, '...'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($item['published_at'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <form method="post" onsubmit="return confirm('Bu haber silinsin mi?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
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
