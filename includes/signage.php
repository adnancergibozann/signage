<?php
declare(strict_types=1);

function fetch_signage_state(): array
{
    $pdo = get_pdo();
    $now = new DateTimeImmutable('now');

    $settings = load_all_settings();
    $companyName = $settings['company_name'] ?? 'Gapgross';
    $logoPath = $settings['logo_path'] ?? null;
    $organigramPath = $settings['organigram_path'] ?? null;
    $refresh = (int)($settings['signage_refresh_seconds'] ?? config_value('signage.refresh_seconds', 5));
    $timeFormat = $settings['time_format'] ?? config_value('signage.time_format', '24h');
    $primary = $settings['theme_primary'] ?? config_value('theme.primary');
    $secondary = $settings['theme_secondary'] ?? config_value('theme.secondary');
    $tickerSpeed = (int)($settings['ticker_speed'] ?? 40);

    $managers = fetch_managers_with_status($pdo, $now, $settings);
    $announcements = fetch_active_announcements($pdo, $now);
    $tickers = fetch_active_tickers($pdo, $now);
    $media = find_active_media($pdo, $now);
    $alerts = build_alerts($settings, $now);

    return [
        'timestamp' => $now->format(DateTimeInterface::ATOM),
        'settings' => [
            'companyName' => $companyName,
            'refreshSeconds' => $refresh,
            'timeFormat' => $timeFormat,
            'primaryColor' => $primary,
            'secondaryColor' => $secondary,
            'tickerSpeed' => $tickerSpeed,
            'logoUrl' => $logoPath ? asset_url('public/uploads/branding/' . $logoPath) : null,
            'organigramUrl' => $organigramPath ? asset_url('public/uploads/branding/' . $organigramPath) : null,
        ],
        'managers' => $managers,
        'announcements' => $announcements,
        'ticker' => $tickers,
        'activeMedia' => $media,
        'alerts' => $alerts,
    ];
}

function fetch_managers_with_status(PDO $pdo, DateTimeImmutable $now, array $settings = []): array
{
    $stmt = $pdo->query('SELECT u.id, u.full_name, u.department, u.photo_path, s.status, s.state_started_at, s.state_ends_at, s.note
        FROM users u
        LEFT JOIN manager_statuses s ON s.user_id = u.id
        WHERE u.role = "manager"
        ORDER BY u.full_name');

    $managers = [];
    $lunchWindow = current_lunch_window($settings, $now);
    $lunchEndsAt = $lunchWindow ? $lunchWindow['end']->format('Y-m-d H:i:s') : null;
    $lunchStartsAt = $lunchWindow ? $lunchWindow['start']->format('Y-m-d H:i:s') : null;
    $lunchRemaining = $lunchWindow ? max(0, $lunchWindow['end']->getTimestamp() - $now->getTimestamp()) : null;
    foreach ($stmt as $row) {
        $status = $row['status'] ?? 'available';
        $endsAt = $row['state_ends_at'];
        $remainingSeconds = null;
        $startedAt = $row['state_started_at'];
        if ($lunchWindow) {
            $status = 'lunch';
            $endsAt = $lunchEndsAt;
            $remainingSeconds = $lunchRemaining;
            $startedAt = $lunchStartsAt;
        } elseif ($endsAt) {
            $diff = (new DateTimeImmutable($endsAt))->getTimestamp() - $now->getTimestamp();
            $remainingSeconds = $diff > 0 ? $diff : 0;
        }

        $managers[] = [
            'id' => (int) $row['id'],
            'name' => $row['full_name'],
            'department' => $row['department'],
            'status' => $status,
            'statusLabel' => map_status_label($status),
            'photoUrl' => $row['photo_path'] ? asset_url('public/uploads/profile/' . $row['photo_path']) : null,
            'note' => $row['note'],
            'remainingSeconds' => $status === 'meeting' ? $remainingSeconds : ($status === 'lunch' ? $remainingSeconds : null),
            'endsAt' => $endsAt,
            'startedAt' => $startedAt,
        ];
    }

    return $managers;
}

function map_status_label(string $status): string
{
    return match ($status) {
        'available' => 'Müsait',
        'unavailable' => 'Meşgul',
        'meeting' => 'Toplantıda',
        'lunch' => 'Yemek Molasında',
        'leave' => 'İzinli',
        default => ucfirst($status),
    };
}

function fetch_active_announcements(PDO $pdo, DateTimeImmutable $now): array
{
    $stmt = $pdo->prepare('SELECT id, title, body, priority
        FROM announcements
        WHERE is_active = 1
            AND (starts_at IS NULL OR starts_at <= :now)
            AND (ends_at IS NULL OR ends_at >= :now)
        ORDER BY priority DESC, starts_at DESC, id DESC');
    $stmt->execute(['now' => $now->format('Y-m-d H:i:s')]);

    return $stmt->fetchAll();
}

function fetch_active_tickers(PDO $pdo, DateTimeImmutable $now): array
{
    $stmt = $pdo->prepare('SELECT message
        FROM ticker_items
        WHERE is_active = 1
            AND (starts_at IS NULL OR starts_at <= :now)
            AND (ends_at IS NULL OR ends_at >= :now)
        ORDER BY priority DESC, id DESC');
    $stmt->execute(['now' => $now->format('Y-m-d H:i:s')]);
    return array_column($stmt->fetchAll(), 'message');
}

function find_active_media(PDO $pdo, DateTimeImmutable $now): ?array
{
    $stmt = $pdo->prepare('SELECT id, title, file_path, media_type, duration_seconds
        FROM media_items
        WHERE is_active = 1
            AND is_fullscreen = 1
            AND (starts_at IS NULL OR starts_at <= :now)
            AND (ends_at IS NULL OR ends_at >= :now)
        ORDER BY priority DESC, starts_at DESC, id DESC
        LIMIT 1');
    $stmt->execute(['now' => $now->format('Y-m-d H:i:s')]);
    $media = $stmt->fetch();
    if (!$media) {
        return null;
    }

    return [
        'title' => $media['title'],
        'type' => $media['media_type'],
        'url' => asset_url('public/uploads/media/' . $media['file_path']),
        'durationSeconds' => $media['duration_seconds'] ? (int) $media['duration_seconds'] : null,
    ];
}

function build_alerts(array $settings, DateTimeImmutable $now): array
{
    $alerts = [];
    if (current_lunch_window($settings, $now)) {
        $alerts[] = [
            'type' => 'lunch',
            'message' => 'Belirlenen yemek molası saatindeyiz.',
        ];
    }
    return $alerts;
}

function current_lunch_window(array $settings, DateTimeImmutable $now): ?array
{
    $startTime = extract_time_component($settings['lunch_notice_start'] ?? null);
    $endTime = extract_time_component($settings['lunch_notice_end'] ?? null);
    if (!$startTime || !$endTime) {
        return null;
    }

    [$startHour, $startMinute] = array_map('intval', explode(':', $startTime));
    [$endHour, $endMinute] = array_map('intval', explode(':', $endTime));

    $tz = $now->getTimezone();
    $baseDate = $now->format('Y-m-d');
    $start = DateTimeImmutable::createFromFormat('Y-m-d H:i', sprintf('%s %02d:%02d', $baseDate, $startHour, $startMinute), $tz);
    $end = DateTimeImmutable::createFromFormat('Y-m-d H:i', sprintf('%s %02d:%02d', $baseDate, $endHour, $endMinute), $tz);
    if (!$start || !$end) {
        return null;
    }

    if ($end <= $start) {
        $end = $end->modify('+1 day');
        if ($now < $start) {
            $start = $start->modify('-1 day');
            $end = $end->modify('-1 day');
        }
    }

    if ($now < $start) {
        return null;
    }
    if ($now > $end) {
        return null;
    }

    return [
        'start' => $start,
        'end' => $end,
    ];
}
