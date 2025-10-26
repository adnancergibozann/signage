<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signage.php';

require_login();

$activePage = 'news';
$user = current_user();
$errors = [];
$feedErrors = [];
$feedPreview = null;
$feedUrlInput = '';
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pdo = get_pdo();

function extract_feed_preview(SimpleXMLElement $xml): array
{
    $items = [];

    if (isset($xml->channel)) {
        $channel = $xml->channel;
        foreach ($channel->item as $entry) {
            $items[] = [
                'title' => (string) ($entry->title ?? ''),
                'link' => (string) ($entry->link ?? ''),
                'date' => (string) ($entry->pubDate ?? ''),
            ];
            if (count($items) >= 5) {
                break;
            }
        }
        $title = (string) ($channel->title ?? 'RSS Önizleme');
    } else {
        foreach ($xml->entry as $entry) {
            $items[] = [
                'title' => (string) ($entry->title ?? ''),
                'link' => (string) ($entry->link['href'] ?? ''),
                'date' => (string) ($entry->updated ?? $entry->published ?? ''),
            ];
            if (count($items) >= 5) {
                break;
            }
        }
        $title = (string) ($xml->title ?? 'RSS Önizleme');
    }

    return [
        'title' => $title,
        'items' => $items,
    ];
}

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
            header('Location: ' . route_url('admin/news.php'));
            exit;
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM news_items WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $_SESSION['flash'] = 'Haber silindi.';
            header('Location: ' . route_url('admin/news.php'));
            exit;
        }
    } elseif ($action === 'test_feed') {
        $feedUrlInput = trim($_POST['feed_url'] ?? '');
        if ($feedUrlInput === '') {
            $feedErrors[] = 'RSS adresi zorunludur.';
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 10,
                    'header' => "User-Agent: SignageDashboard/1.0\r\nAccept: application/rss+xml, application/xml",
                ],
            ]);

            $response = @file_get_contents($feedUrlInput, false, $context);
            if ($response === false) {
                $feedErrors[] = 'RSS kaynağına ulaşılamadı.';
            } else {
                $xml = @simplexml_load_string($response, 'SimpleXMLElement', LIBXML_NOCDATA);
                if ($xml === false) {
                    $feedErrors[] = 'RSS formatı okunamadı.';
                } else {
                    $feedPreview = extract_feed_preview($xml);
                    $feedPreview['url'] = $feedUrlInput;
                }
            }
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
    <link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/styles.css'), ENT_QUOTES, 'UTF-8'); ?>">
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
            <h2>RSS Feed Testi</h2>
            <p>RSS adresini girerek kaynağın erişilebilirliğini ve ilk maddelerini kontrol et.</p>
            <form method="post" class="form-grid">
                <input type="hidden" name="action" value="test_feed">
                <div style="grid-column: 1 / -1;">
                    <label for="feed_url">RSS URL</label>
                    <input type="url" id="feed_url" name="feed_url" placeholder="https://www.trthaber.com/rss" value="<?php echo htmlspecialchars($feedUrlInput, ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div style="grid-column: 1 / -1;">
                    <button type="submit" class="button button-secondary">Bağlantıyı Test Et</button>
                </div>
            </form>
            <?php if ($feedErrors): ?>
                <div class="alert alert-error" style="margin-top: 1rem;">
                    <ul>
                        <?php foreach ($feedErrors as $error): ?>
                            <li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <?php if ($feedPreview && !$feedErrors): ?>
                <div style="margin-top: 1rem;">
                    <h3 style="margin-bottom: 0.5rem;">Önizleme: <?php echo htmlspecialchars($feedPreview['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
                    <ul style="list-style: disc; padding-left: 1.5rem;">
                        <?php if (empty($feedPreview['items'])): ?>
                            <li>Herhangi bir içerik bulunamadı.</li>
                        <?php else: ?>
                            <?php foreach ($feedPreview['items'] as $item): ?>
                                <li style="margin-bottom: 0.5rem;">
                                    <strong><?php echo htmlspecialchars($item['title'] ?: 'Başlık yok', ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <?php if (!empty($item['date'])): ?>
                                        <span style="color: rgba(255,255,255,0.6);">&mdash; <?php echo htmlspecialchars($item['date'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($item['link'])): ?>
                                        <div><a href="<?php echo htmlspecialchars($item['link'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Bağlantıyı aç</a></div>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </section>

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
