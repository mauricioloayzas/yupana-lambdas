<?php
require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../vendor/autoload.php';

use App\Common\Repositories\CuentaContableRepository;

return function (array $event) {
    try {
        $repo = new CuentaContableRepository();
        $cuentas = array_map(fn($c) => $c->toArray(), $repo->getAll());

        return [
            'statusCode' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($cuentas)
        ];
    } catch (\Throwable $e) {
        return ['statusCode' => 500, 'body' => json_encode(['error' => $e->getMessage()])];
    }
};
