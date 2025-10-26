<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/signage.php';

$settings = get_signage_settings();
$logoUrl = $settings['logo_data_url'] ?? null;
$organizationName = trim((string) ($settings['organization_name'] ?? 'Signage'));

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (attempt_login($username, $password)) {
        header('Location: ' . route_url('admin/dashboard.php'));
        exit;
    }

    $error = 'Kullanıcı adı veya şifre hatalı.';
}

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yönetim Girişi | Signage</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/styles.css'), ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body class="auth-body">
    <main class="auth-container">
        <?php if ($logoUrl): ?>
            <img src="<?php echo htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($organizationName ?: 'Logo', ENT_QUOTES, 'UTF-8'); ?>" class="auth-logo">
        <?php endif; ?>
        <?php if ($organizationName !== ''): ?>
            <p class="auth-organization"><?php echo htmlspecialchars($organizationName, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
        <h1>Panel Girişi</h1>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <form method="post" class="card">
            <label for="username">Kullanıcı Adı</label>
            <input type="text" id="username" name="username" required autofocus>

            <label for="password">Şifre</label>
            <input type="password" id="password" name="password" required>

            <button type="submit" class="button button-primary">Giriş Yap</button>
        </form>
    </main>
</body>
</html>
