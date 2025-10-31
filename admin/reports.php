<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

require_login();
require_role(['boss', 'super_admin']);

$pageTitle = 'Toplantı Raporu';
$activePage = 'reports';
$pdo = get_pdo();

$selectedDate = $_GET['date'] ?? (new DateTime())->format('Y-m-d');
try {
    $dateObj = new DateTimeImmutable($selectedDate);
} catch (Exception) {
    $dateObj = new DateTimeImmutable();
    $selectedDate = $dateObj->format('Y-m-d');
}

$stmt = $pdo->prepare('SELECT ml.*, u.full_name FROM meeting_logs ml INNER JOIN users u ON u.id = ml.manager_id WHERE DATE(ml.started_at) = :date ORDER BY ml.started_at ASC');
$stmt->execute(['date' => $selectedDate]);
$meetings = $stmt->fetchAll();

$totalMeetings = count($meetings);
$totalMinutes = 0;
foreach ($meetings as $meeting) {
    if ($meeting['started_at'] && $meeting['ended_at']) {
        $start = new DateTimeImmutable($meeting['started_at']);
        $end = new DateTimeImmutable($meeting['ended_at']);
        $totalMinutes += max(0, (int) (($end->getTimestamp() - $start->getTimestamp()) / 60));
    }
}

include __DIR__ . '/partials/header.php';
?>
<section class="card">
    <h3>Rapor Tarihi</h3>
    <form method="get">
        <input type="date" name="date" value="<?= htmlspecialchars($selectedDate) ?>">
        <button class="button" type="submit">Görüntüle</button>
    </form>
</section>

<section class="card">
    <h3>Özet</h3>
    <p><strong>Toplam Toplantı:</strong> <?= $totalMeetings ?></p>
    <p><strong>Toplam Süre:</strong> <?= $totalMinutes ?> dakika</p>
</section>

<section class="card">
    <h3>Toplantı Detayları</h3>
    <?php if (empty($meetings)): ?>
        <p>Seçilen tarihte toplantı bulunamadı.</p>
    <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Müdür</th>
                    <th>Başlangıç</th>
                    <th>Bitiş</th>
                    <th>Planlanan Bitiş</th>
                    <th>Not</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($meetings as $meeting): ?>
                    <tr>
                        <td><?= htmlspecialchars($meeting['full_name']) ?></td>
                        <td><?= format_datetime($meeting['started_at'], 'H:i') ?></td>
                        <td><?= $meeting['ended_at'] ? format_datetime($meeting['ended_at'], 'H:i') : '-' ?></td>
                        <td><?= $meeting['expected_end_at'] ? format_datetime($meeting['expected_end_at'], 'H:i') : '-' ?></td>
                        <td><?= htmlspecialchars($meeting['note'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
<?php
include __DIR__ . '/partials/footer.php';
