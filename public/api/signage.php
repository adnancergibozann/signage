<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/signage.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

try {
    $payload = get_signage_payload();

    echo json_encode([
        'success' => true,
        'version' => $payload['version'],
        'generated_at' => $payload['generated_at'],
        'data' => $payload['data'],
        'license' => $payload['license'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Beklenmeyen bir hata oluştu',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
