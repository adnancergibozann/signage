<?php
require_once __DIR__ . '/../includes/messages.php';

$messages = fetch_messages();
$speed = $messages ? max(array_column($messages, 'speed')) : 30;
$speed = max(5, min(60, (int) $speed));
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Signage Widget</title>
    <link rel="stylesheet" href="../assets/styles.css">
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
    <div class="widget-wrapper">
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
