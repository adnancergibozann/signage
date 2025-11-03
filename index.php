<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/signage.php';

$state = fetch_signage_state();
$settings = $state['settings'];
$timestamp = new DateTimeImmutable($state['timestamp']);
$timeFormat = $settings['timeFormat'] ?? '24h';
$timeString = $timeFormat === '12h'
    ? $timestamp->format('h:i A')
    : $timestamp->format('H:i');
$dateString = $timestamp->format('d F Y l');
$primary = htmlspecialchars($settings['primaryColor'], ENT_QUOTES);
$secondary = htmlspecialchars($settings['secondaryColor'], ENT_QUOTES);
$tickerSpeed = (int) ($settings['tickerSpeed'] ?? 35);
$logoUrl = $settings['logoUrl'] ?? null;
$organigramUrl = $settings['organigramUrl'] ?? null;
$baseUri = base_uri();
$placeholderProfile = asset_url('assets/placeholder-profile.svg');
$signageCss = asset_url('assets/css/signage.css?v=1');
$signageJs = asset_url('assets/js/signage.js?v=1');
$globalMeeting = $state['nextMeeting'] ?? null;

$formatMeetingTime = static function (array $meeting) use ($timestamp): string {
    $startRaw = $meeting['scheduledStartIso'] ?? $meeting['scheduledStart'] ?? null;
    if (!$startRaw) {
        return (string) ($meeting['scheduledStart'] ?? '');
    }
    try {
        $start = new DateTimeImmutable((string) $startRaw);
    } catch (Throwable) {
        return (string) $startRaw;
    }

    $timeText = $start->format('H:i');
    if (($meeting['status'] ?? null) === 'in_progress') {
        return 'Şimdi · ' . $timeText;
    }

    $isToday = $meeting['isToday'] ?? null;
    $sameDay = $isToday !== null
        ? (bool) $isToday
        : $start->format('Y-m-d') === $timestamp->format('Y-m-d');

    if ($sameDay) {
        return 'Bugün · ' . $timeText;
    }

    return $start->format('d.m Y · H:i');
};
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Gapgross Signage</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="<?= $signageCss ?>">
    <style>
        :root {
            --gap-primary: <?= $primary ?>;
            --gap-secondary: <?= $secondary ?>;
            --ticker-speed: <?= $tickerSpeed ?>s;
        }
    </style>
</head>
<body>
    <main class="signage-canvas" role="main">
        <section class="header-bar">
            <div class="branding">
                <img src="<?= $logoUrl ? htmlspecialchars($logoUrl, ENT_QUOTES) : $placeholderProfile ?>" alt="Gapgross"<?= $logoUrl ? '' : ' style="display:none"' ?>>
                <h1><?= htmlspecialchars($settings['companyName'] ?? 'Gapgross') ?></h1>
            </div>
            <div class="clock" aria-live="polite">
                <div class="time"><?= htmlspecialchars($timeString) ?></div>
                <div class="date"><?= htmlspecialchars($dateString) ?></div>
            </div>
        </section>

        <section class="main-area">
            <div>
                <div class="next-meeting-board" aria-live="polite">
                    <div class="next-meeting-board__header">Sıradaki Görüşme</div>
                    <?php if ($globalMeeting): ?>
                        <?php
                            $globalStatus = $globalMeeting['status'] ?? '';
                            $globalClass = $globalStatus ? ' ' . htmlspecialchars(str_replace('_', '-', $globalStatus)) : '';
                            $globalLabel = htmlspecialchars($globalMeeting['statusLabel'] ?? 'Planlı Görüşme');
                            $globalManager = $globalMeeting['manager'] ?? null;
                            $managerParts = [];
                            if ($globalManager) {
                                if (!empty($globalManager['name'])) {
                                    $managerParts[] = htmlspecialchars((string) $globalManager['name']);
                                }
                                if (!empty($globalManager['department'])) {
                                    $managerParts[] = htmlspecialchars((string) $globalManager['department']);
                                }
                            }
                            $visitorParts = [];
                            if (!empty($globalMeeting['visitorName'])) {
                                $visitorParts[] = htmlspecialchars((string) $globalMeeting['visitorName']);
                            }
                            if (!empty($globalMeeting['visitorCompany'])) {
                                $visitorParts[] = htmlspecialchars((string) $globalMeeting['visitorCompany']);
                            }
                            $timeText = $formatMeetingTime($globalMeeting);
                            $purposeText = $globalMeeting['purpose'] ?? '';
                            $notesText = $globalMeeting['notes'] ?? '';
                        ?>
                        <div class="next-meeting-card<?= $globalClass ?>">
                            <div class="next-meeting-card__status"><?= $globalLabel ?></div>
                            <?php if ($managerParts): ?>
                                <div class="next-meeting-card__manager"><?= implode(' · ', $managerParts) ?></div>
                            <?php endif; ?>
                            <div class="next-meeting-card__visitor">
                                <?= $visitorParts ? implode(' · ', $visitorParts) : 'Misafir' ?>
                            </div>
                            <?php if ($timeText !== ''): ?>
                                <div class="next-meeting-card__time"><?= htmlspecialchars($timeText) ?></div>
                            <?php endif; ?>
                            <?php if ($purposeText): ?>
                                <div class="next-meeting-card__purpose"><?= htmlspecialchars($purposeText) ?></div>
                            <?php endif; ?>
                            <?php if ($notesText): ?>
                                <div class="next-meeting-card__notes"><?= htmlspecialchars($notesText) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="next-meeting-board__empty">Planlı görüşme bulunmuyor.</div>
                    <?php endif; ?>
                </div>
                <div class="status-board" aria-live="polite">
                    <?php if (empty($state['managers'])): ?>
                        <div class="manager-card">Tanımlı satınalma müdürü bulunamadı.</div>
                    <?php else: ?>
                        <?php foreach ($state['managers'] as $manager): ?>
                            <?php
                                $statusClass = htmlspecialchars($manager['status']);
                                $statusLabel = htmlspecialchars($manager['statusLabel']);
                                $photo = $manager['photoUrl'] ? htmlspecialchars($manager['photoUrl'], ENT_QUOTES) : $placeholderProfile;
                                $note = $manager['note'] ?? null;
                                $remaining = $manager['remainingSeconds'] ?? null;
                                $nextMeeting = $manager['nextMeeting'] ?? null;
                                $cardAttrs = sprintf('class="manager-card %s" data-status-label="%s"', $statusClass, $statusLabel);
                            ?>
                            <article <?= $cardAttrs ?>>
                                <div class="manager-header">
                                    <img class="manager-photo" src="<?= $photo ?>" alt="<?= htmlspecialchars($manager['name']) ?>">
                                    <div class="manager-info">
                                        <h2><?= htmlspecialchars($manager['name']) ?></h2>
                                        <span><?= htmlspecialchars($manager['department'] ?? '') ?></span>
                                    </div>
                                </div>
                                <?php if ($note): ?>
                                    <div class="status-note"><?= htmlspecialchars($note) ?></div>
                                <?php endif; ?>
                                <?php if ($nextMeeting): ?>
                                    <?php
                                        $meetingStatus = $nextMeeting['status'] ?? '';
                                        $meetingClass = $meetingStatus ? ' ' . htmlspecialchars(str_replace('_', '-', $meetingStatus)) : '';
                                        $meetingLabel = htmlspecialchars($nextMeeting['statusLabel'] ?? 'Planlı Görüşme');
                                        $visitorName = htmlspecialchars($nextMeeting['visitorName'] ?? 'Misafir');
                                        $visitorCompany = $nextMeeting['visitorCompany'] ? htmlspecialchars($nextMeeting['visitorCompany']) : null;
                                        $startText = '';
                                        $rawStart = $nextMeeting['scheduledStart'] ?? null;
                                        if ($rawStart) {
                                            try {
                                                $meetingStart = new DateTimeImmutable($rawStart);
                                                if ($meetingStatus === 'in_progress') {
                                                    $startText = 'Şimdi · ' . $meetingStart->format('H:i');
                                                } elseif ($meetingStart->format('Y-m-d') === $timestamp->format('Y-m-d')) {
                                                    $startText = 'Bugün · ' . $meetingStart->format('H:i');
                                                } else {
                                                    $startText = $meetingStart->format('d.m Y · H:i');
                                                }
                                            } catch (Throwable) {
                                                $startText = $rawStart;
                                            }
                                        }
                                        $purpose = $nextMeeting['purpose'] ?? '';
                                        $meetingNote = $nextMeeting['notes'] ?? '';
                                    ?>
                                    <div class="next-meeting<?= $meetingClass ?>">
                                        <div class="next-meeting__label"><?= $meetingLabel ?></div>
                                        <div class="next-meeting__who">
                                            <?= $visitorName ?><?php if ($visitorCompany): ?> · <?= $visitorCompany ?><?php endif; ?>
                                        </div>
                                        <?php if ($startText !== ''): ?>
                                            <div class="next-meeting__time"><?= htmlspecialchars($startText) ?></div>
                                        <?php endif; ?>
                                        <?php if ($purpose): ?>
                                            <div class="next-meeting__purpose"><?= htmlspecialchars($purpose) ?></div>
                                        <?php endif; ?>
                                        <?php if ($meetingNote): ?>
                                            <div class="next-meeting__purpose"><?= htmlspecialchars($meetingNote) ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($remaining !== null): ?>
                                    <div class="countdown">
                                        <?php
                                            $minutes = str_pad((string) intdiv($remaining, 60), 2, '0', STR_PAD_LEFT);
                                            $seconds = str_pad((string) ($remaining % 60), 2, '0', STR_PAD_LEFT);
                                            echo $minutes . ':' . $seconds;
                                        ?>
                                    </div>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="alerts">
                    <?php foreach ($state['alerts'] as $alert): ?>
                        <div class="alert-banner"><?= htmlspecialchars($alert['message']) ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
            <aside class="announcements">
                <h2>Duyurular</h2>
                <div class="announcements-list">
                    <?php if (empty($state['announcements'])): ?>
                        <div class="announcement-item">Aktif duyuru bulunmuyor.</div>
                    <?php else: ?>
                        <?php foreach ($state['announcements'] as $announcement): ?>
                            <div class="announcement-item">
                                <strong><?= htmlspecialchars($announcement['title']) ?></strong>
                                <?php if (!empty($announcement['body'])): ?>
                                    <p><?= htmlspecialchars($announcement['body']) ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <button type="button" class="organigram-button"<?= $organigramUrl ? '' : ' style="display:none"' ?>>Yönetim Şeması</button>
            </aside>
        </section>

        <section class="ticker-bar" aria-label="Kayan yazı">
            <div class="ticker-track">
                <?php
                $tickerItems = $state['ticker'];
                if (empty($tickerItems)) {
                    $tickerItems = ['Gapgross satınalma departmanına hoş geldiniz.'];
                }
                $loopItems = array_merge($tickerItems, $tickerItems);
                foreach ($loopItems as $message): ?>
                    <div class="ticker-item"><?= htmlspecialchars($message) ?></div>
                <?php endforeach; ?>
            </div>
        </section>

        <div class="organigram-modal" id="organigram-modal" role="dialog" aria-modal="true" aria-label="Yönetim Şeması">
            <button type="button" aria-label="Kapat">✕</button>
            <img src="<?= $organigramUrl ? htmlspecialchars($organigramUrl, ENT_QUOTES) : '' ?>" alt="Yönetim şeması">
        </div>

        <div class="fullscreen-media<?= $state['activeMedia'] ? ' active' : '' ?>">
            <?php if ($state['activeMedia']): ?>
                <?php if ($state['activeMedia']['type'] === 'video'): ?>
                    <video src="<?= htmlspecialchars($state['activeMedia']['url'], ENT_QUOTES) ?>" autoplay muted playsinline></video>
                <?php else: ?>
                    <img src="<?= htmlspecialchars($state['activeMedia']['url'], ENT_QUOTES) ?>" alt="<?= htmlspecialchars($state['activeMedia']['title']) ?>">
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>

    <script>
        window.APP_BASE_PATH = <?= json_encode($baseUri, JSON_UNESCAPED_SLASHES) ?>;
        window.APP_PLACEHOLDER_PHOTO = <?= json_encode($placeholderProfile, JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <script src="<?= $signageJs ?>" defer></script>
</body>
</html>
