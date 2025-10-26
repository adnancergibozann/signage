<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/messages.php';

require_login();
$activePage = 'ticker';

$allowed_colors = [
    '#FFD400' => 'Sarı',
    '#0A0A0A' => 'Siyah',
    '#FFFFFF' => 'Beyaz',
];

$errors = [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'create') {
        $title = trim($_POST['title'] ?? '');
        $body = trim($_POST['body'] ?? '');
        $text_color = $_POST['text_color'] ?? '#FFFFFF';
        $background_color = $_POST['background_color'] ?? '#0A0A0A';
        $speed = (int) ($_POST['speed'] ?? 30);

        if ($title === '') {
            $errors[] = 'Başlık alanı zorunludur.';
        }

        if (!array_key_exists($text_color, $allowed_colors)) {
            $errors[] = 'Yazı rengi geçerli değil.';
        }

        if (!array_key_exists($background_color, $allowed_colors)) {
            $errors[] = 'Arka plan rengi geçerli değil.';
        }

        $speed = max(5, min(60, $speed));

        if (!$errors) {
            create_message([
                'title' => $title,
                'body' => $body,
                'text_color' => $text_color,
                'background_color' => $background_color,
                'speed' => $speed,
            ]);

            $_SESSION['flash'] = 'Kayan yazı başarıyla eklendi.';
            header('Location: ' . route_url('admin/dashboard.php'));
            exit;
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id) {
            delete_message($id);
            $_SESSION['flash'] = 'Mesaj silindi.';
            header('Location: ' . route_url('admin/dashboard.php'));
            exit;
        }
    }
}

$messages = fetch_messages();
$user = current_user();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yönetim Paneli | Signage</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/styles.css'), ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body>
    <div class="admin-layout">
        <?php include __DIR__ . '/partials/sidebar.php'; ?>
        <main class="content">
            <header class="flex" style="justify-content: space-between; margin-bottom: 2rem;">
                <div>
                    <h1 style="color: var(--color-primary);">Kayan Yazılar</h1>
                    <p>Yeni mesajlar ekleyebilir, mevcut içerikleri yönetebilirsin.</p>
                </div>
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

            <section class="card" style="margin-bottom: 2rem;">
                <h2>Yeni Kayan Yazı</h2>
                <form method="post" class="form-grid">
                    <input type="hidden" name="action" value="create">
                    <div>
                        <label for="title">Başlık</label>
                        <input type="text" id="title" name="title" required>
                    </div>
                    <div>
                        <label for="body">Detay</label>
                        <textarea id="body" name="body" placeholder="İsteğe bağlı açıklama..."></textarea>
                    </div>
                    <div>
                        <label for="text_color">Yazı Rengi</label>
                        <select id="text_color" name="text_color">
                            <?php foreach ($allowed_colors as $hex => $label): ?>
                                <option value="<?php echo $hex; ?>"><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="background_color">Arka Plan Rengi</label>
                        <select id="background_color" name="background_color">
                            <?php foreach ($allowed_colors as $hex => $label): ?>
                                <option value="<?php echo $hex; ?>" <?php echo $hex === '#0A0A0A' ? 'selected' : ''; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="speed">Kayma Süresi (saniye)</label>
                        <input type="number" id="speed" name="speed" min="5" max="60" value="30">
                    </div>
                    <div style="align-self: end;">
                        <button type="submit" class="button button-primary" style="width: 100%;">Kaydet</button>
                    </div>
                </form>
            </section>

            <section class="card">
                <h2>Kayıtlı Mesajlar</h2>
                <?php if (!$messages): ?>
                    <p>Henüz mesaj eklenmedi.</p>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Başlık</th>
                                <th>Açıklama</th>
                                <th>Renkler</th>
                                <th>Hız</th>
                                <th>İşlem</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($messages as $message): ?>
                                <tr>
                                    <td><?php echo (int) $message['id']; ?></td>
                                    <td><?php echo htmlspecialchars($message['title'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($message['body'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <span class="tag-preview">
                                            <span style="width:14px;height:14px;border-radius:50%;background: <?php echo $message['background_color']; ?>;"></span>
                                            <span style="width:14px;height:14px;border-radius:50%;background: <?php echo $message['text_color']; ?>;"></span>
                                        </span>
                                    </td>
                                    <td><span class="badge"><?php echo (int) $message['speed']; ?>s</span></td>
                                    <td>
                                        <form method="post" onsubmit="return confirm('Bu mesaj silinsin mi?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo (int) $message['id']; ?>">
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
