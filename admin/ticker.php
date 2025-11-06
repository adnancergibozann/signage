<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ticker.php';

require_login();
require_role('super_admin');

$pageTitle = 'Kayan Yazı Yönetimi';
$activePage = 'ticker';
$pdo = get_pdo();
$settings = load_all_settings();
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'update_settings') {
            handle_update_ticker_settings();
            $message = 'Ticker ayarları güncellendi.';
        } elseif ($action === 'create') {
            handle_create_ticker($pdo);
            $message = 'Ticker öğesi oluşturuldu.';
        } elseif ($action === 'update') {
            handle_update_ticker($pdo);
            $message = 'Ticker güncellendi.';
        } elseif ($action === 'delete') {
            handle_delete_ticker($pdo);
            $message = 'Ticker silindi.';
        } elseif ($action === 'create_feed') {
            handle_create_ticker_feed($pdo);
            $message = 'RSS kaynağı eklendi.';
        } elseif ($action === 'update_feed') {
            handle_update_ticker_feed($pdo);
            $message = 'RSS kaynağı güncellendi.';
        } elseif ($action === 'delete_feed') {
            handle_delete_ticker_feed($pdo);
            $message = 'RSS kaynağı silindi.';
        } elseif ($action === 'refresh_feed') {
            $result = handle_refresh_ticker_feed($pdo);
            $label = $result['title'] ? ' (' . $result['title'] . ')' : '';
            $message = sprintf('RSS kaynağı%s yenilendi ve %d öğe alındı.', $label, $result['count']);
        }
        $settings = load_all_settings();
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$stmt = $pdo->query('SELECT * FROM ticker_items ORDER BY priority DESC, created_at DESC');
$tickers = $stmt->fetchAll();
$feedStmt = $pdo->query('SELECT * FROM ticker_feeds ORDER BY priority DESC, id DESC');
$feeds = $feedStmt->fetchAll();
$tickerFontSize = (int) ($settings['ticker_font_size'] ?? 24);
if ($tickerFontSize <= 0) {
    $tickerFontSize = 24;
}
$tickerBandHeight = (int) ($settings['ticker_band_height'] ?? 70);
if ($tickerBandHeight <= 0) {
    $tickerBandHeight = 70;
}

include __DIR__ . '/partials/header.php';
?>
<?php if ($message): ?><div class="alert success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<section class="card">
    <h3>Kayan Yazı Ayarları</h3>
    <form method="post">
        <input type="hidden" name="action" value="update_settings">
        <div class="form-grid">
            <div>
                <label for="ticker_font_size">Punto (px)</label>
                <input type="number" id="ticker_font_size" name="ticker_font_size" min="12" max="96" value="<?= htmlspecialchars((string) $tickerFontSize) ?>" required>
            </div>
            <div>
                <label for="ticker_band_height">Bant Yüksekliği (px)</label>
                <input type="number" id="ticker_band_height" name="ticker_band_height" min="40" max="240" value="<?= htmlspecialchars((string) $tickerBandHeight) ?>" required>
            </div>
        </div>
        <p class="form-help">Değerler signage ekranına anında yansır.</p>
        <div class="actions">
            <button class="button" type="submit">Ayarları Kaydet</button>
        </div>
    </form>
</section>

<section class="card">
    <h3>Yeni Ticker Öğesi</h3>
    <form method="post">
        <input type="hidden" name="action" value="create">
        <div class="form-grid">
            <div>
                <label for="message">Mesaj</label>
                <input type="text" id="message" name="message" required>
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
    <h3>RSS Kaynağı Ekle</h3>
    <form method="post">
        <input type="hidden" name="action" value="create_feed">
        <div class="form-grid">
            <div>
                <label for="feed_title">Kaynak Adı (isteğe bağlı)</label>
                <input type="text" id="feed_title" name="title" placeholder="Örn. Dış Haberler">
            </div>
            <div>
                <label for="feed_url">RSS Adresi</label>
                <input type="url" id="feed_url" name="feed_url" placeholder="https://example.com/rss" required>
            </div>
            <div>
                <label for="feed_priority">Öncelik</label>
                <input type="number" id="feed_priority" name="priority" value="1" min="1">
            </div>
            <div>
                <label for="feed_ttl">Yenileme Aralığı (sn)</label>
                <input type="number" id="feed_ttl" name="cache_ttl_seconds" value="300" min="60" max="21600" step="30">
            </div>
            <div>
                <label for="feed_is_active">Aktif</label>
                <select id="feed_is_active" name="is_active">
                    <option value="1">Evet</option>
                    <option value="0">Hayır</option>
                </select>
            </div>
        </div>
        <p class="form-help">RSS kaynakları ticker mesajlarının sonuna eklenir. Yenileme aralığına göre otomatik güncellenir.</p>
        <div class="actions">
            <button class="button" type="submit">Kaynağı Kaydet</button>
        </div>
    </form>
</section>

<section class="card">
    <h3>Mevcut Öğeler</h3>
    <?php foreach ($tickers as $item): ?>
        <form method="post" class="card" style="background: rgba(16,21,44,0.75);">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
            <div class="form-grid">
                <div>
                    <label>Mesaj</label>
                    <input type="text" name="message" value="<?= htmlspecialchars($item['message']) ?>" required>
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
        <form method="post" onsubmit="return confirm('Silmek istediğinize emin misiniz?');">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
            <button class="button danger" type="submit">Sil</button>
        </form>
    <?php endforeach; ?>
</section>

<section class="card">
    <h3>RSS Kaynakları</h3>
    <?php if (!$feeds): ?>
        <p class="form-help">Henüz RSS kaynağı eklenmemiştir.</p>
    <?php endif; ?>
    <?php foreach ($feeds as $feed): ?>
        <form method="post" class="card" style="background: rgba(16,21,44,0.75);">
            <input type="hidden" name="action" value="update_feed">
            <input type="hidden" name="id" value="<?= (int) $feed['id'] ?>">
            <div class="form-grid">
                <div>
                    <label for="feed_title_<?= (int) $feed['id'] ?>">Kaynak Adı</label>
                    <input type="text" id="feed_title_<?= (int) $feed['id'] ?>" name="title" value="<?= htmlspecialchars($feed['title'] ?? '') ?>">
                </div>
                <div>
                    <label for="feed_url_<?= (int) $feed['id'] ?>">RSS Adresi</label>
                    <input type="url" id="feed_url_<?= (int) $feed['id'] ?>" name="feed_url" value="<?= htmlspecialchars($feed['feed_url']) ?>" required>
                </div>
                <div>
                    <label for="feed_priority_<?= (int) $feed['id'] ?>">Öncelik</label>
                    <input type="number" id="feed_priority_<?= (int) $feed['id'] ?>" name="priority" value="<?= (int) $feed['priority'] ?>" min="1">
                </div>
                <div>
                    <label for="feed_ttl_<?= (int) $feed['id'] ?>">Yenileme Aralığı (sn)</label>
                    <input type="number" id="feed_ttl_<?= (int) $feed['id'] ?>" name="cache_ttl_seconds" value="<?= (int) $feed['cache_ttl_seconds'] ?>" min="60" max="21600" step="30">
                </div>
                <div>
                    <label for="feed_active_<?= (int) $feed['id'] ?>">Aktif</label>
                    <select id="feed_active_<?= (int) $feed['id'] ?>" name="is_active">
                        <option value="1"<?= (int) $feed['is_active'] === 1 ? ' selected' : '' ?>>Evet</option>
                        <option value="0"<?= (int) $feed['is_active'] === 0 ? ' selected' : '' ?>>Hayır</option>
                    </select>
                </div>
            </div>
            <?php if (!empty($feed['cache_fetched_at'])): ?>
                <p class="form-help">Son yenileme: <?= htmlspecialchars(format_datetime($feed['cache_fetched_at'])) ?></p>
            <?php endif; ?>
            <?php if (!empty($feed['last_error'])): ?>
                <p class="form-help error">Son hata: <?= htmlspecialchars($feed['last_error']) ?></p>
            <?php endif; ?>
            <div class="actions">
                <button class="button" type="submit">Güncelle</button>
            </div>
        </form>
        <div class="table-actions">
            <form method="post">
                <input type="hidden" name="action" value="refresh_feed">
                <input type="hidden" name="id" value="<?= (int) $feed['id'] ?>">
                <button class="button secondary" type="submit">Yenile</button>
            </form>
            <form method="post" onsubmit="return confirm('RSS kaynağı silinsin mi?');">
                <input type="hidden" name="action" value="delete_feed">
                <input type="hidden" name="id" value="<?= (int) $feed['id'] ?>">
                <button class="button danger" type="submit">Sil</button>
            </form>
        </div>
    <?php endforeach; ?>
</section>
<?php
include __DIR__ . '/partials/footer.php';

function handle_update_ticker_settings(): void
{
    $fontSize = isset($_POST['ticker_font_size']) ? (int) $_POST['ticker_font_size'] : 24;
    $bandHeight = isset($_POST['ticker_band_height']) ? (int) $_POST['ticker_band_height'] : 70;

    $fontSize = max(12, min(96, $fontSize));
    $bandHeight = max(40, min(240, $bandHeight));

    set_setting('ticker_font_size', (string) $fontSize);
    set_setting('ticker_band_height', (string) $bandHeight);

    record_syslog('ticker.settings.update', 'Kayan yazı görünümü güncellendi.', current_user()['id'] ?? null, [
        'fontSize' => $fontSize,
        'bandHeight' => $bandHeight,
    ]);
}

function handle_create_ticker(PDO $pdo): void
{
    $stmt = $pdo->prepare('INSERT INTO ticker_items (message, priority, is_active, starts_at, ends_at)
        VALUES (:message, :priority, :is_active, :starts_at, :ends_at)');
    $stmt->execute([
        'message' => trim($_POST['message'] ?? ''),
        'priority' => (int) ($_POST['priority'] ?? 1),
        'is_active' => (int) ($_POST['is_active'] ?? 1),
        'starts_at' => parse_datetime_local($_POST['starts_at'] ?? '') ?: null,
        'ends_at' => parse_datetime_local($_POST['ends_at'] ?? '') ?: null,
    ]);
}

function handle_update_ticker(PDO $pdo): void
{
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException('Geçersiz kayıt.');
    }
    $stmt = $pdo->prepare('UPDATE ticker_items SET message = :message, priority = :priority, is_active = :is_active, starts_at = :starts_at, ends_at = :ends_at WHERE id = :id');
    $stmt->execute([
        'message' => trim($_POST['message'] ?? ''),
        'priority' => (int) ($_POST['priority'] ?? 1),
        'is_active' => (int) ($_POST['is_active'] ?? 1),
        'starts_at' => parse_datetime_local($_POST['starts_at'] ?? '') ?: null,
        'ends_at' => parse_datetime_local($_POST['ends_at'] ?? '') ?: null,
        'id' => $id,
    ]);
}

function handle_create_ticker_feed(PDO $pdo): void
{
    $title = trim($_POST['title'] ?? '');
    $url = trim($_POST['feed_url'] ?? '');
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        throw new RuntimeException('Geçerli bir RSS adresi giriniz.');
    }

    $priority = (int) ($_POST['priority'] ?? 1);
    if ($priority <= 0) {
        $priority = 1;
    }
    $ttl = normalize_feed_ttl(isset($_POST['cache_ttl_seconds']) ? (int) $_POST['cache_ttl_seconds'] : 300);
    $isActive = (int) ($_POST['is_active'] ?? 1) === 1 ? 1 : 0;

    $stmt = $pdo->prepare('INSERT INTO ticker_feeds (title, feed_url, priority, is_active, cache_ttl_seconds)
        VALUES (:title, :url, :priority, :is_active, :ttl)');
    $stmt->execute([
        'title' => $title !== '' ? $title : null,
        'url' => $url,
        'priority' => $priority,
        'is_active' => $isActive,
        'ttl' => $ttl,
    ]);

    record_syslog('ticker.feed.create', 'Yeni RSS kaynağı eklendi.', current_user()['id'] ?? null, [
        'feedUrl' => $url,
        'title' => $title,
    ]);
}

function handle_update_ticker_feed(PDO $pdo): void
{
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException('Geçersiz RSS kaynağı.');
    }

    $stmt = $pdo->prepare('SELECT feed_url FROM ticker_feeds WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $existing = $stmt->fetch();
    if (!$existing) {
        throw new RuntimeException('RSS kaynağı bulunamadı.');
    }

    $title = trim($_POST['title'] ?? '');
    $url = trim($_POST['feed_url'] ?? '');
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        throw new RuntimeException('Geçerli bir RSS adresi giriniz.');
    }

    $priority = (int) ($_POST['priority'] ?? 1);
    if ($priority <= 0) {
        $priority = 1;
    }
    $ttl = normalize_feed_ttl(isset($_POST['cache_ttl_seconds']) ? (int) $_POST['cache_ttl_seconds'] : 300);
    $isActive = (int) ($_POST['is_active'] ?? 1) === 1 ? 1 : 0;

    $stmt = $pdo->prepare('UPDATE ticker_feeds
        SET title = :title,
            feed_url = :url,
            priority = :priority,
            is_active = :is_active,
            cache_ttl_seconds = :ttl,
            cache_payload = NULL,
            cache_fetched_at = NULL,
            last_error = NULL
        WHERE id = :id');
    $stmt->execute([
        'title' => $title !== '' ? $title : null,
        'url' => $url,
        'priority' => $priority,
        'is_active' => $isActive,
        'ttl' => $ttl,
        'id' => $id,
    ]);

    record_syslog('ticker.feed.update', 'RSS kaynağı güncellendi.', current_user()['id'] ?? null, [
        'feedId' => $id,
        'feedUrl' => $url,
        'title' => $title,
    ]);
}

function handle_delete_ticker_feed(PDO $pdo): void
{
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException('Geçersiz RSS kaynağı.');
    }
    $stmt = $pdo->prepare('DELETE FROM ticker_feeds WHERE id = :id');
    $stmt->execute(['id' => $id]);

    record_syslog('ticker.feed.delete', 'RSS kaynağı silindi.', current_user()['id'] ?? null, [
        'feedId' => $id,
    ]);
}

function handle_refresh_ticker_feed(PDO $pdo): array
{
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException('Geçersiz RSS kaynağı.');
    }

    $stmt = $pdo->prepare('SELECT * FROM ticker_feeds WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $feed = $stmt->fetch();
    if (!$feed) {
        throw new RuntimeException('RSS kaynağı bulunamadı.');
    }

    $now = new DateTimeImmutable('now');
    $items = refresh_ticker_feed($pdo, $id, $feed, $now);

    record_syslog('ticker.feed.refresh', 'RSS kaynağı manuel olarak yenilendi.', current_user()['id'] ?? null, [
        'feedId' => $id,
        'title' => $feed['title'] ?? null,
        'itemCount' => count($items),
    ]);

    return [
        'count' => count($items),
        'title' => $feed['title'] ? (string) $feed['title'] : null,
    ];
}

function normalize_feed_ttl(int $ttl): int
{
    if ($ttl <= 0) {
        $ttl = 300;
    }

    return max(60, min(21600, $ttl));
}

function handle_delete_ticker(PDO $pdo): void
{
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException('Geçersiz kayıt.');
    }
    $pdo->prepare('DELETE FROM ticker_items WHERE id = :id')->execute(['id' => $id]);
}
