<?php
declare(strict_types=1);

function meeting_status_options(): array
{
    return ['planned', 'in_progress', 'completed', 'cancelled'];
}

function meeting_status_label(string $status): string
{
    return match ($status) {
        'planned' => 'Planlandı',
        'in_progress' => 'Devam Ediyor',
        'completed' => 'Tamamlandı',
        'cancelled' => 'İptal Edildi',
        default => ucfirst($status),
    };
}

function create_scheduled_meeting(
    PDO $pdo,
    int $managerId,
    string $visitorName,
    ?string $visitorCompany,
    ?string $purpose,
    DateTimeImmutable $scheduledStart,
    ?DateTimeImmutable $scheduledEnd,
    ?string $notes,
    ?int $userId = null
): int {
    $stmt = $pdo->prepare('INSERT INTO scheduled_meetings (
            manager_id,
            visitor_name,
            visitor_company,
            purpose,
            scheduled_start,
            scheduled_end,
            status,
            notes,
            created_by,
            updated_by
        ) VALUES (
            :manager_id,
            :visitor_name,
            :visitor_company,
            :purpose,
            :scheduled_start,
            :scheduled_end,
            :status,
            :notes,
            :created_by,
            :updated_by
        )');
    $stmt->execute([
        'manager_id' => $managerId,
        'visitor_name' => $visitorName,
        'visitor_company' => $visitorCompany,
        'purpose' => $purpose,
        'scheduled_start' => $scheduledStart->format('Y-m-d H:i:s'),
        'scheduled_end' => $scheduledEnd?->format('Y-m-d H:i:s'),
        'status' => 'planned',
        'notes' => $notes,
        'created_by' => $userId,
        'updated_by' => $userId,
    ]);

    return (int) $pdo->lastInsertId();
}

function update_scheduled_meeting_status(PDO $pdo, int $meetingId, string $status, ?int $userId = null): void
{
    if (!in_array($status, meeting_status_options(), true)) {
        throw new InvalidArgumentException('Geçersiz toplantı durumu.');
    }

    $stmt = $pdo->prepare('UPDATE scheduled_meetings
        SET status = :status,
            updated_by = :updated_by,
            updated_at = NOW()
        WHERE id = :id');
    $stmt->execute([
        'status' => $status,
        'updated_by' => $userId,
        'id' => $meetingId,
    ]);

    if ($stmt->rowCount() === 0) {
        throw new RuntimeException('Toplantı kaydı bulunamadı.');
    }
}

function delete_scheduled_meeting(PDO $pdo, int $meetingId): void
{
    $stmt = $pdo->prepare('DELETE FROM scheduled_meetings WHERE id = :id');
    $stmt->execute(['id' => $meetingId]);
    if ($stmt->rowCount() === 0) {
        throw new RuntimeException('Toplantı kaydı bulunamadı.');
    }
}

function fetch_scheduled_meetings(PDO $pdo, array $options = []): array
{
    $conditions = [];
    $params = [];

    if (!empty($options['status'])) {
        $conditions[] = 'm.status = :status';
        $params['status'] = $options['status'];
    } elseif (!empty($options['status_in']) && is_array($options['status_in'])) {
        $placeholders = implode(',', array_fill(0, count($options['status_in']), '?'));
        $conditions[] = 'm.status IN (' . $placeholders . ')';
        $params = array_merge($params, array_values($options['status_in']));
    }

    if (!empty($options['manager_id'])) {
        $conditions[] = 'm.manager_id = :manager_id';
        $params['manager_id'] = (int) $options['manager_id'];
    }

    if (!empty($options['from'])) {
        $conditions[] = '((m.scheduled_end IS NULL AND m.scheduled_start >= :from)
            OR (m.scheduled_end IS NOT NULL AND m.scheduled_end >= :from))';
        $params['from'] = $options['from'] instanceof DateTimeInterface
            ? $options['from']->format('Y-m-d H:i:s')
            : (string) $options['from'];
    }

    if (!empty($options['to'])) {
        $conditions[] = 'm.scheduled_start <= :to';
        $params['to'] = $options['to'] instanceof DateTimeInterface
            ? $options['to']->format('Y-m-d H:i:s')
            : (string) $options['to'];
    }

    $sql = 'SELECT m.*, u.full_name AS manager_name, u.department AS manager_department, u.role AS manager_role
        FROM scheduled_meetings m
        INNER JOIN users u ON u.id = m.manager_id';

    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', array_map(static function ($condition) {
            return '(' . $condition . ')';
        }, $conditions));
    }

    $order = strtoupper((string) ($options['order'] ?? 'ASC'));
    if (!in_array($order, ['ASC', 'DESC'], true)) {
        $order = 'ASC';
    }

    $sql .= ' ORDER BY m.scheduled_start ' . $order;

    if (!empty($options['limit'])) {
        $sql .= ' LIMIT ' . (int) $options['limit'];
    }

    $stmt = $pdo->prepare($sql);

    $index = 1;
    foreach ($params as $key => $value) {
        if (is_int($key)) {
            $stmt->bindValue($index, $value);
            $index++;
        }
    }

    foreach ($params as $key => $value) {
        if (!is_int($key)) {
            $stmt->bindValue(':' . $key, $value);
        }
    }

    $stmt->execute();
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['manager_id'] = (int) $row['manager_id'];
        $row['status_label'] = meeting_status_label($row['status']);
    }

    return $rows;
}

function fetch_next_meetings_for_managers(PDO $pdo, array $managerIds, DateTimeImmutable $reference): array
{
    if (!$managerIds) {
        return [];
    }

    $managerIds = array_values(array_unique(array_map('intval', $managerIds)));
    if (!$managerIds) {
        return [];
    }

    $placeholders = [];
    foreach (array_keys($managerIds) as $index) {
        $placeholders[] = ':manager' . $index;
    }
    $sql = 'SELECT m.*
        FROM scheduled_meetings m
        WHERE m.manager_id IN (' . implode(',', $placeholders) . ')
            AND (m.status = "in_progress" OR m.status = "planned")
            AND (
                m.status = "in_progress"
                OR m.scheduled_start >= :reference
                OR (m.scheduled_end IS NOT NULL AND m.scheduled_end >= :reference)
            )
        ORDER BY m.manager_id, m.scheduled_start ASC';

    $stmt = $pdo->prepare($sql);
    foreach ($managerIds as $index => $id) {
        $stmt->bindValue(':manager' . $index, $id, PDO::PARAM_INT);
    }
    $stmt->bindValue(':reference', $reference->format('Y-m-d H:i:s'));
    $stmt->execute();

    $next = [];
    foreach ($stmt as $row) {
        $managerId = (int) $row['manager_id'];
        if (isset($next[$managerId])) {
            continue;
        }
        $next[$managerId] = [
            'id' => (int) $row['id'],
            'status' => $row['status'],
            'statusLabel' => meeting_status_label($row['status']),
            'visitorName' => $row['visitor_name'],
            'visitorCompany' => $row['visitor_company'],
            'purpose' => $row['purpose'],
            'notes' => $row['notes'],
            'scheduledStart' => $row['scheduled_start'],
            'scheduledEnd' => $row['scheduled_end'],
        ];
    }

    return $next;
}
