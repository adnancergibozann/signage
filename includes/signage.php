<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/messages.php';
require_once __DIR__ . '/layout.php';

function get_signage_data(): array
{
    $settings = get_signage_settings();
    $ticker = fetch_messages();
    $teachers = fetch_on_duty_teachers();
    $weather = fetch_latest_weather();
    $media = fetch_active_media_items();
    $periods = fetch_schedule_periods();
    $schedule = fetch_weekly_schedule();
    $news = fetch_latest_news();
    $countdowns = fetch_active_countdowns();
    $nextPeriod = compute_next_period_state($periods);
    $layout = fetch_signage_layouts();

    return [
        'settings' => $settings,
        'ticker' => $ticker,
        'teachers' => $teachers,
        'weather' => $weather,
        'media' => $media,
        'periods' => $periods,
        'schedule' => $schedule,
        'news' => $news,
        'countdowns' => $countdowns,
        'nextPeriod' => $nextPeriod,
        'layout' => $layout,
    ];
}

function module_layout_style(array $layoutMap, string $key): string
{
    $defaults = signage_layout_defaults();
    $definition = $layoutMap[$key] ?? ($defaults[$key] ?? null);

    if (!$definition) {
        return '';
    }

    $top = max(0.0, min(100.0, (float) ($definition['top'] ?? 0)));
    $left = max(0.0, min(100.0, (float) ($definition['left'] ?? 0)));
    $width = max(5.0, min(100.0 - $left, (float) ($definition['width'] ?? 20)));
    $height = max(5.0, min(100.0 - $top, (float) ($definition['height'] ?? 20)));
    $fontScale = max(0.25, min(4.0, (float) ($definition['font_scale'] ?? 1)));
    $zIndex = (int) ($definition['z_index'] ?? 1);

    $styles = [
        'top: ' . round($top, 2) . '%',
        'left: ' . round($left, 2) . '%',
        'width: ' . round($width, 2) . '%',
        'height: ' . round($height, 2) . '%',
        'z-index: ' . $zIndex,
        '--module-font-scale: ' . $fontScale,
    ];

    return implode('; ', $styles);
}

function get_signage_settings(): array
{
    $pdo = get_pdo();
    $stmt = $pdo->query('SELECT organization_name, logo, logo_mime, ticker_font_family, ticker_font_size, ticker_border_width FROM signage_settings WHERE id = 1 LIMIT 1');
    $row = $stmt->fetch();

    if (!$row) {
        return [
            'organization_name' => 'Okulumuz',
            'logo_data_url' => null,
            'ticker_font_family' => 'Segoe UI, sans-serif',
            'ticker_font_size' => 28,
            'ticker_border_width' => 2,
        ];
    }

    return [
        'organization_name' => $row['organization_name'],
        'logo_data_url' => build_data_url($row['logo'], $row['logo_mime']),
        'ticker_font_family' => $row['ticker_font_family'] ?: 'Segoe UI, sans-serif',
        'ticker_font_size' => $row['ticker_font_size'] ? (int) $row['ticker_font_size'] : 28,
        'ticker_border_width' => $row['ticker_border_width'] !== null ? (int) $row['ticker_border_width'] : 2,
    ];
}

function fetch_on_duty_teachers(): array
{
    $pdo = get_pdo();
    $stmt = $pdo->query('SELECT first_name, last_name, branch, photo, photo_mime FROM teachers WHERE is_on_duty = 1 ORDER BY position ASC, last_name ASC');
    $teachers = [];
    foreach ($stmt->fetchAll() as $teacher) {
        $teachers[] = [
            'name' => trim($teacher['first_name'] . ' ' . $teacher['last_name']),
            'branch' => $teacher['branch'],
            'photo' => build_data_url($teacher['photo'], $teacher['photo_mime']),
        ];
    }

    return $teachers;
}

function fetch_latest_weather(): ?array
{
    $pdo = get_pdo();
    $stmt = $pdo->prepare('SELECT city, temperature, feels_like, humidity, wind_speed, condition_label, condition_icon, fetched_at FROM weather_snapshots WHERE is_active = 1 ORDER BY fetched_at DESC LIMIT 1');
    $stmt->execute();
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    return [
        'city' => $row['city'],
        'temperature' => (float) $row['temperature'],
        'feels_like' => $row['feels_like'] !== null ? (float) $row['feels_like'] : null,
        'humidity' => $row['humidity'] !== null ? (int) $row['humidity'] : null,
        'wind_speed' => $row['wind_speed'] !== null ? (float) $row['wind_speed'] : null,
        'condition' => $row['condition_label'],
        'icon' => $row['condition_icon'],
        'fetched_at' => $row['fetched_at'],
    ];
}

function fetch_active_media_items(): array
{
    $pdo = get_pdo();
    $stmt = $pdo->prepare('SELECT title, type, source, storage_path, duration_seconds FROM media_items WHERE is_active = 1 AND (expires_at IS NULL OR expires_at >= :now) ORDER BY position ASC, id ASC');
    $stmt->execute(['now' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s')]);
    $items = [];
    $mimeMap = [
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'ogv' => 'video/ogg',
        'ogg' => 'video/ogg',
        'mov' => 'video/quicktime',
    ];

    foreach ($stmt->fetchAll() as $item) {
        $source = $item['source'];
        $mime = null;
        if (!empty($item['storage_path'])) {
            $storagePath = ltrim($item['storage_path'], '/');
            $source = asset_url($storagePath);
            $ext = strtolower(pathinfo($storagePath, PATHINFO_EXTENSION));
            if (isset($mimeMap[$ext])) {
                $mime = $mimeMap[$ext];
            }
        }

        $items[] = [
            'title' => $item['title'],
            'type' => $item['type'],
            'source' => $source,
            'is_local' => !empty($item['storage_path']),
            'mime' => $mime,
            'duration' => max(3, (int) $item['duration_seconds']),
        ];
    }

    return $items;
}

function fetch_schedule_periods(): array
{
    $pdo = get_pdo();
    $stmt = $pdo->query('SELECT period_number, label, start_time, end_time, period_type FROM schedule_periods ORDER BY start_time ASC');
    $periods = [];
    foreach ($stmt->fetchAll() as $row) {
        $periods[] = [
            'period' => (int) $row['period_number'],
            'label' => $row['label'],
            'start_time' => $row['start_time'],
            'end_time' => $row['end_time'],
            'type' => $row['period_type'] ?? 'lesson',
        ];
    }

    return $periods;
}

function fetch_weekly_schedule(): array
{
    $pdo = get_pdo();
    $stmt = $pdo->query('SELECT c.id, c.name, c.display_order FROM classrooms c ORDER BY c.display_order ASC, c.name ASC');
    $classrooms = $stmt->fetchAll();

    if (!$classrooms) {
        return [];
    }

    $scheduleStmt = $pdo->prepare('SELECT weekday, period_number, subject, teacher FROM class_schedule_entries WHERE classroom_id = :classroom_id');

    $schedule = [];
    foreach ($classrooms as $classroom) {
        $scheduleStmt->execute(['classroom_id' => $classroom['id']]);
        $entries = $scheduleStmt->fetchAll();

        $grid = [];
        foreach ($entries as $entry) {
            $weekday = (int) $entry['weekday'];
            $period = (int) $entry['period_number'];
            $grid[$weekday][$period] = [
                'subject' => $entry['subject'],
                'teacher' => $entry['teacher'],
            ];
        }

        $schedule[] = [
            'classroom' => $classroom['name'],
            'entries' => $grid,
        ];
    }

    return $schedule;
}

function fetch_latest_news(): array
{
    $pdo = get_pdo();
    $stmt = $pdo->query('SELECT title, summary, image_url, source_url, published_at, created_at FROM news_items WHERE is_active = 1 ORDER BY COALESCE(published_at, created_at) DESC LIMIT 15');
    $items = [];

    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'title' => $row['title'],
            'summary' => $row['summary'],
            'image_url' => $row['image_url'],
            'source_url' => $row['source_url'],
            'published_at' => $row['published_at'],
            'fetched_at' => $row['published_at'] ?? $row['created_at'] ?? null,
        ];
    }

    $feedStmt = $pdo->query('SELECT i.title, i.summary, i.image_url, i.source_url, i.published_at, i.fetched_at FROM news_feed_items i INNER JOIN news_feeds f ON f.id = i.feed_id WHERE f.is_active = 1 ORDER BY COALESCE(i.published_at, i.fetched_at) DESC');

    foreach ($feedStmt->fetchAll() as $row) {
        $items[] = [
            'title' => $row['title'],
            'summary' => $row['summary'],
            'image_url' => $row['image_url'],
            'source_url' => $row['source_url'],
            'published_at' => $row['published_at'],
            'fetched_at' => $row['fetched_at'],
        ];
    }

    usort($items, static function (array $a, array $b) {
        $timeA = $a['published_at'] ?? $a['fetched_at'] ?? null;
        $timeB = $b['published_at'] ?? $b['fetched_at'] ?? null;

        if ($timeA === $timeB) {
            return 0;
        }

        if ($timeA === null) {
            return 1;
        }

        if ($timeB === null) {
            return -1;
        }

        return strcmp($timeB, $timeA);
    });

    return array_slice($items, 0, 15);
}

function fetch_active_countdowns(): array
{
    $pdo = get_pdo();
    $stmt = $pdo->prepare('SELECT title, target_at, icon, highlight_color FROM countdowns WHERE is_active = 1 AND target_at >= :now ORDER BY target_at ASC');
    $stmt->execute(['now' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s')]);
    $items = [];
    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'title' => $row['title'],
            'target_at' => $row['target_at'],
            'icon' => $row['icon'],
            'color' => $row['highlight_color'],
        ];
    }

    return $items;
}

function compute_next_period_state(array $periods): array
{
    if (!$periods) {
        return [
            'state' => 'unconfigured',
            'label' => 'Ders saatleri tanımlanmadı',
        ];
    }

    $timezone = new \DateTimeZone(date_default_timezone_get() ?: 'Europe/Istanbul');
    $now = new \DateTimeImmutable('now', $timezone);

    $dayOfWeek = (int) $now->format('N');
    if ($dayOfWeek >= 6) {
        return [
            'state' => 'after-school',
            'label' => 'Hafta Sonu',
            'message' => 'Okulumuz tatilde',
        ];
    }

    $timeline = [];
    foreach ($periods as $period) {
        $timeline[] = [
            'label' => $period['label'],
            'type' => $period['type'] ?? 'lesson',
            'start' => to_datetime($period['start_time'], $timezone, $now),
            'end' => to_datetime($period['end_time'], $timezone, $now),
        ];
    }

    usort($timeline, static function (array $a, array $b) {
        return $a['start'] <=> $b['start'];
    });

    $first = $timeline[0];
    $last = $timeline[count($timeline) - 1];

    if ($now < $first['start']) {
        return [
            'state' => 'before-school',
            'label' => 'Okulumuz Açılıyor',
            'timeLeft' => diff_to_array($now->diff($first['start'])),
            'next_change_at' => $first['start']->format(\DateTimeInterface::ATOM),
            'next_period' => format_next_period_context($first),
        ];
    }

    if ($now >= $last['end']) {
        return [
            'state' => 'after-school',
            'label' => 'Okulumuz Kapandı',
            'message' => 'Yarın görüşmek üzere',
        ];
    }

    foreach ($timeline as $index => $slot) {
        $nextSlot = $timeline[$index + 1] ?? null;

        if ($now >= $slot['start'] && $now < $slot['end']) {
            $state = $slot['type'] === 'break' ? 'break' : 'lesson';
            $label = $slot['label'] ?: ($state === 'break' ? 'Teneffüs' : 'Ders');

            return [
                'state' => $state,
                'label' => $label,
                'timeLeft' => diff_to_array($now->diff($slot['end'])),
                'next_change_at' => $slot['end']->format(\DateTimeInterface::ATOM),
                'next_period' => $nextSlot ? format_next_period_context($nextSlot) : null,
            ];
        }

        if ($now < $slot['start']) {
            // we are in a gap between slots; treat as break until the next one begins
            $state = 'break';
            $label = 'Teneffüs';

            return [
                'state' => $state,
                'label' => $label,
                'timeLeft' => diff_to_array($now->diff($slot['start'])),
                'next_change_at' => $slot['start']->format(\DateTimeInterface::ATOM),
                'next_period' => format_next_period_context($slot),
            ];
        }
    }

    return [
        'state' => 'after-school',
        'label' => 'Okulumuz Kapandı',
        'message' => 'Yarın görüşmek üzere',
    ];
}

function format_next_period_context(array $period): array
{
    $label = $period['label'] ?? '';
    if ($label === '' && (($period['type'] ?? '') === 'break')) {
        $label = 'Teneffüs';
    }

    return [
        'label' => $label,
        'starts_at' => $period['start']->format('H:i'),
        'type' => $period['type'] ?? 'lesson',
    ];
}

function to_datetime(string $time, \DateTimeZone $tz, \DateTimeImmutable $reference): \DateTimeImmutable
{
    [$hour, $minute] = array_map('intval', explode(':', substr($time, 0, 5)));
    return $reference->setTime($hour, $minute);
}

function diff_to_array(\DateInterval $interval): array
{
    $minutes = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;
    return [
        'minutes' => max(0, $minutes),
        'seconds' => max(0, $interval->s),
    ];
}

function build_data_url($binary, ?string $mime): ?string
{
    if (!$binary) {
        return null;
    }

    $encoded = base64_encode($binary);
    $mime = $mime ?: 'image/png';

    return sprintf('data:%s;base64,%s', $mime, $encoded);
}
