<?php

namespace App\Common\Http;

use GuzzleHttp\Client;

/**
 * Llamadas backend-a-backend hacia caja-registradora (X-Api-Key). Solo para el
 * backfill al activar Contabilidad — nunca lanza excepción: si caja-registradora
 * está caído o mal configurado, el backfill simplemente no incluye esa parte.
 */
class CajaRegistradoraClient
{
    private Client $http;
    private string $apiKey;
    private bool $enabled;

    public function __construct()
    {
        $base          = rtrim($_ENV['CAJA_REGISTRADORA_API_BASE'] ?? '', '/');
        $this->apiKey  = $_ENV['CAJA_REGISTRADORA_API_KEY'] ?? '';
        $this->enabled = $base !== '' && $this->apiKey !== '';
        $this->http    = new Client(['base_uri' => $base . '/', 'timeout' => 8]);
    }

    /**
     * Ventas ya autorizadas del perfil hasta hoy: totalSinImpuestos + totalImpuesto,
     * o null si no se pudo calcular.
     *
     * @return array{sin_impuestos: float, impuesto: float}|null
     */
    public function ventasHastaHoy(string $profileId): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        try {
            $response = $this->http->get("internal/profiles/{$profileId}/revenue-to-date", [
                'headers' => ['X-Api-Key' => $this->apiKey],
            ]);
            $body = json_decode((string)$response->getBody(), true);
            if (!isset($body['data']['sin_impuestos'], $body['data']['impuesto'])) {
                return null;
            }
            return [
                'sin_impuestos' => (float)$body['data']['sin_impuestos'],
                'impuesto'      => (float)$body['data']['impuesto'],
            ];
        } catch (\Throwable $e) {
            error_log('CajaRegistradoraClient::ventasHastaHoy error: ' . $e->getMessage());
            return null;
        }
    }
}
