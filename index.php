<?php

require_once __DIR__ . '/includes/signage.php';

$payload = get_signage_payload();
$data = $payload['data'];
$signageVersion = $payload['version'];
$signageGeneratedAt = $payload['generated_at'];
$messages = array_values(array_filter(
    $data['ticker'],
    static function (array $message): bool {
        return trim((string) ($message['body'] ?? '')) !== '';
    }
));

$marqueeSpeed = $messages ? max(array_column($messages, 'speed')) : 30;
$marqueeSpeed = max(5, min(60, (int) $marqueeSpeed));
$settings = $data['settings'];
$tickerFontFamily = $settings['ticker_font_family'] ?? 'Segoe UI, sans-serif';
$tickerFontSize = isset($settings['ticker_font_size']) ? (int) $settings['ticker_font_size'] : 28;
$tickerBorderWidth = isset($settings['ticker_border_width']) ? (int) $settings['ticker_border_width'] : 2;
$layout = $data['layout'];

$tickerFontSize = max(12, min(96, $tickerFontSize));
$tickerBorderWidth = max(0, min(12, $tickerBorderWidth));
$teachers = $data['teachers'];
$weather = $data['weather'];
$weatherSettings = $data['weather_settings'] ?? ['refresh_interval_minutes' => 0, 'city' => ''];
$weatherRefreshMinutes = isset($weatherSettings['refresh_interval_minutes']) ? (int) $weatherSettings['refresh_interval_minutes'] : 0;
$weatherEndpoint = route_url('public/api/weather.php');
$signagePollEndpoint = route_url('public/api/signage.php');
$signagePollInterval = 15;
$mediaItems = $data['media'];
$scheduleSlides = $data['schedule'];
$countdowns = $data['countdowns'];
$nextPeriod = $data['nextPeriod'];
$periods = $data['periods'];
$timezone = new DateTimeZone(date_default_timezone_get() ?: 'Europe/Istanbul');
$now = new DateTimeImmutable('now', $timezone);
$currentDay = (int) $now->format('N');
$dayNameMap = [
    1 => ['short' => 'Pzt', 'long' => 'Pazartesi'],
    2 => ['short' => 'Sal', 'long' => 'Salı'],
    3 => ['short' => 'Çar', 'long' => 'Çarşamba'],
    4 => ['short' => 'Per', 'long' => 'Perşembe'],
    5 => ['short' => 'Cum', 'long' => 'Cuma'],
    6 => ['short' => 'Cmt', 'long' => 'Cumartesi'],
    7 => ['short' => 'Paz', 'long' => 'Pazar'],
];
$activeDayInfo = $dayNameMap[$currentDay] ?? $dayNameMap[1];
$weatherUpdatedLabel = null;
if ($weather && !empty($weather['fetched_at'])) {
    try {
        $weatherDate = new DateTimeImmutable($weather['fetched_at'], $timezone);
        $weatherUpdatedLabel = $weatherDate->format('H:i');
    } catch (Throwable $e) {
        $weatherUpdatedLabel = $weather['fetched_at'];
    }
}
$periodTimeline = array_map(
    static function (array $period): array {
        $start = $period['start_label'] ?? substr($period['start_time'], 0, 5);
        $end = $period['end_label'] ?? substr($period['end_time'], 0, 5);

        return [
            'period' => $period['period'],
            'label' => $period['label'],
            'start_time' => $start,
            'end_time' => $end,
            'type' => $period['type'] ?? 'lesson',
        ];
    },
    $periods
);
$newsItems = $data['news'];
$currentPeriod = $nextPeriod['current_period'] ?? null;
$currentSummary = '';
if (is_array($currentPeriod)) {
    $label = trim((string) ($currentPeriod['label'] ?? ''));
    $startTime = trim((string) ($currentPeriod['start_time'] ?? ''));
    $endTime = trim((string) ($currentPeriod['end_time'] ?? ''));

    $parts = [];
    if ($label !== '') {
        $parts[] = $label;
    }

    $range = '';
    if ($startTime !== '' && $endTime !== '') {
        $range = $startTime . ' - ' . $endTime;
    } elseif ($startTime !== '') {
        $range = $startTime;
    } elseif ($endTime !== '') {
        $range = $endTime;
    }

    if ($range !== '') {
        $parts[] = '(' . $range . ')';
    }

    if ($parts) {
        $currentSummary = 'Şu an: ' . implode(' ', $parts);
    }
}

function esc_html(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function module_style_attr(array $layout, string $key): string
{
    return htmlspecialchars(module_layout_style($layout, $key), ENT_QUOTES, 'UTF-8');
}

$signageMeta = [
    'endpoint' => $signagePollEndpoint,
    'pollInterval' => $signagePollInterval,
    'version' => $signageVersion,
    'generatedAt' => $signageGeneratedAt,
];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Digital Signage</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/styles.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <style>
        .marquee-track { animation-duration: <?php echo $marqueeSpeed; ?>s; }
    </style>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/signage.css'), ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body class="signage-body">
<div class="signage-stage">
    <header class="module signage-module logo-module" data-module="logo" style="<?php echo module_style_attr($layout, 'logo'); ?>">
        <?php if ($settings['logo_data_url']): ?>
            <img src="<?php echo esc_html($settings['logo_data_url']); ?>" alt="Logo" class="logo-image">
        <?php endif; ?>
        <div>
            <h1 class="organization-name"><?php echo esc_html($settings['organization_name']); ?></h1>
            <p class="module-subtitle">Kampüs Bilgilendirme Ekranı</p>
        </div>
    </header>

    <section class="module signage-module next-period" data-module="next_period" data-next-change="<?php echo esc_html($nextPeriod['next_change_at'] ?? ''); ?>" data-state="<?php echo esc_html($nextPeriod['state']); ?>" style="<?php echo module_style_attr($layout, 'next_period'); ?>">
        <div class="next-period-header">
            <span class="next-period-icon" data-role="next-period-icon" aria-hidden="true">
                <?php
                $stateIcons = [
                    'lesson' => '📚',
                    'break' => '☕',
                    'before-school' => '🌅',
                    'after-school' => '🏠',
                    'unconfigured' => '⚙️',
                ];
                echo $stateIcons[$nextPeriod['state']] ?? 'ℹ️';
                ?>
            </span>
            <div>
                <h2>Sonraki Ders/Teneffüs</h2>
                <p class="next-period-status" data-role="next-period-label"><?php echo esc_html($nextPeriod['label'] ?? ''); ?></p>
                <?php if ($currentSummary !== ''): ?>
                    <p class="next-period-current" data-role="next-period-current"><?php echo esc_html($currentSummary); ?></p>
                <?php else: ?>
                    <p class="next-period-current" data-role="next-period-current" hidden></p>
                <?php endif; ?>
            </div>
        </div>
        <div class="next-period-countdown" data-role="next-period-countdown"<?php echo empty($nextPeriod['timeLeft']) ? ' hidden' : ''; ?>>
            <span class="countdown-value" data-role="next-period-minutes"><?php echo (int) ($nextPeriod['timeLeft']['minutes'] ?? 0); ?></span>
            <span class="countdown-label">Dakika</span>
            <span class="countdown-separator">:</span>
            <span class="countdown-value" data-role="next-period-seconds"><?php echo str_pad((string) ($nextPeriod['timeLeft']['seconds'] ?? 0), 2, '0', STR_PAD_LEFT); ?></span>
            <span class="countdown-label">Saniye</span>
        </div>
        <p class="next-period-message" data-role="next-period-message"<?php echo empty($nextPeriod['message']) ? ' hidden' : ''; ?>><?php echo esc_html($nextPeriod['message'] ?? ''); ?></p>
        <?php if (!empty($nextPeriod['next_period'])): ?>
            <?php
            $upNext = $nextPeriod['next_period'];
            $upNextLabel = $upNext['label'];
            if (($upNext['type'] ?? '') === 'break') {
                $upNextLabel .= ' (Teneffüs)';
            }
            ?>
            <p class="next-period-meta" data-role="next-period-meta">
                Sıradaki: <strong data-role="next-period-meta-label"><?php echo esc_html($upNextLabel); ?></strong>
                (<span data-role="next-period-meta-time"><?php echo esc_html($upNext['starts_at']); ?></span>)
            </p>
        <?php else: ?>
            <p class="next-period-meta" data-role="next-period-meta" hidden>Sıradaki: <strong data-role="next-period-meta-label"></strong> (<span data-role="next-period-meta-time"></span>)</p>
        <?php endif; ?>
    </section>

    <section class="module signage-module teachers-module" data-module="teachers" style="<?php echo module_style_attr($layout, 'teachers'); ?>">
        <h2>Bugünün Nöbetçileri</h2>
        <?php if (!$teachers): ?>
            <p class="module-placeholder">Nöbetçi öğretmen atanmadı.</p>
        <?php else: ?>
            <ul class="teachers-list">
                <?php foreach ($teachers as $teacher): ?>
                    <li class="teacher-card">
                        <?php if ($teacher['photo']): ?>
                            <img src="<?php echo esc_html($teacher['photo']); ?>" alt="<?php echo esc_html($teacher['name']); ?>" class="teacher-avatar">
                        <?php else: ?>
                            <span class="teacher-avatar fallback" aria-hidden="true">
                                <?php echo esc_html(mb_substr($teacher['name'], 0, 1)); ?>
                            </span>
                        <?php endif; ?>
                        <div>
                            <p class="teacher-name"><?php echo esc_html($teacher['name']); ?></p>
                            <p class="teacher-branch"><?php echo esc_html($teacher['branch']); ?></p>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="module signage-module news-module" data-module="news" data-slider="news" data-default-duration="12" style="<?php echo module_style_attr($layout, 'news'); ?>">
        <h2>Güncel Haberler</h2>
        <?php if (!$newsItems): ?>
            <p class="module-placeholder">Haber bulunamadı.</p>
        <?php else: ?>
            <div class="news-slider">
                <?php foreach ($newsItems as $index => $news): ?>
                    <?php
                    $hasThumb = !empty($news['image_url']);
                    $cardClasses = 'news-card' . ($index === 0 ? ' active' : '') . (!$hasThumb ? ' no-thumb' : '');
                    ?>
                    <article class="<?php echo $cardClasses; ?>" data-duration="12">
                        <?php if ($hasThumb): ?>
                            <img src="<?php echo esc_html($news['image_url']); ?>" alt="<?php echo esc_html($news['title']); ?>" class="news-thumb">
                        <?php endif; ?>
                        <div class="news-content">
                            <p class="news-title"><?php echo esc_html($news['title']); ?></p>
                            <?php if (!empty($news['summary'])): ?>
                                <p class="news-summary"><?php echo esc_html($news['summary']); ?></p>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="module signage-module media-module" data-module="media" data-slider="media" style="<?php echo module_style_attr($layout, 'media'); ?>">
        <div class="media-slider">
            <?php if (!$mediaItems): ?>
                <div class="media-slide active">
                    <div class="module-placeholder">Medya eklenmedi.</div>
                </div>
            <?php else: ?>
                <?php foreach ($mediaItems as $index => $item): ?>
                    <div class="media-slide<?php echo $index === 0 ? ' active' : ''; ?>" data-duration="<?php echo (int) $item['duration']; ?>">
                        <div class="media-content media-type-<?php echo esc_html($item['type']); ?>">
                            <?php if ($item['type'] === 'image'): ?>
                                <img src="<?php echo esc_html($item['source']); ?>" alt="<?php echo esc_html($item['title']); ?>">
                            <?php elseif ($item['type'] === 'video'): ?>
                                <?php if (!empty($item['is_local'])): ?>
                                    <video title="<?php echo esc_html($item['title']); ?>" autoplay muted loop playsinline preload="auto">
                                        <source src="<?php echo esc_html($item['source']); ?>"<?php echo !empty($item['mime']) ? ' type="' . esc_html($item['mime']) . '"' : ''; ?>>
                                        Videonuz tarayıcı tarafından desteklenmiyor.
                                    </video>
                                <?php else: ?>
                                    <iframe src="<?php echo esc_html($item['source']); ?>" title="<?php echo esc_html($item['title']); ?>" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
                                <?php endif; ?>
                            <?php elseif ($item['type'] === 'pdf'): ?>
                                <iframe src="<?php echo esc_html($item['source']); ?>" title="<?php echo esc_html($item['title']); ?>"></iframe>
                            <?php else: ?>
                                <p><?php echo esc_html($item['title']); ?></p>
                            <?php endif; ?>
                        </div>
                        <p class="media-title"><?php echo esc_html($item['title']); ?></p>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

    <section class="module signage-module weather-module" data-module="weather" style="<?php echo module_style_attr($layout, 'weather'); ?>">
        <h2>Hava Durumu</h2>
        <div class="weather-content" data-role="weather-content"<?php echo $weather ? '' : ' hidden'; ?>>
            <div class="weather-main">
                <div>
                    <p class="weather-city" data-role="weather-city"><?php echo esc_html($weather['city'] ?? ''); ?></p>
                    <p class="weather-temp" data-role="weather-temp">
                        <?php echo $weather ? esc_html(number_format((float) $weather['temperature'], 1)) : '0.0'; ?>°C
                    </p>
                </div>
                <span class="weather-icon" data-role="weather-icon" aria-hidden="true"<?php echo empty($weather['icon']) ? ' hidden' : ''; ?>><?php echo esc_html($weather['icon'] ?? ''); ?></span>
            </div>
            <ul class="weather-meta">
                <li data-role="weather-condition"><?php echo esc_html($weather['condition'] ?? ''); ?></li>
                <li data-role="weather-feels"<?php echo ($weather && $weather['feels_like'] !== null) ? '' : ' hidden'; ?>>
                    Hissedilen: <?php echo $weather && $weather['feels_like'] !== null ? esc_html(number_format((float) $weather['feels_like'], 1)) : ''; ?>°C
                </li>
                <li data-role="weather-humidity"<?php echo ($weather && $weather['humidity'] !== null) ? '' : ' hidden'; ?>>
                    Nem: %<?php echo $weather && $weather['humidity'] !== null ? esc_html($weather['humidity']) : ''; ?>
                </li>
                <li data-role="weather-wind"<?php echo ($weather && $weather['wind_speed'] !== null) ? '' : ' hidden'; ?>>
                    Rüzgar: <?php echo $weather && $weather['wind_speed'] !== null ? esc_html(number_format((float) $weather['wind_speed'], 1)) : ''; ?> km/sa
                </li>
            </ul>
            <p class="weather-updated" data-role="weather-updated"<?php echo $weatherUpdatedLabel ? '' : ' hidden'; ?>>
                Güncelleme: <span data-role="weather-updated-time"><?php echo esc_html($weatherUpdatedLabel ?? ''); ?></span>
            </p>
        </div>
        <p class="module-placeholder" data-role="weather-placeholder"<?php echo $weather ? ' hidden' : ''; ?>>Hava durumu verisi yok.</p>
    </section>

    <section class="module signage-module schedule-module" data-module="schedule" data-slider="schedule" data-default-duration="10" style="<?php echo module_style_attr($layout, 'schedule'); ?>">
        <div class="schedule-slider">
            <?php if (!$scheduleSlides): ?>
                <div class="schedule-slide active">
                    <p class="module-placeholder">Ders programı tanımlanmadı.</p>
                </div>
            <?php else: ?>
                <?php $dayShort = $activeDayInfo['short']; ?>
                <?php $dayLong = $activeDayInfo['long']; ?>
                <?php foreach ($scheduleSlides as $index => $slide): ?>
                    <?php $dayEntries = $slide['entries'][$currentDay] ?? []; ?>
                    <div class="schedule-slide<?php echo $index === 0 ? ' active' : ''; ?>" data-duration="10">
                        <h3><?php echo esc_html($slide['classroom']); ?></h3>
                        <p class="schedule-day"><?php echo esc_html($dayLong); ?></p>
                        <div class="schedule-scroll" data-role="schedule-scroll">
                            <table>
                                <thead>
                                <tr>
                                    <th>Periyot</th>
                                    <th><?php echo esc_html($dayShort); ?></th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($periods as $period): ?>
                                    <?php
                                    $entry = $dayEntries[$period['period']] ?? null;
                                    $isBreak = $period['type'] === 'break';
                                    ?>
                                    <tr class="<?php echo $isBreak ? 'schedule-row-break' : ''; ?>">
                                        <td>
                                            <span class="period-label"><?php echo esc_html($period['label']); ?></span>
                                            <span class="period-time"><?php echo esc_html(substr($period['start_time'], 0, 5)); ?> - <?php echo esc_html(substr($period['end_time'], 0, 5)); ?></span>
                                        </td>
                                        <td class="<?php echo $isBreak ? 'schedule-cell-break' : ''; ?>">
                                            <?php if ($isBreak): ?>
                                                <span class="schedule-break">Teneffüs</span>
                                            <?php elseif ($entry): ?>
                                                <strong><?php echo esc_html($entry['subject']); ?></strong>
                                                <?php if (!empty($entry['teacher'])): ?>
                                                    <span class="schedule-teacher"><?php echo esc_html($entry['teacher']); ?></span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="schedule-empty">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

    <section class="module signage-module countdown-module" data-module="countdowns" data-slider="countdown" data-default-duration="10" style="<?php echo module_style_attr($layout, 'countdowns'); ?>">
        <h2>Yaklaşan Etkinlikler</h2>
        <?php if (!$countdowns): ?>
            <p class="module-placeholder">Etkinlik bulunamadı.</p>
        <?php else: ?>
            <div class="countdown-slider">
                <?php foreach ($countdowns as $index => $event): ?>
                    <div class="countdown-card<?php echo $index === 0 ? ' active' : ''; ?>" data-duration="10" data-target="<?php echo esc_html((new \DateTimeImmutable($event['target_at']))->format(\DateTimeInterface::ATOM)); ?>">
                        <span class="countdown-icon" aria-hidden="true"><?php echo esc_html($event['icon'] ?: '🎯'); ?></span>
                        <div>
                            <p class="countdown-title"><?php echo esc_html($event['title']); ?></p>
                            <div class="countdown-timer" style="--countdown-color: <?php echo esc_html($event['color']); ?>;">
                                <div class="countdown-segment">
                                    <span class="countdown-value" data-role="countdown-days">0</span>
                                    <span class="countdown-label">Gün</span>
                                </div>
                                <div class="countdown-segment">
                                    <span class="countdown-value" data-role="countdown-hours">00</span>
                                    <span class="countdown-label">Saat</span>
                                </div>
                                <div class="countdown-segment">
                                    <span class="countdown-value" data-role="countdown-minutes">00</span>
                                    <span class="countdown-label">Dakika</span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <footer class="module signage-module ticker-module" data-module="ticker" style="<?php echo module_style_attr($layout, 'ticker'); ?>; --ticker-font-family: <?php echo esc_html($tickerFontFamily); ?>; --ticker-font-size: <?php echo $tickerFontSize; ?>px; --ticker-border-width: <?php echo $tickerBorderWidth; ?>px;">
        <?php if (!$messages): ?>
            <div class="card" style="text-align: center;">Henüz bir içerik eklenmedi. Yönetim panelinden hemen oluşturabilirsin.</div>
        <?php else: ?>
            <div class="marquee">
                <div class="marquee-track">
                    <?php foreach (array_merge($messages, $messages) as $message): ?>
                        <?php $detail = trim((string) ($message['body'] ?? '')); ?>
                        <?php if ($detail === '') { continue; } ?>
                        <div class="marquee-item" style="background: <?php echo esc_html($message['background_color']); ?>; color: <?php echo esc_html($message['text_color']); ?>;">
                            <div class="marquee-text"><?php echo esc_html($detail); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </footer>
</div>
<script>
    window.__SIGNAGE__ = <?php echo json_encode([
        'scheduleDuration' => 10000,
        'periods' => $periodTimeline,
        'nextPeriod' => $nextPeriod,
        'weatherRefreshMinutes' => $weatherRefreshMinutes,
        'weatherEndpoint' => $weatherEndpoint,
        'scheduleCurrentDay' => $currentDay,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    window.__SIGNAGE_META__ = <?php echo json_encode($signageMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
</script>
<script src="<?php echo htmlspecialchars(asset_url('assets/signage.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
</body>
</html>
