<?php

namespace App\Common\Services;

use App\Common\Repositories\CuentaContableProfileRepository;
use App\Common\Repositories\MayorContableRepository;

/**
 * Estado de Resultado Integral (tipo_estado=2), para el rango de meses de un
 * año pedido (por defecto, el año completo). Las cuentas de ingreso/costo/
 * gasto son de FLUJO, no de saldo acumulado — Yupana nunca cierra el período
 * con un asiento, así que hay que sumar los movimientos del Mayor de cada mes
 * pedido en vez de leer cuenta.saldo (que es acumulado desde siempre).
 *
 * Gracias a que la mayorización (Fase 2) ya sube el saldo por la cadena
 * parent_id, alcanza con consultar el Mayor de las ~13 cuentas RAÍZ reales
 * (41, 43, 51, 52, 61, 63, 65, 66, 71, 72, 74, 76, 81) — no hace falta sumar
 * cada hoja a mano, ya vienen acumuladas ahí. Los subtotales (42, 60, 62,
 * 64, 67, 73, 75, 77, 79, 82) NO llegan por parent_id (son fórmulas cruzadas
 * entre ramas, no jerarquía) — se calculan aparte, en el orden exacto que
 * describe el nombre de cada cuenta en el catálogo NIIF oficial.
 */
class EstadoResultadosService
{
    use MovimientoPeriodoTrait;

    private const CODIGOS_BASE = [
        '41' => 'INGRESOS DE ACTIVIDADES ORDINARIAS',
        '43' => 'OTROS INGRESOS',
        '51' => 'COSTO DE VENTAS Y PRODUCCIÓN',
        '52' => 'GASTOS',
        '61' => '15% PARTICIPACIÓN TRABAJADORES',
        '63' => 'IMPUESTO A LA RENTA CAUSADO',
        '65' => '(-) GASTO POR IMPUESTO DIFERIDO',
        '66' => '(+) INGRESO POR IMPUESTO DIFERIDO',
        '71' => 'INGRESOS POR OPERACIONES DISCONTINUADAS',
        '72' => 'GASTOS POR OPERACIONES DISCONTINUADAS',
        '74' => '15% PARTICIPACIÓN TRABAJADORES (operaciones discontinuadas)',
        '76' => 'IMPUESTO A LA RENTA CAUSADO (operaciones discontinuadas)',
        '81' => 'COMPONENTES DEL OTRO RESULTADO INTEGRAL',
    ];

    // Desglose de líneas hijas de 41 que Fase 2 sí postea automáticamente — solo
    // para que el reporte sea legible, no participan en la fórmula (ya está
    // contada en 41).
    private const CODIGOS_DETALLE_INGRESOS = [
        '4101' => 'Venta de bienes',
        '4102' => 'Prestación de servicios',
    ];

    public function generar(string $profileId, string $anio, ?string $mesDesde = null, ?string $mesHasta = null): array
    {
        $cuentaRepo = new CuentaContableProfileRepository();
        $mayorRepo = new MayorContableRepository();
        $desde = $mesDesde ?? '01';
        $hasta = $mesHasta ?? '12';

        $v = [];
        foreach (self::CODIGOS_BASE as $codigo => $_) {
            $v[$codigo] = $this->movimientoPeriodo($cuentaRepo, $mayorRepo, $profileId, $codigo, $anio, $desde, $hasta);
        }

        $detalle = [];
        foreach (self::CODIGOS_DETALLE_INGRESOS as $codigo => $_) {
            $detalle[$codigo] = $this->movimientoPeriodo($cuentaRepo, $mayorRepo, $profileId, $codigo, $anio, $desde, $hasta);
        }

        // Fórmula, en el orden exacto del catálogo oficial (ver Fase 3 del plan).
        $a = $v['41'] - $v['51'];
        $b = $a + $v['43'] - $v['52'];
        $c = $b - $v['61'];
        $d = $c - $v['63'];
        $ganOpContinuadas = $d - $v['65'] + $v['66'];
        $e = $v['71'] - $v['72'];
        $f = $e - $v['74'];
        $g = $f - $v['76'];
        $h = $ganOpContinuadas + $g;
        $i = $h + $v['81'];

        $lineas = [
            $this->linea('41', self::CODIGOS_BASE['41'], $v['41'], 0, false),
            $this->linea('4101', self::CODIGOS_DETALLE_INGRESOS['4101'], $detalle['4101'], 1, false),
            $this->linea('4102', self::CODIGOS_DETALLE_INGRESOS['4102'], $detalle['4102'], 1, false),
            $this->linea('51', self::CODIGOS_BASE['51'], $v['51'], 0, false),
            $this->linea('42', 'GANANCIA BRUTA (Subtotal A)', $a, 0, true),
            $this->linea('43', self::CODIGOS_BASE['43'], $v['43'], 0, false),
            $this->linea('52', self::CODIGOS_BASE['52'], $v['52'], 0, false),
            $this->linea('60', 'GANANCIA ANTES DE 15% TRABAJADORES E IMPUESTO A LA RENTA (Subtotal B)', $b, 0, true),
            $this->linea('61', self::CODIGOS_BASE['61'], $v['61'], 0, false),
            $this->linea('62', 'GANANCIA ANTES DE IMPUESTOS (Subtotal C)', $c, 0, true),
            $this->linea('63', self::CODIGOS_BASE['63'], $v['63'], 0, false),
            $this->linea('64', 'GANANCIA ANTES DE IMPUESTO DIFERIDO (Subtotal D)', $d, 0, true),
            $this->linea('65', self::CODIGOS_BASE['65'], $v['65'], 0, false),
            $this->linea('66', self::CODIGOS_BASE['66'], $v['66'], 0, false),
            $this->linea('67', 'GANANCIA DE OPERACIONES CONTINUADAS', $ganOpContinuadas, 0, true),
            $this->linea('71', self::CODIGOS_BASE['71'], $v['71'], 0, false),
            $this->linea('72', self::CODIGOS_BASE['72'], $v['72'], 0, false),
            $this->linea('73', 'GANANCIA OP. DISCONTINUADAS ANTES DE TRABAJADORES E IR (Subtotal E)', $e, 0, true),
            $this->linea('74', self::CODIGOS_BASE['74'], $v['74'], 0, false),
            $this->linea('75', 'GANANCIA OP. DISCONTINUADAS ANTES DE IMPUESTOS (Subtotal F)', $f, 0, true),
            $this->linea('76', self::CODIGOS_BASE['76'], $v['76'], 0, false),
            $this->linea('77', 'GANANCIA DE OPERACIONES DISCONTINUADAS (Subtotal G)', $g, 0, true),
            $this->linea('79', 'GANANCIA NETA DEL PERIODO (Subtotal H)', $h, 0, true),
            $this->linea('81', self::CODIGOS_BASE['81'], $v['81'], 0, false),
            $this->linea('82', 'RESULTADO INTEGRAL TOTAL DEL AÑO (Subtotal I)', $i, 0, true),
        ];

        return [
            'anio' => $anio,
            'mes_desde' => $desde,
            'mes_hasta' => $hasta,
            'ganancia_neta_periodo' => self::redondear($h),
            'resultado_integral_total' => self::redondear($i),
            'lineas' => $lineas,
        ];
    }

    private function linea(string $codigo, string $nombre, float $valor, int $nivel, bool $esSubtotal): array
    {
        return [
            'codigo'      => $codigo,
            'nombre'      => $nombre,
            'nivel'       => $nivel,
            'es_subtotal' => $esSubtotal,
            'valor'       => self::redondear($valor),
        ];
    }

    /** round() puede devolver -0.0 (se ve feo como "-0" en un reporte) — se normaliza a 0.0. */
    private static function redondear(float $v): float
    {
        $r = round($v, 2);
        return $r === 0.0 ? 0.0 : $r;
    }
}
