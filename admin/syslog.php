<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

require_login();
require_role('super_admin');

$pageTitle = 'Sistem Günlükleri';
$activePage = 'syslog';
$pdo = get_pdo();

$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 200;
if ($limit <= 0 || $limit > 500) {
    $limit = 200;
}

$entries = fetch_syslog_entries($pdo, $limit);

include __DIR__ . '/partials/header.php';
?>
<section class="card">
    <h3>Son Kayıtlar</h3>
    <form method="get" class="inline-form">
        <label for="limit">Gösterilecek kayıt sayısı</label>
        <input type="number" id="limit" name="limit" min="50" max="500" step="50" value="<?= htmlspecialchars((string) $limit) ?>">
        <button class="button secondary" type="submit">Güncelle</button>
    </form>
    <div class="table-scroll">
        <table class="table">
            <thead>
            <tr>
                <th>Tarih</th>
                <th>Kullanıcı</th>
                <th>İşlem</th>
                <th>Detay</th>
                <th>IP</th>
                <th>Bağlam</th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($entries)): ?>
                <tr>
                    <td colspan="6">Kayıt bulunamadı.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($entries as $entry): ?>
                    <tr>
                        <td><?= htmlspecialchars(format_datetime($entry['created_at'], 'd.m.Y H:i:s')) ?></td>
                        <td><?= htmlspecialchars($entry['full_name'] ?? 'Sistem') ?></td>
                        <td><code><?= htmlspecialchars($entry['action']) ?></code></td>
                        <td><?= htmlspecialchars($entry['details'] ?? '') ?></td>
                        <td><?= htmlspecialchars($entry['ip_address'] ?? '-') ?></td>
                        <td>
                            <?php if (!empty($entry['context']) && is_array($entry['context'])): ?>
                                <ul class="context-list">
                                    <?php foreach ($entry['context'] as $key => $value): ?>
                                        <li><strong><?= htmlspecialchars((string) $key) ?>:</strong> <?= htmlspecialchars(is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE)) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php
include __DIR__ . '/partials/footer.php';
