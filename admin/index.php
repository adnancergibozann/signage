<?php
session_start();

$configPath = __DIR__ . '/../config/settings.json';
$authConfigPath = __DIR__ . '/../config/admin.php';
$error = null;
$success = false;
$loginError = null;

if (!file_exists($authConfigPath)) {
    $defaultAuth = <<<'PHP'
<?php
return [
    'password_hash' => '$2y$12$bMEjKiNVmPeh7kdp2hmoEenyKTMhfQ4E/2nxAq4EResm6NnAwyQ/e',
    'hint' => 'Varsayılan parola: admin123',
];
PHP;
    file_put_contents($authConfigPath, $defaultAuth);
}

$authConfig = include $authConfigPath;

if (isset($_POST['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: index.php');
    exit;
}

if (!($_SESSION['admin_logged_in'] ?? false)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        $password = (string) ($_POST['password'] ?? '');
        if (isset($authConfig['password_hash']) && password_verify($password, $authConfig['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_logged_in'] = true;
        } else {
            $loginError = 'Parola geçersiz. Lütfen tekrar deneyin.';
        }
    }

    if (!($_SESSION['admin_logged_in'] ?? false)) {
        $hint = $authConfig['hint'] ?? null;
        ?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yönetici Girişi - Kayar Yazı</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="admin-body">
    <div class="admin-container">
        <h1>Yönetici Girişi</h1>
        <p class="admin-description">Yönetim paneline erişmek için parolayı girin.</p>
        <?php if ($loginError): ?>
            <div class="alert alert-error">❌ <?php echo htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <form method="post" class="admin-form">
            <label for="password">Parola</label>
            <input type="password" id="password" name="password" required>
            <button type="submit" class="btn-submit">Giriş Yap</button>
        </form>
        <?php if ($hint): ?>
            <p class="admin-hint">💡 <?php echo htmlspecialchars($hint, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
    </div>
</body>
</html>
        <?php
        exit;
    }
}

if (!file_exists($configPath)) {
    $defaultConfig = [
        'message' => 'Hoş geldiniz!',
        'speed' => 30,
        'fontSize' => 48,
    ];
    file_put_contents($configPath, json_encode($defaultConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

$config = json_decode(file_get_contents($configPath), true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = trim($_POST['message'] ?? '');
    $speed = (int) ($_POST['speed'] ?? $config['speed']);
    $fontSize = (int) ($_POST['fontSize'] ?? $config['fontSize']);

    if ($message === '') {
        $error = 'Lütfen bir mesaj girin.';
    } elseif ($speed < 5 || $speed > 200) {
        $error = 'Hız 5 ile 200 arasında olmalıdır.';
    } elseif ($fontSize < 16 || $fontSize > 120) {
        $error = 'Yazı boyutu 16 ile 120 arasında olmalıdır.';
    } else {
        $config = [
            'message' => $message,
            'speed' => $speed,
            'fontSize' => $fontSize,
        ];

        $written = file_put_contents(
            $configPath,
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        if ($written === false) {
            $error = 'Ayarlar kaydedilirken bir hata oluştu. Dosya izinlerini kontrol edin.';
        } else {
            $success = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yönetici Paneli - Kayar Yazı</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="admin-body">
    <div class="admin-container">
        <h1>Kayar Yazı Yönetimi</h1>
        <p class="admin-description">Aşağıdaki formdan ana sayfadaki kayar yazının içeriğini, hızını ve yazı boyutunu belirleyebilirsiniz.</p>

        <?php if ($error): ?>
            <div class="alert alert-error">❌ <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php elseif ($success): ?>
            <div class="alert alert-success">✅ Ayarlar başarıyla güncellendi.</div>
        <?php endif; ?>

        <form method="post" class="admin-form">
            <label for="message">Mesaj</label>
            <textarea id="message" name="message" rows="4" required><?php echo htmlspecialchars($config['message'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>

            <label for="speed">Hız (5 - 200 piksel/sn)</label>
            <input type="number" id="speed" name="speed" min="5" max="200" value="<?php echo (int) ($config['speed'] ?? 30); ?>" required>

            <label for="fontSize">Yazı Boyutu (16 - 120 px)</label>
            <input type="number" id="fontSize" name="fontSize" min="16" max="120" value="<?php echo (int) ($config['fontSize'] ?? 48); ?>" required>

            <button type="submit" class="btn-submit">Kaydet</button>
        </form>

        <a class="preview-link" href="../index.php" target="_blank">Ana Sayfayı Görüntüle</a>
        <a class="preview-link" href="../widget.php" target="_blank">Widget Önizlemesini Aç</a>
        <form method="post" class="logout-form">
            <button type="submit" name="logout" class="btn-logout">Oturumu Kapat</button>
        </form>
    </div>
</body>
</html>
