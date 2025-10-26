<?php

require_once __DIR__ . '/../config/database.php';

function signage_layout_defaults(): array
{
    return [
        'logo' => [
            'title' => 'Logo & Kurum Bilgisi',
            'top' => 2.0,
            'left' => 2.0,
            'width' => 22.0,
            'height' => 15.0,
            'font_scale' => 1.0,
            'z_index' => 5,
        ],
        'next_period' => [
            'title' => 'Sonraki Ders/Teneffüs',
            'top' => 19.0,
            'left' => 2.0,
            'width' => 22.0,
            'height' => 16.0,
            'font_scale' => 1.0,
            'z_index' => 4,
        ],
        'teachers' => [
            'title' => 'Nöbetçi Öğretmenler',
            'top' => 38.0,
            'left' => 2.0,
            'width' => 22.0,
            'height' => 30.0,
            'font_scale' => 1.0,
            'z_index' => 3,
        ],
        'news' => [
            'title' => 'Güncel Haberler',
            'top' => 70.0,
            'left' => 2.0,
            'width' => 22.0,
            'height' => 18.0,
            'font_scale' => 1.0,
            'z_index' => 3,
        ],
        'media' => [
            'title' => 'Medya Slayt Alanı',
            'top' => 8.0,
            'left' => 26.0,
            'width' => 44.0,
            'height' => 58.0,
            'font_scale' => 1.0,
            'z_index' => 2,
        ],
        'schedule' => [
            'title' => 'Ders Programı',
            'top' => 8.0,
            'left' => 72.0,
            'width' => 26.0,
            'height' => 58.0,
            'font_scale' => 1.0,
            'z_index' => 3,
        ],
        'weather' => [
            'title' => 'Hava Durumu',
            'top' => 2.0,
            'left' => 72.0,
            'width' => 26.0,
            'height' => 18.0,
            'font_scale' => 1.0,
            'z_index' => 4,
        ],
        'countdowns' => [
            'title' => 'Yaklaşan Etkinlikler',
            'top' => 68.0,
            'left' => 72.0,
            'width' => 26.0,
            'height' => 22.0,
            'font_scale' => 1.0,
            'z_index' => 3,
        ],
        'ticker' => [
            'title' => 'Kayan Yazılar',
            'top' => 90.0,
            'left' => 2.0,
            'width' => 96.0,
            'height' => 8.0,
            'font_scale' => 1.0,
            'z_index' => 6,
        ],
    ];
}

function fetch_signage_layouts(): array
{
    $defaults = signage_layout_defaults();
    $pdo = get_pdo();
    $stmt = $pdo->query('SELECT module_key, top_percent, left_percent, width_percent, height_percent, font_scale, z_index FROM signage_layouts');
    foreach ($stmt->fetchAll() as $row) {
        $key = $row['module_key'];
        if (!isset($defaults[$key])) {
            $defaults[$key] = [
                'title' => ucfirst(str_replace('_', ' ', $key)),
                'top' => 5.0,
                'left' => 5.0,
                'width' => 20.0,
                'height' => 20.0,
                'font_scale' => 1.0,
                'z_index' => 1,
            ];
        }
        $defaults[$key]['top'] = (float) $row['top_percent'];
        $defaults[$key]['left'] = (float) $row['left_percent'];
        $defaults[$key]['width'] = (float) $row['width_percent'];
        $defaults[$key]['height'] = (float) $row['height_percent'];
        $defaults[$key]['font_scale'] = (float) $row['font_scale'];
        $defaults[$key]['z_index'] = (int) $row['z_index'];
    }

    return $defaults;
}

function save_signage_layouts(array $layouts): void
{
    $defaults = signage_layout_defaults();
    $pdo = get_pdo();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare('INSERT INTO signage_layouts (module_key, title, top_percent, left_percent, width_percent, height_percent, font_scale, z_index)
VALUES (:module_key, :title, :top_percent, :left_percent, :width_percent, :height_percent, :font_scale, :z_index)
ON DUPLICATE KEY UPDATE top_percent = VALUES(top_percent), left_percent = VALUES(left_percent), width_percent = VALUES(width_percent), height_percent = VALUES(height_percent), font_scale = VALUES(font_scale), z_index = VALUES(z_index)');

        foreach ($layouts as $key => $values) {
            if (!isset($defaults[$key])) {
                continue;
            }

            $title = $defaults[$key]['title'];
            $top = max(0.0, min(100.0, (float) ($values['top'] ?? $defaults[$key]['top'])));
            $left = max(0.0, min(100.0, (float) ($values['left'] ?? $defaults[$key]['left'])));
            $width = max(5.0, min(100.0, (float) ($values['width'] ?? $defaults[$key]['width'])));
            $height = max(5.0, min(100.0, (float) ($values['height'] ?? $defaults[$key]['height'])));
            $fontScale = max(0.25, min(4.0, (float) ($values['font_scale'] ?? $defaults[$key]['font_scale'])));
            $zIndex = max(0, min(20, (int) ($values['z_index'] ?? $defaults[$key]['z_index'])));

            // Clamp position so module stays within bounds after sizing
            $width = min($width, 100.0 - $left);
            $height = min($height, 100.0 - $top);

            $stmt->execute([
                'module_key' => $key,
                'title' => $title,
                'top_percent' => round($top, 2),
                'left_percent' => round($left, 2),
                'width_percent' => round($width, 2),
                'height_percent' => round($height, 2),
                'font_scale' => round($fontScale, 2),
                'z_index' => $zIndex,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function reset_signage_layouts(): void
{
    $pdo = get_pdo();
    $pdo->exec('DELETE FROM signage_layouts');
}
