<?php
require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../../vendor/autoload.php';

use App\Common\Http\ProfileAccessMiddleware;
use App\Common\Repositories\CuentaContableProfileRepository;

// Solo permite editar nombre y descripción: tipo/naturaleza/codigo/tipo_estado/
// es_detalle vienen del catálogo NIIF oficial y no deben cambiar por empresa.
const CAMPOS_EDITABLES = ['nombre', 'descripcion'];

return function (array $event) {
    $profileId = $event['pathParameters']['profileId'] ?? null;
    $id = $event['pathParameters']['id'] ?? null;
    $body = json_decode($event['body'] ?? '', true);

    if (empty($profileId) || empty($id)) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Missing required profileId/id'])];
    }

    if ($deny = ProfileAccessMiddleware::check($event, $profileId)) {
        return $deny;
    }

    $repo = new CuentaContableProfileRepository();
    $cuenta = $repo->get($id);
    if (!$cuenta || $cuenta->profile_id !== $profileId) {
        return ['statusCode' => 404, 'body' => json_encode(['error' => 'Cuenta no encontrada'])];
    }

    $data = array_intersect_key($body ?? [], array_flip(CAMPOS_EDITABLES));
    if (empty($data)) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'No hay campos editables en el body'])];
    }

    try {
        $actualizada = $repo->update($id, $data);

        return [
            'statusCode' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['data' => $actualizada->toArray()])
        ];
    } catch (\Throwable $e) {
        return ['statusCode' => 500, 'body' => json_encode(['error' => $e->getMessage()])];
    }
};
