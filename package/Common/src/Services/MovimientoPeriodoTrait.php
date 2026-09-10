<?php

namespace App\Common\Services;

use App\Common\Repositories\CuentaContableProfileRepository;
use App\Common\Repositories\MayorContableRepository;

/**
 * Suma el movimiento de una cuenta (por código) dentro de un rango de meses
 * de un año, en valor de PRESENTACIÓN (positivo cuando la cuenta creció en su
 * dirección normal — debe para naturaleza débito, haber para naturaleza
 * crédito). Compartido por EstadoResultadosService (cuentas de flujo del
 * período) y EstadoFlujoEfectivoService (cambios de saldo de cuentas de
 * balance entre el inicio y el fin del período) — misma mecánica, dos usos.
 */
trait MovimientoPeriodoTrait
{
    private function movimientoPeriodo(
        CuentaContableProfileRepository $cuentaRepo,
        MayorContableRepository $mayorRepo,
        string $profileId,
        string $codigo,
        string $anio,
        string $desde,
        string $hasta
    ): float {
        $cuenta = $cuentaRepo->getByProfileIdAndCodigo($profileId, $codigo);
        if (!$cuenta) {
            return 0.0;
        }

        $debe = 0.0;
        $haber = 0.0;
        foreach ($mayorRepo->findAllByCuentaId($cuenta->id) as $movimiento) {
            if ($movimiento->anio !== $anio) {
                continue;
            }
            if ($movimiento->mes < $desde || $movimiento->mes > $hasta) {
                continue;
            }
            $debe += $movimiento->debe;
            $haber += $movimiento->haber;
        }

        $saldoPeriodo = $debe - $haber;
        return $cuenta->naturaleza->value === 'debit' ? $saldoPeriodo : -$saldoPeriodo;
    }
}
