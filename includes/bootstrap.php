<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';
$GLOBALS['config'] = array_merge($config, $GLOBALS['config'] ?? []);

if (!empty($GLOBALS['config']['timezone'])) {
    date_default_timezone_set($GLOBALS['config']['timezone']);
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/log.php';
