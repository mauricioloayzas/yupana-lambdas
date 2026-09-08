<?php
require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../../vendor/autoload.php';

use App\Common\Http\ProfileAccessMiddleware;
use App\Common\Repositories\CuentaContableProfileRepository;

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
        $repo = new CuentaContableProfileRepository();
        $cuenta = $repo->get($id);

        if (!$cuenta || $cuenta->profile_id !== $profileId) {
            return ['statusCode' => 404, 'body' => json_encode(['error' => 'Cuenta no encontrada'])];
        }

        return [
            'statusCode' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($cuenta->toArray())
        ];
    } catch (\Throwable $e) {
        return ['statusCode' => 500, 'body' => json_encode(['error' => $e->getMessage()])];
    }
};
