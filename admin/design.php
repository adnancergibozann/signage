<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/signage.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();
$activePage = 'design';
$user = current_user();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$errors = [];

$defaults = signage_layout_defaults();
$current = fetch_signage_layouts();
$modules = [];

foreach ($defaults as $key => $definition) {
    $values = $current[$key] ?? $definition;
    $modules[$key] = [
        'key' => $key,
        'title' => $definition['title'],
        'top' => round((float) ($values['top'] ?? $definition['top']), 2),
        'left' => round((float) ($values['left'] ?? $definition['left']), 2),
        'width' => round((float) ($values['width'] ?? $definition['width']), 2),
        'height' => round((float) ($values['height'] ?? $definition['height']), 2),
        'font_scale' => round((float) ($values['font_scale'] ?? $definition['font_scale']), 2),
        'z_index' => (int) ($values['z_index'] ?? $definition['z_index']),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_layout') {
        $raw = $_POST['layout_payload'] ?? '';
        $payload = json_decode($raw, true);

        if (!is_array($payload)) {
            $errors[] = 'Yerleşim verisi okunamadı. Lütfen tekrar deneyin.';
        } else {
            try {
                save_signage_layouts($payload);
                $_SESSION['flash'] = 'Yerleşim ayarları güncellendi.';
                header('Location: ' . route_url('admin/design.php'));
                exit;
            } catch (Throwable $e) {
                $errors[] = 'Kaydetme sırasında bir hata oluştu: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'reset_layout') {
        reset_signage_layouts();
        $_SESSION['flash'] = 'Yerleşim varsayılan değerlere döndürüldü.';
        header('Location: ' . route_url('admin/design.php'));
        exit;
    }
}

function design_preview_html(string $key): string
{
    switch ($key) {
        case 'logo':
            return '<strong>Logo &amp; Kurum</strong><span>Okulumuz</span>';
        case 'next_period':
            return '<strong>Sonraki Ders</strong><span>09:30</span>';
        case 'teachers':
            return '<strong>Nöbetçi Öğretmenler</strong><span>3 kişi</span>';
        case 'news':
            return '<strong>Haber Manşeti</strong><span>Özet örneği</span>';
        case 'media':
            return '<strong>Medya Alanı</strong><span>Slayt önizleme</span>';
        case 'weather':
            return '<strong>Hava Durumu</strong><span>24°C Açık</span>';
        case 'schedule':
            return '<strong>Ders Programı</strong><span>Hafta içi 7 gün</span>';
        case 'countdowns':
            return '<strong>Etkinlik</strong><span>12 Gün</span>';
        case 'ticker':
            return '<span>🎓 Örnek duyuru metni kayar</span>';
        default:
            return '<strong>Örnek Modül</strong>';
    }
}

$defaultsPayload = [];
foreach ($defaults as $key => $definition) {
    $defaultsPayload[$key] = [
        'top' => round($definition['top'], 2),
        'left' => round($definition['left'], 2),
        'width' => round($definition['width'], 2),
        'height' => round($definition['height'], 2),
        'font_scale' => round($definition['font_scale'], 2),
        'z_index' => (int) $definition['z_index'],
    ];
}

$layoutPayload = [];
foreach ($modules as $module) {
    $layoutPayload[$module['key']] = [
        'top' => $module['top'],
        'left' => $module['left'],
        'width' => $module['width'],
        'height' => $module['height'],
        'font_scale' => $module['font_scale'],
        'z_index' => $module['z_index'],
    ];
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dizayn Modülü | Signage</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/styles.css'), ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body>
<div class="admin-layout">
    <?php include __DIR__ . '/partials/sidebar.php'; ?>
    <main class="content">
        <header class="flex" style="justify-content: space-between; margin-bottom: 2rem; align-items: flex-start;">
            <div>
                <h1 style="color: var(--color-primary);">Dizayn Modülü</h1>
                <p>Ekrandaki modüllerin konum, boyut ve yazı boyutlarını sürükle-bırak ile düzenleyin.</p>
            </div>
            <div class="design-actions">
                <form method="post" id="layout-save-form">
                    <input type="hidden" name="action" value="save_layout">
                    <input type="hidden" name="layout_payload" id="layout-payload">
                    <button type="submit" class="button button-primary">Dizaynı Kaydet</button>
                </form>
                <form method="post" onsubmit="return confirm('Varsayılan yerleşime dönmek istediğinize emin misiniz?');">
                    <input type="hidden" name="action" value="reset_layout">
                    <button type="submit" class="button button-secondary">Varsayılanlara Dön</button>
                </form>
            </div>
        </header>

        <?php if ($flash): ?>
            <div class="alert" style="background: rgba(64, 255, 142, 0.15); border: 1px solid rgba(64, 255, 142, 0.4); margin-bottom: 1.5rem;">
                <?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="alert alert-error">
                <ul style="list-style: disc; padding-left: 1.2rem;">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="design-layout">
            <section class="design-canvas">
                <div class="design-stage" id="design-stage">
                    <?php foreach ($modules as $module): ?>
                        <?php $style = module_layout_style($modules, $module['key']); ?>
                        <div class="design-module" data-key="<?php echo htmlspecialchars($module['key'], ENT_QUOTES, 'UTF-8'); ?>"
                             data-top="<?php echo htmlspecialchars($module['top'], ENT_QUOTES, 'UTF-8'); ?>"
                             data-left="<?php echo htmlspecialchars($module['left'], ENT_QUOTES, 'UTF-8'); ?>"
                             data-width="<?php echo htmlspecialchars($module['width'], ENT_QUOTES, 'UTF-8'); ?>"
                             data-height="<?php echo htmlspecialchars($module['height'], ENT_QUOTES, 'UTF-8'); ?>"
                             data-font-scale="<?php echo htmlspecialchars($module['font_scale'], ENT_QUOTES, 'UTF-8'); ?>"
                             data-z-index="<?php echo htmlspecialchars($module['z_index'], ENT_QUOTES, 'UTF-8'); ?>"
                             style="<?php echo htmlspecialchars($style, ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="design-drag-handle">
                                <span><?php echo htmlspecialchars($module['title'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="design-geometry" data-role="geometry"></span>
                            </div>
                            <div class="design-module-preview">
                                <?php echo design_preview_html($module['key']); ?>
                            </div>
                            <div class="design-resize-handle" role="presentation"></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="design-help">
                    <p>Modülleri sürükleyerek taşıyın, köşe noktasından tutup yeniden boyutlandırın. Sağ panelden değerleri manuel olarak da güncelleyebilirsiniz.</p>
                    <button type="button" class="button button-secondary" data-role="apply-defaults">Varsayılan Yerleşimi Yükle</button>
                </div>
            </section>
            <aside class="design-side-panel">
                <?php foreach ($modules as $module): ?>
                    <section class="design-panel-card" data-key="<?php echo htmlspecialchars($module['key'], ENT_QUOTES, 'UTF-8'); ?>">
                        <h2><?php echo htmlspecialchars($module['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
                        <div class="design-panel-grid">
                            <label>
                                <span>Sol (%)</span>
                                <input type="number" step="0.1" min="0" max="100" data-module-input data-key="<?php echo htmlspecialchars($module['key'], ENT_QUOTES, 'UTF-8'); ?>" data-field="left" value="<?php echo htmlspecialchars($module['left'], ENT_QUOTES, 'UTF-8'); ?>">
                            </label>
                            <label>
                                <span>Üst (%)</span>
                                <input type="number" step="0.1" min="0" max="100" data-module-input data-key="<?php echo htmlspecialchars($module['key'], ENT_QUOTES, 'UTF-8'); ?>" data-field="top" value="<?php echo htmlspecialchars($module['top'], ENT_QUOTES, 'UTF-8'); ?>">
                            </label>
                            <label>
                                <span>Genişlik (%)</span>
                                <input type="number" step="0.1" min="5" max="100" data-module-input data-key="<?php echo htmlspecialchars($module['key'], ENT_QUOTES, 'UTF-8'); ?>" data-field="width" value="<?php echo htmlspecialchars($module['width'], ENT_QUOTES, 'UTF-8'); ?>">
                            </label>
                            <label>
                                <span>Yükseklik (%)</span>
                                <input type="number" step="0.1" min="5" max="100" data-module-input data-key="<?php echo htmlspecialchars($module['key'], ENT_QUOTES, 'UTF-8'); ?>" data-field="height" value="<?php echo htmlspecialchars($module['height'], ENT_QUOTES, 'UTF-8'); ?>">
                            </label>
                        </div>
                        <div class="design-panel-grid" style="grid-template-columns: repeat(2, minmax(0, 1fr));">
                            <label>
                                <span>Yazı Ölçeği</span>
                                <input type="number" step="0.05" min="0.25" max="4" data-module-input data-key="<?php echo htmlspecialchars($module['key'], ENT_QUOTES, 'UTF-8'); ?>" data-field="fontScale" value="<?php echo htmlspecialchars($module['font_scale'], ENT_QUOTES, 'UTF-8'); ?>">
                            </label>
                            <label>
                                <span>Z Sırası</span>
                                <input type="number" step="1" min="0" max="20" data-module-input data-key="<?php echo htmlspecialchars($module['key'], ENT_QUOTES, 'UTF-8'); ?>" data-field="zIndex" value="<?php echo htmlspecialchars($module['z_index'], ENT_QUOTES, 'UTF-8'); ?>">
                            </label>
                        </div>
                    </section>
                <?php endforeach; ?>
            </aside>
        </div>
    </main>
</div>
<script>
    window.__LAYOUT_CONFIG__ = {
        modules: <?php echo json_encode($layoutPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        defaults: <?php echo json_encode($defaultsPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
    };
</script>
<script src="<?php echo htmlspecialchars(asset_url('assets/design.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
</body>
</html>
