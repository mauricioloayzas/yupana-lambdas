<?php

namespace App\Common\Repositories;

use Mauloasan\BobConstruye\DynamoDB\DynamoDbClientFactory;
use Mauloasan\BobConstruye\DynamoDB\Entities\Yupana\CuentaContableProfileEntity;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Ramsey\Uuid\Uuid;

class CuentaContableProfileRepository
{
    private DynamoDbClient $dbClient;
    private Marshaler $marshaler;
    private string $tableName;

    public function __construct()
    {
        $this->dbClient = DynamoDbClientFactory::create();
        $this->marshaler = new Marshaler();
        $this->tableName = $_ENV['DYNAMODB_TABLE_CUENTAS_PROFILES'];
    }

    public function create(array $data, string $profileId): CuentaContableProfileEntity
    {
        $id = Uuid::uuid4()->toString();

        $item = [
            'id'          => $id,
            'profile_id'  => $profileId,
            'codigo'      => $data['codigo'],
            'nombre'      => $data['nombre'],
            'tipo'        => $data['tipo'],
            'naturaleza'  => $data['naturaleza'],
            'tipo_estado' => (int)$data['tipo_estado'],
            'status'      => 'active',
            'es_detalle'  => (bool)($data['es_detalle'] ?? false),
            'saldo'       => 0.0,
            'descripcion' => $data['descripcion'] ?? '',
            'parent_id'   => $data['parent_id'] ?? null,
            'created_at'  => date('c'),
            'updated_at'  => null,
        ];

        $this->dbClient->putItem([
            'TableName' => $this->tableName,
            'Item'      => $this->marshaler->marshalItem($item),
        ]);

        return CuentaContableProfileEntity::fromArray($item);
    }

    public function get(string $id): ?CuentaContableProfileEntity
    {
        $result = $this->dbClient->getItem([
            'TableName' => $this->tableName,
            'Key'       => $this->marshaler->marshalItem(['id' => $id]),
        ]);

        if (empty($result['Item'])) {
            return null;
        }

        return CuentaContableProfileEntity::fromArray($this->marshaler->unmarshalItem($result['Item']));
    }

    /**
     * @return CuentaContableProfileEntity[]
     */
    public function getByProfileId(string $profileId): array
    {
        $result = $this->dbClient->query([
            'TableName'                 => $this->tableName,
            'IndexName'                 => 'profile_id-index',
            'KeyConditionExpression'    => 'profile_id = :profile_id',
            'ExpressionAttributeValues' => $this->marshaler->marshalItem([
                ':profile_id' => $profileId,
            ]),
            'ScanIndexForward' => true,
        ]);

        $cuentas = [];
        foreach ($result['Items'] ?? [] as $item) {
            $cuentas[] = CuentaContableProfileEntity::fromArray($this->marshaler->unmarshalItem($item));
        }

        return $cuentas;
    }

    public function getByProfileIdAndCodigo(string $profileId, string $codigo): ?CuentaContableProfileEntity
    {
        $result = $this->dbClient->query([
            'TableName'                 => $this->tableName,
            'IndexName'                 => 'profile_id-index',
            'KeyConditionExpression'    => 'profile_id = :profile_id AND codigo = :codigo',
            'ExpressionAttributeValues' => $this->marshaler->marshalItem([
                ':profile_id' => $profileId,
                ':codigo'     => $codigo,
            ]),
        ]);

        if (empty($result['Items'])) {
            return null;
        }

        return CuentaContableProfileEntity::fromArray($this->marshaler->unmarshalItem(reset($result['Items'])));
    }

    /**
     * Incrementa (o decrementa, con delta negativo) el saldo "en caliente" de
     * una cuenta de forma atómica (DynamoDB ADD) — a diferencia de leer el
     * saldo actual y sobreescribirlo con uno nuevo calculado en PHP, esto no
     * tiene condición de carrera si dos posteos a la misma cuenta llegan casi
     * al mismo tiempo (ver MayorContableService, que es quien la usa).
     */
    public function incrementarSaldo(string $id, float $delta): void
    {
        $this->dbClient->updateItem([
            'TableName'                 => $this->tableName,
            'Key'                       => $this->marshaler->marshalItem(['id' => $id]),
            'UpdateExpression'          => 'ADD saldo :delta SET updated_at = :updated_at',
            'ExpressionAttributeValues' => $this->marshaler->marshalItem([
                ':delta'      => $delta,
                ':updated_at' => date('c'),
            ]),
        ]);
    }

    public function update(string $id, array $data): ?CuentaContableProfileEntity
    {
        unset($data['id'], $data['profile_id'], $data['codigo']);

        $updateExpression = 'SET ';
        $expressionAttributeValues = [];
        $expressionAttributeNames = [];

        foreach ($data as $key => $value) {
            $placeholderName = '#' . $key;
            $placeholderValue = ':' . $key;
            $updateExpression .= $placeholderName . ' = ' . $placeholderValue . ', ';
            $expressionAttributeNames[$placeholderName] = $key;
            $expressionAttributeValues[$placeholderValue] = $value;
        }

        $updateExpression .= '#updated_at = :updated_at';
        $expressionAttributeNames['#updated_at'] = 'updated_at';
        $expressionAttributeValues[':updated_at'] = date('c');

        $this->dbClient->updateItem([
            'TableName'                 => $this->tableName,
            'Key'                       => $this->marshaler->marshalItem(['id' => $id]),
            'UpdateExpression'          => $updateExpression,
            'ExpressionAttributeNames'  => $expressionAttributeNames,
            'ExpressionAttributeValues' => $this->marshaler->marshalItem($expressionAttributeValues),
            'ReturnValues'              => 'ALL_NEW',
        ]);

        return $this->get($id);
    }
}
