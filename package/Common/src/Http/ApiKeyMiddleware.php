<?php

namespace App\Common\Http;

class ApiKeyMiddleware
{
    /**
     * Valida el header X-Api-Key contra el secreto compartido configurado en
     * YUPANA_API_KEY. Son llamadas backend-a-backend (ej. caja-registradora
     * generando el asiento de una venta al autorizar una factura), por eso no
     * usan el authorizer de Cognito. Mismo patrón que caja-registradora,
     * apotheca y notifier.
     *
     * @return array|null Respuesta 401 si falta o no coincide, o null si está permitido.
     */
    public static function check(array $event): ?array
    {
        $headers  = array_change_key_case($event['headers'] ?? [], CASE_LOWER);
        $apiKey   = $headers['x-api-key'] ?? '';
        $expected = $_ENV['YUPANA_API_KEY'] ?? '';

        if (empty($expected) || empty($apiKey) || !hash_equals($expected, $apiKey)) {
            return ['statusCode' => 401, 'body' => json_encode(['error' => 'API key inválida o faltante'])];
        }

        return null;
    }
}
