<?php

namespace App\Common\Services;

use App\Common\Repositories\CuentaContableProfileRepository;
use App\Common\Repositories\MayorContableRepository;

/**
 * Estado de Flujos de Efectivo (método indirecto), derivado algebraicamente
 * de los cambios de saldo del resto de cuentas de balance — sin rastrear
 * partidas no monetarias aparte (si alguna vez se postea depreciación u otro
 * ajuste a mano, ya está dentro del grupo de Activo/Pasivo que se suma acá).
 *
 * La identidad que lo hace posible: cada asiento mantiene debe=haber global,
 * así que la suma de movimientos (en valor de presentación) de TODAS las
 * cuentas de un período da cero. Reordenando:
 *
 *   movimiento(Efectivo) = movimiento(Pasivo) + movimiento(Patrimonio real)
 *                         + movimiento(Ingreso) − movimiento(Costo) − movimiento(Gasto)
 *                         − movimiento(Activo, excluyendo Efectivo)
 *
 * "movimiento(Ingreso) − movimiento(Costo) − movimiento(Gasto)" es exactamente
 * la Ganancia Neta del Período que ya calcula EstadoResultadosService (mismo
 * conjunto de cuentas raíz, mismo signo — ya verificado ahí porque es la
 * misma cifra que BalanceGeneralService inyecta en 30701 para que el Balance
 * cuadre), así que se reusa en vez de recalcularla cuenta por cuenta.
 *
 * Clasificación de cuentas verificada contra el catálogo real
 * (plan-cuentas-niif.json) — ver Fase 4 del plan para el detalle.
 */
class EstadoFlujoEfectivoService
{
    use MovimientoPeriodoTrait;

    private const CODIGO_EFECTIVO = '10101';

    // Operación: capital de trabajo (activo/pasivo corriente NO financiero).
    private const OPERACION_ACTIVO = ['10102', '10103', '10104', '10105', '10106', '10107', '10108'];
    private const OPERACION_PASIVO = ['20103', '20105', '20107', '20108', '20110', '20111', '20112', '20113'];

    // Inversión: activo no corriente (PPE, intangibles, inversiones).
    private const INVERSION_ACTIVO = ['10201', '10202', '10203', '10204', '10205', '10206', '10207'];

    // Financiamiento: deuda financiera + patrimonio real (excluye 307
    // Resultados del Ejercicio — ya contado como punto de partida).
    private const FINANCIAMIENTO_PASIVO = [
        '20101', '20102', '20104', '20106', '20109', // pasivo financiero corriente
        '20201', '20202', '20203', '20204', '20205', '20206', '20207', '20208', '20209', '20210', // pasivo no corriente
    ];
    private const FINANCIAMIENTO_PATRIMONIO = ['301', '302', '303', '304', '305', '306'];

    public function generar(string $profileId, string $anio, ?string $mesDesde = null, ?string $mesHasta = null): array
    {
        $cuentaRepo = new CuentaContableProfileRepository();
        $mayorRepo = new MayorContableRepository();
        $desde = $mesDesde ?? '01';
        $hasta = $mesHasta ?? '12';

        $gananciaNeta = (new EstadoResultadosService())->generar($profileId, $anio, $desde, $hasta)['ganancia_neta_periodo'];

        $movOperacionActivo = $this->sumarMovimientos($cuentaRepo, $mayorRepo, $profileId, self::OPERACION_ACTIVO, $anio, $desde, $hasta);
        $movOperacionPasivo = $this->sumarMovimientos($cuentaRepo, $mayorRepo, $profileId, self::OPERACION_PASIVO, $anio, $desde, $hasta);
        // Un aumento de activo corriente (más inventario, más cuentas por cobrar)
        // CONSUME efectivo — se resta. Un aumento de pasivo corriente (más
        // cuentas por pagar) LIBERA efectivo — se suma.
        $capitalTrabajo = -$movOperacionActivo + $movOperacionPasivo;
        $netoOperacion = $gananciaNeta + $capitalTrabajo;

        // Comprar un activo no corriente (activo fijo) CONSUME efectivo.
        $netoInversion = -$this->sumarMovimientos($cuentaRepo, $mayorRepo, $profileId, self::INVERSION_ACTIVO, $anio, $desde, $hasta);

        // Tomar deuda financiera o recibir aportes de capital TRAE efectivo.
        $netoFinanciamiento = $this->sumarMovimientos(
            $cuentaRepo,
            $mayorRepo,
            $profileId,
            [...self::FINANCIAMIENTO_PASIVO, ...self::FINANCIAMIENTO_PATRIMONIO],
            $anio,
            $desde,
            $hasta
        );

        $movimientoNetoEfectivo = $netoOperacion + $netoInversion + $netoFinanciamiento;

        $efectivoInicial = $this->movimientoAcumuladoAntes($cuentaRepo, $mayorRepo, $profileId, self::CODIGO_EFECTIVO, $anio, $desde);
        $efectivoFinalCalculado = $efectivoInicial + $movimientoNetoEfectivo;

        $cuentaEfectivo = $cuentaRepo->getByProfileIdAndCodigo($profileId, self::CODIGO_EFECTIVO);
        $efectivoActualReal = $cuentaEfectivo
            ? ($cuentaEfectivo->naturaleza->value === 'debit' ? $cuentaEfectivo->saldo : -$cuentaEfectivo->saldo)
            : 0.0;

        // El chequeo de salud (¿el efectivo calculado coincide con el saldo
        // real de la cuenta Efectivo?) solo tiene sentido si el período pedido
        // llega hasta hoy — cuenta.saldo es SIEMPRE el acumulado a la fecha
        // actual, no a la fecha de corte del período. Para un período pasado
        // (ej. solo enero, corriendo en septiembre) no hay nada que comparar:
        // se muestran ambos números igual, pero sin la alarma de "no cuadra".
        $hoy = new \DateTimeImmutable();
        $periodoLlegaHastaHoy = $anio > $hoy->format('Y')
            || ($anio === $hoy->format('Y') && $hasta >= $hoy->format('m'));

        $diferencia = self::redondear($efectivoFinalCalculado - $efectivoActualReal);
        $cuadra = !$periodoLlegaHastaHoy || abs($diferencia) < 0.05;

        $lineas = [
            $this->linea('OP-1', 'Ganancia neta del período', $gananciaNeta, 1, false),
            $this->linea('OP-2', 'Variación neta en capital de trabajo', $capitalTrabajo, 1, false),
            $this->linea('OP', 'Efectivo neto de Actividades de Operación', $netoOperacion, 0, true),
            $this->linea('INV', 'Efectivo neto de Actividades de Inversión', $netoInversion, 0, true),
            $this->linea('FIN', 'Efectivo neto de Actividades de Financiamiento', $netoFinanciamiento, 0, true),
            $this->linea('NETO', 'Incremento (disminución) neto de Efectivo', $movimientoNetoEfectivo, 0, true),
            $this->linea('INI', 'Efectivo al inicio del período', $efectivoInicial, 0, false),
            $this->linea('FIN-EFEC', 'Efectivo al final del período', $efectivoFinalCalculado, 0, true),
        ];

        return [
            'anio' => $anio,
            'mes_desde' => $desde,
            'mes_hasta' => $hasta,
            'efectivo_inicial' => self::redondear($efectivoInicial),
            'movimiento_neto' => self::redondear($movimientoNetoEfectivo),
            'efectivo_final' => self::redondear($efectivoFinalCalculado),
            'efectivo_actual_real' => self::redondear($efectivoActualReal),
            'periodo_llega_hasta_hoy' => $periodoLlegaHastaHoy,
            'diferencia' => $diferencia,
            'cuadra' => $cuadra,
            'lineas' => $lineas,
        ];
    }

    /**
     * @param string[] $codigos
     */
    private function sumarMovimientos(
        CuentaContableProfileRepository $cuentaRepo,
        MayorContableRepository $mayorRepo,
        string $profileId,
        array $codigos,
        string $anio,
        string $desde,
        string $hasta
    ): float {
        $total = 0.0;
        foreach ($codigos as $codigo) {
            $total += $this->movimientoPeriodo($cuentaRepo, $mayorRepo, $profileId, $codigo, $anio, $desde, $hasta);
        }
        return $total;
    }

    /**
     * Suma el movimiento (en valor de presentación) de una cuenta en TODOS
     * los meses estrictamente anteriores al período pedido (cualquier año
     * anterior, o el mismo año antes de $mesDesde) — el saldo acumulado de la
     * cuenta justo antes de que empiece el período, sin depender de
     * cuenta.saldo (que es acumulado hasta HOY, no hasta el inicio del período).
     */
    private function movimientoAcumuladoAntes(
        CuentaContableProfileRepository $cuentaRepo,
        MayorContableRepository $mayorRepo,
        string $profileId,
        string $codigo,
        string $anio,
        string $mesDesde
    ): float {
        $cuenta = $cuentaRepo->getByProfileIdAndCodigo($profileId, $codigo);
        if (!$cuenta) {
            return 0.0;
        }

        $debe = 0.0;
        $haber = 0.0;
        foreach ($mayorRepo->findAllByCuentaId($cuenta->id) as $movimiento) {
            $esAnterior = $movimiento->anio < $anio || ($movimiento->anio === $anio && $movimiento->mes < $mesDesde);
            if (!$esAnterior) {
                continue;
            }
            $debe += $movimiento->debe;
            $haber += $movimiento->haber;
        }

        $saldo = $debe - $haber;
        return $cuenta->naturaleza->value === 'debit' ? $saldo : -$saldo;
    }

    private function linea(string $codigo, string $nombre, float $valor, int $nivel, bool $esSubtotal): array
    {
        return [
            'codigo' => $codigo,
            'nombre' => $nombre,
            'nivel' => $nivel,
            'es_subtotal' => $esSubtotal,
            'valor' => self::redondear($valor),
        ];
    }

    /** round() puede devolver -0.0 (se ve feo como "-0" en un reporte) — se normaliza a 0.0. */
    private static function redondear(float $v): float
    {
        $r = round($v, 2);
        return $r === 0.0 ? 0.0 : $r;
    }
}
