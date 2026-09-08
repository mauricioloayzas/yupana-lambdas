<?php

namespace App\Common\Services;

use App\Common\Repositories\CuentaContableRepository;
use App\Common\Repositories\CuentaContableProfileRepository;

/**
 * Clona el catálogo maestro NIIF hacia una empresa (profile_id). Idempotente:
 * si el perfil ya tiene cuentas clonadas, no vuelve a clonar (corrige el bug de
 * personal-finances/services/profiles/accounts/init.php, que duplica todo si se
 * llama dos veces).
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

        $maestro = new CuentaContableRepository();
        $cuentasBase = $maestro->getAll();

        $creadas = 0;
        foreach ($cuentasBase as $cuenta) {
            $profileRepo->create($cuenta->toArray(), $profileId);
            $creadas++;
        }

        return [
            'already_initialized' => false,
            'base_cuentas' => count($cuentasBase),
            'cuentas' => $creadas,
            'success' => $creadas === count($cuentasBase),
        ];
    }
}
