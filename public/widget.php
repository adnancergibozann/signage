<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/messages.php';
require_once __DIR__ . '/../includes/signage.php';

$messages = fetch_messages();
$speed = $messages ? max(array_column($messages, 'speed')) : 30;
$speed = max(5, min(60, (int) $speed));
$settings = get_signage_settings();
$tickerFontFamily = $settings['ticker_font_family'] ?? 'Segoe UI, sans-serif';
$tickerFontSize = isset($settings['ticker_font_size']) ? (int) $settings['ticker_font_size'] : 28;
$tickerBorderWidth = isset($settings['ticker_border_width']) ? (int) $settings['ticker_border_width'] : 2;

$tickerFontSize = max(12, min(96, $tickerFontSize));
$tickerBorderWidth = max(0, min(12, $tickerBorderWidth));
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Signage Widget</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/styles.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <style>
        body {
            margin: 0;
            background: transparent;
        }
        .marquee-track {
            animation-duration: <?php echo $speed; ?>s;
        }
    </style>
</head>
<body>
    <div class="widget-wrapper" style="--ticker-font-family: <?php echo htmlspecialchars($tickerFontFamily, ENT_QUOTES, 'UTF-8'); ?>; --ticker-font-size: <?php echo $tickerFontSize; ?>px; --ticker-border-width: <?php echo $tickerBorderWidth; ?>px;">
        <?php if (!$messages): ?>
            <div class="card" style="text-align: center;">İçerik bulunamadı.</div>
        <?php else: ?>
            <div class="marquee">
                <div class="marquee-track">
                    <?php foreach (array_merge($messages, $messages) as $message): ?>
                        <div class="marquee-item" style="background: <?php echo $message['background_color']; ?>; color: <?php echo $message['text_color']; ?>;">
                            <div class="marquee-title"><?php echo htmlspecialchars($message['title'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php if (!empty($message['body'])): ?>
                                <div class="marquee-body"><?php echo htmlspecialchars($message['body'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
