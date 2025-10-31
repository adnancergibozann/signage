<?php
declare(strict_types=1);

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(): void
{
    if (!current_user()) {
        header('Location: /admin/login.php');
        exit;
    }
}

function has_role(string $role): bool
{
    $user = current_user();
    return $user && $user['role'] === $role;
}

function require_role(string|array $roles): void
{
    $roles = (array) $roles;
    $user = current_user();
    if (!$user || !in_array($user['role'], $roles, true)) {
        header('HTTP/1.1 403 Forbidden');
        echo 'Bu işlemi yapmak için yetkiniz yok.';
        exit;
    }
}

function attempt_login(string $username, string $password): bool
{
    $pdo = get_pdo();
    $stmt = $pdo->prepare('SELECT id, username, password_hash, role, full_name, department FROM users WHERE username = :username LIMIT 1');
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user'] = [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'role' => $user['role'],
            'full_name' => $user['full_name'],
            'department' => $user['department'],
        ];
        return true;
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

function ensure_manager_status_row(int $userId): void
{
    $pdo = get_pdo();
    $stmt = $pdo->prepare('INSERT IGNORE INTO manager_statuses (user_id) VALUES (:user_id)');
    $stmt->execute(['user_id' => $userId]);
}
