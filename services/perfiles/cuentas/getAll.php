<?php
require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../../vendor/autoload.php';

use App\Common\Http\ProfileAccessMiddleware;
use App\Common\Repositories\CuentaContableProfileRepository;

return function (array $event) {
    $profileId = $event['pathParameters']['profileId'] ?? null;
    if (empty($profileId)) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Missing required profileId'])];
    }

    if ($deny = ProfileAccessMiddleware::check($event, $profileId)) {
        return $deny;
    }

    $soloDetalle = $event['queryStringParameters']['solo_detalle'] ?? null;

    try {
        $repo = new CuentaContableProfileRepository();
        $cuentas = array_map(fn($c) => $c->toArray(), $repo->getByProfileId($profileId));

        if ($soloDetalle) {
            $cuentas = array_values(array_filter($cuentas, fn($c) => $c['es_detalle'] === true));
        }

        return [
            'statusCode' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['data' => $cuentas])
        ];
    } catch (\Throwable $e) {
        return ['statusCode' => 500, 'body' => json_encode(['error' => $e->getMessage()])];
    }
};
