<?php
declare(strict_types=1);

function fetch_active_ticker_feed_items(PDO $pdo, DateTimeImmutable $now): array
{
    $stmt = $pdo->query('SELECT id, title, feed_url, priority, cache_payload, cache_fetched_at, cache_ttl_seconds, last_error
        FROM ticker_feeds
        WHERE is_active = 1
        ORDER BY priority DESC, id DESC');

    $items = [];
    foreach ($stmt as $feed) {
        $feedItems = [];
        $feedId = (int) $feed['id'];
        $ttl = (int) ($feed['cache_ttl_seconds'] ?? 300);
        if ($ttl <= 0) {
            $ttl = 300;
        }
        $lastFetched = null;
        if (!empty($feed['cache_fetched_at'])) {
            try {
                $lastFetched = new DateTimeImmutable($feed['cache_fetched_at']);
            } catch (Throwable) {
                $lastFetched = null;
            }
        }
        $shouldRefresh = !$lastFetched || ($lastFetched->getTimestamp() + $ttl) <= $now->getTimestamp();
        if ($shouldRefresh) {
            try {
                $feedItems = refresh_ticker_feed($pdo, $feedId, $feed, $now);
            } catch (Throwable $e) {
                $feedItems = decode_ticker_feed_cache($feed['cache_payload'] ?? null);
                record_ticker_feed_error($pdo, $feedId, $e->getMessage(), $now);
            }
        } else {
            $feedItems = decode_ticker_feed_cache($feed['cache_payload'] ?? null);
            if (!$feedItems) {
                try {
                    $feedItems = refresh_ticker_feed($pdo, $feedId, $feed, $now);
                } catch (Throwable $e) {
                    record_ticker_feed_error($pdo, $feedId, $e->getMessage(), $now);
                }
            }
        }
        if ($feedItems) {
            $items = array_merge($items, $feedItems);
        }
    }

    return $items;
}

function refresh_ticker_feed_by_id(PDO $pdo, int $feedId): array
{
    $stmt = $pdo->prepare('SELECT id, title, feed_url, priority, cache_payload, cache_fetched_at, cache_ttl_seconds, last_error
        FROM ticker_feeds WHERE id = :id');
    $stmt->execute(['id' => $feedId]);
    $feed = $stmt->fetch();
    if (!$feed) {
        throw new RuntimeException('RSS kaynağı bulunamadı.');
    }

    $now = new DateTimeImmutable('now');

    return refresh_ticker_feed($pdo, $feedId, $feed, $now);
}

function refresh_ticker_feed(PDO $pdo, int $feedId, array $feed, DateTimeImmutable $now): array
{
    $url = trim((string) ($feed['feed_url'] ?? ''));
    if ($url === '') {
        throw new RuntimeException('RSS adresi tanımlı değil.');
    }

    $httpContext = stream_context_create([
        'http' => [
            'timeout' => 5,
            'follow_location' => 1,
            'user_agent' => 'GapgrossSignage/1.0 (+https://gapgross.local)',
        ],
        'https' => [
            'timeout' => 5,
            'user_agent' => 'GapgrossSignage/1.0 (+https://gapgross.local)',
        ],
    ]);

    $contents = @file_get_contents($url, false, $httpContext);
    if ($contents === false || $contents === '') {
        throw new RuntimeException('RSS içeriği alınamadı.');
    }

    [$sourceTitle, $parsedItems] = parse_feed_items($contents);
    if (!$parsedItems) {
        throw new RuntimeException('RSS akışında öğe bulunamadı.');
    }

    $prefix = $feed['title'] ?: $sourceTitle;
    $messages = [];
    foreach ($parsedItems as $entry) {
        $entry = trim($entry);
        if ($entry === '') {
            continue;
        }
        if ($prefix) {
            $messages[] = sprintf('%s: %s', $prefix, $entry);
        } else {
            $messages[] = $entry;
        }
    }

    $messages = array_slice($messages, 0, 15);

    $payload = json_encode([
        'items' => $messages,
        'sourceTitle' => $sourceTitle,
    ], JSON_UNESCAPED_UNICODE);

    $stmt = $pdo->prepare('UPDATE ticker_feeds SET cache_payload = :payload, cache_fetched_at = :fetched, last_error = NULL
        WHERE id = :id');
    $stmt->execute([
        'payload' => $payload,
        'fetched' => $now->format('Y-m-d H:i:s'),
        'id' => $feedId,
    ]);

    return $messages;
}

function parse_feed_items(string $xml): array
{
    $previous = libxml_use_internal_errors(true);
    try {
        $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
        if ($doc === false) {
            throw new RuntimeException('RSS verisi çözümlenemedi.');
        }
        $items = [];
        $sourceTitle = '';
        if (isset($doc->channel)) {
            $sourceTitle = trim((string) ($doc->channel->title ?? ''));
            foreach ($doc->channel->item as $item) {
                $title = trim((string) ($item->title ?? ''));
                if ($title === '') {
                    $title = trim((string) ($item->description ?? ''));
                }
                if ($title !== '') {
                    $items[] = $title;
                }
            }
        } else {
            $sourceTitle = trim((string) ($doc->title ?? ''));
            if (isset($doc->entry)) {
                foreach ($doc->entry as $entry) {
                    $title = trim((string) ($entry->title ?? ''));
                    if ($title === '') {
                        $summary = (string) ($entry->summary ?? '');
                        $title = trim(strip_tags($summary));
                    }
                    if ($title !== '') {
                        $items[] = $title;
                    }
                }
            }
        }

        return [$sourceTitle, $items];
    } finally {
        libxml_clear_errors();
        if ($previous !== null) {
            libxml_use_internal_errors($previous);
        }
    }
}

function decode_ticker_feed_cache(?string $payload): array
{
    if (!$payload) {
        return [];
    }
    $decoded = json_decode($payload, true);
    if (!is_array($decoded)) {
        return [];
    }
    $items = $decoded['items'] ?? $decoded;
    if (!is_array($items)) {
        return [];
    }

    $messages = [];
    foreach ($items as $item) {
        if (!is_string($item)) {
            continue;
        }
        $item = trim($item);
        if ($item !== '') {
            $messages[] = $item;
        }
    }

    return $messages;
}

function record_ticker_feed_error(PDO $pdo, int $feedId, string $message, DateTimeImmutable $now): void
{
    $stmt = $pdo->prepare('UPDATE ticker_feeds SET last_error = :error, cache_fetched_at = :fetched WHERE id = :id');
    $stmt->execute([
        'error' => mb_substr($message, 0, 500),
        'fetched' => $now->format('Y-m-d H:i:s'),
        'id' => $feedId,
    ]);
}

