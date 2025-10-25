<?php
$configPath = __DIR__ . '/config/settings.json';

if (!file_exists($configPath)) {
    $defaultConfig = [
        'message' => 'Hoş geldiniz!',
        'speed' => 30,
        'fontSize' => 48,
    ];
    file_put_contents($configPath, json_encode($defaultConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

$config = json_decode(file_get_contents($configPath), true) ?? [];
$message = $config['message'] ?? 'Hoş geldiniz!';
$speed = (int) ($config['speed'] ?? 30);
$fontSize = (int) ($config['fontSize'] ?? 48);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kayar Yazı Widget</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="widget-body">
    <div class="marquee-wrapper">
        <div class="marquee">
            <div class="marquee-track" data-marquee-speed="<?php echo $speed; ?>" style="font-size: <?php echo $fontSize; ?>px;">
                <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        </div>
    </div>

    <script src="assets/js/marquee.js"></script>
</body>
</html>
