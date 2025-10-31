<?php
declare(strict_types=1);

require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../../includes/signage.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

echo json_encode(fetch_signage_state(), JSON_UNESCAPED_UNICODE);
