<?php
require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../vendor/autoload.php';

use Aws\Exception\AwsException;

return function ($event) {
    $region = $_ENV['AWS_REGION'] ?? 'us-east-1';
    $tableName = $_ENV['DYNAMODB_TABLE_CUENTAS'] ?? '';

    try {
        $client = new \Aws\DynamoDb\DynamoDbClient(['region' => $region, 'version' => 'latest']);
        $result = $client->describeTable(['TableName' => $tableName]);

        return [
            'statusCode' => 200,
            'body' => json_encode([
                'status' => '✅ Conexión Exitosa',
                'table' => $tableName,
                'table_status' => $result['Table']['TableStatus'],
            ])
        ];
    } catch (AwsException $e) {
        return [
            'statusCode' => 500,
            'body' => json_encode(['status' => '❌ Error de Conexión', 'error' => $e->getAwsErrorMessage()])
        ];
    }
};
