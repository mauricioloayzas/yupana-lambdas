<?php
require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../../vendor/autoload.php';

use App\Common\Http\ProfileAccessMiddleware;
use App\Common\Services\AsientoContableService;

return function (array $event) {
    $profileId = $event['pathParameters']['profileId'] ?? null;
    $data = json_decode($event['body'] ?? '', true);

    if (empty($profileId)) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Missing required profileId'])];
    }

    if ($deny = ProfileAccessMiddleware::check($event, $profileId)) {
        return $deny;
    }

    $requiredFields = ['fecha', 'descripcion', 'entries'];
    foreach ($requiredFields as $field) {
        if (empty($data[$field])) {
            return ['statusCode' => 400, 'body' => json_encode(['error' => "Missing field: $field"])];
        }
    }

    try {
        $asiento = (new AsientoContableService())->crearAsiento($profileId, $data);

        return [
            'statusCode' => 201,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['data' => $asiento])
        ];
    } catch (\Throwable $e) {
        // Las validaciones de negocio (desbalance, cuenta no postable, etc.) se
        // devuelven como 400; cualquier otra falla, como 500.
        $esValidacion = str_contains($e->getMessage(), 'asiento') || str_contains($e->getMessage(), 'cuenta') || str_contains($e->getMessage(), 'Cada línea');
        return [
            'statusCode' => $esValidacion ? 400 : 500,
            'body' => json_encode(['error' => $e->getMessage()])
        ];
    }
};
