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

$calendarAnchor = new DateTimeImmutable($now->format('Y-m-01 00:00:00'));
$calendarRangeStart = $calendarAnchor->modify('-1 month');
$calendarRangeEnd = $calendarAnchor->modify('+3 months')->modify('-1 second');
$calendarMeetingsRaw = fetch_scheduled_meetings($pdo, [
    'manager_id' => $user['id'],
    'from' => $calendarRangeStart,
    'to' => $calendarRangeEnd,
    'status_in' => meeting_status_options(),
    'order' => 'ASC',
]);

$calendarMeetings = array_map(static function (array $meeting): array {
    return [
        'id' => (int) $meeting['id'],
        'visitor' => $meeting['visitor_name'] ?? '',
        'company' => $meeting['visitor_company'] ?? '',
        'purpose' => $meeting['purpose'] ?? '',
        'notes' => $meeting['notes'] ?? '',
        'status' => $meeting['status'],
        'statusLabel' => $meeting['status_label'] ?? meeting_status_label($meeting['status']),
        'start' => $meeting['scheduled_start'],
        'end' => $meeting['scheduled_end'],
    ];
}, $calendarMeetingsRaw);

function render_manager_status_section(?array $managerData): string
{
    if (!$managerData) {
        return '';
    }

    ob_start();
    ?>
    <section class="card">
        <h3>Mevcut Durum</h3>
        <p><strong>Durum:</strong> <?= htmlspecialchars($managerData['statusLabel']) ?></p>
        <p><strong>Not:</strong> <?= htmlspecialchars($managerData['note'] ?? '-') ?></p>
        <?php if ($managerData['remainingSeconds'] !== null): ?>
            <p><strong>Geri Sayım:</strong> <?= format_duration((int) $managerData['remainingSeconds']) ?> sonra</p>
        <?php elseif (!empty($managerData['endsAt'])): ?>
            <p><strong>Durum Bitişi:</strong> <?= htmlspecialchars(format_datetime($managerData['endsAt'], 'd.m.Y H:i')) ?></p>
        <?php endif; ?>
    </section>
    <?php
    return trim((string) ob_get_clean());
}

function render_manager_highlight_section(?array $activeMeeting, ?array $nextHighlightedMeeting): string
{
    if (!$activeMeeting && !$nextHighlightedMeeting) {
        return '';
    }

    ob_start();
    ?>
    <section class="card meeting-highlight">
        <h3>Planlı Görüşme Özeti</h3>
        <div class="meeting-highlight__grid">
            <?php if ($activeMeeting): ?>
                <?php
                    $activeStart = $activeMeeting['scheduled_start'] ? format_datetime($activeMeeting['scheduled_start'], 'd.m H:i') : '-';
                    $activeVisitor = htmlspecialchars($activeMeeting['visitor_name'] ?? '-');
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
                    $nextVisitor = htmlspecialchars($nextHighlightedMeeting['visitor_name'] ?? '-');
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
    <?php
    return trim((string) ob_get_clean());
}

function render_manager_meetings_section(array $upcomingMeetings): string
{
    ob_start();
    ?>
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
                                $visitor = htmlspecialchars($meeting['visitor_name'] ?? '-');
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
    return trim((string) ob_get_clean());
}

if (($_GET['refresh'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'statusHtml' => render_manager_status_section($managerData),
        'highlightHtml' => render_manager_highlight_section($activeMeeting, $nextHighlightedMeeting),
        'meetingsHtml' => render_manager_meetings_section($upcomingMeetings),
        'calendarMeetings' => $calendarMeetings,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

include __DIR__ . '/partials/header.php';
?>
<?php if ($message): ?><div class="alert success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div data-manager-status>
    <?= render_manager_status_section($managerData) ?>
</div>

<div data-manager-highlight>
    <?= render_manager_highlight_section($activeMeeting, $nextHighlightedMeeting) ?>
</div>

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

<div data-manager-meetings>
    <?= render_manager_meetings_section($upcomingMeetings) ?>
</div>

<section class="card">
    <h3>Takvim</h3>
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

<script>
window.managerCalendarMeetings = <?= json_encode($calendarMeetings, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script>
(function () {
    const calendarRoot = document.querySelector('[data-calendar]');
    if (!calendarRoot) {
        window.managerCalendarController = {
            setMeetings() {},
        };
        return;
    }

    const titleEl = calendarRoot.querySelector('[data-calendar-title]');
    const gridEl = calendarRoot.querySelector('[data-calendar-grid]');
    const detailTitleEl = calendarRoot.querySelector('[data-calendar-detail-title]');
    const detailListEl = calendarRoot.querySelector('[data-calendar-detail-list]');
    const prevBtn = calendarRoot.querySelector('[data-calendar-prev]');
    const nextBtn = calendarRoot.querySelector('[data-calendar-next]');
    const todayBtn = calendarRoot.querySelector('[data-calendar-today]');

    const rawMeetings = Array.isArray(window.managerCalendarMeetings) ? window.managerCalendarMeetings : [];

    function normalizeMeetings(source) {
        return source.map((item) => ({
            id: item.id,
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
            month: 'long',
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
            year: 'numeric',
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
        window.managerCalendarMeetings = safeData;
        meetings = normalizeMeetings(safeData);
        renderCalendar();
        renderDetails();
    }

    window.managerCalendarController = {
        setMeetings,
    };

    renderCalendar();
    renderDetails();
})();
</script>

<script>
(function () {
    const refreshUrl = '<?= htmlspecialchars(url_for('admin/manager.php?refresh=1'), ENT_QUOTES, 'UTF-8') ?>';
    const statusContainer = document.querySelector('[data-manager-status]');
    const highlightContainer = document.querySelector('[data-manager-highlight]');
    const meetingsContainer = document.querySelector('[data-manager-meetings]');
    const refreshIntervalMs = 15000;
    let refreshTimer = null;

    function updateSection(container, html) {
        if (!container || typeof html !== 'string') {
            return;
        }
        const trimmed = html.trim();
        if (container.innerHTML.trim() === trimmed) {
            return;
        }
        container.innerHTML = trimmed;
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
            updateSection(statusContainer, payload.statusHtml ?? '');
            updateSection(highlightContainer, payload.highlightHtml ?? '');
            updateSection(meetingsContainer, payload.meetingsHtml ?? '');
            if (payload.calendarMeetings && window.managerCalendarController) {
                window.managerCalendarController.setMeetings(payload.calendarMeetings);
            }
        } catch (error) {
            console.error('Panel verileri güncellenemedi:', error);
        } finally {
            refreshTimer = window.setTimeout(performRefresh, refreshIntervalMs);
        }
    }

    refreshTimer = window.setTimeout(performRefresh, refreshIntervalMs);
})();
</script>

<?php
include __DIR__ . '/partials/footer.php';
