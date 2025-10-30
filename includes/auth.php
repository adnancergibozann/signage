<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function attempt_login(string $username, string $password): bool
{
    try {
        $pdo = get_pdo();
        $stmt = $pdo->prepare('SELECT id, username, password_hash, role FROM admin_users WHERE username = :username LIMIT 1');
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user'] = [
                'id' => $user['id'],
                'username' => $user['username'],
                'role' => $user['role'] ?? 'admin',
            ];
            return true;
        }
    } catch (PDOException $e) {
        error_log($e->getMessage());
    }

    return false;
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function current_user_role(): string
{
    $user = current_user();

    return $user['role'] ?? 'admin';
}

function is_super_admin(?array $user = null): bool
{
    $user = $user ?? current_user();

    return $user && ($user['role'] ?? 'admin') === 'super_admin';
}

function require_login(): void
{
    if (!current_user()) {
        header('Location: ' . route_url('admin/login.php'));
        exit;
    }
}

function require_super_admin(): void
{
    require_login();

    if (!is_super_admin()) {
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><title>Erişim Engellendi</title></head><body style="font-family: Arial, sans-serif; background:#0A0A0A; color:#fff; display:flex; align-items:center; justify-content:center; min-height:100vh;"><div style="text-align:center; max-width:480px; padding:2rem; background:rgba(0,0,0,0.6); border-radius:12px;">'
            . '<h1 style="margin-bottom:1rem; color:#FFD400;">Yetkisiz Erişim</h1>'
            . '<p>Bu sayfayı görüntülemek için yetkin bulunmuyor. Lütfen sistem yöneticisi ile iletişime geç.</p>'
            . '<p style="margin-top:1.5rem;"><a href="' . htmlspecialchars(route_url('admin/dashboard.php'), ENT_QUOTES, 'UTF-8') . '" style="color:#FFD400; text-decoration:none;">Panele Dön</a></p>'
            . '</div></body></html>';
        exit;
    }
}
