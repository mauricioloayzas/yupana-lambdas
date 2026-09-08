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

    $repo = new AsientoContableRepository();
    $asiento = $repo->get($id);
    if (!$asiento || $asiento->profile_id !== $profileId) {
        return ['statusCode' => 404, 'body' => json_encode(['error' => 'Asiento no encontrado'])];
    }

    // Nota: eliminar un asiento ya mayorizado NO reversa sus saldos en el mayor
    // (revertir mayorización queda fuera de esta fase). Por ahora solo elimina
    // el registro del asiento/detalles; el ajuste de saldos, si hace falta, se
    // hace con un asiento de reverso manual.
    try {
        (new AsientoContableDetalleRepository())->deleteByAsientoId($id);
        $repo->delete($id);

        return ['statusCode' => 204];
    } catch (\Throwable $e) {
        return ['statusCode' => 500, 'body' => json_encode(['error' => $e->getMessage()])];
    }
};
