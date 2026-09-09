<?php
require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../../vendor/autoload.php';

use App\Common\Http\ProfileAccessMiddleware;
use App\Common\Repositories\AsientoContableRepository;

// No se permite editar las líneas (entries) de un asiento ya mayorizado: cambiar
// montos requeriría reversar la mayorización, que no está en el alcance de esta
// fase. Solo se puede corregir la fecha o la descripción.
const CAMPOS_EDITABLES = ['fecha', 'descripcion'];

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

    $repo = new AsientoContableRepository();
    $asiento = $repo->get($id);
    if (!$asiento || $asiento->profile_id !== $profileId) {
        return ['statusCode' => 404, 'body' => json_encode(['error' => 'Asiento no encontrado'])];
    }

    $data = array_intersect_key($body ?? [], array_flip(CAMPOS_EDITABLES));
    if (empty($data)) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'No hay campos editables en el body'])];
    }

    try {
        $actualizado = $repo->update($id, $data);

        return [
            'statusCode' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['data' => $actualizado->toArray()])
        ];
    } catch (\Throwable $e) {
        return ['statusCode' => 500, 'body' => json_encode(['error' => $e->getMessage()])];
    }
};
