<?php

namespace App\Common\Services;

use App\Common\Repositories\CuentaContableProfileRepository;
use Mauloasan\BobConstruye\DynamoDB\Entities\Yupana\CuentaContableProfileEntity;

/**
 * Estado de Situación Financiera (Balance General, tipo_estado=1) a una fecha
 * de corte. Usa el saldo ACUMULADO de cada cuenta (cuenta.saldo, desde
 * siempre) — es lo correcto para una foto en un punto en el tiempo, y la
 * mayorización (Fase 2) ya lo mantiene sumado por la cadena parent_id, así
 * que ni los grupos necesitan lógica extra.
 *
 * Yupana nunca hace un asiento de cierre que traspase Ingresos/Gastos a
 * Patrimonio, así que sin ayuda el Balance no cuadraría (Activo != Pasivo +
 * Patrimonio) en ningún punto intermedio del año. Se resuelve reconociendo la
 * utilidad acumulada desde siempre (todas las cuentas RAÍZ de tipo_estado=2,
 * con signo de presentación) como Patrimonio, en la cuenta oficial pensada
 * exactamente para esto: 30701 "GANANCIA NETA DEL PERIODO". Las cuentas
 * "fórmula" del Estado de Resultados (42, 60, 62...) nunca reciben posteos
 * directos (son raíces sin parent_id, pero su saldo real es siempre 0), así
 * que sumar TODAS las raíces de tipo_estado=2 no duplica nada.
 */
class BalanceGeneralService
{
    private const CODIGO_ACTIVO = '1';
    private const CODIGO_PASIVO = '2';
    private const CODIGO_PATRIMONIO = '3';
    private const CODIGO_RESULTADO_EJERCICIO = '30701';

    public function generar(string $profileId, string $fecha): array
    {
        $cuentaRepo = new CuentaContableProfileRepository();
        $todas = $cuentaRepo->getByProfileId($profileId);

        $resultadoAcumulado = 0.0;
        foreach ($todas as $c) {
            if ($c->tipo_estado === 2 && $c->parent_id === null) {
                $resultadoAcumulado += $this->valorPresentacion($c);
            }
        }

        $porId = [];
        foreach ($todas as $c) {
            $porId[$c->id] = $c;
        }

        $lineas = [];
        foreach ($todas as $c) {
            if ($c->tipo_estado !== 1) {
                continue;
            }

            $valor = $this->valorPresentacion($c);
            if ($c->codigo === self::CODIGO_RESULTADO_EJERCICIO) {
                $valor += $resultadoAcumulado;
            }

            $lineas[] = [
                'codigo'     => $c->codigo,
                'nombre'     => $c->nombre,
                'nivel'      => $this->profundidad($c, $porId),
                'es_detalle' => $c->es_detalle,
                'valor'      => self::redondear($valor),
            ];
        }

        usort($lineas, fn($a, $b) => $a['codigo'] <=> $b['codigo']);

        $activo = $cuentaRepo->getByProfileIdAndCodigo($profileId, self::CODIGO_ACTIVO);
        $pasivo = $cuentaRepo->getByProfileIdAndCodigo($profileId, self::CODIGO_PASIVO);
        $patrimonio = $cuentaRepo->getByProfileIdAndCodigo($profileId, self::CODIGO_PATRIMONIO);

        $totalActivo = $activo ? $this->valorPresentacion($activo) : 0.0;
        $totalPasivo = $pasivo ? $this->valorPresentacion($pasivo) : 0.0;
        $totalPatrimonio = ($patrimonio ? $this->valorPresentacion($patrimonio) : 0.0) + $resultadoAcumulado;

        $diferencia = self::redondear($totalActivo - ($totalPasivo + $totalPatrimonio));

        return [
            'fecha'             => $fecha,
            'total_activo'      => self::redondear($totalActivo),
            'total_pasivo'      => self::redondear($totalPasivo),
            'total_patrimonio'  => self::redondear($totalPatrimonio),
            'resultado_acumulado' => self::redondear($resultadoAcumulado),
            'diferencia'        => $diferencia,
            'cuadra'            => abs($diferencia) < 0.05,
            'lineas'            => $lineas,
        ];
    }

    private function valorPresentacion(CuentaContableProfileEntity $c): float
    {
        return $c->naturaleza->value === 'debit' ? $c->saldo : -$c->saldo;
    }

    /** round() puede devolver -0.0 (se ve feo como "-0" en un reporte) — se normaliza a 0.0. */
    private static function redondear(float $v): float
    {
        $r = round($v, 2);
        return $r === 0.0 ? 0.0 : $r;
    }

    /**
     * @param array<string, CuentaContableProfileEntity> $porId
     */
    private function profundidad(CuentaContableProfileEntity $cuenta, array $porId): int
    {
        $nivel = 0;
        $actual = $cuenta;
        while ($actual->parent_id !== null && isset($porId[$actual->parent_id])) {
            $actual = $porId[$actual->parent_id];
            $nivel++;
        }
        return $nivel;
    }
}
