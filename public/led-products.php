<?php
require_once __DIR__ . '/../includes/products.php';

try {
    $slides = fetch_product_slides(true);
} catch (Throwable $exception) {
    error_log('LED products bootstrap error: ' . $exception->getMessage());
    http_response_code(500);

    $displayErrors = filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOLEAN);
    $details = $displayErrors ? '<pre>' . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>' : '';
    $helpText = 'Veritabanına erişilemediği için ürün listesi yüklenemedi. MySQL servisinin çalıştığını ve yapılandırma bilgilerinin doğru olduğunu doğrulayın.';

    echo '<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><title>LED ürünler yüklenemedi</title><style>body{font-family:system-ui,sans-serif;background:#0A0A0A;color:#fff;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;padding:0;}main{max-width:560px;padding:28px;background:rgba(17,17,17,0.92);border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,0.45);}h1{margin-top:0;font-size:26px;color:#FFD400;}p{line-height:1.6;font-size:16px;margin:0 0 16px;}pre{background:#111;border-radius:12px;padding:16px;color:#f88;overflow:auto;font-size:14px;}</style></head><body><main><h1>Veri alınamadı</h1><p>' . htmlspecialchars($helpText, ENT_QUOTES, 'UTF-8') . '</p>' . $details . '<p>Bağlantı sağlandıktan sonra sayfayı yenileyerek tekrar deneyebilirsiniz.</p></main></body></html>';
    exit;
}

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
