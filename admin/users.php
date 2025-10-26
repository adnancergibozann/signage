<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();

$activePage = 'users';
$user = current_user();
$passwordErrors = [];
$userErrors = [];
$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$pdo = get_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'change_password') {
        $currentPassword = trim($_POST['current_password'] ?? '');
        $newPassword = trim($_POST['new_password'] ?? '');
        $newPasswordConfirm = trim($_POST['new_password_confirm'] ?? '');

        if ($currentPassword === '' || $newPassword === '' || $newPasswordConfirm === '') {
            $passwordErrors[] = 'Tüm şifre alanlarını doldurmalısın.';
        }

        if (strlen($newPassword) > 0 && strlen($newPassword) < 8) {
            $passwordErrors[] = 'Yeni şifre en az 8 karakter olmalıdır.';
        }

        if ($newPassword !== $newPasswordConfirm) {
            $passwordErrors[] = 'Yeni şifre ve tekrarı eşleşmiyor.';
        }

        if (!$passwordErrors) {
            $stmt = $pdo->prepare('SELECT password_hash FROM admin_users WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $user['id']]);
            $row = $stmt->fetch();

            if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
                $passwordErrors[] = 'Mevcut şifreyi doğru girdiğinden emin ol.';
            } else {
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $update = $pdo->prepare('UPDATE admin_users SET password_hash = :hash WHERE id = :id');
                $update->execute([
                    'hash' => $newHash,
                    'id' => $user['id'],
                ]);

                $_SESSION['flash_success'] = 'Şifren başarıyla güncellendi.';
                header('Location: ' . route_url('admin/users.php'));
                exit;
            }
        }
    }

    if ($action === 'create_user') {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $passwordConfirm = trim($_POST['password_confirm'] ?? '');

        if ($username === '') {
            $userErrors[] = 'Kullanıcı adı zorunludur.';
        } elseif (strlen($username) < 3) {
            $userErrors[] = 'Kullanıcı adı en az 3 karakter olmalıdır.';
        }

        if ($password === '' || $passwordConfirm === '') {
            $userErrors[] = 'Şifre alanlarını doldurmalısın.';
        } elseif (strlen($password) < 8) {
            $userErrors[] = 'Şifre en az 8 karakter olmalıdır.';
        }

        if ($password !== $passwordConfirm) {
            $userErrors[] = 'Şifre tekrarı eşleşmiyor.';
        }

        if (!$userErrors) {
            $check = $pdo->prepare('SELECT COUNT(*) FROM admin_users WHERE username = :username');
            $check->execute(['username' => $username]);
            if ($check->fetchColumn()) {
                $userErrors[] = 'Bu kullanıcı adı zaten kullanılıyor.';
            } else {
                $insert = $pdo->prepare('INSERT INTO admin_users (username, password_hash) VALUES (:username, :hash)');
                $insert->execute([
                    'username' => $username,
                    'hash' => password_hash($password, PASSWORD_DEFAULT),
                ]);

                $_SESSION['flash_success'] = 'Yeni kullanıcı oluşturuldu.';
                header('Location: ' . route_url('admin/users.php'));
                exit;
            }
        }
    }
}

$users = $pdo->query('SELECT id, username, created_at FROM admin_users ORDER BY username ASC')->fetchAll();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kullanıcı Yönetimi | Signage</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/styles.css'), ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body>
<div class="admin-layout">
    <?php include __DIR__ . '/partials/sidebar.php'; ?>
    <main class="content">
        <header style="margin-bottom: 2rem;">
            <h1 style="color: var(--color-primary);">Kullanıcı Yönetimi</h1>
            <p>Yeni kullanıcı ekle ve şifreni güncelle.</p>
        </header>

        <?php if ($flashSuccess): ?>
            <div class="alert" style="background: rgba(46, 204, 113, 0.15); border:1px solid rgba(46, 204, 113, 0.4);">
                <?php echo htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <?php if ($flashError): ?>
            <div class="alert alert-error">
                <?php echo htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <section class="card" style="margin-bottom: 2rem;">
            <h2>Şifreni Değiştir</h2>
            <?php if ($passwordErrors): ?>
                <div class="alert alert-error">
                    <ul>
                        <?php foreach ($passwordErrors as $error): ?>
                            <li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <form method="post" class="form-grid">
                <input type="hidden" name="action" value="change_password">
                <div>
                    <label for="current_password">Mevcut Şifre</label>
                    <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
                </div>
                <div>
                    <label for="new_password">Yeni Şifre</label>
                    <input type="password" id="new_password" name="new_password" required autocomplete="new-password">
                </div>
                <div>
                    <label for="new_password_confirm">Yeni Şifre (Tekrar)</label>
                    <input type="password" id="new_password_confirm" name="new_password_confirm" required autocomplete="new-password">
                </div>
                <div style="grid-column: 1 / -1;">
                    <button type="submit" class="button button-primary">Şifreyi Güncelle</button>
                </div>
            </form>
        </section>

        <section class="card">
            <h2>Yeni Kullanıcı Ekle</h2>
            <?php if ($userErrors): ?>
                <div class="alert alert-error">
                    <ul>
                        <?php foreach ($userErrors as $error): ?>
                            <li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <form method="post" class="form-grid">
                <input type="hidden" name="action" value="create_user">
                <div>
                    <label for="username">Kullanıcı Adı</label>
                    <input type="text" id="username" name="username" minlength="3" required>
                </div>
                <div>
                    <label for="password">Şifre</label>
                    <input type="password" id="password" name="password" minlength="8" required autocomplete="new-password">
                </div>
                <div>
                    <label for="password_confirm">Şifre (Tekrar)</label>
                    <input type="password" id="password_confirm" name="password_confirm" minlength="8" required autocomplete="new-password">
                </div>
                <div style="grid-column: 1 / -1;">
                    <button type="submit" class="button button-primary">Kullanıcı Oluştur</button>
                </div>
            </form>
        </section>

        <section class="card" style="margin-top: 2rem;">
            <h2>Var Olan Kullanıcılar</h2>
            <?php if (!$users): ?>
                <p>Henüz kullanıcı eklenmedi.</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Kullanıcı Adı</th>
                            <th>Oluşturulma</th>
                            <th>Durum</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($row['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo ((int) $row['id'] === (int) $user['id']) ? 'Siz' : ''; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </main>
</div>
</body>
</html>
