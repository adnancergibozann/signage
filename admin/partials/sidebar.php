<?php
/** @var array|null $user */
/** @var string $activePage */

$navItems = [
    'prayer' => ['label' => 'Namaz Ayarları', 'href' => '/admin/dashboard.php'],
    'audio' => ['label' => 'Ses Dosyaları', 'href' => '/admin/dashboard.php#audio-profiles'],
];
?>
<aside class="sidebar">
    <div>
        <h2>Ezan Saati Paneli</h2>
        <?php if (!empty($user['username'])): ?>
            <p>Hoş geldin, <?php echo htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8'); ?> 👋</p>
        <?php endif; ?>
    </div>
    <nav>
        <ul>
            <?php foreach ($navItems as $key => $item): ?>
                <li>
                    <a href="<?php echo htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8'); ?>" class="<?php echo $activePage === $key ? 'active' : ''; ?>">
                        <?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                </li>
            <?php endforeach; ?>
            <li><a href="/index.php" target="_blank">Kiosk Önizleme</a></li>
        </ul>
    </nav>
    <form action="/admin/logout.php" method="post">
        <button type="submit" class="button button-secondary">Çıkış Yap</button>
    </form>
</aside>
