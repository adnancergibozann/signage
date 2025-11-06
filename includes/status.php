<?php
declare(strict_types=1);

function status_options_for_role(string $role): array
{
    $options = ['available', 'unavailable', 'meeting', 'lunch', 'leave'];
    if ($role === 'finance') {
        $options[] = 'cheque_ready';
        $options[] = 'payment_ready';
    }

    return array_values(array_unique($options));
}

function map_status_label(string $status): string
{
    return match ($status) {
        'available' => 'Müsait',
        'unavailable' => 'Meşgul',
        'meeting' => 'Toplantıda',
        'lunch' => 'Yemek Molasında',
        'leave' => 'İzinli',
        'cheque_ready' => 'Çek Vermeye Uygun',
        'payment_ready' => 'Ödemeye Uygun',
        default => ucfirst($status),
    };
}

function set_manager_display_order(PDO $pdo, int $managerId, ?int $displayOrder): void
{
    ensure_manager_status_row($managerId);
    $stmt = $pdo->prepare('UPDATE manager_statuses SET display_order = :display_order, updated_at = NOW() WHERE user_id = :id');
    if ($displayOrder === null) {
        $stmt->bindValue('display_order', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue('display_order', $displayOrder, PDO::PARAM_INT);
    }
    $stmt->bindValue('id', $managerId, PDO::PARAM_INT);
    $stmt->execute();
}

function update_manager_status(
    PDO $pdo,
    int $managerId,
    string $status,
    ?DateTimeImmutable $stateEndsAt,
    ?string $note,
    ?int $durationMinutes = null
): void {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT status, active_meeting_id FROM manager_statuses WHERE user_id = :id FOR UPDATE');
        $stmt->execute(['id' => $managerId]);
        $current = $stmt->fetch();
        if (!$current) {
            ensure_manager_status_row($managerId);
            $stmt->execute(['id' => $managerId]);
            $current = $stmt->fetch();
        }

        $now = new DateTimeImmutable();

        $calculatedEndsAt = $stateEndsAt;
        if (!$calculatedEndsAt && $durationMinutes && $durationMinutes > 0) {
            $calculatedEndsAt = $now->modify("+{$durationMinutes} minutes");
        }

        if ($current && $current['status'] === 'meeting' && $status !== 'meeting' && $current['active_meeting_id']) {
            $pdo->prepare('UPDATE meeting_logs SET ended_at = :ended_at WHERE id = :id')->execute([
                'ended_at' => $now->format('Y-m-d H:i:s'),
                'id' => $current['active_meeting_id'],
            ]);
            $pdo->prepare('UPDATE manager_statuses SET active_meeting_id = NULL WHERE user_id = :id')->execute(['id' => $managerId]);
        }

        $activeMeetingId = $current['active_meeting_id'] ?? null;
        $stateStartedAt = $now->format('Y-m-d H:i:s');
        $stateEndsAtStr = $calculatedEndsAt?->format('Y-m-d H:i:s');

        if ($status === 'meeting') {
            if ($current && $current['active_meeting_id']) {
                $pdo->prepare('UPDATE meeting_logs SET ended_at = :ended_at WHERE id = :id AND ended_at IS NULL')->execute([
                    'ended_at' => $stateStartedAt,
                    'id' => $current['active_meeting_id'],
                ]);
            }
            $stmt = $pdo->prepare('INSERT INTO meeting_logs (manager_id, started_at, expected_end_at, note)
                VALUES (:manager_id, :started_at, :expected_end_at, :note)');
            $stmt->execute([
                'manager_id' => $managerId,
                'started_at' => $stateStartedAt,
                'expected_end_at' => $stateEndsAtStr,
                'note' => $note,
            ]);
            $activeMeetingId = (int) $pdo->lastInsertId();
        }

        $updateStmt = $pdo->prepare('UPDATE manager_statuses
            SET status = :status,
                state_started_at = :state_started_at,
                state_ends_at = :state_ends_at,
                note = :note,
                active_meeting_id = :active_meeting_id,
                updated_at = NOW()
            WHERE user_id = :user_id');
        $updateStmt->execute([
            'status' => $status,
            'state_started_at' => $stateStartedAt,
            'state_ends_at' => $stateEndsAtStr,
            'note' => $note,
            'active_meeting_id' => $activeMeetingId,
            'user_id' => $managerId,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function end_active_meeting(PDO $pdo, int $managerId): void
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT active_meeting_id FROM manager_statuses WHERE user_id = :id FOR UPDATE');
        $stmt->execute(['id' => $managerId]);
        $status = $stmt->fetch();
        if ($status && $status['active_meeting_id']) {
            $pdo->prepare('UPDATE meeting_logs SET ended_at = :ended_at WHERE id = :id')->execute([
                'ended_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                'id' => $status['active_meeting_id'],
            ]);
            $pdo->prepare('UPDATE manager_statuses SET active_meeting_id = NULL WHERE user_id = :id')->execute(['id' => $managerId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function update_manager_note(PDO $pdo, int $managerId, ?string $note): void
{
    ensure_manager_status_row($managerId);
    $stmt = $pdo->prepare('UPDATE manager_statuses SET note = :note, updated_at = NOW() WHERE user_id = :id');
    $stmt->execute([
        'note' => $note,
        'id' => $managerId,
    ]);
}
