<?php
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    logout();
}

header('Location: ' . route_url('admin/login.php'));
exit;
