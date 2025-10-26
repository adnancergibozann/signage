<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signage.php';

require_login();

$activePage = 'schedule';
$user = current_user();
$errors = [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pdo = get_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_period') {
        $number = (int) ($_POST['period_number'] ?? 0);
        $label = trim($_POST['label'] ?? '');
        $start = trim($_POST['start_time'] ?? '');
        $end = trim($_POST['end_time'] ?? '');
        $periodType = $_POST['period_type'] ?? 'lesson';

        if ($number <= 0) {
            $errors[] = 'Periyot numarası giriniz.';
        }
        if ($label === '') {
            $errors[] = 'Periyot etiketi gereklidir.';
        }
        if ($start === '' || $end === '') {
            $errors[] = 'Başlangıç ve bitiş saatleri zorunludur.';
        }
        if (!in_array($periodType, ['lesson', 'break'], true)) {
            $errors[] = 'Periyot türü ders veya teneffüs olmalıdır.';
        }

        if (!$errors) {
            $stmt = $pdo->prepare('INSERT INTO schedule_periods (period_number, label, start_time, end_time, period_type) VALUES (:number, :label, :start, :end, :type)
                ON DUPLICATE KEY UPDATE label = VALUES(label), start_time = VALUES(start_time), end_time = VALUES(end_time), period_type = VALUES(period_type)');
            $stmt->execute([
                'number' => $number,
                'label' => $label,
                'start' => $start,
                'end' => $end,
                'type' => $periodType,
            ]);
            $_SESSION['flash'] = 'Periyot kaydedildi.';
            header('Location: ' . route_url('admin/schedule.php'));
            exit;
        }
    }

    if ($action === 'delete_period') {
        $number = (int) ($_POST['period_number'] ?? 0);
        if ($number > 0) {
            $stmt = $pdo->prepare('DELETE FROM schedule_periods WHERE period_number = :number');
            $stmt->execute(['number' => $number]);
            $_SESSION['flash'] = 'Periyot silindi.';
            header('Location: ' . route_url('admin/schedule.php'));
            exit;
        }
    }

    if ($action === 'create_class') {
        $name = trim($_POST['class_name'] ?? '');
        $order = (int) ($_POST['display_order'] ?? 1);
        if ($name === '') {
            $errors[] = 'Sınıf adı zorunludur.';
        }
        if (!$errors) {
            $stmt = $pdo->prepare('INSERT INTO classrooms (name, display_order) VALUES (:name, :order)');
            try {
                $stmt->execute(['name' => $name, 'order' => $order]);
                $_SESSION['flash'] = 'Sınıf eklendi.';
                header('Location: ' . route_url('admin/schedule.php'));
                exit;
            } catch (\PDOException $e) {
                $errors[] = 'Bu sınıf zaten mevcut.';
            }
        }
    }

    if ($action === 'delete_class') {
        $id = (int) ($_POST['classroom_id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM classrooms WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $_SESSION['flash'] = 'Sınıf silindi.';
            header('Location: ' . route_url('admin/schedule.php'));
            exit;
        }
    }

    if ($action === 'save_entry') {
        $classroom = (int) ($_POST['classroom_id'] ?? 0);
        $weekday = (int) ($_POST['weekday'] ?? 0);
        $periodNumber = (int) ($_POST['period_number'] ?? 0);
        $subject = trim($_POST['subject'] ?? '');
        $teacher = trim($_POST['teacher'] ?? '');

        if ($classroom <= 0 || $weekday < 1 || $weekday > 7 || $periodNumber <= 0) {
            $errors[] = 'Sınıf, gün ve periyot seçilmelidir.';
        }
        if ($subject === '') {
            $errors[] = 'Ders adı giriniz.';
        }

        if (!$errors) {
            $typeStmt = $pdo->prepare('SELECT period_type FROM schedule_periods WHERE period_number = :number LIMIT 1');
            $typeStmt->execute(['number' => $periodNumber]);
            $periodRow = $typeStmt->fetch();
            if (!$periodRow) {
                $errors[] = 'Seçilen periyot bulunamadı.';
            } elseif ($periodRow['period_type'] !== 'lesson') {
                $errors[] = 'Teneffüs periyotlarına ders atanamaz.';
            }
        }

        if (!$errors) {
            $stmt = $pdo->prepare('INSERT INTO class_schedule_entries (classroom_id, weekday, period_number, subject, teacher)
                VALUES (:classroom, :weekday, :period, :subject, :teacher)
                ON DUPLICATE KEY UPDATE subject = VALUES(subject), teacher = VALUES(teacher)');
            $stmt->execute([
                'classroom' => $classroom,
                'weekday' => $weekday,
                'period' => $periodNumber,
                'subject' => $subject,
                'teacher' => $teacher ?: null,
            ]);
            $_SESSION['flash'] = 'Ders kaydedildi.';
            header('Location: ' . route_url('admin/schedule.php'));
            exit;
        }
    }

    if ($action === 'delete_entry') {
        $entryId = (int) ($_POST['entry_id'] ?? 0);
        if ($entryId > 0) {
            $stmt = $pdo->prepare('DELETE FROM class_schedule_entries WHERE id = :id');
            $stmt->execute(['id' => $entryId]);
            $_SESSION['flash'] = 'Ders silindi.';
            header('Location: ' . route_url('admin/schedule.php'));
            exit;
        }
    }
}

$periods = fetch_schedule_periods();
$classrooms = $pdo->query('SELECT id, name, display_order FROM classrooms ORDER BY display_order ASC, name ASC')->fetchAll();
$schedule = fetch_weekly_schedule();
$weekdays = [
    1 => 'Pazartesi',
    2 => 'Salı',
    3 => 'Çarşamba',
    4 => 'Perşembe',
    5 => 'Cuma',
    6 => 'Cumartesi',
    7 => 'Pazar',
];
$weekdayShort = [
    1 => 'Pzt',
    2 => 'Sal',
    3 => 'Çar',
    4 => 'Per',
    5 => 'Cum',
    6 => 'Cmt',
    7 => 'Paz',
];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ders Programı | Signage</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/styles.css'), ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body>
<div class="admin-layout">
    <?php include __DIR__ . '/partials/sidebar.php'; ?>
    <main class="content">
        <header style="margin-bottom: 2rem;">
            <h1 style="color: var(--color-primary);">Ders Programı</h1>
            <p>Periyotları, sınıfları ve dersleri yapılandır.</p>
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
            <h2>Periyot Ayarları</h2>
            <form method="post" class="form-grid" style="align-items:end;">
                <input type="hidden" name="action" value="save_period">
                <div>
                    <label for="period_number">Periyot No</label>
                    <input type="number" id="period_number" name="period_number" min="1" required>
                </div>
                <div>
                    <label for="label">Etiket</label>
                    <input type="text" id="label" name="label" required>
                </div>
                <div>
                    <label for="start_time">Başlangıç</label>
                    <input type="time" id="start_time" name="start_time" required>
                </div>
                <div>
                    <label for="end_time">Bitiş</label>
                    <input type="time" id="end_time" name="end_time" required>
                </div>
                <div>
                    <label for="period_type">Tür</label>
                    <select id="period_type" name="period_type">
                        <option value="lesson">Ders</option>
                        <option value="break">Teneffüs</option>
                    </select>
                </div>
                <div style="grid-column: 1 / -1;">
                    <button type="submit" class="button button-primary">Kaydet</button>
                </div>
            </form>
            <?php if ($periods): ?>
                <table class="table" style="margin-top:1rem;">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Etiket</th>
                            <th>Saat</th>
                            <th>Tür</th>
                            <th>Sil</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($periods as $period): ?>
                        <tr>
                            <td><?php echo (int) $period['period']; ?></td>
                            <td><?php echo htmlspecialchars($period['label'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars(substr($period['start_time'], 0, 5) . ' - ' . substr($period['end_time'], 0, 5), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo $period['type'] === 'break' ? 'Teneffüs' : 'Ders'; ?></td>
                            <td>
                                <form method="post" onsubmit="return confirm('Periyot silinecek. Devam?');">
                                    <input type="hidden" name="action" value="delete_period">
                                    <input type="hidden" name="period_number" value="<?php echo (int) $period['period']; ?>">
                                    <button type="submit" class="button button-secondary">Sil</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <section class="card" style="margin-bottom: 2rem;">
            <h2>Sınıf Yönetimi</h2>
            <form method="post" class="form-grid" style="align-items:end;">
                <input type="hidden" name="action" value="create_class">
                <div>
                    <label for="class_name">Sınıf Adı</label>
                    <input type="text" id="class_name" name="class_name" required>
                </div>
                <div>
                    <label for="display_order">Sıra</label>
                    <input type="number" id="display_order" name="display_order" min="1" value="1">
                </div>
                <div style="grid-column: 1 / -1;">
                    <button type="submit" class="button button-primary">Sınıf Ekle</button>
                </div>
            </form>
            <?php if ($classrooms): ?>
                <table class="table" style="margin-top:1rem;">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Sınıf</th>
                            <th>Sıra</th>
                            <th>Sil</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($classrooms as $classroom): ?>
                        <tr>
                            <td><?php echo (int) $classroom['id']; ?></td>
                            <td><?php echo htmlspecialchars($classroom['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo (int) $classroom['display_order']; ?></td>
                            <td>
                                <form method="post" onsubmit="return confirm('Sınıf silinsin mi?');">
                                    <input type="hidden" name="action" value="delete_class">
                                    <input type="hidden" name="classroom_id" value="<?php echo (int) $classroom['id']; ?>">
                                    <button type="submit" class="button button-secondary">Sil</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <section class="card" style="margin-bottom: 2rem;">
            <h2>Ders Ekle</h2>
            <form method="post" class="form-grid">
                <input type="hidden" name="action" value="save_entry">
                <div>
                    <label for="classroom_id">Sınıf</label>
                    <select name="classroom_id" id="classroom_id" required>
                        <option value="">Seçiniz</option>
                        <?php foreach ($classrooms as $classroom): ?>
                            <option value="<?php echo (int) $classroom['id']; ?>"><?php echo htmlspecialchars($classroom['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="weekday">Gün</label>
                    <select name="weekday" id="weekday" required>
                        <option value="">Seçiniz</option>
                        <?php foreach ($weekdays as $key => $label): ?>
                            <option value="<?php echo (int) $key; ?>"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="entry_period">Periyot</label>
                    <select name="period_number" id="entry_period" required>
                        <option value="">Seçiniz</option>
                        <?php foreach ($periods as $period): ?>
                            <?php if ($period['type'] !== 'lesson') { continue; } ?>
                            <option value="<?php echo (int) $period['period']; ?>"><?php echo htmlspecialchars($period['label'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="subject">Ders</label>
                    <input type="text" id="subject" name="subject" required>
                </div>
                <div>
                    <label for="teacher">Öğretmen</label>
                    <input type="text" id="teacher" name="teacher">
                </div>
                <div style="grid-column: 1 / -1;">
                    <button type="submit" class="button button-primary">Kaydet</button>
                </div>
            </form>
        </section>

        <section class="card">
            <h2>Program Önizleme</h2>
            <?php if (!$schedule): ?>
                <p>Ders programı oluşturulmadı.</p>
            <?php else: ?>
                <?php foreach ($schedule as $slide): ?>
                    <div style="margin-bottom: 2rem;">
                        <h3><?php echo htmlspecialchars($slide['classroom'], ENT_QUOTES, 'UTF-8'); ?></h3>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Periyot</th>
                                    <?php foreach ($weekdays as $dayKey => $label): ?>
                                        <th><?php echo htmlspecialchars($weekdayShort[$dayKey] ?? mb_substr($label, 0, 3), ENT_QUOTES, 'UTF-8'); ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($periods as $period): ?>
                                <tr class="<?php echo $period['type'] === 'break' ? 'row-break' : ''; ?>">
                                    <td><?php echo htmlspecialchars($period['label'], ENT_QUOTES, 'UTF-8'); ?><br><small><?php echo htmlspecialchars(substr($period['start_time'], 0, 5) . ' - ' . substr($period['end_time'], 0, 5), ENT_QUOTES, 'UTF-8'); ?></small></td>
                                    <?php for ($day = 1; $day <= 7; $day++): ?>
                                        <?php $entry = $slide['entries'][$day][$period['period']] ?? null; ?>
                                        <td class="<?php echo $period['type'] === 'break' ? 'row-break' : ''; ?>">
                                            <?php if ($period['type'] === 'break'): ?>
                                                <span style="color:rgba(255,255,255,0.7); font-weight:600;">Teneffüs</span>
                                            <?php elseif ($entry): ?>
                                                <strong><?php echo htmlspecialchars($entry['subject'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                                <?php if (!empty($entry['teacher'])): ?>
                                                    <div style="font-size:0.85rem;color:rgba(255,255,255,0.7);">
                                                        <?php echo htmlspecialchars($entry['teacher'], ENT_QUOTES, 'UTF-8'); ?>
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="color:rgba(255,255,255,0.4);">—</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endfor; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </main>
</div>
</body>
</html>
