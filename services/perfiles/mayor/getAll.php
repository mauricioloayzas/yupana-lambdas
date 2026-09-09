<?php
require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../../vendor/autoload.php';

use App\Common\Http\ProfileAccessMiddleware;
use App\Common\Repositories\CuentaContableProfileRepository;
use App\Common\Repositories\MayorContableRepository;

return function (array $event) {
    $profileId = $event['pathParameters']['profileId'] ?? null;
    $cuentaId = $event['queryStringParameters']['cuenta_id'] ?? null;

    if (empty($profileId)) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Missing required profileId'])];
    }
    if (empty($cuentaId)) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Missing required cuenta_id in query'])];
    }

    if ($deny = ProfileAccessMiddleware::check($event, $profileId)) {
        return $deny;
    }

    $cuenta = (new CuentaContableProfileRepository())->get($cuentaId);
    if (!$cuenta || $cuenta->profile_id !== $profileId) {
        return ['statusCode' => 404, 'body' => json_encode(['error' => 'Cuenta no encontrada'])];
    }

    try {
        $movimientos = array_map(
            fn($m) => $m->toArray(),
            (new MayorContableRepository())->findAllByCuentaId($cuentaId)
        );

        return [
            'statusCode' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['data' => $movimientos])
        ];
    } catch (\Throwable $e) {
        return ['statusCode' => 500, 'body' => json_encode(['error' => $e->getMessage()])];
    }
};
