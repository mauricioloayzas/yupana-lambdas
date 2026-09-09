<?php

namespace App\Common\Services;

use App\Common\Repositories\CuentaContableProfileRepository;
use App\Common\Repositories\MayorContableRepository;

/**
 * Mayorización: postea una línea (cuenta_id, debe, haber, anio, mes) en la
 * cuenta hoja y en cada uno de sus ancestros, caminando la cadena parent_id
 * (calculada una vez al clonar/crear cada cuenta — ver CuentaContableInitService)
 * en vez de re-derivar ancestros por prefijo de código escaneando todas las
 * cuentas del perfil en cada posteo.
 *
 * Tanto el movimiento mensual (MayorContableRepository::acumular) como el saldo
 * "en caliente" de la cuenta (CuentaContableProfileRepository::incrementarSaldo)
 * se actualizan con operaciones atómicas de DynamoDB (ADD), sin leer primero el
 * valor actual — corrige un bug real de personal-finances (del que este backend
 * partió): ahí, cada posteo LEÍA el saldo del mes tocado y SOBREESCRIBÍA el
 * saldo "en caliente" de la cuenta con ese valor, en vez de acumular sobre el
 * histórico completo — así que el saldo visible de una cuenta terminaba
 * reflejando solo el mes más recientemente posteado, no el saldo real desde el
 * inicio. Además, ese patrón de "leer, calcular en PHP, sobreescribir" tiene
 * condición de carrera si dos posteos a la misma cuenta llegan casi al mismo
 * tiempo (se puede perder uno de los dos incrementos); con ADD, DynamoDB
 * resuelve el incremento del lado del servidor, así que no hay ese riesgo.
 */
class MayorContableService
{
    public function process(array $data): array
    {
        $cuentaProfileRepo = new CuentaContableProfileRepository();
        $mayorRepo = new MayorContableRepository();

        $cuentaHoja = $cuentaProfileRepo->get($data['cuenta_id']);
        if (!$cuentaHoja) {
            throw new \Exception("Cuenta {$data['cuenta_id']} no encontrada.");
        }

        $debe = (float)$data['debe'];
        $haber = (float)$data['haber'];
        $anio = (string)$data['anio'];
        $mes = (string)$data['mes'];

        $resultado = $this->postear($cuentaHoja->id, $anio, $mes, $debe, $haber, $mayorRepo, $cuentaProfileRepo);

        $actual = $cuentaHoja;
        $visitados = [$actual->id => true]; // guarda contra un parent_id mal cargado que formara un ciclo
        while ($actual->parent_id !== null && !isset($visitados[$actual->parent_id])) {
            $actual = $cuentaProfileRepo->get($actual->parent_id);
            if (!$actual) {
                break;
            }
            $visitados[$actual->id] = true;
            $this->postear($actual->id, $anio, $mes, $debe, $haber, $mayorRepo, $cuentaProfileRepo);
        }

        return $resultado;
    }

    private function postear(
        string $cuentaId,
        string $anio,
        string $mes,
        float $debe,
        float $haber,
        MayorContableRepository $mayorRepo,
        CuentaContableProfileRepository $cuentaProfileRepo
    ): array {
        $movimiento = $mayorRepo->acumular($cuentaId, $anio, $mes, $debe, $haber);
        $cuentaProfileRepo->incrementarSaldo($cuentaId, $debe - $haber);

        return $movimiento->toArray();
    }
}
