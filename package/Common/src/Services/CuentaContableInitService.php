<?php

namespace App\Common\Services;

use App\Common\Data\PlanCuentasNiifProvider;
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

        return [
            'already_initialized' => false,
            'base_cuentas' => count($cuentasBase),
            'cuentas' => $creadas,
            'success' => $creadas === count($cuentasBase),
        ];
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
