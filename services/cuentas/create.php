<?php
require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../vendor/autoload.php';

use App\Common\Repositories\CuentaContableRepository;
use Mauloasan\BobConstruye\DynamoDB\Enums\AccountTypes;
use Mauloasan\BobConstruye\DynamoDB\Enums\AccountNature;

return function (array $event) {
    $body = json_decode($event['body'] ?? '', true);

    $requiredFields = ['codigo', 'nombre', 'tipo', 'naturaleza', 'tipo_estado'];
    foreach ($requiredFields as $field) {
        if (!isset($body[$field])) {
            return ['statusCode' => 400, 'body' => json_encode(['error' => "Missing required field: $field"])];
        }
    }

    if (AccountTypes::tryFrom($body['tipo']) === null) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Invalid tipo'])];
    }
    if (AccountNature::tryFrom($body['naturaleza']) === null) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Invalid naturaleza'])];
    }

    try {
        $repo = new CuentaContableRepository();
        $cuenta = $repo->create($body);

        return [
            'statusCode' => 201,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($cuenta->toArray())
        ];
    } catch (\Throwable $e) {
        return ['statusCode' => 500, 'body' => json_encode(['error' => $e->getMessage()])];
    }
};
