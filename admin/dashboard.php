<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/signage.php';

require_login();
$user = current_user();
if (is_manager_role($user['role'])) {
    header('Location: ' . url_for('admin/manager.php'));
    exit;
}

$pageTitle = 'Gösterge Paneli';
$activePage = 'dashboard';
$pdo = get_pdo();
$now = new DateTimeImmutable();
$settings = load_all_settings();
$managers = fetch_managers_with_status($pdo, $now, $settings);

$meetingStmt = $pdo->prepare('SELECT ml.id, u.full_name, ml.started_at, ml.expected_end_at, ml.ended_at, ml.note
    FROM meeting_logs ml
    INNER JOIN users u ON u.id = ml.manager_id
    WHERE DATE(ml.started_at) = :today
    ORDER BY ml.started_at DESC');
$meetingStmt->execute(['today' => $now->format('Y-m-d')]);
$meetingsToday = $meetingStmt->fetchAll();

include __DIR__ . '/partials/header.php';
?>
<section class="card">
    <h3>Satınalma Müdür Durumları</h3>
    <table class="table">
        <thead>
            <tr>
                <th>Ad</th>
                <th>Departman</th>
                <th>Durum</th>
                <th>Not</th>
                <th>Geri Sayım</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($managers as $manager): ?>
            <tr>
                <td><?= htmlspecialchars($manager['name']) ?></td>
                <td><?= htmlspecialchars($manager['department'] ?? '') ?></td>
                <td><span class="badge"><?= htmlspecialchars($manager['statusLabel']) ?></span></td>
                <td><?= htmlspecialchars($manager['note'] ?? '') ?></td>
                <td>
                    <?php if ($manager['remainingSeconds'] !== null): ?>
                        <?= format_duration((int) $manager['remainingSeconds']) ?>
                    <?php elseif ($manager['endsAt']): ?>
                        <?= format_datetime($manager['endsAt'], 'd.m H:i') ?>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<section class="card">
    <h3>Bugünkü Toplantılar</h3>
    <?php if (empty($meetingsToday)): ?>
        <p>Bugün kayıtlı toplantı bulunmuyor.</p>
    <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Satınalma Müdürü</th>
                    <th>Başlangıç</th>
                    <th>Planlanan Bitiş</th>
                    <th>Gerçekleşen Bitiş</th>
                    <th>Not</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($meetingsToday as $meeting): ?>
                    <tr>
                        <td><?= htmlspecialchars($meeting['full_name']) ?></td>
                        <td><?= format_datetime($meeting['started_at'], 'H:i') ?></td>
                        <td><?= $meeting['expected_end_at'] ? format_datetime($meeting['expected_end_at'], 'H:i') : '-' ?></td>
                        <td><?= $meeting['ended_at'] ? format_datetime($meeting['ended_at'], 'H:i') : '-' ?></td>
                        <td><?= htmlspecialchars($meeting['note'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
<?php
include __DIR__ . '/partials/footer.php';
