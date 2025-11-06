<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

require_login();
require_role('super_admin');

$pageTitle = 'Kullanıcı Yönetimi';
$activePage = 'users';
$pdo = get_pdo();
$message = null;
$error = null;
$roleOptions = [
    'manager' => 'Satınalma Müdürü',
    'finance' => 'Finans',
    'accounting' => 'Muhasebe',
    'secretary' => 'Sekreter',
    'boss' => 'Patron',
    'super_admin' => 'Süper Admin',
    'viewer' => 'Signage İzleyici',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create') {
            $message = handle_create_user($pdo);
        } elseif ($action === 'update') {
            $message = handle_update_user($pdo);
        } elseif ($action === 'delete') {
            $message = handle_delete_user($pdo);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$stmt = $pdo->query('SELECT id, username, role, full_name, department, email, phone, photo_path FROM users ORDER BY created_at DESC');
$users = $stmt->fetchAll();

include __DIR__ . '/partials/header.php';
?>
<?php if ($message): ?><div class="alert success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<section class="card">
    <h3>Yeni Kullanıcı</h3>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="create">
        <div class="form-grid">
            <div>
                <label for="username">Kullanıcı adı</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div>
                <label for="password">Şifre</label>
                <input type="password" id="password" name="password" required>
            </div>
            <div>
                <label for="full_name">Ad Soyad</label>
                <input type="text" id="full_name" name="full_name" required>
            </div>
            <div>
                <label for="role">Rol</label>
                <select id="role" name="role" required>
                    <?php foreach ($roleOptions as $value => $label): ?>
                        <option value="<?= htmlspecialchars($value) ?>"<?= $value === 'manager' ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="department">Departman</label>
                <input type="text" id="department" name="department">
            </div>
            <div>
                <label for="email">E-posta</label>
                <input type="email" id="email" name="email">
            </div>
            <div>
                <label for="phone">Telefon</label>
                <input type="text" id="phone" name="phone">
            </div>
            <div>
                <label for="photo">Profil Fotoğrafı</label>
                <input type="file" id="photo" name="photo" accept="image/*">
            </div>
        </div>
        <div class="actions">
            <button class="button" type="submit">Kullanıcıyı Kaydet</button>
        </div>
    </form>
</section>

<section class="card">
    <h3>Mevcut Kullanıcılar</h3>
    <table class="table">
        <thead>
            <tr>
                <th>Ad</th>
                <th>Kullanıcı</th>
                <th>Rol</th>
                <th>Departman</th>
                <th>E-posta</th>
                <th>Telefon</th>
                <th>İşlemler</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $userRow): ?>
            <tr>
                <td><?= htmlspecialchars($userRow['full_name']) ?></td>
                <td><?= htmlspecialchars($userRow['username']) ?></td>
                <td><?= htmlspecialchars($roleOptions[$userRow['role']] ?? $userRow['role']) ?></td>
                <td><?= htmlspecialchars($userRow['department'] ?? '') ?></td>
                <td><?= htmlspecialchars($userRow['email'] ?? '') ?></td>
                <td><?= htmlspecialchars($userRow['phone'] ?? '') ?></td>
                <td>
                    <form method="post" enctype="multipart/form-data" class="user-update-form">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?= (int) $userRow['id'] ?>">
                        <div>
                            <label>Ad Soyad</label>
                            <input type="text" name="full_name" value="<?= htmlspecialchars($userRow['full_name']) ?>" required>
                        </div>
                        <div>
                            <label>Rol</label>
                            <select name="role" required>
                                <?php foreach ($roleOptions as $value => $label): ?>
                                    <option value="<?= htmlspecialchars($value) ?>"<?= $userRow['role'] === $value ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Departman</label>
                            <input type="text" name="department" value="<?= htmlspecialchars($userRow['department'] ?? '') ?>">
                        </div>
                        <div>
                            <label>E-posta</label>
                            <input type="email" name="email" value="<?= htmlspecialchars($userRow['email'] ?? '') ?>">
                        </div>
                        <div>
                            <label>Telefon</label>
                            <input type="text" name="phone" value="<?= htmlspecialchars($userRow['phone'] ?? '') ?>">
                        </div>
                        <div>
                            <label>Yeni Şifre</label>
                            <input type="password" name="password" placeholder="Değiştirmek için doldurun">
                        </div>
                        <div>
                            <label>Profil Fotoğrafı</label>
                            <input type="file" name="photo" accept="image/*">
                        </div>
                        <div class="actions">
                            <button class="button" type="submit">Güncelle</button>
                        </div>
                    </form>
                    <form method="post" onsubmit="return confirm('Bu kullanıcı silinsin mi?');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $userRow['id'] ?>">
                        <button class="button danger" type="submit">Sil</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php
include __DIR__ . '/partials/footer.php';

function handle_create_user(PDO $pdo): string
{
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $fullName = trim($_POST['full_name'] ?? '');
    $role = $_POST['role'] ?? 'manager';
    $allowedRoles = ['manager', 'finance', 'accounting', 'secretary', 'boss', 'super_admin', 'viewer'];
    if ($username === '' || $password === '' || $fullName === '') {
        throw new RuntimeException('Zorunlu alanlar eksik.');
    }
    if (!in_array($role, $allowedRoles, true)) {
        throw new RuntimeException('Geçersiz rol seçimi.');
    }
    $photoFilename = null;
    if (!empty($_FILES['photo']['name'])) {
        $upload = upload_file($_FILES['photo'], public_path('uploads/profile'), ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], ['jpg','jpeg','png','gif','webp']);
        if (!$upload) {
            throw new RuntimeException('Fotoğraf yüklenemedi.');
        }
        $photoFilename = $upload;
    }
    $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, role, full_name, department, email, phone, photo_path)
        VALUES (:username, :password_hash, :role, :full_name, :department, :email, :phone, :photo_path)');
    $stmt->execute([
        'username' => $username,
        'password_hash' => password_hash($password, PASSWORD_BCRYPT),
        'role' => $role,
        'full_name' => $fullName,
        'department' => trim($_POST['department'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'photo_path' => $photoFilename,
    ]);
    $userId = (int) $pdo->lastInsertId();
    if (is_manager_role($role)) {
        ensure_manager_status_row($userId);
    }
    record_syslog('user.create', sprintf('%s kullanıcısı oluşturuldu.', $username), null, [
        'targetUserId' => $userId,
        'role' => $role,
    ]);
    return 'Kullanıcı eklendi.';
}

function handle_update_user(PDO $pdo): string
{
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException('Geçersiz kullanıcı.');
    }
    $stmt = $pdo->prepare('SELECT photo_path, role FROM users WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $existing = $stmt->fetch();
    if (!$existing) {
        throw new RuntimeException('Kullanıcı bulunamadı.');
    }

    $photoPath = $existing['photo_path'];
    if (!empty($_FILES['photo']['name'])) {
        $upload = upload_file($_FILES['photo'], public_path('uploads/profile'), ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], ['jpg','jpeg','png','gif','webp']);
        if (!$upload) {
            throw new RuntimeException('Fotoğraf yüklenemedi.');
        }
        if ($photoPath) {
            @unlink(public_path('uploads/profile/' . $photoPath));
        }
        $photoPath = $upload;
    }

    $password = $_POST['password'] ?? '';
    $updateFields = [
        'full_name' => trim($_POST['full_name'] ?? ''),
        'role' => $_POST['role'] ?? $existing['role'],
        'department' => trim($_POST['department'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'photo_path' => $photoPath,
        'id' => $id,
    ];

    $allowedRoles = ['manager', 'finance', 'accounting', 'secretary', 'boss', 'super_admin', 'viewer'];
    if (!in_array($updateFields['role'], $allowedRoles, true)) {
        throw new RuntimeException('Geçersiz rol seçimi.');
    }

    $set = 'full_name = :full_name, role = :role, department = :department, email = :email, phone = :phone, photo_path = :photo_path';
    if ($password !== '') {
        $updateFields['password_hash'] = password_hash($password, PASSWORD_BCRYPT);
        $set .= ', password_hash = :password_hash';
    }

    $stmt = $pdo->prepare("UPDATE users SET $set WHERE id = :id");
    $stmt->execute($updateFields);

    if (is_manager_role($updateFields['role'])) {
        ensure_manager_status_row($id);
    }

    record_syslog('user.update', sprintf('%s kullanıcısı güncellendi.', $updateFields['full_name']), null, [
        'targetUserId' => $id,
        'role' => $updateFields['role'],
    ]);

    return 'Kullanıcı güncellendi.';
}

function handle_delete_user(PDO $pdo): string
{
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException('Geçersiz kullanıcı.');
    }
    $stmt = $pdo->prepare('SELECT photo_path FROM users WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $user = $stmt->fetch();
    if (!$user) {
        throw new RuntimeException('Kullanıcı bulunamadı.');
    }
    if ($user['photo_path']) {
        @unlink(public_path('uploads/profile/' . $user['photo_path']));
    }
    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $id]);
    record_syslog('user.delete', sprintf('ID %d kullanıcısı silindi.', $id), null, [
        'targetUserId' => $id,
    ]);
    return 'Kullanıcı silindi.';
}
