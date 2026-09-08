<?php

namespace App\Common\Repositories;

use Mauloasan\BobConstruye\DynamoDB\DynamoDbClientFactory;
use Mauloasan\BobConstruye\DynamoDB\Entities\Yupana\AsientoContableEntity;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Ramsey\Uuid\Uuid;

class AsientoContableRepository
{
    private DynamoDbClient $dbClient;
    private Marshaler $marshaler;
    private string $tableName;

    public function __construct()
    {
        $this->dbClient = DynamoDbClientFactory::create();
        $this->marshaler = new Marshaler();
        $this->tableName = $_ENV['DYNAMODB_TABLE_ASIENTOS'];
    }

    public function create(array $data, string $profileId): AsientoContableEntity
    {
        $id = Uuid::uuid4()->toString();

        $item = [
            'id'          => $id,
            'profile_id'  => $profileId,
            'fecha'       => $data['fecha'],
            'descripcion' => $data['descripcion'],
            'origen'      => $data['origen'] ?? 'manual',
            'created_at'  => date('c'),
            'updated_at'  => null,
        ];

        $this->dbClient->putItem([
            'TableName' => $this->tableName,
            'Item'      => $this->marshaler->marshalItem($item),
        ]);

        return AsientoContableEntity::fromArray($item);
    }

    public function get(string $id): ?AsientoContableEntity
    {
        $result = $this->dbClient->getItem([
            'TableName' => $this->tableName,
            'Key'       => $this->marshaler->marshalItem(['id' => $id]),
        ]);

        if (empty($result['Item'])) {
            return null;
        }

        return AsientoContableEntity::fromArray($this->marshaler->unmarshalItem($result['Item']));
    }

    /**
     * @return AsientoContableEntity[]
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
            'ScanIndexForward' => false,
        ]);

        $asientos = [];
        foreach ($result['Items'] ?? [] as $item) {
            $asientos[] = AsientoContableEntity::fromArray($this->marshaler->unmarshalItem($item));
        }

        return $asientos;
    }

    public function update(string $id, array $data): ?AsientoContableEntity
    {
        unset($data['id'], $data['profile_id']);

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

    public function delete(string $id): bool
    {
        $this->dbClient->deleteItem([
            'TableName' => $this->tableName,
            'Key'       => $this->marshaler->marshalItem(['id' => $id]),
        ]);

        return true;
    }
}
