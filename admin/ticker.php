<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

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
        }
        $settings = load_all_settings();
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$stmt = $pdo->query('SELECT * FROM ticker_items ORDER BY priority DESC, created_at DESC');
$tickers = $stmt->fetchAll();
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

function handle_delete_ticker(PDO $pdo): void
{
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException('Geçersiz kayıt.');
    }
    $pdo->prepare('DELETE FROM ticker_items WHERE id = :id')->execute(['id' => $id]);
}
