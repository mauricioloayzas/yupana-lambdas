<?php

namespace App\Common\Repositories;

use Mauloasan\BobConstruye\DynamoDB\DynamoDbClientFactory;
use Mauloasan\BobConstruye\DynamoDB\Entities\Orchestrator\ProfileEntity;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;

/**
 * Lectura del perfil compartido del orquestador (misma tabla que usan
 * caja-registradora/apotheca/professionalis). Yupana solo lee: la administración
 * del perfil vive en orchestrator/backend.
 */
class ProfileRepository
{
    private DynamoDbClient $dbClient;
    private Marshaler $marshaler;
    private string $tableName;

    public function __construct()
    {
        $this->dbClient = DynamoDbClientFactory::create();
        $this->marshaler = new Marshaler();
        $this->tableName = $_ENV['DYNAMODB_TABLE_PROFILES'];
    }

    public function get(string $id): ?ProfileEntity
    {
        $result = $this->dbClient->getItem([
            'TableName' => $this->tableName,
            'Key'       => $this->marshaler->marshalItem(['id' => $id]),
        ]);

        if (empty($result['Item'])) {
            return null;
        }

        return ProfileEntity::fromArray($this->marshaler->unmarshalItem($result['Item']));
    }
}
