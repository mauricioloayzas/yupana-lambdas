<?php
require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../../vendor/autoload.php';

use App\Common\Http\ProfileAccessMiddleware;
use App\Common\Repositories\AsientoContableRepository;

return function (array $event) {
    $profileId = $event['pathParameters']['profileId'] ?? null;
    if (empty($profileId)) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Missing required profileId'])];
    }

    if ($deny = ProfileAccessMiddleware::check($event, $profileId)) {
        return $deny;
    }

    try {
        $repo = new AsientoContableRepository();
        $asientos = array_map(fn($a) => $a->toArray(), $repo->getByProfileId($profileId));

        return [
            'statusCode' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['data' => $asientos])
        ];
    } catch (\Throwable $e) {
        return ['statusCode' => 500, 'body' => json_encode(['error' => $e->getMessage()])];
    }
};
