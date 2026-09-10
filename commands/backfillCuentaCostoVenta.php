<?php

declare(strict_types=1);

// Migración de una sola vez: crea la cuenta personalizada "Costo de Mercadería
// Vendida (sistema perpetuo)" bajo 5101 para los perfiles que ya habían
// activado Contabilidad ANTES de que CuentaContableInitService empezara a
// crearla sola. Sin esto, el asiento de una factura con línea de producto
// físico falla completo (revenue incluido) para esos perfiles, porque el
// código "510113" que arma AsientoFacturaBuilder (caja-registradora) no existe
// todavía.
//
// Uso:
//   AWS_PROFILE=mauloasan php commands/backfillCuentaCostoVenta.php --environment dev
//   AWS_PROFILE=mauloasan php commands/backfillCuentaCostoVenta.php --environment prod
//
// Idempotente: si el perfil ya tiene un hijo personalizado bajo 5101, no crea otro.

require __DIR__ . '/../vendor/autoload.php';

$loader = require __DIR__ . '/../vendor/autoload.php';
$loader->addPsr4('App\\Common\\', [__DIR__ . '/../package/Common/src/']);

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use App\Common\Services\CuentaContablePersonalizadaService;

$options = getopt('', ['environment:', 'prefix::']);
$env = $options['environment'] ?? null;
$prefix = $options['prefix'] ?? 'yupana';

if (!$env) {
    echo "Uso: php commands/backfillCuentaCostoVenta.php --environment dev|prod [--prefix yupana]\n";
    exit(1);
}

$tableName = "{$env}_{$prefix}_cuentas_profiles";
$region = getenv('AWS_REGION') ?: 'us-east-1';

echo "Tabla: $tableName ($region)\n";

$client = new DynamoDbClient(['region' => $region, 'version' => 'latest']);
$marshaler = new Marshaler();

// Trae todo y agrupa por perfil (misma lógica que backfillParentIds.php).
$items = [];
$params = ['TableName' => $tableName];
do {
    $result = $client->scan($params);
    foreach ($result['Items'] as $item) {
        $items[] = $marshaler->unmarshalItem($item);
    }
    $params['ExclusiveStartKey'] = $result['LastEvaluatedKey'] ?? null;
} while (!empty($params['ExclusiveStartKey']));

$porPerfil = [];
foreach ($items as $item) {
    $porPerfil[$item['profile_id']][] = $item;
}

$creadas = 0;
$yaTenian = 0;

// El comando corre standalone (fuera de una Lambda): las variables de entorno
// que leen los repositorios (DYNAMODB_TABLE_CUENTAS_PROFILES) hay que armarlas
// a mano acá, ya que package/autoload.php espera /opt/vendor (solo existe en Lambda).
$_ENV['DYNAMODB_TABLE_CUENTAS_PROFILES'] = $tableName;
$_ENV['AWS_REGION'] = $region;

foreach ($porPerfil as $profileId => $cuentas) {
    $grupo5101 = null;
    $tieneCostoVenta = false;

    foreach ($cuentas as $c) {
        if ($c['codigo'] === '5101') {
            $grupo5101 = $c;
        }
        // OJO: 5101 ya tiene 12 hijos OFICIALES (510101-510112, el cálculo
        // periódico de cierre) desde que se clona el catálogo — "¿tiene algún
        // hijo?" siempre da true y nunca detectaría que falta crear la
        // personalizada. Hay que buscar el código exacto "510113".
        if ($c['codigo'] === '510113') {
            $tieneCostoVenta = true;
        }
    }

    if ($tieneCostoVenta) {
        $yaTenian++;
        continue;
    }

    if ($grupo5101 === null) {
        echo "Perfil $profileId: no tiene la cuenta 5101 (¿catálogo incompleto?), se omite.\n";
        continue;
    }

    try {
        (new CuentaContablePersonalizadaService())->crear(
            $profileId,
            $grupo5101['id'],
            'Costo de Mercadería Vendida (sistema perpetuo)',
            'Costo de venta calculado en tiempo real a partir del costo promedio de apotheca en cada venta — no es una de las subcuentas oficiales 510101-510112 (esas son para el cálculo periódico de cierre).'
        );
        echo "Perfil $profileId: cuenta de costo de venta creada.\n";
        $creadas++;
    } catch (\Throwable $e) {
        echo "Perfil $profileId: ERROR creando la cuenta — " . $e->getMessage() . "\n";
    }
}

echo "\nListo. Creadas: $creadas | Ya tenían: $yaTenian\n";
