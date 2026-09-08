<?php

namespace App\Common\Services;

use App\Common\Repositories\CuentaContableProfileRepository;
use App\Common\Repositories\MayorContableRepository;

/**
 * Mayorización: postea una línea (cuenta_id, debe, haber, anio, mes) en la
 * cuenta hoja y en cada uno de sus ancestros. A diferencia de personal-finances
 * (que usa explode(".", $code), pensado para códigos con separador), el plan
 * NIIF usa códigos numéricos sin separador ("1", "101", "10101", "1010201",
 * "101020501"...) donde la jerarquía es por PREFIJO de string — así que se
 * camina hacia arriba probando prefijos decrecientes contra un mapa
 * codigo => cuenta cargado una sola vez, y se postea en cada prefijo que
 * exista realmente como cuenta del perfil (sin asumir profundidad fija).
 */
class MayorContableService
{
    public function process(array $data): array
    {
        $cuentaProfileRepo = new CuentaContableProfileRepository();

        $cuentaHoja = $cuentaProfileRepo->get($data['cuenta_id']);
        if (!$cuentaHoja) {
            throw new \Exception("Cuenta {$data['cuenta_id']} no encontrada.");
        }

        $resultado = $this->ledgerProcess($data, $data['cuenta_id']);

        $todasLasCuentas = $cuentaProfileRepo->getByProfileId($cuentaHoja->profile_id);
        $mapaPorCodigo = [];
        foreach ($todasLasCuentas as $cuenta) {
            $mapaPorCodigo[$cuenta->codigo] = $cuenta;
        }

        $codigo = $cuentaHoja->codigo;
        for ($longitud = strlen($codigo) - 1; $longitud >= 1; $longitud--) {
            $prefijo = substr($codigo, 0, $longitud);
            if (isset($mapaPorCodigo[$prefijo])) {
                $this->ledgerProcess($data, $mapaPorCodigo[$prefijo]->id);
            }
        }

        return $resultado;
    }

    private function ledgerProcess(array $data, string $cuentaId): array
    {
        $mayorRepo = new MayorContableRepository();
        $cuentaProfileRepo = new CuentaContableProfileRepository();

        $debe = (float)$data['debe'];
        $haber = (float)$data['haber'];
        $anio = $data['anio'];
        $mes = $data['mes'];

        $existente = $mayorRepo->findByCuentaIdAnioMes($cuentaId, $anio, $mes);

        if ($existente) {
            $nuevoDebe = $existente->debe + $debe;
            $nuevoHaber = $existente->haber + $haber;
            $nuevoSaldo = $nuevoDebe - $nuevoHaber;

            $movimiento = $mayorRepo->update($existente->id, [
                'debe'  => $nuevoDebe,
                'haber' => $nuevoHaber,
                'saldo' => $nuevoSaldo,
            ]);
        } else {
            $movimiento = $mayorRepo->create([
                'cuenta_id' => $cuentaId,
                'debe'      => $debe,
                'haber'     => $haber,
                'saldo'     => $debe - $haber,
                'anio'      => $anio,
                'mes'       => $mes,
            ]);
        }

        $cuentaProfileRepo->update($cuentaId, ['saldo' => $movimiento->saldo]);

        return $movimiento->toArray();
    }
}
