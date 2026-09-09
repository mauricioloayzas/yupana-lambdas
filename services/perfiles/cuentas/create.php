<?php
require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../../vendor/autoload.php';

use App\Common\Http\ProfileAccessMiddleware;
use App\Common\Services\CuentaContablePersonalizadaService;

return function (array $event) {
    $profileId = $event['pathParameters']['profileId'] ?? null;
    $body = json_decode($event['body'] ?? '', true);

    if (empty($profileId)) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Missing required profileId'])];
    }

    if ($deny = ProfileAccessMiddleware::check($event, $profileId)) {
        return $deny;
    }

    if (empty($body['parent_id']) || empty($body['nombre'])) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Missing required fields: parent_id, nombre'])];
    }

    try {
        $cuenta = (new CuentaContablePersonalizadaService())->crear(
            $profileId,
            $body['parent_id'],
            $body['nombre'],
            $body['descripcion'] ?? null
        );

        return [
            'statusCode' => 201,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['data' => $cuenta])
        ];
    } catch (\Throwable $e) {
        $esValidacion = str_contains($e->getMessage(), 'padre');
        return [
            'statusCode' => $esValidacion ? 400 : 500,
            'body' => json_encode(['error' => $e->getMessage()])
        ];
    }
};
