<?php

require_once __DIR__ . '/../config/database.php';

function fetch_product_slides(bool $onlyActive = true): array
{
    $pdo = get_pdo();
    $sql = 'SELECT id, name, description, price, original_price, tag_label, tag_color, tag_text_color, card_background, image, image_mime, position, is_active, auto_background_removed, created_at, updated_at FROM product_slides';
    if ($onlyActive) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY position ASC, id ASC';

    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll();

    return array_map('product_slide_row_to_array', $rows ?: []);
}

function fetch_all_product_slides(): array
{
    return fetch_product_slides(false);
}

function find_product_slide(int $id): ?array
{
    $pdo = get_pdo();
    $stmt = $pdo->prepare('SELECT id, name, description, price, original_price, tag_label, tag_color, tag_text_color, card_background, image, image_mime, position, is_active, auto_background_removed, created_at, updated_at FROM product_slides WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    return $row ? product_slide_row_to_array($row) : null;
}

function product_slide_row_to_array(array $row): array
{
    $price = $row['price'] !== null ? (float) $row['price'] : null;
    $original = $row['original_price'] !== null ? (float) $row['original_price'] : null;
    $discountPercent = null;

    if ($price !== null && $original !== null && $original > 0 && $price < $original) {
        $discountPercent = max(0, (int) round(100 - (($price / $original) * 100)));
    }

    return [
        'id' => (int) $row['id'],
        'name' => $row['name'],
        'description' => $row['description'],
        'price' => $price,
        'original_price' => $original,
        'discount_percent' => $discountPercent,
        'tag_label' => $row['tag_label'],
        'tag_color' => $row['tag_color'],
        'tag_text_color' => $row['tag_text_color'],
        'card_background' => $row['card_background'],
        'image_data_url' => product_binary_to_data_url($row['image'], $row['image_mime']),
        'position' => (int) $row['position'],
        'is_active' => (int) $row['is_active'] === 1,
        'auto_background_removed' => (int) $row['auto_background_removed'] === 1,
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
    ];
}

function product_binary_to_data_url($binary, ?string $mime): ?string
{
    if (!$binary) {
        return null;
    }

    $mime = $mime ?: 'image/png';
    return sprintf('data:%s;base64,%s', $mime, base64_encode($binary));
}
