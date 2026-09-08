<?php

namespace App\Common\Repositories;

use Mauloasan\BobConstruye\DynamoDB\DynamoDbClientFactory;
use Mauloasan\BobConstruye\DynamoDB\Entities\Yupana\MayorContableEntity;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Ramsey\Uuid\Uuid;

class MayorContableRepository
{
    private DynamoDbClient $dbClient;
    private Marshaler $marshaler;
    private string $tableName;

    public function __construct()
    {
        $this->dbClient = DynamoDbClientFactory::create();
        $this->marshaler = new Marshaler();
        $this->tableName = $_ENV['DYNAMODB_TABLE_MAYOR'];
    }

    public function create(array $data): MayorContableEntity
    {
        $id = Uuid::uuid4()->toString();
        $now = date('c');

        $item = [
            'id'         => $id,
            'cuenta_id'  => $data['cuenta_id'],
            'debe'       => (float)$data['debe'],
            'haber'      => (float)$data['haber'],
            'saldo'      => (float)$data['saldo'],
            'anio'       => $data['anio'],
            'mes'        => $data['mes'],
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $this->dbClient->putItem([
            'TableName' => $this->tableName,
            'Item'      => $this->marshaler->marshalItem($item),
        ]);

        return MayorContableEntity::fromArray($item);
    }

    public function update(string $id, array $data): ?MayorContableEntity
    {
        $data['updated_at'] = date('c');
        unset($data['id'], $data['cuenta_id']);

        $updateExpression = 'SET ';
        $expressionAttributeValues = [];
        $expressionAttributeNames = [];

        foreach ($data as $key => $value) {
            $updateExpression .= "#$key = :$key, ";
            $expressionAttributeNames["#$key"] = $key;
            $expressionAttributeValues[":$key"] = $value;
        }
        $updateExpression = rtrim($updateExpression, ', ');

        $this->dbClient->updateItem([
            'TableName'                 => $this->tableName,
            'Key'                       => $this->marshaler->marshalItem(['id' => $id]),
            'UpdateExpression'          => $updateExpression,
            'ExpressionAttributeNames'  => $expressionAttributeNames,
            'ExpressionAttributeValues' => $this->marshaler->marshalItem($expressionAttributeValues),
            'ReturnValues'              => 'ALL_NEW',
        ]);

        $result = $this->dbClient->getItem([
            'TableName' => $this->tableName,
            'Key'       => $this->marshaler->marshalItem(['id' => $id]),
        ]);

        return empty($result['Item']) ? null : MayorContableEntity::fromArray($this->marshaler->unmarshalItem($result['Item']));
    }

    /**
     * @return MayorContableEntity[]
     */
    public function findAllByCuentaId(string $cuentaId): array
    {
        $result = $this->dbClient->query([
            'TableName'                 => $this->tableName,
            'IndexName'                 => 'cuenta_id-index',
            'KeyConditionExpression'    => 'cuenta_id = :cuenta_id',
            'ExpressionAttributeValues' => $this->marshaler->marshalItem([
                ':cuenta_id' => $cuentaId,
            ]),
        ]);

        $movimientos = [];
        foreach ($result['Items'] ?? [] as $item) {
            $movimientos[] = MayorContableEntity::fromArray($this->marshaler->unmarshalItem($item));
        }

        return $movimientos;
    }

    public function findByCuentaIdAnioMes(string $cuentaId, string $anio, string $mes): ?MayorContableEntity
    {
        $result = $this->dbClient->query([
            'TableName'                 => $this->tableName,
            'IndexName'                 => 'cuenta_id-index',
            'KeyConditionExpression'    => 'cuenta_id = :cuenta_id',
            'FilterExpression'          => '#an = :anio AND #me = :mes',
            'ExpressionAttributeNames'  => ['#an' => 'anio', '#me' => 'mes'],
            'ExpressionAttributeValues' => $this->marshaler->marshalItem([
                ':cuenta_id' => $cuentaId,
                ':anio'      => $anio,
                ':mes'       => str_pad($mes, 2, '0', STR_PAD_LEFT),
            ]),
        ]);

        if (empty($result['Items'])) {
            return null;
        }

        return MayorContableEntity::fromArray($this->marshaler->unmarshalItem($result['Items'][0]));
    }
}
