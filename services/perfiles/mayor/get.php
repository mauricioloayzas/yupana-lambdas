<?php
require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../../vendor/autoload.php';

use App\Common\Http\ProfileAccessMiddleware;
use App\Common\Repositories\CuentaContableProfileRepository;
use App\Common\Repositories\MayorContableRepository;

return function (array $event) {
    $profileId = $event['pathParameters']['profileId'] ?? null;
    $cuentaId = $event['pathParameters']['cuentaId'] ?? null;
    $anio = $event['pathParameters']['anio'] ?? null;
    $mes = $event['pathParameters']['mes'] ?? null;

    if (empty($profileId) || empty($cuentaId) || empty($anio) || empty($mes)) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Missing required profileId/cuentaId/anio/mes'])];
    }

    if ($deny = ProfileAccessMiddleware::check($event, $profileId)) {
        return $deny;
    }

    $cuenta = (new CuentaContableProfileRepository())->get($cuentaId);
    if (!$cuenta || $cuenta->profile_id !== $profileId) {
        return ['statusCode' => 404, 'body' => json_encode(['error' => 'Cuenta no encontrada'])];
    }

    try {
        $movimiento = (new MayorContableRepository())->findByCuentaIdAnioMes($cuentaId, $anio, $mes);

        if (!$movimiento) {
            return ['statusCode' => 404, 'body' => json_encode(['error' => 'Sin movimientos en ese período'])];
        }

        return [
            'statusCode' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['data' => $movimiento->toArray()])
        ];
    } catch (\Throwable $e) {
        return ['statusCode' => 500, 'body' => json_encode(['error' => $e->getMessage()])];
    }
};
