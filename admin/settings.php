<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signage.php';

require_login();

$activePage = 'settings';
$user = current_user();
$errors = [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$settings = get_signage_settings();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = get_pdo();

    if (isset($_POST['action']) && $_POST['action'] === 'update') {
        $organization = trim($_POST['organization_name'] ?? '');
        if ($organization === '') {
            $errors[] = 'Kurum adı boş bırakılamaz.';
        }

        $logoData = null;
        $logoMime = null;

        if (!empty($_FILES['logo']['tmp_name'])) {
            if (!is_uploaded_file($_FILES['logo']['tmp_name'])) {
                $errors[] = 'Logo yüklenirken hata oluştu.';
            } else {
                $allowedTypes = ['image/png', 'image/jpeg', 'image/svg+xml', 'image/webp'];
                $mime = mime_content_type($_FILES['logo']['tmp_name']);
                if (!in_array($mime, $allowedTypes, true)) {
                    $errors[] = 'Desteklenmeyen logo formatı.';
                } elseif ($_FILES['logo']['size'] > 1024 * 1024) {
                    $errors[] = 'Logo boyutu en fazla 1MB olmalıdır.';
                } else {
                    $logoData = file_get_contents($_FILES['logo']['tmp_name']);
                    $logoMime = $mime;
                }
            }
        }

        if (!$errors) {
            $stmt = $pdo->prepare('UPDATE signage_settings SET organization_name = :name, logo = COALESCE(:logo, logo), logo_mime = COALESCE(:logo_mime, logo_mime) WHERE id = 1');
            $stmt->bindValue(':name', $organization);
            if ($logoData !== null) {
                $stmt->bindValue(':logo', $logoData, PDO::PARAM_LOB);
                $stmt->bindValue(':logo_mime', $logoMime);
            } else {
                $stmt->bindValue(':logo', null, PDO::PARAM_NULL);
                $stmt->bindValue(':logo_mime', null, PDO::PARAM_NULL);
            }
            $stmt->execute();

            $_SESSION['flash'] = 'Tema ayarları güncellendi.';
            header('Location: ' . route_url('admin/settings.php'));
            exit;
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'remove_logo') {
        $pdo->exec('UPDATE signage_settings SET logo = NULL, logo_mime = NULL WHERE id = 1');
        $_SESSION['flash'] = 'Logo kaldırıldı.';
        header('Location: ' . route_url('admin/settings.php'));
        exit;
    }

    $settings = get_signage_settings();
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tema Ayarları | Signage</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/styles.css'), ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body>
<div class="admin-layout">
    <?php include __DIR__ . '/partials/sidebar.php'; ?>
    <main class="content">
        <header style="margin-bottom: 2rem;">
            <h1 style="color: var(--color-primary);">Tema ve Logo Ayarları</h1>
            <p>Kurumsal kimliği güncelle.</p>
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
            <div class="alert" style="background: rgba(255, 212, 0, 0.15); border: 1px solid rgba(255, 212, 0, 0.5);">
                <?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <section class="card" style="max-width: 720px;">
            <form method="post" enctype="multipart/form-data" class="form-grid">
                <input type="hidden" name="action" value="update">
                <div style="grid-column: 1 / -1;">
                    <label for="organization_name">Kurum Adı</label>
                    <input type="text" id="organization_name" name="organization_name" value="<?php echo htmlspecialchars($settings['organization_name'], ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div>
                    <label for="logo">Logo</label>
                    <input type="file" name="logo" id="logo" accept="image/*">
                    <small>PNG, JPG, SVG veya WEBP (maks. 1MB)</small>
                </div>
                <div style="display:flex;align-items:center;gap:1rem;">
                    <?php if (!empty($settings['logo_data_url'])): ?>
                        <img src="<?php echo htmlspecialchars($settings['logo_data_url'], ENT_QUOTES, 'UTF-8'); ?>" alt="Logo önizleme" style="width:140px;height:80px;object-fit:contain;">
                    <?php else: ?>
                        <span style="color: rgba(255, 255, 255, 0.7); font-style: italic;">Logo yüklenmedi.</span>
                    <?php endif; ?>
                </div>
                <div style="grid-column: 1 / -1; display: flex; gap: 1rem;">
                    <button type="submit" class="button button-primary">Kaydet</button>
                    <?php if (!empty($settings['logo_data_url'])): ?>
                        <button type="submit" name="action" value="remove_logo" class="button button-secondary" onclick="return confirm('Logo kaldırılacak. Emin misiniz?');">Logoyu Kaldır</button>
                    <?php endif; ?>
                </div>
            </form>
        </section>
    </main>
</div>
</body>
</html>
