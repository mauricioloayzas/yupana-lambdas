<?php
require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../package/autoload.php';

use App\Common\Http\ProfileAccessMiddleware;
use App\Common\Services\EstadoFlujoEfectivoService;
use App\Common\Services\EstadoFlujoEfectivoPdfService;

return function (array $event) {
    $profileId = $event['pathParameters']['profileId'] ?? null;
    if (empty($profileId)) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Missing required profileId'])];
    }

    if ($deny = ProfileAccessMiddleware::check($event, $profileId)) {
        return $deny;
    }

    $params = $event['queryStringParameters'] ?? [];
    $anio = $params['anio'] ?? date('Y');
    $mesDesde = $params['mes_desde'] ?? null;
    $mesHasta = $params['mes_hasta'] ?? null;
    $formato = $params['formato'] ?? 'json';

    if (!in_array($formato, ['json', 'pdf'], true)) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'formato debe ser json o pdf'])];
    }

    try {
        $resultado = (new EstadoFlujoEfectivoService())->generar($profileId, $anio, $mesDesde, $mesHasta);

        if ($formato === 'json') {
            return [
                'statusCode' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode(['data' => $resultado]),
            ];
        }

        $contenido = (new EstadoFlujoEfectivoPdfService())->generar($resultado, $profileId);

        return [
            'statusCode' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['data' => [
                'formato' => 'pdf',
                'filename' => "flujo-efectivo-{$anio}.pdf",
                'mime_type' => 'application/pdf',
                'contenido_base64' => base64_encode($contenido),
                'resumen' => [
                    'efectivo_inicial' => $resultado['efectivo_inicial'],
                    'movimiento_neto' => $resultado['movimiento_neto'],
                    'efectivo_final' => $resultado['efectivo_final'],
                    'cuadra' => $resultado['cuadra'],
                ],
            ]]),
        ];
    } catch (\Throwable $e) {
        return ['statusCode' => 500, 'body' => json_encode(['error' => $e->getMessage()])];
    }
};
