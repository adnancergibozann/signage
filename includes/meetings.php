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
        $values = array_values(array_filter(
            $options['status_in'],
            static fn($value) => $value !== null && $value !== ''
        ));

        if ($values) {
            $placeholders = [];
            foreach ($values as $index => $value) {
                $key = 'status_in_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $value;
            }
            $conditions[] = 'm.status IN (' . implode(',', $placeholders) . ')';
        }
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

    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
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

function find_scheduled_meeting(PDO $pdo, int $meetingId): ?array
{
    $stmt = $pdo->prepare('SELECT m.*, u.full_name AS manager_name, u.role AS manager_role, u.department AS manager_department
        FROM scheduled_meetings m
        INNER JOIN users u ON u.id = m.manager_id
        WHERE m.id = :id');
    $stmt->execute(['id' => $meetingId]);
    $meeting = $stmt->fetch();

    if (!$meeting) {
        return null;
    }

    $meeting['id'] = (int) $meeting['id'];
    $meeting['manager_id'] = (int) $meeting['manager_id'];
    $meeting['status_label'] = meeting_status_label($meeting['status']);

    return $meeting;
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
        ORDER BY m.manager_id,
            CASE WHEN m.status = "in_progress" THEN 0 ELSE 1 END,
            m.scheduled_start ASC';

    $stmt = $pdo->prepare($sql);
    foreach ($managerIds as $index => $id) {
        $stmt->bindValue(':manager' . $index, $id, PDO::PARAM_INT);
    }
    $stmt->bindValue(':reference', $reference->format('Y-m-d H:i:s'));
    $stmt->execute();

    $results = [];
    $todayString = $reference->format('Y-m-d');

    foreach ($stmt as $row) {
        $managerId = (int) $row['manager_id'];
        if (!isset($results[$managerId])) {
            $results[$managerId] = [
                'current' => null,
                'next' => null,
            ];
        }

        $normalized = [
            'id' => (int) $row['id'],
            'status' => $row['status'],
            'statusLabel' => meeting_status_label($row['status']),
            'visitorName' => $row['visitor_name'],
            'visitorCompany' => $row['visitor_company'],
            'purpose' => $row['purpose'],
            'notes' => $row['notes'],
            'scheduledStart' => $row['scheduled_start'],
            'scheduledEnd' => $row['scheduled_end'],
            'managerId' => $managerId,
        ];

        $isSameDay = false;
        if (!empty($row['scheduled_start'])) {
            try {
                $start = new DateTimeImmutable((string) $row['scheduled_start']);
                $isSameDay = $start->format('Y-m-d') === $todayString;
            } catch (Throwable) {
                $isSameDay = false;
            }
        }

        if ($row['status'] === 'in_progress' && $results[$managerId]['current'] === null) {
            $normalized['position'] = 'current';
            $results[$managerId]['current'] = $normalized;
            continue;
        }

        if ($row['status'] === 'planned') {
            $normalized['position'] = 'next';
            $normalized['_is_today'] = $isSameDay;
            $existingNext = $results[$managerId]['next'];
            if ($existingNext === null
                || (!$existingNext['_is_today'] && $isSameDay)
                || ($existingNext['_is_today'] === $isSameDay && $existingNext['scheduledStart'] > $row['scheduled_start'])) {
                $results[$managerId]['next'] = $normalized;
            }
        }
    }

    foreach ($results as &$items) {
        if (isset($items['next']['_is_today'])) {
            unset($items['next']['_is_today']);
        }
    }

    return $results;
}

function fetch_next_global_meeting(PDO $pdo, DateTimeImmutable $reference): ?array
{
    $sql = 'SELECT m.*, u.full_name AS manager_name, u.department AS manager_department, u.role AS manager_role'
        . ' FROM scheduled_meetings m'
        . ' INNER JOIN users u ON u.id = m.manager_id'
        . ' WHERE (m.status = "in_progress" OR m.status = "planned")'
        . ' AND ('
        . '     m.status = "in_progress"'
        . '     OR m.scheduled_start >= :reference'
        . '     OR (m.scheduled_end IS NOT NULL AND m.scheduled_end >= :reference)'
        . ' )'
        . ' ORDER BY CASE WHEN m.status = "in_progress" THEN 0 ELSE 1 END, m.scheduled_start ASC'
        . ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['reference' => $reference->format('Y-m-d H:i:s')]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $data = [
        'id' => (int) $row['id'],
        'status' => $row['status'],
        'statusLabel' => meeting_status_label($row['status']),
        'visitorName' => $row['visitor_name'],
        'visitorCompany' => $row['visitor_company'],
        'purpose' => $row['purpose'],
        'notes' => $row['notes'],
        'scheduledStart' => $row['scheduled_start'],
        'scheduledEnd' => $row['scheduled_end'],
        'managerId' => (int) $row['manager_id'],
        'managerName' => $row['manager_name'],
        'managerDepartment' => $row['manager_department'],
        'managerRole' => $row['manager_role'],
    ];

    return $data;
}
