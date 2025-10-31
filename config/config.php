<?php
if (PHP_SAPI !== 'cli') {
    $detectedBasePath = '';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? '';
    $projectRoot = realpath(__DIR__ . '/..');

    if ($scriptName && $scriptFilename && $projectRoot) {
        $scriptReal = realpath($scriptFilename);
        if ($scriptReal && str_starts_with($scriptReal, $projectRoot)) {
            $relativePath = substr($scriptReal, strlen($projectRoot));
            $relativePath = '/' . ltrim(str_replace(DIRECTORY_SEPARATOR, '/', $relativePath), '/');
            if ($relativePath !== '' && str_ends_with($scriptName, $relativePath)) {
                $baseCandidate = substr($scriptName, 0, -strlen($relativePath));
                $detectedBasePath = rtrim($baseCandidate, '/');
            }
        }
    }
} else {
    $detectedBasePath = '';
}

return [
    'timezone' => 'Europe/Istanbul',
    'app' => [
        'base_path' => rtrim(getenv('APP_BASE_PATH') ?: $detectedBasePath, '/'),
    ],
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: '3306',
        'name' => getenv('DB_NAME') ?: 'signage',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],
    'signage' => [
        'refresh_seconds' => (int) (getenv('SIGNAGE_REFRESH') ?: 5),
        'time_format' => getenv('SIGNAGE_TIME_FORMAT') ?: '24h',
    ],
    'theme' => [
        'primary' => '#E3000B',
        'secondary' => '#17007A',
        'dark' => '#080915',
        'light' => '#FFFFFF',
    ],
];
