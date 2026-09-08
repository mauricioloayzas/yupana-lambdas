<?php
require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../../vendor/autoload.php';

use App\Common\Http\ProfileAccessMiddleware;
use App\Common\Services\CuentaContableInitService;

return function (array $event) {
    $profileId = $event['pathParameters']['profileId'] ?? null;
    if (empty($profileId)) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Missing required profileId'])];
    }

    if ($deny = ProfileAccessMiddleware::check($event, $profileId)) {
        return $deny;
    }

    try {
        $result = (new CuentaContableInitService())->init($profileId);

        return [
            'statusCode' => $result['already_initialized'] ? 200 : 201,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($result)
        ];
    } catch (\Throwable $e) {
        return ['statusCode' => 500, 'body' => json_encode(['error' => $e->getMessage()])];
    }
};
