<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/messages.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/license.php';
require_once __DIR__ . '/weather.php';

function get_signage_data(): array
{
    $pdo = get_pdo();
    $settings = get_signage_settings();
    $ticker = fetch_messages();
    $teachers = fetch_on_duty_teachers();
    $weatherSettings = get_weather_settings($pdo);
    $weather = fetch_latest_weather($pdo);
    $weather = maybe_refresh_weather($weatherSettings, $weather, $pdo);
    $weatherSettings = get_weather_settings($pdo);
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
        'weather_settings' => $weatherSettings,
        'media' => $media,
        'periods' => $periods,
        'schedule' => $schedule,
        'news' => $news,
        'countdowns' => $countdowns,
        'nextPeriod' => $nextPeriod,
        'layout' => $layout,
    ];
}

function signage_data_version(array $data): string
{
    $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($encoded === false) {
        $encoded = serialize($data);
    }

    return hash('sha1', $encoded);
}

function get_signage_payload(): array
{
    $license = get_license_status();
    $data = $license['is_active'] ? get_signage_data() : [];

    $versionSeed = [
        'license' => [
            'status' => $license['status_code'] ?? null,
            'start_date' => $license['start_date'] ?? null,
            'end_date' => $license['end_date'] ?? null,
            'updated_at' => $license['updated_at'] ?? null,
        ],
    ];

    if ($license['is_active']) {
        $versionSeed['data'] = $data;
    }

    return [
        'data' => $data,
        'version' => signage_data_version($versionSeed),
        'generated_at' => (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM),
        'license' => $license,
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
            'start_label' => substr($row['start_time'], 0, 5),
            'end_label' => substr($row['end_time'], 0, 5),
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
            'current_period' => null,
            'next_period' => null,
        ];
    }

    $timezone = new \DateTimeZone(date_default_timezone_get() ?: 'Europe/Istanbul');
    $now = new \DateTimeImmutable('now', $timezone);

    $timeline = [];
    foreach ($periods as $period) {
        $start = to_datetime($period['start_time'], $timezone, $now);
        $end = to_datetime($period['end_time'], $timezone, $now);

        if ($end <= $start) {
            continue;
        }

        $timeline[] = [
            'label' => $period['label'],
            'type' => $period['type'] ?? 'lesson',
            'start' => $start,
            'end' => $end,
            'start_label' => $period['start_label'] ?? $start->format('H:i'),
            'end_label' => $period['end_label'] ?? $end->format('H:i'),
            'period' => $period['period'] ?? null,
        ];
    }

    if (!$timeline) {
        return [
            'state' => 'unconfigured',
            'label' => 'Ders saatleri tanımlanmadı',
            'current_period' => null,
            'next_period' => null,
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
            'label' => headline_for_next_period('before-school'),
            'timeLeft' => diff_to_array($now->diff($first['start'])),
            'next_change_at' => $first['start']->format(\DateTimeInterface::ATOM),
            'next_period' => format_next_period_context($first),
            'current_period' => null,
        ];
    }

    if ($now >= $last['end']) {
        return [
            'state' => 'after-school',
            'label' => headline_for_next_period('after-school'),
            'message' => 'Yarın görüşmek üzere',
            'current_period' => null,
            'next_period' => null,
        ];
    }

    foreach ($timeline as $index => $slot) {
        $nextSlot = $timeline[$index + 1] ?? null;

        if ($now >= $slot['start'] && $now < $slot['end']) {
            $state = ($slot['type'] ?? 'lesson') === 'break' ? 'break' : 'lesson';
            $targetType = $state === 'lesson' ? 'break' : 'lesson';
            $targetSlot = find_next_slot_by_type($timeline, $index, $targetType) ?? $nextSlot;
            $targetMoment = $targetSlot['start'] ?? $slot['end'];

            return [
                'state' => $state,
                'label' => headline_for_next_period($state),
                'timeLeft' => diff_to_array($now->diff($targetMoment)),
                'next_change_at' => $targetMoment->format(\DateTimeInterface::ATOM),
                'current_period' => format_current_period_context($slot),
                'next_period' => $targetSlot ? format_next_period_context($targetSlot) : null,
            ];
        }

        if ($now < $slot['start']) {
            $previousSlot = $timeline[$index - 1] ?? null;
            $gapSlot = [
                'label' => 'Teneffüs',
                'type' => 'break',
                'start' => $previousSlot['end'] ?? $now,
                'end' => $slot['start'],
                'start_label' => $previousSlot['end_label'] ?? $now->format('H:i'),
                'end_label' => $slot['start_label'],
            ];

            return [
                'state' => 'break',
                'label' => headline_for_next_period('break'),
                'timeLeft' => diff_to_array($now->diff($slot['start'])),
                'next_change_at' => $slot['start']->format(\DateTimeInterface::ATOM),
                'current_period' => format_current_period_context($gapSlot),
                'next_period' => format_next_period_context($slot),
            ];
        }
    }

    return [
        'state' => 'after-school',
        'label' => headline_for_next_period('after-school'),
        'message' => 'Yarın görüşmek üzere',
        'current_period' => null,
        'next_period' => null,
    ];
}

function headline_for_next_period(string $state): string
{
    return match ($state) {
        'lesson' => 'Teneffüse Kalan Süre',
        'break' => 'Derse Kalan Süre',
        'before-school' => 'İlk Derse Kalan Süre',
        'after-school' => 'Okulumuz Kapandı',
        'unconfigured' => 'Ders saatleri tanımlanmadı',
        default => 'Ders Durumu',
    };
}

function find_next_slot_by_type(array $timeline, int $index, string $type): ?array
{
    $count = count($timeline);
    for ($i = $index + 1; $i < $count; $i++) {
        $slot = $timeline[$i];
        if (($slot['type'] ?? 'lesson') === $type) {
            return $slot;
        }
    }

    return null;
}

function format_current_period_context(array $slot): array
{
    $type = $slot['type'] ?? 'lesson';
    $label = $slot['label'] ?? '';

    if ($label === '') {
        $label = $type === 'break' ? 'Teneffüs' : 'Ders';
    }

    $start = $slot['start_label'] ?? null;
    if ($start === null && isset($slot['start']) && $slot['start'] instanceof \DateTimeInterface) {
        $start = $slot['start']->format('H:i');
    }

    $end = $slot['end_label'] ?? null;
    if ($end === null && isset($slot['end']) && $slot['end'] instanceof \DateTimeInterface) {
        $end = $slot['end']->format('H:i');
    }

    return [
        'label' => $label,
        'type' => $type,
        'start_time' => $start,
        'end_time' => $end,
    ];
}

function format_next_period_context(array $period): array
{
    $type = $period['type'] ?? 'lesson';
    $label = $period['label'] ?? '';
    if ($label === '' && $type === 'break') {
        $label = 'Teneffüs';
    }

    $start = $period['start_label'] ?? null;
    if ($start === null && isset($period['start']) && $period['start'] instanceof \DateTimeInterface) {
        $start = $period['start']->format('H:i');
    }

    return [
        'label' => $label,
        'starts_at' => $start ?? '',
        'type' => $type,
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
