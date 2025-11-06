<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

logout();

header('Location: ' . url_for('admin/login.php'));
exit;
