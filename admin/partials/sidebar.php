<?php
/** @var array|null $user */
/** @var string $activePage */

$navItems = [
    'ticker' => ['label' => 'Kayan Yazılar', 'href' => '/admin/dashboard.php'],
    'settings' => ['label' => 'Tema & Logo', 'href' => '/admin/settings.php'],
    'media' => ['label' => 'Medya Yönetimi', 'href' => '/admin/media.php'],
    'products' => ['label' => 'Ürün İndirimleri', 'href' => '/admin/products.php'],
    'teachers' => ['label' => 'Öğretmenler', 'href' => '/admin/teachers.php'],
    'schedule' => ['label' => 'Ders Programı', 'href' => '/admin/schedule.php'],
    'weather' => ['label' => 'Hava Durumu', 'href' => '/admin/weather.php'],
    'news' => ['label' => 'Haberler', 'href' => '/admin/news.php'],
    'countdowns' => ['label' => 'Geri Sayımlar', 'href' => '/admin/countdowns.php'],
];
?>
<aside class="sidebar">
    <div>
        <h2>Signage Panel</h2>
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
            <li><a href="/index.php" target="_blank">Önizleme</a></li>
            <li><a href="/public/widget.php" target="_blank">Widget</a></li>
        </ul>
    </nav>
    <form action="/admin/logout.php" method="post">
        <button type="submit" class="button button-secondary">Çıkış Yap</button>
    </form>
</aside>
