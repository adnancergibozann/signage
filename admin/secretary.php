<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/signage.php';
require_once __DIR__ . '/../includes/status.php';

require_login();
require_role(['super_admin', 'secretary']);

$pageTitle = 'Sekreter Paneli';
$activePage = 'secretary';
$pdo = get_pdo();
$message = null;
$error = null;

$settings = load_all_settings();
$currentUser = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action !== '') {
        try {
            switch ($action) {
                case 'create_meeting':
                    $managerId = (int) ($_POST['manager_id'] ?? 0);
                    if ($managerId <= 0) {
                        throw new RuntimeException('Lütfen bir yönetici seçiniz.');
                    }

                    $stmt = $pdo->prepare('SELECT id, full_name, role FROM users WHERE id = :id');
                    $stmt->execute(['id' => $managerId]);
                    $managerRow = $stmt->fetch();
                    if (!$managerRow || !is_manager_role($managerRow['role'])) {
                        throw new RuntimeException('Seçilen kullanıcı yönetici değil.');
                    }

                    $visitorName = trim($_POST['visitor_name'] ?? '');
                    if ($visitorName === '') {
                        throw new RuntimeException('Ziyaretçi adı zorunludur.');
                    }

                    $visitorCompany = trim($_POST['visitor_company'] ?? '');
                    $purpose = trim($_POST['purpose'] ?? '');
                    $notes = trim($_POST['notes'] ?? '');

                    $startInput = $_POST['scheduled_start'] ?? '';
                    $startString = parse_datetime_local($startInput ?? '');
                    if (!$startString) {
                        throw new RuntimeException('Geçerli bir başlangıç tarihi ve saati giriniz.');
                    }
                    $scheduledStart = new DateTimeImmutable($startString);

                    $endInput = $_POST['scheduled_end'] ?? '';
                    $scheduledEnd = null;
                    if ($endInput !== '') {
                        $endString = parse_datetime_local($endInput);
                        if (!$endString) {
                            throw new RuntimeException('Geçerli bir bitiş tarihi ve saati giriniz.');
                        }
                        $scheduledEnd = new DateTimeImmutable($endString);
                        if ($scheduledEnd < $scheduledStart) {
                            throw new RuntimeException('Bitiş saati başlangıç saatinden önce olamaz.');
                        }
                    }

                    $meetingId = create_scheduled_meeting(
                        $pdo,
                        $managerId,
                        $visitorName,
                        $visitorCompany !== '' ? $visitorCompany : null,
                        $purpose !== '' ? $purpose : null,
                        $scheduledStart,
                        $scheduledEnd,
                        $notes !== '' ? $notes : null,
                        $currentUser['id'] ?? null
                    );

                    $message = sprintf('%s için planlı görüşme eklendi.', $managerRow['full_name']);

                    record_syslog('secretary.meeting.create', 'Sekreter planlı bir görüşme oluşturdu.', null, [
                        'meetingId' => $meetingId,
                        'managerId' => $managerId,
                        'scheduledStart' => $scheduledStart->format(DateTimeInterface::ATOM),
                        'scheduledEnd' => $scheduledEnd?->format(DateTimeInterface::ATOM),
                    ]);
                    break;

                case 'update_meeting_status':
                    $meetingId = (int) ($_POST['meeting_id'] ?? 0);
                    $status = $_POST['status'] ?? '';
                    if ($meetingId <= 0) {
                        throw new RuntimeException('Geçersiz görüşme kaydı.');
                    }
                    if (!in_array($status, meeting_status_options(), true)) {
                        throw new RuntimeException('Geçersiz görüşme durumu.');
                    }

                    $stmt = $pdo->prepare('SELECT m.id, m.status, u.full_name
                        FROM scheduled_meetings m
                        INNER JOIN users u ON u.id = m.manager_id
                        WHERE m.id = :id');
                    $stmt->execute(['id' => $meetingId]);
                    $meeting = $stmt->fetch();
                    if (!$meeting) {
                        throw new RuntimeException('Planlı görüşme bulunamadı.');
                    }

                    update_scheduled_meeting_status($pdo, $meetingId, $status, $currentUser['id'] ?? null);
                    $message = 'Görüşme durumu güncellendi.';

                    record_syslog('secretary.meeting.status', 'Sekreter planlı görüşme durumunu değiştirdi.', null, [
                        'meetingId' => $meetingId,
                        'previousStatus' => $meeting['status'],
                        'newStatus' => $status,
                    ]);
                    break;

                case 'delete_meeting':
                    $meetingId = (int) ($_POST['meeting_id'] ?? 0);
                    if ($meetingId <= 0) {
                        throw new RuntimeException('Geçersiz görüşme kaydı.');
                    }
                    $stmt = $pdo->prepare('SELECT m.id, u.full_name FROM scheduled_meetings m INNER JOIN users u ON u.id = m.manager_id WHERE m.id = :id');
                    $stmt->execute(['id' => $meetingId]);
                    $meeting = $stmt->fetch();
                    if (!$meeting) {
                        throw new RuntimeException('Planlı görüşme bulunamadı.');
                    }

                    delete_scheduled_meeting($pdo, $meetingId);
                    $message = 'Planlı görüşme silindi.';

                    record_syslog('secretary.meeting.delete', 'Sekreter planlı görüşmeyi sildi.', null, [
                        'meetingId' => $meetingId,
                        'managerName' => $meeting['full_name'],
                    ]);
                    break;

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

                case 'update_ticker_settings':
                    $fontSize = isset($_POST['ticker_font_size']) ? (int) $_POST['ticker_font_size'] : 24;
                    $bandHeight = isset($_POST['ticker_band_height']) ? (int) $_POST['ticker_band_height'] : 70;
                    $fontSize = max(12, min(96, $fontSize));
                    $bandHeight = max(40, min(240, $bandHeight));
                    set_setting('ticker_font_size', (string) $fontSize);
                    set_setting('ticker_band_height', (string) $bandHeight);
                    $message = 'Kayan yazı ayarları güncellendi.';
                    record_syslog('secretary.ticker.settings', 'Sekreter kayan yazı görünümünü güncelledi.', $currentUser['id'] ?? null, [
                        'fontSize' => $fontSize,
                        'bandHeight' => $bandHeight,
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

$managerRoleKeys = manager_role_keys();
$managerOptionsStmt = $pdo->prepare('SELECT id, full_name, department, role FROM users WHERE role IN (' . implode(',', array_fill(0, count($managerRoleKeys), '?')) . ') ORDER BY full_name');
$managerOptionsStmt->execute($managerRoleKeys);
$managerOptions = $managerOptionsStmt->fetchAll();

$upcomingMeetings = fetch_scheduled_meetings($pdo, [
    'status_in' => ['planned', 'in_progress'],
    'from' => $now->modify('-1 day'),
    'order' => 'ASC',
    'limit' => 100,
]);

$recentMeetings = fetch_scheduled_meetings($pdo, [
    'status_in' => ['completed', 'cancelled'],
    'order' => 'DESC',
    'limit' => 50,
]);

$tickerStmt = $pdo->query('SELECT id, message, priority, is_active, starts_at, ends_at, created_at FROM ticker_items ORDER BY created_at DESC LIMIT 20');
$tickers = $tickerStmt->fetchAll();

$announcementStmt = $pdo->query('SELECT id, title, body, priority, is_active, starts_at, ends_at, created_at FROM announcements ORDER BY created_at DESC LIMIT 20');
$announcements = $announcementStmt->fetchAll();

$calendarAnchor = new DateTimeImmutable($now->format('Y-m-01 00:00:00'));
$calendarRangeStart = $calendarAnchor->modify('-1 month');
$calendarRangeEnd = $calendarAnchor->modify('+3 months')->modify('-1 second');
$calendarMeetingsRaw = fetch_scheduled_meetings($pdo, [
    'from' => $calendarRangeStart,
    'to' => $calendarRangeEnd,
    'order' => 'ASC',
]);
$calendarMeetings = array_map(static function (array $meeting): array {
    $startIso = null;
    if (!empty($meeting['scheduled_start'])) {
        $startIso = (new DateTimeImmutable($meeting['scheduled_start']))->format(DateTimeInterface::ATOM);
    }

    $endIso = null;
    if (!empty($meeting['scheduled_end'])) {
        $endIso = (new DateTimeImmutable($meeting['scheduled_end']))->format(DateTimeInterface::ATOM);
    }

    return [
        'id' => (int) $meeting['id'],
        'manager' => $meeting['manager_name'],
        'managerDepartment' => $meeting['manager_department'],
        'visitor' => $meeting['visitor_name'],
        'company' => $meeting['visitor_company'],
        'purpose' => $meeting['purpose'],
        'notes' => $meeting['notes'],
        'status' => $meeting['status'],
        'statusLabel' => $meeting['status_label'],
        'start' => $startIso,
        'end' => $endIso,
    ];
}, $calendarMeetingsRaw);

function render_secretary_upcoming_block(array $meetings): string
{
    ob_start();
    if (!$meetings) {
        echo '<p>Planlanmış yaklaşan görüşme bulunmuyor.</p>';
    } else {
        ?>
        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr>
                        <th>Başlangıç</th>
                        <th>Bitiş</th>
                        <th>Yönetici</th>
                        <th>Misafir</th>
                        <th>Konu / Not</th>
                        <th>Durum</th>
                        <th>İşlemler</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($meetings as $meeting): ?>
                        <?php
                            $startLabel = $meeting['scheduled_start'] ? format_datetime($meeting['scheduled_start'], 'd.m H:i') : '-';
                            $endLabel = $meeting['scheduled_end'] ? format_datetime($meeting['scheduled_end'], 'H:i') : '-';
                            $visitorLine = htmlspecialchars($meeting['visitor_name'] ?? '-');
                            if (!empty($meeting['visitor_company'])) {
                                $visitorLine .= ' · ' . htmlspecialchars($meeting['visitor_company']);
                            }
                            $purposeParts = [];
                            if (!empty($meeting['purpose'])) {
                                $purposeParts[] = htmlspecialchars($meeting['purpose']);
                            }
                            if (!empty($meeting['notes'])) {
                                $purposeParts[] = nl2br(htmlspecialchars($meeting['notes']));
                            }
                        ?>
                        <tr>
                            <td><?= $startLabel ?></td>
                            <td><?= $endLabel ?></td>
                            <td>
                                <strong><?= htmlspecialchars($meeting['manager_name']) ?></strong>
                                <?php if (!empty($meeting['manager_department'])): ?>
                                    <div class="table-subtext"><?= htmlspecialchars($meeting['manager_department']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= $visitorLine ?></td>
                            <td>
                                <?php if ($purposeParts): ?>
                                    <?php foreach ($purposeParts as $line): ?>
                                        <div><?= $line ?></div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><span class="badge"><?= htmlspecialchars($meeting['status_label']) ?></span></td>
                            <td>
                                <div class="table-actions">
                                    <form method="post">
                                        <input type="hidden" name="action" value="update_meeting_status">
                                        <input type="hidden" name="meeting_id" value="<?= (int) $meeting['id'] ?>">
                                        <select name="status">
                                            <?php foreach (meeting_status_options() as $value): ?>
                                                <option value="<?= htmlspecialchars($value) ?>" <?= $value === $meeting['status'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars(meeting_status_label($value)) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="button secondary" type="submit">Kaydet</button>
                                    </form>
                                    <form method="post" onsubmit="return confirm('Bu görüşme silinsin mi?');">
                                        <input type="hidden" name="action" value="delete_meeting">
                                        <input type="hidden" name="meeting_id" value="<?= (int) $meeting['id'] ?>">
                                        <button class="button danger" type="submit">Sil</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    return trim((string) ob_get_clean());
}

function render_secretary_recent_block(array $meetings): string
{
    ob_start();
    if (!$meetings) {
        echo '<p>Henüz sonuçlanan veya iptal edilen görüşme yok.</p>';
    } else {
        ?>
        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr>
                        <th>Başlangıç</th>
                        <th>Bitiş</th>
                        <th>Yönetici</th>
                        <th>Misafir</th>
                        <th>Durum</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($meetings as $meeting): ?>
                        <?php
                            $startLabel = $meeting['scheduled_start'] ? format_datetime($meeting['scheduled_start'], 'd.m H:i') : '-';
                            $endLabel = $meeting['scheduled_end'] ? format_datetime($meeting['scheduled_end'], 'H:i') : '-';
                            $visitorLine = htmlspecialchars($meeting['visitor_name'] ?? '-');
                            if (!empty($meeting['visitor_company'])) {
                                $visitorLine .= ' · ' . htmlspecialchars($meeting['visitor_company']);
                            }
                        ?>
                        <tr>
                            <td><?= $startLabel ?></td>
                            <td><?= $endLabel ?></td>
                            <td>
                                <strong><?= htmlspecialchars($meeting['manager_name']) ?></strong>
                                <?php if (!empty($meeting['manager_department'])): ?>
                                    <div class="table-subtext"><?= htmlspecialchars($meeting['manager_department']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= $visitorLine ?></td>
                            <td><span class="badge"><?= htmlspecialchars($meeting['status_label']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    return trim((string) ob_get_clean());
}

function render_secretary_status_rows(array $managers): string
{
    ob_start();
    if (!$managers) {
        ?>
        <tr>
            <td colspan="7">Tanımlı yönetici bulunmuyor.</td>
        </tr>
        <?php
    } else {
        foreach ($managers as $manager) {
            ?>
            <tr>
                <td><input type="checkbox" name="selected_managers[]" value="<?= (int) $manager['id'] ?>"></td>
                <td>
                    <?= htmlspecialchars($manager['name']) ?><br>
                    <small><?= htmlspecialchars(role_label($manager['role'])) ?></small>
                </td>
                <td><?= htmlspecialchars($manager['department'] ?? '') ?></td>
                <td><?= $manager['displayOrder'] !== null ? htmlspecialchars((string) $manager['displayOrder']) : '-' ?></td>
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
            <?php
        }
    }

    return trim((string) ob_get_clean());
}

function render_secretary_ticker_block(array $tickers): string
{
    ob_start();
    if (!$tickers) {
        echo '<p>Henüz kayan yazı eklenmemiş.</p>';
    } else {
        ?>
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
                                    <form method="post" onsubmit="return confirm('Bu kayıt silinsin mi?');">
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
        <?php
    }

    return trim((string) ob_get_clean());
}

function render_secretary_announcement_block(array $announcements): string
{
    ob_start();
    if (!$announcements) {
        echo '<p>Henüz duyuru eklenmemiş.</p>';
    } else {
        ?>
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
                                    <div class="table-subtext">
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
        <?php
    }

    return trim((string) ob_get_clean());
}

if (($_GET['refresh'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'upcomingHtml' => render_secretary_upcoming_block($upcomingMeetings),
        'recentHtml' => render_secretary_recent_block($recentMeetings),
        'statusRowsHtml' => render_secretary_status_rows($managers),
        'tickerHtml' => render_secretary_ticker_block($tickers),
        'announcementsHtml' => render_secretary_announcement_block($announcements),
        'calendarMeetings' => $calendarMeetings,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

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

<nav class="secretary-menu" aria-label="Sekreter paneli menüsü">
    <button type="button" class="menu-link active" data-target="agenda">Ajanda</button>
    <button type="button" class="menu-link" data-target="meetings">Planlama</button>
    <button type="button" class="menu-link" data-target="status">Durumlar</button>
    <button type="button" class="menu-link" data-target="lunch">Yemek Molası</button>
    <button type="button" class="menu-link" data-target="ticker">Kayan Yazı</button>
    <button type="button" class="menu-link" data-target="announcements">Duyurular</button>
</nav>

<div class="panel-section active" data-section="agenda">
    <section class="card">
        <h3>Ajanda Takvimi</h3>
        <div class="calendar-layout" data-calendar>
            <div class="calendar-main">
                <div class="calendar-header">
                    <button type="button" class="calendar-nav" data-calendar-prev aria-label="Önceki ay">‹</button>
                    <div class="calendar-title" data-calendar-title></div>
                    <button type="button" class="calendar-nav" data-calendar-next aria-label="Sonraki ay">›</button>
                </div>
                <div class="calendar-weekdays">
                    <span>Pzt</span>
                    <span>Sal</span>
                    <span>Çar</span>
                    <span>Per</span>
                    <span>Cum</span>
                    <span>Cmt</span>
                    <span>Paz</span>
                </div>
                <div class="calendar-grid" data-calendar-grid></div>
                <div class="calendar-footer">
                    <button type="button" class="calendar-today" data-calendar-today>Bugüne Git</button>
                </div>
            </div>
            <aside class="calendar-details">
                <h4 data-calendar-detail-title>Gün seçiniz</h4>
                <div class="calendar-detail-list" data-calendar-detail-list>
                    <p class="calendar-empty">Görüntülemek için takvimden bir gün seçiniz.</p>
                </div>
            </aside>
        </div>
    </section>
</div>

<div class="panel-section" data-section="meetings">
    <section class="card">
        <h3>Planlı Görüşme Oluştur</h3>
        <?php if (!$managerOptions): ?>
            <p>Planlama yapabilmek için önce yönetici tanımlamalısınız.</p>
        <?php else: ?>
            <form method="post" autocomplete="off">
                <input type="hidden" name="action" value="create_meeting">
                <div class="form-grid">
                    <div>
                        <label for="meeting_manager_id">Yönetici</label>
                        <select id="meeting_manager_id" name="manager_id" required>
                            <option value="">Seçiniz</option>
                            <?php foreach ($managerOptions as $managerOption): ?>
                                <option value="<?= (int) $managerOption['id'] ?>">
                                    <?= htmlspecialchars($managerOption['full_name']) ?>
                                    <?php if (!empty($managerOption['department'])): ?>
                                        (<?= htmlspecialchars($managerOption['department']) ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="meeting_visitor_name">Ziyaretçi Adı</label>
                        <input type="text" id="meeting_visitor_name" name="visitor_name" required>
                    </div>
                    <div>
                        <label for="meeting_visitor_company">Firma</label>
                        <input type="text" id="meeting_visitor_company" name="visitor_company" placeholder="Opsiyonel">
                    </div>
                    <div>
                        <label for="meeting_purpose">Konu</label>
                        <input type="text" id="meeting_purpose" name="purpose" placeholder="Örn. Ürün sunumu">
                    </div>
                    <div>
                        <label for="meeting_start">Başlangıç</label>
                        <input type="datetime-local" id="meeting_start" name="scheduled_start" required>
                    </div>
                    <div>
                        <label for="meeting_end">Bitiş</label>
                        <input type="datetime-local" id="meeting_end" name="scheduled_end">
                    </div>
                    <div style="grid-column: 1 / -1;">
                        <label for="meeting_notes">Not</label>
                        <textarea id="meeting_notes" name="notes" rows="2" placeholder="Opsiyonel ek bilgiler"></textarea>
                    </div>
                </div>
                <div class="actions">
                    <button class="button" type="submit">Görüşmeyi Planla</button>
                </div>
            </form>
        <?php endif; ?>
    </section>

    <section class="card">
        <h3>Yaklaşan Görüşmeler</h3>
        <div data-secretary-upcoming>
            <?= render_secretary_upcoming_block($upcomingMeetings) ?>
        </div>
    </section>

    <section class="card">
        <h3>Son İşlemler</h3>
        <div data-secretary-recent>
            <?= render_secretary_recent_block($recentMeetings) ?>
        </div>
    </section>
</div>

<div class="panel-section" data-section="status">
    <section class="card">
        <h3>Toplu Durum Güncelleme</h3>
        <p>Listeden bir veya birden fazla yöneticiyi seçip yeni durum, süre (dakika) ve kısa not belirleyebilirsiniz.</p>
        <form method="post">
            <input type="hidden" name="action" value="bulk_status">
            <div class="table-scroll">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Seç</th>
                            <th>Ad</th>
                            <th>Departman</th>
                            <th>Ekran Sırası</th>
                            <th>Durum</th>
                            <th>Not</th>
                            <th>Kalan Süre</th>
                        </tr>
                    </thead>
                    <tbody data-secretary-status-body>
                        <?= render_secretary_status_rows($managers) ?>
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
    </section>
</div>

<div class="panel-section" data-section="lunch">
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
</div>

<div class="panel-section" data-section="ticker">
    <section class="card">
        <h3>Kayan Yazı Ayarları</h3>
        <form method="post">
            <input type="hidden" name="action" value="update_ticker_settings">
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
            <p class="form-help">Değerler signage ekranındaki kayan yazı alanına anında yansır.</p>
            <div class="actions">
                <button class="button" type="submit">Ayarları Kaydet</button>
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
        <div data-secretary-ticker>
            <?= render_secretary_ticker_block($tickers) ?>
        </div>
    </section>
</div>

<div class="panel-section" data-section="announcements">
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
        <div data-secretary-announcements>
            <?= render_secretary_announcement_block($announcements) ?>
        </div>
    </section>
</div>

<script>
window.secretaryCalendarMeetings = <?= json_encode($calendarMeetings, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script>
(function () {
    const sections = document.querySelectorAll('.panel-section');
    const menuButtons = document.querySelectorAll('.secretary-menu .menu-link');

    function activateSection(key) {
        sections.forEach((section) => {
            if (section.dataset.section === key) {
                section.classList.add('active');
            } else {
                section.classList.remove('active');
            }
        });
        menuButtons.forEach((button) => {
            if (button.dataset.target === key) {
                button.classList.add('active');
                button.setAttribute('aria-current', 'page');
            } else {
                button.classList.remove('active');
                button.removeAttribute('aria-current');
            }
        });
    }

    menuButtons.forEach((button) => {
        button.addEventListener('click', () => {
            activateSection(button.dataset.target);
        });
    });

    const calendarRoot = document.querySelector('[data-calendar]');
    if (!calendarRoot) {
        window.secretaryCalendarController = {
            setMeetings() {},
        };
        activateSection('agenda');
        return;
    }

    const titleEl = calendarRoot.querySelector('[data-calendar-title]');
    const gridEl = calendarRoot.querySelector('[data-calendar-grid]');
    const detailTitleEl = calendarRoot.querySelector('[data-calendar-detail-title]');
    const detailListEl = calendarRoot.querySelector('[data-calendar-detail-list]');
    const prevBtn = calendarRoot.querySelector('[data-calendar-prev]');
    const nextBtn = calendarRoot.querySelector('[data-calendar-next]');
    const todayBtn = calendarRoot.querySelector('[data-calendar-today]');

    const rawMeetings = Array.isArray(window.secretaryCalendarMeetings) ? window.secretaryCalendarMeetings : [];

    function normalizeMeetings(source) {
        return source.map((item) => ({
            id: item.id,
            manager: item.manager,
            managerDepartment: item.managerDepartment,
            visitor: item.visitor,
            company: item.company,
            purpose: item.purpose,
            notes: item.notes,
            status: item.status,
            statusLabel: item.statusLabel,
            start: item.start ? new Date(item.start) : null,
            end: item.end ? new Date(item.end) : null,
        }));
    }

    let meetings = normalizeMeetings(rawMeetings);

    let currentMonth = new Date();
    currentMonth.setDate(1);
    let selectedDate = new Date();
    selectedDate.setHours(0, 0, 0, 0);

    function isSameDay(a, b) {
        return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
    }

    function getDayRange(date) {
        const start = new Date(date.getFullYear(), date.getMonth(), date.getDate(), 0, 0, 0, 0);
        const end = new Date(date.getFullYear(), date.getMonth(), date.getDate(), 23, 59, 59, 999);
        return { start, end };
    }

    function eventsForDate(date) {
        const range = getDayRange(date);
        return meetings.filter((meeting) => {
            if (!meeting.start) {
                return false;
            }
            const start = meeting.start;
            const end = meeting.end || meeting.start;
            return start <= range.end && end >= range.start;
        });
    }

    let visibleCells = [];

    function renderDetails() {
        if (!detailTitleEl || !detailListEl) {
            return;
        }

        const formatter = new Intl.DateTimeFormat('tr-TR', {
            weekday: 'long',
            day: 'numeric',
            month: 'long'
        });
        detailTitleEl.textContent = formatter.format(selectedDate);

        const dayEvents = eventsForDate(selectedDate).sort((a, b) => {
            const aTime = a.start ? a.start.getTime() : 0;
            const bTime = b.start ? b.start.getTime() : 0;
            return aTime - bTime;
        });

        detailListEl.innerHTML = '';
        if (!dayEvents.length) {
            const empty = document.createElement('p');
            empty.className = 'calendar-empty';
            empty.textContent = 'Bu gün için planlı görüşme bulunmuyor.';
            detailListEl.appendChild(empty);
            return;
        }

        dayEvents.forEach((event) => {
            const item = document.createElement('article');
            item.className = 'calendar-event';

            const timeParts = [];
            if (event.start) {
                timeParts.push(event.start.toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit' }));
            }
            if (event.end) {
                timeParts.push(event.end.toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit' }));
            }

            item.innerHTML = `
                <header>
                    <div class="calendar-event-time">${timeParts.join(' - ')}</div>
                    <div class="calendar-event-status">${event.statusLabel || ''}</div>
                </header>
                <div class="calendar-event-body">
                    <strong>${event.visitor ? event.visitor : 'Misafir bilgisi yok'}</strong>
                    ${event.company ? `<div class="calendar-event-sub">${event.company}</div>` : ''}
                    <div class="calendar-event-meta">${event.manager || ''}${event.managerDepartment ? ` · ${event.managerDepartment}` : ''}</div>
                    ${event.purpose ? `<div class="calendar-event-note">${event.purpose}</div>` : ''}
                    ${event.notes ? `<div class="calendar-event-note">${event.notes}</div>` : ''}
                </div>
            `;

            detailListEl.appendChild(item);
        });
    }

    function updateSelection() {
        visibleCells.forEach((entry) => {
            if (isSameDay(entry.date, selectedDate)) {
                entry.element.classList.add('is-selected');
            } else {
                entry.element.classList.remove('is-selected');
            }
        });
    }

    function renderCalendar() {
        if (!titleEl || !gridEl) {
            return;
        }

        const formatter = new Intl.DateTimeFormat('tr-TR', {
            month: 'long',
            year: 'numeric'
        });
        titleEl.textContent = formatter.format(currentMonth);

        gridEl.innerHTML = '';
        visibleCells = [];

        const firstDay = new Date(currentMonth);
        const startOffset = (firstDay.getDay() + 6) % 7;
        for (let i = 0; i < startOffset; i += 1) {
            const filler = document.createElement('div');
            filler.className = 'calendar-day filler';
            gridEl.appendChild(filler);
        }

        const daysInMonth = new Date(currentMonth.getFullYear(), currentMonth.getMonth() + 1, 0).getDate();
        const today = new Date();
        today.setHours(0, 0, 0, 0);

        for (let day = 1; day <= daysInMonth; day += 1) {
            const cellDate = new Date(currentMonth.getFullYear(), currentMonth.getMonth(), day);
            const cell = document.createElement('button');
            cell.type = 'button';
            cell.className = 'calendar-day';
            cell.innerHTML = `<span class="calendar-day-number">${day}</span>`;

            const dayEvents = eventsForDate(cellDate);
            if (dayEvents.length) {
                cell.classList.add('has-meetings');
                cell.innerHTML += `<span class="calendar-day-count">${dayEvents.length}</span>`;
            }

            if (isSameDay(cellDate, today)) {
                cell.classList.add('is-today');
            }

            cell.addEventListener('click', () => {
                selectedDate = new Date(cellDate.getFullYear(), cellDate.getMonth(), cellDate.getDate());
                updateSelection();
                renderDetails();
            });

            gridEl.appendChild(cell);
            visibleCells.push({ date: cellDate, element: cell });
        }

        updateSelection();
    }

    prevBtn?.addEventListener('click', () => {
        currentMonth = new Date(currentMonth.getFullYear(), currentMonth.getMonth() - 1, 1);
        renderCalendar();
        updateSelection();
        renderDetails();
    });

    nextBtn?.addEventListener('click', () => {
        currentMonth = new Date(currentMonth.getFullYear(), currentMonth.getMonth() + 1, 1);
        renderCalendar();
        updateSelection();
        renderDetails();
    });

    todayBtn?.addEventListener('click', () => {
        const today = new Date();
        currentMonth = new Date(today.getFullYear(), today.getMonth(), 1);
        selectedDate = new Date(today.getFullYear(), today.getMonth(), today.getDate());
        renderCalendar();
        updateSelection();
        renderDetails();
    });

    function setMeetings(newData) {
        const safeData = Array.isArray(newData) ? newData : [];
        window.secretaryCalendarMeetings = safeData;
        meetings = normalizeMeetings(safeData);
        renderCalendar();
        renderDetails();
    }

    window.secretaryCalendarController = {
        setMeetings,
    };

    renderCalendar();
    renderDetails();
    activateSection('agenda');
})();
</script>

<script>
(function () {
    const refreshUrl = '<?= htmlspecialchars(url_for('admin/secretary.php?refresh=1'), ENT_QUOTES, 'UTF-8') ?>';
    const upcomingContainer = document.querySelector('[data-secretary-upcoming]');
    const recentContainer = document.querySelector('[data-secretary-recent]');
    const tickerContainer = document.querySelector('[data-secretary-ticker]');
    const announcementContainer = document.querySelector('[data-secretary-announcements]');
    const statusBody = document.querySelector('[data-secretary-status-body]');
    const refreshIntervalMs = 15000;
    let refreshTimer = null;

    function updateContainer(container, html) {
        if (!container || typeof html !== 'string') {
            return;
        }
        const trimmed = html.trim();
        if (container.innerHTML.trim() === trimmed) {
            return;
        }
        container.innerHTML = trimmed;
    }

    function updateStatusRows(html) {
        if (!statusBody || typeof html !== 'string') {
            return;
        }
        const selected = Array.from(statusBody.querySelectorAll('input[type="checkbox"]:checked'))
            .map((input) => input.value);
        statusBody.innerHTML = html.trim();
        if (selected.length) {
            selected.forEach((value) => {
                const checkbox = statusBody.querySelector(`input[type="checkbox"][value="${value}"]`);
                if (checkbox) {
                    checkbox.checked = true;
                }
            });
        }
    }

    async function performRefresh() {
        try {
            const response = await fetch(refreshUrl, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            const payload = await response.json();
            updateContainer(upcomingContainer, payload.upcomingHtml ?? '');
            updateContainer(recentContainer, payload.recentHtml ?? '');
            updateContainer(tickerContainer, payload.tickerHtml ?? '');
            updateContainer(announcementContainer, payload.announcementsHtml ?? '');
            if (payload.statusRowsHtml !== undefined) {
                updateStatusRows(payload.statusRowsHtml);
            }
            if (payload.calendarMeetings && window.secretaryCalendarController) {
                window.secretaryCalendarController.setMeetings(payload.calendarMeetings);
            }
        } catch (error) {
            console.error('Sekreter paneli yenilenemedi:', error);
        } finally {
            refreshTimer = window.setTimeout(performRefresh, refreshIntervalMs);
        }
    }

    refreshTimer = window.setTimeout(performRefresh, refreshIntervalMs);
})();
</script>

<?php
include __DIR__ . '/partials/footer.php';
