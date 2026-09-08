<?php
require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../vendor/autoload.php';

use App\Common\Services\MayorContableService;

return function (array $event) {
    $service = new MayorContableService();

    // Invocación HTTP directa (uso manual/pruebas)
    if (isset($event['body'])) {
        $data = json_decode($event['body'] ?? '', true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['statusCode' => 400, 'body' => json_encode(['error' => 'Invalid JSON body'])];
        }

        $requiredFields = ['cuenta_id', 'debe', 'haber', 'anio', 'mes'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                return ['statusCode' => 400, 'body' => json_encode(['error' => "Missing required field: $field"])];
            }
        }

        try {
            return [
                'statusCode' => 201,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode($service->process($data))
            ];
        } catch (\Throwable $e) {
            return ['statusCode' => 500, 'body' => json_encode(['error' => $e->getMessage()])];
        }
    }

    // Invocación vía SQS (el flujo real: AsientoContableService encola una
    // línea por cada movimiento de un asiento)
    if (isset($event['Records'])) {
        foreach ($event['Records'] as $record) {
            $data = json_decode($record['body'], true);

            $requiredFields = ['cuenta_id', 'debe', 'haber', 'anio', 'mes'];
            $faltaAlgo = false;
            foreach ($requiredFields as $field) {
                if (!isset($data[$field])) {
                    $faltaAlgo = true;
                }
            }
            if ($faltaAlgo) {
                continue;
            }

            try {
                $service->process($data);
            } catch (\Throwable $e) {
                error_log('Error mayorizando: ' . $e->getMessage());
            }
        }

        return ['statusCode' => 200, 'body' => ''];
    }

    return ['statusCode' => 400, 'body' => json_encode(['error' => 'Unrecognized event'])];
};
