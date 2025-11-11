<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/products.php';

require_login();

$activePage = 'products';
$user = current_user();
$errors = [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pdo = get_pdo();

$editingId = isset($_GET['id']) ? max(0, (int) $_GET['id']) : 0;
$editingItem = $editingId > 0 ? find_product_slide($editingId) : null;

if ($editingId > 0 && !$editingItem) {
    $errors[] = 'Düzenlenmek istenen ürün bulunamadı.';
    $editingId = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $priceInput = trim($_POST['price'] ?? '');
        $originalPriceInput = trim($_POST['original_price'] ?? '');
        $tagLabel = trim($_POST['tag_label'] ?? '');
        $tagColor = $_POST['tag_color'] ?? '#FFD400';
        $tagTextColor = $_POST['tag_text_color'] ?? '#111111';
        $cardBackground = $_POST['card_background'] ?? '#111111';
        $position = (int) ($_POST['position'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $autoRemove = isset($_POST['auto_remove_background']);

        if ($name === '') {
            $errors[] = 'Ürün adı zorunludur.';
        }

        $price = parse_price($priceInput, $errors, 'Satış fiyatı');
        $originalPrice = parse_price($originalPriceInput, $errors, 'Liste fiyatı');

        if ($tagColor && !is_valid_hex_color($tagColor)) {
            $errors[] = 'Etiket rengi geçersiz.';
        }

        if ($tagTextColor && !is_valid_hex_color($tagTextColor)) {
            $errors[] = 'Etiket yazı rengi geçersiz.';
        }

        if ($cardBackground && !is_valid_hex_color($cardBackground)) {
            $errors[] = 'Kart arka plan rengi geçersiz.';
        }

        if ($position <= 0) {
            $position = (int) $pdo->query('SELECT COALESCE(MAX(position), 0) + 1 FROM product_slides')->fetchColumn();
        }

        $imageData = null;
        $imageMime = null;
        $backgroundRemoved = null;

        $hasUpload = isset($_FILES['product_image']) && is_uploaded_file($_FILES['product_image']['tmp_name']);
        if ($hasUpload) {
            [$imageData, $imageMime, $backgroundRemoved] = process_product_upload($_FILES['product_image'], $autoRemove, $errors);
        } elseif ($id <= 0) {
            $errors[] = 'Lütfen ürün görseli yükleyin.';
        } elseif ($autoRemove) {
            $currentImage = fetch_product_image_blob($pdo, $id);
            if ($currentImage && $currentImage['image']) {
                $processed = try_remove_background($currentImage['image']);
                if ($processed) {
                    $imageData = $processed;
                    $imageMime = 'image/png';
                    $backgroundRemoved = 1;
                } else {
                    $errors[] = 'Mevcut görselde arka plan temizlenemedi.';
                }
            } else {
                $errors[] = 'Arka planı temizlenecek mevcut bir görsel bulunamadı.';
            }
        }

        if (!$errors) {
            if ($id > 0) {
                $fields = [
                    'name' => $name,
                    'description' => $description,
                    'price' => $price,
                    'original_price' => $originalPrice,
                    'tag_label' => $tagLabel,
                    'tag_color' => $tagColor,
                    'tag_text_color' => $tagTextColor,
                    'card_background' => $cardBackground,
                    'position' => $position,
                    'is_active' => $isActive,
                ];

                $setClauses = [];
                foreach ($fields as $key => $value) {
                    $setClauses[] = sprintf('%s = :%s', $key, $key);
                }

                if ($imageData !== null) {
                    $setClauses[] = 'image = :image';
                    $setClauses[] = 'image_mime = :image_mime';
                    $setClauses[] = 'auto_background_removed = :auto_background_removed';
                    $fields['image'] = $imageData;
                    $fields['image_mime'] = $imageMime;
                    $fields['auto_background_removed'] = $backgroundRemoved ? 1 : 0;
                }

                $fields['id'] = $id;
                $sql = 'UPDATE product_slides SET ' . implode(', ', $setClauses) . ' WHERE id = :id';
                $stmt = $pdo->prepare($sql);
                $stmt->execute($fields);

                $_SESSION['flash'] = 'Ürün güncellendi.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO product_slides (name, description, price, original_price, tag_label, tag_color, tag_text_color, card_background, image, image_mime, auto_background_removed, position, is_active) VALUES (:name, :description, :price, :original_price, :tag_label, :tag_color, :tag_text_color, :card_background, :image, :image_mime, :auto_background_removed, :position, :is_active)');
                $stmt->execute([
                    'name' => $name,
                    'description' => $description,
                    'price' => $price,
                    'original_price' => $originalPrice,
                    'tag_label' => $tagLabel,
                    'tag_color' => $tagColor,
                    'tag_text_color' => $tagTextColor,
                    'card_background' => $cardBackground,
                    'image' => $imageData,
                    'image_mime' => $imageMime,
                    'auto_background_removed' => $backgroundRemoved ? 1 : 0,
                    'position' => $position,
                    'is_active' => $isActive,
                ]);

                $_SESSION['flash'] = 'Ürün kaydedildi.';
            }

            header('Location: /admin/products.php');
            exit;
        }

        $editingId = $id;
        $editingItem = $id > 0 ? find_product_slide($id) : null;
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM product_slides WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $_SESSION['flash'] = 'Ürün silindi.';
            header('Location: /admin/products.php');
            exit;
        }
    }
}

$products = fetch_all_product_slides();

function parse_price(string $value, array &$errors, string $label): ?float
{
    if ($value === '') {
        return null;
    }

    $normalized = str_replace([' ', '₺'], '', $value);
    $normalized = str_replace(',', '.', $normalized);

    if (!is_numeric($normalized)) {
        $errors[] = sprintf('%s sayısal olmalıdır.', $label);
        return null;
    }

    return round((float) $normalized, 2);
}

function is_valid_hex_color(string $value): bool
{
    return (bool) preg_match('/^#[0-9A-Fa-f]{6}$/', $value);
}

function process_product_upload(array $file, bool $autoRemove, array &$errors): array
{
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Görsel yüklenirken bir hata oluştu.';
        return [null, null, null];
    }

    $mime = mime_content_type($file['tmp_name']);
    $allowed = ['image/png', 'image/jpeg', 'image/webp'];
    if (!in_array($mime, $allowed, true)) {
        $errors[] = 'Yalnızca PNG, JPG ve WebP formatları desteklenir.';
        return [null, null, null];
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        $errors[] = 'Görsel boyutu 5MB sınırını aşmamalıdır.';
        return [null, null, null];
    }

    $binary = file_get_contents($file['tmp_name']);
    if ($binary === false) {
        $errors[] = 'Görsel okunamadı.';
        return [null, null, null];
    }

    if ($autoRemove) {
        $processed = try_remove_background($binary);
        if ($processed) {
            return [$processed, 'image/png', 1];
        }
        $errors[] = 'Arka plan otomatik temizlenemedi.';
    }

    return [$binary, $mime, 0];
}

function try_remove_background(string $binary): ?string
{
    if (!extension_loaded('gd')) {
        return null;
    }

    $image = imagecreatefromstring($binary);
    if (!$image) {
        return null;
    }

    $width = imagesx($image);
    $height = imagesy($image);
    if ($width === 0 || $height === 0) {
        imagedestroy($image);
        return null;
    }

    imagealphablending($image, true);
    imagesavealpha($image, true);

    $background = approximate_background_color($image, $width, $height);
    $output = imagecreatetruecolor($width, $height);
    imagealphablending($output, false);
    imagesavealpha($output, true);

    $transparent = imagecolorallocatealpha($output, 0, 0, 0, 127);
    imagefill($output, 0, 0, $transparent);

    $threshold = 42;

    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $rgba = imagecolorsforindex($image, imagecolorat($image, $x, $y));

            if ($rgba['alpha'] === 127) {
                continue;
            }

            if (color_distance($rgba, $background) <= $threshold) {
                continue;
            }

            $color = imagecolorallocatealpha($output, $rgba['red'], $rgba['green'], $rgba['blue'], $rgba['alpha']);
            imagesetpixel($output, $x, $y, $color);
        }
    }

    ob_start();
    imagepng($output);
    $processed = ob_get_clean();

    imagedestroy($image);
    imagedestroy($output);

    return $processed ?: null;
}

function approximate_background_color($image, int $width, int $height): array
{
    $samples = [
        [0, 0],
        [$width - 1, 0],
        [0, $height - 1],
        [$width - 1, $height - 1],
        [(int) floor($width / 2), 0],
        [(int) floor($width / 2), $height - 1],
        [0, (int) floor($height / 2)],
        [$width - 1, (int) floor($height / 2)],
    ];

    $total = ['red' => 0, 'green' => 0, 'blue' => 0];
    $count = 0;

    foreach ($samples as [$x, $y]) {
        $x = max(0, min($width - 1, $x));
        $y = max(0, min($height - 1, $y));
        $rgba = imagecolorsforindex($image, imagecolorat($image, $x, $y));
        $total['red'] += $rgba['red'];
        $total['green'] += $rgba['green'];
        $total['blue'] += $rgba['blue'];
        $count++;
    }

    return [
        'red' => (int) round($total['red'] / $count),
        'green' => (int) round($total['green'] / $count),
        'blue' => (int) round($total['blue'] / $count),
    ];
}

function color_distance(array $a, array $b): float
{
    $dr = $a['red'] - $b['red'];
    $dg = $a['green'] - $b['green'];
    $db = $a['blue'] - $b['blue'];

    return sqrt(($dr * $dr) + ($dg * $dg) + ($db * $db));
}

function fetch_product_image_blob(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT image, image_mime FROM product_slides WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    return $row ?: null;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ürün İndirimleri | Signage</title>
    <link rel="stylesheet" href="/assets/styles.css">
    <style>
        .product-preview-img {
            width: 80px;
            height: 80px;
            object-fit: contain;
            background: rgba(255,255,255,0.05);
            border-radius: 12px;
            padding: 8px;
        }
        .inline-flex {
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        .form-grid.two-column {
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        }
    </style>
</head>
<body>
<div class="admin-layout">
    <?php include __DIR__ . '/partials/sidebar.php'; ?>
    <main class="content">
        <header style="margin-bottom: 2rem;">
            <h1 style="color: var(--color-primary);">Ürün İndirimleri</h1>
            <p>LED ekran kaydırıcısında görünecek ürünleri yönetin.</p>
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
            <div class="alert" style="background: rgba(255,212,0,0.15); border: 1px solid rgba(255,212,0,0.5);">
                <?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <section class="card" style="margin-bottom: 2rem;">
            <h2><?php echo $editingId > 0 ? 'Ürünü Düzenle' : 'Yeni Ürün'; ?></h2>
            <form method="post" enctype="multipart/form-data" class="form-grid two-column">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?php echo (int) $editingId; ?>">

                <div>
                    <label for="name">Ürün Adı</label>
                    <input type="text" id="name" name="name" required value="<?php echo htmlspecialchars($editingItem['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <div>
                    <label for="description">Kısa Açıklama</label>
                    <input type="text" id="description" name="description" value="<?php echo htmlspecialchars($editingItem['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Örn. 500 ml - Karışık Meyve">
                </div>

                <div>
                    <label for="price">Satış Fiyatı</label>
                    <input type="text" id="price" name="price" inputmode="decimal" value="<?php echo htmlspecialchars($editingItem['price'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Örn. 49.90">
                </div>

                <div>
                    <label for="original_price">Liste Fiyatı</label>
                    <input type="text" id="original_price" name="original_price" inputmode="decimal" value="<?php echo htmlspecialchars($editingItem['original_price'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Örn. 69.90">
                </div>

                <div>
                    <label for="tag_label">Etiket Başlığı</label>
                    <input type="text" id="tag_label" name="tag_label" value="<?php echo htmlspecialchars($editingItem['tag_label'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Örn. %25 İndirim">
                </div>

                <div class="inline-flex">
                    <div>
                        <label for="tag_color">Etiket Rengi</label>
                        <input type="color" id="tag_color" name="tag_color" value="<?php echo htmlspecialchars($editingItem['tag_color'] ?? '#FFD400', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div>
                        <label for="tag_text_color">Etiket Yazı Rengi</label>
                        <input type="color" id="tag_text_color" name="tag_text_color" value="<?php echo htmlspecialchars($editingItem['tag_text_color'] ?? '#111111', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                </div>

                <div>
                    <label for="card_background">Kart Arka Planı</label>
                    <input type="color" id="card_background" name="card_background" value="<?php echo htmlspecialchars($editingItem['card_background'] ?? '#111111', ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <div>
                    <label for="position">Sıra</label>
                    <input type="number" id="position" name="position" min="1" value="<?php echo htmlspecialchars($editingItem['position'] ?? (count($products) + 1), ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <div>
                    <label for="product_image">Ürün Görseli</label>
                    <input type="file" id="product_image" name="product_image" accept="image/png,image/jpeg,image/webp">
                    <small>PNG formatı önerilir. Mevcut görseli korumak için yeni dosya seçmeyin.</small>
                </div>

                <div>
                    <label for="auto_remove_background" style="display: block;">Arka Planı Temizle</label>
                    <label class="checkbox">
                        <input type="checkbox" id="auto_remove_background" name="auto_remove_background" <?php echo !empty($editingItem['auto_background_removed']) ? 'checked' : ''; ?>>
                        <span>Tek renk arka planı otomatik şeffaflaştır</span>
                    </label>
                </div>

                <div>
                    <label class="checkbox">
                        <input type="checkbox" id="is_active" name="is_active" <?php echo (empty($editingItem) || !empty($editingItem['is_active'])) ? 'checked' : ''; ?>>
                        <span>LED ekranında göster</span>
                    </label>
                </div>

                <div style="grid-column: 1 / -1; display: flex; gap: 1rem; align-items: center;">
                    <button type="submit" class="button button-primary">Kaydet</button>
                    <?php if ($editingId > 0): ?>
                        <a href="/admin/products.php" class="button button-secondary">Vazgeç</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <section class="card">
            <h2>Mevcut Ürünler</h2>
            <?php if (!$products): ?>
                <p>Henüz ürün eklenmedi.</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Ürün</th>
                            <th>Fiyat</th>
                            <th>Etiket</th>
                            <th>Durum</th>
                            <th>Önizleme</th>
                            <th>İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($products as $product): ?>
                        <tr>
                            <td><?php echo (int) $product['id']; ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8'); ?></strong><br>
                                <small><?php echo htmlspecialchars($product['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></small>
                            </td>
                            <td>
                                <?php if ($product['price'] !== null): ?>
                                    <span><?php echo number_format($product['price'], 2, ',', '.'); ?> ₺</span>
                                <?php endif; ?>
                                <?php if ($product['original_price'] !== null): ?>
                                    <br><small style="text-decoration: line-through; opacity: .6;">
                                        <?php echo number_format($product['original_price'], 2, ',', '.'); ?> ₺
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($product['tag_label']): ?>
                                    <span style="display:inline-block;padding:4px 8px;border-radius:6px;background: <?php echo htmlspecialchars($product['tag_color'], ENT_QUOTES, 'UTF-8'); ?>;color: <?php echo htmlspecialchars($product['tag_text_color'], ENT_QUOTES, 'UTF-8'); ?>;">
                                        <?php echo htmlspecialchars($product['tag_label'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($product['discount_percent'] !== null): ?>
                                    <br><small>%<?php echo (int) $product['discount_percent']; ?> indirim</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo $product['is_active'] ? 'Aktif' : 'Pasif'; ?><br>
                                <small>Sıra: <?php echo (int) $product['position']; ?></small>
                            </td>
                            <td>
                                <?php if ($product['image_data_url']): ?>
                                    <img src="<?php echo htmlspecialchars($product['image_data_url'], ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8'); ?>" class="product-preview-img">
                                <?php else: ?>
                                    <small>Görsel yok</small>
                                <?php endif; ?>
                            </td>
                            <td style="display:flex; gap:.5rem;">
                                <a href="/admin/products.php?id=<?php echo (int) $product['id']; ?>" class="button button-secondary">Düzenle</a>
                                <form method="post" onsubmit="return confirm('Bu ürün silinsin mi?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int) $product['id']; ?>">
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
