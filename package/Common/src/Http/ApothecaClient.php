<?php

namespace App\Common\Http;

use GuzzleHttp\Client;

/**
 * Llamadas backend-a-backend hacia apotheca (X-Api-Key). Solo para el backfill
 * al activar Contabilidad — nunca lanza excepción: si apotheca está caído o mal
 * configurado, el backfill simplemente no incluye esa parte (no bloquea la
 * activación del módulo).
 */
class ApothecaClient
{
    private Client $http;
    private string $apiKey;
    private bool $enabled;

    public function __construct()
    {
        $base          = rtrim($_ENV['APOTHECA_API_BASE'] ?? '', '/');
        $this->apiKey  = $_ENV['APOTHECA_API_KEY'] ?? '';
        $this->enabled = $base !== '' && $this->apiKey !== '';
        $this->http    = new Client(['base_uri' => $base . '/', 'timeout' => 8]);
    }

    /** Valor total del inventario del perfil (Σ stock_quantity * average_cost), o null si no se pudo calcular. */
    public function valorInventario(string $profileId): ?float
    {
        if (!$this->enabled) {
            return null;
        }

        try {
            $response = $this->http->get("internal/profiles/{$profileId}/inventory-value", [
                'headers' => ['X-Api-Key' => $this->apiKey],
            ]);
            $body = json_decode((string)$response->getBody(), true);
            return isset($body['data']['valor']) ? (float)$body['data']['valor'] : null;
        } catch (\Throwable $e) {
            error_log('ApothecaClient::valorInventario error: ' . $e->getMessage());
            return null;
        }
    }
}
