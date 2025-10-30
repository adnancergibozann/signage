<?php
/** @var array|null $user */
/** @var string $activePage */

require_once __DIR__ . '/../../includes/license.php';

$isSuperAdmin = is_super_admin($user ?? null);
$licenseStatus = get_license_status();

$navItems = [
    'ticker' => ['label' => 'Kayan Yazılar', 'href' => route_url('admin/dashboard.php')],
    'design' => ['label' => 'Dizayn Modülü', 'href' => route_url('admin/design.php')],
    'settings' => ['label' => 'Tema & Logo', 'href' => route_url('admin/settings.php')],
    'media' => ['label' => 'Medya Yönetimi', 'href' => route_url('admin/media.php')],
    'teachers' => ['label' => 'Öğretmenler', 'href' => route_url('admin/teachers.php')],
    'schedule' => ['label' => 'Ders Programı', 'href' => route_url('admin/schedule.php')],
    'weather' => ['label' => 'Hava Durumu', 'href' => route_url('admin/weather.php')],
    'news' => ['label' => 'Haberler', 'href' => route_url('admin/news.php')],
    'countdowns' => ['label' => 'Geri Sayımlar', 'href' => route_url('admin/countdowns.php')],
    'membership' => ['label' => 'Üyelik Bilgileri', 'href' => route_url('admin/membership.php')],
];

if ($isSuperAdmin) {
    $navItems['license'] = ['label' => 'Lisans Yönetimi', 'href' => route_url('admin/license.php')];
}

$navItems['users'] = ['label' => 'Kullanıcılar', 'href' => route_url('admin/users.php')];

$licenseStatusClass = 'status-warning';
if (($licenseStatus['status_code'] ?? '') === 'active') {
    $licenseStatusClass = 'status-success';
} elseif (($licenseStatus['status_code'] ?? '') === 'expired') {
    $licenseStatusClass = 'status-danger';
}

$licenseRemaining = $licenseStatus['remaining_text'] ?? 'Süre doldu';
?>
<aside class="sidebar">
    <div>
        <h2>Signage Panel</h2>
        <?php if (!empty($user['username'])): ?>
            <p>Hoş geldin, <?php echo htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8'); ?> 👋</p>
        <?php endif; ?>
        <div class="sidebar-license">
            <strong>Üyelik Durumu</strong>
            <span class="license-status <?php echo $licenseStatusClass; ?>"><?php echo htmlspecialchars($licenseStatus['status_label'] ?? 'Bilinmiyor', ENT_QUOTES, 'UTF-8'); ?></span>
            <p class="license-remaining">Kalan süre: <?php echo htmlspecialchars($licenseRemaining, ENT_QUOTES, 'UTF-8'); ?></p>
            <a href="<?php echo htmlspecialchars(route_url('admin/membership.php'), ENT_QUOTES, 'UTF-8'); ?>">Detayları Gör</a>
        </div>
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
            <li><a href="<?php echo htmlspecialchars(route_url('index.php'), ENT_QUOTES, 'UTF-8'); ?>" target="_blank">Önizleme</a></li>
            <li><a href="<?php echo htmlspecialchars(route_url('public/widget.php'), ENT_QUOTES, 'UTF-8'); ?>" target="_blank">Widget</a></li>
        </ul>
    </nav>
    <form action="<?php echo htmlspecialchars(route_url('admin/logout.php'), ENT_QUOTES, 'UTF-8'); ?>" method="post">
        <button type="submit" class="button button-secondary">Çıkış Yap</button>
    </form>
</aside>
