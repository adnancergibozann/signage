<?php
require_once __DIR__ . '/../includes/products.php';

$slides = fetch_product_slides(true);
$slideCount = count($slides);
$marqueeDuration = max(20, $slideCount * 8);
$renderSlides = $slideCount ? array_merge($slides, $slides) : [];

function esc_html(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LED Ürün İndirimleri</title>
    <link rel="stylesheet" href="/assets/product-slider.css">
    <style>
        :root {
            --marquee-duration: <?php echo (int) $marqueeDuration; ?>s;
        }
    </style>
</head>
<body class="product-led-body">
<div class="product-led-stage">
    <?php if (!$slides): ?>
        <div class="product-led-empty">
            <p>Henüz ürün eklenmedi.</p>
        </div>
    <?php else: ?>
        <div class="product-led-marquee">
            <div class="product-led-track">
                <?php foreach ($renderSlides as $index => $product): ?>
                    <article class="product-led-card" style="--card-background: <?php echo esc_html($product['card_background']); ?>;">
                        <div class="product-led-media">
                            <?php if (!empty($product['image_data_url'])): ?>
                                <img src="<?php echo esc_html($product['image_data_url']); ?>" alt="<?php echo esc_html($product['name']); ?>" loading="lazy">
                            <?php else: ?>
                                <div class="product-led-media-placeholder">Görsel Yok</div>
                            <?php endif; ?>
                        </div>
                        <div class="product-led-details">
                            <?php if (!empty($product['tag_label'])): ?>
                                <span class="product-led-badge" style="--badge-background: <?php echo esc_html($product['tag_color']); ?>; --badge-color: <?php echo esc_html($product['tag_text_color']); ?>;">
                                    <?php echo esc_html($product['tag_label']); ?>
                                </span>
                            <?php endif; ?>
                            <h2 class="product-led-name"><?php echo esc_html($product['name']); ?></h2>
                            <?php if (!empty($product['description'])): ?>
                                <p class="product-led-description"><?php echo esc_html($product['description']); ?></p>
                            <?php endif; ?>
                            <div class="product-led-pricing">
                                <?php if ($product['price'] !== null): ?>
                                    <span class="product-led-price"><?php echo number_format($product['price'], 2, ',', '.'); ?> ₺</span>
                                <?php endif; ?>
                                <?php if ($product['original_price'] !== null && $product['original_price'] > 0 && $product['price'] !== null && $product['price'] < $product['original_price']): ?>
                                    <span class="product-led-original"><?php echo number_format($product['original_price'], 2, ',', '.'); ?> ₺</span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($product['discount_percent'])): ?>
                                <p class="product-led-percent">%<?php echo (int) $product['discount_percent']; ?> indirim</p>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
