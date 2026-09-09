<?php

declare(strict_types=1);

// Migración de una sola vez: calcula y guarda parent_id en las cuentas
// clonadas ANTES de que CuentaContableInitService empezara a calcularlo (ej.
// las 353 de un profile que ya activó el módulo). Sin esto, MayorContableService
// no encuentra ancestros a los que subir el saldo para esas cuentas — se
// queda posteando solo en la hoja.
//
// Uso:
//   AWS_PROFILE=mauloasan php commands/backfillParentIds.php --environment dev
//   AWS_PROFILE=mauloasan php commands/backfillParentIds.php --environment prod
//
// Idempotente: si una fila ya tiene parent_id, no la toca.

require __DIR__ . '/../vendor/autoload.php';

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;

$options = getopt('', ['environment:', 'prefix::']);
$env = $options['environment'] ?? null;
$prefix = $options['prefix'] ?? 'yupana';

if (!$env) {
    echo "Uso: php commands/backfillParentIds.php --environment dev|prod [--prefix yupana]\n";
    exit(1);
}

$tableName = "{$env}_{$prefix}_cuentas_profiles";
$region = getenv('AWS_REGION') ?: 'us-east-1';

echo "Tabla: $tableName ($region)\n";

$client = new DynamoDbClient(['region' => $region, 'version' => 'latest']);
$marshaler = new Marshaler();

// 1. Traer todo (la tabla es chica: pocos cientos de filas por empresa).
$items = [];
$params = ['TableName' => $tableName];
do {
    $result = $client->scan($params);
    foreach ($result['Items'] as $item) {
        $items[] = $marshaler->unmarshalItem($item);
    }
    $params['ExclusiveStartKey'] = $result['LastEvaluatedKey'] ?? null;
} while (!empty($params['ExclusiveStartKey']));

echo 'Filas leídas: ' . count($items) . "\n";

// 2. Agrupar por profile_id — el parent_id solo tiene sentido dentro del
// catálogo de la MISMA empresa.
$porPerfil = [];
foreach ($items as $item) {
    $porPerfil[$item['profile_id']][] = $item;
}

$actualizadas = 0;
$yaTenian = 0;
$sinPadre = 0;

foreach ($porPerfil as $profileId => $cuentas) {
    // Mapa codigo => id, para resolver el padre por prefijo más largo.
    $idPorCodigo = [];
    foreach ($cuentas as $c) {
        $idPorCodigo[$c['codigo']] = $c['id'];
    }

    foreach ($cuentas as $c) {
        if (!empty($c['parent_id'])) {
            $yaTenian++;
            continue;
        }

        $parentId = null;
        $codigo = $c['codigo'];
        for ($longitud = strlen($codigo) - 1; $longitud >= 1; $longitud--) {
            $prefijo = substr($codigo, 0, $longitud);
            if (isset($idPorCodigo[$prefijo])) {
                $parentId = $idPorCodigo[$prefijo];
                break;
            }
        }

        if ($parentId === null) {
            // Cuenta raíz real (1, 2, 3, 41, 43, 51, 52...) — no es un error.
            $sinPadre++;
            continue;
        }

        $client->updateItem([
            'TableName'                 => $tableName,
            'Key'                       => $marshaler->marshalItem(['id' => $c['id']]),
            'UpdateExpression'          => 'SET parent_id = :parent_id, updated_at = :now',
            'ExpressionAttributeValues' => $marshaler->marshalItem([
                ':parent_id' => $parentId,
                ':now'       => date('c'),
            ]),
        ]);
        $actualizadas++;
    }

    echo "Perfil $profileId: " . count($cuentas) . " cuentas procesadas\n";
}

echo "\nListo. Actualizadas: $actualizadas | Ya tenían parent_id: $yaTenian | Raíces sin padre: $sinPadre\n";
