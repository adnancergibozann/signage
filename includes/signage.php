<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/messages.php';

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
    ];
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
    foreach ($stmt->fetchAll() as $item) {
        $source = $item['source'];
        if (!empty($item['storage_path'])) {
            $source = asset_url($item['storage_path']);
        }

        $items[] = [
            'title' => $item['title'],
            'type' => $item['type'],
            'source' => $source,
            'is_local' => !empty($item['storage_path']),
            'duration' => max(3, (int) $item['duration_seconds']),
        ];
    }

    return $items;
}

function fetch_schedule_periods(): array
{
    $pdo = get_pdo();
    $stmt = $pdo->query('SELECT period_number, label, start_time, end_time FROM schedule_periods ORDER BY period_number ASC');
    $periods = [];
    foreach ($stmt->fetchAll() as $row) {
        $periods[] = [
            'period' => (int) $row['period_number'],
            'label' => $row['label'],
            'start_time' => $row['start_time'],
            'end_time' => $row['end_time'],
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
    $stmt = $pdo->query('SELECT title, summary, image_url, source_url, published_at FROM news_items WHERE is_active = 1 ORDER BY COALESCE(published_at, created_at) DESC LIMIT 10');
    $items = [];
    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'title' => $row['title'],
            'summary' => $row['summary'],
            'image_url' => $row['image_url'],
            'source_url' => $row['source_url'],
            'published_at' => $row['published_at'],
        ];
    }

    return $items;
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

    $sorted = $periods;
    usort($sorted, static function ($a, $b) {
        return strcmp($a['start_time'], $b['start_time']);
    });

    $firstStart = new \DateTimeImmutable($sorted[0]['start_time'], $timezone);
    $lastEnd = new \DateTimeImmutable($sorted[count($sorted) - 1]['end_time'], $timezone);

    $firstStart = $now->setTime((int) $firstStart->format('H'), (int) $firstStart->format('i'));
    $lastEnd = $now->setTime((int) $lastEnd->format('H'), (int) $lastEnd->format('i'));

    if ($now < $firstStart) {
        return [
            'state' => 'before-school',
            'label' => 'Okulumuz Açılıyor',
            'timeLeft' => diff_to_array($now->diff($firstStart)),
            'next_change_at' => $firstStart->format(\DateTimeInterface::ATOM),
            'next_period' => [
                'label' => $sorted[0]['label'],
                'starts_at' => $sorted[0]['start_time'],
            ],
        ];
    }

    if ($now >= $lastEnd) {
        return [
            'state' => 'after-school',
            'label' => 'Okulumuz Kapandı',
            'message' => 'Yarın görüşmek üzere',
        ];
    }

    foreach ($sorted as $index => $period) {
        $start = to_datetime($period['start_time'], $timezone, $now);
        $end = to_datetime($period['end_time'], $timezone, $now);

        if ($now >= $start && $now < $end) {
            $next = $sorted[$index + 1] ?? null;
            return [
                'state' => 'lesson',
                'label' => $period['label'],
                'timeLeft' => diff_to_array($now->diff($end)),
                'next_change_at' => $end->format(\DateTimeInterface::ATOM),
                'next_period' => $next ? [
                    'label' => $next['label'],
                    'starts_at' => $next['start_time'],
                ] : null,
            ];
        }

        if ($now >= $end) {
            $next = $sorted[$index + 1] ?? null;
            if ($next) {
                $nextStart = to_datetime($next['start_time'], $timezone, $now);
                if ($now < $nextStart) {
                    return [
                        'state' => 'break',
                        'label' => 'Teneffüs',
                        'timeLeft' => diff_to_array($now->diff($nextStart)),
                        'next_change_at' => $nextStart->format(\DateTimeInterface::ATOM),
                        'next_period' => [
                            'label' => $next['label'],
                            'starts_at' => $next['start_time'],
                        ],
                    ];
                }
            }
        }
    }

    return [
        'state' => 'after-school',
        'label' => 'Okulumuz Kapandı',
        'message' => 'Yarın görüşmek üzere',
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
