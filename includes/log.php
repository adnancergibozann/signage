<?php
declare(strict_types=1);

function record_syslog(string $action, ?string $details = null, ?int $userId = null, array $context = []): void
{
    if ($userId === null) {
        $current = current_user();
        if ($current) {
            $userId = (int) $current['id'];
        }
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $contextJson = null;
    if ($context) {
        try {
            $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $contextJson = null;
        }
    }

    try {
        $pdo = get_pdo();
        $stmt = $pdo->prepare('INSERT INTO system_logs (user_id, action, details, ip_address, context)
            VALUES (:user_id, :action, :details, :ip_address, :context)');
        $stmt->execute([
            'user_id' => $userId,
            'action' => $action,
            'details' => $details,
            'ip_address' => $ip,
            'context' => $contextJson,
        ]);
    } catch (Throwable $e) {
        error_log('SYSLOG_WRITE_FAILED: ' . $e->getMessage());
    }
}

function fetch_syslog_entries(PDO $pdo, int $limit = 200): array
{
    $stmt = $pdo->prepare('SELECT l.id, l.user_id, l.action, l.details, l.ip_address, l.context, l.created_at, u.full_name
        FROM system_logs l
        LEFT JOIN users u ON u.id = l.user_id
        ORDER BY l.created_at DESC
        LIMIT :limit');
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $entries = $stmt->fetchAll();

    foreach ($entries as &$entry) {
        if (!empty($entry['context'])) {
            try {
                $entry['context'] = json_decode((string) $entry['context'], true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                $entry['context'] = null;
            }
        } else {
            $entry['context'] = null;
        }
    }

    return $entries;
}
