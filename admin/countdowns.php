<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signage.php';

require_login();

$activePage = 'countdowns';
$user = current_user();
$errors = [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pdo = get_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $title = trim($_POST['title'] ?? '');
        $target = trim($_POST['target_at'] ?? '');
        $icon = trim($_POST['icon'] ?? '');
        $color = trim($_POST['color'] ?? '#FFD400');

        if ($title === '') {
            $errors[] = 'Etkinlik adı zorunludur.';
        }
        if ($target === '') {
            $errors[] = 'Tarih seçilmelidir.';
        }

        $targetValue = null;
        if ($target !== '') {
            $timestamp = strtotime($target);
            if ($timestamp === false) {
                $errors[] = 'Geçersiz tarih formatı.';
            } else {
                $targetValue = date('Y-m-d H:i:s', $timestamp);
            }
        }

        if (!$errors) {
            $stmt = $pdo->prepare('INSERT INTO countdowns (title, target_at, icon, highlight_color, position) VALUES (:title, :target_at, :icon, :color, :position)');
            $stmt->execute([
                'title' => $title,
                'target_at' => $targetValue,
                'icon' => $icon ?: null,
                'color' => $color ?: '#FFD400',
                'position' => 1 + (int) $pdo->query('SELECT COALESCE(MAX(position), 0) FROM countdowns')->fetchColumn(),
            ]);
            $_SESSION['flash'] = 'Geri sayım eklendi.';
            header('Location: /admin/countdowns.php');
            exit;
        }
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM countdowns WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $_SESSION['flash'] = 'Geri sayım silindi.';
            header('Location: /admin/countdowns.php');
            exit;
        }
    }
}

$countdowns = $pdo->query('SELECT id, title, target_at, icon, highlight_color FROM countdowns ORDER BY target_at ASC')->fetchAll();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Geri Sayımlar | Signage</title>
    <link rel="stylesheet" href="/assets/styles.css">
</head>
<body>
<div class="admin-layout">
    <?php include __DIR__ . '/partials/sidebar.php'; ?>
    <main class="content">
        <header style="margin-bottom: 2rem;">
            <h1 style="color: var(--color-primary);">Geri Sayımlar</h1>
            <p>Önemli etkinlikler için geri sayımları yönet.</p>
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
            <h2>Yeni Geri Sayım</h2>
            <form method="post" class="form-grid">
                <input type="hidden" name="action" value="create">
                <div style="grid-column: 1 / -1;">
                    <label for="title">Etkinlik Adı</label>
                    <input type="text" id="title" name="title" required>
                </div>
                <div>
                    <label for="target_at">Hedef Tarih</label>
                    <input type="datetime-local" id="target_at" name="target_at" required>
                </div>
                <div>
                    <label for="icon">İkon (emoji)</label>
                    <input type="text" id="icon" name="icon" maxlength="4">
                </div>
                <div>
                    <label for="color">Vurgu Rengi</label>
                    <input type="color" id="color" name="color" value="#FFD400">
                </div>
                <div style="grid-column: 1 / -1;">
                    <button type="submit" class="button button-primary">Kaydet</button>
                </div>
            </form>
        </section>

        <section class="card">
            <h2>Etkinlik Listesi</h2>
            <?php if (!$countdowns): ?>
                <p>Henüz etkinlik eklenmedi.</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Etkinlik</th>
                            <th>Tarih</th>
                            <th>İkon</th>
                            <th>Renk</th>
                            <th>İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($countdowns as $event): ?>
                        <tr>
                            <td><?php echo (int) $event['id']; ?></td>
                            <td><?php echo htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($event['target_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($event['icon'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><span style="display:inline-block;width:18px;height:18px;border-radius:50%;background: <?php echo htmlspecialchars($event['highlight_color'], ENT_QUOTES, 'UTF-8'); ?>;"></span></td>
                            <td>
                                <form method="post" onsubmit="return confirm('Bu geri sayım silinsin mi?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int) $event['id']; ?>">
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
