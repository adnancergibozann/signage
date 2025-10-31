<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if (current_user()) {
    header('Location: ' . default_dashboard_route(current_user()['role']));
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($username === '' || $password === '') {
        $error = 'Kullanıcı adı ve şifre zorunludur.';
    } elseif (attempt_login($username, $password)) {
        $user = current_user();
        header('Location: ' . default_dashboard_route($user['role']));
        exit;
    } else {
        $error = 'Geçersiz kullanıcı adı veya şifre.';
    }
}

function default_dashboard_route(string $role): string
{
    return match ($role) {
        'manager' => url_for('admin/manager.php'),
        'boss' => url_for('admin/dashboard.php'),
        'super_admin' => url_for('admin/dashboard.php'),
        default => url_for('index.php'),
    };
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Gapgross Yönetim Girişi</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="<?= asset_url('assets/css/admin.css?v=1') ?>">
    <style>
        body { display: flex; justify-content: center; align-items: center; }
        .login-card {
            background: rgba(15, 21, 45, 0.9);
            border-radius: 20px;
            padding: 36px;
            width: min(420px, 90%);
            box-shadow: 0 24px 48px rgba(0, 0, 0, 0.35);
        }
        .login-card h1 {
            margin-top: 0;
        }
        .login-card form { display: flex; flex-direction: column; gap: 16px; }
        .login-card button { align-self: stretch; }
    </style>
</head>
<body>
    <div class="login-card">
        <h1>Gapgross Yönetim</h1>
        <?php if ($error): ?>
            <div class="alert error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="post" autocomplete="off">
            <div>
                <label for="username">Kullanıcı adı</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div>
                <label for="password">Şifre</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button class="button" type="submit">Giriş Yap</button>
        </form>
    </div>
</body>
</html>
