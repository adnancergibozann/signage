<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/prayer.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = get_pdo();
    $settings = get_prayer_settings($pdo);
    $timezoneName = $settings['timezone'] ?: 'UTC';
    $timezone = new DateTimeZone($timezoneName);

    $now = new DateTimeImmutable('now', $timezone);
    $dayAfter = $now->add(new DateInterval('P2D'));

    $schedule = get_prayer_schedule($now->sub(new DateInterval('P1D')), $dayAfter, $pdo);
    $audioProfiles = get_prayer_audio_profiles($pdo);

    $events = [];
    foreach ($schedule as $items) {
        foreach ($items as $item) {
            $events[] = [
                'key' => $item['key'],
                'label' => $item['label'],
                'datetime' => $item['datetime']->format(DateTimeInterface::ATOM),
                'type' => 'prayer',
            ];

            if ($item['key'] === 'dhuhr' && $settings['jumuah_offset_minutes'] > 0) {
                $weekday = (int) $item['datetime']->format('N');
                if ($weekday === 5) { // Friday
                    $special = $item['datetime']->sub(new DateInterval('PT' . abs($settings['jumuah_offset_minutes']) . 'M'));
                    $events[] = [
                        'key' => 'jumuah',
                        'label' => PRAYER_LABELS['jumuah'],
                        'datetime' => $special->format(DateTimeInterface::ATOM),
                        'type' => 'special',
                        'related_prayer' => 'dhuhr',
                    ];
                }
            }
        }
    }

    usort($events, static function (array $a, array $b): int {
        return strcmp($a['datetime'], $b['datetime']);
    });

    $todayKey = $now->format('Y-m-d');
    $todayTimings = [];
    if (!empty($schedule[$todayKey])) {
        foreach ($schedule[$todayKey] as $item) {
            if (!in_array($item['key'], ['fajr', 'dhuhr', 'asr', 'maghrib', 'isha'], true)) {
                continue;
            }
            $todayTimings[] = [
                'key' => $item['key'],
                'label' => $item['label'],
                'time' => $item['time_label'],
                'datetime' => $item['datetime']->format(DateTimeInterface::ATOM),
            ];
        }
    }

    $upcoming = [];
    foreach ($events as $event) {
        if (new DateTimeImmutable($event['datetime']) >= $now) {
            $upcoming[] = $event;
        }
        if (count($upcoming) >= 6) {
            break;
        }
    }

    $profileMap = [];
    foreach ($audioProfiles as $profile) {
        $profileMap[$profile['key']] = $profile;
    }

    $response = [
        'settings' => [
            'country' => $settings['country'],
            'city' => $settings['city'],
            'district' => $settings['district'],
            'timezone' => $timezoneName,
            'jumuah_offset_minutes' => (int) $settings['jumuah_offset_minutes'],
        ],
        'server_now' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
        'location_now' => $now->format(DateTimeInterface::ATOM),
        'current_date' => [
            'iso' => $todayKey,
            'label' => format_turkish_date($now, $timezoneName),
        ],
        'today_timings' => $todayTimings,
        'events' => $events,
        'upcoming' => $upcoming,
        'audio_profiles' => $profileMap,
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
