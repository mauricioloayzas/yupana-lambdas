<?php

namespace App\Common\Repositories;

use Mauloasan\BobConstruye\DynamoDB\DynamoDbClientFactory;
use Mauloasan\BobConstruye\DynamoDB\Entities\Yupana\MayorContableEntity;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;

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

    /**
     * Id determinístico por (cuenta_id, año, mes): como (cuenta_id, anio, mes)
     * identifica una única fila del mayor, no hace falta buscarla primero para
     * saber si ya existe — se puede calcular su Key directo y actualizarla (o
     * crearla) con un solo UpdateItem atómico. Público para que MayorContableService
     * pueda armar el mismo id sin duplicar esta regla.
     */
    public function idPara(string $cuentaId, string $anio, string $mes): string
    {
        return $cuentaId . '#' . $anio . '#' . str_pad($mes, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Acumula debe/haber (y su saldo neto) en el movimiento mensual de una
     * cuenta, de forma atómica: SET para los campos identificadores (mismo
     * valor siempre, calculado desde la propia Key) y ADD para debe/haber/saldo,
     * que DynamoDB inicializa solo en el primer posteo del mes y va sumando en
     * los siguientes — sin GetItem previo, así que dos posteos casi simultáneos
     * a la misma cuenta/mes no se pisan entre sí (el bug real que tenía
     * personal-finances: leía el saldo, lo recalculaba en PHP y sobreescribía
     * el saldo "en caliente" de la cuenta con el del mes tocado más reciente,
     * en vez de acumular el histórico completo).
     */
    public function acumular(string $cuentaId, string $anio, string $mes, float $debe, float $haber): MayorContableEntity
    {
        $mesNormalizado = str_pad($mes, 2, '0', STR_PAD_LEFT);
        $id = $this->idPara($cuentaId, $anio, $mesNormalizado);
        $now = date('c');

        $result = $this->dbClient->updateItem([
            'TableName'                 => $this->tableName,
            'Key'                       => $this->marshaler->marshalItem(['id' => $id]),
            'UpdateExpression'          => 'SET cuenta_id = :cuenta_id, anio = :anio, mes = :mes, '
                . 'created_at = if_not_exists(created_at, :now), updated_at = :now '
                . 'ADD debe :debe, haber :haber, saldo :delta',
            'ExpressionAttributeValues' => $this->marshaler->marshalItem([
                ':cuenta_id' => $cuentaId,
                ':anio'      => $anio,
                ':mes'       => $mesNormalizado,
                ':now'       => $now,
                ':debe'      => $debe,
                ':haber'     => $haber,
                ':delta'     => $debe - $haber,
            ]),
            'ReturnValues' => 'ALL_NEW',
        ]);

        return MayorContableEntity::fromArray($this->marshaler->unmarshalItem($result['Attributes']));
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
        $result = $this->dbClient->getItem([
            'TableName' => $this->tableName,
            'Key'       => $this->marshaler->marshalItem(['id' => $this->idPara($cuentaId, $anio, $mes)]),
        ]);

        if (empty($result['Item'])) {
            return null;
        }

        return MayorContableEntity::fromArray($this->marshaler->unmarshalItem($result['Item']));
    }
}
