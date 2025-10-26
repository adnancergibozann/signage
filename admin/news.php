<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signage.php';

require_login();

$activePage = 'news';
$user = current_user();
$errors = [];
$feedErrors = [];
$feedFormErrors = [];
$feedPreview = null;
$feedUrlInput = '';
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pdo = get_pdo();

function fetch_feed_xml(string $url, array &$errors = []): ?SimpleXMLElement
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 10,
            'header' => "User-Agent: SignageDashboard/1.0\r\nAccept: application/rss+xml, application/xml",
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        $errors[] = 'RSS kaynağına ulaşılamadı.';
        return null;
    }

    $xml = @simplexml_load_string($response, 'SimpleXMLElement', LIBXML_NOCDATA);
    if ($xml === false) {
        $errors[] = 'RSS formatı okunamadı.';
        return null;
    }

    return $xml;
}

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

function normalize_feed_summary(string $value, int $limit = 240): string
{
    $clean = trim(strip_tags(html_entity_decode($value, ENT_QUOTES, 'UTF-8')));
    if (mb_strlen($clean, 'UTF-8') > $limit) {
        $clean = rtrim(mb_substr($clean, 0, $limit - 1, 'UTF-8')) . '…';
    }

    return $clean;
}

function parse_feed_datetime(?string $value): ?string
{
    if (!$value) {
        return null;
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return null;
    }

    return date('Y-m-d H:i:s', $timestamp);
}

function extract_entry_image(SimpleXMLElement $entry): ?string
{
    if (isset($entry->enclosure) && !empty($entry->enclosure['url'])) {
        return (string) $entry->enclosure['url'];
    }

    $media = $entry->children('media', true);
    if ($media) {
        if (isset($media->thumbnail) && !empty($media->thumbnail->attributes()['url'])) {
            return (string) $media->thumbnail->attributes()['url'];
        }
        if (isset($media->content) && !empty($media->content->attributes()['url'])) {
            return (string) $media->content->attributes()['url'];
        }
    }

    foreach ($entry->link ?? [] as $link) {
        if ((string) ($link['rel'] ?? '') === 'enclosure' && !empty($link['href'])) {
            return (string) $link['href'];
        }
    }

    return null;
}

function normalize_feed_items(SimpleXMLElement $xml, int $limit = 10): array
{
    $items = [];

    if (isset($xml->channel)) {
        foreach ($xml->channel->item as $entry) {
            $items[] = [
                'title' => trim((string) ($entry->title ?? '')),
                'summary' => normalize_feed_summary((string) ($entry->description ?? '')),
                'image_url' => extract_entry_image($entry),
                'source_url' => (string) ($entry->link ?? ''),
                'published_at' => parse_feed_datetime((string) ($entry->pubDate ?? '')),
            ];
            if (count($items) >= $limit) {
                break;
            }
        }
    } else {
        foreach ($xml->entry as $entry) {
            $items[] = [
                'title' => trim((string) ($entry->title ?? '')),
                'summary' => normalize_feed_summary((string) ($entry->summary ?? $entry->content ?? '')),
                'image_url' => extract_entry_image($entry),
                'source_url' => (string) ($entry->link['href'] ?? ''),
                'published_at' => parse_feed_datetime((string) ($entry->updated ?? $entry->published ?? '')),
            ];
            if (count($items) >= $limit) {
                break;
            }
        }
    }

    return array_values(array_filter($items, static function (array $item) {
        return $item['title'] !== '' && $item['source_url'] !== '';
    }));
}

function sync_feed(PDO $pdo, array $feed): array
{
    $errors = [];
    $xml = fetch_feed_xml($feed['feed_url'], $errors);

    if (!$xml) {
        $status = $errors[0] ?? 'RSS kaynağına ulaşılamadı';
        $pdo->prepare('UPDATE news_feeds SET last_checked_at = NOW(), last_status = :status WHERE id = :id')
            ->execute(['status' => $status, 'id' => $feed['id']]);

        return ['success' => false, 'message' => $status];
    }

    $items = normalize_feed_items($xml, (int) $feed['items_limit']);

    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM news_feed_items WHERE feed_id = :feed_id')->execute(['feed_id' => $feed['id']]);

    if ($items) {
        $insert = $pdo->prepare('INSERT INTO news_feed_items (feed_id, title, summary, image_url, source_url, published_at) VALUES (:feed_id, :title, :summary, :image, :source, :published_at)');
        foreach ($items as $item) {
            $insert->execute([
                'feed_id' => $feed['id'],
                'title' => $item['title'],
                'summary' => $item['summary'] ?: null,
                'image' => $item['image_url'] ?: null,
                'source' => $item['source_url'],
                'published_at' => $item['published_at'],
            ]);
        }
    }

    $pdo->prepare('UPDATE news_feeds SET last_checked_at = NOW(), last_status = :status WHERE id = :id')
        ->execute([
            'status' => $items ? count($items) . ' içerik güncellendi' : 'İçerik bulunamadı',
            'id' => $feed['id'],
        ]);

    $pdo->commit();

    return ['success' => true, 'message' => $items ? count($items) . ' içerik güncellendi' : 'İçerik bulunamadı'];
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
            $xmlErrors = [];
            $xml = fetch_feed_xml($feedUrlInput, $xmlErrors);
            if ($xml) {
                $feedPreview = extract_feed_preview($xml);
                $feedPreview['url'] = $feedUrlInput;
            } else {
                $feedErrors = array_merge($feedErrors, $xmlErrors);
            }
        }
    } elseif ($action === 'create_feed') {
        $feedTitle = trim($_POST['feed_title'] ?? '');
        $feedSource = trim($_POST['feed_source_url'] ?? '');
        $itemsLimit = (int) ($_POST['items_limit'] ?? 10);
        $itemsLimit = max(1, min(25, $itemsLimit));

        if ($feedTitle === '') {
            $feedFormErrors[] = 'Kaynak adı zorunludur.';
        }
        if ($feedSource === '') {
            $feedFormErrors[] = 'RSS adresi zorunludur.';
        }

        if (!$feedFormErrors) {
            $stmt = $pdo->prepare('INSERT INTO news_feeds (title, feed_url, items_limit) VALUES (:title, :url, :limit)');
            try {
                $stmt->execute([
                    'title' => $feedTitle,
                    'url' => $feedSource,
                    'limit' => $itemsLimit,
                ]);
                $feedId = (int) $pdo->lastInsertId();
                $feed = [
                    'id' => $feedId,
                    'feed_url' => $feedSource,
                    'items_limit' => $itemsLimit,
                ];
                $result = sync_feed($pdo, $feed);
                $_SESSION['flash'] = $result['success'] ? 'RSS kaynağı eklendi ve ' . $result['message'] : 'RSS kaynağı eklendi ancak ' . $result['message'];
                header('Location: ' . route_url('admin/news.php'));
                exit;
            } catch (\PDOException $e) {
                $feedFormErrors[] = 'Bu RSS kaynağı zaten kayıtlı.';
            }
        }
    } elseif ($action === 'refresh_feed') {
        $feedId = (int) ($_POST['feed_id'] ?? 0);
        if ($feedId > 0) {
            $feedStmt = $pdo->prepare('SELECT id, feed_url, items_limit FROM news_feeds WHERE id = :id');
            $feedStmt->execute(['id' => $feedId]);
            $feed = $feedStmt->fetch();
            if ($feed) {
                $result = sync_feed($pdo, $feed);
                $_SESSION['flash'] = $result['message'];
            }
            header('Location: ' . route_url('admin/news.php'));
            exit;
        }
    } elseif ($action === 'delete_feed') {
        $feedId = (int) ($_POST['feed_id'] ?? 0);
        if ($feedId > 0) {
            $stmt = $pdo->prepare('DELETE FROM news_feeds WHERE id = :id');
            $stmt->execute(['id' => $feedId]);
            $_SESSION['flash'] = 'RSS kaynağı silindi.';
            header('Location: ' . route_url('admin/news.php'));
            exit;
        }
    } elseif ($action === 'toggle_feed') {
        $feedId = (int) ($_POST['feed_id'] ?? 0);
        $isActive = isset($_POST['is_active']) && (int) $_POST['is_active'] === 1 ? 1 : 0;
        if ($feedId > 0) {
            $stmt = $pdo->prepare('UPDATE news_feeds SET is_active = :active WHERE id = :id');
            $stmt->execute(['active' => $isActive, 'id' => $feedId]);
            $_SESSION['flash'] = $isActive ? 'RSS kaynağı aktifleştirildi.' : 'RSS kaynağı pasifleştirildi.';
            header('Location: ' . route_url('admin/news.php'));
            exit;
        }
    }
}

$newsItems = $pdo->query('SELECT id, title, summary, published_at FROM news_items ORDER BY COALESCE(published_at, created_at) DESC')->fetchAll();
$feeds = $pdo->query('SELECT id, title, feed_url, items_limit, is_active, last_checked_at, last_status FROM news_feeds ORDER BY title ASC')->fetchAll();
$feedItems = $pdo->query('SELECT f.title AS feed_title, i.title, i.summary, i.source_url, i.published_at, i.fetched_at FROM news_feed_items i INNER JOIN news_feeds f ON f.id = i.feed_id ORDER BY COALESCE(i.published_at, i.fetched_at) DESC LIMIT 20')->fetchAll();
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
            <h2>RSS Kaynakları</h2>
            <p>Aktif kaynakları yönet ve yeni içerik çek.</p>
            <form method="post" class="form-grid">
                <input type="hidden" name="action" value="create_feed">
                <div>
                    <label for="feed_title">Kaynak Adı</label>
                    <input type="text" id="feed_title" name="feed_title" required>
                </div>
                <div style="grid-column: span 2;">
                    <label for="feed_source_url">RSS URL</label>
                    <input type="url" id="feed_source_url" name="feed_source_url" placeholder="https://www.trthaber.com/rss" required>
                </div>
                <div>
                    <label for="items_limit">Kayıt Limiti</label>
                    <input type="number" id="items_limit" name="items_limit" min="1" max="25" value="10">
                </div>
                <div style="grid-column: 1 / -1;">
                    <button type="submit" class="button button-primary">RSS Kaynağı Ekle</button>
                </div>
            </form>
            <?php if ($feedFormErrors): ?>
                <div class="alert alert-error" style="margin-top: 1rem;">
                    <ul>
                        <?php foreach ($feedFormErrors as $error): ?>
                            <li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <?php if ($feeds): ?>
                <table class="table" style="margin-top: 1rem;">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Kaynak</th>
                            <th>Adres</th>
                            <th>Limit</th>
                            <th>Durum</th>
                            <th>Son Kontrol</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($feeds as $feed): ?>
                        <tr>
                            <td><?php echo (int) $feed['id']; ?></td>
                            <td><?php echo htmlspecialchars($feed['title'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <a href="<?php echo htmlspecialchars($feed['feed_url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Kaynağı aç</a>
                            </td>
                            <td><?php echo (int) $feed['items_limit']; ?></td>
                            <td>
                                <span style="font-weight:600; color: <?php echo $feed['is_active'] ? 'var(--color-primary)' : 'rgba(255,255,255,0.6)'; ?>;">
                                    <?php echo $feed['is_active'] ? 'Aktif' : 'Pasif'; ?>
                                </span>
                                <br>
                                <small><?php echo htmlspecialchars($feed['last_status'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars($feed['last_checked_at'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td style="display:flex; flex-wrap:wrap; gap:0.5rem;">
                                <form method="post">
                                    <input type="hidden" name="action" value="refresh_feed">
                                    <input type="hidden" name="feed_id" value="<?php echo (int) $feed['id']; ?>">
                                    <button type="submit" class="button button-secondary">Yenile</button>
                                </form>
                                <form method="post">
                                    <input type="hidden" name="action" value="toggle_feed">
                                    <input type="hidden" name="feed_id" value="<?php echo (int) $feed['id']; ?>">
                                    <input type="hidden" name="is_active" value="<?php echo $feed['is_active'] ? 0 : 1; ?>">
                                    <button type="submit" class="button button-secondary"><?php echo $feed['is_active'] ? 'Pasifleştir' : 'Aktifleştir'; ?></button>
                                </form>
                                <form method="post" onsubmit="return confirm('Bu RSS kaynağı silinsin mi?');">
                                    <input type="hidden" name="action" value="delete_feed">
                                    <input type="hidden" name="feed_id" value="<?php echo (int) $feed['id']; ?>">
                                    <button type="submit" class="button button-secondary">Sil</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="margin-top:1rem;">Henüz RSS kaynağı eklenmedi.</p>
            <?php endif; ?>
        </section>

        <section class="card" style="margin-bottom: 2rem;">
            <h2>RSS İçerik Önizlemesi</h2>
            <?php if (!$feedItems): ?>
                <p>RSS kaynaklarından içerik bulunamadı.</p>
            <?php else: ?>
                <ul style="list-style:none; padding:0; margin:0; display:grid; gap:1rem;">
                    <?php foreach ($feedItems as $item): ?>
                        <li style="padding:0.75rem 1rem; border:1px solid rgba(255,255,255,0.08); border-radius:12px;">
                            <div style="font-weight:600; margin-bottom:0.25rem;">
                                <?php echo htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <div style="color: rgba(255,255,255,0.6); font-size:0.85rem; margin-bottom:0.5rem;">
                                <?php echo htmlspecialchars($item['feed_title'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php if (!empty($item['published_at']) || !empty($item['fetched_at'])): ?>
                                    • <?php echo htmlspecialchars($item['published_at'] ?? $item['fetched_at'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($item['summary'])): ?>
                                <p style="margin:0 0 0.5rem; color: rgba(255,255,255,0.75);"><?php echo htmlspecialchars($item['summary'], ENT_QUOTES, 'UTF-8'); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($item['source_url'])): ?>
                                <a href="<?php echo htmlspecialchars($item['source_url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" class="button button-secondary" style="padding:0.35rem 0.75rem; font-size:0.85rem;">Haberi aç</a>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
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
