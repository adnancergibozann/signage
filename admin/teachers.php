<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signage.php';

require_login();

$activePage = 'teachers';
$user = current_user();
$errors = [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pdo = get_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $branch = trim($_POST['branch'] ?? '');
        $isOnDuty = isset($_POST['is_on_duty']) ? 1 : 0;

        if ($firstName === '' || $lastName === '' || $branch === '') {
            $errors[] = 'Ad, soyad ve branş alanları zorunludur.';
        }

        $photo = null;
        $photoMime = null;
        if (!empty($_FILES['photo']['tmp_name']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
            $mime = mime_content_type($_FILES['photo']['tmp_name']);
            $allowed = ['image/png', 'image/jpeg', 'image/webp'];
            if (!in_array($mime, $allowed, true)) {
                $errors[] = 'Fotoğraf formatı desteklenmiyor.';
            } elseif ($_FILES['photo']['size'] > 1024 * 1024) {
                $errors[] = 'Fotoğraf boyutu 1MB sınırını aşmamalıdır.';
            } else {
                $photo = file_get_contents($_FILES['photo']['tmp_name']);
                $photoMime = $mime;
            }
        }

        if (!$errors) {
            $stmt = $pdo->prepare('INSERT INTO teachers (first_name, last_name, branch, photo, photo_mime, is_on_duty, position) VALUES (:first, :last, :branch, :photo, :mime, :duty, :position)');
            $stmt->bindValue(':first', $firstName);
            $stmt->bindValue(':last', $lastName);
            $stmt->bindValue(':branch', $branch);
            $stmt->bindValue(':photo', $photo, $photo !== null ? PDO::PARAM_LOB : PDO::PARAM_NULL);
            $stmt->bindValue(':mime', $photoMime);
            $stmt->bindValue(':duty', $isOnDuty, PDO::PARAM_INT);
            $stmt->bindValue(':position', 1 + (int) $pdo->query('SELECT COALESCE(MAX(position), 0) FROM teachers')->fetchColumn(), PDO::PARAM_INT);
            $stmt->execute();

            $_SESSION['flash'] = 'Öğretmen eklendi.';
            header('Location: /admin/teachers.php');
            exit;
        }
    }

    if ($action === 'toggle_duty') {
        $id = (int) ($_POST['id'] ?? 0);
        $state = isset($_POST['value']) && $_POST['value'] === '1' ? 1 : 0;
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE teachers SET is_on_duty = :state WHERE id = :id');
            $stmt->execute(['state' => $state, 'id' => $id]);
            $_SESSION['flash'] = 'Nöbet durumu güncellendi.';
            header('Location: /admin/teachers.php');
            exit;
        }
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM teachers WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $_SESSION['flash'] = 'Öğretmen silindi.';
            header('Location: /admin/teachers.php');
            exit;
        }
    }
}

$teachers = $pdo->query('SELECT id, first_name, last_name, branch, is_on_duty FROM teachers ORDER BY position ASC, last_name ASC')->fetchAll();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Öğretmen Yönetimi | Signage</title>
    <link rel="stylesheet" href="/assets/styles.css">
</head>
<body>
<div class="admin-layout">
    <?php include __DIR__ . '/partials/sidebar.php'; ?>
    <main class="content">
        <header style="margin-bottom: 2rem;">
            <h1 style="color: var(--color-primary);">Öğretmen Yönetimi</h1>
            <p>Nöbetçi öğretmenleri düzenle.</p>
        </header>

        <?php if ($errors): ?>
            <div class="alert alert-error">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($flash): ?>
            <div class="alert" style="background: rgba(255,212,0,0.15); border:1px solid rgba(255,212,0,0.5);">
                <?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <section class="card" style="margin-bottom: 2rem;">
            <h2>Yeni Öğretmen</h2>
            <form method="post" enctype="multipart/form-data" class="form-grid">
                <input type="hidden" name="action" value="create">
                <div>
                    <label for="first_name">Ad</label>
                    <input type="text" id="first_name" name="first_name" required>
                </div>
                <div>
                    <label for="last_name">Soyad</label>
                    <input type="text" id="last_name" name="last_name" required>
                </div>
                <div>
                    <label for="branch">Branş</label>
                    <input type="text" id="branch" name="branch" required>
                </div>
                <div>
                    <label for="photo">Fotoğraf</label>
                    <input type="file" id="photo" name="photo" accept="image/*">
                </div>
                <div style="align-self: center;">
                    <label class="flex" style="gap:0.5rem; align-items:center;">
                        <input type="checkbox" name="is_on_duty" value="1"> Bugün nöbetçi
                    </label>
                </div>
                <div style="grid-column: 1 / -1;">
                    <button type="submit" class="button button-primary">Kaydet</button>
                </div>
            </form>
        </section>

        <section class="card">
            <h2>Öğretmen Listesi</h2>
            <?php if (!$teachers): ?>
                <p>Henüz öğretmen eklenmedi.</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Ad Soyad</th>
                            <th>Branş</th>
                            <th>Nöbet</th>
                            <th>İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($teachers as $teacher): ?>
                        <tr>
                            <td><?php echo (int) $teacher['id']; ?></td>
                            <td><?php echo htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($teacher['branch'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <form method="post">
                                    <input type="hidden" name="action" value="toggle_duty">
                                    <input type="hidden" name="id" value="<?php echo (int) $teacher['id']; ?>">
                                    <input type="hidden" name="value" value="<?php echo $teacher['is_on_duty'] ? '0' : '1'; ?>">
                                    <button type="submit" class="button button-secondary">
                                        <?php echo $teacher['is_on_duty'] ? 'Pasifleştir' : 'Nöbetçi Yap'; ?>
                                    </button>
                                </form>
                            </td>
                            <td>
                                <form method="post" onsubmit="return confirm('Bu öğretmen silinsin mi?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int) $teacher['id']; ?>">
                                    <button type="submit" class="button button-secondary">Sil</button>
                                </form>
                            </td>
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
