<?php
require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../../vendor/autoload.php';

use App\Common\Http\ProfileAccessMiddleware;
use App\Common\Repositories\CuentaContableProfileRepository;
use Mauloasan\BobConstruye\DynamoDB\Enums\AccountStatus;

return function (array $event) {
    $profileId = $event['pathParameters']['profileId'] ?? null;
    $id = $event['pathParameters']['id'] ?? null;
    $body = json_decode($event['body'] ?? '', true);
    $status = $body['status'] ?? null;

    if (empty($profileId) || empty($id)) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Missing required profileId/id'])];
    }
    if (!$status || AccountStatus::tryFrom($status) === null) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Missing/invalid status'])];
    }

    if ($deny = ProfileAccessMiddleware::check($event, $profileId)) {
        return $deny;
    }

    $repo = new CuentaContableProfileRepository();
    $cuenta = $repo->get($id);
    if (!$cuenta || $cuenta->profile_id !== $profileId) {
        return ['statusCode' => 404, 'body' => json_encode(['error' => 'Cuenta no encontrada'])];
    }

    try {
        $actualizada = $repo->update($id, ['status' => $status]);

        return [
            'statusCode' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['data' => $actualizada->toArray()])
        ];
    } catch (\Throwable $e) {
        return ['statusCode' => 500, 'body' => json_encode(['error' => $e->getMessage()])];
    }
};
