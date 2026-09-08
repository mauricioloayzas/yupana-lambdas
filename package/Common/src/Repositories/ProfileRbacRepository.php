<?php

namespace App\Common\Repositories;

use Mauloasan\BobConstruye\DynamoDB\DynamoDbClientFactory;
use Mauloasan\BobConstruye\DynamoDB\Entities\Orchestrator\ProfileRbacEntity;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;

class ProfileRbacRepository
{
    private DynamoDbClient $dbClient;
    private Marshaler $marshaler;
    private string $tableName;

    public function __construct()
    {
        $this->dbClient = DynamoDbClientFactory::create();
        $this->marshaler = new Marshaler();
        $this->tableName = $_ENV['DYNAMODB_TABLE_RBACS'];
    }

    public function getProfileRbacsByUserId(string $userId): ?array
    {
        $result = $this->dbClient->query([
            'TableName'                 => $this->tableName,
            'IndexName'                 => 'user_id-index',
            'KeyConditionExpression'    => 'user_id = :user_id',
            'ExpressionAttributeValues' => $this->marshaler->marshalItem([
                ':user_id' => $userId,
            ]),
        ]);

        if (empty($result['Items'])) {
            return null;
        }

        $rbacs = [];
        foreach ($result['Items'] as $item) {
            $rbacs[] = ProfileRbacEntity::fromArray($this->marshaler->unmarshalItem($item));
        }

        return $rbacs;
    }
}
