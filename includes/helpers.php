<?php
if (!function_exists('app_base_path')) {
    function app_base_path(): string
    {
        static $basePath;

        if ($basePath !== null) {
            return $basePath;
        }

        $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        $projectRoot = realpath(__DIR__ . '/..');

        if ($documentRoot && $projectRoot) {
            $documentRoot = rtrim(str_replace('\\', '/', realpath($documentRoot)), '/');
            $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');

            if ($documentRoot && strpos($projectRoot, $documentRoot) === 0) {
                $relative = substr($projectRoot, strlen($documentRoot));
                if ($relative === false || $relative === '') {
                    return $basePath = '';
                }

                if ($relative[0] !== '/') {
                    $relative = '/' . $relative;
                }

                return $basePath = rtrim($relative, '/');
            }
        }

        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $scriptDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

        if ($scriptDir === '.' || $scriptDir === '/' || $scriptDir === '') {
            return $basePath = '';
        }

        if (substr($scriptDir, -6) === '/admin') {
            $scriptDir = substr($scriptDir, 0, -6);
        }

        return $basePath = rtrim($scriptDir, '/');
    }
}

if (!function_exists('route_url')) {
    function route_url(string $path): string
    {
        $normalizedPath = ltrim($path, '/');
        $base = app_base_path();

        if ($base === '') {
            return '/' . $normalizedPath;
        }

        return rtrim($base, '/') . '/' . $normalizedPath;
    }
}

if (!function_exists('asset_url')) {
    function asset_url(string $path): string
    {
        return route_url($path);
    }
}
