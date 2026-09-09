<?php
require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../../vendor/autoload.php';

use App\Common\Http\ApiKeyMiddleware;
use App\Common\Services\AsientoContableService;

// Backend-a-backend (X-Api-Key, no Cognito): usado por caja-registradora al
// autorizar una factura (venta/servicio) y por apotheca al registrar una
// compra de inventario — ambos arman el asiento con códigos NIIF, sin conocer
// los UUID internos de las cuentas de esta empresa (ver
// AsientoContableService::crearAsientoPorCodigo).
return function (array $event) {
    if ($deny = ApiKeyMiddleware::check($event)) {
        return $deny;
    }

    $profileId = $event['pathParameters']['profileId'] ?? null;
    $data = json_decode($event['body'] ?? '', true);

    if (empty($profileId)) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Missing required profileId'])];
    }

    $requiredFields = ['fecha', 'descripcion', 'entries'];
    foreach ($requiredFields as $field) {
        if (empty($data[$field])) {
            return ['statusCode' => 400, 'body' => json_encode(['error' => "Missing field: $field"])];
        }
    }

    try {
        $asiento = (new AsientoContableService())->crearAsientoPorCodigo($profileId, $data);

        return [
            'statusCode' => 201,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['data' => $asiento])
        ];
    } catch (\Throwable $e) {
        $esValidacion = str_contains($e->getMessage(), 'asiento') || str_contains($e->getMessage(), 'cuenta') || str_contains($e->getMessage(), 'línea');
        return [
            'statusCode' => $esValidacion ? 400 : 500,
            'body' => json_encode(['error' => $e->getMessage()])
        ];
    }
};
