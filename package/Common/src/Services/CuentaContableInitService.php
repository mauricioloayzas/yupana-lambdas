<?php

namespace App\Common\Services;

use App\Common\Data\PlanCuentasNiifProvider;
use App\Common\Http\ApothecaClient;
use App\Common\Http\CajaRegistradoraClient;
use App\Common\Repositories\CuentaContableProfileRepository;

/**
 * Clona el catálogo NIIF (bundleado en PlanCuentasNiifProvider) hacia una
 * empresa (profile_id). Idempotente: si el perfil ya tiene cuentas clonadas,
 * no vuelve a clonar (corrige el bug de
 * personal-finances/services/profiles/accounts/init.php, que duplica todo si
 * se llama dos veces).
 */
class CuentaContableInitService
{
    /** Cuenta oficial "Materiales utilizados o productos vendidos" (grupo, no postable). */
    private const CODIGO_GRUPO_COSTO_VENTA = '5101';

    public function init(string $profileId): array
    {
        $profileRepo = new CuentaContableProfileRepository();
        $existentes = $profileRepo->getByProfileId($profileId);

        if (!empty($existentes)) {
            return [
                'already_initialized' => true,
                'cuentas' => count($existentes),
            ];
        }

        $cuentasBase = PlanCuentasNiifProvider::cuentas();

        // El JSON ya viene en el mismo orden que el PDF oficial: cada cuenta
        // aparece después de su padre (ej. "1" antes de "101" antes de "10101").
        // Aprovechando eso, en una sola pasada se puede resolver el parent_id de
        // cada cuenta contra las que ya se fueron creando, sin una segunda vuelta.
        $idPorCodigo = [];
        $creadas = 0;

        foreach ($cuentasBase as $cuenta) {
            $parentId = $this->buscarParentId($cuenta['codigo'], $idPorCodigo);

            $creada = $profileRepo->create([...$cuenta, 'parent_id' => $parentId], $profileId);
            $idPorCodigo[$cuenta['codigo']] = $creada->id;
            $creadas++;
        }

        $this->crearCuentaCostoVenta($profileId, $idPorCodigo[self::CODIGO_GRUPO_COSTO_VENTA] ?? null);
        $backfill = $this->backfillHistorico($profileId);

        return [
            'already_initialized' => false,
            'base_cuentas' => count($cuentasBase),
            'cuentas' => $creadas,
            'success' => $creadas === count($cuentasBase),
            'backfill' => $backfill,
        ];
    }

    /**
     * El catálogo oficial modela el costo de venta con método PERIÓDICO (5101 =
     * inventario inicial + compras − inventario final, para calcular al cierre).
     * Como Fase 2 postea el costo de cada venta en tiempo real (apotheca ya lleva
     * costo promedio ponderado), se crea una cuenta personalizada bajo 5101 en vez
     * de forzar un uso no previsto de las subcuentas oficiales — no bloquea el
     * resto de la activación si falla.
     */
    private function crearCuentaCostoVenta(string $profileId, ?string $grupoCostoVentaId): void
    {
        if ($grupoCostoVentaId === null) {
            return;
        }

        try {
            (new CuentaContablePersonalizadaService())->crear(
                $profileId,
                $grupoCostoVentaId,
                'Costo de Mercadería Vendida (sistema perpetuo)',
                'Costo de venta calculado en tiempo real a partir del costo promedio de apotheca en cada venta — no es una de las subcuentas oficiales 510101-510112 (esas son para el cálculo periódico de cierre).'
            );
        } catch (\Throwable $e) {
            error_log('No se pudo crear la cuenta de costo de venta: ' . $e->getMessage());
        }
    }

    /**
     * Si el perfil ya tenía inventario y/o facturas autorizadas ANTES de activar
     * Contabilidad, genera un asiento de saldos iniciales: no intenta reconstruir
     * dónde quedó el efectivo real de cada venta pasada (Yupana nunca lo vio) —
     * el inventario actual se reconoce contra "Resultados acumulados por adopción
     * de las NIIF" (30603, el uso oficial previsto para esta cuenta), y las
     * ventas históricas se tratan como una venta de catch-up fechada hoy.
     */
    private function backfillHistorico(string $profileId): array
    {
        $resultado = ['inventario' => false, 'ventas' => false];
        $entries = [];

        $valorInventario = (new ApothecaClient())->valorInventario($profileId);
        if ($valorInventario !== null && round($valorInventario, 2) > 0) {
            $valor = round($valorInventario, 2);
            $entries[] = ['codigo' => '1010306', 'debe' => $valor, 'haber' => 0];
            $entries[] = ['codigo' => '30603', 'debe' => 0, 'haber' => $valor];
            $resultado['inventario'] = true;
        }

        $ventas = (new CajaRegistradoraClient())->ventasHastaHoy($profileId);
        if ($ventas !== null && round($ventas['sin_impuestos'] + $ventas['impuesto'], 2) > 0) {
            $sinImpuestos = round($ventas['sin_impuestos'], 2);
            $impuesto = round($ventas['impuesto'], 2);
            $entries[] = ['codigo' => '10101', 'debe' => round($sinImpuestos + $impuesto, 2), 'haber' => 0];
            $entries[] = ['codigo' => '4101', 'debe' => 0, 'haber' => $sinImpuestos];
            $entries[] = ['codigo' => '2010701', 'debe' => 0, 'haber' => $impuesto];
            $resultado['ventas'] = true;
        }

        if (empty($entries)) {
            return $resultado;
        }

        try {
            (new AsientoContableService())->crearAsientoPorCodigo($profileId, [
                'fecha'       => date('Y-m-d'),
                'descripcion' => 'Saldos iniciales por activación de Contabilidad',
                'origen'      => 'ajuste',
                'entries'     => $entries,
            ]);
        } catch (\Throwable $e) {
            error_log('No se pudo crear el asiento de backfill: ' . $e->getMessage());
            return ['inventario' => false, 'ventas' => false];
        }

        return $resultado;
    }

    /**
     * Prefijo más largo de $codigo que ya exista en $idPorCodigo (las cuentas
     * creadas hasta este punto de la pasada) — es el padre inmediato.
     */
    private function buscarParentId(string $codigo, array $idPorCodigo): ?string
    {
        for ($longitud = strlen($codigo) - 1; $longitud >= 1; $longitud--) {
            $prefijo = substr($codigo, 0, $longitud);
            if (isset($idPorCodigo[$prefijo])) {
                return $idPorCodigo[$prefijo];
            }
        }

        return null;
    }
}
