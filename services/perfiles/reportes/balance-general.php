<?php
require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../package/autoload.php';

use App\Common\Http\ProfileAccessMiddleware;
use App\Common\Services\BalanceGeneralService;
use App\Common\Services\BalanceGeneralPdfService;

return function (array $event) {
    $profileId = $event['pathParameters']['profileId'] ?? null;
    if (empty($profileId)) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Missing required profileId'])];
    }

    if ($deny = ProfileAccessMiddleware::check($event, $profileId)) {
        return $deny;
    }

    $params = $event['queryStringParameters'] ?? [];
    $fecha = $params['fecha'] ?? date('Y-m-d');
    $formato = $params['formato'] ?? 'json';

    if (!in_array($formato, ['json', 'pdf'], true)) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'formato debe ser json o pdf'])];
    }

    try {
        $resultado = (new BalanceGeneralService())->generar($profileId, $fecha);

        if ($formato === 'json') {
            return [
                'statusCode' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode(['data' => $resultado]),
            ];
        }

        $contenido = (new BalanceGeneralPdfService())->generar($resultado, $profileId);

        return [
            'statusCode' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['data' => [
                'formato' => 'pdf',
                'filename' => "balance-general-{$fecha}.pdf",
                'mime_type' => 'application/pdf',
                'contenido_base64' => base64_encode($contenido),
                'resumen' => [
                    'total_activo' => $resultado['total_activo'],
                    'total_pasivo' => $resultado['total_pasivo'],
                    'total_patrimonio' => $resultado['total_patrimonio'],
                    'cuadra' => $resultado['cuadra'],
                ],
            ]]),
        ];
    } catch (\Throwable $e) {
        return ['statusCode' => 500, 'body' => json_encode(['error' => $e->getMessage()])];
    }
};
