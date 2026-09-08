<?php

namespace App\Common\Repositories;

use Mauloasan\BobConstruye\DynamoDB\DynamoDbClientFactory;
use Mauloasan\BobConstruye\DynamoDB\Entities\Yupana\CuentaContableEntity;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Ramsey\Uuid\Uuid;

/**
 * Catálogo maestro NIIF (sin profile_id). Se siembra una sola vez vía
 * commands/cuentaCommand.php y se lee completo (scan) al clonar hacia una empresa.
 */
class CuentaContableRepository
{
    private DynamoDbClient $dbClient;
    private Marshaler $marshaler;
    private string $tableName;

    public function __construct()
    {
        $this->dbClient = DynamoDbClientFactory::create();
        $this->marshaler = new Marshaler();
        $this->tableName = $_ENV['DYNAMODB_TABLE_CUENTAS'];
    }

    public function create(array $data): CuentaContableEntity
    {
        $id = Uuid::uuid4()->toString();

        $item = [
            'id'          => $id,
            'codigo'      => $data['codigo'],
            'nombre'      => $data['nombre'],
            'tipo'        => $data['tipo'],
            'naturaleza'  => $data['naturaleza'],
            'tipo_estado' => (int)$data['tipo_estado'],
            'es_detalle'  => (bool)($data['es_detalle'] ?? false),
            'descripcion' => $data['descripcion'] ?? '',
            'created_at'  => date('c'),
            'updated_at'  => null,
        ];

        $this->dbClient->putItem([
            'TableName' => $this->tableName,
            'Item'      => $this->marshaler->marshalItem($item),
        ]);

        return CuentaContableEntity::fromArray($item);
    }

    public function get(string $id): ?CuentaContableEntity
    {
        $result = $this->dbClient->getItem([
            'TableName' => $this->tableName,
            'Key'       => $this->marshaler->marshalItem(['id' => $id]),
        ]);

        if (empty($result['Item'])) {
            return null;
        }

        return CuentaContableEntity::fromArray($this->marshaler->unmarshalItem($result['Item']));
    }

    /**
     * @return CuentaContableEntity[]
     */
    public function getAll(): array
    {
        $cuentas = [];
        $params = ['TableName' => $this->tableName];

        do {
            $result = $this->dbClient->scan($params);
            foreach ($result['Items'] as $item) {
                $cuentas[] = CuentaContableEntity::fromArray($this->marshaler->unmarshalItem($item));
            }
            $params['ExclusiveStartKey'] = $result['LastEvaluatedKey'] ?? null;
        } while (!empty($params['ExclusiveStartKey']));

        return $cuentas;
    }
}
