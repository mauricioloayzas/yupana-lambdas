<?php
require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../package/autoload.php';

use App\Common\Http\ProfileAccessMiddleware;
use App\Common\Services\EstadoResultadosService;
use App\Common\Services\EstadoResultadosPdfService;

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
        $resultado = (new EstadoResultadosService())->generar($profileId, $anio, $mesDesde, $mesHasta);

        if ($formato === 'json') {
            return [
                'statusCode' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode(['data' => $resultado]),
            ];
        }

        $contenido = (new EstadoResultadosPdfService())->generar($resultado, $profileId);

        return [
            'statusCode' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['data' => [
                'formato' => 'pdf',
                'filename' => "estado-resultados-{$anio}.pdf",
                'mime_type' => 'application/pdf',
                'contenido_base64' => base64_encode($contenido),
                'resumen' => [
                    'ganancia_neta_periodo' => $resultado['ganancia_neta_periodo'],
                    'resultado_integral_total' => $resultado['resultado_integral_total'],
                ],
            ]]),
        ];
    } catch (\Throwable $e) {
        return ['statusCode' => 500, 'body' => json_encode(['error' => $e->getMessage()])];
    }
};
