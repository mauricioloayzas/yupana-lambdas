<?php

namespace App\Common\Services;

use App\Common\Repositories\AsientoContableRepository;
use App\Common\Repositories\AsientoContableDetalleRepository;
use App\Common\Repositories\CuentaContableProfileRepository;
use Aws\Sqs\SqsClient;
use Exception;

/**
 * Crea un asiento contable manual, validando partida doble y que cada línea
 * postee contra una cuenta postable (es_detalle=true) de la propia empresa —
 * el original de personal-finances (JournalEntryTrait) no impide postear a una
 * cuenta totalizadora, hueco que aquí sí se cierra.
 */
class AsientoContableService
{
    public function crearAsiento(string $profileId, array $data): array
    {
        $entries = $data['entries'] ?? [];
        if (empty($entries)) {
            throw new Exception('El asiento debe tener al menos una línea.');
        }

        $totalDebe = 0.0;
        $totalHaber = 0.0;
        $cuentaRepo = new CuentaContableProfileRepository();

        foreach ($entries as $entry) {
            $cuentaId = $entry['cuenta_id'] ?? null;
            if (!$cuentaId) {
                throw new Exception('Cada línea debe indicar cuenta_id.');
            }

            $cuenta = $cuentaRepo->get($cuentaId);
            if (!$cuenta || $cuenta->profile_id !== $profileId) {
                throw new Exception("La cuenta $cuentaId no existe en esta empresa.");
            }
            if (!$cuenta->es_detalle) {
                throw new Exception("La cuenta {$cuenta->codigo} - {$cuenta->nombre} es una cuenta totalizadora; no se puede postear directamente sobre ella.");
            }

            $totalDebe += (float)($entry['debe'] ?? 0);
            $totalHaber += (float)($entry['haber'] ?? 0);
        }

        if (round($totalDebe, 2) !== round($totalHaber, 2)) {
            throw new Exception('El asiento no está balanceado: la suma del debe debe ser igual a la del haber.');
        }
        if (round($totalDebe, 2) === 0.0) {
            throw new Exception('El asiento no puede tener valor cero.');
        }

        $asientoRepo = new AsientoContableRepository();
        $detalleRepo = new AsientoContableDetalleRepository();
        $creado = null;

        try {
            $creado = $asientoRepo->create($data, $profileId)->toArray();
            $asientoId = $creado['id'];

            [$anio, $mes] = $this->anioMesDeFecha($data['fecha']);
            $sqsClient = new SqsClient(['region' => $_ENV['AWS_REGION'] ?? 'us-east-1', 'version' => 'latest']);

            $detalles = [];
            foreach ($entries as $entry) {
                $debe = (float)($entry['debe'] ?? 0);
                $haber = (float)($entry['haber'] ?? 0);

                $detalle = $detalleRepo->create($asientoId, $entry['cuenta_id'], $debe, $haber);
                $detalles[] = $detalle->toArray();

                $sqsClient->sendMessage([
                    'QueueUrl'    => $_ENV['SQS_MAYOR_URL'],
                    'MessageBody' => json_encode([
                        'cuenta_id' => $entry['cuenta_id'],
                        'debe'      => $debe,
                        'haber'     => $haber,
                        'anio'      => $anio,
                        'mes'       => $mes,
                    ]),
                ]);
            }

            $creado['detalles'] = $detalles;
            return $creado;

        } catch (Exception $e) {
            if ($creado && isset($creado['id'])) {
                $detalleRepo->deleteByAsientoId($creado['id']);
                $asientoRepo->delete($creado['id']);
            }
            throw $e;
        }
    }

    private function anioMesDeFecha(string $fecha): array
    {
        $timestamp = strtotime($fecha) ?: time();
        return [date('Y', $timestamp), date('m', $timestamp)];
    }
}
