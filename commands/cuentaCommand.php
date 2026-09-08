<?php

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

// Uso:
//   php commands/cuentaCommand.php --file plan-cuentas-niif.csv --api https://xxxx.execute-api.us-east-1.amazonaws.com \
//       --auth-api https://yyyy.execute-api.us-east-1.amazonaws.com --email tu@correo.com --password '...'
//
// email/password también se pueden pasar por variables de entorno YUPANA_SEED_EMAIL /
// YUPANA_SEED_PASSWORD en vez de flags, para no dejarlas en el historial de la shell.
// A propósito NO se hardcodean credenciales en este archivo (a diferencia del
// accountCommand.php de personal-finances, del que este script viene adaptado).
$options = getopt('', ['file:', 'api:', 'auth-api:', 'email::', 'password::']);
$csvFile = $options['file'] ?? null;
$apiBase = rtrim($options['api'] ?? '', '/');
$authApiBase = rtrim($options['auth-api'] ?? $apiBase, '/');
$email = $options['email'] ?? getenv('YUPANA_SEED_EMAIL');
$password = $options['password'] ?? getenv('YUPANA_SEED_PASSWORD');

if (!$csvFile || !file_exists($csvFile) || !$apiBase || !$email || !$password) {
    echo "Uso: php commands/cuentaCommand.php --file plan-cuentas-niif.csv --api <url> [--auth-api <url>] --email <email> --password <password>\n";
    echo "(o exportar YUPANA_SEED_EMAIL / YUPANA_SEED_PASSWORD en vez de --email/--password)\n";
    exit(1);
}

$client = new Client();
$handle = fopen($csvFile, 'r');

if ($handle === false) {
    echo "Error opening file: $csvFile\n";
    exit(1);
}

// Salta la fila de cabecera
fgetcsv($handle, null, "\t");

$responseLogin = $client->post("$authApiBase/auth/login", [
    'json' => [
        'email'    => $email,
        'password' => $password,
    ],
    'headers' => ['Content-Type' => 'application/json'],
]);
$responseLogin = json_decode($responseLogin->getBody(), true);

$creadas = 0;
$fallidas = 0;

while (($data = fgetcsv($handle, null, "\t")) !== false) {
    try {
        $cuentaData = [
            'codigo'      => $data[0],
            'nombre'      => $data[1],
            'tipo'        => $data[2],
            'naturaleza'  => $data[3],
            'descripcion' => $data[4] ?? $data[1],
            'es_detalle'  => (bool)(int)$data[5],
            'tipo_estado' => (int)$data[6],
        ];

        $response = $client->post("$apiBase/cuentas", [
            'json'    => $cuentaData,
            'headers' => [
                'Content-Type'  => 'application/json',
                'authorization' => 'Bearer ' . $responseLogin['AccessToken'],
            ],
        ]);

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            $creada = json_decode($response->getBody(), true);
            echo "OK: {$creada['codigo']} - {$creada['nombre']}\n";
            $creadas++;
        } else {
            echo "FALLÓ fila: " . implode(',', $data) . ". Status: " . $response->getStatusCode() . "\n";
            $fallidas++;
        }
    } catch (RequestException $e) {
        $responseBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : 'Sin respuesta';
        echo "ERROR en fila: " . implode(',', $data) . " - " . $e->getMessage() . " | Respuesta: " . $responseBody . "\n";
        $fallidas++;
    }
}

fclose($handle);

echo "Listo: $creadas cuentas creadas, $fallidas fallidas.\n";
