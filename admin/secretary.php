<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/signage.php';
require __DIR__ . '/../includes/status.php';

require_login();
require_role(['super_admin', 'secretary']);

$pageTitle = 'Sekreter Paneli';
$activePage = 'secretary';
$pdo = get_pdo();
$message = null;
$error = null;

$settings = load_all_settings();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action !== '') {
        try {
            switch ($action) {
                case 'bulk_status':
                    $selected = $_POST['selected_managers'] ?? [];
                    if (!is_array($selected)) {
                        $selected = [];
                    }
                    $selected = array_values(array_unique(array_map('intval', $selected)));
                    $selected = array_filter($selected, static fn (int $id): bool => $id > 0);
                    if (!$selected) {
                        throw new RuntimeException('Lütfen en az bir yönetici seçiniz.');
                    }

                    $status = $_POST['status'] ?? 'available';
                    $duration = isset($_POST['duration']) ? max(0, (int) $_POST['duration']) : null;
                    if ($duration === 0) {
                        $duration = null;
                    }
                    $note = trim($_POST['note'] ?? '');

                    $placeholders = implode(',', array_fill(0, count($selected), '?'));
                    $stmt = $pdo->prepare("SELECT id, full_name, role FROM users WHERE id IN ($placeholders)");
                    $stmt->execute($selected);
                    $rows = $stmt->fetchAll();
                    if (count($rows) !== count($selected)) {
                        throw new RuntimeException('Seçilen yöneticilerden bazıları bulunamadı.');
                    }

                    $updated = [];
                    $skipped = [];
                    foreach ($rows as $row) {
                        if (!is_manager_role($row['role'])) {
                            $skipped[] = $row['full_name'];
                            continue;
                        }
                        $allowed = status_options_for_role($row['role']);
                        if (!in_array($status, $allowed, true)) {
                            $skipped[] = $row['full_name'];
                            continue;
                        }
                        update_manager_status(
                            $pdo,
                            (int) $row['id'],
                            $status,
                            null,
                            $note !== '' ? $note : null,
                            $duration
                        );
                        $updated[] = ['id' => (int) $row['id'], 'name' => $row['full_name']];
                    }

                    if (!$updated) {
                        throw new RuntimeException('Seçilen yöneticiler için durum güncellenemedi.');
                    }

                    $messageParts = [];
                    $messageParts[] = sprintf(
                        '%s durumuna %d yönetici taşındı.',
                        map_status_label($status),
                        count($updated)
                    );
                    if ($skipped) {
                        $messageParts[] = 'Atlananlar: ' . implode(', ', $skipped) . '.';
                    }
                    $message = implode(' ', $messageParts);

                    record_syslog('secretary.bulk_status', 'Sekreter toplu durum güncellemesi yaptı.', null, [
                        'status' => $status,
                        'durationMinutes' => $duration,
                        'note' => $note !== '' ? $note : null,
                        'updatedManagerIds' => array_map(static fn (array $item): int => $item['id'], $updated),
                        'skippedManagers' => $skipped,
                    ]);
                    break;

                case 'update_lunch':
                    $startInput = $_POST['lunch_notice_start'] ?? '';
                    $endInput = $_POST['lunch_notice_end'] ?? '';
                    $start = $startInput === '' ? null : extract_time_component($startInput);
                    $end = $endInput === '' ? null : extract_time_component($endInput);

                    if ($startInput !== '' && !$start) {
                        throw new RuntimeException('Yemek molası başlangıç saati geçersiz.');
                    }
                    if ($endInput !== '' && !$end) {
                        throw new RuntimeException('Yemek molası bitiş saati geçersiz.');
                    }

                    set_setting('lunch_notice_start', $start ?? '');
                    set_setting('lunch_notice_end', $end ?? '');
                    $message = 'Yemek molası saatleri güncellendi.';

                    record_syslog('secretary.update_lunch', 'Sekreter yemek molası saatlerini güncelledi.', null, [
                        'start' => $start,
                        'end' => $end,
                    ]);
                    break;

                case 'create_ticker':
                    $tickerMessage = trim($_POST['message'] ?? '');
                    if ($tickerMessage === '') {
                        throw new RuntimeException('Kayan yazı mesajı boş olamaz.');
                    }
                    $priority = (int) ($_POST['priority'] ?? 1);
                    $isActive = (int) ($_POST['is_active'] ?? 1);
                    $startsAt = parse_datetime_local($_POST['starts_at'] ?? '') ?: null;
                    $endsAt = parse_datetime_local($_POST['ends_at'] ?? '') ?: null;

                    $stmt = $pdo->prepare('INSERT INTO ticker_items (message, priority, is_active, starts_at, ends_at) VALUES (:message, :priority, :is_active, :starts_at, :ends_at)');
                    $stmt->execute([
                        'message' => $tickerMessage,
                        'priority' => $priority,
                        'is_active' => $isActive,
                        'starts_at' => $startsAt,
                        'ends_at' => $endsAt,
                    ]);
                    $tickerId = (int) $pdo->lastInsertId();
                    $message = 'Kayan yazı eklendi.';

                    record_syslog('secretary.ticker.create', 'Sekreter yeni kayan yazı ekledi.', null, [
                        'tickerId' => $tickerId,
                        'priority' => $priority,
                        'isActive' => (bool) $isActive,
                    ]);
                    break;

                case 'toggle_ticker':
                    $tickerId = (int) ($_POST['id'] ?? 0);
                    if ($tickerId <= 0) {
                        throw new RuntimeException('Geçersiz kayan yazı.');
                    }
                    $targetState = (int) ($_POST['is_active'] ?? 0);
                    $stmt = $pdo->prepare('UPDATE ticker_items SET is_active = :state WHERE id = :id');
                    $stmt->execute([
                        'state' => $targetState,
                        'id' => $tickerId,
                    ]);
                    $message = $targetState ? 'Kayan yazı aktifleştirildi.' : 'Kayan yazı pasifleştirildi.';

                    record_syslog('secretary.ticker.toggle', 'Sekreter kayan yazı durumunu değiştirdi.', null, [
                        'tickerId' => $tickerId,
                        'isActive' => (bool) $targetState,
                    ]);
                    break;

                case 'delete_ticker':
                    $tickerId = (int) ($_POST['id'] ?? 0);
                    if ($tickerId <= 0) {
                        throw new RuntimeException('Geçersiz kayan yazı.');
                    }
                    $stmt = $pdo->prepare('DELETE FROM ticker_items WHERE id = :id');
                    $stmt->execute(['id' => $tickerId]);
                    if ($stmt->rowCount() === 0) {
                        throw new RuntimeException('Kayan yazı bulunamadı.');
                    }
                    $message = 'Kayan yazı silindi.';

                    record_syslog('secretary.ticker.delete', 'Sekreter bir kayan yazıyı sildi.', null, [
                        'tickerId' => $tickerId,
                    ]);
                    break;

                case 'create_announcement':
                    $title = trim($_POST['title'] ?? '');
                    if ($title === '') {
                        throw new RuntimeException('Duyuru başlığı zorunludur.');
                    }
                    $body = trim($_POST['body'] ?? '');
                    $priority = (int) ($_POST['priority'] ?? 1);
                    $isActive = (int) ($_POST['is_active'] ?? 1);
                    $startsAt = parse_datetime_local($_POST['starts_at'] ?? '') ?: null;
                    $endsAt = parse_datetime_local($_POST['ends_at'] ?? '') ?: null;

                    $stmt = $pdo->prepare('INSERT INTO announcements (title, body, priority, is_active, starts_at, ends_at) VALUES (:title, :body, :priority, :is_active, :starts_at, :ends_at)');
                    $stmt->execute([
                        'title' => $title,
                        'body' => $body,
                        'priority' => $priority,
                        'is_active' => $isActive,
                        'starts_at' => $startsAt,
                        'ends_at' => $endsAt,
                    ]);
                    $announcementId = (int) $pdo->lastInsertId();
                    $message = 'Duyuru kaydedildi.';

                    record_syslog('secretary.announcement.create', 'Sekreter yeni duyuru oluşturdu.', null, [
                        'announcementId' => $announcementId,
                        'priority' => $priority,
                        'isActive' => (bool) $isActive,
                    ]);
                    break;

                case 'toggle_announcement':
                    $announcementId = (int) ($_POST['id'] ?? 0);
                    if ($announcementId <= 0) {
                        throw new RuntimeException('Geçersiz duyuru.');
                    }
                    $targetState = (int) ($_POST['is_active'] ?? 0);
                    $stmt = $pdo->prepare('UPDATE announcements SET is_active = :state WHERE id = :id');
                    $stmt->execute([
                        'state' => $targetState,
                        'id' => $announcementId,
                    ]);
                    $message = $targetState ? 'Duyuru aktifleştirildi.' : 'Duyuru pasifleştirildi.';

                    record_syslog('secretary.announcement.toggle', 'Sekreter bir duyurunun durumunu değiştirdi.', null, [
                        'announcementId' => $announcementId,
                        'isActive' => (bool) $targetState,
                    ]);
                    break;

                case 'delete_announcement':
                    $announcementId = (int) ($_POST['id'] ?? 0);
                    if ($announcementId <= 0) {
                        throw new RuntimeException('Geçersiz duyuru.');
                    }
                    $stmt = $pdo->prepare('DELETE FROM announcements WHERE id = :id');
                    $stmt->execute(['id' => $announcementId]);
                    if ($stmt->rowCount() === 0) {
                        throw new RuntimeException('Duyuru bulunamadı.');
                    }
                    $message = 'Duyuru silindi.';

                    record_syslog('secretary.announcement.delete', 'Sekreter bir duyuruyu sildi.', null, [
                        'announcementId' => $announcementId,
                    ]);
                    break;

                default:
                    throw new RuntimeException('Geçersiz işlem.');
            }

            $settings = load_all_settings();
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$now = new DateTimeImmutable();
$managers = fetch_managers_with_status($pdo, $now, $settings);
$statusChoices = [];
foreach (manager_role_keys() as $roleKey) {
    foreach (status_options_for_role($roleKey) as $option) {
        if (!array_key_exists($option, $statusChoices)) {
            $statusChoices[$option] = map_status_label($option);
        }
    }
}

$tickerStmt = $pdo->query('SELECT id, message, priority, is_active, starts_at, ends_at, created_at FROM ticker_items ORDER BY created_at DESC LIMIT 20');
$tickers = $tickerStmt->fetchAll();

$announcementStmt = $pdo->query('SELECT id, title, body, priority, is_active, starts_at, ends_at, created_at FROM announcements ORDER BY created_at DESC LIMIT 20');
$announcements = $announcementStmt->fetchAll();

include __DIR__ . '/partials/header.php';
?>
<?php if ($message): ?><div class="alert success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<section class="card">
    <h3>Toplu Durum Güncelleme</h3>
    <p>Listeden bir veya birden fazla yöneticiyi seçip yeni durum, süre (dakika) ve kısa not belirleyebilirsiniz.</p>
    <?php if (!$managers): ?>
        <p>Tanımlı yönetici bulunmuyor.</p>
    <?php else: ?>
        <form method="post">
            <input type="hidden" name="action" value="bulk_status">
            <div class="table-scroll">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Seç</th>
                            <th>Ad</th>
                            <th>Departman</th>
                            <th>Durum</th>
                            <th>Not</th>
                            <th>Kalan Süre</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($managers as $manager): ?>
                            <tr>
                                <td><input type="checkbox" name="selected_managers[]" value="<?= (int) $manager['id'] ?>"></td>
                                <td>
                                    <?= htmlspecialchars($manager['name']) ?><br>
                                    <small><?= htmlspecialchars(role_label($manager['role'])) ?></small>
                                </td>
                                <td><?= htmlspecialchars($manager['department'] ?? '') ?></td>
                                <td><span class="badge"><?= htmlspecialchars($manager['statusLabel']) ?></span></td>
                                <td><?= htmlspecialchars($manager['note'] ?? '') ?></td>
                                <td>
                                    <?php if ($manager['remainingSeconds'] !== null): ?>
                                        <?= format_duration((int) $manager['remainingSeconds']) ?>
                                    <?php elseif (!empty($manager['endsAt'])): ?>
                                        <?= htmlspecialchars(format_datetime($manager['endsAt'], 'd.m H:i')) ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="form-grid" style="margin-top: 18px;">
                <div>
                    <label for="bulk_status">Yeni Durum</label>
                    <select id="bulk_status" name="status" required>
                        <?php foreach ($statusChoices as $value => $label): ?>
                            <option value="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="bulk_duration">Süre (dk)</label>
                    <input type="number" id="bulk_duration" name="duration" min="0" step="5" placeholder="ör. 30">
                </div>
                <div>
                    <label for="bulk_note">Not</label>
                    <input type="text" id="bulk_note" name="note" placeholder="İsteğe bağlı not">
                </div>
            </div>
            <div class="actions">
                <button class="button" type="submit">Durumları Güncelle</button>
            </div>
        </form>
    <?php endif; ?>
</section>

<section class="card">
    <h3>Yemek Molası Saatleri</h3>
    <form method="post">
        <input type="hidden" name="action" value="update_lunch">
        <div class="form-grid">
            <div>
                <label for="lunch_notice_start">Başlangıç</label>
                <input type="time" id="lunch_notice_start" name="lunch_notice_start" value="<?= htmlspecialchars(extract_time_component($settings['lunch_notice_start'] ?? '') ?? '') ?>">
            </div>
            <div>
                <label for="lunch_notice_end">Bitiş</label>
                <input type="time" id="lunch_notice_end" name="lunch_notice_end" value="<?= htmlspecialchars(extract_time_component($settings['lunch_notice_end'] ?? '') ?? '') ?>">
            </div>
        </div>
        <div class="actions">
            <button class="button" type="submit">Saatleri Kaydet</button>
        </div>
    </form>
</section>

<section class="card">
    <h3>Kayan Yazı Girişi</h3>
    <form method="post">
        <input type="hidden" name="action" value="create_ticker">
        <div class="form-grid">
            <div style="grid-column: 1 / -1;">
                <label for="ticker_message">Mesaj</label>
                <textarea id="ticker_message" name="message" required></textarea>
            </div>
            <div>
                <label for="ticker_priority">Öncelik</label>
                <input type="number" id="ticker_priority" name="priority" value="1" min="1">
            </div>
            <div>
                <label for="ticker_is_active">Durum</label>
                <select id="ticker_is_active" name="is_active">
                    <option value="1">Aktif</option>
                    <option value="0">Pasif</option>
                </select>
            </div>
            <div>
                <label for="ticker_starts_at">Başlangıç</label>
                <input type="datetime-local" id="ticker_starts_at" name="starts_at">
            </div>
            <div>
                <label for="ticker_ends_at">Bitiş</label>
                <input type="datetime-local" id="ticker_ends_at" name="ends_at">
            </div>
        </div>
        <div class="actions">
            <button class="button" type="submit">Kayan Yazı Ekle</button>
        </div>
    </form>
</section>

<section class="card">
    <h3>Mevcut Kayan Yazılar</h3>
    <?php if (!$tickers): ?>
        <p>Henüz kayan yazı eklenmemiş.</p>
    <?php else: ?>
        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr>
                        <th>Mesaj</th>
                        <th>Öncelik</th>
                        <th>Durum</th>
                        <th>Başlangıç</th>
                        <th>Bitiş</th>
                        <th>İşlemler</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tickers as $item): ?>
                        <tr>
                            <td><?= htmlspecialchars($item['message']) ?></td>
                            <td><?= (int) $item['priority'] ?></td>
                            <td><span class="badge"><?= $item['is_active'] ? 'Aktif' : 'Pasif' ?></span></td>
                            <td><?= $item['starts_at'] ? htmlspecialchars(format_datetime($item['starts_at'], 'd.m H:i')) : '-' ?></td>
                            <td><?= $item['ends_at'] ? htmlspecialchars(format_datetime($item['ends_at'], 'd.m H:i')) : '-' ?></td>
                            <td>
                                <div class="table-actions">
                                    <form method="post">
                                        <input type="hidden" name="action" value="toggle_ticker">
                                        <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                        <input type="hidden" name="is_active" value="<?= $item['is_active'] ? '0' : '1' ?>">
                                        <button class="button secondary" type="submit"><?= $item['is_active'] ? 'Pasifleştir' : 'Aktifleştir' ?></button>
                                    </form>
                                    <form method="post" onsubmit="return confirm('Bu kayan yazı silinsin mi?');">
                                        <input type="hidden" name="action" value="delete_ticker">
                                        <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                        <button class="button danger" type="submit">Sil</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <h3>Duyuru Girişi</h3>
    <form method="post">
        <input type="hidden" name="action" value="create_announcement">
        <div class="form-grid">
            <div>
                <label for="announcement_title">Başlık</label>
                <input type="text" id="announcement_title" name="title" required>
            </div>
            <div style="grid-column: 1 / -1;">
                <label for="announcement_body">İçerik</label>
                <textarea id="announcement_body" name="body"></textarea>
            </div>
            <div>
                <label for="announcement_priority">Öncelik</label>
                <input type="number" id="announcement_priority" name="priority" value="1" min="1">
            </div>
            <div>
                <label for="announcement_is_active">Durum</label>
                <select id="announcement_is_active" name="is_active">
                    <option value="1">Aktif</option>
                    <option value="0">Pasif</option>
                </select>
            </div>
            <div>
                <label for="announcement_starts_at">Başlangıç</label>
                <input type="datetime-local" id="announcement_starts_at" name="starts_at">
            </div>
            <div>
                <label for="announcement_ends_at">Bitiş</label>
                <input type="datetime-local" id="announcement_ends_at" name="ends_at">
            </div>
        </div>
        <div class="actions">
            <button class="button" type="submit">Duyuru Kaydet</button>
        </div>
    </form>
</section>

<section class="card">
    <h3>Mevcut Duyurular</h3>
    <?php if (!$announcements): ?>
        <p>Henüz duyuru eklenmemiş.</p>
    <?php else: ?>
        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr>
                        <th>Başlık</th>
                        <th>Öncelik</th>
                        <th>Durum</th>
                        <th>Başlangıç</th>
                        <th>Bitiş</th>
                        <th>İşlemler</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($announcements as $item): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($item['title']) ?></strong>
                                <?php if (!empty($item['body'])): ?>
                                    <div style="margin-top: 4px; font-size: 0.9rem; color: var(--gap-muted);">
                                        <?= nl2br(htmlspecialchars($item['body'])) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?= (int) $item['priority'] ?></td>
                            <td><span class="badge"><?= $item['is_active'] ? 'Aktif' : 'Pasif' ?></span></td>
                            <td><?= $item['starts_at'] ? htmlspecialchars(format_datetime($item['starts_at'], 'd.m H:i')) : '-' ?></td>
                            <td><?= $item['ends_at'] ? htmlspecialchars(format_datetime($item['ends_at'], 'd.m H:i')) : '-' ?></td>
                            <td>
                                <div class="table-actions">
                                    <form method="post">
                                        <input type="hidden" name="action" value="toggle_announcement">
                                        <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                        <input type="hidden" name="is_active" value="<?= $item['is_active'] ? '0' : '1' ?>">
                                        <button class="button secondary" type="submit"><?= $item['is_active'] ? 'Pasifleştir' : 'Aktifleştir' ?></button>
                                    </form>
                                    <form method="post" onsubmit="return confirm('Bu duyuru silinsin mi?');">
                                        <input type="hidden" name="action" value="delete_announcement">
                                        <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                        <button class="button danger" type="submit">Sil</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php
include __DIR__ . '/partials/footer.php';
