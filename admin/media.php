<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signage.php';

require_login();

if (!function_exists('ensure_media_upload_dir')) {
    function ensure_media_upload_dir(): ?string
    {
        $dir = __DIR__ . '/../public/uploads/media';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }

        return $dir;
    }
}

$activePage = 'media';
$user = current_user();
$errors = [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pdo = get_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $title = trim($_POST['title'] ?? '');
        $type = $_POST['type'] ?? 'image';
        $duration = max(3, min(60, (int) ($_POST['duration'] ?? 5)));
        $expiresInput = trim($_POST['expires_at'] ?? '');
        $expiresAt = null;

        $allowedTypes = ['image', 'video', 'pdf'];
        if (!in_array($type, $allowedTypes, true)) {
            $errors[] = 'Geçersiz medya tipi.';
        }

        if ($title === '') {
            $errors[] = 'Başlık zorunludur.';
        }

        $source = null;
        $storagePath = null;

        if ($expiresInput !== '') {
            $timestamp = strtotime($expiresInput);
            if ($timestamp === false) {
                $errors[] = 'Geçersiz yayın bitiş tarihi.';
            } else {
                $expiresAt = date('Y-m-d H:i:s', $timestamp);
            }
        }

        if ($type === 'video') {
            $source = trim($_POST['video_url'] ?? '');
            if ($source === '') {
                if (!empty($_FILES['video_file']['tmp_name']) && is_uploaded_file($_FILES['video_file']['tmp_name'])) {
                    $videoMime = mime_content_type($_FILES['video_file']['tmp_name']);
                    $videoMap = [
                        'video/mp4' => 'mp4',
                        'video/webm' => 'webm',
                        'video/ogg' => 'ogv',
                        'video/quicktime' => 'mov',
                    ];

                    if (!array_key_exists($videoMime, $videoMap)) {
                        $errors[] = 'Desteklenmeyen video formatı. (mp4, webm, ogg, mov)';
                    } elseif ($_FILES['video_file']['size'] > 150 * 1024 * 1024) {
                        $errors[] = 'Video dosyası 150MB sınırını aşmamalıdır.';
                    } else {
                        $uploadDir = ensure_media_upload_dir();
                        if ($uploadDir === null) {
                            $errors[] = 'Video yükleme klasörü oluşturulamadı.';
                        } else {
                            $fileName = 'video_' . bin2hex(random_bytes(6)) . '.' . $videoMap[$videoMime];
                            $targetPath = $uploadDir . '/' . $fileName;
                            if (!move_uploaded_file($_FILES['video_file']['tmp_name'], $targetPath)) {
                                $errors[] = 'Video yüklenirken hata oluştu.';
                            } else {
                                $storagePath = 'public/uploads/media/' . $fileName;
                            }
                        }
                    }
                } else {
                    $errors[] = 'Video için URL girin veya dosya yükleyin.';
                }
            }
        } else {
            if (!empty($_FILES['file_source']['tmp_name']) && is_uploaded_file($_FILES['file_source']['tmp_name'])) {
                $fileMime = mime_content_type($_FILES['file_source']['tmp_name']);
                $imageMap = [
                    'image/png' => 'png',
                    'image/jpeg' => 'jpg',
                    'image/gif' => 'gif',
                    'image/webp' => 'webp',
                    'image/svg+xml' => 'svg',
                ];
                $documentMap = [
                    'application/pdf' => 'pdf',
                ];
                $allowedMap = $type === 'image' ? $imageMap : $documentMap;

                if (!array_key_exists($fileMime, $allowedMap)) {
                    $errors[] = 'Dosya formatı desteklenmiyor.';
                } elseif ($_FILES['file_source']['size'] > 5 * 1024 * 1024) {
                    $errors[] = 'Dosya boyutu 5MB sınırını aşmamalıdır.';
                } else {
                    $uploadDir = ensure_media_upload_dir();
                    if ($uploadDir === null) {
                        $errors[] = 'Dosya yükleme klasörü oluşturulamadı.';
                    } else {
                        $prefix = $type === 'image' ? 'image_' : 'document_';
                        $fileName = $prefix . bin2hex(random_bytes(6)) . '.' . $allowedMap[$fileMime];
                        $targetPath = $uploadDir . '/' . $fileName;
                        if (!move_uploaded_file($_FILES['file_source']['tmp_name'], $targetPath)) {
                            $errors[] = 'Dosya yüklenirken hata oluştu.';
                        } else {
                            $storagePath = 'public/uploads/media/' . $fileName;
                        }
                    }
                }
            } else {
                $errors[] = 'Dosya yüklenmedi.';
            }
        }

        if (!$errors && ($source || $storagePath)) {
            $stmt = $pdo->prepare('INSERT INTO media_items (title, type, source, storage_path, duration_seconds, position, expires_at) VALUES (:title, :type, :source, :storage, :duration, :position, :expires_at)');
            $stmt->bindValue(':title', $title);
            $stmt->bindValue(':type', $type);
            if ($source !== null && $source !== '') {
                $stmt->bindValue(':source', $source);
            } else {
                $stmt->bindValue(':source', null, PDO::PARAM_NULL);
            }
            if ($storagePath !== null) {
                $stmt->bindValue(':storage', $storagePath);
            } else {
                $stmt->bindValue(':storage', null, PDO::PARAM_NULL);
            }
            $stmt->bindValue(':duration', $duration, PDO::PARAM_INT);
            $stmt->bindValue(':position', 1 + (int) $pdo->query('SELECT COALESCE(MAX(position), 0) FROM media_items')->fetchColumn(), PDO::PARAM_INT);
            if ($expiresAt !== null) {
                $stmt->bindValue(':expires_at', $expiresAt);
            } else {
                $stmt->bindValue(':expires_at', null, PDO::PARAM_NULL);
            }
            $stmt->execute();

            $_SESSION['flash'] = 'Medya öğesi eklendi.';
            header('Location: ' . route_url('admin/media.php'));
            exit;
        }
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $fileStmt = $pdo->prepare('SELECT storage_path FROM media_items WHERE id = :id');
            $fileStmt->execute(['id' => $id]);
            $mediaRow = $fileStmt->fetch();
            if ($mediaRow && !empty($mediaRow['storage_path'])) {
                $path = __DIR__ . '/../' . ltrim($mediaRow['storage_path'], '/');
                if (is_file($path)) {
                    @unlink($path);
                }
            }

            $stmt = $pdo->prepare('DELETE FROM media_items WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $_SESSION['flash'] = 'Medya silindi.';
            header('Location: ' . route_url('admin/media.php'));
            exit;
        }
    }
}

$mediaItems = $pdo->query('SELECT id, title, type, source, storage_path, duration_seconds, expires_at FROM media_items ORDER BY position ASC, id ASC')->fetchAll();
$mediaMimeMap = [
    'mp4' => 'video/mp4',
    'webm' => 'video/webm',
    'ogv' => 'video/ogg',
    'ogg' => 'video/ogg',
    'mov' => 'video/quicktime',
    'pdf' => 'application/pdf',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'svg' => 'image/svg+xml',
];
$mediaPreview = array_map(static function (array $row) use ($mediaMimeMap): array {
    $previewSource = null;
    $mime = null;
    $storagePath = $row['storage_path'] ?? null;
    $isLocal = !empty($storagePath);

    if ($isLocal) {
        $relative = ltrim($storagePath, '/');
        $previewSource = asset_url($relative);
        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        if (isset($mediaMimeMap[$extension])) {
            $mime = $mediaMimeMap[$extension];
        }
    } elseif (!empty($row['source'])) {
        $previewSource = $row['source'];
        if (preg_match('/^data:([^;]+);/i', $row['source'], $matches)) {
            $mime = $matches[1];
        }
    }

    return [
        'id' => (int) $row['id'],
        'title' => $row['title'],
        'type' => $row['type'],
        'duration' => (int) $row['duration_seconds'],
        'expires_at' => $row['expires_at'],
        'preview_source' => $previewSource,
        'mime' => $mime,
        'is_local' => $isLocal,
        'storage_path' => $storagePath,
        'raw_source' => $row['source'],
    ];
}, $mediaItems);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medya Yönetimi | Signage</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/styles.css'), ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body>
<div class="admin-layout">
    <?php include __DIR__ . '/partials/sidebar.php'; ?>
    <main class="content">
        <header style="margin-bottom: 2rem;">
            <h1 style="color: var(--color-primary);">Medya Yönetimi</h1>
            <p>Slider'da görünecek içerikleri düzenle.</p>
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
            <h2>Yeni Medya</h2>
            <form method="post" enctype="multipart/form-data" class="form-grid">
                <input type="hidden" name="action" value="create">
                <div>
                    <label for="title">Başlık</label>
                    <input type="text" id="title" name="title" required>
                </div>
                <div>
                    <label for="type">Medya Tipi</label>
                    <select id="type" name="type">
                        <option value="image">Görsel</option>
                        <option value="video">Video (YouTube)</option>
                        <option value="pdf">PDF</option>
                    </select>
                </div>
                <div>
                    <label for="duration">Gösterim Süresi (sn)</label>
                    <input type="number" id="duration" name="duration" min="3" max="60" value="8">
                </div>
                <div>
                    <label for="file_source">Dosya (Görsel/PDF)</label>
                    <input type="file" id="file_source" name="file_source" accept="image/*,application/pdf">
                </div>
                <div>
                    <label for="video_url">Video URL</label>
                    <input type="url" id="video_url" name="video_url" placeholder="https://www.youtube.com/embed/...">
                    <small>Alternatif olarak yerel video yükleyebilirsin.</small>
                </div>
                <div>
                    <label for="video_file">Video Dosyası</label>
                    <input type="file" id="video_file" name="video_file" accept="video/*">
                    <small>MP4, WEBM, OGG veya MOV (maks. 150MB)</small>
                </div>
                <div>
                    <label for="expires_at">Yayın Bitiş Tarihi</label>
                    <input type="datetime-local" id="expires_at" name="expires_at">
                    <small>Boş bırakılırsa içerik süresiz yayınlanır.</small>
                </div>
                <div style="grid-column: 1 / -1;">
                    <button type="submit" class="button button-primary">Kaydet</button>
                </div>
            </form>
        </section>

        <section class="card" style="margin-bottom: 2rem;">
            <h2>Önizleme</h2>
            <?php if (!$mediaPreview): ?>
                <p>Henüz medya eklenmedi.</p>
            <?php else: ?>
                <div class="media-preview-grid">
                    <?php foreach ($mediaPreview as $item): ?>
                        <article class="media-preview-card">
                            <div class="media-preview-frame">
                                <?php if ($item['type'] === 'image'): ?>
                                    <?php if ($item['preview_source']): ?>
                                        <img src="<?php echo htmlspecialchars($item['preview_source'], ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php else: ?>
                                        <div class="media-preview-placeholder">Önizleme yok</div>
                                    <?php endif; ?>
                                <?php elseif ($item['type'] === 'video'): ?>
                                    <?php if ($item['is_local'] && $item['preview_source']): ?>
                                        <video controls muted playsinline loop preload="metadata">
                                            <source src="<?php echo htmlspecialchars($item['preview_source'], ENT_QUOTES, 'UTF-8'); ?>"<?php echo $item['mime'] ? ' type="' . htmlspecialchars($item['mime'], ENT_QUOTES, 'UTF-8') . '"' : ''; ?>>
                                            Tarayıcınız bu videoyu oynatamıyor.
                                        </video>
                                    <?php elseif ($item['preview_source']): ?>
                                        <iframe src="<?php echo htmlspecialchars($item['preview_source'], ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8'); ?>" allow="autoplay; encrypted-media" allowfullscreen loading="lazy"></iframe>
                                    <?php else: ?>
                                        <div class="media-preview-placeholder">Önizleme yok</div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php if ($item['preview_source']): ?>
                                        <iframe src="<?php echo htmlspecialchars($item['preview_source'], ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8'); ?>" loading="lazy"></iframe>
                                    <?php else: ?>
                                        <div class="media-preview-placeholder">Önizleme yok</div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <div class="media-preview-meta">
                                <h3><?php echo htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
                                <p class="media-preview-details">
                                    <span><?php echo strtoupper($item['type']); ?></span>
                                    <span><?php echo (int) $item['duration']; ?> sn</span>
                                    <span><?php echo $item['expires_at'] ? 'Bitiş: ' . htmlspecialchars($item['expires_at'], ENT_QUOTES, 'UTF-8') : 'Süresiz'; ?></span>
                                </p>
                                <?php if ($item['storage_path']): ?>
                                    <?php $asset = asset_url(ltrim($item['storage_path'], '/')); ?>
                                    <a class="media-preview-path" href="<?php echo htmlspecialchars($asset, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($item['storage_path'], ENT_QUOTES, 'UTF-8'); ?></a>
                                <?php elseif ($item['type'] === 'video' && $item['raw_source']): ?>
                                    <span class="media-preview-path">Harici video kaynağı</span>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2>Mevcut Medyalar</h2>
            <?php if (!$mediaItems): ?>
                <p>Henüz medya eklenmedi.</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Başlık</th>
                            <th>Tip</th>
                            <th>Süre</th>
                            <th>Kaynak</th>
                            <th>Yayın Bitişi</th>
                            <th>İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($mediaItems as $row): ?>
                        <tr>
                            <td><?php echo (int) $row['id']; ?></td>
                            <td><?php echo htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo strtoupper($row['type']); ?></td>
                            <td><?php echo (int) $row['duration_seconds']; ?> sn</td>
                            <td>
                                <?php if (!empty($row['storage_path'])): ?>
                                    <?php $asset = asset_url(ltrim($row['storage_path'], '/')); ?>
                                    <a href="<?php echo htmlspecialchars($asset, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($row['storage_path'], ENT_QUOTES, 'UTF-8'); ?></a>
                                <?php elseif ($row['type'] === 'video' && !empty($row['source'])): ?>
                                    <a href="<?php echo htmlspecialchars($row['source'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Bağlantı</a>
                                <?php elseif ($row['type'] === 'video'): ?>
                                    <small>Kaynak tanımlı değil</small>
                                <?php elseif (!empty($row['source'])): ?>
                                    <small>Gömülü veri</small>
                                <?php else: ?>
                                    <small>Kaynak tanımlı değil</small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $row['expires_at'] ? htmlspecialchars($row['expires_at'], ENT_QUOTES, 'UTF-8') : 'Süresiz'; ?></td>
                            <td>
                                <form method="post" onsubmit="return confirm('Bu medya silinsin mi?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
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
