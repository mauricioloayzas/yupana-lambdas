<?php
require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../../vendor/autoload.php';

use App\Common\Http\ProfileAccessMiddleware;
use App\Common\Repositories\AsientoContableRepository;
use App\Common\Repositories\AsientoContableDetalleRepository;

return function (array $event) {
    $profileId = $event['pathParameters']['profileId'] ?? null;
    $id = $event['pathParameters']['id'] ?? null;
    if (empty($profileId) || empty($id)) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Missing required profileId/id'])];
    }

    if ($deny = ProfileAccessMiddleware::check($event, $profileId)) {
        return $deny;
    }

    try {
        $repo = new AsientoContableRepository();
        $asiento = $repo->get($id);

        if (!$asiento || $asiento->profile_id !== $profileId) {
            return ['statusCode' => 404, 'body' => json_encode(['error' => 'Asiento no encontrado'])];
        }

        $asientoArr = $asiento->toArray();
        $asientoArr['detalles'] = array_map(
            fn($d) => $d->toArray(),
            (new AsientoContableDetalleRepository())->getByAsientoId($id)
        );

        return [
            'statusCode' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($asientoArr)
        ];
    } catch (\Throwable $e) {
        return ['statusCode' => 500, 'body' => json_encode(['error' => $e->getMessage()])];
    }
};
