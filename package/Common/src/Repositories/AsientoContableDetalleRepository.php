<?php

namespace App\Common\Repositories;

use Mauloasan\BobConstruye\DynamoDB\DynamoDbClientFactory;
use Mauloasan\BobConstruye\DynamoDB\Entities\Yupana\AsientoContableDetalleEntity;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Ramsey\Uuid\Uuid;

class AsientoContableDetalleRepository
{
    private DynamoDbClient $dbClient;
    private Marshaler $marshaler;
    private string $tableName;

    public function __construct()
    {
        $this->dbClient = DynamoDbClientFactory::create();
        $this->marshaler = new Marshaler();
        $this->tableName = $_ENV['DYNAMODB_TABLE_ASIENTOS_DETALLES'];
    }

    public function create(string $asientoId, string $cuentaId, float $debe, float $haber): AsientoContableDetalleEntity
    {
        $id = Uuid::uuid4()->toString();

        $item = [
            'id'         => $id,
            'asiento_id' => $asientoId,
            'cuenta_id'  => $cuentaId,
            'debe'       => $debe,
            'haber'      => $haber,
            'created_at' => date('c'),
            'updated_at' => null,
        ];

        $this->dbClient->putItem([
            'TableName' => $this->tableName,
            'Item'      => $this->marshaler->marshalItem($item),
        ]);

        return AsientoContableDetalleEntity::fromArray($item);
    }

    /**
     * @return AsientoContableDetalleEntity[]
     */
    public function getByAsientoId(string $asientoId): array
    {
        $result = $this->dbClient->query([
            'TableName'                 => $this->tableName,
            'IndexName'                 => 'asiento_id-index',
            'KeyConditionExpression'    => 'asiento_id = :asiento_id',
            'ExpressionAttributeValues' => $this->marshaler->marshalItem([
                ':asiento_id' => $asientoId,
            ]),
        ]);

        $detalles = [];
        foreach ($result['Items'] ?? [] as $item) {
            $detalles[] = AsientoContableDetalleEntity::fromArray($this->marshaler->unmarshalItem($item));
        }

        return $detalles;
    }

    public function deleteByAsientoId(string $asientoId): void
    {
        foreach ($this->getByAsientoId($asientoId) as $detalle) {
            $this->dbClient->deleteItem([
                'TableName' => $this->tableName,
                'Key'       => $this->marshaler->marshalItem(['id' => $detalle->id]),
            ]);
        }
    }
}
