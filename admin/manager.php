<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/signage.php';
require_once __DIR__ . '/../includes/status.php';
require_once __DIR__ . '/../includes/meetings.php';

require_login();
require_role(manager_role_keys());

$pageTitle = 'Durum Yönetimim';
$activePage = 'manager';
$pdo = get_pdo();
$settings = load_all_settings();
$user = current_user();
$statusOptions = status_options_for_role($user['role']);
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'update_status';
    try {
        switch ($action) {
            case 'start_meeting':
                $meetingId = (int) ($_POST['meeting_id'] ?? 0);
                if ($meetingId <= 0) {
                    throw new RuntimeException('Geçersiz toplantı seçildi.');
                }
                $meeting = find_scheduled_meeting($pdo, $meetingId);
                if (!$meeting || (int) $meeting['manager_id'] !== (int) $user['id']) {
                    throw new RuntimeException('Bu toplantıyı yönetme yetkiniz yok.');
                }
                if ($meeting['status'] === 'in_progress') {
                    $message = 'Görüşme zaten devam ediyor.';
                    break;
                }
                if ($meeting['status'] !== 'planned') {
                    throw new RuntimeException('Bu toplantı başlatılamaz.');
                }

                update_scheduled_meeting_status($pdo, $meetingId, 'in_progress', (int) $user['id']);

                $noteParts = [];
                if (!empty($meeting['visitor_name'])) {
                    $noteParts[] = $meeting['visitor_name'];
                }
                if (!empty($meeting['visitor_company'])) {
                    $noteParts[] = $meeting['visitor_company'];
                }
                $meetingNote = $noteParts ? 'Planlı görüşme: ' . implode(' · ', $noteParts) : 'Planlı görüşme';

                $expectedEnd = null;
                if (!empty($meeting['scheduled_end'])) {
                    try {
                        $expectedEndCandidate = new DateTimeImmutable((string) $meeting['scheduled_end']);
                        $expectedEnd = $expectedEndCandidate;
                    } catch (Throwable) {
                        $expectedEnd = null;
                    }
                }

                update_manager_status($pdo, $user['id'], 'meeting', $expectedEnd, $meetingNote, null);

                record_syslog('manager.meeting.start', sprintf('%s planlı görüşmeyi başlattı.', $user['full_name']), (int) $user['id'], [
                    'meetingId' => $meetingId,
                    'visitor' => $meeting['visitor_name'],
                ]);
                $message = 'Görüşme başlatıldı.';
                break;

            case 'finish_meeting':
                $meetingId = (int) ($_POST['meeting_id'] ?? 0);
                if ($meetingId <= 0) {
                    throw new RuntimeException('Geçersiz toplantı seçildi.');
                }
                $meeting = find_scheduled_meeting($pdo, $meetingId);
                if (!$meeting || (int) $meeting['manager_id'] !== (int) $user['id']) {
                    throw new RuntimeException('Bu toplantıyı yönetme yetkiniz yok.');
                }
                if ($meeting['status'] === 'completed') {
                    $message = 'Görüşme zaten tamamlanmış.';
                    break;
                }
                if ($meeting['status'] !== 'in_progress') {
                    throw new RuntimeException('Bu toplantı şu anda devam etmiyor.');
                }

                update_scheduled_meeting_status($pdo, $meetingId, 'completed', (int) $user['id']);

                $statusStmt = $pdo->prepare('SELECT status FROM manager_statuses WHERE user_id = :id LIMIT 1');
                $statusStmt->execute(['id' => $user['id']]);
                $currentStatusRow = $statusStmt->fetch();
                if ($currentStatusRow && $currentStatusRow['status'] === 'meeting') {
                    update_manager_status($pdo, $user['id'], 'available', null, null, null);
                }

                record_syslog('manager.meeting.finish', sprintf('%s planlı görüşmeyi tamamladı.', $user['full_name']), (int) $user['id'], [
                    'meetingId' => $meetingId,
                    'visitor' => $meeting['visitor_name'],
                ]);
                $message = 'Görüşme tamamlandı.';
                break;

            case 'update_status':
            default:
                $status = $_POST['status'] ?? 'available';
                if (!in_array($status, $statusOptions, true)) {
                    throw new RuntimeException('Geçersiz durum seçildi.');
                }
                $durationInput = isset($_POST['duration']) ? (int) $_POST['duration'] : 0;
                $duration = $durationInput > 0 ? $durationInput : null;
                $note = trim($_POST['note'] ?? '');
                update_manager_status($pdo, $user['id'], $status, null, $note ?: null, $duration);
                record_syslog('status.self_update', sprintf('%s durumu %s olarak güncellendi.', $user['full_name'], map_status_label($status)), (int) $user['id'], [
                    'status' => $status,
                    'durationMinutes' => $duration,
                ]);
                $message = 'Durum güncellendi.';
                break;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$now = new DateTimeImmutable();
$managerData = null;
foreach (fetch_managers_with_status($pdo, $now, $settings) as $manager) {
    if ($manager['id'] === $user['id']) {
        $managerData = $manager;
        break;
    }
}

$upcomingMeetings = fetch_scheduled_meetings($pdo, [
    'manager_id' => $user['id'],
    'status_in' => ['planned', 'in_progress'],
    'from' => $now,
    'order' => 'ASC',
    'limit' => 10,
]);

$activeMeeting = null;
$nextSameDayMeeting = null;
$nextMeetingFallback = null;
$todayString = $now->format('Y-m-d');

foreach ($upcomingMeetings as $candidate) {
    $startDate = null;
    $isToday = false;
    if (!empty($candidate['scheduled_start'])) {
        try {
            $startDate = new DateTimeImmutable((string) $candidate['scheduled_start']);
            $isToday = $startDate->format('Y-m-d') === $todayString;
        } catch (Throwable) {
            $isToday = false;
        }
    }

    if ($candidate['status'] === 'in_progress' && !$activeMeeting) {
        $candidate['is_today'] = $isToday;
        $activeMeeting = $candidate;
        continue;
    }

    if ($candidate['status'] === 'planned') {
        if ($isToday && !$nextSameDayMeeting) {
            $candidate['is_today'] = true;
            $nextSameDayMeeting = $candidate;
        }
        if (!$nextMeetingFallback) {
            $candidate['is_today'] = $isToday;
            $nextMeetingFallback = $candidate;
        }
    }
}

$nextHighlightedMeeting = $nextSameDayMeeting ?? $nextMeetingFallback;

include __DIR__ . '/partials/header.php';
?>
<?php if ($message): ?><div class="alert success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if ($managerData): ?>
<section class="card">
    <h3>Mevcut Durum</h3>
    <p><strong>Durum:</strong> <?= htmlspecialchars($managerData['statusLabel']) ?></p>
    <p><strong>Not:</strong> <?= htmlspecialchars($managerData['note'] ?? '-') ?></p>
    <?php if ($managerData['remainingSeconds'] !== null): ?>
        <p><strong>Geri Sayım:</strong> <?= format_duration((int) $managerData['remainingSeconds']) ?> sonra</p>
    <?php elseif ($managerData['endsAt']): ?>
        <p><strong>Durum Bitişi:</strong> <?= format_datetime($managerData['endsAt'], 'd.m.Y H:i') ?></p>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($activeMeeting || $nextHighlightedMeeting): ?>
<section class="card meeting-highlight">
    <h3>Planlı Görüşme Özeti</h3>
    <div class="meeting-highlight__grid">
        <?php if ($activeMeeting): ?>
            <?php
                $activeStart = $activeMeeting['scheduled_start'] ? format_datetime($activeMeeting['scheduled_start'], 'd.m H:i') : '-';
                $activeVisitor = htmlspecialchars($activeMeeting['visitor_name']);
                if (!empty($activeMeeting['visitor_company'])) {
                    $activeVisitor .= ' · ' . htmlspecialchars($activeMeeting['visitor_company']);
                }
            ?>
            <div class="meeting-highlight__item is-current">
                <div class="meeting-highlight__header">
                    <h4>Aktif Görüşme</h4>
                    <span class="badge">Devam Ediyor</span>
                </div>
                <p class="meeting-highlight__time">Başlangıç: <?= $activeStart ?></p>
                <p class="meeting-highlight__visitor"><?= $activeVisitor ?></p>
                <?php if (!empty($activeMeeting['purpose'])): ?>
                    <p class="meeting-highlight__purpose">Konu: <?= htmlspecialchars($activeMeeting['purpose']) ?></p>
                <?php endif; ?>
                <?php if (!empty($activeMeeting['notes'])): ?>
                    <p class="meeting-highlight__notes">Not: <?= nl2br(htmlspecialchars($activeMeeting['notes'])) ?></p>
                <?php endif; ?>
                <form method="post" class="meeting-highlight__actions">
                    <input type="hidden" name="action" value="finish_meeting">
                    <input type="hidden" name="meeting_id" value="<?= (int) $activeMeeting['id'] ?>">
                    <button class="button small" type="submit">Görüşmeyi Bitir</button>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($nextHighlightedMeeting): ?>
            <?php
                $nextStart = $nextHighlightedMeeting['scheduled_start'] ? format_datetime($nextHighlightedMeeting['scheduled_start'], 'd.m H:i') : '-';
                $nextVisitor = htmlspecialchars($nextHighlightedMeeting['visitor_name']);
                if (!empty($nextHighlightedMeeting['visitor_company'])) {
                    $nextVisitor .= ' · ' . htmlspecialchars($nextHighlightedMeeting['visitor_company']);
                }
                $isTodayLabel = !empty($nextHighlightedMeeting['is_today']) ? ' (Bugün)' : '';
            ?>
            <div class="meeting-highlight__item is-next">
                <div class="meeting-highlight__header">
                    <h4>Sıradaki Görüşme<?= $isTodayLabel ?></h4>
                    <span class="badge">Planlandı</span>
                </div>
                <p class="meeting-highlight__time">Başlangıç: <?= $nextStart ?></p>
                <p class="meeting-highlight__visitor"><?= $nextVisitor ?></p>
                <?php if (!empty($nextHighlightedMeeting['purpose'])): ?>
                    <p class="meeting-highlight__purpose">Konu: <?= htmlspecialchars($nextHighlightedMeeting['purpose']) ?></p>
                <?php endif; ?>
                <?php if (!empty($nextHighlightedMeeting['notes'])): ?>
                    <p class="meeting-highlight__notes">Not: <?= nl2br(htmlspecialchars($nextHighlightedMeeting['notes'])) ?></p>
                <?php endif; ?>
                <?php if ($nextHighlightedMeeting['status'] === 'planned'): ?>
                    <form method="post" class="meeting-highlight__actions">
                        <input type="hidden" name="action" value="start_meeting">
                        <input type="hidden" name="meeting_id" value="<?= (int) $nextHighlightedMeeting['id'] ?>">
                        <button class="button small secondary" type="submit">Görüşmeyi Başlat</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<section class="card">
    <h3>Durumumu Güncelle</h3>
    <form method="post" autocomplete="off">
        <input type="hidden" name="action" value="update_status">
        <div class="form-grid">
            <div>
                <label>Durum Seçimi</label>
                <?php $currentStatus = $managerData['status'] ?? ($statusOptions[0] ?? 'available'); ?>
                <select name="status" required>
                    <?php foreach ($statusOptions as $statusOption): ?>
                        <option value="<?= htmlspecialchars($statusOption) ?>"<?= $currentStatus === $statusOption ? ' selected' : '' ?>><?= htmlspecialchars(map_status_label($statusOption)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Süre (dk)</label>
                <?php $defaultDuration = isset($managerData['remainingSeconds']) && $managerData['remainingSeconds'] !== null
                    ? (int) ceil($managerData['remainingSeconds'] / 60)
                    : null; ?>
                <input type="number" name="duration" min="0" step="5" placeholder="Opsiyonel" value="<?= $defaultDuration ? htmlspecialchars((string) $defaultDuration) : '' ?>">
            </div>
            <div>
                <label>Not</label>
                <input type="text" name="note" value="<?= htmlspecialchars($managerData['note'] ?? '') ?>" placeholder="Kısa not">
            </div>
        </div>
        <p class="form-help">Süre girilirse durum belirtilen dakika sonunda otomatik olarak tamamlanır.</p>
        <div class="actions">
            <button class="button" type="submit">Durumu Kaydet</button>
        </div>
    </form>
</section>

<section class="card">
    <h3>Planlı Görüşmelerim</h3>
    <?php if (!$upcomingMeetings): ?>
        <p>Yaklaşan planlı görüşmeniz bulunmuyor.</p>
    <?php else: ?>
        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr>
                        <th>Başlangıç</th>
                        <th>Durum</th>
                        <th>Misafir</th>
                        <th>Konu / Not</th>
                        <th>İşlem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($upcomingMeetings as $meeting): ?>
                        <?php
                            $startLabel = $meeting['scheduled_start'] ? format_datetime($meeting['scheduled_start'], 'd.m H:i') : '-';
                            $visitor = htmlspecialchars($meeting['visitor_name']);
                            if (!empty($meeting['visitor_company'])) {
                                $visitor .= ' · ' . htmlspecialchars($meeting['visitor_company']);
                            }
                            $details = [];
                            if (!empty($meeting['purpose'])) {
                                $details[] = htmlspecialchars($meeting['purpose']);
                            }
                            if (!empty($meeting['notes'])) {
                                $details[] = nl2br(htmlspecialchars($meeting['notes']));
                            }
                        ?>
                        <tr>
                            <td><?= $startLabel ?></td>
                            <td><span class="badge"><?= htmlspecialchars($meeting['status_label']) ?></span></td>
                            <td><?= $visitor ?></td>
                            <td>
                                <?php if ($details): ?>
                                    <?php foreach ($details as $line): ?>
                                        <div><?= $line ?></div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="table-actions">
                                    <?php if ($meeting['status'] === 'planned'): ?>
                                        <form method="post">
                                            <input type="hidden" name="action" value="start_meeting">
                                            <input type="hidden" name="meeting_id" value="<?= (int) $meeting['id'] ?>">
                                            <button class="button small secondary" type="submit">Başlat</button>
                                        </form>
                                    <?php elseif ($meeting['status'] === 'in_progress'): ?>
                                        <form method="post">
                                            <input type="hidden" name="action" value="finish_meeting">
                                            <input type="hidden" name="meeting_id" value="<?= (int) $meeting['id'] ?>">
                                            <button class="button small" type="submit">Bitir</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="table-subtext">-</span>
                                    <?php endif; ?>
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
