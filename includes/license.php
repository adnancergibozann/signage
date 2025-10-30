<?php

require_once __DIR__ . '/../config/database.php';

function get_license_settings(?PDO $pdo = null): array
{
    $pdo = $pdo ?? get_pdo();

    $stmt = $pdo->query('SELECT id, license_type, start_date, end_date, is_active, notes, updated_at FROM license_settings WHERE id = 1 LIMIT 1');
    $settings = $stmt->fetch();

    if ($settings) {
        return $settings;
    }

    $today = new DateTimeImmutable('today');
    $defaultStart = $today->format('Y-m-d');
    $defaultEnd = $today->modify('+1 year')->format('Y-m-d');

    $insert = $pdo->prepare('INSERT INTO license_settings (id, license_type, start_date, end_date, is_active) VALUES (1, :type, :start, :end, 1)');
    $insert->execute([
        'type' => 'yearly',
        'start' => $defaultStart,
        'end' => $defaultEnd,
    ]);

    return [
        'id' => 1,
        'license_type' => 'yearly',
        'start_date' => $defaultStart,
        'end_date' => $defaultEnd,
        'is_active' => 1,
        'notes' => null,
        'updated_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
    ];
}

function normalize_license_date(?string $value): ?DateTimeImmutable
{
    if (!$value) {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    if ($date instanceof DateTimeImmutable) {
        return $date;
    }

    try {
        return new DateTimeImmutable($value);
    } catch (Exception $e) {
        return null;
    }
}

function get_license_status(?PDO $pdo = null): array
{
    $settings = get_license_settings($pdo);

    $startDate = normalize_license_date($settings['start_date'] ?? null);
    $endDate = normalize_license_date($settings['end_date'] ?? null);
    $now = new DateTimeImmutable('now');
    $today = new DateTimeImmutable('today');

    $isActiveFlag = (int) ($settings['is_active'] ?? 0) === 1;
    $hasValidRange = $startDate instanceof DateTimeImmutable && $endDate instanceof DateTimeImmutable && $endDate >= $startDate;

    $expired = false;
    $remainingDays = 0;
    $statusCode = 'inactive';

    if ($hasValidRange) {
        $endOfDay = $endDate->setTime(23, 59, 59);
        $expired = $endOfDay < $now;

        if (!$expired) {
            $diff = $today->diff($endDate);
            $remainingDays = (int) $diff->format('%r%a');
            if ($remainingDays < 0) {
                $remainingDays = 0;
            }
        }
    }

    if (!$hasValidRange) {
        $statusCode = 'inactive';
    } elseif (!$isActiveFlag) {
        $statusCode = 'inactive';
    } elseif ($expired) {
        $statusCode = 'expired';
    } else {
        $statusCode = 'active';
    }

    $remainingText = $statusCode === 'inactive' ? 'Devre dışı' : 'Süre doldu';
    if ($statusCode === 'active') {
        if ($remainingDays > 0) {
            $remainingText = $remainingDays . ' gün';
        } else {
            $remainingText = 'Son gün';
        }
    }

    $statusLabel = [
        'active' => 'Aktif',
        'inactive' => 'Pasif',
        'expired' => 'Süresi Doldu',
    ][$statusCode] ?? 'Bilinmiyor';

    $totalDays = 0;
    if ($startDate instanceof DateTimeImmutable && $endDate instanceof DateTimeImmutable) {
        $totalDiff = $startDate->diff($endDate);
        $totalDays = max(0, (int) $totalDiff->format('%a') + 1);
    }

    return [
        'is_active' => $statusCode === 'active',
        'status_code' => $statusCode,
        'status_label' => $statusLabel,
        'license_type' => $settings['license_type'] ?? 'monthly',
        'start_date' => $startDate ? $startDate->format('Y-m-d') : null,
        'end_date' => $endDate ? $endDate->format('Y-m-d') : null,
        'remaining_days' => $remainingDays,
        'remaining_text' => $remainingText,
        'is_expired' => $statusCode === 'expired',
        'is_manually_disabled' => !$isActiveFlag,
        'notes' => $settings['notes'] ?? null,
        'updated_at' => $settings['updated_at'] ?? null,
        'total_days' => $totalDays,
        'settings' => $settings,
        'current_date' => $today->format('Y-m-d'),
    ];
}

function save_license_settings(array $values, ?PDO $pdo = null): void
{
    $pdo = $pdo ?? get_pdo();

    $stmt = $pdo->prepare('INSERT INTO license_settings (id, license_type, start_date, end_date, is_active, notes) VALUES (1, :type, :start, :end, :active, :notes)
        ON DUPLICATE KEY UPDATE license_type = VALUES(license_type), start_date = VALUES(start_date), end_date = VALUES(end_date), is_active = VALUES(is_active), notes = VALUES(notes)');

    $stmt->execute([
        'type' => $values['license_type'],
        'start' => $values['start_date'],
        'end' => $values['end_date'],
        'active' => $values['is_active'],
        'notes' => $values['notes'],
    ]);
}

