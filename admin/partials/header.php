<?php
/** @var string $pageTitle */
/** @var string $activePage */
$user = current_user();
$role = $user['role'] ?? null;
$navItems = [
    ['key' => 'dashboard', 'label' => 'Gösterge Paneli', 'href' => '/admin/dashboard.php', 'roles' => ['super_admin', 'boss', 'manager']],
    ['key' => 'users', 'label' => 'Kullanıcılar', 'href' => '/admin/users.php', 'roles' => ['super_admin']],
    ['key' => 'statuses', 'label' => 'Durum Yönetimi', 'href' => '/admin/statuses.php', 'roles' => ['super_admin']],
    ['key' => 'announcements', 'label' => 'Duyurular', 'href' => '/admin/announcements.php', 'roles' => ['super_admin']],
    ['key' => 'ticker', 'label' => 'Kayan Yazı', 'href' => '/admin/ticker.php', 'roles' => ['super_admin']],
    ['key' => 'media', 'label' => 'Medya Yayınları', 'href' => '/admin/media.php', 'roles' => ['super_admin']],
    ['key' => 'settings', 'label' => 'Ayarlar', 'href' => '/admin/settings.php', 'roles' => ['super_admin']],
    ['key' => 'manager', 'label' => 'Durumum', 'href' => '/admin/manager.php', 'roles' => ['manager']],
    ['key' => 'reports', 'label' => 'Toplantı Raporu', 'href' => '/admin/reports.php', 'roles' => ['boss', 'super_admin']],
];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($pageTitle ?? 'Yönetim Paneli') ?> · Gapgross</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/assets/css/admin.css?v=1">
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <h1>Gapgross</h1>
        <nav class="nav-links">
            <?php foreach ($navItems as $item): ?>
                <?php if (in_array($role, $item['roles'], true)): ?>
                    <a href="<?= $item['href'] ?>" class="<?= ($activePage ?? '') === $item['key'] ? 'active' : '' ?>">
                        <?= htmlspecialchars($item['label']) ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <div class="nav-footer">
            <div><?= htmlspecialchars($user['full_name'] ?? '') ?></div>
            <small><?= htmlspecialchars($user['role'] ?? '') ?></small>
            <a class="button secondary" href="/admin/logout.php">Çıkış Yap</a>
        </div>
    </aside>
    <main class="content">
        <div class="page-header">
            <h2><?= htmlspecialchars($pageTitle ?? 'Panel') ?></h2>
        </div>
